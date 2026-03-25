<?php

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();
require_get();

$projectId = (int) ($_GET['project_id'] ?? 0);
if (!$projectId) json_response(['error' => 'Brak project_id'], 400);
require_project_owner($projectId);

$limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

$messages = Message::getByProject($projectId, $limit, $offset);
$total = Message::countByProject($projectId);

json_response([
    'messages' => $messages,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
]);
