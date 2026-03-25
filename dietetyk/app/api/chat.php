<?php
set_time_limit(120);

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();
require_post();
check_csrf();

$data = json_input();

$projectId = (int) ($data['project_id'] ?? 0);
$message = trim($data['message'] ?? '');

if (!$projectId || !$message) {
    json_response(['error' => 'Brak project_id lub message'], 400);
}
require_project_owner($projectId);

$project = Project::find($projectId);
if (!$project) {
    json_response(['error' => 'Projekt nie znaleziony'], 404);
}

// Zapisz wiadomość użytkownika
Message::create($projectId, 'user', $message);

// Zbuduj 3-warstwowy kontekst
$systemPrompt = ContextBuilder::build($projectId);
$recentMessages = ContextBuilder::getRecentMessages($projectId);

// Wyślij do OpenAI (system prompt + ostatnie wiadomości, user msg już w recentMessages)
$aiResponse = OpenAIService::chatWithContext($systemPrompt, $recentMessages, '');

if ($aiResponse === null) {
    json_response(['error' => 'Błąd komunikacji z AI'], 502);
}

// Zapisz odpowiedź AI
Message::create($projectId, 'assistant', $aiResponse);

// Najpierw odpowiedz użytkownikowi, potem background tasks
$responseData = [
    'response' => $aiResponse,
    'new_insights' => [],
    'summary_generated' => false,
];

// Ekstrakcja insightów do project_memory (gpt-5-mini)
$newInsights = InsightExtractor::extract($projectId, $message, $aiResponse);
$responseData['new_insights'] = $newInsights;

// Sprawdź czy czas na summary block (co 20 wiadomości)
if (SummaryService::shouldSummarize($projectId)) {
    $blockId = SummaryService::generateSummary($projectId);
    $responseData['summary_generated'] = ($blockId !== null);
}

json_response($responseData);
