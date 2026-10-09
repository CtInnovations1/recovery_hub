<?php
declare(strict_types=1);

function requestRecoveryReroutes(PDO $pdo, array $ids, string $path, int $locationId, string $reason, array $operator): array
{
    $areas = ['REC_WHATNOT'=>'RETAIL_ELIGIBLE','REC_WHOLESALE'=>'WHOLESALE','REC_REPAIR'=>'REPAIR','REC_HARVEST'=>'HARVEST'];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
    sort($ids);
    $reason = trim($reason);
    if (!$ids || count($ids) > 10000 || !isset($areas[$path]) || $locationId < 1 || $reason === '' || mb_strlen($reason) > 500) {
        throw new InvalidArgumentException('Select 1–10000 devices, a Recovery Path, a destination and a reason (maximum 500 characters).');
    }
    $pdo->beginTransaction();
    try {
        $location = $pdo->prepare('SELECT * FROM recovery_locations WHERE erp_location_id=? AND recovery_area=? AND is_active=1 AND is_visible=1 FOR UPDATE');
        $location->execute([$locationId, $areas[$path]]);
        $destination = $location->fetch(PDO::FETCH_ASSOC);
        if (!$destination || in_array($destination['location_status'], ['hold','full'], true)) {
            throw new InvalidArgumentException('Choose an active destination with available capacity in the selected area.');
        }
        $results = [];
        foreach ($ids as $id) {
            $stmt = $pdo->prepare('SELECT * FROM recovery_devices WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$device || !$device['erp_device_id'] || $device['outcome_at'] !== null || $device['recovery_status'] === 'COMPLETED' || $device['erp_available'] === 0 || $device['erp_available'] === '0') {
                throw new InvalidArgumentException('All selected devices must be linked to ERP and available in Recovery.');
            }
            if ($device['recovery_type'] === $path || (int) $device['erp_location_id'] === $locationId) {
                throw new InvalidArgumentException($device['serial_number'].': both path and physical location must change.');
            }
            $pending = $pdo->prepare("SELECT id FROM recovery_reroutes WHERE recovery_device_id=? AND status='PENDING' LIMIT 1");
            $pending->execute([$id]);
            if ($pending->fetchColumn()) { throw new InvalidArgumentException($device['serial_number'].': a pending reroute already exists.'); }
            $commerce=$pdo->prepare("SELECT id FROM recovery_commerce_listings WHERE recovery_device_id=? AND state IN ('PENDING','EXPORTED') LIMIT 1");$commerce->execute([$id]);
            if($commerce->fetchColumn()){throw new InvalidArgumentException($device['serial_number'].': commerce listing is reserved; resolve it before rerouting.');}
            $show = $pdo->prepare("SELECT i.id FROM recovery_show_items i JOIN recovery_shows s ON s.id=i.show_id WHERE i.recovery_device_id=? AND i.item_status='ASSIGNED' AND s.status<>'CLOSED' LIMIT 1");
            $show->execute([$id]);
            if ($show->fetchColumn()) { throw new InvalidArgumentException($device['serial_number'].': remove it from its active show before rerouting.'); }
            $pdo->prepare("INSERT INTO recovery_reroutes (recovery_device_id,serial_number,original_recovery_type,new_recovery_type,original_location_id,original_location_name,new_location_id,new_location_name,reroute_reason,status,performed_by_user_id,performed_by_user_name,requested_at)
                VALUES (?,?,?,?,?,?,?,?,?,'PENDING',?,?,UTC_TIMESTAMP())")->execute([
                    $id,$device['serial_number'],$device['recovery_type'],$path,$device['erp_location_id'],$device['location_name'],
                    $locationId,$destination['location_name'],$reason,$operator['id'],$operator['name'],
                ]);
            $results[] = ['id'=>(int)$pdo->lastInsertId(),'status'=>'PENDING','serial_number'=>$device['serial_number'],
                'original_recovery_type'=>$device['recovery_type'],'new_recovery_type'=>$path,
                'original_location_id'=>$device['erp_location_id'],'original_location_name'=>$device['location_name'],
                'new_location_id'=>$locationId,'new_location_name'=>$destination['location_name'],
                'new_location_status'=>$destination['location_status'],'new_recovery_area'=>$destination['recovery_area'],'reroute_reason'=>$reason];
        }
        $pdo->commit();
        return $results;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}
