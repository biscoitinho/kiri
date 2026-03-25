<?php

class Project
{
    public static function all(?int $userId = null): array
    {
        $db = Database::get();
        if ($userId) {
            $stmt = $db->prepare("SELECT * FROM projects WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        }
        return $db->query("SELECT * FROM projects ORDER BY created_at DESC")->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $name, string $description = '', ?int $userId = null): int
    {
        $db = Database::get();
        $userId = $userId ?? Auth::currentUserId() ?? 1;
        $stmt = $db->prepare("INSERT INTO projects (name, description, user_id) VALUES (?, ?, ?)");
        $stmt->execute([$name, $description, $userId]);
        return (int) $db->lastInsertId();
    }

    /**
     * Sprawdza czy projekt należy do danego usera
     */
    public static function belongsToUser(int $projectId, int $userId): bool
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT COUNT(*) FROM projects WHERE id = ? AND user_id = ?");
        $stmt->execute([$projectId, $userId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function update(int $id, string $name, string $description, string $status = 'active'): void
    {
        $db = Database::get();
        $stmt = $db->prepare("UPDATE projects SET name = ?, description = ?, status = ? WHERE id = ?");
        $stmt->execute([$name, $description, $status, $id]);
    }

    public static function delete(int $id): void
    {
        $db = Database::get();
        $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->execute([$id]);
    }

    public static function getStats(int $id): array
    {
        $db = Database::get();

        $msgCount = $db->prepare("SELECT COUNT(*) FROM messages WHERE project_id = ?");
        $msgCount->execute([$id]);

        $insightCount = $db->prepare("SELECT COUNT(*) FROM insights WHERE project_id = ?");
        $insightCount->execute([$id]);

        $lastCheckpoint = $db->prepare("SELECT * FROM checkpoints WHERE project_id = ? ORDER BY created_at DESC LIMIT 1");
        $lastCheckpoint->execute([$id]);

        return [
            'message_count' => (int) $msgCount->fetchColumn(),
            'insight_count' => (int) $insightCount->fetchColumn(),
            'last_checkpoint' => $lastCheckpoint->fetch() ?: null,
        ];
    }
}
