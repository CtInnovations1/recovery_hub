<?php
declare(strict_types=1);

function recoverySession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('recovery_operator');
        session_start(['cookie_secure' => getenv('RECOVERY_ENV') !== 'local', 'cookie_httponly' => true, 'cookie_samesite' => 'Lax',
            'use_strict_mode' => true, 'gc_maxlifetime' => 28800]);
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function requireRecoveryOperator(): array
{
    recoverySession();
    $api = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/') || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if (empty($_SESSION['operator']) || empty($_SESSION['erp_token']) || ($_SESSION['expires_at'] ?? 0) <= time()) {
        if ($api) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Sign in with your ERP account.', 'error' => 'Sign in with your ERP account.']);
        } else {
            header('Location: /login.php');
        }
        exit;
    }
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Session verification failed. Reload the page.']);
            exit;
        }
    }
    return $_SESSION['operator'];
}

function recoveryCsrf(): string
{
    recoverySession();
    return $_SESSION['csrf'];
}
