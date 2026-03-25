<?php

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(['error' => 'Nieprawidłowe dane JSON'], 400);
    }
    return $data;
}

function sanitize(string $text): string
{
    return htmlspecialchars(trim($text), ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['error' => 'Wymagana metoda POST'], 405);
    }
}

function require_get(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_response(['error' => 'Wymagana metoda GET'], 405);
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool
{
    return hash_equals(csrf_token(), $token);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function check_csrf(): void
{
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verify_csrf($token)) {
        json_response(['error' => 'Nieprawidłowy token CSRF'], 403);
    }
}

/**
 * Sprawdza ownership projektu - zwraca 403 jeśli nie należy do zalogowanego usera
 */
function require_project_owner(int $projectId): void
{
    $userId = Auth::currentUserId();
    if (!$userId || !Project::belongsToUser($projectId, $userId)) {
        json_response(['error' => 'Brak dostępu do tego projektu'], 403);
    }
}

function format_date(string $datetime): string
{
    $dt = new DateTime($datetime);
    return $dt->format('d.m.Y H:i');
}

function time_ago(string $datetime): string
{
    $now = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);

    if ($diff->d > 0) return $diff->d . ' dn. temu';
    if ($diff->h > 0) return $diff->h . ' godz. temu';
    if ($diff->i > 0) return $diff->i . ' min temu';
    return 'przed chwilą';
}
