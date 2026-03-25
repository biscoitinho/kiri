<?php

class InsightExtractor
{
    private static string $extractionPrompt = <<<'PROMPT'
Analizujesz odpowiedź AI trenera. Twoim zadaniem jest wyłowić kluczowe informacje warte zapamiętania.

Zwróć JSON (lub pustą tablicę jeśli brak wniosków):
[
  {
    "type": "diet|training|injury|recovery|psychology|goal|nutrition|competition|strategy|progress",
    "content": "krótki, konkretny wniosek po polsku",
    "priority": 1-10
  }
]

Zasady:
- Wyłapuj TYLKO nowe, konkretne fakty (zmiana wagi, kontuzja, decyzja treningowa)
- NIE powtarzaj ogólnych porad
- Priority 8-10 = kluczowe (kontuzja, zmiana wagi, ważna decyzja)
- Priority 5-7 = istotne (obserwacja, wzorzec)
- Priority 1-4 = drobne (uwaga, sugestia)
- Zwracaj WYŁĄCZNIE poprawny JSON, bez komentarzy
PROMPT;

    /**
     * Analizuje odpowiedź AI i wyciąga insighty
     */
    public static function extract(int $projectId, string $userMessage, string $aiResponse): array
    {
        $combined = "WIADOMOŚĆ UŻYTKOWNIKA:\n{$userMessage}\n\nODPOWIEDŹ AI:\n{$aiResponse}";

        $insights = OpenAIService::extractJson(self::$extractionPrompt, $combined, MODEL_MINI);

        if (!$insights || !is_array($insights)) {
            return [];
        }

        $saved = [];
        foreach ($insights as $insight) {
            if (empty($insight['type']) || empty($insight['content'])) continue;

            $type = $insight['type'];
            $content = $insight['content'];
            $priority = max(1, min(10, (int) ($insight['priority'] ?? 5)));

            // Sprawdź czy podobny insight już istnieje
            if (self::isDuplicate($projectId, $type, $content)) continue;

            $id = Insight::create($projectId, $type, $content, $priority);
            $saved[] = ['id' => $id, 'type' => $type, 'content' => $content, 'priority' => $priority];
        }

        return $saved;
    }

    /**
     * Prosty check duplikatów - porównuje treść
     */
    private static function isDuplicate(int $projectId, string $type, string $content): bool
    {
        $existing = Insight::getByProject($projectId);
        $contentLower = strtolower($content);

        foreach ($existing as $insight) {
            if ($insight['type'] !== $type) continue;

            $existingLower = strtolower($insight['content']);
            // Jeśli >70% podobieństwa tekstu - uznaj za duplikat
            similar_text($contentLower, $existingLower, $percent);
            if ($percent > 70) return true;
        }

        return false;
    }
}
