<?php
declare(strict_types=1);

$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Recovery Hub | CTI ERP</title>

    <link rel="stylesheet" href="/assets/css/erp-theme.css">
</head>

<body>

<?php require __DIR__ . '/includes/header.php'; ?>

<main class="page">

    <div class="page-header">
        <div>
            <h1 class="page-title">Recovery Hub</h1>
            <p class="page-description">
                Recovery inventory management and disposition workspace
            </p>
        </div>
    </div>

    <div class="dashboard-grid">

        <div class="card metric">
            <div class="metric-label">Open Recovery Inventory</div>
            <div class="metric-value">—</div>
            <div class="metric-sub">Serialized devices</div>
        </div>

        <div class="card metric">
            <div class="metric-label">Cost Basis</div>
            <div class="metric-value">—</div>
            <div class="metric-sub">Open inventory</div>
        </div>

        <div class="card metric">
            <div class="metric-label">Estimated Recovery</div>
            <div class="metric-value">—</div>
            <div class="metric-sub">Expected recovery value</div>
        </div>

        <div class="card metric">
            <div class="metric-label">Recovery Rate</div>
            <div class="metric-value">—</div>
            <div class="metric-sub">Recovery value / cost basis</div>
        </div>

    </div>

    <section class="card section">
        <h2>Recovery Inventory</h2>
        <p>
            Recovery Hub V4 is ready for ERP inventory integration.
        </p>
    </section>

</main>

</body>
</html>
