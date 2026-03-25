<?php

class Message
{
    public static function getByProject(int $projectId, int $limit = 50, int $offset = 0): array
    {
        $db = Database::get();
        // Pobierz ostatnie N wiadomości (najnowsze), potem sortuj chronologicznie
        $stmt = $db->prepare("
            SELECT * FROM (
                SELECT * FROM messages
                WHERE project_id = ?
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ) sub ORDER BY created_at ASC
        ");
        $stmt->execute([$projectId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public static function getRecent(int $projectId, int $limit = 20): array
    {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT * FROM (
                SELECT * FROM messages
                WHERE project_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ) sub ORDER BY created_at ASC
        ");
        $stmt->execute([$projectId, $limit]);
        return $stmt->fetchAll();
    }

    public static function create(int $projectId, string $role, string $content): int
    {
        $db = Database::get();
        $stmt = $db->prepare("INSERT INTO messages (project_id, role, content) VALUES (?, ?, ?)");
        $stmt->execute([$projectId, $role, $content]);
        return (int) $db->lastInsertId();
    }

    public static function countByProject(int $projectId): int
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE project_id = ?");
        $stmt->execute([$projectId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Pobierz ostatnią wiadomość assistant po danym ID (do recovery po zerwaniu SSE)
     */
    public static function getAssistantAfter(int $projectId, int $afterId): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT * FROM messages
            WHERE project_id = ? AND id > ? AND role = 'assistant'
            ORDER BY id ASC LIMIT 1
        ");
        $stmt->execute([$projectId, $afterId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Pobierz ostatnią wiadomość użytkownika (do ustalenia after_id)
     */
    public static function getLastByRole(int $projectId, string $role): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT * FROM messages
            WHERE project_id = ? AND role = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$projectId, $role]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
