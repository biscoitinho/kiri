<?php

// === Local config override (API keys, server-specific settings) ===
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// === OpenAI API ===
if (!defined('OPENAI_API_KEY')) {
    define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '');
}

// Modele
if (!defined('MODEL_CHAT')) define('MODEL_CHAT', 'gpt-5');
if (!defined('MODEL_MINI')) define('MODEL_MINI', 'gpt-5-mini');

// === Baza danych ===
define('DB_PATH', __DIR__ . '/../storage/database.sqlite');

// === Aplikacja ===
define('APP_NAME', 'DIEtetyk');
if (!defined('APP_URL')) define('APP_URL', 'http://localhost:8080');
if (!defined('APP_SECRET')) define('APP_SECRET', 'a7F3kR9mXqZ2nP5wT8vL1cJ6hY4sBd0e');

// === Sesja ===
define('SESSION_LIFETIME', 3600 * 8); // 8 godzin

// === Chat ===
define('CHAT_CONTEXT_MESSAGES', 20);   // ile ostatnich wiadomości w kontekście
define('CHAT_MAX_INSIGHTS', 10);       // ile insightów dołączać do kontekstu

// === 2FA ===
define('TOTP_ISSUER', 'DIEtetyk');
