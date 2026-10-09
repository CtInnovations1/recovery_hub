<?php
declare(strict_types=1);
function recoveryUiEscape(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function recoveryUiDevices(PDO $pdo): array {
    return $pdo->query("SELECT d.*,
        (SELECT GROUP_CONCAT(DISTINCT defect_code SEPARATOR '||') FROM recovery_device_defects WHERE recovery_device_id=d.id) AS defect_codes,
        (SELECT MAX(confirmed_at) FROM recovery_reroutes WHERE recovery_device_id=d.id AND status='COMPLETED') AS last_movement,
        EXISTS(SELECT 1 FROM recovery_reroutes WHERE recovery_device_id=d.id AND status='PENDING') AS pending_reroute,
        EXISTS(SELECT 1 FROM recovery_commerce_listings WHERE recovery_device_id=d.id AND state IN ('PENDING','EXPORTED')) AS commerce_reserved
        FROM recovery_devices d ORDER BY d.serial_number")->fetchAll(PDO::FETCH_ASSOC);
}
function recoveryUiSteps(array $labels,int $active=1): void {
    echo '<div class="workflow-steps" aria-label="Workflow progress">';
    foreach($labels as $i=>$label){echo '<div class="workflow-step '.($i+1<=$active?'is-active':'').'"><span>'.($i+1).'</span><div><strong>'.recoveryUiEscape($label).'</strong></div></div>';}
    echo '</div>';
}
function recoveryUiSelection(array $devices,string $path):void {
    $eligible=array_values(array_filter($devices,fn($d)=>$d['recovery_type']===$path && !$d['outcome_at'] && $d['erp_available'] && !$d['pending_reroute'] && !$d['commerce_reserved']));
    echo '<div class="handoff-layout"><aside><section class="card workflow-card"><h2>Selected Recovery Group</h2><div class="group-identity"><span class="context-icon">▣</span><div><strong id="groupProduct">All eligible products</strong><p class="subtle">'.recoveryUiEscape($path).'</p></div></div><dl class="summary-list"><dt>Available units</dt><dd>'.count($eligible).'</dd><dt>Selected units</dt><dd id="flowSelected">0</dd><dt>Known cost basis</dt><dd id="flowCost">—</dd><dt>Avg. cost per unit</dt><dd id="flowAverage">—</dd></dl></section><section class="card workflow-card"><h2>Filters</h2><label>Product <select id="flowProduct"><option value="">All products</option></select></label><label>Location <select id="flowLocation"><option value="">All locations</option></select></label><label>Grade <select id="flowGrade"><option value="">All grades</option></select></label><label>Search serial <input id="flowSearch" type="search" placeholder="Search serial or description…"></label><button type="button" id="flowAll">Select eligible results</button></section></aside><div class="handoff-content"><section class="card workflow-card"><div class="section-toolbar"><div><h2>Selected Devices</h2><p class="subtle">Choose the serialized devices for this transaction.</p></div><button type="button" id="flowClear">Clear selection</button></div><div class="table-wrap flow-table"><table><thead><tr><th>Select</th><th>Serial number</th><th>Product ID</th><th>Grade</th><th>Cost basis</th><th>Current location</th><th>Source</th><th>Source #</th></tr></thead><tbody id="flowRows"></tbody></table></div><p class="subtle" id="flowCount"></p></section>';
    echo '<script>window.recoveryFlowDevices='.json_encode($eligible,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE).';</script>';
}
