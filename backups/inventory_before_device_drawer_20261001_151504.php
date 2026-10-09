<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$currentPage = 'inventory';

/*
|--------------------------------------------------------------------------
| Recovery Inventory — MVP
|--------------------------------------------------------------------------
|
| Aggregate-first management, serialized execution.
|
| Default hierarchy:
| Product ID -> Defect Code -> Recovery Path -> Serialized Devices
|
| ERP remains the source of truth for serialized device data.
|
*/

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function moneyOrDash($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return '$' . number_format((float)$value, 2);
}

function recoveryLabel(string $type): string
{
    return match ($type) {
        'REC_WHATNOT'   => 'Whatnot',
        'REC_WHOLESALE' => 'Wholesale',
        'REC_REPAIR'    => 'Repair',
        'REC_HARVEST'   => 'Harvest',
        default         => $type,
    };
}


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search       = trim((string)($_GET['search'] ?? ''));
$recoveryType = trim((string)($_GET['recovery_type'] ?? ''));
$defectCode   = trim((string)($_GET['defect_code'] ?? ''));
$location     = trim((string)($_GET['location'] ?? ''));

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(
        d.serial_number LIKE :search
        OR d.product_id LIKE :search
        OR d.product_description LIKE :search
    )';

    $params['search'] = '%' . $search . '%';
}

if ($recoveryType !== '') {
    $where[] = 'd.recovery_type = :recovery_type';
    $params['recovery_type'] = $recoveryType;
}

if ($defectCode !== '') {
    $where[] = 'EXISTS (
        SELECT 1
        FROM recovery_device_defects fx
        WHERE fx.recovery_device_id = d.id
          AND fx.defect_code = :defect_code
    )';

    $params['defect_code'] = $defectCode;
}

if ($location !== '') {
    $where[] = 'd.location_name = :location';
    $params['location'] = $location;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';


/*
|--------------------------------------------------------------------------
| Recovery Devices
|--------------------------------------------------------------------------
|
| One row per serialized device.
| Defects are aggregated separately so devices are not duplicated.
|
*/

$sql = "
    SELECT
        d.id,
        d.erp_device_id,
        d.serial_number,
        d.product_id,
        d.product_description,
        d.product_category,
        d.product_type,
        d.grade,
        d.recovery_type,
        d.recovery_status,
        d.erp_location_id,
        d.location_name,
        d.cost_basis,
        d.estimated_recovery_value,
        d.entered_recovery_at,
        d.erp_synced_at,

        GROUP_CONCAT(
            DISTINCT df.defect_code
            ORDER BY df.defect_code
            SEPARATOR '||'
        ) AS defect_codes

    FROM recovery_devices d

    LEFT JOIN recovery_device_defects df
        ON df.recovery_device_id = d.id

    {$whereSql}

    GROUP BY d.id

    ORDER BY
        COALESCE(NULLIF(d.product_id, ''), 'ZZZZ_UNASSIGNED'),
        d.recovery_type,
        d.serial_number
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$devices = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| Filter Options
|--------------------------------------------------------------------------
*/

$types = [
    'REC_WHATNOT',
    'REC_WHOLESALE',
    'REC_REPAIR',
    'REC_HARVEST',
];

$defects = $pdo->query("
    SELECT DISTINCT defect_code
    FROM recovery_device_defects
    WHERE defect_code IS NOT NULL
      AND defect_code <> ''
    ORDER BY defect_code
")->fetchAll(PDO::FETCH_COLUMN);

$locations = $pdo->query("
    SELECT DISTINCT location_name
    FROM recovery_devices
    WHERE location_name IS NOT NULL
      AND location_name <> ''
    ORDER BY location_name
")->fetchAll(PDO::FETCH_COLUMN);


/*
|--------------------------------------------------------------------------
| Build Analytical Hierarchy
|--------------------------------------------------------------------------
|
| A device can contain multiple defect codes.
| Therefore the same serial may appear under multiple defect branches.
|
| Device-level totals below are calculated from unique devices, not from
| hierarchy rows, preventing financial double-counting.
|
*/

$tree = [];

foreach ($devices as $device) {

    $productId = trim((string)($device['product_id'] ?? ''));

    if ($productId === '') {
        $productId = 'UNASSIGNED PRODUCT';
    }

    $rawDefects = trim((string)($device['defect_codes'] ?? ''));

    $deviceDefects = $rawDefects !== ''
        ? array_values(array_unique(array_filter(
            array_map('trim', explode('||', $rawDefects))
        )))
        : ['NO DEFECT CODE'];

    $path = (string)$device['recovery_type'];

    foreach ($deviceDefects as $defect) {

        if (!isset($tree[$productId])) {
            $tree[$productId] = [];
        }

        if (!isset($tree[$productId][$defect])) {
            $tree[$productId][$defect] = [];
        }

        if (!isset($tree[$productId][$defect][$path])) {
            $tree[$productId][$defect][$path] = [];
        }

        $tree[$productId][$defect][$path][] = $device;
    }
}


/*
|--------------------------------------------------------------------------
| Filtered Metrics
|--------------------------------------------------------------------------
|
| Calculated from unique serialized devices.
|
*/

$filteredQty = count($devices);

$knownCostQty = 0;
$missingCostQty = 0;
$filteredCostBasis = 0.0;

$knownEstimateQty = 0;
$filteredEstimatedRecovery = 0.0;

foreach ($devices as $device) {

    if ($device['cost_basis'] !== null) {
        $knownCostQty++;
        $filteredCostBasis += (float)$device['cost_basis'];
    } else {
        $missingCostQty++;
    }

    if ($device['estimated_recovery_value'] !== null) {
        $knownEstimateQty++;
        $filteredEstimatedRecovery +=
            (float)$device['estimated_recovery_value'];
    }
}

$recoveryPercent = null;

if (
    $filteredCostBasis > 0 &&
    $knownEstimateQty === $filteredQty &&
    $filteredQty > 0
) {
    $recoveryPercent =
        ($filteredEstimatedRecovery / $filteredCostBasis) * 100;
}


/*
|--------------------------------------------------------------------------
| Overall Inventory Count
|--------------------------------------------------------------------------
*/

$totalInventory = (int)$pdo->query("
    SELECT COUNT(*)
    FROM recovery_devices
")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Recovery Inventory | CTI ERP</title>

    <link
        rel="stylesheet"
        href="/assets/css/erp-theme.css"
    >

    <style>

        .inventory-metrics {
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:14px;
            margin-bottom:18px;
        }

        .filter-card {
            padding:16px;
            margin-bottom:18px;
        }

        .filters {
            display:grid;
            grid-template-columns:2fr 1fr 1fr 1fr auto;
            gap:12px;
            align-items:end;
        }

        .field label {
            display:block;
            margin-bottom:6px;
            font-size:12px;
            font-weight:600;
            color:#596174;
        }

        .field input,
        .field select {
            width:100%;
            height:40px;
            border:1px solid #d9dde5;
            border-radius:5px;
            background:#fff;
            color:#26304a;
            padding:0 11px;
            outline:none;
        }

        .field input:focus,
        .field select:focus {
            border-color:#1683f8;
            box-shadow:0 0 0 2px rgba(22,131,248,.10);
        }

        .btn {
            height:40px;
            border-radius:5px;
            padding:0 17px;
            cursor:pointer;
            font-weight:600;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .btn-primary {
            border:0;
            background:#1976d2;
            color:#fff;
        }

        .btn-secondary {
            background:#fff;
            color:#26304a;
            border:1px solid #d9dde5;
        }

        .filter-actions {
            display:flex;
            gap:8px;
        }

        .inventory-card {
            overflow:hidden;
        }

        .inventory-toolbar {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            padding:14px 16px;
            border-bottom:1px solid #e4e7ec;
        }

        .inventory-toolbar-title {
            font-size:14px;
            font-weight:700;
            color:#26304a;
        }

        .inventory-toolbar-meta {
            font-size:12px;
            color:#858998;
        }

        .hierarchy-header {
            display:grid;
            grid-template-columns:
                minmax(350px,1fr)
                90px
                150px
                140px
                140px;
            gap:12px;
            padding:11px 16px;
            background:#fafbfc;
            border-bottom:1px solid #e4e7ec;
            color:#596174;
            font-size:11px;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:.03em;
        }

        .money {
            text-align:right;
            font-variant-numeric:tabular-nums;
        }

        details {
            margin:0;
        }

        summary {
            list-style:none;
        }

        summary::-webkit-details-marker {
            display:none;
        }

        .tree-row {
            display:grid;
            grid-template-columns:
                minmax(350px,1fr)
                90px
                150px
                140px
                140px;
            gap:12px;
            align-items:center;
            min-height:48px;
            padding:8px 16px;
            border-bottom:1px solid #edf0f3;
            cursor:pointer;
        }

        .tree-row:hover {
            background:#f8fbff;
        }

        .tree-label {
            min-width:0;
            display:flex;
            align-items:center;
            gap:8px;
        }

        .tree-text {
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }

        .chevron {
            width:16px;
            flex:0 0 16px;
            color:#858998;
            font-size:11px;
            transition:transform .15s ease;
        }

        details[open] > summary .chevron {
            transform:rotate(90deg);
        }

        .level-product {
            font-weight:700;
            color:#26304a;
            background:#fff;
        }

        .level-defect {
            padding-left:40px;
            font-weight:600;
            color:#3f4758;
            background:#fcfcfd;
        }

        .level-path {
            padding-left:66px;
            background:#fff;
        }

        .path {
            display:inline-flex;
            align-items:center;
            border-radius:12px;
            padding:4px 9px;
            font-size:11px;
            font-weight:700;
            background:#eef5ff;
            color:#1565c0;
        }

        .serial-table-wrap {
            padding:0 16px 14px 92px;
            background:#fafcff;
            border-bottom:1px solid #edf0f3;
            overflow-x:auto;
        }

        .serial-table {
            width:100%;
            border-collapse:collapse;
            background:#fff;
            border:1px solid #e4e7ec;
        }

        .serial-table th {
            background:#f7f8fa;
            color:#596174;
            text-align:left;
            font-size:11px;
            font-weight:700;
            padding:9px 10px;
            border-bottom:1px solid #e4e7ec;
            white-space:nowrap;
        }

        .serial-table td {
            padding:9px 10px;
            border-bottom:1px solid #edf0f3;
            font-size:12px;
            white-space:nowrap;
        }

        .serial-table tbody tr:last-child td {
            border-bottom:0;
        }

        .serial-table tbody tr:hover {
            background:#f8fbff;
        }

        .serial {
            color:#1565c0;
            font-weight:600;
        }

        .muted {
            color:#858998;
        }

        .warning-text {
            color:#a15c00;
            font-weight:600;
        }

        .status {
            display:inline-flex;
            align-items:center;
            padding:3px 8px;
            border-radius:11px;
            background:#eef7f0;
            color:#347744;
            font-size:10px;
            font-weight:700;
        }

        .empty {
            padding:70px 20px;
            text-align:center;
            color:#858998;
        }

        .empty strong {
            display:block;
            color:#26304a;
            font-size:16px;
            margin-bottom:6px;
        }

        .inventory-footer {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            padding:12px 16px;
            color:#6b7280;
            font-size:12px;
            border-top:1px solid #e4e7ec;
        }

        @media(max-width:1180px) {

            .filters {
                grid-template-columns:1fr 1fr;
            }

            .inventory-metrics {
                grid-template-columns:1fr 1fr;
            }

            .hierarchy-header,
            .tree-row {
                grid-template-columns:
                    minmax(300px,1fr)
                    70px
                    130px
                    120px
                    120px;
            }
        }

        @media(max-width:760px) {

            .filters,
            .inventory-metrics {
                grid-template-columns:1fr;
            }

            .hierarchy-header {
                display:none;
            }

            .tree-row {
                grid-template-columns:1fr auto;
            }

            .tree-row > div:nth-child(3),
            .tree-row > div:nth-child(4),
            .tree-row > div:nth-child(5) {
                display:none;
            }

            .serial-table-wrap {
                padding-left:16px;
            }
        }

    </style>

</head>

<body>

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="page">

    <div class="page-header">

        <div>

            <h1 class="page-title">
                Recovery Inventory
            </h1>

            <p class="page-description">
                Analyze Recovery inventory from aggregate product groups
                down to serialized devices.
            </p>

        </div>

    </div>


    <div class="inventory-metrics">

        <div class="card metric">

            <div class="metric-label">
                Devices
            </div>

            <div class="metric-value">
                <?= number_format($filteredQty) ?>
            </div>

            <div class="metric-sub">
                <?= $filteredQty === $totalInventory
                    ? 'Current Recovery inventory'
                    : 'Filtered from ' . number_format($totalInventory) ?>
            </div>

        </div>


        <div class="card metric">

            <div class="metric-label">
                Known Cost Basis
            </div>

            <div class="metric-value">
                $<?= number_format($filteredCostBasis, 2) ?>
            </div>

            <div class="metric-sub">

                <?= number_format($knownCostQty) ?>
                devices with cost

                <?php if ($missingCostQty > 0): ?>

                    ·
                    <span class="warning-text">
                        <?= number_format($missingCostQty) ?> unknown
                    </span>

                <?php endif; ?>

            </div>

        </div>


        <div class="card metric">

            <div class="metric-label">
                Estimated Recovery
            </div>

            <div class="metric-value">

                <?php if (
                    $filteredQty > 0 &&
                    $knownEstimateQty === $filteredQty
                ): ?>

                    $<?= number_format($filteredEstimatedRecovery, 2) ?>

                <?php else: ?>

                    —

                <?php endif; ?>

            </div>

            <div class="metric-sub">
                <?= $knownEstimateQty > 0
                    ? number_format($knownEstimateQty) . ' devices estimated'
                    : 'Not yet available' ?>
            </div>

        </div>


        <div class="card metric">

            <div class="metric-label">
                Recovery %
            </div>

            <div class="metric-value">

                <?= $recoveryPercent !== null
                    ? number_format($recoveryPercent, 1) . '%'
                    : '—' ?>

            </div>

            <div class="metric-sub">
                Estimated recovery / cost basis
            </div>

        </div>

    </div>


    <form method="get" class="card filter-card">

        <div class="filters">

            <div class="field">

                <label>
                    Product ID / Serial
                </label>

                <input
                    type="text"
                    name="search"
                    value="<?= h($search) ?>"
                    placeholder="Product ID or serial number"
                >

            </div>


            <div class="field">

                <label>
                    Defect Code
                </label>

                <select name="defect_code">

                    <option value="">
                        All defects
                    </option>

                    <?php foreach ($defects as $defect): ?>

                        <option
                            value="<?= h((string)$defect) ?>"
                            <?= $defectCode === $defect
                                ? 'selected'
                                : '' ?>
                        >
                            <?= h((string)$defect) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="field">

                <label>
                    Recovery Path
                </label>

                <select name="recovery_type">

                    <option value="">
                        All recovery paths
                    </option>

                    <?php foreach ($types as $type): ?>

                        <option
                            value="<?= h($type) ?>"
                            <?= $recoveryType === $type
                                ? 'selected'
                                : '' ?>
                        >
                            <?= h(recoveryLabel($type)) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="field">

                <label>
                    Location
                </label>

                <select name="location">

                    <option value="">
                        All locations
                    </option>

                    <?php foreach ($locations as $loc): ?>

                        <option
                            value="<?= h((string)$loc) ?>"
                            <?= $location === $loc
                                ? 'selected'
                                : '' ?>
                        >
                            <?= h((string)$loc) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="filter-actions">

                <button
                    class="btn btn-primary"
                    type="submit"
                >
                    Search
                </button>

                <a
                    class="btn btn-secondary"
                    href="/pages/inventory.php"
                >
                    Reset
                </a>

            </div>

        </div>

    </form>


    <div class="card inventory-card">

        <div class="inventory-toolbar">

            <div class="inventory-toolbar-title">
                Recovery Detail
            </div>

            <div class="inventory-toolbar-meta">
                Product → Defect → Recovery Path → Serial
            </div>

        </div>


        <?php if (!$devices): ?>

            <div class="empty">

                <strong>
                    No Recovery devices found
                </strong>

                Try changing the current filters.

            </div>

        <?php else: ?>


            <div class="hierarchy-header">

                <div>
                    Inventory Group
                </div>

                <div class="money">
                    Qty
                </div>

                <div class="money">
                    Known Cost
                </div>

                <div class="money">
                    Est. Recovery
                </div>

                <div class="money">
                    Recovery %
                </div>

            </div>


            <?php foreach ($tree as $productId => $defectGroups): ?>

                <?php

                $productDeviceIds = [];
                $productCost = 0.0;
                $productEstimate = 0.0;
                $productHasUnknownEstimate = false;

                foreach ($defectGroups as $pathGroups) {

                    foreach ($pathGroups as $pathDevices) {

                        foreach ($pathDevices as $device) {

                            $id = (int)$device['id'];

                            if (isset($productDeviceIds[$id])) {
                                continue;
                            }

                            $productDeviceIds[$id] = true;

                            if ($device['cost_basis'] !== null) {
                                $productCost +=
                                    (float)$device['cost_basis'];
                            }

                            if ($device['estimated_recovery_value'] !== null) {
                                $productEstimate +=
                                    (float)$device['estimated_recovery_value'];
                            } else {
                                $productHasUnknownEstimate = true;
                            }
                        }
                    }
                }

                $productQty = count($productDeviceIds);

                $productPercent =
                    !$productHasUnknownEstimate &&
                    $productCost > 0
                        ? ($productEstimate / $productCost) * 100
                        : null;

                ?>


                <details class="product-group">

                    <summary class="tree-row level-product">

                        <div class="tree-label">

                            <span class="chevron">
                                ▶
                            </span>

                            <span class="tree-text">

                                <?= $productId === 'UNASSIGNED PRODUCT'
                                    ? '<span class="warning-text">UNASSIGNED PRODUCT</span>'
                                    : h($productId) ?>

                            </span>

                        </div>

                        <div class="money">
                            <?= number_format($productQty) ?>
                        </div>

                        <div class="money">
                            $<?= number_format($productCost, 2) ?>
                        </div>

                        <div class="money">
                            <?= $productHasUnknownEstimate
                                ? '—'
                                : '$' . number_format($productEstimate, 2) ?>
                        </div>

                        <div class="money">
                            <?= $productPercent !== null
                                ? number_format($productPercent, 1) . '%'
                                : '—' ?>
                        </div>

                    </summary>


                    <?php foreach ($defectGroups as $defect => $pathGroups): ?>

                        <?php

                        $defectDeviceIds = [];
                        $defectCost = 0.0;
                        $defectEstimate = 0.0;
                        $defectHasUnknownEstimate = false;

                        foreach ($pathGroups as $pathDevices) {

                            foreach ($pathDevices as $device) {

                                $id = (int)$device['id'];

                                if (isset($defectDeviceIds[$id])) {
                                    continue;
                                }

                                $defectDeviceIds[$id] = true;

                                if ($device['cost_basis'] !== null) {
                                    $defectCost +=
                                        (float)$device['cost_basis'];
                                }

                                if ($device['estimated_recovery_value'] !== null) {
                                    $defectEstimate +=
                                        (float)$device['estimated_recovery_value'];
                                } else {
                                    $defectHasUnknownEstimate = true;
                                }
                            }
                        }

                        $defectQty = count($defectDeviceIds);

                        $defectPercent =
                            !$defectHasUnknownEstimate &&
                            $defectCost > 0
                                ? ($defectEstimate / $defectCost) * 100
                                : null;

                        ?>


                        <details class="defect-group">

                            <summary class="tree-row level-defect">

                                <div class="tree-label">

                                    <span class="chevron">
                                        ▶
                                    </span>

                                    <span class="tree-text">
                                        <?= h($defect) ?>
                                    </span>

                                </div>

                                <div class="money">
                                    <?= number_format($defectQty) ?>
                                </div>

                                <div class="money">
                                    $<?= number_format($defectCost, 2) ?>
                                </div>

                                <div class="money">
                                    <?= $defectHasUnknownEstimate
                                        ? '—'
                                        : '$' . number_format($defectEstimate, 2) ?>
                                </div>

                                <div class="money">
                                    <?= $defectPercent !== null
                                        ? number_format($defectPercent, 1) . '%'
                                        : '—' ?>
                                </div>

                            </summary>


                            <?php foreach ($pathGroups as $path => $pathDevices): ?>

                                <?php

                                $pathQty = count($pathDevices);
                                $pathCost = 0.0;
                                $pathEstimate = 0.0;
                                $pathHasUnknownEstimate = false;

                                foreach ($pathDevices as $device) {

                                    if ($device['cost_basis'] !== null) {
                                        $pathCost +=
                                            (float)$device['cost_basis'];
                                    }

                                    if ($device['estimated_recovery_value'] !== null) {
                                        $pathEstimate +=
                                            (float)$device['estimated_recovery_value'];
                                    } else {
                                        $pathHasUnknownEstimate = true;
                                    }
                                }

                                $pathPercent =
                                    !$pathHasUnknownEstimate &&
                                    $pathCost > 0
                                        ? ($pathEstimate / $pathCost) * 100
                                        : null;

                                ?>


                                <details class="path-group">

                                    <summary class="tree-row level-path">

                                        <div class="tree-label">

                                            <span class="chevron">
                                                ▶
                                            </span>

                                            <span class="path">
                                                <?= h(recoveryLabel($path)) ?>
                                            </span>

                                        </div>

                                        <div class="money">
                                            <?= number_format($pathQty) ?>
                                        </div>

                                        <div class="money">
                                            $<?= number_format($pathCost, 2) ?>
                                        </div>

                                        <div class="money">
                                            <?= $pathHasUnknownEstimate
                                                ? '—'
                                                : '$' . number_format($pathEstimate, 2) ?>
                                        </div>

                                        <div class="money">
                                            <?= $pathPercent !== null
                                                ? number_format($pathPercent, 1) . '%'
                                                : '—' ?>
                                        </div>

                                    </summary>


                                    <div class="serial-table-wrap">

                                        <table class="serial-table">

                                            <thead>

                                                <tr>
                                                    <th>Serial</th>
                                                    <th>ERP Device</th>
                                                    <th>Grade</th>
                                                    <th>Location</th>
                                                    <th>Status</th>
                                                    <th class="money">Cost Basis</th>
                                                    <th class="money">Est. Recovery</th>
                                                    <th>ERP Sync</th>
                                                </tr>

                                            </thead>

                                            <tbody>

                                                <?php foreach ($pathDevices as $device): ?>

                                                    <tr>

                                                        <td>
                                                            <span class="serial">
                                                                <?= h($device['serial_number']) ?>
                                                            </span>
                                                        </td>

                                                        <td>
                                                            <?= h((string)$device['erp_device_id']) ?>
                                                        </td>

                                                        <td>
                                                            <?= $device['grade']
                                                                ? h($device['grade'])
                                                                : '<span class="muted">—</span>' ?>
                                                        </td>

                                                        <td>
                                                            <?= $device['location_name']
                                                                ? h($device['location_name'])
                                                                : '<span class="muted">—</span>' ?>
                                                        </td>

                                                        <td>

                                                            <?php if ($device['recovery_status']): ?>

                                                                <span class="status">
                                                                    <?= h($device['recovery_status']) ?>
                                                                </span>

                                                            <?php else: ?>

                                                                <span class="muted">
                                                                    —
                                                                </span>

                                                            <?php endif; ?>

                                                        </td>

                                                        <td class="money">
                                                            <?= moneyOrDash($device['cost_basis']) ?>
                                                        </td>

                                                        <td class="money">
                                                            <?= moneyOrDash(
                                                                $device['estimated_recovery_value']
                                                            ) ?>
                                                        </td>

                                                        <td>
                                                            <?= $device['erp_synced_at']
                                                                ? h($device['erp_synced_at'])
                                                                : '<span class="muted">—</span>' ?>
                                                        </td>

                                                    </tr>

                                                <?php endforeach; ?>

                                            </tbody>

                                        </table>

                                    </div>

                                </details>

                            <?php endforeach; ?>

                        </details>

                    <?php endforeach; ?>

                </details>

            <?php endforeach; ?>

        <?php endif; ?>


        <div class="inventory-footer">

            <span>
                <?= number_format($filteredQty) ?>
                serialized devices
            </span>

            <span>
                <?= number_format(count($tree)) ?>
                product groups
            </span>

        </div>

    </div>

</main>

</body>
</html>
