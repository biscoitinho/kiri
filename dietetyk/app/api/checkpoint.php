<?php

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if (!$projectId) json_response(['error' => 'Brak project_id'], 400);
    require_project_owner($projectId);

    $type = $_GET['type'] ?? 'list';

    if ($type === 'weight_history') {
        $data = Checkpoint::getWeightHistory($projectId);
        json_response(['weight_history' => $data]);
    }

    $checkpoints = Checkpoint::getByProject($projectId);
    $latest = $checkpoints[0] ?? null;
    json_response(['checkpoints' => $checkpoints, 'latest' => $latest]);
}

if ($method === 'POST') {
    check_csrf();
    $data = json_input();

    $projectId = (int) ($data['project_id'] ?? 0);
    if (!$projectId) json_response(['error' => 'Brak project_id'], 400);
    require_project_owner($projectId);

    $weight = isset($data['weight']) ? (float) $data['weight'] : null;
    $sleepHours = isset($data['sleep_hours']) ? (float) $data['sleep_hours'] : null;
    $hungerLevel = isset($data['hunger_level']) ? (int) $data['hunger_level'] : null;
    $energyLevel = isset($data['energy_level']) ? (int) $data['energy_level'] : null;
    $notes = trim($data['notes'] ?? '');

    if (!$weight && !$sleepHours && $hungerLevel === null && $energyLevel === null && !$notes) {
        json_response(['error' => 'Podaj przynajmniej jedną wartość'], 400);
    }

    $id = Checkpoint::create($projectId, $weight, $sleepHours, $hungerLevel, $energyLevel, $notes);

    // Aktualizuj project_state jeśli podano wagę
    if ($weight) {
        ProjectState::upsert($projectId, ['current_weight_kg' => $weight]);
    }

    json_response(['id' => $id, 'message' => 'Checkpoint zapisany']);
}

json_response(['error' => 'Nieobsługiwana metoda'], 405);
