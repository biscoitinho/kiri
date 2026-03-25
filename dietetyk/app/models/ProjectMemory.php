<?php

class ProjectMemory
{
    public static function getByProject(int $projectId, ?int $limit = null): array
    {
        $db = Database::get();
        $sql = "SELECT * FROM project_memory WHERE project_id = ? ORDER BY priority DESC, updated_at DESC";
        if ($limit) $sql .= " LIMIT " . (int) $limit;
        $stmt = $db->prepare($sql);
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    public static function getTop(int $projectId, int $limit = 15): array
    {
        return self::getByProject($projectId, $limit);
    }

    public static function getByCategory(int $projectId, string $category): array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM project_memory WHERE project_id = ? AND category = ? ORDER BY priority DESC");
        $stmt->execute([$projectId, $category]);
        return $stmt->fetchAll();
    }

    public static function create(int $projectId, string $category, string $content, int $priority = 5, ?int $sourceBlockId = null): int
    {
        $db = Database::get();
        $stmt = $db->prepare("
            INSERT INTO project_memory (project_id, category, content, priority, source_summary_block_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$projectId, $category, $content, $priority, $sourceBlockId]);
        return (int) $db->lastInsertId();
    }

    public static function update(int $id, string $content, int $priority): void
    {
        $db = Database::get();
        $stmt = $db->prepare("UPDATE project_memory SET content = ?, priority = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$content, $priority, $id]);
    }

    public static function delete(int $id): void
    {
        $db = Database::get();
        $stmt = $db->prepare("DELETE FROM project_memory WHERE id = ?");
        $stmt->execute([$id]);
    }

    public static function isDuplicate(int $projectId, string $category, string $content): bool
    {
        $existing = self::getByCategory($projectId, $category);
        $contentLower = strtolower($content);

        foreach ($existing as $item) {
            similar_text(strtolower($item['content']), $contentLower, $percent);
            if ($percent > 70) return true;
        }
        return false;
    }

    public static function countByProject(int $projectId): int
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT COUNT(*) FROM project_memory WHERE project_id = ?");
        $stmt->execute([$projectId]);
        return (int) $stmt->fetchColumn();
    }

    public static function getCategories(): array
    {
        return ['goal', 'nutrition', 'training', 'injury', 'recovery', 'psychology', 'competition', 'status', 'diet', 'strategy'];
    }
}
