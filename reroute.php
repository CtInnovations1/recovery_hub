<?php
declare(strict_types=1);
// Keep old bookmarks on the canonical page and shared ERP interface.
$query = http_build_query($_GET);
header('Location: /pages/reroute.php'.($query !== '' ? '?'.$query : ''));
exit;
