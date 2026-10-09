<?php
$currentPage = $currentPage ?? 'dashboard';
?>
<header class="cti-header">

    <a class="cti-brand" href="/index.php">
        <div class="cti-logo">CTI</div>
        <div class="cti-brand-text">CTI ERP</div>
    </a>

    <nav class="cti-nav">
        <a href="/index.php"
           class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            Recovery
        </a>

        <a href="/pages/inventory.php"
           class="<?= $currentPage === 'inventory' ? 'active' : '' ?>">
            Inventory
        </a>
        
        <a href="/pages/analysis.php"
            class="<?= $currentPage === 'analysis' ? 'active' : '' ?>">
            Analysis
        </a>

        <a href="/pages/intake.php"
           class="<?= $currentPage === 'intake' ? 'active' : '' ?>">
            Intake
        </a>

        <a href="/pages/reroute.php"
           class="<?= $currentPage === 'reroute' ? 'active' : '' ?>">
            Reroute
        
    </nav>

    <div class="cti-user">
        <span class="cti-user-name">Recovery Hub</span>
        <div class="cti-avatar">R</div>
    </div>

</header>
