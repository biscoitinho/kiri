<?php

class Checkpoint
{
    public static function getByProject(int $projectId, int $limit = 30): array
    {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT * FROM checkpoints
            WHERE project_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$projectId, $limit]);
        return $stmt->fetchAll();
    }

    public static function getLatest(int $projectId): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM checkpoints WHERE project_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$projectId]);
        return $stmt->fetch() ?: null;
    }

    public static function create(int $projectId, ?float $weight, ?float $sleepHours, ?int $hungerLevel, ?int $energyLevel, string $notes = ''): int
    {
        $db = Database::get();
        $stmt = $db->prepare("
            INSERT INTO checkpoints (project_id, weight, sleep_hours, hunger_level, energy_level, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$projectId, $weight, $sleepHours, $hungerLevel, $energyLevel, $notes]);
        return (int) $db->lastInsertId();
    }

    public static function getWeightHistory(int $projectId, int $limit = 60): array
    {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT weight, created_at FROM checkpoints
            WHERE project_id = ? AND weight IS NOT NULL
            ORDER BY created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$projectId, $limit]);
        return $stmt->fetchAll();
    }
}
