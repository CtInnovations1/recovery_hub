<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$currentPage = 'analysis';

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value): string
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

function inventoryUrl(array $params = []): string
{
    return '/pages/inventory.php' .
        ($params ? '?' . http_build_query($params) : '');
}

/*
|--------------------------------------------------------------------------
| Overall Metrics
|--------------------------------------------------------------------------
*/

$overall = $pdo->query("
    SELECT
        COUNT(*) AS qty,
        COUNT(cost_basis) AS known_cost_qty,
        SUM(CASE WHEN cost_basis IS NULL THEN 1 ELSE 0 END) AS missing_cost_qty,
        SUM(cost_basis) AS known_cost,
        AVG(cost_basis) AS avg_known_cost,
        COUNT(DISTINCT NULLIF(product_id, '')) AS product_count,
        SUM(
            CASE
                WHEN product_id IS NULL OR product_id = ''
                THEN 1 ELSE 0
            END
        ) AS missing_product_qty,
        SUM(
            CASE
                WHEN location_name IS NULL OR location_name = ''
                THEN 1 ELSE 0
            END
        ) AS missing_location_qty
    FROM recovery_devices
")->fetch(PDO::FETCH_ASSOC);

$totalQty = (int)($overall['qty'] ?? 0);
$knownCostQty = (int)($overall['known_cost_qty'] ?? 0);
$missingCostQty = (int)($overall['missing_cost_qty'] ?? 0);
$knownCost = (float)($overall['known_cost'] ?? 0);
$avgKnownCost = $overall['avg_known_cost'] !== null
    ? (float)$overall['avg_known_cost']
    : null;

$costCoverage = $totalQty > 0
    ? ($knownCostQty / $totalQty) * 100
    : 0;

/*
|--------------------------------------------------------------------------
| Recovery Path Analysis
|--------------------------------------------------------------------------
*/

$pathRows = $pdo->query("
    SELECT
        recovery_type,
        COUNT(*) AS qty,
        COUNT(cost_basis) AS known_cost_qty,
        SUM(CASE WHEN cost_basis IS NULL THEN 1 ELSE 0 END) AS missing_cost_qty,
        SUM(cost_basis) AS known_cost,
        AVG(cost_basis) AS avg_known_cost,
        SUM(estimated_recovery_value) AS estimated_recovery,
        COUNT(estimated_recovery_value) AS estimate_qty
    FROM recovery_devices
    GROUP BY recovery_type
")->fetchAll(PDO::FETCH_ASSOC);

$pathData = [];

foreach ($pathRows as $row) {
    $pathData[$row['recovery_type']] = $row;
}

$paths = [
    'REC_WHATNOT',
    'REC_WHOLESALE',
    'REC_REPAIR',
    'REC_HARVEST',
];

/*
|--------------------------------------------------------------------------
| Top Products
|--------------------------------------------------------------------------
*/

$productRows = $pdo->query("
    SELECT
        COALESCE(NULLIF(product_id, ''), 'UNASSIGNED PRODUCT') AS product_id,
        MAX(product_description) AS product_description,
        MAX(product_category) AS product_category,
        COUNT(*) AS qty,
        COUNT(cost_basis) AS known_cost_qty,
        SUM(cost_basis) AS known_cost,
        AVG(cost_basis) AS avg_known_cost,
        COUNT(DISTINCT recovery_type) AS path_count
    FROM recovery_devices
    GROUP BY COALESCE(NULLIF(product_id, ''), 'UNASSIGNED PRODUCT')
    ORDER BY qty DESC, product_id
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Top Defects
|--------------------------------------------------------------------------
|
| Financial values are calculated once per defect/device relationship.
| A device may legitimately appear under multiple defect codes.
|
*/

$defectRows = $pdo->query("
    SELECT
        df.defect_code,
        COUNT(DISTINCT d.id) AS qty,
        COUNT(DISTINCT CASE WHEN d.cost_basis IS NOT NULL THEN d.id END)
            AS known_cost_qty,
        SUM(d.cost_basis) AS known_cost,
        COUNT(DISTINCT d.product_id) AS product_count
    FROM recovery_device_defects df
    INNER JOIN recovery_devices d
        ON d.id = df.recovery_device_id
    WHERE df.defect_code IS NOT NULL
      AND df.defect_code <> ''
    GROUP BY df.defect_code
    ORDER BY qty DESC, df.defect_code
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Location Distribution
|--------------------------------------------------------------------------
*/

$locationRows = $pdo->query("
    SELECT
        COALESCE(NULLIF(location_name, ''), 'NO LOCATION') AS location_name,
        MAX(erp_location_id) AS erp_location_id,
        MAX(location_type) AS location_type,
        COUNT(*) AS qty,
        COUNT(cost_basis) AS known_cost_qty,
        SUM(cost_basis) AS known_cost,
        COUNT(DISTINCT recovery_type) AS path_count
    FROM recovery_devices
    GROUP BY COALESCE(NULLIF(location_name, ''), 'NO LOCATION')
    ORDER BY qty DESC, location_name
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Defect Coverage
|--------------------------------------------------------------------------
*/

$devicesWithDefects = (int)$pdo->query("
    SELECT COUNT(DISTINCT recovery_device_id)
    FROM recovery_device_defects
")->fetchColumn();

$defectCoverage = $totalQty > 0
    ? ($devicesWithDefects / $totalQty) * 100
    : 0;

$productCoverageQty =
    $totalQty - (int)$overall['missing_product_qty'];

$productCoverage = $totalQty > 0
    ? ($productCoverageQty / $totalQty) * 100
    : 0;

$locationCoverageQty =
    $totalQty - (int)$overall['missing_location_qty'];

$locationCoverage = $totalQty > 0
    ? ($locationCoverageQty / $totalQty) * 100
    : 0;

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Recovery Analysis | CTI ERP</title>

    <link
        rel="stylesheet"
        href="/assets/css/erp-theme-v5.css"
    >

    <style>

        .analysis-metrics {
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:14px;
            margin-bottom:18px;
        }

        .analysis-grid {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:18px;
            margin-bottom:18px;
        }

        .section-card {
            overflow:hidden;
        }

        .section-header {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            padding:15px 17px;
            border-bottom:1px solid #e4e7ec;
        }

        .section-title {
            color:#0f1b3d;
            font-size:14px;
            font-weight:800;
        }

        .section-meta {
            color:#858998;
            font-size:11px;
        }

        .path-grid {
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:12px;
            padding:16px;
        }

        .path-card {
            display:block;
            min-width:0;
            padding:15px;
            border:1px solid #e4e7ec;
            border-radius:6px;
            background:#fff;
            color:inherit;
            text-decoration:none;
            transition:
                border-color .15s ease,
                box-shadow .15s ease,
                transform .15s ease;
        }

        .path-card:hover {
            border-color:#1683f8;
            box-shadow:0 3px 12px rgba(15,27,61,.08);
            transform:translateY(-1px);
        }

        .path-name {
            color:#1565c0;
            font-size:12px;
            font-weight:800;
        }

        .path-qty {
            margin-top:8px;
            color:#0f1b3d;
            font-size:26px;
            font-weight:800;
            line-height:1;
        }

        .path-label {
            margin-top:4px;
            color:#858998;
            font-size:10px;
        }

        .path-cost {
            margin-top:13px;
            color:#26304a;
            font-size:14px;
            font-weight:700;
        }

        .path-sub {
            margin-top:4px;
            color:#858998;
            font-size:10px;
        }

        .analysis-table-wrap {
            overflow-x:auto;
        }

        .analysis-table {
            width:100%;
            border-collapse:collapse;
        }

        .analysis-table th {
            padding:10px 12px;
            background:#fafbfc;
            border-bottom:1px solid #e4e7ec;
            color:#596174;
            text-align:left;
            white-space:nowrap;
            font-size:10px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.03em;
        }

        .analysis-table td {
            padding:11px 12px;
            border-bottom:1px solid #edf0f3;
            color:#26304a;
            font-size:12px;
            vertical-align:middle;
        }

        .analysis-table tbody tr:last-child td {
            border-bottom:0;
        }

        .analysis-table tbody tr:hover {
            background:#f8fbff;
        }

        .analysis-table .number {
            text-align:right;
            font-variant-numeric:tabular-nums;
        }

        .analysis-link {
            color:#1565c0;
            font-weight:700;
            text-decoration:none;
        }

        .analysis-link:hover {
            text-decoration:underline;
        }

        .product-description {
            display:block;
            max-width:280px;
            margin-top:3px;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
            color:#858998;
            font-size:10px;
        }

        .coverage-list {
            padding:5px 17px 17px;
        }

        .coverage-item {
            padding:13px 0;
            border-bottom:1px solid #edf0f3;
        }

        .coverage-item:last-child {
            border-bottom:0;
        }

        .coverage-top {
            display:flex;
            justify-content:space-between;
            gap:12px;
            margin-bottom:7px;
        }

        .coverage-name {
            color:#26304a;
            font-size:12px;
            font-weight:700;
        }

        .coverage-value {
            color:#596174;
            font-size:11px;
            font-weight:700;
        }

        .coverage-track {
            height:6px;
            overflow:hidden;
            border-radius:999px;
            background:#edf0f3;
        }

        .coverage-bar {
            height:100%;
            border-radius:999px;
            background:#1683f8;
        }

        .coverage-note {
            margin-top:5px;
            color:#858998;
            font-size:10px;
        }

        .warning-value {
            color:#a15c00;
        }

        .analysis-note {
            padding:13px 16px;
            margin-bottom:18px;
            border:1px solid #d8e7fa;
            border-radius:5px;
            background:#f6faff;
            color:#596174;
            font-size:11px;
        }

        .analysis-note strong {
            color:#0f1b3d;
        }

        .empty-value {
            color:#858998;
        }

        @media(max-width:1150px) {

            .path-grid {
                grid-template-columns:1fr 1fr;
            }

            .analysis-metrics {
                grid-template-columns:1fr 1fr;
            }
        }

        @media(max-width:850px) {

            .analysis-grid {
                grid-template-columns:1fr;
            }
        }

        @media(max-width:650px) {

            .analysis-metrics,
            .path-grid {
                grid-template-columns:1fr;
            }
        }

    </style>

</head>

<body class="recovery-app">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="page">

    <div class="page-header">

        <div>

            <h1 class="page-title">
                Recovery Analysis
            </h1>

            <p class="page-description">
                Analyze Recovery inventory by path, product,
                defect and physical location.
            </p>

        </div>

    </div>


    <div class="analysis-note">

        <strong>Current analytical basis:</strong>

        Recovery metrics use synchronized serialized devices.
        Cost metrics include only devices with a known Cost Basis.
        Estimated Recovery is not calculated until recovery estimates
        are available.

    </div>


    <!-- ================================================================
         SUMMARY
         ================================================================ -->

    <div class="analysis-metrics">

        <div class="card metric">

            <div class="metric-label">
                Recovery Devices
            </div>

            <div class="metric-value">
                <?= number_format($totalQty) ?>
            </div>

            <div class="metric-sub">
                Serialized Recovery inventory
            </div>

        </div>


        <div class="card metric">

            <div class="metric-label">
                Known Cost Basis
            </div>

            <div class="metric-value">
                <?= money($knownCost) ?>
            </div>

            <div class="metric-sub">
                <?= number_format($knownCostQty) ?>
                devices with cost
            </div>

        </div>


        <div class="card metric">

            <div class="metric-label">
                Average Known Cost
            </div>

            <div class="metric-value">
                <?= money($avgKnownCost) ?>
            </div>

            <div class="metric-sub">
                Average across known costs
            </div>

        </div>


        <div class="card metric">

            <div class="metric-label">
                Missing Cost Basis
            </div>

            <div class="metric-value warning-value">
                <?= number_format($missingCostQty) ?>
            </div>

            <div class="metric-sub">
                <?= number_format(100 - $costCoverage, 1) ?>%
                of Recovery inventory
            </div>

        </div>

    </div>


    <!-- ================================================================
         RECOVERY PATH
         ================================================================ -->

    <section class="card section-card">

        <div class="section-header">

            <div class="section-title">
                Recovery Inventory by Path
            </div>

            <div class="section-meta">
                Click a path to open filtered Inventory
            </div>

        </div>


        <div class="path-grid">

            <?php foreach ($paths as $path): ?>

                <?php

                $row = $pathData[$path] ?? [
                    'qty' => 0,
                    'known_cost_qty' => 0,
                    'missing_cost_qty' => 0,
                    'known_cost' => null,
                    'avg_known_cost' => null,
                    'estimated_recovery' => null,
                    'estimate_qty' => 0,
                ];

                ?>

                <a
                    class="path-card"
                    href="<?= h(inventoryUrl([
                        'recovery_type' => $path,
                    ])) ?>"
                >

                    <div class="path-name">
                        <?= h(recoveryLabel($path)) ?>
                    </div>

                    <div class="path-qty">
                        <?= number_format((int)$row['qty']) ?>
                    </div>

                    <div class="path-label">
                        devices
                    </div>

                    <div class="path-cost">
                        <?= (int)$row['known_cost_qty'] > 0
                            ? money($row['known_cost'])
                            : '—' ?>
                    </div>

                    <div class="path-sub">

                        Known Cost Basis

                        <?php if ((int)$row['missing_cost_qty'] > 0): ?>

                            ·
                            <?= number_format(
                                (int)$row['missing_cost_qty']
                            ) ?>
                            unknown

                        <?php endif; ?>

                    </div>

                </a>

            <?php endforeach; ?>

        </div>

    </section>


    <div style="height:18px;"></div>


    <!-- ================================================================
         PRODUCTS + DEFECTS
         ================================================================ -->

    <div class="analysis-grid">

        <!-- Products -->

        <section class="card section-card">

            <div class="section-header">

                <div class="section-title">
                    Top Products
                </div>

                <div class="section-meta">
                    By device quantity
                </div>

            </div>


            <div class="analysis-table-wrap">

                <table class="analysis-table">

                    <thead>

                        <tr>
                            <th>Product ID</th>
                            <th class="number">Qty</th>
                            <th class="number">Known Cost</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($productRows as $row): ?>

                            <tr>

                                <td>

                                    <?php if (
                                        $row['product_id']
                                        !== 'UNASSIGNED PRODUCT'
                                    ): ?>

                                        <a
                                            class="analysis-link"
                                            href="<?= h(inventoryUrl([
                                                'search' =>
                                                    $row['product_id'],
                                            ])) ?>"
                                        >
                                            <?= h($row['product_id']) ?>
                                        </a>

                                    <?php else: ?>

                                        <span class="warning-value">
                                            UNASSIGNED PRODUCT
                                        </span>

                                    <?php endif; ?>

                                    <?php if (
                                        $row['product_description']
                                    ): ?>

                                        <span class="product-description">
                                            <?= h(
                                                $row[
                                                    'product_description'
                                                ]
                                            ) ?>
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td class="number">
                                    <?= number_format(
                                        (int)$row['qty']
                                    ) ?>
                                </td>

                                <td class="number">
                                    <?= (int)$row['known_cost_qty'] > 0
                                        ? money($row['known_cost'])
                                        : '—' ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </section>


        <!-- Defects -->

        <section class="card section-card">

            <div class="section-header">

                <div class="section-title">
                    Top Defects
                </div>

                <div class="section-meta">
                    By serialized device count
                </div>

            </div>


            <div class="analysis-table-wrap">

                <table class="analysis-table">

                    <thead>

                        <tr>
                            <th>Defect Code</th>
                            <th class="number">Devices</th>
                            <th class="number">Known Cost</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($defectRows as $row): ?>

                            <tr>

                                <td>

                                    <a
                                        class="analysis-link"
                                        href="<?= h(inventoryUrl([
                                            'defect_code' =>
                                                $row['defect_code'],
                                        ])) ?>"
                                    >
                                        <?= h($row['defect_code']) ?>
                                    </a>

                                </td>

                                <td class="number">
                                    <?= number_format(
                                        (int)$row['qty']
                                    ) ?>
                                </td>

                                <td class="number">
                                    <?= (int)$row['known_cost_qty'] > 0
                                        ? money($row['known_cost'])
                                        : '—' ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </div>


    <!-- ================================================================
         LOCATIONS + DATA COVERAGE
         ================================================================ -->

    <div class="analysis-grid">

        <!-- Locations -->

        <section class="card section-card">

            <div class="section-header">

                <div class="section-title">
                    Inventory by Location
                </div>

                <div class="section-meta">
                    Current synchronized location
                </div>

            </div>


            <div class="analysis-table-wrap">

                <table class="analysis-table">

                    <thead>

                        <tr>
                            <th>Location</th>
                            <th class="number">Qty</th>
                            <th class="number">Known Cost</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($locationRows as $row): ?>

                            <tr>

                                <td>

                                    <?php if (
                                        $row['location_name']
                                        !== 'NO LOCATION'
                                    ): ?>

                                        <a
                                            class="analysis-link"
                                            href="<?= h(inventoryUrl([
                                                'location' =>
                                                    $row['location_name'],
                                            ])) ?>"
                                        >
                                            <?= h(
                                                $row['location_name']
                                            ) ?>
                                        </a>

                                    <?php else: ?>

                                        <span class="warning-value">
                                            NO LOCATION
                                        </span>

                                    <?php endif; ?>

                                    <?php if (
                                        $row['erp_location_id']
                                    ): ?>

                                        <span class="product-description">
                                            ERP Location
                                            #<?= h(
                                                (string)$row[
                                                    'erp_location_id'
                                                ]
                                            ) ?>

                                            <?php if (
                                                $row['location_type']
                                            ): ?>

                                                ·
                                                <?= h(
                                                    $row[
                                                        'location_type'
                                                    ]
                                                ) ?>

                                            <?php endif; ?>

                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td class="number">
                                    <?= number_format(
                                        (int)$row['qty']
                                    ) ?>
                                </td>

                                <td class="number">
                                    <?= (int)$row['known_cost_qty'] > 0
                                        ? money($row['known_cost'])
                                        : '—' ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </section>


        <!-- Coverage -->

        <section class="card section-card">

            <div class="section-header">

                <div class="section-title">
                    Recovery Data Coverage
                </div>

                <div class="section-meta">
                    Current synchronized dataset
                </div>

            </div>


            <div class="coverage-list">

                <div class="coverage-item">

                    <div class="coverage-top">

                        <div class="coverage-name">
                            Cost Basis
                        </div>

                        <div class="coverage-value">
                            <?= number_format($knownCostQty) ?>
                            /
                            <?= number_format($totalQty) ?>
                        </div>

                    </div>

                    <div class="coverage-track">

                        <div
                            class="coverage-bar"
                            style="width:<?= min(
                                100,
                                max(0, $costCoverage)
                            ) ?>%;"
                        ></div>

                    </div>

                    <div class="coverage-note">
                        <?= number_format($costCoverage, 1) ?>%
                        coverage ·
                        <?= number_format($missingCostQty) ?>
                        missing
                    </div>

                </div>


                <div class="coverage-item">

                    <div class="coverage-top">

                        <div class="coverage-name">
                            Product ID
                        </div>

                        <div class="coverage-value">
                            <?= number_format($productCoverageQty) ?>
                            /
                            <?= number_format($totalQty) ?>
                        </div>

                    </div>

                    <div class="coverage-track">

                        <div
                            class="coverage-bar"
                            style="width:<?= min(
                                100,
                                max(0, $productCoverage)
                            ) ?>%;"
                        ></div>

                    </div>

                    <div class="coverage-note">
                        <?= number_format($productCoverage, 1) ?>%
                        coverage ·
                        <?= number_format(
                            (int)$overall['missing_product_qty']
                        ) ?>
                        missing
                    </div>

                </div>


                <div class="coverage-item">

                    <div class="coverage-top">

                        <div class="coverage-name">
                            Defect Codes
                        </div>

                        <div class="coverage-value">
                            <?= number_format($devicesWithDefects) ?>
                            /
                            <?= number_format($totalQty) ?>
                        </div>

                    </div>

                    <div class="coverage-track">

                        <div
                            class="coverage-bar"
                            style="width:<?= min(
                                100,
                                max(0, $defectCoverage)
                            ) ?>%;"
                        ></div>

                    </div>

                    <div class="coverage-note">
                        <?= number_format($defectCoverage, 1) ?>%
                        of devices have at least one defect
                    </div>

                </div>


                <div class="coverage-item">

                    <div class="coverage-top">

                        <div class="coverage-name">
                            Current Location
                        </div>

                        <div class="coverage-value">
                            <?= number_format($locationCoverageQty) ?>
                            /
                            <?= number_format($totalQty) ?>
                        </div>

                    </div>

                    <div class="coverage-track">

                        <div
                            class="coverage-bar"
                            style="width:<?= min(
                                100,
                                max(0, $locationCoverage)
                            ) ?>%;"
                        ></div>

                    </div>

                    <div class="coverage-note">
                        <?= number_format($locationCoverage, 1) ?>%
                        coverage ·
                        <?= number_format(
                            (int)$overall['missing_location_qty']
                        ) ?>
                        missing
                    </div>

                </div>

            </div>

        </section>

    </div>

</main>

</body>
</html>
