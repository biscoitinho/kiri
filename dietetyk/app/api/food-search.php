<?php

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $query = trim($_GET['q'] ?? '');
    $barcode = trim($_GET['barcode'] ?? '');

    if ($barcode) {
        $result = FoodDatabase::getByBarcode($barcode);
        if ($result) {
            json_response(['source' => 'openfoodfacts', 'product' => $result]);
        } else {
            json_response(['error' => 'Nie znaleziono produktu'], 404);
        }
    }

    if (!$query) json_response(['error' => 'Brak zapytania (?q=...)'], 400);

    $result = FoodDatabase::lookup($query);
    json_response($result);
}

json_response(['error' => 'Nieobsługiwana metoda'], 405);
