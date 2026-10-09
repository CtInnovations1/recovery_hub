<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/request_reroutes.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'POST required.']); exit; }
try {
    $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    $ids = $input['device_ids'] ?? [$input['device_id'] ?? 0];
    if (!is_array($ids)) { throw new InvalidArgumentException('Invalid device selection.'); }
    $rows = requestRecoveryReroutes($pdo,$ids,(string)($input['new_recovery_type']??''),(int)($input['destination_location_id']??0),(string)($input['reason']??''),$_SESSION['operator']);
    echo json_encode(['success'=>true,'reroute'=>$rows[0],'reroutes'=>$rows]);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException || $e instanceof JsonException ? 422 : 500);
    if ($e instanceof PDOException) { error_log($e->getMessage()); }
    echo json_encode(['success'=>false,'message'=>$e instanceof PDOException ? 'Unable to create reroute. Check the integration migration and server log.' : $e->getMessage()]);
}
