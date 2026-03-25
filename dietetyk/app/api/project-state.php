<?php

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if (!$projectId) json_response(['error' => 'Brak project_id'], 400);
    require_project_owner($projectId);

    $state = ProjectState::get($projectId);
    json_response(['state' => $state]);
}

if ($method === 'POST') {
    check_csrf();
    $data = json_input();

    $projectId = (int) ($data['project_id'] ?? 0);
    if (!$projectId) json_response(['error' => 'Brak project_id'], 400);
    require_project_owner($projectId);

    unset($data['project_id'], $data['_csrf']);
    ProjectState::upsert($projectId, $data);
    json_response(['message' => 'Stan zaktualizowany']);
}

json_response(['error' => 'Nieobsługiwana metoda'], 405);
