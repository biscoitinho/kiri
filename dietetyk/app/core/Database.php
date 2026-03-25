<?php

class Database
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            self::$instance = new PDO('sqlite:' . DB_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');
        }
        return self::$instance;
    }

    public static function close(): void
    {
        if (self::$instance !== null) {
            self::$instance->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            self::$instance = null;
        }
    }

    public static function migrate(): void
    {
        $db = self::get();

        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                totp_secret TEXT DEFAULT NULL,
                totp_enabled INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL DEFAULT 1,
                name TEXT NOT NULL,
                description TEXT,
                status TEXT DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        // Migracja: dodaj user_id do istniejącej tabeli projects (jeśli brakuje)
        $cols = $db->query("PRAGMA table_info(projects)")->fetchAll();
        $colNames = array_column($cols, 'name');
        if (!in_array('user_id', $colNames)) {
            $db->exec("ALTER TABLE projects ADD COLUMN user_id INTEGER NOT NULL DEFAULT 1");
        }

        // Migracja: dodaj height_cm, age, sex, pal do project_state
        $stateCols = $db->query("PRAGMA table_info(project_state)")->fetchAll();
        $stateColNames = array_column($stateCols, 'name');
        if (!in_array('height_cm', $stateColNames)) {
            $db->exec("ALTER TABLE project_state ADD COLUMN height_cm REAL");
            $db->exec("ALTER TABLE project_state ADD COLUMN age INTEGER");
            $db->exec("ALTER TABLE project_state ADD COLUMN sex TEXT DEFAULT 'M'");
            $db->exec("ALTER TABLE project_state ADD COLUMN pal REAL DEFAULT 1.2");
        }
        // Migracja: uzupełnij brakujące dane profilu dla projektu 1
        $check = $db->query("SELECT height_cm FROM project_state WHERE project_id = 1")->fetchColumn();
        if (!$check) {
            $db->exec("UPDATE project_state SET height_cm = 187, age = 30, sex = 'M', pal = 1.2 WHERE project_id = 1");
        }

        // Migracja: dodaj last_login_at do users
        $userCols = $db->query("PRAGMA table_info(users)")->fetchAll();
        $userColNames = array_column($userCols, 'name');
        if (!in_array('last_login_at', $userColNames)) {
            $db->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME");
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                role TEXT NOT NULL CHECK(role IN ('user','assistant','system')),
                content TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS insights (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                content TEXT NOT NULL,
                priority INTEGER DEFAULT 5,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS checkpoints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                weight REAL,
                sleep_hours REAL,
                hunger_level INTEGER,
                energy_level INTEGER,
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            )
        ");

        // === Warstwa 2: Summary blocks ===
        $db->exec("
            CREATE TABLE IF NOT EXISTS summary_blocks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                message_from_id INTEGER NOT NULL,
                message_to_id INTEGER NOT NULL,
                summary TEXT NOT NULL,
                facts_json TEXT,
                state_updates_json TEXT,
                temporary_notes_json TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            )
        ");

        // === Warstwa 3: Durable project memory ===
        $db->exec("
            CREATE TABLE IF NOT EXISTS project_memory (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                category TEXT NOT NULL,
                content TEXT NOT NULL,
                priority INTEGER DEFAULT 5,
                source_summary_block_id INTEGER,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            )
        ");

        // === Warstwa 4: Project state (snapshot) ===
        $db->exec("
            CREATE TABLE IF NOT EXISTS project_state (
                project_id INTEGER PRIMARY KEY,
                start_weight_kg REAL,
                current_weight_kg REAL,
                target_weight_kg REAL,
                current_phase TEXT,
                next_competition TEXT,
                training_mode TEXT,
                diet_mode TEXT,
                injury_status TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            )
        ");

        // === Daily logs ===
        $db->exec("
            CREATE TABLE IF NOT EXISTS daily_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                date DATE NOT NULL,
                meals_json TEXT,
                hunger_notes TEXT,
                sleep_hours REAL,
                training_notes TEXT,
                pain_notes TEXT,
                weight REAL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                UNIQUE(project_id, date)
            )
        ");

        // === Meal log ===
        $db->exec("
            CREATE TABLE IF NOT EXISTS meal_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                meal_type TEXT DEFAULT 'other',
                description TEXT NOT NULL,
                items_json TEXT,
                total_kcal INTEGER DEFAULT 0,
                total_protein REAL DEFAULT 0,
                total_carbs REAL DEFAULT 0,
                total_fat REAL DEFAULT 0,
                source TEXT DEFAULT 'manual',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            )
        ");

        // === Lokalna baza produktów (cache + seed) ===
        $db->exec("
            CREATE TABLE IF NOT EXISTS food_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                name_lower TEXT NOT NULL,
                brand TEXT DEFAULT '',
                kcal_100g INTEGER NOT NULL,
                protein_100g REAL DEFAULT 0,
                carbs_100g REAL DEFAULT 0,
                fat_100g REAL DEFAULT 0,
                fiber_100g REAL DEFAULT 0,
                typical_portion_g INTEGER DEFAULT 100,
                source TEXT DEFAULT 'seed',
                search_count INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_food_cache_name ON food_cache(name_lower)");

        // === API usage tracking ===
        $db->exec("
            CREATE TABLE IF NOT EXISTS api_usage (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                model TEXT NOT NULL,
                endpoint TEXT NOT NULL,
                input_tokens INTEGER DEFAULT 0,
                output_tokens INTEGER DEFAULT 0,
                total_tokens INTEGER DEFAULT 0,
                cost_usd REAL DEFAULT 0,
                context TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Indeksy
        $db->exec("CREATE INDEX IF NOT EXISTS idx_api_usage_date ON api_usage(created_at DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_meal_log_project ON meal_log(project_id, created_at DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_messages_project ON messages(project_id, created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_insights_project ON insights(project_id, priority DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_checkpoints_project ON checkpoints(project_id, created_at DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_summary_blocks_project ON summary_blocks(project_id, created_at DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_project_memory_project ON project_memory(project_id, priority DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_daily_logs_project ON daily_logs(project_id, date DESC)");

        // Migracja: dodaj water_ml i activity_kcal do daily_logs
        $dlCols = $db->query("PRAGMA table_info(daily_logs)")->fetchAll();
        $dlColNames = array_column($dlCols, 'name');
        if (!in_array('water_ml', $dlColNames)) {
            $db->exec("ALTER TABLE daily_logs ADD COLUMN water_ml INTEGER DEFAULT 0");
        }
        if (!in_array('activity_kcal', $dlColNames)) {
            $db->exec("ALTER TABLE daily_logs ADD COLUMN activity_kcal INTEGER DEFAULT 0");
        }
        if (!in_array('hunger_level', $dlColNames)) {
            $db->exec("ALTER TABLE daily_logs ADD COLUMN hunger_level INTEGER");
        }
        if (!in_array('energy_level', $dlColNames)) {
            $db->exec("ALTER TABLE daily_logs ADD COLUMN energy_level INTEGER");
        }
        if (!in_array('notes', $dlColNames)) {
            $db->exec("ALTER TABLE daily_logs ADD COLUMN notes TEXT DEFAULT ''");
        }
    }
}
