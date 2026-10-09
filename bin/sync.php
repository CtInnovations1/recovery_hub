<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../config/database.php';
require __DIR__.'/../includes/sync_bridge.php';
if (!(int) $pdo->query("SELECT GET_LOCK('recovery_bridge_sync',0)")->fetchColumn()) { exit("Another synchronization is running.\n"); }
try {
    $bridge = new ERPBridge(require __DIR__.'/../config/integration.php');
    echo json_encode(recoverySyncLocations($pdo, $bridge)).PHP_EOL;
    $cursor = 0;
    do {
        $page = recoverySyncDevices($pdo, $bridge, $cursor);
        if ($page['has_more'] && $page['next_after_id'] <= $cursor) { throw new RuntimeException('ERP cursor did not advance.'); }
        $cursor = $page['next_after_id'];
        echo json_encode($page).PHP_EOL;
    } while ($page['has_more']);
    $cursor=0;
    do {
        $page=recoverySyncOutcomes($pdo,$bridge,$cursor);
        if($page['has_more']&&$page['next_after_id']<=$cursor){throw new RuntimeException('Outcome cursor did not advance.');}
        $cursor=$page['next_after_id'];echo json_encode($page).PHP_EOL;
    } while($page['has_more']);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('recovery_bridge_sync')");
}
