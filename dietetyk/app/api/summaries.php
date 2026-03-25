<?php
set_time_limit(120);

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if (!$projectId) json_response(['error' => 'Brak project_id'], 400);
    require_project_owner($projectId);

    $summaries = SummaryBlock::getByProject($projectId, 20);
    $unsummarized = SummaryBlock::countUnsummarized($projectId);

    json_response([
        'summaries' => $summaries,
        'unsummarized_messages' => $unsummarized,
    ]);
}

if ($method === 'POST') {
    check_csrf();
    $data = json_input();

    $projectId = (int) ($data['project_id'] ?? 0);
    if (!$projectId) json_response(['error' => 'Brak project_id'], 400);
    require_project_owner($projectId);

    $blockId = SummaryService::generateSummary($projectId);

    if ($blockId) {
        json_response(['message' => 'Summary block wygenerowany', 'block_id' => $blockId]);
    } else {
        json_response(['error' => 'Za mało wiadomości do streszczenia lub błąd AI'], 400);
    }
}

json_response(['error' => 'Nieobsługiwana metoda'], 405);
