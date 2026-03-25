<?php
set_time_limit(60);

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();
require_post();
check_csrf();

$data = json_input();

$projectId = (int) ($data['project_id'] ?? 0);
$userMessage = trim($data['user_message'] ?? '');
$aiResponse = trim($data['ai_response'] ?? '');

if (!$projectId || !$userMessage || !$aiResponse) {
    json_response(['error' => 'Brak wymaganych pól'], 400);
}
require_project_owner($projectId);

$newInsights = InsightExtractor::extract($projectId, $userMessage, $aiResponse);

json_response(['new_insights' => $newInsights]);
