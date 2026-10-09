<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/database.php';

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success'=>false,'message'=>'POST request required.'],405);
}

$input = json_decode(file_get_contents('php://input'), true);
$showId = (int)($input['show_id'] ?? 0);
$deviceId = (int)($input['device_id'] ?? 0);

if ($showId < 1 || $deviceId < 1) {
    respond(['success'=>false,'message'=>'Invalid show or device.'],400);
}

$stmt = $pdo->prepare("
    UPDATE recovery_show_items
    SET item_status='REMOVED', removed_at=NOW()
    WHERE show_id=:show_id
      AND recovery_device_id=:device_id
      AND item_status='ASSIGNED'
");
$stmt->execute(['show_id'=>$showId,'device_id'=>$deviceId]);

respond(['success'=>true,'message'=>'Device removed from show.']);
