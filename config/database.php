<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__.'/../includes/auth.php';
    requireRecoveryOperator();
}

require __DIR__.'/database_connection.php';
