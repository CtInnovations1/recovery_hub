<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/sync_bridge.php';
try {
    $bridge = new ERPBridge(require __DIR__.'/../../config/integration.php');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode($bridge->request('GET', '/health')); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    $result = match ($input['action'] ?? '') {
        'locations' => recoverySyncLocations($pdo, $bridge),
        'devices' => recoverySyncDevices($pdo, $bridge, max(0, (int) ($input['after_id'] ?? 0))),
        'outcomes' => recoverySyncOutcomes($pdo, $bridge, max(0, (int) ($input['after_id'] ?? 0))),
        default => throw new InvalidArgumentException('Unknown synchronization action.'),
    };
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 422 : 502);
    if ($e instanceof PDOException) { error_log($e->getMessage()); }
    echo json_encode(['success' => false, 'message' => $e instanceof PDOException ? 'Database integration schema is not ready, or synchronization failed. Check the server log.' : $e->getMessage()]);
}
