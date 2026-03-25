<?php

class Insight
{
    public static function getByProject(int $projectId, ?int $limit = null): array
    {
        $db = Database::get();
        $sql = "SELECT * FROM insights WHERE project_id = ? ORDER BY priority DESC, created_at DESC";
        if ($limit) {
            $sql .= " LIMIT " . (int) $limit;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    public static function getTopByProject(int $projectId, int $limit = 10): array
    {
        return self::getByProject($projectId, $limit);
    }

    public static function create(int $projectId, string $type, string $content, int $priority = 5): int
    {
        $db = Database::get();
        $stmt = $db->prepare("INSERT INTO insights (project_id, type, content, priority) VALUES (?, ?, ?, ?)");
        $stmt->execute([$projectId, $type, $content, $priority]);
        return (int) $db->lastInsertId();
    }

    public static function update(int $id, string $content, int $priority): void
    {
        $db = Database::get();
        $stmt = $db->prepare("UPDATE insights SET content = ?, priority = ? WHERE id = ?");
        $stmt->execute([$content, $priority, $id]);
    }

    public static function delete(int $id): void
    {
        $db = Database::get();
        $stmt = $db->prepare("DELETE FROM insights WHERE id = ?");
        $stmt->execute([$id]);
    }

    public static function getTypes(): array
    {
        return ['goal', 'diet', 'nutrition', 'training', 'injury', 'recovery', 'strategy', 'psychology', 'competition', 'progress'];
    }
}
