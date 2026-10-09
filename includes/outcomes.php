<?php
declare(strict_types=1);

function storeRecoveryOutcome(PDO $pdo, array $receipt): void
{
    if (($receipt['status'] ?? '') !== 'COMPLETED' || empty($receipt['request_key']) || empty($receipt['outcome_at'])) {
        throw new RuntimeException('ERP did not supply a completed serialized outcome.');
    }
    $find=$pdo->prepare('SELECT * FROM recovery_devices WHERE erp_device_id=? FOR UPDATE');$find->execute([$receipt['device_id']]);$device=$find->fetch(PDO::FETCH_ASSOC);
    if (!$device || strtoupper($device['serial_number']) !== strtoupper($receipt['serial_number'])) { throw new RuntimeException('Outcome device is not linked to Recovery. Synchronize devices first.'); }
    $first=$pdo->prepare('SELECT original_recovery_type FROM recovery_reroutes WHERE recovery_device_id=? ORDER BY requested_at,id LIMIT 1');$first->execute([$device['id']]);
    $initial=$first->fetchColumn() ?: $device['recovery_type'];
    $pdo->prepare('INSERT INTO recovery_outcomes(recovery_device_id,recovery_type,actual_recovery_value,outcome_type,external_reference_type,external_reference_id,outcome_at,request_key,gross_recovery,direct_costs,net_recovery,currency,performed_by_user_id,performed_by_user_name,final_disposition,initial_recovery_type,cost_basis_snapshot,source_type_snapshot,source_reference_snapshot,vendor_name_snapshot)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE request_key=VALUES(request_key)')->execute([
            $device['id'],$receipt['recovery_type'],$receipt['net_recovery'],$receipt['outcome_type'],$receipt['external_reference_type'],$receipt['external_reference_id'],$receipt['outcome_at'],$receipt['request_key'],
            $receipt['gross_recovery'],$receipt['direct_costs'],$receipt['net_recovery'],$receipt['currency'],$receipt['user_id'],$receipt['user_name']??null,$receipt['final_disposition']??null,$initial,$device['cost_basis'],$device['source_type'],$device['source_reference'],$device['vendor_name'],
        ]);
    $pdo->prepare("UPDATE recovery_devices SET recovery_status='COMPLETED',outcome_at=?,erp_available=0 WHERE id=?")->execute([$receipt['outcome_at'],$device['id']]);
}
