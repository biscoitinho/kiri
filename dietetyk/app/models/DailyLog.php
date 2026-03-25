<?php

class DailyLog
{
    public static function getByProject(int $projectId, int $limit = 14): array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM daily_logs WHERE project_id = ? ORDER BY date DESC LIMIT ?");
        $stmt->execute([$projectId, $limit]);
        return $stmt->fetchAll();
    }

    public static function getByDate(int $projectId, string $date): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM daily_logs WHERE project_id = ? AND date = ?");
        $stmt->execute([$projectId, $date]);
        return $stmt->fetch() ?: null;
    }

    public static function getToday(int $projectId): ?array
    {
        return self::getByDate($projectId, date('Y-m-d'));
    }

    public static function upsert(int $projectId, string $date, array $data): int
    {
        $db = Database::get();
        $existing = self::getByDate($projectId, $date);

        if ($existing) {
            $fields = [];
            $values = [];
            foreach ($data as $key => $value) {
                $fields[] = "$key = ?";
                $values[] = $value;
            }
            $values[] = $existing['id'];
            $stmt = $db->prepare("UPDATE daily_logs SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($values);
            return $existing['id'];
        } else {
            $data['project_id'] = $projectId;
            $data['date'] = $date;
            $columns = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            $stmt = $db->prepare("INSERT INTO daily_logs ($columns) VALUES ($placeholders)");
            $stmt->execute(array_values($data));
            return (int) $db->lastInsertId();
        }
    }
}
