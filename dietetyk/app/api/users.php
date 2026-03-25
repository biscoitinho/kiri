<?php

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();

// Tylko admin (user_id=1) może zarządzać użytkownikami
if (Auth::currentUserId() !== 1) {
    json_response(['error' => 'Brak uprawnień'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $db = Database::get();
    $users = $db->query("SELECT id, username, totp_enabled, created_at, last_login_at FROM users ORDER BY created_at")->fetchAll();
    json_response(['users' => $users]);
}

if ($method === 'POST') {
    check_csrf();
    $data = json_input();
    $action = $data['action'] ?? 'create';

    if ($action === 'create') {
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';

        if (!$username || !$password) {
            json_response(['error' => 'Podaj login i hasło'], 400);
        }
        if (strlen($password) < 8) {
            json_response(['error' => 'Hasło musi mieć minimum 8 znaków'], 400);
        }

        $db = Database::get();
        $existing = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $existing->execute([$username]);
        if ((int) $existing->fetchColumn() > 0) {
            json_response(['error' => 'Ten login jest już zajęty'], 400);
        }

        $hash = Auth::hashPassword($password);
        $stmt = $db->prepare("INSERT INTO users (username, password_hash, totp_enabled) VALUES (?, ?, 0)");
        $stmt->execute([$username, $hash]);

        json_response(['id' => (int) $db->lastInsertId(), 'message' => 'Konto utworzone']);
    }

    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if (!$id) json_response(['error' => 'Brak id'], 400);

        // Nie pozwól usunąć samego siebie
        if ($id === Auth::currentUserId()) {
            json_response(['error' => 'Nie możesz usunąć swojego konta'], 400);
        }

        $db = Database::get();
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        json_response(['message' => 'Konto usunięte']);
    }
}

json_response(['error' => 'Nieobsługiwana metoda'], 405);
