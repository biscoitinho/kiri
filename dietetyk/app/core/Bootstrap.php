<?php

// Ścieżki
define('ROOT_PATH', realpath(__DIR__ . '/../../'));
define('APP_PATH', ROOT_PATH . '/app');
define('STORAGE_PATH', ROOT_PATH . '/storage');

// Config
require_once APP_PATH . '/config.php';

// Core
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Auth.php';
require_once APP_PATH . '/core/Helpers.php';

// Modele
require_once APP_PATH . '/models/Project.php';
require_once APP_PATH . '/models/Message.php';
require_once APP_PATH . '/models/Insight.php';
require_once APP_PATH . '/models/Checkpoint.php';
require_once APP_PATH . '/models/SummaryBlock.php';
require_once APP_PATH . '/models/ProjectMemory.php';
require_once APP_PATH . '/models/ProjectState.php';
require_once APP_PATH . '/models/DailyLog.php';
require_once APP_PATH . '/models/MealLog.php';
require_once APP_PATH . '/models/FoodCache.php';
require_once APP_PATH . '/models/ApiUsage.php';

// Serwisy
require_once APP_PATH . '/services/OpenAIService.php';
require_once APP_PATH . '/services/ContextBuilder.php';
require_once APP_PATH . '/services/InsightExtractor.php';
require_once APP_PATH . '/services/SummaryService.php';
require_once APP_PATH . '/services/FoodDatabase.php';
require_once APP_PATH . '/services/BackupService.php';

// Sesja
if (session_status() === PHP_SESSION_NONE) {
    // GC musi żyć co najmniej tyle co cookie, inaczej serwer kasuje sesję wcześniej
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// Migracja DB (CREATE IF NOT EXISTS - bezpieczne przy każdym uruchomieniu)
Database::migrate();

// Auto-backup: wywoływany tylko z dashboard (index.php), nie z każdego API calla
// Użyj BackupService::autoBackupIfNeeded() w odpowiednim miejscu
