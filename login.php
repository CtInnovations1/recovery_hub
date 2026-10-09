<?php
declare(strict_types=1);
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/erp_bridge.php';
recoverySession();
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['_csrf'] ?? ''))) {
            throw new RuntimeException('Reload this page and try again.');
        }
        $bridge = new ERPBridge(require __DIR__.'/config/integration.php');
        $result = $bridge->request('POST', '/login', ['email' => trim((string) ($_POST['email'] ?? '')), 'password' => (string) ($_POST['password'] ?? '')]);
        session_regenerate_id(true);
        $_SESSION['operator'] = $result['user'];
        $_SESSION['erp_token'] = $result['token'];
        $_SESSION['expires_at'] = time() + 8 * 3600;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        header('Location: /index.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in · Recovery Hub</title><link rel="stylesheet" href="/assets/css/erp-theme-v5.css">
 </head><body class="recovery-app"><main class="login-page"><section class="card">
<div class="cti-brand"><div class="cti-logo">CTI</div><div class="cti-brand-text">CTI ERP</div></div>
<h1>Recovery Hub</h1><p class="page-description">Sign in with your ERP account.</p>
<?php if ($error): ?><p role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post" class="workflow-form"><input type="hidden" name="_csrf" value="<?= htmlspecialchars(recoveryCsrf(), ENT_QUOTES) ?>">
<p><label>Email <input style="width:100%" type="email" name="email" autocomplete="username" required></label></p>
<p><label>Password <input style="width:100%" type="password" name="password" autocomplete="current-password" required></label></p>
<button type="submit" class="btn-primary">Sign in</button></form></section></main></body></html>
