<?php

class FoodDatabase
{
    private static string $apiBase = 'https://world.openfoodfacts.org';

    /**
     * Szukaj produktu po nazwie (Open Food Facts API)
     * Zwraca top wyniki z kalorycznością
     */
    public static function search(string $query, int $limit = 5): array
    {
        $params = http_build_query([
            'search_terms' => $query,
            'search_simple' => 1,
            'action' => 'process',
            'json' => 1,
            'page_size' => $limit,
            'fields' => 'product_name,brands,nutriments,serving_size,image_front_small_url',
            'tagtype_0' => 'countries',
            'tag_contains_0' => 'contains',
            'tag_0' => 'Poland',
        ]);

        $url = self::$apiBase . '/cgi/search.pl?' . $params;
        $response = self::httpGet($url);

        if (!$response || !isset($response['products'])) return [];

        $results = [];
        foreach ($response['products'] as $product) {
            $nutriments = $product['nutriments'] ?? [];

            $item = [
                'name' => $product['product_name'] ?? 'Nieznany',
                'brand' => $product['brands'] ?? '',
                'serving_size' => $product['serving_size'] ?? '',
                'image' => $product['image_front_small_url'] ?? null,
                'per_100g' => [
                    'kcal' => round($nutriments['energy-kcal_100g'] ?? $nutriments['energy_100g'] ?? 0),
                    'protein' => round($nutriments['proteins_100g'] ?? 0, 1),
                    'carbs' => round($nutriments['carbohydrates_100g'] ?? 0, 1),
                    'fat' => round($nutriments['fat_100g'] ?? 0, 1),
                    'fiber' => round($nutriments['fiber_100g'] ?? 0, 1),
                ],
            ];

            // Odrzuć produkty bez danych kalorycznych
            if ($item['per_100g']['kcal'] > 0) {
                $results[] = $item;
            }
        }

        return $results;
    }

    /**
     * Szukaj po kodzie kreskowym (EAN)
     */
    public static function getByBarcode(string $barcode): ?array
    {
        $url = self::$apiBase . '/api/v2/product/' . urlencode($barcode) . '.json';
        $url .= '?fields=product_name,brands,nutriments,serving_size,image_front_small_url';

        $response = self::httpGet($url);

        if (!$response || ($response['status'] ?? 0) !== 1) return null;

        $product = $response['product'] ?? [];
        $nutriments = $product['nutriments'] ?? [];

        return [
            'name' => $product['product_name'] ?? 'Nieznany',
            'brand' => $product['brands'] ?? '',
            'serving_size' => $product['serving_size'] ?? '',
            'image' => $product['image_front_small_url'] ?? null,
            'per_100g' => [
                'kcal' => round($nutriments['energy-kcal_100g'] ?? 0),
                'protein' => round($nutriments['proteins_100g'] ?? 0, 1),
                'carbs' => round($nutriments['carbohydrates_100g'] ?? 0, 1),
                'fat' => round($nutriments['fat_100g'] ?? 0, 1),
                'fiber' => round($nutriments['fiber_100g'] ?? 0, 1),
            ],
        ];
    }

    /**
     * Szacuj kalorie przez AI (fallback gdy brak w bazie)
     */
    public static function estimateWithAI(string $description): ?array
    {
        $prompt = <<<'PROMPT'
Oszacuj wartości odżywcze podanego posiłku/produktu. Zwróć WYŁĄCZNIE JSON:
{
  "items": [
    {
      "name": "nazwa produktu",
      "amount": "ilość (np. 100g, 1 szt, 200ml)",
      "kcal": liczba,
      "protein": gramy,
      "carbs": gramy,
      "fat": gramy
    }
  ],
  "total": {
    "kcal": suma,
    "protein": suma,
    "carbs": suma,
    "fat": suma
  },
  "confidence": "high|medium|low"
}
Bądź realistyczny. Jeśli nie podano ilości, przyjmij typową porcję.
PROMPT;

        return OpenAIService::extractJson($prompt, $description, MODEL_MINI);
    }

    /**
     * Wyciąga ilość (g/ml/szt) z opisu i zwraca czystą nazwę
     * "350 g jogurtu naturalnego" → ["jogurt naturalny", 350, 0]
     * "5 szt jajek" → ["jajek", 0, 5]
     * Returns: [cleanName, parsedGrams, parsedPieces]
     */
    private static function parseQuantityAndName(string $query): array
    {
        $query = trim($query);
        $grams = 0;
        $pieces = 0;

        // "5 szt jajek", "3 szt. bułki"
        if (preg_match('/^(\d+)\s*szt\.?\s+(.+)$/iu', $query, $m)) {
            $pieces = (int) $m[1];
            $query = trim($m[2]);
        }
        // "350 g ...", "200ml ...", "350g..."
        elseif (preg_match('/^(\d+)\s*(g|gram[óo]?w?|ml)\s+(.+)$/iu', $query, $m)) {
            $grams = (int) $m[1];
            $query = trim($m[3]);
        }
        // "5 jajek" - ilość szt bez jednostki (max 20)
        elseif (preg_match('/^(\d+)\s+(.+)$/u', $query, $m) && (int)$m[1] <= 20) {
            $pieces = (int) $m[1];
            $query = trim($m[2]);
        }

        return [$query, $grams, $pieces];
    }

    /**
     * 1. Lokalna baza (darmowa, instant)
     * 2. AI estimation (szybkie ~2s, cachowane do lokalnej bazy)
     * 3. OFF API tylko dla barcode
     */
    public static function lookup(string $query): array
    {
        // Wyciągnij ilość i czystą nazwę
        [$cleanName, $parsedGrams, $parsedPieces] = self::parseQuantityAndName($query);

        // 1. Szukaj w lokalnej bazie - czysta nazwa (priorytet), potem oryginał
        $local = FoodCache::search($cleanName, 3);
        if (empty($local)) {
            $local = FoodCache::search($query, 3);
        }

        if (!empty($local)) {
            return [
                'source' => 'local',
                'parsed_grams' => $parsedGrams,
                'parsed_pieces' => $parsedPieces,
                'results' => array_map(function ($row) {
                    return [
                        'name' => $row['name'],
                        'brand' => $row['brand'],
                        'per_100g' => [
                            'kcal' => (int) $row['kcal_100g'],
                            'protein' => (float) $row['protein_100g'],
                            'carbs' => (float) $row['carbs_100g'],
                            'fat' => (float) $row['fat_100g'],
                            'fiber' => (float) $row['fiber_100g'],
                        ],
                        'typical_portion_g' => (int) $row['typical_portion_g'],
                        'source_type' => $row['source'],
                    ];
                }, $local),
            ];
        }

        // 2. AI estimation + cache wyniku
        $estimate = self::estimateWithAI($query);

        if ($estimate) {
            // Cache poszczególne składniki do lokalnej bazy
            foreach ($estimate['items'] ?? [] as $item) {
                $name = $item['name'] ?? '';
                $amount = $item['amount'] ?? '';
                $kcal = (int) ($item['kcal'] ?? 0);

                if ($name && $kcal > 0) {
                    // Przelicz na 100g jeśli podano ilość
                    $grams = self::parseGrams($amount);
                    $factor = $grams > 0 ? (100 / $grams) : 1;

                    FoodCache::addFromAI($name, [
                        'kcal' => round($kcal * $factor),
                        'protein' => round(($item['protein'] ?? 0) * $factor, 1),
                        'carbs' => round(($item['carbs'] ?? 0) * $factor, 1),
                        'fat' => round(($item['fat'] ?? 0) * $factor, 1),
                        'portion' => $grams ?: 100,
                    ]);
                }
            }

            return [
                'source' => 'ai_estimate',
                'estimate' => $estimate,
            ];
        }

        return [
            'source' => 'none',
            'error' => 'Nie znaleziono produktu',
        ];
    }

    /**
     * Wyciągnij gramy z opisu ilości (np. "100g" → 100, "1 szt" → 0)
     */
    private static function parseGrams(string $amount): int
    {
        if (preg_match('/(\d+)\s*g/i', $amount, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(\d+)\s*ml/i', $amount, $m)) {
            return (int) $m[1]; // przybliżenie ml ≈ g
        }
        return 0;
    }

    private static function httpGet(string $url): ?array
    {
        $ch = curl_init($url);
        $caPath = STORAGE_PATH . '/cacert.pem';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_CAINFO => $caPath,
            CURLOPT_USERAGENT => 'DIEtetyk/1.0 (contact@dieteityk.app)',
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("OFF API error: $error");
            return null;
        }

        return json_decode($response, true);
    }
}
