<?php
declare(strict_types=1);
require_once __DIR__.'/../config/database.php';
$currentPage='analysis';
// Separate aggregate subqueries keep one row per serialized device.
$devices=$pdo->query("SELECT d.*, o.actual_recovery_value, o.last_outcome_at,
    IF(o.outcome_count>0,o.cost_basis_snapshot,d.cost_basis) AS cost_basis,
    o.gross_recovery,o.direct_costs,o.external_reference_id,
    (SELECT GROUP_CONCAT(DISTINCT f.defect_code SEPARATOR '||') FROM recovery_device_defects f WHERE f.recovery_device_id=d.id) AS defect_codes,
    (SELECT MAX(r.confirmed_at) FROM recovery_reroutes r WHERE r.recovery_device_id=d.id AND r.status='COMPLETED') AS last_movement,
    EXISTS(SELECT 1 FROM recovery_reroutes r WHERE r.recovery_device_id=d.id AND r.status='PENDING') AS pending_reroute
    FROM recovery_devices d LEFT JOIN (
        SELECT recovery_device_id,COUNT(*) AS outcome_count,SUM(actual_recovery_value) AS actual_recovery_value,MAX(outcome_at) AS last_outcome_at,
        MAX(cost_basis_snapshot) AS cost_basis_snapshot,SUM(gross_recovery) AS gross_recovery,SUM(direct_costs) AS direct_costs,MAX(external_reference_id) AS external_reference_id FROM recovery_outcomes GROUP BY recovery_device_id
    ) o ON o.recovery_device_id=d.id ORDER BY d.serial_number")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Configurable analysis · Recovery Hub</title>
<link rel="stylesheet" href="/assets/css/erp-theme-v5.css"></head><body class="recovery-app"><?php require __DIR__.'/../includes/header.php'; ?>
<main class="page analysis-page"><div class="breadcrumb"><a href="/index.php">Recovery</a><span>›</span>Recovery Analysis</div><div class="page-header"><div><h1 class="page-title">Recovery Analysis</h1><p class="page-description">Filter the dataset, choose a grouping order and drill down to each serial.</p></div></div>
<section class="panel"><h2>Filters</h2><div id="filters" class="filters"></div><p><button id="reset">Reset filters</button> <a href="/pages/analysis.php">Summary reports</a></p></section><section class="panel"><div id="metrics" class="metrics"></div><div id="summaryNote" class="subtle"></div></section><div class="analysis-workspace"><aside><section class="panel"><h2>Group by</h2><div id="dimensions" class="dimensions"></div><p><select id="addDimension" aria-label="Add grouping dimension"></select> <button id="add">Add dimension</button></p><p class="subtle">Use ↑ and ↓ to reorder. Devices with several defects can appear under several defect groups; the overall totals count each device once.</p></section><section class="panel"><h2>Saved Views</h2><div id="analysisViews"></div><button id="saveAnalysisView">＋ Save current view</button></section></aside><div class="analysis-content"><section class="panel"><div class="actions"><h2>Serialized inventory</h2><span id="selectedCount">0 selected</span><button id="selectAll">Select eligible results</button><button id="clear">Clear selection</button><button id="reroute" class="primary">Reroute selected</button></div><div class="group-column-head"><span>Group</span><span>Devices</span><span>Cost basis</span><span>Estimated</span><span>Avg. net</span><span>Days</span><span>Completed</span><span>Open</span></div><div id="tree"></div></section></div></div></main>
<script src="/assets/js/recovery-analysis.js"></script>
<script>RecoveryAnalysis.mount(<?=json_encode($devices,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)?>);</script></body></html>
