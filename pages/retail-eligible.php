<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$currentPage = 'retail-eligible';

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function moneyOrDash(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return '$' . number_format((float)$value, 2);
}

$search = trim((string)($_GET['search'] ?? ''));
$showId = (int)($_GET['show_id'] ?? 0);

$where = [
    "d.recovery_type = 'REC_WHATNOT'",
    "d.location_name LIKE 'REC-RETAIL-ELIGIBLE-%'",
    "NOT EXISTS (
        SELECT 1 FROM recovery_reroutes r
        WHERE r.recovery_device_id = d.id
          AND r.status = 'PENDING'
    )",
    "NOT EXISTS (
        SELECT 1
        FROM recovery_show_items si
        INNER JOIN recovery_shows s ON s.id = si.show_id
        WHERE si.recovery_device_id = d.id
          AND si.item_status = 'ASSIGNED'
          AND s.status <> 'CLOSED'
    )"
];

$params = [];

if ($search !== '') {
    $where[] = "(
        d.serial_number LIKE :s1
        OR d.product_id LIKE :s2
        OR d.product_description LIKE :s3
        OR d.location_name LIKE :s4
    )";
    $like = '%' . $search . '%';
    $params = ['s1'=>$like,'s2'=>$like,'s3'=>$like,'s4'=>$like];
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        d.id,d.erp_device_id,d.serial_number,d.product_id,d.product_description,
        d.product_category,d.product_type,d.grade,d.location_name,d.cost_basis,
        d.estimated_recovery_value,
        GROUP_CONCAT(DISTINCT df.defect_code ORDER BY df.id SEPARATOR ' | ') AS defect_codes
    FROM recovery_devices d
    LEFT JOIN recovery_device_defects df ON df.recovery_device_id = d.id
    {$whereSql}
    GROUP BY
        d.id,d.erp_device_id,d.serial_number,d.product_id,d.product_description,
        d.product_category,d.product_type,d.grade,d.location_name,d.cost_basis,
        d.estimated_recovery_value
    ORDER BY d.product_id,d.serial_number
    LIMIT 1000
");
$stmt->execute($params);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$shows = $pdo->query("
    SELECT
        s.id,s.show_name,s.show_date,s.channel,s.status,s.notes,s.created_at,
        COUNT(CASE WHEN i.item_status='ASSIGNED' THEN 1 END) AS device_count,
        COALESCE(SUM(CASE WHEN i.item_status='ASSIGNED' THEN d.cost_basis ELSE 0 END),0) AS total_cost
    FROM recovery_shows s
    LEFT JOIN recovery_show_items i ON i.show_id=s.id
    LEFT JOIN recovery_devices d ON d.id=i.recovery_device_id
    GROUP BY s.id,s.show_name,s.show_date,s.channel,s.status,s.notes,s.created_at
    ORDER BY COALESCE(s.show_date,s.created_at) DESC,s.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$selectedShow = null;
$selectedItems = [];

if ($showId > 0) {
    $s = $pdo->prepare("SELECT * FROM recovery_shows WHERE id=:id LIMIT 1");
    $s->execute(['id'=>$showId]);
    $selectedShow = $s->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($selectedShow) {
        $i = $pdo->prepare("
            SELECT
                d.id,d.serial_number,d.product_id,d.product_description,d.grade,
                d.location_name,d.cost_basis,
                GROUP_CONCAT(DISTINCT df.defect_code ORDER BY df.id SEPARATOR ' | ') AS defect_codes
            FROM recovery_show_items si
            INNER JOIN recovery_devices d ON d.id=si.recovery_device_id
            LEFT JOIN recovery_device_defects df ON df.recovery_device_id=d.id
            WHERE si.show_id=:show_id
              AND si.item_status='ASSIGNED'
            GROUP BY d.id,d.serial_number,d.product_id,d.product_description,
                     d.grade,d.location_name,d.cost_basis
            ORDER BY d.product_id,d.serial_number
        ");
        $i->execute(['show_id'=>$showId]);
        $selectedItems = $i->fetchAll(PDO::FETCH_ASSOC);
    }
}

$activeShows = 0;
foreach ($shows as $show) {
    if ($show['status'] !== 'CLOSED') $activeShows++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Retail Eligible | Recovery Hub</title>
<link rel="stylesheet" href="/assets/css/erp-theme-v5.css">
<style>
.retail-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(340px,.6fr);gap:18px;align-items:start}
.metric-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px}
.metric{padding:16px}.metric-label{color:#858998;font-size:11px;font-weight:700}.metric-value{margin-top:5px;color:#0f1b3d;font-size:24px;font-weight:800}
.toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin-bottom:12px}
.toolbar-left,.toolbar-right{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.search-input,.form-input,.form-select,.form-textarea{border:1px solid #d9dde5;border-radius:5px;background:#fff;color:#26304a;font-family:inherit;outline:none}
.search-input,.form-input,.form-select{height:40px;padding:0 10px}.search-input{min-width:280px}.form-textarea{width:100%;min-height:72px;padding:9px 10px;resize:vertical}
.btn{min-height:40px;border-radius:5px;padding:0 14px;border:1px solid #d9dde5;background:#fff;color:#26304a;font-family:inherit;font-size:12px;font-weight:700;cursor:pointer}
.btn-primary{border-color:#1976d2;background:#1976d2;color:#fff}.btn:disabled{opacity:.5;cursor:not-allowed}
.table-wrap{overflow:auto;max-height:650px}table{width:100%;border-collapse:collapse;font-size:11px}
th{position:sticky;top:0;z-index:1;padding:9px 8px;background:#f8fafc;border-bottom:1px solid #e4e7ec;text-align:left;white-space:nowrap;color:#596174}
td{padding:9px 8px;border-bottom:1px solid #edf0f3;vertical-align:top}.serial{color:#0f1b3d;font-weight:800}.muted{color:#858998}
.pill{display:inline-flex;padding:3px 7px;border-radius:999px;background:#eef7f0;color:#347744;font-size:10px;font-weight:800}
.shows-panel{overflow:hidden}.panel-head{padding:15px 16px;border-bottom:1px solid #e4e7ec;color:#0f1b3d;font-weight:800}.panel-body{padding:15px 16px}
.show-form{display:grid;gap:9px}.show-list{margin-top:16px;border-top:1px solid #edf0f3}
.show-item{display:block;padding:12px 0;border-bottom:1px solid #edf0f3;color:inherit;text-decoration:none}.show-item-title{color:#0f1b3d;font-size:12px;font-weight:800}.show-item-meta{margin-top:4px;color:#858998;font-size:10px}
.show-actions{display:flex;gap:6px;margin-top:8px}.message{display:none;margin:12px 0;padding:10px 12px;border-radius:5px;font-size:11px}.message.visible{display:block}.message.error{background:#fff4f2;border:1px solid #fecdca;color:#b42318}.message.success{background:#ecfdf3;border:1px solid #abefc6;color:#067647}.selected-show{margin-top:18px}
@media(max-width:1050px){.retail-grid{grid-template-columns:1fr}}@media(max-width:700px){.metric-row{grid-template-columns:1fr}.search-input{min-width:0;width:100%}}
</style>
</head>
<body class="recovery-app">
<?php require __DIR__ . '/../includes/header.php'; ?>
<main class="page">
<div class="page-header"><div><h1 class="page-title">Retail Eligible</h1><p class="page-description">Build Whatnot show lists from devices physically staged in Retail Eligible locations.</p></div></div>
<div class="metric-row">
<div class="card metric"><div class="metric-label">AVAILABLE FOR SHOW</div><div class="metric-value"><?= number_format(count($devices)) ?></div></div>
<div class="card metric"><div class="metric-label">ACTIVE SHOWS</div><div class="metric-value"><?= number_format($activeShows) ?></div></div>
<div class="card metric"><div class="metric-label">CHANNEL</div><div class="metric-value" style="font-size:18px;">Whatnot</div></div>
</div>
<div id="message" class="message"></div>
<div class="retail-grid">
<section class="card">
<div class="panel-head">Eligible Inventory</div>
<div class="panel-body">
<form method="get" class="toolbar">
<div class="toolbar-left">
<input class="search-input" type="text" name="search" value="<?= h($search) ?>" placeholder="Search serial, product, description, location">
<button class="btn" type="submit">Search</button>
<?php if ($search !== ''): ?><a class="btn" href="/pages/retail-eligible.php">Clear</a><?php endif; ?>
</div>
<div class="toolbar-right">
<select id="targetShow" class="form-select"><option value="">Select Show</option>
<?php foreach ($shows as $show): if ($show['status'] !== 'CLOSED'): ?>
<option value="<?= (int)$show['id'] ?>"><?= h($show['show_name']) ?></option>
<?php endif; endforeach; ?>
</select>
<button id="addButton" type="button" class="btn btn-primary" onclick="addSelectedToShow()">Add Selected to Show</button>
</div>
</form>
<div class="table-wrap"><table><thead><tr>
<th><input type="checkbox" id="selectAll" onchange="toggleAll(this.checked)"></th><th>Serial</th><th>Product</th><th>Grade</th><th>Defects</th><th>Location</th><th>Cost</th>
</tr></thead><tbody>
<?php if (!$devices): ?><tr><td colspan="7" class="muted">No Retail Eligible devices available for a show.</td></tr>
<?php else: foreach ($devices as $device): ?>
<tr>
<td><input type="checkbox" class="device-check" value="<?= (int)$device['id'] ?>"></td>
<td><span class="serial"><?= h($device['serial_number']) ?></span></td>
<td><strong><?= h($device['product_id']) ?></strong><div class="muted"><?= h($device['product_description']) ?></div></td>
<td><span class="pill"><?= h($device['grade'] ?: '—') ?></span></td>
<td><?= h($device['defect_codes'] ?: '—') ?></td>
<td><?= h($device['location_name']) ?></td>
<td><?= h(moneyOrDash($device['cost_basis'])) ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>
</div></section>
<aside class="card shows-panel">
<div class="panel-head">Whatnot Shows</div>
<div class="panel-body">
<div class="show-form">
<input id="showName" class="form-input" type="text" placeholder="Show name">
<input id="showDate" class="form-input" type="datetime-local">
<textarea id="showNotes" class="form-textarea" maxlength="500" placeholder="Notes"></textarea>
<button type="button" class="btn btn-primary" onclick="createShow()">Create Show</button>
</div>
<div class="show-list">
<?php if (!$shows): ?><div class="muted" style="padding:14px 0;">No shows created yet.</div>
<?php else: foreach ($shows as $show): ?>
<a class="show-item" href="/pages/retail-eligible.php?show_id=<?= (int)$show['id'] ?>">
<div class="show-item-title"><?= h($show['show_name']) ?></div>
<div class="show-item-meta"><?= number_format((int)$show['device_count']) ?> devices · <?= h($show['status']) ?> · <?= h(moneyOrDash($show['total_cost'])) ?></div>
<div class="show-item-meta"><?= h($show['show_date'] ?: 'No date set') ?></div>
</a>
<?php endforeach; endif; ?>
</div>
<?php if ($selectedShow): ?>
<div class="selected-show">
<div class="panel-head" style="padding-left:0;padding-right:0;"><?= h($selectedShow['show_name']) ?></div>
<div class="show-actions"><a class="btn btn-primary" href="/api/shows/export.php?show_id=<?= (int)$selectedShow['id'] ?>">Download CSV</a></div>
<div style="margin-top:12px;">
<?php if (!$selectedItems): ?><div class="muted">No devices assigned to this show.</div>
<?php else: foreach ($selectedItems as $item): ?>
<div style="padding:9px 0;border-bottom:1px solid #edf0f3;">
<div class="serial"><?= h($item['serial_number']) ?></div>
<div class="muted"><?= h($item['product_id']) ?> · <?= h($item['grade'] ?: '—') ?> · <?= h($item['location_name']) ?></div>
<button type="button" class="btn" style="margin-top:6px;min-height:30px;" onclick="removeFromShow(<?= (int)$selectedShow['id'] ?>,<?= (int)$item['id'] ?>)">Remove</button>
</div>
<?php endforeach; endif; ?>
</div></div>
<?php endif; ?>
</div></aside>
</div>
</main>
<script>
function message(text,type){const box=document.getElementById('message');box.textContent=text;box.className='message visible '+type;}
function toggleAll(checked){document.querySelectorAll('.device-check').forEach(cb=>cb.checked=checked);}
async function createShow(){
 const showName=document.getElementById('showName').value.trim();
 const showDate=document.getElementById('showDate').value;
 const notes=document.getElementById('showNotes').value.trim();
 if(!showName){message('Enter a show name.','error');return;}
 try{
  const response=await fetch('/api/shows/create.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({show_name:showName,show_date:showDate,notes:notes})});
  const result=await response.json();
  if(!response.ok||!result.success) throw new Error(result.message||'Unable to create show.');
  window.location='/pages/retail-eligible.php?show_id='+result.show_id;
 }catch(error){message(error.message||'Unable to create show.','error');}
}
async function addSelectedToShow(){
 const showId=parseInt(document.getElementById('targetShow').value,10);
 const deviceIds=Array.from(document.querySelectorAll('.device-check:checked')).map(cb=>parseInt(cb.value,10));
 if(!showId){message('Select a show.','error');return;}
 if(!deviceIds.length){message('Select at least one device.','error');return;}
 try{
  const response=await fetch('/api/shows/add_items.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({show_id:showId,device_ids:deviceIds})});
  const result=await response.json();
  if(!response.ok||!result.success) throw new Error(result.message||'Unable to add devices.');
  window.location='/pages/retail-eligible.php?show_id='+showId;
 }catch(error){message(error.message||'Unable to add devices.','error');}
}
async function removeFromShow(showId,deviceId){
 if(!confirm('Remove this device from the show?')) return;
 try{
  const response=await fetch('/api/shows/remove_item.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({show_id:showId,device_id:deviceId})});
  const result=await response.json();
  if(!response.ok||!result.success) throw new Error(result.message||'Unable to remove device.');
  window.location.reload();
 }catch(error){message(error.message||'Unable to remove device.','error');}
}
</script>
</body>
</html>
