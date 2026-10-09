<?php
declare(strict_types=1);
require_once __DIR__.'/../config/database.php';
$currentPage = 'integration';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ERP connection · Recovery Hub</title><link rel="stylesheet" href="/assets/css/erp-theme-v5.css"></head><body class="recovery-app">
<?php require __DIR__.'/../includes/header.php'; ?>
<main class="page workflow-page"><div class="page-header"><div><h1 class="page-title">ERP connection</h1><p class="page-description">Refresh device snapshots and valid physical destinations from ERP.</p></div></div><section class="card workflow-card"><h2>Connection and synchronization</h2>
<p><button id="check">Check connection</button> <button class="btn-primary" id="sync">Synchronize devices, locations and outcomes</button></p><pre id="result" role="status"></pre></section>
<p><a href="/pages/movement.php">Confirm physical movement</a> · <a href="/pages/inventory.php">Recovery inventory</a></p></main>
<script>
const result=document.getElementById('result');const sync=document.getElementById('sync');
async function request(body){const response=await fetch('/api/erp/bridge.php',body?{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)}:{headers:{'Accept':'application/json'}});const data=await response.json();if(!response.ok||!data.success)throw new Error(data.message||'Connection failed.');return data;}
document.getElementById('check').onclick=async()=>{try{const data=await request();result.textContent=data.movement_ready?'ERP connected. Movement receipts are ready.':'ERP connected. Install the movement migration before confirming devices.';}catch(e){result.textContent=e.message;}};
sync.onclick=async()=>{sync.disabled=true;try{const locations=await request({action:'locations'});result.textContent=locations.locations+' locations synchronized.\n';let cursor=0;let count=0;let more=true;while(more){const page=await request({action:'devices',after_id:cursor});if(page.has_more&&page.next_after_id<=cursor)throw new Error('ERP cursor did not advance.');cursor=page.next_after_id;more=page.has_more;count+=page.stats.inserted+page.stats.updated;result.textContent=locations.locations+' locations synchronized.\n'+count+' device snapshots synchronized.';}cursor=0;more=true;let outcomes=0;while(more){const page=await request({action:'outcomes',after_id:cursor});if(page.has_more&&page.next_after_id<=cursor)throw new Error('Outcome cursor did not advance.');cursor=page.next_after_id;more=page.has_more;outcomes+=page.outcomes;}result.textContent+='\n'+outcomes+' outcomes synchronized.';}catch(e){result.textContent+='\n'+e.message;}finally{sync.disabled=false;}};
</script></body></html>
