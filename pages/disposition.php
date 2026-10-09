<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$currentPage = 'disposition';

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

function routeClass(string $type): string
{
    return match ($type) {
        'REC_WHATNOT'   => 'route-whatnot',
        'REC_WHOLESALE' => 'route-wholesale',
        'REC_REPAIR'    => 'route-repair',
        'REC_HARVEST'   => 'route-harvest',
        default         => 'route-default',
    };
}

$allowedRoutes = [
    'REC_WHATNOT',
    'REC_WHOLESALE',
    'REC_REPAIR',
    'REC_HARVEST',
];

$route = strtoupper(trim((string)($_GET['route'] ?? 'ALL')));

if ($route !== 'ALL' && !in_array($route, $allowedRoutes, true)) {
    $route = 'ALL';
}

$search = trim((string)($_GET['search'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

$where = [];
$params = [];

if ($route !== 'ALL') {
    $where[] = 'd.recovery_type = :route';
    $params['route'] = $route;
} else {
    $where[] = "d.recovery_type IN ('REC_WHATNOT','REC_WHOLESALE','REC_REPAIR','REC_HARVEST')";
}

if ($search !== '') {
    $where[] = '(
        d.serial_number LIKE :search_serial
        OR d.product_id LIKE :search_product
        OR d.product_description LIKE :search_description
        OR d.location_name LIKE :search_location
    )';

    $like = '%' . $search . '%';

    $params['search_serial'] = $like;
    $params['search_product'] = $like;
    $params['search_description'] = $like;
    $params['search_location'] = $like;
}

if ($status !== '') {
    $where[] = 'd.recovery_status = :status';
    $params['status'] = $status;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

$countStmt = $pdo->query("
    SELECT
        recovery_type,
        COUNT(*) AS total
    FROM recovery_devices
    WHERE recovery_type IN (
        'REC_WHATNOT',
        'REC_WHOLESALE',
        'REC_REPAIR',
        'REC_HARVEST'
    )
    GROUP BY recovery_type
");

$routeCounts = [
    'REC_WHATNOT' => 0,
    'REC_WHOLESALE' => 0,
    'REC_REPAIR' => 0,
    'REC_HARVEST' => 0,
];

foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $routeCounts[(string)$row['recovery_type']] = (int)$row['total'];
}

$totalCount = array_sum($routeCounts);

$statusStmt = $pdo->query("
    SELECT DISTINCT recovery_status
    FROM recovery_devices
    WHERE recovery_type IN (
        'REC_WHATNOT',
        'REC_WHOLESALE',
        'REC_REPAIR',
        'REC_HARVEST'
    )
      AND recovery_status IS NOT NULL
      AND recovery_status <> ''
    ORDER BY recovery_status
");

$statuses = $statusStmt->fetchAll(PDO::FETCH_COLUMN);

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
        d.source_type,
        d.recovery_type,
        d.recovery_status,
        d.erp_location_id,
        d.location_name,
        d.cost_basis,
        d.estimated_recovery_value,
        d.entered_recovery_at,
        GROUP_CONCAT(
            DISTINCT CONCAT(
                df.defect_code,
                '||',
                COALESCE(df.defect_description, '')
            )
            ORDER BY df.id
            SEPARATOR '##'
        ) AS defects_raw
    FROM recovery_devices d
    LEFT JOIN recovery_device_defects df
        ON df.recovery_device_id = d.id
    {$whereSql}
    GROUP BY
        d.id,
        d.erp_device_id,
        d.serial_number,
        d.product_id,
        d.product_description,
        d.product_category,
        d.product_type,
        d.grade,
        d.source_type,
        d.recovery_type,
        d.recovery_status,
        d.erp_location_id,
        d.location_name,
        d.cost_basis,
        d.estimated_recovery_value,
        d.entered_recovery_at
    ORDER BY d.id DESC
    LIMIT 500
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($devices as &$device) {
    $device['defects'] = [];

    $raw = (string)($device['defects_raw'] ?? '');

    if ($raw !== '') {
        foreach (explode('##', $raw) as $item) {
            [$code, $description] = array_pad(explode('||', $item, 2), 2, '');

            $device['defects'][] = [
                'code' => trim($code),
                'description' => trim($description),
            ];
        }
    }

    unset($device['defects_raw']);
}
unset($device);


/*
|--------------------------------------------------------------------------
| CSV Export
|--------------------------------------------------------------------------
*/

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filenameRoute = $route === 'ALL'
        ? 'all_routes'
        : strtolower($route);

    $filename = 'recovery_disposition_' .
        $filenameRoute .
        '_' .
        date('Ymd_His') .
        '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );

    $out = fopen('php://output', 'w');

    fputcsv($out, [
        'Recovery Device ID',
        'ERP Device ID',
        'Serial Number',
        'Product ID',
        'Product Description',
        'Category',
        'Product Type',
        'Grade',
        'Recovery Path',
        'Recovery Status',
        'Location',
        'Cost Basis',
        'Estimated Recovery Value',
        'Defect Codes',
        'Defect Descriptions',
        'Entered Recovery At',
    ]);

    foreach ($devices as $device) {
        $codes = [];
        $descriptions = [];

        foreach ($device['defects'] as $defect) {
            if ($defect['code'] !== '') {
                $codes[] = $defect['code'];
            }

            if ($defect['description'] !== '') {
                $descriptions[] = $defect['description'];
            }
        }

        fputcsv($out, [
            $device['id'],
            $device['erp_device_id'],
            $device['serial_number'],
            $device['product_id'],
            $device['product_description'],
            $device['product_category'],
            $device['product_type'],
            $device['grade'],
            $device['recovery_type'],
            $device['recovery_status'],
            $device['location_name'],
            $device['cost_basis'],
            $device['estimated_recovery_value'],
            implode(' | ', $codes),
            implode(' | ', $descriptions),
            $device['entered_recovery_at'],
        ]);
    }

    fclose($out);
    exit;
}

$queryForExport = $_GET;
$queryForExport['export'] = 'csv';

$exportUrl = '/pages/disposition.php?' . http_build_query($queryForExport);

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Recovery Disposition | CTI ERP</title>

    <link
        rel="stylesheet"
        href="/assets/css/erp-theme-v5.css"
    >

    <style>

        .disposition-summary {
            display:grid;
            grid-template-columns:repeat(5, minmax(0,1fr));
            gap:12px;
            margin-bottom:18px;
        }

        .route-card {
            display:block;
            padding:15px;
            border:1px solid #e4e7ec;
            border-radius:7px;
            background:#fff;
            text-decoration:none;
            transition:.15s ease;
        }

        .route-card:hover {
            transform:translateY(-1px);
            box-shadow:0 4px 14px rgba(15,27,61,.06);
        }

        .route-card.active {
            border-color:#1976d2;
            box-shadow:0 0 0 2px rgba(25,118,210,.08);
        }

        .route-card-label {
            color:#72798a;
            font-size:10px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.35px;
        }

        .route-card-count {
            margin-top:6px;
            color:#17203a;
            font-size:25px;
            line-height:1;
            font-weight:800;
        }

        .filters {
            display:flex;
            gap:10px;
            align-items:end;
            flex-wrap:wrap;
            padding:16px;
            margin-bottom:18px;
        }

        .filter-field {
            min-width:180px;
        }

        .filter-field.search {
            flex:1;
            min-width:260px;
        }

        .filter-field label {
            display:block;
            margin-bottom:6px;
            color:#596174;
            font-size:11px;
            font-weight:700;
        }

        .filter-field input,
        .filter-field select {
            width:100%;
            height:40px;
            border:1px solid #d9dde5;
            border-radius:5px;
            padding:0 10px;
            background:#fff;
            color:#26304a;
            outline:none;
        }

        .filter-field input:focus,
        .filter-field select:focus {
            border-color:#1683f8;
            box-shadow:0 0 0 2px rgba(22,131,248,.10);
        }

        .btn {
            min-height:40px;
            border-radius:5px;
            padding:0 15px;
            cursor:pointer;
            border:1px solid transparent;
            font-family:inherit;
            font-size:12px;
            font-weight:700;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .btn-primary {
            background:#1976d2;
            border-color:#1976d2;
            color:#fff;
        }

        .btn-secondary {
            background:#fff;
            border-color:#d9dde5;
            color:#26304a;
        }

        .table-card {
            overflow:hidden;
        }

        .table-toolbar {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            padding:15px 16px;
            border-bottom:1px solid #e4e7ec;
        }

        .table-title {
            color:#0f1b3d;
            font-size:14px;
            font-weight:800;
        }

        .table-meta {
            margin-top:3px;
            color:#858998;
            font-size:11px;
        }

        .table-wrap {
            overflow:auto;
        }

        .disposition-table {
            width:100%;
            border-collapse:collapse;
            min-width:1450px;
        }

        .disposition-table th {
            padding:10px 11px;
            text-align:left;
            background:#f8f9fb;
            border-bottom:1px solid #e4e7ec;
            color:#72798a;
            font-size:10px;
            text-transform:uppercase;
            letter-spacing:.35px;
            white-space:nowrap;
        }

        .disposition-table td {
            padding:11px;
            border-bottom:1px solid #edf0f3;
            color:#26304a;
            font-size:12px;
            vertical-align:top;
        }

        .disposition-table tr:last-child td {
            border-bottom:0;
        }

        .serial {
            font-weight:800;
            color:#0f1b3d;
        }

        .secondary {
            margin-top:3px;
            color:#858998;
            font-size:10px;
            line-height:1.4;
        }

        .route-pill,
        .status-pill {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:24px;
            padding:3px 9px;
            border-radius:999px;
            font-size:10px;
            font-weight:800;
            white-space:nowrap;
        }

        .route-whatnot {
            background:#B9FFCC;
            color:#087A36;
            border:1px solid #72F59A;
        }

        .route-wholesale {
            background:#C7E8FF;
            color:#075A9C;
            border:1px solid #72C8FF;
        }

        .route-repair {
            background:#FFE2A8;
            color:#9A4B00;
            border:1px solid #FFBE55;
        }

        .route-harvest {
            background:#E4CCFF;
            color:#6521A8;
            border:1px solid #C58BFF;
        }

        .route-default {
            background:#f2f4f7;
            color:#596174;
            border:1px solid #d9dde5;
        }

        .status-pill {
            background:#f4f6f8;
            color:#596174;
            border:1px solid #dce1e6;
        }

        .defects {
            display:flex;
            flex-direction:column;
            gap:5px;
            min-width:300px;
        }

        .defect-row {
            padding:6px 8px;
            border-radius:5px;
            background:#fff7ed;
            border:1px solid #fed7aa;
        }

        .defect-code {
            color:#9a4c00;
            font-size:10px;
            font-weight:800;
        }

        .defect-description {
            margin-top:2px;
            color:#805228;
            font-size:10px;
            line-height:1.35;
        }

        .empty {
            padding:45px 20px;
            text-align:center;
            color:#858998;
            font-size:12px;
        }

        @media(max-width:1050px) {
            .disposition-summary {
                grid-template-columns:repeat(2, minmax(0,1fr));
            }
        }

        @media(max-width:600px) {
            .disposition-summary {
                grid-template-columns:1fr;
            }

            .filter-field,
            .filter-field.search {
                width:100%;
                min-width:100%;
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
                Recovery Disposition
            </h1>

            <p class="page-description">
                Operational queues for Whatnot, Wholesale, Repair and Harvest.
            </p>
        </div>

    </div>


    <div class="disposition-summary">

        <a
            href="/pages/disposition.php"
            class="route-card <?= $route === 'ALL' ? 'active' : '' ?>"
        >
            <div class="route-card-label">
                All Active Routes
            </div>

            <div class="route-card-count">
                <?= number_format($totalCount) ?>
            </div>
        </a>

        <?php foreach ($allowedRoutes as $type): ?>

            <a
                href="/pages/disposition.php?route=<?= urlencode($type) ?>"
                class="route-card <?= $route === $type ? 'active' : '' ?>"
            >
                <div class="route-card-label">
                    <?= h(recoveryLabel($type)) ?>
                </div>

                <div class="route-card-count">
                    <?= number_format($routeCounts[$type] ?? 0) ?>
                </div>
            </a>

        <?php endforeach; ?>

    </div>


    <form
        method="get"
        class="card filters"
    >

        <?php if ($route !== 'ALL'): ?>
            <input
                type="hidden"
                name="route"
                value="<?= h($route) ?>"
            >
        <?php endif; ?>

        <div class="filter-field search">

            <label for="search">
                Search
            </label>

            <input
                id="search"
                name="search"
                type="text"
                value="<?= h($search) ?>"
                placeholder="Serial, product, description or location"
            >

        </div>

        <div class="filter-field">

            <label for="status">
                Recovery Status
            </label>

            <select
                id="status"
                name="status"
            >

                <option value="">
                    All statuses
                </option>

                <?php foreach ($statuses as $statusOption): ?>

                    <option
                        value="<?= h((string)$statusOption) ?>"
                        <?= $status === (string)$statusOption ? 'selected' : '' ?>
                    >
                        <?= h((string)$statusOption) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <button
            type="submit"
            class="btn btn-primary"
        >
            Apply
        </button>

        <a
            href="<?= h(
                $route === 'ALL'
                    ? '/pages/disposition.php'
                    : '/pages/disposition.php?route=' . urlencode($route)
            ) ?>"
            class="btn btn-secondary"
        >
            Clear
        </a>

    </form>


    <section class="card table-card">

        <div class="table-toolbar">

            <div>
                <div class="table-title">
                    <?= $route === 'ALL'
                        ? 'All Disposition Queues'
                        : h(recoveryLabel($route) . ' Queue') ?>
                </div>

                <div class="table-meta">
                    <?= number_format(count($devices)) ?>
                    device<?= count($devices) === 1 ? '' : 's' ?> shown
                </div>
            </div>

            <a
                href="<?= h($exportUrl) ?>"
                class="btn btn-secondary"
            >
                Export CSV
            </a>

        </div>


        <div class="table-wrap">

            <?php if (!$devices): ?>

                <div class="empty">
                    No devices match the current filters.
                </div>

            <?php else: ?>

                <table class="disposition-table">

                    <thead>
                        <tr>
                            <th>Route</th>
                            <th>Status</th>
                            <th>Serial</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Grade</th>
                            <th>Defects</th>
                            <th>Location</th>
                            <th>Cost</th>
                            <th>Est. Recovery</th>
                            <th>Entered Recovery</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($devices as $device): ?>

                            <tr>

                                <td>
                                    <span
                                        class="route-pill <?= h(routeClass((string)$device['recovery_type'])) ?>"
                                    >
                                        <?= h(recoveryLabel((string)$device['recovery_type'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="status-pill">
                                        <?= h((string)($device['recovery_status'] ?: '—')) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="serial">
                                        <?= h((string)$device['serial_number']) ?>
                                    </div>

                                    <div class="secondary">
                                        ERP ID:
                                        <?= h(
                                            $device['erp_device_id'] !== null
                                                ? (string)$device['erp_device_id']
                                                : '—'
                                        ) ?>
                                    </div>
                                </td>

                                <td>
                                    <div>
                                        <?= h((string)($device['product_id'] ?: '—')) ?>
                                    </div>

                                    <?php if (!empty($device['product_description'])): ?>
                                        <div class="secondary">
                                            <?= h((string)$device['product_description']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= h((string)($device['product_category'] ?: '—')) ?>

                                    <?php if (!empty($device['product_type'])): ?>
                                        <div class="secondary">
                                            <?= h((string)$device['product_type']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= h((string)($device['grade'] ?: '—')) ?>
                                </td>

                                <td>

                                    <?php if (!$device['defects']): ?>

                                        <span class="secondary">
                                            No defects
                                        </span>

                                    <?php else: ?>

                                        <div class="defects">

                                            <?php foreach ($device['defects'] as $defect): ?>

                                                <div class="defect-row">

                                                    <div class="defect-code">
                                                        <?= h((string)$defect['code']) ?>
                                                    </div>

                                                    <?php if ($defect['description'] !== ''): ?>

                                                        <div class="defect-description">
                                                            <?= h((string)$defect['description']) ?>
                                                        </div>

                                                    <?php endif; ?>

                                                </div>

                                            <?php endforeach; ?>

                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?= h((string)($device['location_name'] ?: '—')) ?>
                                </td>

                                <td>
                                    <?= h(moneyOrDash($device['cost_basis'])) ?>
                                </td>

                                <td>
                                    <?= h(moneyOrDash($device['estimated_recovery_value'])) ?>
                                </td>

                                <td>
                                    <?= h((string)($device['entered_recovery_at'] ?: '—')) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>

    </section>

</main>

</body>
</html>
