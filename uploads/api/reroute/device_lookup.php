<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

try {

    $serial = trim((string)($_GET['serial'] ?? ''));

    if ($serial === '') {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Serial number is required.',
        ]);

        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            d.id,
            d.erp_device_id,
            d.serial_number,
            d.product_id,
            d.product_description,
            d.product_category,
            d.product_type,
            d.grade,
            d.recovery_type,
            d.recovery_status,
            d.erp_location_id,
            d.location_name,
            d.location_type,
            d.cost_basis,
            d.estimated_recovery_value,
            d.erp_synced_at
        FROM recovery_devices d
        WHERE d.serial_number = :serial
        LIMIT 1
    ");

    $stmt->execute([
        'serial' => $serial,
    ]);

    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Serial not found in Recovery inventory.',
        ]);

        exit;
    }

    $defectStmt = $pdo->prepare("
        SELECT
            defect_code,
            defect_description
        FROM recovery_device_defects
        WHERE recovery_device_id = :device_id
        ORDER BY defect_code
    ");

    $defectStmt->execute([
        'device_id' => $device['id'],
    ]);

    $device['defects'] =
        $defectStmt->fetchAll(PDO::FETCH_ASSOC);

    $pendingStmt = $pdo->prepare("
        SELECT
            id,
            new_recovery_type,
            new_location_id,
            new_location_name,
            reroute_reason,
            requested_at
        FROM recovery_reroutes
        WHERE recovery_device_id = :device_id
          AND status = 'PENDING'
        ORDER BY id DESC
        LIMIT 1
    ");

    $pendingStmt->execute([
        'device_id' => $device['id'],
    ]);

    $device['pending_reroute'] =
        $pendingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    echo json_encode([
        'success' => true,
        'device' => $device,
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load Recovery device.',
    ]);
}
