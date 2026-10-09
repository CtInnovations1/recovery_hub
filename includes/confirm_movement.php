<?php
declare(strict_types=1);
require_once __DIR__.'/erp_bridge.php';

function confirmRecoveryMovement(PDO $pdo, ERPBridge $bridge, int $rerouteId, string $serialScan, string $destinationScan, array $operator, string $token, string $instance): array
{
    $serialScan = strtoupper(trim($serialScan));
    $destinationScan = strtoupper(trim($destinationScan));
    if ($rerouteId < 1 || $serialScan === '' || $destinationScan === '' || !preg_match('/^[a-zA-Z0-9_-]{3,40}$/', $instance)) {
        throw new InvalidArgumentException('Scan destination and serial, and configure the Recovery instance ID.');
    }
    // Persist the exact request BEFORE contacting SiteGround; never invent a new key after a timeout.
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT r.*, d.erp_device_id FROM recovery_reroutes r JOIN recovery_devices d ON d.id=r.recovery_device_id WHERE r.id=? FOR UPDATE');
        $stmt->execute([$rerouteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || strtoupper(trim($row['serial_number'])) !== $serialScan) {
            throw new InvalidArgumentException('The scanned serial does not match this pending request.');
        }
        if (!$row['erp_device_id']) {
            throw new InvalidArgumentException('Link this device to an ERP device before moving it.');
        }
        if (!in_array($row['status'], ['PENDING', 'COMPLETED'], true)) {
            throw new InvalidArgumentException('This request cannot be confirmed.');
        }
        $payload = $row['confirmation_payload'] ? json_decode($row['confirmation_payload'], true, 512, JSON_THROW_ON_ERROR) : null;
        if ($payload) {
            if ($payload['destination_scan'] !== $destinationScan || $payload['serial_number'] !== $serialScan) {
                throw new InvalidArgumentException('Retry using the same destination and serial as the original confirmation.');
            }
        } else {
            $location = $pdo->prepare('SELECT location_name FROM recovery_locations WHERE erp_location_id=? AND is_active=1 AND is_visible=1');
            $location->execute([$row['new_location_id']]);
            $currentName = $location->fetchColumn();
            if (!$currentName || strtoupper(trim($currentName)) !== $destinationScan) {
                throw new InvalidArgumentException('Scan the current ERP destination label; synchronize locations if it changed.');
            }
            $payload = [
                'request_key' => $row['request_key'] ?: $instance.':'.bin2hex(random_bytes(16)),
                'device_id' => (int) $row['erp_device_id'], 'serial_number' => $serialScan,
                'original_recovery_type' => $row['original_recovery_type'],
                'original_location_id' => $row['original_location_id'] === null ? null : (int) $row['original_location_id'],
                'new_recovery_type' => $row['new_recovery_type'], 'destination_location_id' => (int) $row['new_location_id'],
                'destination_scan' => $destinationScan, 'reason' => $row['reroute_reason'],
            ];
            $pdo->prepare('UPDATE recovery_reroutes SET request_key=?, confirmation_payload=? WHERE id=?')->execute([
                $payload['request_key'], json_encode($payload, JSON_THROW_ON_ERROR), $rerouteId,
            ]);
        }
        if ($row['status'] === 'COMPLETED') {
            if (!$row['erp_receipt']) { throw new RuntimeException('This historical completion has no ERP receipt.'); }
            $pdo->commit();
            return json_decode($row['erp_receipt'], true, 512, JSON_THROW_ON_ERROR);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    try {
        $receipt = $bridge->request('POST', '/reroutes/confirm', $payload, $token);
        if (($receipt['request_key'] ?? '') !== $payload['request_key'] || (int) ($receipt['device_id'] ?? 0) !== $payload['device_id'] ||
            ($receipt['recovery_type'] ?? '') !== $payload['new_recovery_type'] || (int) ($receipt['location_id'] ?? 0) !== $payload['destination_location_id']) {
            throw new RuntimeException('ERP receipt does not match the requested movement.');
        }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE recovery_reroutes SET status='COMPLETED', confirmed_at=?, rerouted_at=?, confirmed_by_user_id=?,
            confirmed_by_user_name=?, erp_receipt=?, last_error=NULL, new_location_name=? WHERE id=? AND request_key=?")->execute([
                $receipt['confirmed_at'], $receipt['confirmed_at'], $receipt['user_id'],
                $receipt['user_name'] ?? $operator['name'], json_encode($receipt, JSON_THROW_ON_ERROR), $receipt['location_name'], $rerouteId, $payload['request_key'],
            ]);
        // A delayed retry must not rewind a newer ERP movement: update this local snapshot only when it still matches the request's starting state.
        $pdo->prepare('UPDATE recovery_devices SET recovery_type=?, erp_location_id=?, location_name=?, erp_synced_at=UTC_TIMESTAMP()
            WHERE id=? AND recovery_type=? AND (erp_location_id <=> ?)')->execute([
                $receipt['recovery_type'], $receipt['location_id'], $receipt['location_name'], $row['recovery_device_id'],
                $row['original_recovery_type'], $row['original_location_id'],
            ]);
        $pdo->commit();
        return $receipt;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $pdo->prepare('UPDATE recovery_reroutes SET last_error=? WHERE id=? AND status=\'PENDING\'')->execute([mb_substr($e->getMessage(), 0, 500), $rerouteId]);
        // A validation rejection is an authoritative non-commit. Transport errors retain the exact frozen request.
        if ($e instanceof ERPBridgeException && $e->httpStatus === 422) {
            $pdo->prepare("UPDATE recovery_reroutes SET confirmation_payload=NULL,request_key=NULL WHERE id=? AND status='PENDING' AND erp_receipt IS NULL")
                ->execute([$rerouteId]);
        }
        throw $e;
    }
}
