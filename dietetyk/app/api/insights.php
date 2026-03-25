<?php

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if (!$projectId) json_response(['error' => 'Brak project_id'], 400);
    require_project_owner($projectId);

    $insights = Insight::getByProject($projectId);
    json_response(['insights' => $insights]);
}

if ($method === 'POST') {
    check_csrf();
    $data = json_input();

    $action = $data['action'] ?? 'create';

    if ($action === 'create') {
        $projectId = (int) ($data['project_id'] ?? 0);
        $type = trim($data['type'] ?? '');
        $content = trim($data['content'] ?? '');
        $priority = (int) ($data['priority'] ?? 5);

        if (!$projectId || !$type || !$content) {
            json_response(['error' => 'Brak wymaganych pól'], 400);
        }
        require_project_owner($projectId);

        $id = Insight::create($projectId, $type, $content, $priority);
        json_response(['id' => $id, 'message' => 'Insight dodany']);
    }

    if ($action === 'update') {
        $id = (int) ($data['id'] ?? 0);
        $content = trim($data['content'] ?? '');
        $priority = (int) ($data['priority'] ?? 5);

        if (!$id || !$content) {
            json_response(['error' => 'Brak wymaganych pól'], 400);
        }

        Insight::update($id, $content, $priority);
        json_response(['message' => 'Insight zaktualizowany']);
    }

    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if (!$id) json_response(['error' => 'Brak id'], 400);

        Insight::delete($id);
        json_response(['message' => 'Insight usunięty']);
    }
}

json_response(['error' => 'Nieobsługiwana metoda'], 405);
