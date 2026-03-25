<?php

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if (!$projectId) json_response(['error' => 'Brak project_id'], 400);
    require_project_owner($projectId);

    $type = $_GET['type'] ?? 'today';

    if ($type === 'correlation') {
        $days = min(90, max(7, (int) ($_GET['days'] ?? 30)));
        $since = date('Y-m-d', strtotime("-{$days} days"));
        $db = Database::get();

        // Daily logs (sen, waga, woda, aktywność)
        $stmt = $db->prepare("
            SELECT date, weight, sleep_hours, water_ml, activity_kcal
            FROM daily_logs
            WHERE project_id = ? AND date >= ?
            ORDER BY date ASC
        ");
        $stmt->execute([$projectId, $since]);
        $dailyLogs = $stmt->fetchAll();

        // Suma kalorii z meal_log per dzień
        $stmt = $db->prepare("
            SELECT DATE(created_at) as date,
                   SUM(total_kcal) as total_kcal,
                   SUM(total_protein) as total_protein,
                   SUM(total_carbs) as total_carbs,
                   SUM(total_fat) as total_fat
            FROM meal_log
            WHERE project_id = ? AND DATE(created_at) >= ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$projectId, $since]);
        $mealTotals = [];
        foreach ($stmt->fetchAll() as $row) {
            $mealTotals[$row['date']] = $row;
        }

        // Waga z checkpointów (uzupełnienie gdy brak w daily_logs)
        $stmt = $db->prepare("
            SELECT DATE(created_at) as date, weight
            FROM checkpoints
            WHERE project_id = ? AND weight IS NOT NULL AND DATE(created_at) >= ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$projectId, $since]);
        $checkpointWeights = [];
        foreach ($stmt->fetchAll() as $row) {
            $checkpointWeights[$row['date']] = (float) $row['weight'];
        }

        // Złóż dane w jedną timeline
        $result = [];
        $dailyByDate = [];
        foreach ($dailyLogs as $dl) {
            $dailyByDate[$dl['date']] = $dl;
        }

        // Generuj każdy dzień w zakresie
        $current = new DateTime($since);
        $end = new DateTime('today');
        while ($current <= $end) {
            $d = $current->format('Y-m-d');
            $dl = $dailyByDate[$d] ?? null;
            $ml = $mealTotals[$d] ?? null;

            $result[] = [
                'date' => $d,
                'weight' => ($dl && $dl['weight']) ? (float) $dl['weight'] : ($checkpointWeights[$d] ?? null),
                'sleep_hours' => ($dl && $dl['sleep_hours']) ? (float) $dl['sleep_hours'] : null,
                'water_ml' => ($dl && $dl['water_ml']) ? (int) $dl['water_ml'] : null,
                'activity_kcal' => ($dl && $dl['activity_kcal']) ? (int) $dl['activity_kcal'] : null,
                'kcal' => $ml ? (int) $ml['total_kcal'] : null,
                'protein' => $ml ? round((float) $ml['total_protein'], 1) : null,
            ];

            $current->modify('+1 day');
        }

        json_response(['correlation' => $result]);
    }

    // Domyślnie: dane na dziś
    $today = DailyLog::getToday($projectId);
    json_response(['daily_log' => $today]);
}

if ($method === 'POST') {
    check_csrf();
    $data = json_input();

    $projectId = (int) ($data['project_id'] ?? 0);
    if (!$projectId) json_response(['error' => 'Brak project_id'], 400);
    require_project_owner($projectId);

    $date = $data['date'] ?? date('Y-m-d');
    unset($data['project_id'], $data['_csrf'], $data['date']);

    if (empty($data)) {
        json_response(['error' => 'Brak danych do zapisania'], 400);
    }

    $id = DailyLog::upsert($projectId, $date, $data);

    // Jeśli podano wagę, zaktualizuj project_state
    if (!empty($data['weight'])) {
        ProjectState::upsert($projectId, ['current_weight_kg' => (float) $data['weight']]);
    }

    json_response(['id' => $id, 'message' => 'Zapisano']);
}

json_response(['error' => 'Nieobsługiwana metoda'], 405);
