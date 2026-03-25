<?php

/**
 * Router dla wbudowanego serwera PHP (php -S)
 * Obsługuje statyczne pliki i kieruje resztę do odpowiednich stron
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Statyczne pliki - serwuj bezpośrednio
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// API routing
if (str_starts_with($uri, '/api/')) {
    $endpoint = substr($uri, 5); // usuń /api/
    $endpoint = rtrim($endpoint, '/');
    $apiFile = __DIR__ . '/../app/api/' . $endpoint . '.php';

    if (file_exists($apiFile)) {
        require $apiFile;
        exit;
    }

    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Endpoint nie znaleziony']);
    exit;
}

// Strony
$routes = [
    '/' => 'index.php',
    '/login' => 'login.php',
    '/setup' => 'setup.php',
    '/logout' => 'logout.php',
    '/project' => 'project.php',
    '/settings' => 'settings.php',
];

$page = $routes[$uri] ?? null;

if ($page && file_exists(__DIR__ . '/' . $page)) {
    require __DIR__ . '/' . $page;
    exit;
}

// 404
http_response_code(404);
echo '404 - Nie znaleziono';
