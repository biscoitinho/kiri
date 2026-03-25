<?php

class BackupService
{
    private static string $backupDir = __DIR__ . '/../../storage/backups';

    /**
     * Tworzy backup bazy SQLite (Online Backup API - bezpieczne przy WAL)
     * Zwraca ścieżkę do pliku backup lub null przy błędzie
     */
    public static function createBackup(string $label = 'auto'): ?string
    {
        $dir = self::ensureDir();
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "backup_{$label}_{$timestamp}.sqlite";
        $path = $dir . '/' . $filename;

        try {
            $source = Database::get();
            $dest = new PDO('sqlite:' . $path);

            // SQLite Online Backup - kopiuje spójny snapshot nawet przy aktywnym WAL
            $source->sqliteCreateFunction('backup_done', function() { return 1; });

            // Fallback: użyj VACUUM INTO (SQLite 3.27+) lub kopiuj plik
            if (method_exists($source, 'exec')) {
                try {
                    $source->exec("VACUUM INTO '{$path}'");
                } catch (\Exception $e) {
                    // Fallback: checkpoint WAL i kopiuj plik
                    $source->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                    $dest = null;
                    copy(DB_PATH, $path);
                }
            }

            // Eksportuj warstwy AI jako JSON (łatwy do odczytu/importu)
            self::exportLayersJson($dir, $timestamp, $label);

            // Rotacja - max 30 backupów
            self::rotate(30);

            return $path;
        } catch (\Exception $e) {
            error_log("Backup failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Eksportuje 3 warstwy AI do czytelnego JSON
     */
    private static function exportLayersJson(string $dir, string $timestamp, string $label): void
    {
        $db = Database::get();
        $export = [];

        // Warstwa 1: Raw messages (ostatnie 100)
        $export['raw_messages'] = $db->query("
            SELECT m.*, p.name as project_name
            FROM messages m
            JOIN projects p ON p.id = m.project_id
            ORDER BY m.id DESC LIMIT 100
        ")->fetchAll();

        // Warstwa 2: Summary blocks
        $export['summary_blocks'] = $db->query("
            SELECT sb.*, p.name as project_name
            FROM summary_blocks sb
            JOIN projects p ON p.id = sb.project_id
            ORDER BY sb.id DESC
        ")->fetchAll();

        // Warstwa 3a: Durable memory
        $export['project_memory'] = $db->query("
            SELECT pm.*, p.name as project_name
            FROM project_memory pm
            JOIN projects p ON p.id = pm.project_id
            ORDER BY pm.priority DESC
        ")->fetchAll();

        // Warstwa 3b: Project state
        $export['project_state'] = $db->query("
            SELECT ps.*, p.name as project_name
            FROM project_state ps
            JOIN projects p ON p.id = ps.project_id
        ")->fetchAll();

        // Bonus: projekty, posiłki, food cache stats
        $export['projects'] = $db->query("SELECT * FROM projects")->fetchAll();
        $export['meal_log'] = $db->query("SELECT * FROM meal_log ORDER BY id DESC LIMIT 200")->fetchAll();

        $export['_meta'] = [
            'exported_at' => date('Y-m-d H:i:s'),
            'label' => $label,
            'counts' => [
                'messages' => $db->query("SELECT COUNT(*) FROM messages")->fetchColumn(),
                'summary_blocks' => $db->query("SELECT COUNT(*) FROM summary_blocks")->fetchColumn(),
                'project_memory' => $db->query("SELECT COUNT(*) FROM project_memory")->fetchColumn(),
                'meal_log' => $db->query("SELECT COUNT(*) FROM meal_log")->fetchColumn(),
                'food_cache' => $db->query("SELECT COUNT(*) FROM food_cache")->fetchColumn(),
                'insights' => $db->query("SELECT COUNT(*) FROM insights")->fetchColumn(),
            ],
        ];

        $jsonPath = $dir . "/layers_{$label}_{$timestamp}.json";
        file_put_contents($jsonPath, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Auto-backup: max 1 na godzinę, wywoływany przy starcie
     * Używa flock() aby uniknąć race condition przy równoległych requestach
     */
    public static function autoBackupIfNeeded(): void
    {
        $dir = self::ensureDir();
        $lockFile = $dir . '/.last_auto_backup';

        // Sprawdź timestamp BEZ locka - szybki early return dla 99% requestów
        if (file_exists($lockFile)) {
            $lastBackup = (int) file_get_contents($lockFile);
            if (time() - $lastBackup < 3600) return; // max 1/godz
        }

        // Użyj flock żeby tylko 1 request tworzył backup
        $fp = fopen($lockFile . '.lock', 'w');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            // Inny request już tworzy backup - skip
            if ($fp) fclose($fp);
            return;
        }

        try {
            // Podwójne sprawdzenie po uzyskaniu locka (inny request mógł już zrobić backup)
            if (file_exists($lockFile)) {
                $lastBackup = (int) file_get_contents($lockFile);
                if (time() - $lastBackup < 3600) return;
            }

            // Zapisz timestamp PRZED backupem - natychmiastowa blokada
            file_put_contents($lockFile, (string) time());
            self::createBackup('auto');
        } catch (\Exception $e) {
            // Backup się nie udał - usuń timestamp żeby spróbować ponownie
            @unlink($lockFile);
            error_log("Auto-backup failed: " . $e->getMessage());
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Backup przed migracją
     */
    public static function preMingrateBackup(): void
    {
        // Tylko jeśli baza istnieje i ma dane
        if (!file_exists(DB_PATH) || filesize(DB_PATH) < 1024) return;
        self::createBackup('pre-migrate');
    }

    /**
     * Lista dostępnych backupów
     */
    public static function list(): array
    {
        $dir = self::ensureDir();
        $files = glob($dir . '/backup_*.sqlite');
        if (!$files) return [];

        $backups = [];
        foreach ($files as $file) {
            $basename = basename($file);
            // Parsuj: backup_LABEL_YYYY-MM-DD_HH-II-SS.sqlite
            preg_match('/backup_(.+?)_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.sqlite/', $basename, $m);

            $jsonFile = str_replace('backup_', 'layers_', str_replace('.sqlite', '.json', $file));
            $meta = null;
            if (file_exists($jsonFile)) {
                $json = json_decode(file_get_contents($jsonFile), true);
                $meta = $json['_meta'] ?? null;
            }

            $backups[] = [
                'filename' => $basename,
                'label' => $m[1] ?? 'unknown',
                'date' => isset($m[2]) ? str_replace('_', ' ', $m[2]) : '',
                'size' => filesize($file),
                'size_human' => self::humanSize(filesize($file)),
                'meta' => $meta,
                'has_json' => file_exists($jsonFile),
            ];
        }

        // Najnowsze pierwsze
        usort($backups, fn($a, $b) => $b['date'] <=> $a['date']);
        return $backups;
    }

    /**
     * Przywróć backup (zastępuje bieżącą bazę)
     */
    public static function restore(string $filename): bool
    {
        $dir = self::ensureDir();
        $backupPath = $dir . '/' . basename($filename);

        if (!file_exists($backupPath)) return false;
        if (!str_ends_with($backupPath, '.sqlite')) return false;

        // Najpierw zrób backup bieżącego stanu
        self::createBackup('pre-restore');

        // Zamknij połączenie z bazą
        Database::close();

        // Checkpoint WAL
        try {
            $db = new PDO('sqlite:' . DB_PATH);
            $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $db = null;
        } catch (\Exception $e) {}

        // Nadpisz bazę
        return copy($backupPath, DB_PATH);
    }

    /**
     * Rotacja - usuwa najstarsze backupy ponad limit
     */
    private static function rotate(int $maxBackups): void
    {
        $dir = self::ensureDir();
        $sqliteFiles = glob($dir . '/backup_*.sqlite');
        $jsonFiles = glob($dir . '/layers_*.json');

        if (count($sqliteFiles) <= $maxBackups) return;

        // Sortuj po dacie modyfikacji (najstarsze pierwsze)
        usort($sqliteFiles, fn($a, $b) => filemtime($a) - filemtime($b));

        $toRemove = count($sqliteFiles) - $maxBackups;
        for ($i = 0; $i < $toRemove; $i++) {
            $jsonPair = str_replace('backup_', 'layers_', str_replace('.sqlite', '.json', $sqliteFiles[$i]));
            @unlink($sqliteFiles[$i]);
            @unlink($jsonPair);
        }
    }

    private static function ensureDir(): string
    {
        $dir = realpath(__DIR__ . '/../../storage') . '/backups';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return $dir;
    }

    private static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
