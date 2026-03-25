<?php
require_once __DIR__ . '/../app/core/Bootstrap.php';

Database::migrate();

$db = Database::get();
$userCount = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$isFirstUser = ($userCount === 0);

// Tylko pierwszy user może się zarejestrować publicznie
// Kolejnych tworzy zalogowany admin
if (!$isFirstUser) {
    redirect('/login');
}

$error = '';
$step = 'create'; // create | totp_setup | done
$totpSecret = '';
$totpUri = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        // Sprawdź czy login zajęty
        $existingUser = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $existingUser->execute([$username]);
        $usernameTaken = (int) $existingUser->fetchColumn() > 0;

        if (!$username) {
            $error = 'Podaj login';
        } elseif ($usernameTaken) {
            $error = 'Ten login jest już zajęty';
        } elseif (strlen($password) < 8) {
            $error = 'Hasło musi mieć minimum 8 znaków';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Hasła nie pasują do siebie';
        } else {
            $hash = Auth::hashPassword($password);
            $totpSecret = Auth::generateTotpSecret();

            $stmt = $db->prepare("INSERT INTO users (username, password_hash, totp_secret, totp_enabled) VALUES (?, ?, ?, 0)");
            $stmt->execute([$username, $hash, $totpSecret]);

            $_SESSION['setup_user_id'] = (int) $db->lastInsertId();
            $_SESSION['setup_totp_secret'] = $totpSecret;

            $step = 'totp_setup';
            $totpUri = Auth::getTotpUri($totpSecret, $username);
        }
    }

    if ($action === 'verify_totp') {
        $code = trim($_POST['totp_code'] ?? '');
        $userId = $_SESSION['setup_user_id'] ?? 0;
        $totpSecret = $_SESSION['setup_totp_secret'] ?? '';

        if ($userId && $totpSecret && $code) {
            if (Auth::verifyTotp($totpSecret, $code)) {
                $stmt = $db->prepare("UPDATE users SET totp_enabled = 1 WHERE id = ?");
                $stmt->execute([$userId]);

                unset($_SESSION['setup_user_id'], $_SESSION['setup_totp_secret']);
                Auth::login($userId);

                // Zaimportuj dane startowe tylko dla pierwszego usera
                if ($isFirstUser) {
                    require_once __DIR__ . '/../app/seed.php';
                }

                redirect('/');
            } else {
                $error = 'Nieprawidłowy kod. Spróbuj ponownie.';
                $step = 'totp_setup';
                $totpUri = Auth::getTotpUri($totpSecret, '');
            }
        }
    }

    if ($action === 'skip_totp') {
        $userId = $_SESSION['setup_user_id'] ?? 0;
        if ($userId) {
            unset($_SESSION['setup_user_id'], $_SESSION['setup_totp_secret']);
            Auth::login($userId);
            if ($isFirstUser) {
                require_once __DIR__ . '/../app/seed.php';
            }
            redirect('/');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfiguracja - <?= APP_NAME ?></title>
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
                <p class="text-secondary"><?= $isFirstUser ? 'Pierwsza konfiguracja' : 'Rejestracja nowego konta' ?></p>
            </div>

            <div class="card card-md">
                <div class="card-body">
                    <?php if ($step === 'create'): ?>
                        <h2 class="h2 text-center mb-4">Utwórz konto</h2>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= sanitize($error) ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create">

                            <div class="mb-3">
                                <label class="form-label">Login</label>
                                <input type="text" name="username" class="form-control" autofocus required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Hasło (min. 8 znaków)</label>
                                <input type="password" name="password" class="form-control" minlength="8" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Powtórz hasło</label>
                                <input type="password" name="password_confirm" class="form-control" minlength="8" required>
                            </div>

                            <div class="form-footer">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="ti ti-user-plus me-2"></i>Utwórz konto
                                </button>
                            </div>
                        </form>

                    <?php elseif ($step === 'totp_setup'): ?>
                        <h2 class="h2 text-center mb-4">Konfiguracja 2FA</h2>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= sanitize($error) ?></div>
                        <?php endif; ?>

                        <div class="mb-3 text-center">
                            <p>Zeskanuj kod QR w aplikacji uwierzytelniającej<br>(Google Authenticator, Authy, itp.)</p>

                            <div class="mb-3 p-3 bg-white border rounded d-inline-block">
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($totpUri) ?>"
                                     alt="QR Code" width="200" height="200">
                            </div>

                            <div class="mb-3">
                                <small class="text-secondary">Lub wpisz ręcznie klucz:</small><br>
                                <code class="fs-4"><?= $_SESSION['setup_totp_secret'] ?? '' ?></code>
                            </div>
                        </div>

                        <form method="POST" class="mb-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="verify_totp">

                            <div class="mb-3">
                                <label class="form-label">Wpisz kod z aplikacji (6 cyfr)</label>
                                <input type="text" name="totp_code" class="form-control text-center fs-2"
                                       maxlength="6" pattern="\d{6}" inputmode="numeric" autofocus required
                                       style="letter-spacing: 0.5em;">
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="ti ti-shield-check me-2"></i>Aktywuj 2FA
                            </button>
                        </form>

                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="skip_totp">
                            <button type="submit" class="btn btn-ghost-secondary w-100">
                                Pomiń 2FA (niezalecane)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
    <script src="/assets/js/tabler.min.js"></script>
</body>
</html>
