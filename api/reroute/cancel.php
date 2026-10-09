<?php
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__.'/../../config/database.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
try{
    $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
    $stmt=$pdo->prepare("UPDATE recovery_reroutes SET status='CANCELLED',last_error=? WHERE id=? AND status='PENDING' AND confirmation_payload IS NULL AND erp_receipt IS NULL");
    $stmt->execute(['Cancelled by '.$_SESSION['operator']['name'],(int)($input['id']??0)]);
    if(!$stmt->rowCount()){throw new RuntimeException('Only unsent requests can be cancelled. If confirmation was sent, retry the same scans to recover the ERP receipt.');}
    echo json_encode(['success'=>true]);
}catch(Throwable $e){http_response_code(409);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
