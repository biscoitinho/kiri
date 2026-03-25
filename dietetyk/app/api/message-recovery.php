<?php

/**
 * Recovery endpoint - pobiera odpowiedź AI po zerwaniu połączenia SSE
 * GET /api/message-recovery?project_id=X&after_id=Y
 */

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();
require_get();

$projectId = (int) ($_GET['project_id'] ?? 0);
$afterId = (int) ($_GET['after_id'] ?? 0);

if (!$projectId || !$afterId) {
    json_response(['error' => 'Brak project_id lub after_id'], 400);
}
require_project_owner($projectId);

$message = Message::getAssistantAfter($projectId, $afterId);

json_response([
    'found' => $message !== null,
    'message' => $message,
]);
