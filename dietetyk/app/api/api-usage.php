<?php

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();

if (Auth::currentUserId() !== 1) {
    json_response(['error' => 'Brak uprawnień'], 403);
}

require_get();

$type = $_GET['type'] ?? 'summary';

switch ($type) {
    case 'summary':
        json_response([
            'today' => ApiUsage::getTodaySummary(),
            'month' => ApiUsage::getMonthSummary(),
        ]);
        break;

    case 'daily':
        $days = min(90, max(1, (int) ($_GET['days'] ?? 30)));
        json_response(['days' => ApiUsage::getDailySummaries($days)]);
        break;

    case 'recent':
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
        json_response(['calls' => ApiUsage::getRecentCalls($limit)]);
        break;

    default:
        json_response(['error' => 'Nieznany typ'], 400);
}
