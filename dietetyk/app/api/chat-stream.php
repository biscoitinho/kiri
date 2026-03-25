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
$userMsgId = Message::create($projectId, 'user', $message);

// Zbuduj kontekst
$systemPrompt = ContextBuilder::build($projectId);
$recentMessages = ContextBuilder::getRecentMessages($projectId);

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Wyłącz buforowanie
if (ob_get_level()) ob_end_clean();

// Wyślij ID wiadomości user (do recovery po zerwaniu połączenia)
echo "data: " . json_encode(['type' => 'msg_id', 'id' => $userMsgId]) . "\n\n";
flush();

// Streaming (nie podajemy $message osobno - jest już w recentMessages)
$fullResponse = OpenAIService::chatStream(
    $systemPrompt,
    $recentMessages,
    '',
    function (string $token) {
        echo "data: " . json_encode(['type' => 'token', 'content' => $token], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }
);

if ($fullResponse === null) {
    echo "data: " . json_encode(['type' => 'error', 'content' => 'Błąd komunikacji z AI']) . "\n\n";
    flush();
    echo "data: [DONE]\n\n";
    flush();
    exit;
}

// Zapisz pełną odpowiedź do DB
Message::create($projectId, 'assistant', $fullResponse);

// Wyślij sygnał zakończenia
echo "data: " . json_encode(['type' => 'done', 'content' => $fullResponse]) . "\n\n";
flush();

// Sprawdź summary (nie blokuje - user już widzi odpowiedź)
if (SummaryService::shouldSummarize($projectId)) {
    $memBefore = ProjectMemory::countByProject($projectId);
    SummaryService::generateSummary($projectId);
    $memAfter = ProjectMemory::countByProject($projectId);
    if ($memAfter > $memBefore) {
        $newCount = $memAfter - $memBefore;
        // Pobierz najnowsze wpisy (po dacie, nie po priorytecie)
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM project_memory WHERE project_id = ? ORDER BY updated_at DESC LIMIT ?");
        $stmt->execute([$projectId, $newCount]);
        $newMemories = $stmt->fetchAll();
        echo "data: " . json_encode(['type' => 'memory_updated', 'count' => $memAfter, 'new' => $newMemories], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }
}

echo "data: [DONE]\n\n";
flush();
