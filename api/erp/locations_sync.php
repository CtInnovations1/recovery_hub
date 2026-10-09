<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
requireRecoveryOperator();
header('Content-Type: application/json');
http_response_code(410);
echo json_encode(['success'=>false,'message'=>'Use ERP connection > Synchronize. The legacy synchronization endpoint has been retired.','url'=>'/pages/integration.php']);
