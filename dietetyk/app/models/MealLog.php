<?php

class MealLog
{
    public static function create(int $projectId, string $description, string $mealType = 'other', array $items = [], int $kcal = 0, float $protein = 0, float $carbs = 0, float $fat = 0, string $source = 'manual'): int
    {
        $db = Database::get();
        $stmt = $db->prepare("
            INSERT INTO meal_log (project_id, meal_type, description, items_json, total_kcal, total_protein, total_carbs, total_fat, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $projectId, $mealType, $description,
            !empty($items) ? json_encode($items, JSON_UNESCAPED_UNICODE) : null,
            $kcal, $protein, $carbs, $fat, $source,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function getToday(int $projectId): array
    {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT * FROM meal_log
            WHERE project_id = ? AND DATE(created_at, 'localtime') = DATE('now', 'localtime')
            ORDER BY created_at ASC
        ");
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    public static function getTodayTotals(int $projectId): array
    {
        $db = Database::get();
        // Jedzenie (bez treningów)
        $stmt = $db->prepare("
            SELECT
                COALESCE(SUM(total_kcal), 0) as kcal,
                COALESCE(SUM(total_protein), 0) as protein,
                COALESCE(SUM(total_carbs), 0) as carbs,
                COALESCE(SUM(total_fat), 0) as fat,
                COUNT(*) as meal_count
            FROM meal_log
            WHERE project_id = ? AND DATE(created_at, 'localtime') = DATE('now', 'localtime')
              AND source != 'training'
        ");
        $stmt->execute([$projectId]);
        $totals = $stmt->fetch();

        // Spalone kalorie (treningi)
        $stmt2 = $db->prepare("
            SELECT COALESCE(SUM(total_kcal), 0) as burned, COUNT(*) as training_count
            FROM meal_log
            WHERE project_id = ? AND DATE(created_at, 'localtime') = DATE('now', 'localtime')
              AND source = 'training'
        ");
        $stmt2->execute([$projectId]);
        $training = $stmt2->fetch();

        $totals['burned'] = (int) $training['burned'];
        $totals['training_count'] = (int) $training['training_count'];
        $totals['net_kcal'] = (int) $totals['kcal'] - (int) $training['burned'];

        return $totals;
    }

    public static function getByDate(int $projectId, string $date): array
    {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT * FROM meal_log
            WHERE project_id = ? AND DATE(created_at) = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$projectId, $date]);
        return $stmt->fetchAll();
    }

    public static function getHistory(int $projectId, int $days = 7): array
    {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT DATE(created_at, 'localtime') as date,
                   SUM(CASE WHEN source != 'training' THEN total_kcal ELSE 0 END) as kcal,
                   SUM(CASE WHEN source = 'training' THEN total_kcal ELSE 0 END) as burned,
                   SUM(CASE WHEN source != 'training' THEN total_protein ELSE 0 END) as protein,
                   SUM(CASE WHEN source != 'training' THEN total_carbs ELSE 0 END) as carbs,
                   SUM(CASE WHEN source != 'training' THEN total_fat ELSE 0 END) as fat,
                   COUNT(*) as meals
            FROM meal_log
            WHERE project_id = ? AND DATE(created_at, 'localtime') >= DATE('now', 'localtime', ?)
            GROUP BY DATE(created_at, 'localtime')
            ORDER BY date ASC
        ");
        $stmt->execute([$projectId, "-{$days} days"]);
        return $stmt->fetchAll();
    }

    public static function findTodayByName(int $projectId, string $name): ?array
    {
        $db = Database::get();
        $name = '%' . trim($name) . '%';
        $stmt = $db->prepare("
            SELECT * FROM meal_log
            WHERE project_id = ? AND DATE(created_at, 'localtime') = DATE('now', 'localtime')
              AND description LIKE ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$projectId, $name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function update(int $id, string $description, int $kcal, float $protein, float $carbs, float $fat, array $items = [], string $source = 'local'): void
    {
        $db = Database::get();
        $stmt = $db->prepare("
            UPDATE meal_log
            SET description = ?, total_kcal = ?, total_protein = ?, total_carbs = ?, total_fat = ?,
                items_json = ?, source = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $description, $kcal, $protein, $carbs, $fat,
            !empty($items) ? json_encode($items, JSON_UNESCAPED_UNICODE) : null,
            $source, $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $db = Database::get();
        $stmt = $db->prepare("DELETE FROM meal_log WHERE id = ?");
        $stmt->execute([$id]);
    }

    public static function getMealTypes(): array
    {
        return ['sniadanie', 'lunch', 'obiad', 'kolacja', 'przekaska', 'other'];
    }
}
