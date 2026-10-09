<?php
$currentPage=$currentPage??'dashboard';require_once __DIR__.'/session_ui.php';
$navigation=[['dashboard','/index.php','⌂','Recovery'],['inventory','/pages/inventory.php','▣','Inventory'],['analysis','/pages/analysis-detail.php','▥','Analysis'],['reroute','/pages/reroute-batch.php','⇄','Reroute'],['movement','/pages/movement.php','↪','Scan & Move'],['commerce','/pages/commerce.php','▤','Whatnot'],['disposition','/pages/exit.php','▣','Wholesale'],['harvest','/pages/exit.php?operation=harvest','♻','Harvest'],['intake','/pages/intake.php','＋','Intake'],['defect-library','/pages/defect-library.php','▦','Library'],['integration','/pages/integration.php','⚙','Connection']];
$operatorName=$_SESSION['operator']['name']??'Recovery Operator';
?>
<header class="cti-header"><a class="cti-brand" href="/index.php"><span class="erp-wordmark">CTI <small>ERP</small></span></a>
<nav class="cti-nav" aria-label="Recovery navigation"><?php foreach($navigation as [$key,$url,$icon,$label]):?><a href="<?=htmlspecialchars($url,ENT_QUOTES)?>" class="<?=$currentPage===$key?'active':''?>"><span class="nav-icon" aria-hidden="true"><?=$icon?></span><?=$label?></a><?php endforeach;?></nav>
<form class="header-search" action="/pages/inventory.php" method="get"><input type="search" name="q" placeholder="Search serial…" aria-label="Search recovery inventory"></form>
<div class="cti-user"><div class="cti-avatar"><?=htmlspecialchars(strtoupper(substr($operatorName,0,1)),ENT_QUOTES)?></div><div class="cti-user-name"><strong><?=htmlspecialchars($operatorName,ENT_QUOTES,'UTF-8')?></strong><small>Recovery Team</small></div><form method="post" action="/logout.php"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(recoveryCsrf(),ENT_QUOTES)?>"><button type="submit" aria-label="Sign out" title="Sign out">↪</button></form></div></header>
<script src="/assets/js/reroute-selection.js"></script>
