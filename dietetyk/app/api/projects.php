<?php

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();

$method = $_SERVER['REQUEST_METHOD'];
$userId = Auth::currentUserId();

if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);

    if ($id) {
        if (!Project::belongsToUser($id, $userId)) json_response(['error' => 'Brak dostępu'], 403);
        $project = Project::find($id);
        if (!$project) json_response(['error' => 'Nie znaleziono'], 404);
        $project['stats'] = Project::getStats($id);
        json_response(['project' => $project]);
    }

    $projects = Project::all($userId);
    json_response(['projects' => $projects]);
}

if ($method === 'POST') {
    check_csrf();
    $data = json_input();

    $action = $data['action'] ?? 'create';

    if ($action === 'create') {
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        if (!$name) json_response(['error' => 'Brak nazwy'], 400);

        $id = Project::create($name, $description, $userId);
        json_response(['id' => $id, 'message' => 'Projekt utworzony']);
    }

    if ($action === 'update') {
        $id = (int) ($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $status = $data['status'] ?? 'active';
        if (!$id || !$name) json_response(['error' => 'Brak wymaganych pól'], 400);
        if (!Project::belongsToUser($id, $userId)) json_response(['error' => 'Brak dostępu'], 403);

        Project::update($id, $name, $description, $status);
        json_response(['message' => 'Projekt zaktualizowany']);
    }
}

json_response(['error' => 'Nieobsługiwana metoda'], 405);
