<?php

require_once __DIR__ . '/../core/Bootstrap.php';
Auth::requireLoginApi();

// Tylko admin (user_id=1) może zarządzać backupami
if (Auth::currentUserId() !== 1) {
    json_response(['error' => 'Brak uprawnień'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $backups = BackupService::list();
        json_response(['backups' => $backups]);
    }

    if ($action === 'download') {
        $filename = basename($_GET['file'] ?? '');
        if (!$filename || !str_ends_with($filename, '.sqlite')) {
            json_response(['error' => 'Nieprawidłowy plik'], 400);
        }
        $path = STORAGE_PATH . '/backups/' . $filename;
        if (!file_exists($path)) {
            json_response(['error' => 'Plik nie istnieje'], 404);
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    if ($action === 'download-json') {
        $filename = basename($_GET['file'] ?? '');
        if (!$filename || !str_ends_with($filename, '.json')) {
            json_response(['error' => 'Nieprawidłowy plik'], 400);
        }
        $path = STORAGE_PATH . '/backups/' . $filename;
        if (!file_exists($path)) {
            json_response(['error' => 'Plik nie istnieje'], 404);
        }
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    json_response(['error' => 'Nieznana akcja'], 400);
}

if ($method === 'POST') {
    check_csrf();
    $data = json_input();
    $action = $data['action'] ?? '';

    if ($action === 'create') {
        $label = preg_replace('/[^a-z0-9\-]/', '', strtolower($data['label'] ?? 'manual'));
        $path = BackupService::createBackup($label ?: 'manual');
        if ($path) {
            json_response([
                'message' => 'Backup utworzony',
                'filename' => basename($path),
                'backups' => BackupService::list(),
            ]);
        } else {
            json_response(['error' => 'Błąd tworzenia backupu'], 500);
        }
    }

    if ($action === 'restore') {
        $filename = basename($data['filename'] ?? '');
        if (!$filename) json_response(['error' => 'Brak nazwy pliku'], 400);

        $result = BackupService::restore($filename);
        if ($result) {
            json_response(['message' => 'Baza przywrócona z backupu. Odśwież stronę.']);
        } else {
            json_response(['error' => 'Błąd przywracania'], 500);
        }
    }

    json_response(['error' => 'Nieznana akcja'], 400);
}

json_response(['error' => 'Nieobsługiwana metoda'], 405);
