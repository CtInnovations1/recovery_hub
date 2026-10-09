<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/confirm_movement.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false, 'message'=>'POST required.']); exit; }
try {
    $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input)) { throw new InvalidArgumentException('Invalid request.'); }
    $config = require __DIR__.'/../../config/integration.php';
    $result = confirmRecoveryMovement($pdo, new ERPBridge($config), (int) ($input['reroute_id'] ?? 0),
        (string) ($input['serial_scan'] ?? ''), (string) ($input['destination_scan'] ?? ''),
        $_SESSION['operator'], $_SESSION['erp_token'], (string) $config['instance_id']);
    echo json_encode($result);
} catch (Throwable $e) {
    $status = $e instanceof ERPBridgeException ? ($e->httpStatus ?: 502) : ($e instanceof InvalidArgumentException ? 422 : 500);
    http_response_code($status >= 400 && $status <= 599 ? $status : 502);
    $message = $e instanceof PDOException ? 'Unable to save the ERP receipt. Retry this same movement after checking the integration schema.' : $e->getMessage();
    echo json_encode(['success' => false, 'message' => $message]);
}
