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

    /*
    |--------------------------------------------------------------------------
    | Device
    |--------------------------------------------------------------------------
    */

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

    $device =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Recovery device not found.',
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Defects
    |--------------------------------------------------------------------------
    */

    $defectStmt = $pdo->prepare("
        SELECT
            defect_code,
            defect_description,
            defect_source,
            created_at
        FROM recovery_device_defects
        WHERE recovery_device_id = :device_id
        ORDER BY defect_code
    ");

    $defectStmt->execute([
        'device_id' => $deviceId,
    ]);

    $device['defects'] =
        $defectStmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Reroute History
    |--------------------------------------------------------------------------
    */

    $rerouteStmt = $pdo->prepare("
        SELECT
            id,
            original_recovery_type,
            new_recovery_type,
            original_location_id,
            original_location_name,
            new_location_id,
            new_location_name,
            reroute_reason,
            status,
            performed_by_user_id,
            performed_by_user_name,
            requested_at,
            confirmed_at,
            confirmed_by_user_id,
            confirmed_by_user_name,
            rerouted_at
        FROM recovery_reroutes
        WHERE recovery_device_id = :device_id
        ORDER BY
            COALESCE(
                confirmed_at,
                rerouted_at,
                requested_at
            ) DESC,
            id DESC
    ");

    $rerouteStmt->execute([
        'device_id' => $deviceId,
    ]);

    $device['reroutes'] =
        $rerouteStmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Recovery Outcomes
    |--------------------------------------------------------------------------
    */

    $outcomeStmt = $pdo->prepare("
        SELECT
            id,
            recovery_type,
            actual_recovery_value,
            outcome_type,
            external_reference_type,
            external_reference_id,
            notes,
            outcome_at,
            created_at
        FROM recovery_outcomes
        WHERE recovery_device_id = :device_id
        ORDER BY
            outcome_at DESC,
            id DESC
    ");

    $outcomeStmt->execute([
        'device_id' => $deviceId,
    ]);

    $device['outcomes'] =
        $outcomeStmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

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
