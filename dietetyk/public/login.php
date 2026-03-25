<?php
require_once __DIR__ . '/../app/core/Bootstrap.php';

$error = '';
$step = 'login'; // login | totp

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username && $password) {
            $db = Database::get();
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && Auth::verifyPassword($password, $user['password_hash'])) {
                if ($user['totp_enabled']) {
                    $_SESSION['pending_user_id'] = $user['id'];
                    $step = 'totp';
                } else {
                    Auth::login($user['id']);
                    redirect('/');
                }
            } else {
                $error = 'Nieprawidłowy login lub hasło';
            }
        } else {
            $error = 'Wypełnij wszystkie pola';
        }
    }

    if ($action === 'totp') {
        $code = trim($_POST['totp_code'] ?? '');
        $userId = $_SESSION['pending_user_id'] ?? 0;

        if ($userId && $code) {
            $db = Database::get();
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if ($user && Auth::verifyTotp($user['totp_secret'], $code)) {
                unset($_SESSION['pending_user_id']);
                Auth::login($user['id']);
                redirect('/');
            } else {
                $error = 'Nieprawidłowy kod 2FA';
                $step = 'totp';
            }
        }
    }
}

// Sprawdź czy użytkownik w ogóle istnieje - jeśli nie, przekieruj do setup
$db = Database::get();
Database::migrate();
$userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($userCount == 0) {
    redirect('/setup');
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logowanie - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="/assets/css/tabler.min.css">
    <link rel="stylesheet" href="/assets/css/tabler-icons.min.css">
</head>
<body class="d-flex flex-column bg-white">
    <div class="page page-center">
        <div class="container container-tight py-4">
            <div class="text-center mb-4">
                <h1 class="d-flex align-items-center justify-content-center gap-2">
                    <i class="ti ti-barbell fs-1"></i>
                    <?= APP_NAME ?>
                </h1>
            </div>

            <div class="card card-md">
                <div class="card-body">
                    <?php if ($step === 'login'): ?>
                        <h2 class="h2 text-center mb-4">Zaloguj się</h2>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= sanitize($error) ?></div>
                        <?php endif; ?>

                        <form method="POST" autocomplete="off">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="login">

                            <div class="mb-3">
                                <label class="form-label">Login</label>
                                <input type="text" name="username" class="form-control" autofocus required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Hasło</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>

                            <div class="form-footer">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="ti ti-login me-2"></i>Zaloguj
                                </button>
                            </div>
                        </form>

                    <?php elseif ($step === 'totp'): ?>
                        <h2 class="h2 text-center mb-4">Weryfikacja 2FA</h2>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= sanitize($error) ?></div>
                        <?php endif; ?>

                        <form method="POST" autocomplete="off">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="totp">

                            <div class="mb-3">
                                <label class="form-label">Kod z aplikacji (6 cyfr)</label>
                                <input type="text" name="totp_code" class="form-control text-center fs-2 tracking-widest"
                                       maxlength="6" pattern="\d{6}" inputmode="numeric" autofocus required
                                       style="letter-spacing: 0.5em;">
                            </div>

                            <div class="form-footer">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="ti ti-shield-check me-2"></i>Weryfikuj
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
    <script src="/assets/js/tabler.min.js"></script>
</body>
</html>
