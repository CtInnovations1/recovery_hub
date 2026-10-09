<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

function fail(string $message, int $status = 400): never
{
    http_response_code($status);

    echo json_encode([
        'success' => false,
        'message' => $message,
    ]);

    exit;
}

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail('POST request required.', 405);
    }

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        fail('Invalid request.');
    }

    $deviceId =
        (int)($input['device_id'] ?? 0);

    $newRecoveryType =
        trim(
            (string)($input['new_recovery_type'] ?? '')
        );

    $destinationLocationId =
        (int)($input['destination_location_id'] ?? 0);

    $reason =
        trim(
            (string)($input['reason'] ?? '')
        );

    $allowedTypes = [
        'REC_WHATNOT',
        'REC_WHOLESALE',
        'REC_REPAIR',
        'REC_HARVEST',
    ];

    $pathToArea = [
        'REC_WHATNOT'   => 'RETAIL_ELIGIBLE',
        'REC_WHOLESALE' => 'WHOLESALE',
        'REC_REPAIR'    => 'REPAIR',
        'REC_HARVEST'   => 'HARVEST',
    ];

    if ($deviceId < 1) {
        fail('Invalid Recovery device.');
    }

    if (
        !in_array(
            $newRecoveryType,
            $allowedTypes,
            true
        )
    ) {
        fail('Invalid Recovery Path.');
    }

    if ($destinationLocationId < 1) {
        fail('Select a physical destination.');
    }

    if ($reason === '') {
        fail('Reroute reason is required.');
    }

    if (mb_strlen($reason) > 500) {
        fail(
            'Reroute reason cannot exceed 500 characters.'
        );
    }

    $expectedArea =
        $pathToArea[$newRecoveryType];

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | Lock Recovery device
    |--------------------------------------------------------------------------
    */

    $deviceStmt = $pdo->prepare("
        SELECT
            id,
            serial_number,
            recovery_type,
            erp_location_id,
            location_name
        FROM recovery_devices
        WHERE id = :id
        LIMIT 1
        FOR UPDATE
    ");

    $deviceStmt->execute([
        'id' => $deviceId,
    ]);

    $device =
        $deviceStmt->fetch(
            PDO::FETCH_ASSOC
        );

    if (!$device) {

        $pdo->rollBack();

        fail(
            'Recovery device not found.',
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | New path must be different
    |--------------------------------------------------------------------------
    */

    if (
        $device['recovery_type'] ===
        $newRecoveryType
    ) {

        $pdo->rollBack();

        fail(
            'The selected Recovery Path is already the current path.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Prevent multiple PENDING reroutes
    |--------------------------------------------------------------------------
    */

    $pendingStmt = $pdo->prepare("
        SELECT id
        FROM recovery_reroutes
        WHERE recovery_device_id = :device_id
          AND status = 'PENDING'
        LIMIT 1
        FOR UPDATE
    ");

    $pendingStmt->execute([
        'device_id' => $deviceId,
    ]);

    if ($pendingStmt->fetchColumn()) {

        $pdo->rollBack();

        fail(
            'This device already has a pending reroute.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validate selected ERP destination
    |--------------------------------------------------------------------------
    |
    | Recovery Hub does not trust the destination sent by the browser.
    | The location must exist in the synchronized ERP location cache,
    | be active and visible, and belong to the correct Recovery area.
    |
    */

    $locationStmt = $pdo->prepare("
        SELECT
            erp_location_id,
            location_name,
            recovery_area,
            location_status,
            purpose
        FROM recovery_locations
        WHERE erp_location_id = :erp_location_id
          AND recovery_area = :recovery_area
          AND is_active = 1
          AND is_visible = 1
        LIMIT 1
        FOR UPDATE
    ");

    $locationStmt->execute([
        'erp_location_id' =>
            $destinationLocationId,

        'recovery_area' =>
            $expectedArea,
    ]);

    $destination =
        $locationStmt->fetch(
            PDO::FETCH_ASSOC
        );

    if (!$destination) {

        $pdo->rollBack();

        fail(
            'The selected destination is not valid for this Recovery Path.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Physical destination must actually change
    |--------------------------------------------------------------------------
    */

    if (
        !empty($device['erp_location_id'])
        &&
        (int)$device['erp_location_id'] ===
            (int)$destination['erp_location_id']
    ) {

        $pdo->rollBack();

        fail(
            'The destination must be different from the current physical location.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Create PENDING reroute
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | recovery_devices is NOT changed here.
    |
    | Current Recovery Path and Location remain unchanged until the
    | physical destination is confirmed.
    |
    */

    $insert = $pdo->prepare("
        INSERT INTO recovery_reroutes
        (
            recovery_device_id,
            serial_number,

            original_recovery_type,
            new_recovery_type,

            original_location_id,
            original_location_name,

            new_location_id,
            new_location_name,

            reroute_reason,
            status,

            performed_by_user_name,
            requested_at
        )
        VALUES
        (
            :recovery_device_id,
            :serial_number,

            :original_recovery_type,
            :new_recovery_type,

            :original_location_id,
            :original_location_name,

            :new_location_id,
            :new_location_name,

            :reroute_reason,
            'PENDING',

            :performed_by_user_name,
            NOW()
        )
    ");

    $insert->execute([
        'recovery_device_id' =>
            $device['id'],

        'serial_number' =>
            $device['serial_number'],

        'original_recovery_type' =>
            $device['recovery_type'],

        'new_recovery_type' =>
            $newRecoveryType,

        'original_location_id' =>
            $device['erp_location_id'],

        'original_location_name' =>
            $device['location_name'],

        'new_location_id' =>
            $destination['erp_location_id'],

        'new_location_name' =>
            $destination['location_name'],

        'reroute_reason' =>
            $reason,

        'performed_by_user_name' =>
            'Recovery Hub',
    ]);

    $rerouteId =
        (int)$pdo->lastInsertId();

    $pdo->commit();

    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,

        'reroute' => [
            'id' =>
                $rerouteId,

            'status' =>
                'PENDING',

            'serial_number' =>
                $device['serial_number'],

            'original_recovery_type' =>
                $device['recovery_type'],

            'new_recovery_type' =>
                $newRecoveryType,

            'original_location_id' =>
                $device['erp_location_id'],

            'original_location_name' =>
                $device['location_name'],

            'new_location_id' =>
                $destination['erp_location_id'],

            'new_location_name' =>
                $destination['location_name'],

            'new_location_status' =>
                $destination['location_status'],

            'new_recovery_area' =>
                $destination['recovery_area'],

            'reroute_reason' =>
                $reason,
        ],
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'Unable to create reroute.',
    ]);
}
