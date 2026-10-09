<?php
declare(strict_types=1);
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/erp_bridge.php';
requireRecoveryOperator();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
try {
    (new ERPBridge(require __DIR__.'/config/integration.php'))->request('POST', '/logout', [], $_SESSION['erp_token']);
} catch (Throwable $e) {
    // The remote token also has an eight-hour expiry; always clear the local session.
}
$_SESSION = [];
session_destroy();
header('Location: /login.php');
