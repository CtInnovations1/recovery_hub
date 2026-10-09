<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

try {

    $deviceId = filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT
    );

    if (!$deviceId || $deviceId < 1) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid device ID.',
        ]);

        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            erp_device_id,
            serial_number,
            product_id,
            product_description,
            product_category,
            product_type,
            grade,
            source_type,
            source_reference,
            vendor_id,
            vendor_name,
            recovery_type,
            recovery_status,
            erp_location_id,
            location_name,
            location_type,
            cost_basis,
            estimated_recovery_value,
            entered_recovery_at,
            outcome_at,
            erp_synced_at,
            created_at,
            updated_at
        FROM recovery_devices
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        'id' => $deviceId,
    ]);

    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Recovery device not found.',
        ]);

        exit;
    }

    $defectStmt = $pdo->prepare("
        SELECT
            defect_code,
            defect_description,
            defect_source
        FROM recovery_device_defects
        WHERE recovery_device_id = :device_id
        ORDER BY defect_code
    ");

    $defectStmt->execute([
        'device_id' => $deviceId,
    ]);

    $device['defects'] =
        $defectStmt->fetchAll(PDO::FETCH_ASSOC);

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
