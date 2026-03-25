<?php
set_time_limit(30);

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if (!$projectId) json_response(['error' => 'Brak project_id'], 400);
    require_project_owner($projectId);

    $type = $_GET['type'] ?? 'today';

    if ($type === 'today') {
        $meals = MealLog::getToday($projectId);
        $totals = MealLog::getTodayTotals($projectId);
        json_response(['meals' => $meals, 'totals' => $totals]);
    }

    if ($type === 'history') {
        $days = (int) ($_GET['days'] ?? 7);
        $history = MealLog::getHistory($projectId, $days);
        json_response(['history' => $history]);
    }

    if ($type === 'calendar') {
        $days = (int) ($_GET['days'] ?? 90);
        $history = MealLog::getHistory($projectId, $days);
        json_response(['calendar' => $history]);
    }

    json_response(['error' => 'Nieznany typ'], 400);
}

if ($method === 'POST') {
    check_csrf();
    $data = json_input();
    $action = $data['action'] ?? 'log';

    // Ownership check for all POST actions
    $postProjectId = (int) ($data['project_id'] ?? 0);
    if ($postProjectId) require_project_owner($postProjectId);

    if ($action === 'log') {
        $projectId = (int) ($data['project_id'] ?? 0);
        $description = trim($data['description'] ?? '');
        $mealType = $data['meal_type'] ?? 'other';

        if (!$projectId || !$description) {
            json_response(['error' => 'Brak project_id lub opisu'], 400);
        }

        // Seed lokalnej bazy przy pierwszym użyciu
        FoodCache::seed();

        // Szukaj: lokalna baza → AI → OFF fallback
        $lookup = FoodDatabase::lookup($description);

        if ($lookup['source'] === 'local' && !empty($lookup['results'])) {
            $product = $lookup['results'][0];
            $per100 = $product['per_100g'];
            $parsedGrams = $lookup['parsed_grams'] ?? 0;
            $parsedPieces = $lookup['parsed_pieces'] ?? 0;
            $typicalPortion = $product['typical_portion_g'] ?? 100;

            // Sztuki: 5 szt × typical_portion_g; Gramy: użyj wprost; Brak: typical_portion
            if ($parsedPieces > 0) {
                $portion = $parsedPieces * $typicalPortion;
            } elseif ($parsedGrams > 0) {
                $portion = $parsedGrams;
            } else {
                $portion = $typicalPortion;
            }
            $factor = $portion / 100;

            $id = MealLog::create(
                $projectId, $description, $mealType,
                [$product],
                (int) round($per100['kcal'] * $factor),
                round($per100['protein'] * $factor, 1),
                round($per100['carbs'] * $factor, 1),
                round($per100['fat'] * $factor, 1),
                'local'
            );

            json_response([
                'id' => $id,
                'source' => 'local',
                'product' => $product,
                'portion_g' => $portion,
                'nutrition' => [
                    'kcal' => (int) round($per100['kcal'] * $factor),
                    'protein' => round($per100['protein'] * $factor, 1),
                    'carbs' => round($per100['carbs'] * $factor, 1),
                    'fat' => round($per100['fat'] * $factor, 1),
                ],
                'totals' => MealLog::getTodayTotals($projectId),
            ]);
        }

        if ($lookup['source'] === 'ai_estimate' && !empty($lookup['estimate'])) {
            $estimate = $lookup['estimate'];
            $total = $estimate['total'] ?? [];

            $id = MealLog::create(
                $projectId, $description, $mealType,
                $estimate['items'] ?? [],
                (int) ($total['kcal'] ?? 0),
                (float) ($total['protein'] ?? 0),
                (float) ($total['carbs'] ?? 0),
                (float) ($total['fat'] ?? 0),
                'ai_estimate'
            );

            json_response([
                'id' => $id,
                'source' => 'ai_estimate',
                'estimate' => $estimate,
                'totals' => MealLog::getTodayTotals($projectId),
            ]);
        }

        if ($lookup['source'] === 'openfoodfacts' && !empty($lookup['results'])) {
            $product = $lookup['results'][0];
            $per100 = $product['per_100g'];
            $portion = 150;
            $factor = $portion / 100;

            $id = MealLog::create(
                $projectId, $description, $mealType,
                [$product],
                (int) round($per100['kcal'] * $factor),
                round($per100['protein'] * $factor, 1),
                round($per100['carbs'] * $factor, 1),
                round($per100['fat'] * $factor, 1),
                'openfoodfacts'
            );

            json_response([
                'id' => $id,
                'source' => 'openfoodfacts',
                'product' => $product,
                'portion_g' => $portion,
                'nutrition' => [
                    'kcal' => (int) round($per100['kcal'] * $factor),
                    'protein' => round($per100['protein'] * $factor, 1),
                    'carbs' => round($per100['carbs'] * $factor, 1),
                    'fat' => round($per100['fat'] * $factor, 1),
                ],
                'totals' => MealLog::getTodayTotals($projectId),
            ]);
        }

        // Nie znaleziono - zapisz bez kalorii
        $id = MealLog::create($projectId, $description, $mealType);
        json_response([
            'id' => $id,
            'source' => 'manual',
            'message' => 'Zapisano bez szacunku kalorii',
            'totals' => MealLog::getTodayTotals($projectId),
        ]);
    }

    if ($action === 'replace') {
        $projectId = (int) ($data['project_id'] ?? 0);
        $oldName = trim($data['old_name'] ?? '');
        $description = trim($data['description'] ?? '');
        $mealType = $data['meal_type'] ?? 'other';

        if (!$projectId || !$oldName || !$description) {
            json_response(['error' => 'Brak project_id, old_name lub description'], 400);
        }

        // Znajdź stary wpis po nazwie
        $existing = MealLog::findTodayByName($projectId, $oldName);
        if ($existing) {
            MealLog::delete($existing['id']);
        }

        // Seed lokalnej bazy przy pierwszym użyciu
        FoodCache::seed();

        // Dodaj nowy wpis (identyczna logika jak 'log')
        $lookup = FoodDatabase::lookup($description);

        if ($lookup['source'] === 'local' && !empty($lookup['results'])) {
            $product = $lookup['results'][0];
            $per100 = $product['per_100g'];
            $parsedGrams = $lookup['parsed_grams'] ?? 0;
            $parsedPieces = $lookup['parsed_pieces'] ?? 0;
            $typicalPortion = $product['typical_portion_g'] ?? 100;

            if ($parsedPieces > 0) {
                $portion = $parsedPieces * $typicalPortion;
            } elseif ($parsedGrams > 0) {
                $portion = $parsedGrams;
            } else {
                $portion = $typicalPortion;
            }
            $factor = $portion / 100;

            $id = MealLog::create(
                $projectId, $description, $mealType,
                [$product],
                (int) round($per100['kcal'] * $factor),
                round($per100['protein'] * $factor, 1),
                round($per100['carbs'] * $factor, 1),
                round($per100['fat'] * $factor, 1),
                'local'
            );

            json_response([
                'id' => $id,
                'replaced' => $existing ? (int) $existing['id'] : null,
                'source' => 'local',
                'portion_g' => $portion,
                'nutrition' => [
                    'kcal' => (int) round($per100['kcal'] * $factor),
                    'protein' => round($per100['protein'] * $factor, 1),
                    'carbs' => round($per100['carbs'] * $factor, 1),
                    'fat' => round($per100['fat'] * $factor, 1),
                ],
                'totals' => MealLog::getTodayTotals($projectId),
            ]);
        }

        if ($lookup['source'] === 'ai_estimate' && !empty($lookup['estimate'])) {
            $estimate = $lookup['estimate'];
            $total = $estimate['total'] ?? [];

            $id = MealLog::create(
                $projectId, $description, $mealType,
                $estimate['items'] ?? [],
                (int) ($total['kcal'] ?? 0),
                (float) ($total['protein'] ?? 0),
                (float) ($total['carbs'] ?? 0),
                (float) ($total['fat'] ?? 0),
                'ai_estimate'
            );

            json_response([
                'id' => $id,
                'replaced' => $existing ? (int) $existing['id'] : null,
                'source' => 'ai_estimate',
                'estimate' => $estimate,
                'totals' => MealLog::getTodayTotals($projectId),
            ]);
        }

        // Fallback - zapisz bez kalorii
        $id = MealLog::create($projectId, $description, $mealType);
        json_response([
            'id' => $id,
            'replaced' => $existing ? (int) $existing['id'] : null,
            'source' => 'manual',
            'totals' => MealLog::getTodayTotals($projectId),
        ]);
    }

    if ($action === 'remove-by-name') {
        $projectId = (int) ($data['project_id'] ?? 0);
        $name = trim($data['name'] ?? '');

        if (!$projectId || !$name) {
            json_response(['error' => 'Brak project_id lub name'], 400);
        }

        $existing = MealLog::findTodayByName($projectId, $name);
        if ($existing) {
            MealLog::delete($existing['id']);
            json_response([
                'removed' => (int) $existing['id'],
                'totals' => MealLog::getTodayTotals($projectId),
            ]);
        }

        json_response(['removed' => null, 'message' => 'Nie znaleziono wpisu', 'totals' => MealLog::getTodayTotals($projectId)]);
    }

    if ($action === 'log-training') {
        $projectId = (int) ($data['project_id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $duration = (int) ($data['duration_min'] ?? 0);
        $kcalBurned = (int) ($data['kcal_burned'] ?? 0);
        $intensity = trim($data['intensity'] ?? 'umiarkowana');

        if (!$projectId || !$name || !$kcalBurned) {
            json_response(['error' => 'Brak danych treningu'], 400);
        }

        $desc = "{$name} {$duration} min ({$intensity})";

        $id = MealLog::create(
            $projectId, $desc, 'training', [],
            $kcalBurned, 0, 0, 0, 'training'
        );

        json_response([
            'id' => $id,
            'description' => $desc,
            'kcal_burned' => $kcalBurned,
            'totals' => MealLog::getTodayTotals($projectId),
        ]);
    }

    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if (!$id) json_response(['error' => 'Brak id'], 400);
        MealLog::delete($id);
        $projectId = (int) ($data['project_id'] ?? 0);
        json_response([
            'message' => 'Usunięto',
            'totals' => $projectId ? MealLog::getTodayTotals($projectId) : null,
        ]);
    }
}

json_response(['error' => 'Nieobsługiwana metoda'], 405);
