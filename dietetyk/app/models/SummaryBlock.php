<?php

class SummaryBlock
{
    public static function getByProject(int $projectId, int $limit = 5): array
    {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT * FROM summary_blocks
            WHERE project_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$projectId, $limit]);
        return $stmt->fetchAll();
    }

    public static function getRecent(int $projectId, int $limit = 3): array
    {
        $rows = self::getByProject($projectId, $limit);
        return array_reverse($rows); // chronologicznie
    }

    public static function create(
        int $projectId,
        int $messageFromId,
        int $messageToId,
        string $summary,
        ?array $facts = null,
        ?array $stateUpdates = null,
        ?array $temporaryNotes = null
    ): int {
        $db = Database::get();
        $stmt = $db->prepare("
            INSERT INTO summary_blocks (project_id, message_from_id, message_to_id, summary, facts_json, state_updates_json, temporary_notes_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $projectId,
            $messageFromId,
            $messageToId,
            $summary,
            $facts ? json_encode($facts, JSON_UNESCAPED_UNICODE) : null,
            $stateUpdates ? json_encode($stateUpdates, JSON_UNESCAPED_UNICODE) : null,
            $temporaryNotes ? json_encode($temporaryNotes, JSON_UNESCAPED_UNICODE) : null,
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * Znajdź ostatni streszczony message_id dla projektu
     */
    public static function getLastSummarizedMessageId(int $projectId): int
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT MAX(message_to_id) FROM summary_blocks WHERE project_id = ?");
        $stmt->execute([$projectId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Pobierz niestreszczone wiadomości (po ostatnim summary block)
     */
    public static function getUnsummarizedMessages(int $projectId): array
    {
        $lastId = self::getLastSummarizedMessageId($projectId);
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT * FROM messages
            WHERE project_id = ? AND id > ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$projectId, $lastId]);
        return $stmt->fetchAll();
    }

    public static function countUnsummarized(int $projectId): int
    {
        $lastId = self::getLastSummarizedMessageId($projectId);
        $db = Database::get();
        $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE project_id = ? AND id > ?");
        $stmt->execute([$projectId, $lastId]);
        return (int) $stmt->fetchColumn();
    }
}
