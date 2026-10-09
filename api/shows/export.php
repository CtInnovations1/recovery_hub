<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';

$showId = (int)($_GET['show_id'] ?? 0);
if ($showId < 1) { http_response_code(400); exit('Invalid show.'); }

$showStmt = $pdo->prepare("SELECT id,show_name,show_date,status FROM recovery_shows WHERE id=:id LIMIT 1");
$showStmt->execute(['id'=>$showId]);
$show = $showStmt->fetch(PDO::FETCH_ASSOC);
if (!$show) { http_response_code(404); exit('Show not found.'); }

$stmt = $pdo->prepare("
    SELECT
        d.erp_device_id,
        d.serial_number,
        d.product_id,
        d.product_description,
        d.product_category,
        d.product_type,
        d.grade,
        d.location_name,
        d.cost_basis,
        d.estimated_recovery_value,
        GROUP_CONCAT(DISTINCT df.defect_code ORDER BY df.id SEPARATOR ' | ') AS defect_codes
    FROM recovery_show_items i
    INNER JOIN recovery_devices d ON d.id = i.recovery_device_id
    LEFT JOIN recovery_device_defects df ON df.recovery_device_id = d.id
    WHERE i.show_id=:show_id
      AND i.item_status='ASSIGNED'
    GROUP BY
        d.id,d.erp_device_id,d.serial_number,d.product_id,d.product_description,
        d.product_category,d.product_type,d.grade,d.location_name,d.cost_basis,
        d.estimated_recovery_value
    ORDER BY d.product_id,d.serial_number
");
$stmt->execute(['show_id'=>$showId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$show['show_name']);
$filename = 'whatnot_show_' . trim($safeName,'_') . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');

$out = fopen('php://output','w');
fwrite($out, "ï»¿");

fputcsv($out, [
    'Show ID','Show Name','Show Date','Serial Number','ERP Device ID',
    'Product ID','Product Description','Category','Product Type','Grade',
    'Defect Codes','Location','Cost Basis','Estimated Recovery Value',
    'Starting Bid','Notes'
]);

foreach ($rows as $row) {
    fputcsv($out, [
        $show['id'],$show['show_name'],$show['show_date'],$row['serial_number'],
        $row['erp_device_id'],$row['product_id'],$row['product_description'],
        $row['product_category'],$row['product_type'],$row['grade'],
        $row['defect_codes'],$row['location_name'],$row['cost_basis'],
        $row['estimated_recovery_value'],'',''
    ]);
}
fclose($out);

if ($show['status'] !== 'CLOSED') {
    $u = $pdo->prepare("UPDATE recovery_shows SET status='EXPORTED',updated_at=NOW() WHERE id=:id");
    $u->execute(['id'=>$showId]);
}
