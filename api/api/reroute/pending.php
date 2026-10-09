<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

try {

    $stmt = $pdo->query("
        SELECT
            r.id,
            r.recovery_device_id,
            r.serial_number,

            d.product_id,
            d.product_description,

            r.original_recovery_type,
            r.new_recovery_type,

            r.original_location_name,
            r.new_location_id,
            r.new_location_name,

            r.reroute_reason,
            r.status,
            r.performed_by_user_name,
            r.requested_at

        FROM recovery_reroutes r

        LEFT JOIN recovery_devices d
            ON d.id = r.recovery_device_id

        WHERE r.status = 'PENDING'

        ORDER BY
            r.requested_at DESC,
            r.id DESC
    ");

    echo json_encode([
        'success' => true,
        'reroutes' =>
            $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'Unable to load pending reroutes.',
    ]);
}
