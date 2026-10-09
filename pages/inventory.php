<?php
declare(strict_types=1);
require __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/ui_components.php';
$currentPage='inventory';$devices=recoveryUiDevices($pdo);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recovery Hub · Inventory</title><link rel="stylesheet" href="/assets/css/erp-theme-v5.css"></head><body class="recovery-app">
<?php require __DIR__.'/../includes/header.php';?>
<main class="page inventory-workspace"><div class="breadcrumb"><a href="/index.php">Recovery</a><span>›</span>Recovery Inventory</div>
<div class="page-header"><div><h1 class="page-title">Recovery Hub</h1><p class="page-description">Manage, reroute, and track inventory assigned to recovery.</p></div><div class="dashboard-actions"><a class="btn-secondary" href="/pages/analysis-detail.php">Grouped analysis</a><button class="btn-primary" id="topReroute">＋ Reroute devices</button></div></div>
<div id="inventoryMetrics" class="inventory-kpis"></div>
<section class="card inventory-panel"><div class="inventory-tabs" id="inventoryTabs"></div><div class="inventory-filters" id="inventoryFilters"></div>
<div class="inventory-toolbar"><div><span id="inventorySelected">0 selected</span><button id="inventoryReroute" disabled>↻ Reroute</button><button id="inventoryWholesale" disabled>Wholesale handoff</button><button id="inventoryCommerce" disabled>Export to commerce</button></div><div><button id="inventoryExport">↓ Export CSV</button><button id="inventoryClear">Clear selection</button><a class="btn-secondary" href="/pages/inventory-grouped.php">Detailed groups</a></div></div>
<div class="table-wrap"><table class="serialized-table"><thead id="inventoryHead"></thead><tbody id="inventoryRows"></tbody></table></div><div class="inventory-pagination"><span id="inventoryCount"></span><div><button id="inventoryPrev" aria-label="Previous page">‹</button><span id="inventoryPage"></span><button id="inventoryNext" aria-label="Next page">›</button><select id="inventoryPageSize" aria-label="Devices per page"><option>25</option><option>50</option><option>100</option></select></div></div>
</section><p class="subtle">Dates, costs and aging display “—” when the original records do not supply them. Route changes require a confirmed physical movement.</p></main>
<script src="/assets/js/recovery-inventory.js"></script><script>RecoveryInventory.mount(<?=json_encode($devices,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)?>);</script></body></html>
