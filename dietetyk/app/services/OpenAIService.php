<?php

class OpenAIService
{
    /**
     * Wysyła zapytanie do OpenAI Responses API (nie-streaming)
     */
    public static function chat(array $input, string $model = MODEL_CHAT, string $context = ''): ?string
    {
        $payload = [
            'model' => $model,
            'input' => $input,
        ];

        $response = self::request('/v1/responses', $payload);

        if ($response === null) {
            return null;
        }

        // Log usage
        if (isset($response['usage'])) {
            $u = $response['usage'];
            ApiUsage::log($model, 'chat', $u['input_tokens'] ?? 0, $u['output_tokens'] ?? 0, $context);
        }

        // Responses API zwraca output jako tablica obiektów
        if (isset($response['output'])) {
            foreach ($response['output'] as $item) {
                if (($item['type'] ?? '') === 'message') {
                    foreach ($item['content'] as $content) {
                        if (($content['type'] ?? '') === 'output_text') {
                            return $content['text'];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Chat z kontekstem - buduje input z wiadomości
     */
    public static function chatWithContext(string $systemPrompt, array $messages, string $userMessage, string $model = MODEL_CHAT): ?string
    {
        $input = self::buildInput($systemPrompt, $messages, $userMessage);
        return self::chat($input, $model);
    }

    /**
     * Streaming chat - wysyła tokeny na żywo przez callback
     */
    public static function chatStream(string $systemPrompt, array $messages, string $userMessage, callable $onToken, string $model = MODEL_CHAT, string $context = 'chat-stream'): ?string
    {
        $input = self::buildInput($systemPrompt, $messages, $userMessage);

        $payload = [
            'model' => $model,
            'input' => $input,
            'stream' => true,
        ];

        $fullResponse = '';
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        $caPath = STORAGE_PATH . '/cacert.pem';

        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . OPENAI_API_KEY,
                'Accept: text/event-stream',
            ],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CAINFO => $caPath,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$fullResponse, &$usage, $onToken) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line === 'data: [DONE]') continue;
                    if (!str_starts_with($line, 'data: ')) continue;

                    $json = json_decode(substr($line, 6), true);
                    if (!$json) continue;

                    $type = $json['type'] ?? '';

                    if ($type === 'response.output_text.delta') {
                        $delta = $json['delta'] ?? '';
                        if ($delta !== '') {
                            $fullResponse .= $delta;
                            $onToken($delta);
                        }
                    } elseif ($type === 'response.completed') {
                        $u = $json['response']['usage'] ?? [];
                        $usage['input_tokens'] = $u['input_tokens'] ?? 0;
                        $usage['output_tokens'] = $u['output_tokens'] ?? 0;
                    }
                }
                return strlen($data);
            },
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("OpenAI Stream cURL error: $error");
            return null;
        }

        if ($httpCode !== 200 && $fullResponse === '') {
            error_log("OpenAI Stream HTTP $httpCode");
            return null;
        }

        // Log usage
        if ($usage['input_tokens'] > 0 || $usage['output_tokens'] > 0) {
            ApiUsage::log($model, $context, $usage['input_tokens'], $usage['output_tokens'], $context);
        }

        return $fullResponse ?: null;
    }

    /**
     * Ekstrakcja JSON z tekstu (do insightów)
     */
    public static function extractJson(string $prompt, string $text, string $model = MODEL_MINI, string $context = 'extract'): ?array
    {
        $input = [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => $text],
        ];

        $response = self::chat($input, $model, $context);

        if ($response === null) return null;

        // Wyciągnij JSON z odpowiedzi (może być w bloku ```json)
        if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
            $response = $matches[1];
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Analizuje zdjęcia produktu spożywczego i zwraca dane odżywcze jako JSON
     * @param array $images [['base64' => ..., 'mime' => ...], ...]
     */
    public static function analyzeImage(array $images): ?array
    {
        $prompt = <<<'PROMPT'
Analizujesz zdjęcia produktu spożywczego. Możesz otrzymać:
- Zdjęcie frontu opakowania (nazwa, marka)
- Zdjęcie tabeli wartości odżywczych (skład)
- Oba na jednym zdjęciu

Połącz informacje ze WSZYSTKICH zdjęć. Wyciągnij dane odżywcze.
Zwróć WYŁĄCZNIE JSON (bez komentarzy):
{
  "name": "nazwa produktu po polsku",
  "brand": "marka (jeśli widoczna)",
  "kcal_100g": liczba,
  "protein_100g": liczba,
  "carbs_100g": liczba,
  "fat_100g": liczba,
  "fiber_100g": liczba,
  "typical_portion_g": liczba (jeśli podana na opakowaniu, inaczej 100)
}
Jeśli wartości podane są na porcję a nie na 100g, przelicz na 100g.
Jeśli widzisz front bez tabeli odżywczej, rozpoznaj produkt i oszacuj wartości z wiedzy ogólnej.
Jeśli nie rozpoznajesz produktu, zwróć {"error": "Nie rozpoznano produktu"}.
PROMPT;

        $contentParts = [];
        foreach ($images as $img) {
            $contentParts[] = [
                'type' => 'input_image',
                'image_url' => "data:{$img['mime']};base64,{$img['base64']}",
            ];
        }

        $input = [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => $contentParts],
        ];

        $response = self::chat($input, MODEL_CHAT, 'vision-scan');

        if ($response === null) return null;

        // Wyciągnij JSON
        if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
            $response = $matches[1];
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Buduje tablicę input z system prompt + historii + nowej wiadomości
     */
    private static function buildInput(string $systemPrompt, array $messages, string $userMessage): array
    {
        $input = [];

        $input[] = [
            'role' => 'system',
            'content' => $systemPrompt,
        ];

        foreach ($messages as $msg) {
            $input[] = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        if ($userMessage !== '') {
            $input[] = [
                'role' => 'user',
                'content' => $userMessage,
            ];
        }

        return $input;
    }

    private static function request(string $endpoint, array $payload): ?array
    {
        $url = 'https://api.openai.com' . $endpoint;

        $ch = curl_init($url);
        $caPath = STORAGE_PATH . '/cacert.pem';
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . OPENAI_API_KEY,
            ],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CAINFO => $caPath,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("OpenAI cURL error: $error");
            return null;
        }

        if ($httpCode !== 200) {
            error_log("OpenAI HTTP $httpCode: $response");
            return null;
        }

        return json_decode($response, true);
    }
}
