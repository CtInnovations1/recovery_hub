<?php
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/erp_bridge.php';
require_once __DIR__.'/../../includes/outcomes.php';
if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);exit;}
try {
    $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
    $operation=(string)($input['operation']??'');
    if(!in_array($operation,['harvest','wholesale','sales-handoff'],true)){throw new InvalidArgumentException('Select a supported operation.');}
    $serial=strtoupper(trim((string)($input['serial_number']??'')));
    $config=require __DIR__.'/../../config/integration.php';
    $pdo->beginTransaction();
    $stmt=$pdo->prepare('SELECT * FROM recovery_devices WHERE serial_number=? FOR UPDATE');$stmt->execute([$serial]);$device=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$device||!$device['erp_device_id']){throw new InvalidArgumentException('Scan an ERP-linked Recovery serial.');}
    $previous=$pdo->prepare('SELECT * FROM recovery_exit_requests WHERE recovery_device_id=? AND operation=?');$previous->execute([$device['id'],$operation]);$saved=$previous->fetch(PDO::FETCH_ASSOC);
    if($saved){$payload=json_decode($saved['payload'],true,512,JSON_THROW_ON_ERROR);}
    else {
        $pending=$pdo->prepare("SELECT id FROM recovery_reroutes WHERE recovery_device_id=? AND status='PENDING' LIMIT 1");$pending->execute([$device['id']]);
        if($pending->fetchColumn()||$device['outcome_at']!==null){throw new InvalidArgumentException('Resolve pending movements before disposition; completed devices cannot exit again.');}
        $payload=['request_key'=>$config['instance_id'].':'.bin2hex(random_bytes(16)),'device_id'=>(int)$device['erp_device_id'],'serial_number'=>$serial];
        if($operation==='harvest'){$payload+=['location_scan'=>strtoupper(trim((string)($input['location_scan']??''))),'final_disposition'=>trim((string)($input['final_disposition']??''))];}
        else {$payload['sales_order_id']=(int)($input['sales_order_id']??0);}
        if($operation==='wholesale'){
            if(!isset($input['direct_costs']) || !preg_match('/^\d{1,9}(\.\d{1,2})?$/',(string)$input['direct_costs']) || ($input['currency']??'')!=='USD'){throw new InvalidArgumentException('Enter confirmed direct costs (zero if none), in USD.');}
            $payload['direct_costs']=number_format((float)$input['direct_costs'],2,'.','');$payload['currency']='USD';
        }
        $pdo->prepare('INSERT INTO recovery_exit_requests(request_key,recovery_device_id,operation,payload) VALUES (?,?,?,?)')->execute([$payload['request_key'],$device['id'],$operation,json_encode($payload,JSON_THROW_ON_ERROR)]);
    }
    // Requests are frozen once sent; reattempting cannot change the physical/commercial intent.
    if($operation==='wholesale' && ($payload['currency']!==($input['currency']??'') || $payload['direct_costs']!==number_format((float)($input['direct_costs']??-1),2,'.',''))){throw new InvalidArgumentException('Retry with the same direct costs and currency.');}
    foreach($operation==='harvest'?['location_scan','final_disposition']:['sales_order_id'] as $field){$value=$field==='location_scan'?strtoupper(trim((string)($input[$field]??''))):trim((string)($input[$field]??''));if((string)$payload[$field]!==$value){throw new InvalidArgumentException('Retry with the same values as the existing disposition request.');}}
    $pdo->commit();
    $receipt=(new ERPBridge($config))->request('POST','/disposition/'.$operation,$payload,$_SESSION['erp_token']);
    if(($receipt['request_key']??'')!==$payload['request_key']||(int)($receipt['device_id']??0)!==$payload['device_id']){throw new RuntimeException('ERP receipt does not match this disposition.');}
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE recovery_exit_requests SET receipt=? WHERE request_key=?')->execute([json_encode($receipt,JSON_THROW_ON_ERROR),$payload['request_key']]);
    if($operation!=='sales-handoff'){storeRecoveryOutcome($pdo,$receipt);}
    $pdo->commit();echo json_encode($receipt);
} catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    if($e instanceof ERPBridgeException && $e->httpStatus===422 && isset($payload['request_key'])){
        $pdo->prepare('DELETE FROM recovery_exit_requests WHERE request_key=? AND receipt IS NULL')->execute([$payload['request_key']]);
    }
    http_response_code($e instanceof InvalidArgumentException?422:502);
    if($e instanceof PDOException)error_log($e->getMessage());
    echo json_encode(['success'=>false,'message'=>$e instanceof PDOException?'Unable to save disposition. Check the schema and retry the same request.':$e->getMessage()]);
}
