<?php

class ApiUsage
{
    // Ceny za 1M tokenów (USD) - Responses API
    private static array $pricing = [
        'gpt-5'      => ['input' => 2.00, 'output' => 8.00],
        'gpt-5-mini' => ['input' => 0.30, 'output' => 1.20],
        'gpt-4.1'    => ['input' => 2.00, 'output' => 8.00],
        'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
    ];

    public static function log(string $model, string $endpoint, int $inputTokens, int $outputTokens, string $context = ''): void
    {
        $total = $inputTokens + $outputTokens;
        $cost = self::calculateCost($model, $inputTokens, $outputTokens);

        $db = Database::get();
        $stmt = $db->prepare("
            INSERT INTO api_usage (model, endpoint, input_tokens, output_tokens, total_tokens, cost_usd, context)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$model, $endpoint, $inputTokens, $outputTokens, $total, $cost, $context]);
    }

    public static function calculateCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $prices = self::$pricing[$model] ?? self::$pricing['gpt-5-mini'];
        return ($inputTokens * $prices['input'] + $outputTokens * $prices['output']) / 1_000_000;
    }

    public static function getTodaySummary(): array
    {
        $db = Database::get();
        $row = $db->query("
            SELECT
                COUNT(*) as calls,
                COALESCE(SUM(input_tokens), 0) as input_tokens,
                COALESCE(SUM(output_tokens), 0) as output_tokens,
                COALESCE(SUM(total_tokens), 0) as total_tokens,
                COALESCE(SUM(cost_usd), 0) as cost_usd
            FROM api_usage
            WHERE DATE(created_at, 'localtime') = DATE('now', 'localtime')
        ")->fetch();
        return $row;
    }

    public static function getMonthSummary(): array
    {
        $db = Database::get();
        $row = $db->query("
            SELECT
                COUNT(*) as calls,
                COALESCE(SUM(input_tokens), 0) as input_tokens,
                COALESCE(SUM(output_tokens), 0) as output_tokens,
                COALESCE(SUM(total_tokens), 0) as total_tokens,
                COALESCE(SUM(cost_usd), 0) as cost_usd
            FROM api_usage
            WHERE strftime('%Y-%m', created_at, 'localtime') = strftime('%Y-%m', 'now', 'localtime')
        ")->fetch();
        return $row;
    }

    public static function getDailySummaries(int $days = 30): array
    {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT
                DATE(created_at, 'localtime') as date,
                COUNT(*) as calls,
                SUM(input_tokens) as input_tokens,
                SUM(output_tokens) as output_tokens,
                SUM(total_tokens) as total_tokens,
                SUM(cost_usd) as cost_usd
            FROM api_usage
            WHERE created_at >= DATE('now', 'localtime', ?)
            GROUP BY DATE(created_at, 'localtime')
            ORDER BY date DESC
        ");
        $stmt->execute(["-{$days} days"]);
        return $stmt->fetchAll();
    }

    public static function getRecentCalls(int $limit = 20): array
    {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT * FROM api_usage ORDER BY created_at DESC LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
}
