<?php
require_once __DIR__ . '/../app/core/Bootstrap.php';
Auth::requireLogin();

$db = Database::get();
$userId = Auth::currentUserId();
$user = $db->prepare("SELECT * FROM users WHERE id = ?")->execute([$userId]) ? $db->prepare("SELECT * FROM users WHERE id = ?")->execute([$userId]) : null;
// Re-fetch properly
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$success = '';
$error = '';
$step = 'main'; // main | totp_setup
$totpUri = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $error = 'Nieprawidłowy token CSRF';
    } else {
        $action = $_POST['action'] ?? '';

        // Zmiana hasła
        if ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $newPass = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if (!Auth::verifyPassword($current, $user['password_hash'])) {
                $error = 'Nieprawidłowe obecne hasło';
            } elseif (strlen($newPass) < 8) {
                $error = 'Nowe hasło musi mieć min. 8 znaków';
            } elseif ($newPass !== $confirm) {
                $error = 'Hasła nie pasują do siebie';
            } else {
                $hash = Auth::hashPassword($newPass);
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hash, $userId]);
                $success = 'Hasło zmienione!';
            }
        }

        // Rozpocznij konfigurację 2FA
        if ($action === 'setup_2fa') {
            $secret = Auth::generateTotpSecret();
            $_SESSION['setup_totp_secret'] = $secret;
            $totpUri = Auth::getTotpUri($secret, $user['username']);
            $step = 'totp_setup';
        }

        // Weryfikuj i aktywuj 2FA
        if ($action === 'verify_2fa') {
            $code = trim($_POST['totp_code'] ?? '');
            $secret = $_SESSION['setup_totp_secret'] ?? '';

            if ($secret && $code && Auth::verifyTotp($secret, $code)) {
                $stmt = $db->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?");
                $stmt->execute([$secret, $userId]);
                unset($_SESSION['setup_totp_secret']);
                $success = '2FA aktywowane!';
                // Refresh user data
                $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
            } else {
                $error = 'Nieprawidłowy kod - spróbuj ponownie';
                $totpUri = Auth::getTotpUri($secret, $user['username']);
                $step = 'totp_setup';
            }
        }

        // Wyłącz 2FA
        if ($action === 'disable_2fa') {
            $code = trim($_POST['totp_code'] ?? '');
            if ($user['totp_secret'] && Auth::verifyTotp($user['totp_secret'], $code)) {
                $stmt = $db->prepare("UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?");
                $stmt->execute([$userId]);
                $success = '2FA wyłączone';
                $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
            } else {
                $error = 'Nieprawidłowy kod 2FA';
            }
        }
    }
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ustawienia - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="/assets/css/tabler.min.css">
    <link rel="stylesheet" href="/assets/css/tabler-icons.min.css">
</head>
<body class="page">
    <div class="page-wrapper">
        <div class="page-body">
            <div class="container-xl py-4">
                <div class="d-flex align-items-center mb-4">
                    <a href="/" class="btn btn-ghost-primary btn-sm me-3">
                        <i class="ti ti-arrow-left me-1"></i>Powrót
                    </a>
                    <h1 class="page-title mb-0"><i class="ti ti-settings me-2"></i>Ustawienia</h1>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible">
                        <i class="ti ti-check me-2"></i><?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible">
                        <i class="ti ti-alert-circle me-2"></i><?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <!-- Zmiana hasła -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="ti ti-key me-2"></i>Zmiana hasła</h3>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="mb-3">
                                        <label class="form-label">Obecne hasło</label>
                                        <input type="password" name="current_password" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Nowe hasło</label>
                                        <input type="password" name="new_password" class="form-control" required minlength="8">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Powtórz nowe hasło</label>
                                        <input type="password" name="confirm_password" class="form-control" required>
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="ti ti-check me-1"></i>Zmień hasło
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- 2FA -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="ti ti-shield-lock me-2"></i>Uwierzytelnianie 2FA</h3>
                                <?php if ($user['totp_enabled']): ?>
                                    <span class="badge bg-green-lt ms-auto">Aktywne</span>
                                <?php else: ?>
                                    <span class="badge bg-red-lt ms-auto">Nieaktywne</span>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <?php if ($step === 'totp_setup'): ?>
                                    <!-- Krok: skanowanie QR -->
                                    <div class="text-center mb-3">
                                        <p class="text-secondary">Zeskanuj kod w aplikacji (Google Authenticator, Authy itp.):</p>
                                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($totpUri) ?>"
                                             alt="QR Code" class="rounded border mb-2" style="width:200px;">
                                        <div class="mt-2">
                                            <small class="text-secondary">Lub wpisz ręcznie:</small><br>
                                            <code class="fs-5"><?= $_SESSION['setup_totp_secret'] ?? '' ?></code>
                                        </div>
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="verify_2fa">
                                        <div class="mb-3">
                                            <label class="form-label">Kod z aplikacji</label>
                                            <input type="text" name="totp_code" class="form-control text-center fs-3"
                                                   maxlength="6" pattern="[0-9]{6}" placeholder="000000" required autofocus
                                                   autocomplete="one-time-code" inputmode="numeric">
                                        </div>
                                        <button type="submit" class="btn btn-green w-100">
                                            <i class="ti ti-shield-check me-1"></i>Aktywuj 2FA
                                        </button>
                                    </form>

                                <?php elseif ($user['totp_enabled']): ?>
                                    <!-- 2FA aktywne - opcja wyłączenia -->
                                    <div class="alert alert-success mb-3">
                                        <i class="ti ti-shield-check me-2"></i>
                                        2FA jest aktywne. Twoje konto jest chronione dodatkowym kodem przy logowaniu.
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="disable_2fa">
                                        <div class="mb-3">
                                            <label class="form-label">Podaj kod 2FA aby wyłączyć</label>
                                            <input type="text" name="totp_code" class="form-control text-center"
                                                   maxlength="6" pattern="[0-9]{6}" placeholder="000000" required
                                                   autocomplete="one-time-code" inputmode="numeric">
                                        </div>
                                        <button type="submit" class="btn btn-outline-danger w-100">
                                            <i class="ti ti-shield-off me-1"></i>Wyłącz 2FA
                                        </button>
                                    </form>

                                <?php else: ?>
                                    <!-- 2FA nieaktywne - opcja włączenia -->
                                    <p class="text-secondary mb-3">
                                        Dodaj dodatkową warstwę zabezpieczeń. Przy logowaniu będziesz potrzebował kodu z aplikacji authenticator.
                                    </p>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="setup_2fa">
                                        <button type="submit" class="btn btn-green w-100">
                                            <i class="ti ti-shield-lock me-1"></i>Skonfiguruj 2FA
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Zarządzanie użytkownikami (tylko admin) -->
                <?php if ($userId === 1): ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="ti ti-users me-2"></i>Użytkownicy</h3>
                    </div>
                    <div class="card-body">
                        <div id="users-list" class="mb-3">
                            <div class="text-secondary">Ładowanie...</div>
                        </div>
                        <hr>
                        <h4 class="mb-3">Dodaj nowego użytkownika</h4>
                        <form id="add-user-form" class="row g-2 align-items-end">
                            <div class="col-auto">
                                <label class="form-label">Login</label>
                                <input type="text" id="new-username" class="form-control" required>
                            </div>
                            <div class="col-auto">
                                <label class="form-label">Hasło (min. 8 znaków)</label>
                                <input type="password" id="new-password" class="form-control" minlength="8" required>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary">
                                    <i class="ti ti-user-plus me-1"></i>Utwórz konto
                                </button>
                            </div>
                        </form>
                        <div id="user-feedback" class="mt-2"></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Info -->
                <div class="card mt-4">
                    <div class="card-body">
                        <div class="d-flex align-items-center text-secondary" style="font-size:0.8rem;">
                            <i class="ti ti-user me-2"></i>
                            <span>Zalogowany jako: <strong><?= htmlspecialchars($user['username']) ?></strong></span>
                            <span class="mx-2">·</span>
                            <span>ID: <?= $user['id'] ?></span>
                        </div>
                    </div>
                </div>

                <?php if ($userId === 1): ?>
                <script>
                const csrfToken = '<?= $_SESSION['csrf_token'] ?>';
                const currentUserId = <?= $userId ?>;

                async function loadUsers() {
                    const res = await fetch('/api/users');
                    const data = await res.json();
                    const list = document.getElementById('users-list');

                    if (!data.users || data.users.length === 0) {
                        list.innerHTML = '<span class="text-secondary">Brak użytkowników</span>';
                        return;
                    }

                    list.innerHTML = '<table class="table table-vcenter">' +
                        '<thead><tr><th>ID</th><th>Login</th><th>2FA</th><th>Utworzono</th><th>Ostatnie logowanie</th><th></th></tr></thead>' +
                        '<tbody>' + data.users.map(u => `
                            <tr>
                                <td>${u.id}</td>
                                <td><strong>${u.username}</strong>${u.id === currentUserId ? ' <span class="badge bg-blue-lt">Ty</span>' : ''}</td>
                                <td>${u.totp_enabled ? '<span class="badge bg-green-lt">Tak</span>' : '<span class="badge bg-secondary-lt">Nie</span>'}</td>
                                <td>${u.created_at}</td>
                                <td>${u.last_login_at || '<span class="text-secondary">-</span>'}</td>
                                <td>${u.id !== currentUserId ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteUser(${u.id}, '${u.username}')"><i class="ti ti-trash"></i></button>` : ''}</td>
                            </tr>
                        `).join('') + '</tbody></table>';
                }

                async function deleteUser(id, username) {
                    if (!confirm(`Usunąć konto "${username}"? Wszystkie projekty i dane tego użytkownika zostaną usunięte!`)) return;

                    const res = await fetch('/api/users', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                        body: JSON.stringify({action: 'delete', id: id, _csrf: csrfToken})
                    });
                    const data = await res.json();
                    showFeedback(data.error || data.message, !data.error);
                    loadUsers();
                }

                document.getElementById('add-user-form').addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const username = document.getElementById('new-username').value.trim();
                    const password = document.getElementById('new-password').value;

                    const res = await fetch('/api/users', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                        body: JSON.stringify({action: 'create', username, password, _csrf: csrfToken})
                    });
                    const data = await res.json();
                    showFeedback(data.error || data.message, !data.error);
                    if (!data.error) {
                        document.getElementById('new-username').value = '';
                        document.getElementById('new-password').value = '';
                        loadUsers();
                    }
                });

                function showFeedback(msg, success) {
                    const el = document.getElementById('user-feedback');
                    el.innerHTML = `<div class="alert alert-${success ? 'success' : 'danger'} alert-dismissible">${msg}</div>`;
                    setTimeout(() => el.innerHTML = '', 5000);
                }

                loadUsers();
                </script>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
