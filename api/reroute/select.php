<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
requireRecoveryOperator();
header('Content-Type: application/json');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
$input=json_decode(file_get_contents('php://input'),true);
$ids=$input['device_ids']??null;
if(!is_array($ids)||!$ids||count($ids)>10000){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Select between 1 and 10000 serialized devices.']);exit;}
$_SESSION['reroute_selection']=array_values(array_unique(array_filter(array_map('intval',$ids),fn($id)=>$id>0)));
echo json_encode(['success'=>true]);
