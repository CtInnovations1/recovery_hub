<?php
declare(strict_types=1);

$currentPage = 'dashboard';

require __DIR__ . '/config/database.php';

function money(?float $value): string
{
    return $value === null ? '—' : '$' . number_format($value, 2);
}

function pathLabel(string $path): string
{
    return match ($path) {
        'REC_WHATNOT'   => 'Whatnot',
        'REC_WHOLESALE' => 'Wholesale',
        'REC_REPAIR'    => 'Repair',
        'REC_HARVEST'   => 'Harvest',
        default         => $path,
    };
}

function pathClass(string $path): string
{
    return match ($path) {
        'REC_WHATNOT'   => 'whatnot',
        'REC_WHOLESALE' => 'wholesale',
        'REC_REPAIR'    => 'repair',
        'REC_HARVEST'   => 'harvest',
        default         => 'default',
    };
}

$paths = [
    'REC_WHATNOT',
    'REC_WHOLESALE',
    'REC_REPAIR',
    'REC_HARVEST',
];

/*
|--------------------------------------------------------------------------
| Overall metrics
|--------------------------------------------------------------------------
*/

$summarySql = "
    SELECT
        COUNT(*) AS total_devices,
        SUM(CASE WHEN cost_basis IS NOT NULL THEN 1 ELSE 0 END) AS devices_with_cost,
        SUM(CASE WHEN cost_basis IS NULL THEN 1 ELSE 0 END) AS devices_missing_cost,
        SUM(cost_basis) AS known_cost_basis,
        SUM(estimated_recovery_value) AS estimated_recovery_value,
        SUM(CASE WHEN estimated_recovery_value IS NOT NULL THEN 1 ELSE 0 END) AS devices_with_estimate
    FROM recovery_devices
";

$summary = $pdo->query($summarySql)->fetch(PDO::FETCH_ASSOC) ?: [];

$totalDevices       = (int)($summary['total_devices'] ?? 0);
$devicesWithCost    = (int)($summary['devices_with_cost'] ?? 0);
$missingCost        = (int)($summary['devices_missing_cost'] ?? 0);
$knownCostBasis     = isset($summary['known_cost_basis']) ? (float)$summary['known_cost_basis'] : null;
$estimatedRecovery  = isset($summary['estimated_recovery_value']) ? (float)$summary['estimated_recovery_value'] : null;
$devicesWithEstimate = (int)($summary['devices_with_estimate'] ?? 0);

$recoveryRate = null;

if (
    $estimatedRecovery !== null &&
    $knownCostBasis !== null &&
    $knownCostBasis > 0 &&
    $devicesWithEstimate > 0
) {
    $recoveryRate = ($estimatedRecovery / $knownCostBasis) * 100;
}

/*
|--------------------------------------------------------------------------
| Recovery Path metrics
|--------------------------------------------------------------------------
*/

$pathSql = "
    SELECT
        recovery_type,
        COUNT(*) AS devices,
        SUM(CASE WHEN cost_basis IS NOT NULL THEN 1 ELSE 0 END) AS devices_with_cost,
        SUM(CASE WHEN cost_basis IS NULL THEN 1 ELSE 0 END) AS missing_cost,
        SUM(cost_basis) AS cost_basis,
        SUM(estimated_recovery_value) AS estimated_recovery,
        SUM(CASE WHEN estimated_recovery_value IS NOT NULL THEN 1 ELSE 0 END) AS devices_with_estimate
    FROM recovery_devices
    WHERE recovery_type IN (
        'REC_WHATNOT',
        'REC_WHOLESALE',
        'REC_REPAIR',
        'REC_HARVEST'
    )
    GROUP BY recovery_type
";

$pathRows = [];

foreach ($pdo->query($pathSql, PDO::FETCH_ASSOC) as $row) {
    $pathRows[$row['recovery_type']] = $row;
}

foreach ($paths as $path) {
    if (!isset($pathRows[$path])) {
        $pathRows[$path] = [
            'recovery_type' => $path,
            'devices' => 0,
            'devices_with_cost' => 0,
            'missing_cost' => 0,
            'cost_basis' => null,
            'estimated_recovery' => null,
            'devices_with_estimate' => 0,
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Location mapping
|--------------------------------------------------------------------------
*/

$locationSql = "
    SELECT
        recovery_type,
        erp_location_id,
        location_name,
        location_type
    FROM recovery_location_map
    WHERE is_active = 1
";

$locations = [];

foreach ($pdo->query($locationSql, PDO::FETCH_ASSOC) as $row) {
    $locations[$row['recovery_type']] = $row;
}

/*
|--------------------------------------------------------------------------
| Defect metrics
|--------------------------------------------------------------------------
*/

$defectSummarySql = "
    SELECT
        COUNT(DISTINCT recovery_device_id) AS devices_with_defects,
        COUNT(*) AS defect_records,
        COUNT(DISTINCT defect_code) AS unique_defects
    FROM recovery_device_defects
";

$defectSummary = $pdo->query($defectSummarySql)->fetch(PDO::FETCH_ASSOC) ?: [];

$topDefectsSql = "
    SELECT
        d.defect_code,
        COUNT(DISTINCT d.recovery_device_id) AS devices
    FROM recovery_device_defects d
    GROUP BY d.defect_code
    ORDER BY devices DESC, d.defect_code ASC
    LIMIT 8
";

$topDefects = $pdo->query($topDefectsSql)->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Product / data quality metrics
|--------------------------------------------------------------------------
*/

$dataQualitySql = "
    SELECT
        SUM(CASE WHEN product_id IS NULL OR TRIM(product_id) = '' THEN 1 ELSE 0 END) AS missing_product,
        SUM(CASE WHEN erp_location_id IS NULL THEN 1 ELSE 0 END) AS missing_location,
        COUNT(DISTINCT NULLIF(TRIM(product_id), '')) AS unique_products
    FROM recovery_devices
";

$dataQuality = $pdo->query($dataQualitySql)->fetch(PDO::FETCH_ASSOC) ?: [];

$maxPathDevices = 1;

foreach ($paths as $path) {
    $maxPathDevices = max($maxPathDevices, (int)$pathRows[$path]['devices']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Recovery Hub | CTI ERP</title>

    <link rel="stylesheet" href="/assets/css/erp-theme-v5.css">

    <style>
        .dashboard-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-primary,
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 14px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
        }

        .btn-primary {
            background: var(--cti-blue);
            color: #fff;
            border: 1px solid var(--cti-blue);
        }

        .btn-secondary {
            background: #fff;
            color: var(--cti-navy);
            border: 1px solid var(--cti-border);
        }

        .dashboard-grid {
            margin-bottom: 16px;
        }

        .metric {
            min-height: 128px;
        }

        .metric-value {
            line-height: 1.05;
        }

        .metric-warning {
            color: #b45309;
        }

        .path-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-top: 16px;
        }

        .path-card {
            position: relative;
            overflow: hidden;
            padding: 18px;
            text-decoration: none;
            color: inherit;
            transition: transform .12s ease, box-shadow .12s ease;
        }

        .path-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 14px rgba(15, 27, 61, .08);
        }

        .path-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: #94a3b8;
        }

        .path-card.whatnot::before {
            background: #1683f8;
        }

        .path-card.wholesale::before {
            background: #6366f1;
        }

        .path-card.repair::before {
            background: #f59e0b;
        }

        .path-card.harvest::before {
            background: #64748b;
        }

        .path-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .path-name {
            color: var(--cti-navy);
            font-size: 15px;
            font-weight: 800;
        }

        .path-code {
            margin-top: 3px;
            color: var(--cti-muted);
            font-size: 11px;
        }

        .path-count {
            color: var(--cti-navy);
            font-size: 30px;
            line-height: 1;
            font-weight: 800;
        }

        .path-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            padding-top: 16px;
            margin-top: 16px;
            border-top: 1px solid #edf0f4;
        }

        .path-meta-label {
            color: var(--cti-muted);
            font-size: 11px;
            margin-bottom: 4px;
        }

        .path-meta-value {
            color: var(--cti-text);
            font-size: 13px;
            font-weight: 700;
        }

        .dashboard-columns {
            display: grid;
            grid-template-columns: minmax(0, 1.6fr) minmax(320px, .8fr);
            gap: 16px;
            margin-top: 16px;
        }

        .section-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }

        .section-header h2 {
            margin-bottom: 3px;
        }

        .section-subtitle {
            color: var(--cti-muted);
            font-size: 12px;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .dashboard-table {
            width: 100%;
            border-collapse: collapse;
        }

        .dashboard-table th {
            text-align: left;
            padding: 10px 12px;
            color: #667085;
            background: #f8fafc;
            border-top: 1px solid var(--cti-border);
            border-bottom: 1px solid var(--cti-border);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .03em;
            white-space: nowrap;
        }

        .dashboard-table td {
            padding: 12px;
            border-bottom: 1px solid #edf0f4;
            vertical-align: middle;
        }

        .dashboard-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .path-pill {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 0 9px;
            border-radius: 999px;
            background: #eef4ff;
            color: #1d4ed8;
            font-size: 11px;
            font-weight: 800;
        }

        .bar-track {
            width: 150px;
            height: 7px;
            overflow: hidden;
            border-radius: 999px;
            background: #edf0f4;
        }

        .bar-fill {
            height: 100%;
            border-radius: inherit;
            background: var(--cti-blue);
        }

        .muted {
            color: var(--cti-muted);
        }

        .warning-text {
            color: #b45309;
            font-weight: 700;
        }

        .defect-list {
            display: flex;
            flex-direction: column;
        }

        .defect-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 10px 0;
            border-bottom: 1px solid #edf0f4;
        }

        .defect-row:last-child {
            border-bottom: 0;
        }

        .defect-code {
            color: var(--cti-text);
            font-weight: 600;
            word-break: break-word;
        }

        .defect-count {
            color: var(--cti-navy);
            font-weight: 800;
            white-space: nowrap;
        }

        .quality-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 16px;
        }

        .quality-box {
            padding: 14px;
            background: #f8fafc;
            border: 1px solid #edf0f4;
            border-radius: 5px;
        }

        .quality-value {
            color: var(--cti-navy);
            font-size: 20px;
            font-weight: 800;
        }

        .quality-label {
            color: var(--cti-muted);
            font-size: 11px;
            margin-top: 3px;
        }

        .location-name {
            color: var(--cti-text);
            font-weight: 700;
        }

        .location-id {
            color: var(--cti-muted);
            font-size: 11px;
            margin-top: 2px;
        }

        @media (max-width: 1180px) {
            .path-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .dashboard-columns {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .page-header {
                flex-direction: column;
            }

            .path-grid {
                grid-template-columns: 1fr;
            }

            .quality-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body class="recovery-app">

<?php require __DIR__ . '/includes/header.php'; ?>

<main class="page">

    <div class="page-header">
        <div>
            <h1 class="page-title">Recovery Hub</h1>
            <p class="page-description">
                Recovery inventory, economics and disposition workspace
            </p>
        </div>

        <div class="dashboard-actions">
            <a href="/pages/inventory.php" class="btn-primary">
                View Recovery Inventory
            </a>

            <a href="/pages/reroute.php" class="btn-secondary">
                Reroute
            </a>
        </div>
    </div>

    <div class="dashboard-grid">

        <div class="card metric">
            <div class="metric-label">Open Recovery Inventory</div>
            <div class="metric-value"><?= number_format($totalDevices) ?></div>
            <div class="metric-sub">
                Serialized devices currently in Recovery
            </div>
        </div>

        <div class="card metric">
            <div class="metric-label">Known Cost Basis</div>
            <div class="metric-value"><?= money($knownCostBasis) ?></div>
            <div class="metric-sub">
                <?= number_format($devicesWithCost) ?> devices with known cost
            </div>
        </div>

        <div class="card metric">
            <div class="metric-label">Estimated Recovery</div>
            <div class="metric-value">
                <?= money($estimatedRecovery) ?>
            </div>
            <div class="metric-sub">
                <?php if ($devicesWithEstimate > 0): ?>
                    <?= number_format($devicesWithEstimate) ?> devices with estimate
                <?php else: ?>
                    Recovery estimates not loaded yet
                <?php endif; ?>
            </div>
        </div>

        <div class="card metric">
            <div class="metric-label">Missing Cost Basis</div>
            <div class="metric-value metric-warning">
                <?= number_format($missingCost) ?>
            </div>
            <div class="metric-sub">
                Devices requiring cost data
            </div>
        </div>

    </div>

    <div class="path-grid">

        <?php foreach ($paths as $path): ?>
            <?php
            $row = $pathRows[$path];

            $count = (int)$row['devices'];
            $cost = $row['cost_basis'] !== null
                ? (float)$row['cost_basis']
                : null;

            $missing = (int)$row['missing_cost'];

            $location = $locations[$path] ?? null;
            ?>

            <a
                class="card path-card <?= htmlspecialchars(pathClass($path)) ?>"
                href="/pages/inventory.php?recovery_type=<?= urlencode($path) ?>"
            >
                <div class="path-card-top">
                    <div>
                        <div class="path-name">
                            <?= htmlspecialchars(pathLabel($path)) ?>
                        </div>

                        <div class="path-code">
                            <?= htmlspecialchars($path) ?>
                        </div>
                    </div>

                    <div class="path-count">
                        <?= number_format($count) ?>
                    </div>
                </div>

                <div class="path-meta">

                    <div>
                        <div class="path-meta-label">Known Cost</div>
                        <div class="path-meta-value">
                            <?= money($cost) ?>
                        </div>
                    </div>

                    <div>
                        <div class="path-meta-label">Missing Cost</div>
                        <div class="path-meta-value">
                            <?= number_format($missing) ?>
                        </div>
                    </div>

                    <div style="grid-column: 1 / -1;">
                        <div class="path-meta-label">Recovery Location</div>

                        <div class="path-meta-value">
                            <?php if ($location): ?>
                                <?= htmlspecialchars($location['location_name']) ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </a>

        <?php endforeach; ?>

    </div>

    <div class="dashboard-columns">

        <section class="card section">

            <div class="section-header">
                <div>
                    <h2>Recovery Inventory by Path</h2>
                    <div class="section-subtitle">
                        Current serialized inventory and known economics
                    </div>
                </div>
            </div>

            <div class="table-wrap">

                <table class="dashboard-table">

                    <thead>
                    <tr>
                        <th>Recovery Path</th>
                        <th>Devices</th>
                        <th>Distribution</th>
                        <th>Known Cost Basis</th>
                        <th>Missing Cost</th>
                        <th>Destination</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($paths as $path): ?>
                        <?php
                        $row = $pathRows[$path];
                        $count = (int)$row['devices'];

                        $width = $maxPathDevices > 0
                            ? ($count / $maxPathDevices) * 100
                            : 0;

                        $location = $locations[$path] ?? null;
                        ?>

                        <tr>

                            <td>
                                <span class="path-pill route-<?= htmlspecialchars(pathClass($path)) ?>">
                                    <?= htmlspecialchars(pathLabel($path)) ?>
                                </span>
                            </td>

                            <td>
                                <strong><?= number_format($count) ?></strong>
                            </td>

                            <td>
                                <div class="bar-track">
                                    <div
                                        class="bar-fill"
                                        style="width: <?= number_format($width, 2, '.', '') ?>%;"
                                    ></div>
                                </div>
                            </td>

                            <td>
                                <?= money(
                                    $row['cost_basis'] !== null
                                        ? (float)$row['cost_basis']
                                        : null
                                ) ?>
                            </td>

                            <td>
                                <?php if ((int)$row['missing_cost'] > 0): ?>
                                    <span class="warning-text">
                                        <?= number_format((int)$row['missing_cost']) ?>
                                    </span>
                                <?php else: ?>
                                    0
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($location): ?>
                                    <div class="location-name">
                                        <?= htmlspecialchars($location['location_name']) ?>
                                    </div>

                                    <div class="location-id">
                                        ERP Location #<?= (int)$location['erp_location_id'] ?>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">Not mapped</span>
                                <?php endif; ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </section>

        <section class="card section">

            <div class="section-header">
                <div>
                    <h2>Top Defects</h2>
                    <div class="section-subtitle">
                        Devices by defect code
                    </div>
                </div>
            </div>

            <div class="defect-list">

                <?php if (!$topDefects): ?>

                    <div class="muted">
                        No defect information available.
                    </div>

                <?php else: ?>

                    <?php foreach ($topDefects as $defect): ?>

                        <div class="defect-row">
                            <div class="defect-code">
                                <?= htmlspecialchars($defect['defect_code']) ?>
                            </div>

                            <div class="defect-count">
                                <?= number_format((int)$defect['devices']) ?>
                            </div>
                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </section>

    </div>

    <section class="card section">

        <div class="section-header">
            <div>
                <h2>Recovery Data Coverage</h2>
                <div class="section-subtitle">
                    Current ERP synchronization and data quality
                </div>
            </div>
        </div>

        <div class="quality-grid">

            <div class="quality-box">
                <div class="quality-value">
                    <?= number_format((int)($dataQuality['unique_products'] ?? 0)) ?>
                </div>
                <div class="quality-label">
                    Unique Product IDs
                </div>
            </div>

            <div class="quality-box">
                <div class="quality-value">
                    <?= number_format((int)($defectSummary['unique_defects'] ?? 0)) ?>
                </div>
                <div class="quality-label">
                    Unique Defect Codes
                </div>
            </div>

            <div class="quality-box">
                <div class="quality-value">
                    <?= number_format((int)($defectSummary['defect_records'] ?? 0)) ?>
                </div>
                <div class="quality-label">
                    Defect Records
                </div>
            </div>

            <div class="quality-box">
                <div class="quality-value">
                    <?= number_format((int)($defectSummary['devices_with_defects'] ?? 0)) ?>
                </div>
                <div class="quality-label">
                    Devices With Defects
                </div>
            </div>

            <div class="quality-box">
                <div class="quality-value <?= ((int)($dataQuality['missing_product'] ?? 0) > 0) ? 'warning-text' : '' ?>">
                    <?= number_format((int)($dataQuality['missing_product'] ?? 0)) ?>
                </div>
                <div class="quality-label">
                    Missing Product ID
                </div>
            </div>

            <div class="quality-box">
                <div class="quality-value <?= ((int)($dataQuality['missing_location'] ?? 0) > 0) ? 'warning-text' : '' ?>">
                    <?= number_format((int)($dataQuality['missing_location'] ?? 0)) ?>
                </div>
                <div class="quality-label">
                    Missing ERP Location
                </div>
            </div>

        </div>

    </section>

</main>

</body>
</html>
PHP