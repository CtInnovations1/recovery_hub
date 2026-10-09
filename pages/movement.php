<?php
declare(strict_types=1);
require_once __DIR__.'/../config/database.php';
$currentPage = 'movement';
$pending = $pdo->query("SELECT id,serial_number,original_recovery_type,new_recovery_type,new_location_name,reroute_reason FROM recovery_reroutes WHERE status='PENDING' ORDER BY requested_at,id")->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Confirm physical movement · Recovery Hub</title><link rel="stylesheet" href="/assets/css/erp-theme-v5.css">
</head><body class="recovery-app">
<?php require __DIR__.'/../includes/header.php'; ?>
<main class="page workflow-page"><div class="page-header"><div><h1 class="page-title">Confirm physical movement</h1><p class="page-description">Move the devices to the requested destination. Scan that location label, then scan each serial. ERP commits the Recovery Path and physical location together.</p></div></div>
<fieldset><legend>Warehouse scans</legend><form id="scanForm" class="workflow-form"><p><label>Destination label <input id="destination" required autocomplete="off" placeholder="Scan destination"></label></p>
<p><label>Device serial <input id="serial" required autocomplete="off" placeholder="Scan serial"></label> <button id="confirm" class="btn-primary">Confirm movement</button></p></form></fieldset>
<p id="message" role="status" aria-live="polite"></p>
<section class="card workflow-card"><h2>Pending movements</h2><div class="table-wrap"><table><thead><tr><th>Serial</th><th>Requested path</th><th>Destination</th><th>Reason</th><th>Action</th></tr></thead><tbody id="pending"></tbody></table></div></section>
<p><a href="/pages/reroute.php">Request a reroute</a> · <a href="/pages/integration.php">Synchronize ERP locations</a></p></main>
<script>
let pending = <?= json_encode($pending, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function render(){const body=document.getElementById('pending');body.replaceChildren();for(const row of pending){const tr=document.createElement('tr');for(const value of [row.serial_number,row.new_recovery_type,row.new_location_name,row.reroute_reason]){const td=document.createElement('td');td.textContent=value;tr.append(td);}const td=document.createElement('td');const cancel=document.createElement('button');cancel.textContent='Cancel request';cancel.onclick=async()=>{if(!confirm('Cancel this pending request?'))return;const response=await fetch('/api/reroute/cancel.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:row.id})});const data=await response.json();if(!data.success){document.getElementById('message').textContent=data.message;return;}pending=pending.filter(r=>r.id!==row.id);render();};td.append(cancel);tr.append(td);body.append(tr);}}
render();
document.getElementById('destination').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();document.getElementById('serial').focus();}});
document.getElementById('scanForm').addEventListener('submit',async e=>{e.preventDefault();const serial=document.getElementById('serial');const button=document.getElementById('confirm');const message=document.getElementById('message');
 const matches=pending.filter(row=>row.serial_number.trim().toUpperCase()===serial.value.trim().toUpperCase());
 if(matches.length!==1){message.className='error';message.textContent='This serial must have exactly one pending reroute.';return;}
 button.disabled=true;try{const response=await fetch('/api/reroute/confirm.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({reroute_id:matches[0].id,serial_scan:serial.value,destination_scan:document.getElementById('destination').value})});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Movement was not confirmed.');message.className='ok';message.textContent=result.serial_number+' confirmed in '+result.location_name;pending=pending.filter(row=>row.id!==matches[0].id);render();serial.value='';serial.focus();}catch(error){message.className='error';message.textContent=error.message+' If the connection failed, retry the same scan.';}finally{button.disabled=false;}
});
</script></body></html>
