<?php

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if (!$projectId) json_response(['error' => 'Brak project_id'], 400);
    require_project_owner($projectId);

    $category = $_GET['category'] ?? null;

    if ($category) {
        $memory = ProjectMemory::getByCategory($projectId, $category);
    } else {
        $memory = ProjectMemory::getByProject($projectId);
    }

    json_response(['memory' => $memory]);
}

if ($method === 'POST') {
    check_csrf();
    $data = json_input();
    $action = $data['action'] ?? 'create';

    if ($action === 'create') {
        $projectId = (int) ($data['project_id'] ?? 0);
        $category = trim($data['category'] ?? '');
        $content = trim($data['content'] ?? '');
        $priority = (int) ($data['priority'] ?? 5);

        if (!$projectId || !$category || !$content) {
            json_response(['error' => 'Brak wymaganych pól'], 400);
        }
        require_project_owner($projectId);

        $id = ProjectMemory::create($projectId, $category, $content, $priority);
        json_response(['id' => $id, 'message' => 'Pamięć dodana']);
    }

    if ($action === 'update') {
        $id = (int) ($data['id'] ?? 0);
        $content = trim($data['content'] ?? '');
        $priority = (int) ($data['priority'] ?? 5);

        if (!$id || !$content) json_response(['error' => 'Brak wymaganych pól'], 400);

        ProjectMemory::update($id, $content, $priority);
        json_response(['message' => 'Pamięć zaktualizowana']);
    }

    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if (!$id) json_response(['error' => 'Brak id'], 400);

        ProjectMemory::delete($id);
        json_response(['message' => 'Pamięć usunięta']);
    }
}

json_response(['error' => 'Nieobsługiwana metoda'], 405);
