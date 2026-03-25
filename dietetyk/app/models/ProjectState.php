<?php

class ProjectState
{
    public static function get(int $projectId): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM project_state WHERE project_id = ?");
        $stmt->execute([$projectId]);
        return $stmt->fetch() ?: null;
    }

    public static function upsert(int $projectId, array $data): void
    {
        $db = Database::get();
        $existing = self::get($projectId);

        if ($existing) {
            $fields = [];
            $values = [];
            foreach ($data as $key => $value) {
                if ($key === 'project_id') continue;
                $fields[] = "$key = ?";
                $values[] = $value;
            }
            $fields[] = "updated_at = CURRENT_TIMESTAMP";
            $values[] = $projectId;

            $sql = "UPDATE project_state SET " . implode(', ', $fields) . " WHERE project_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($values);
        } else {
            $data['project_id'] = $projectId;
            $columns = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            $stmt = $db->prepare("INSERT INTO project_state ($columns) VALUES ($placeholders)");
            $stmt->execute(array_values($data));
        }
    }

    public static function updateField(int $projectId, string $field, mixed $value): void
    {
        $allowed = ['start_weight_kg', 'current_weight_kg', 'target_weight_kg', 'current_phase',
                     'next_competition', 'training_mode', 'diet_mode', 'injury_status',
                     'height_cm', 'age', 'sex', 'pal'];

        if (!in_array($field, $allowed)) return;

        self::upsert($projectId, [$field => $value]);
    }

    /**
     * Formatuje state do włączenia w prompt
     */
    public static function toPromptBlock(int $projectId): string
    {
        $state = self::get($projectId);
        if (!$state) return '';

        $lines = ["[PROJECT STATE]"];

        if (!empty($state['sex']))              $lines[] = "Płeć: " . ($state['sex'] === 'F' ? 'kobieta' : 'mężczyzna');
        if (!empty($state['age']))              $lines[] = "Wiek: {$state['age']} lat";
        if (!empty($state['height_cm']))        $lines[] = "Wzrost: {$state['height_cm']} cm";
        if (!empty($state['current_weight_kg']))$lines[] = "Aktualna waga: {$state['current_weight_kg']} kg";
        if (!empty($state['target_weight_kg'])) $lines[] = "Cel wagowy: {$state['target_weight_kg']} kg";
        if (!empty($state['start_weight_kg']))  $lines[] = "Waga startowa: {$state['start_weight_kg']} kg";
        if (!empty($state['current_phase']))    $lines[] = "Faza: {$state['current_phase']}";
        if (!empty($state['next_competition'])) $lines[] = "Następne zawody: {$state['next_competition']}";
        if (!empty($state['training_mode']))    $lines[] = "Trening: {$state['training_mode']}";
        if (!empty($state['diet_mode']))        $lines[] = "Dieta: {$state['diet_mode']}";
        if (!empty($state['injury_status']))    $lines[] = "Urazy: {$state['injury_status']}";

        return implode("\n", $lines);
    }
}
