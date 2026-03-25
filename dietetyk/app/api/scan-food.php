<?php

set_time_limit(60);

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();
require_post();
check_csrf();

$projectId = (int) ($_POST['project_id'] ?? 0);
if (!$projectId) json_response(['error' => 'Brak project_id'], 400);
require_project_owner($projectId);

// Obsługa uploadu wielu plików (photos[])
$files = $_FILES['photos'] ?? null;
if (!$files || !is_array($files['name'])) {
    json_response(['error' => 'Brak zdjęć'], 400);
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$maxSize = 10 * 1024 * 1024; // 10MB per file
$images = [];

$fileCount = count($files['name']);
for ($i = 0; $i < $fileCount; $i++) {
    if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
    if ($files['size'][$i] > $maxSize) continue;

    $mime = mime_content_type($files['tmp_name'][$i]);
    if (!in_array($mime, $allowedTypes)) continue;

    $images[] = [
        'base64' => base64_encode(file_get_contents($files['tmp_name'][$i])),
        'mime' => $mime,
    ];
}

if (empty($images)) {
    json_response(['error' => 'Brak prawidłowych zdjęć'], 400);
}

if (count($images) > 5) {
    json_response(['error' => 'Max 5 zdjęć na raz'], 400);
}

// Analizuj przez Vision API
$result = OpenAIService::analyzeImage($images);

if ($result === null) {
    json_response(['error' => 'Nie udało się przeanalizować zdjęć'], 500);
}

if (isset($result['error'])) {
    json_response(['error' => $result['error']], 400);
}

// Zapisz do food_cache
$name = $result['name'] ?? 'Nieznany produkt';
FoodCache::addFromAI($name, [
    'kcal' => $result['kcal_100g'] ?? 0,
    'protein' => $result['protein_100g'] ?? 0,
    'carbs' => $result['carbs_100g'] ?? 0,
    'fat' => $result['fat_100g'] ?? 0,
    'portion' => $result['typical_portion_g'] ?? 100,
], $result['brand'] ?? '');

json_response([
    'success' => true,
    'product' => $result,
]);
