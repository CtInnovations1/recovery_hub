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
    respond(['success'=>false,'message'=>'POST request required.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) respond(['success'=>false,'message'=>'Invalid request.'], 400);

$showId = (int)($input['show_id'] ?? 0);
$deviceIds = $input['device_ids'] ?? [];

if ($showId < 1 || !is_array($deviceIds) || !$deviceIds) {
    respond(['success'=>false,'message'=>'Show and devices are required.'], 400);
}

$deviceIds = array_values(array_unique(array_filter(array_map('intval',$deviceIds), fn($id)=>$id>0)));

$showStmt = $pdo->prepare("SELECT id,status FROM recovery_shows WHERE id=:id LIMIT 1");
$showStmt->execute(['id'=>$showId]);
$show = $showStmt->fetch(PDO::FETCH_ASSOC);
if (!$show) respond(['success'=>false,'message'=>'Show not found.'], 404);
if ($show['status'] === 'CLOSED') respond(['success'=>false,'message'=>'Closed shows cannot be edited.'], 409);

$ph = implode(',', array_fill(0, count($deviceIds), '?'));

$eligible = $pdo->prepare("
    SELECT d.id
    FROM recovery_devices d
    WHERE d.id IN ($ph)
      AND d.recovery_type = 'REC_WHATNOT'
      AND d.outcome_at IS NULL
      AND COALESCE(d.erp_available,0)=1
      AND d.location_name LIKE 'REC-RETAIL-ELIGIBLE-%'
      AND NOT EXISTS (
          SELECT 1
          FROM recovery_reroutes r
          WHERE r.recovery_device_id = d.id
            AND r.status = 'PENDING'
      )
");
$eligible->execute($deviceIds);
$eligibleIds = array_map('intval', $eligible->fetchAll(PDO::FETCH_COLUMN));

if (!$eligibleIds) {
    respond(['success'=>false,'message'=>'None of the selected devices are currently Retail Eligible.'], 409);
}

$ph2 = implode(',', array_fill(0, count($eligibleIds), '?'));
$assigned = $pdo->prepare("
    SELECT i.recovery_device_id
    FROM recovery_show_items i
    INNER JOIN recovery_shows s ON s.id = i.show_id
    WHERE i.recovery_device_id IN ($ph2)
      AND i.item_status = 'ASSIGNED'
      AND s.status <> 'CLOSED'
      AND i.show_id <> ?
");
$assigned->execute(array_merge($eligibleIds, [$showId]));
$already = array_flip(array_map('intval', $assigned->fetchAll(PDO::FETCH_COLUMN)));

$insert = $pdo->prepare("
    INSERT INTO recovery_show_items
    (show_id, recovery_device_id, item_status, added_at)
    VALUES (:show_id, :recovery_device_id, 'ASSIGNED', NOW())
    ON DUPLICATE KEY UPDATE item_status='ASSIGNED', removed_at=NULL
");

$added=0; $skipped=0;
$pdo->beginTransaction();
try {
    foreach ($eligibleIds as $deviceId) {
        if (isset($already[$deviceId])) { $skipped++; continue; }
        $insert->execute(['show_id'=>$showId,'recovery_device_id'=>$deviceId]);
        $added++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(['success'=>false,'message'=>'Unable to assign devices to show.'],500);
}

respond(['success'=>true,'added'=>$added,'skipped'=>$skipped,'message'=>$added.' device(s) added to show.']);
