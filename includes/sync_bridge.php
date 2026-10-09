<?php
declare(strict_types=1);
require_once __DIR__.'/erp_bridge.php';
require_once __DIR__.'/outcomes.php';

function recoverySyncOutcomes(PDO $pdo, ERPBridge $bridge, int $afterId = 0): array
{
    $data=$bridge->request('GET','/outcomes?after_id='.$afterId);
    if(!is_array($data['outcomes']??null)){throw new RuntimeException('Invalid ERP outcomes response.');}
    $pdo->beginTransaction();
    try {foreach($data['outcomes']as$receipt){storeRecoveryOutcome($pdo,$receipt);}$pdo->commit();}
    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['success'=>true,'outcomes'=>count($data['outcomes']),'has_more'=>(bool)$data['has_more'],'next_after_id'=>(int)$data['next_after_id']];
}

function recoverySyncDevices(PDO $pdo, ERPBridge $bridge, int $afterId = 0): array
{
    $data = $bridge->request('GET', '/devices?limit=100&after_id='.$afterId);
    if (!isset($data['devices'], $data['has_more'], $data['next_after_id']) || !is_array($data['devices'])) {
        throw new RuntimeException('Invalid ERP device page.');
    }
    $stats = ['inserted' => 0, 'updated' => 0];
    $pdo->beginTransaction();
    try {
        foreach ($data['devices'] as $device) {
            $id = (int) ($device['erp_device_id'] ?? 0);
            $serial = trim((string) ($device['serial_number'] ?? ''));
            if ($id <= $afterId || $serial === '') { throw new RuntimeException('ERP returned invalid device identity.'); }
            $find = $pdo->prepare('SELECT id,erp_device_id,serial_number FROM recovery_devices WHERE erp_device_id=? OR serial_number=? FOR UPDATE');
            $find->execute([$id, $serial]);
            $existing = $find->fetchAll(PDO::FETCH_ASSOC);
            if (count($existing) > 1 || ($existing && $existing[0]['erp_device_id'] !== null && (int) $existing[0]['erp_device_id'] !== $id)) {
                throw new RuntimeException('ERP ID/serial conflict. Resolve the duplicate before synchronization.');
            }
            $fields = ['erp_device_id', 'serial_number', 'product_id', 'product_description', 'product_category',
                'product_type', 'grade', 'source_type', 'source_reference', 'vendor_id', 'vendor_name',
                'recovery_type', 'erp_location_id', 'location_name', 'cost_basis', 'erp_updated_at'];
            $values = [];
            foreach ($fields as $field) { $values[] = $device[$field] ?? null; }
            $values[] = !empty($device['erp_available']) ? 1 : 0;
            if ($existing) {
                $recoveryId = (int) $existing[0]['id'];
                $set = implode(',', array_map(static fn($f) => "$f=?", $fields));
                $pdo->prepare("UPDATE recovery_devices SET $set,erp_available=?,erp_synced_at=UTC_TIMESTAMP(),first_observed_at=COALESCE(first_observed_at,UTC_TIMESTAMP()) WHERE id=?")
                    ->execute([...$values, $recoveryId]);
                $stats['updated']++;
            } else {
                $columns = implode(',', $fields);
                $placeholders = implode(',', array_fill(0, count($values), '?'));
                $pdo->prepare("INSERT INTO recovery_devices ($columns,erp_available,recovery_status,erp_synced_at,first_observed_at) VALUES ($placeholders,'OPEN',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
                    ->execute($values);
                $recoveryId = (int) $pdo->lastInsertId();
                $stats['inserted']++;
            }
            // Keep manual defects; refresh upstream snapshots only.
            $pdo->prepare("DELETE FROM recovery_device_defects WHERE recovery_device_id=? AND defect_source IN ('initial','determined')")->execute([$recoveryId]);
            foreach (['initial', 'determined'] as $source) {
                $raw = $device[$source.'_defect_codes'] ?? '';
                $codes = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?? explode(',', (string) $raw));
                if (!is_array($codes)) { $codes = []; }
                foreach (array_unique(array_filter(array_map(static fn($v) => strtoupper(trim((string) $v)), $codes))) as $code) {
                    $description = $pdo->prepare('SELECT COALESCE(NULLIF(short_definition_en,\'\'),NULLIF(definition_en,\'\'),erp_description) FROM defect_library WHERE defect_code=? LIMIT 1');
                    $description->execute([$code]);
                    $pdo->prepare('INSERT INTO recovery_device_defects (recovery_device_id,defect_code,defect_description,defect_source) VALUES (?,?,?,?)')
                        ->execute([$recoveryId, $code, $description->fetchColumn() ?: $code, $source]);
                }
            }
        }
        $pdo->prepare("INSERT INTO recovery_sync_state(name,value,updated_at) VALUES ('last_device_page',?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=VALUES(updated_at)")
            ->execute([(string) $data['next_after_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return ['success' => true, 'stats' => $stats, 'has_more' => (bool) $data['has_more'], 'next_after_id' => (int) $data['next_after_id']];
}

function recoverySyncLocations(PDO $pdo, ERPBridge $bridge): array
{
    $data = $bridge->request('GET', '/locations');
    $locations = $data['locations'] ?? [];
    if (!is_array($locations) || !$locations) { throw new RuntimeException('ERP returned no Recovery locations. Existing cache was preserved.'); }
    $pathByArea = ['RETAIL_ELIGIBLE' => 'REC_WHATNOT', 'WHOLESALE' => 'REC_WHOLESALE', 'REPAIR' => 'REC_REPAIR', 'HARVEST' => 'REC_HARVEST'];
    $pdo->beginTransaction();
    try {
        $ids = [];
        foreach ($locations as $row) {
            if (empty($row['erp_location_id']) || empty($row['location_name']) || !isset($pathByArea[$row['recovery_area'] ?? ''])) {
                throw new RuntimeException('Invalid location payload. Cache was preserved.');
            }
            $ids[] = (int) $row['erp_location_id'];
            $pdo->prepare('INSERT INTO recovery_locations (erp_location_id,location_name,location_status,purpose,recovery_area,is_visible,is_active,erp_updated_at,last_synced_at)
                VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE location_name=VALUES(location_name),location_status=VALUES(location_status),purpose=VALUES(purpose),recovery_area=VALUES(recovery_area),is_visible=VALUES(is_visible),is_active=VALUES(is_active),erp_updated_at=VALUES(erp_updated_at),last_synced_at=VALUES(last_synced_at)')
                ->execute([$row['erp_location_id'], $row['location_name'], $row['location_status'], $row['purpose'], $row['recovery_area'], $row['is_visible'], $row['is_active'], $row['erp_updated_at']]);
            // Keep the existing dashboard's location map consistent with the canonical cache.
            $pdo->prepare('INSERT INTO recovery_location_map (recovery_type,erp_location_id,location_name,is_active,erp_synced_at) VALUES (?,?,?,?,UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE location_name=VALUES(location_name),is_active=VALUES(is_active),erp_synced_at=VALUES(erp_synced_at)')
                ->execute([$pathByArea[$row['recovery_area']], $row['erp_location_id'], $row['location_name'], $row['is_active']]);
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE recovery_locations SET is_active=0 WHERE erp_location_id NOT IN ($marks)")->execute($ids);
        $pdo->prepare("UPDATE recovery_location_map SET is_active=0 WHERE erp_location_id NOT IN ($marks)")->execute($ids);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return ['success' => true, 'locations' => count($ids)];
}
