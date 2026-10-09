<?php
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/commerce_adapter.php';
require_once __DIR__.'/../../includes/erp_bridge.php';
require_once __DIR__.'/../../includes/outcomes.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
$lock=null;
try {
    $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
    $action=$input['action']??'';
    if(!in_array($action,['export','simulate-paid','scan-out'],true)){throw new InvalidArgumentException('Choose a commerce action.');}
    $serial=strtoupper(trim((string)($input['serial_number']??'')));
    $find=$pdo->prepare('SELECT * FROM recovery_devices WHERE serial_number=?');$find->execute([$serial]);$device=$find->fetch(PDO::FETCH_ASSOC);
    if(!$device || !$device['erp_device_id']){throw new InvalidArgumentException('Scan an ERP-linked serial.');}
    $lock='recovery-commerce-'.$device['id'];$stmt=$pdo->prepare('SELECT GET_LOCK(?,0)');$stmt->execute([$lock]);
    if((int)$stmt->fetchColumn()!==1){$lock=null;throw new RuntimeException('Another commerce action is processing this serial. Retry shortly.');}
    $config=require __DIR__.'/../../config/commerce.php';$integration=require __DIR__.'/../../config/integration.php';
    $provider=$config['local']?'local':'shopify';$adapter=recoveryCommerceAdapter($pdo,$config);$bridge=new ERPBridge($integration);
    $stmt=$pdo->prepare('SELECT * FROM recovery_commerce_listings WHERE recovery_device_id=? AND provider=?');$stmt->execute([$device['id'],$provider]);$listing=$stmt->fetch(PDO::FETCH_ASSOC);
    if($listing){$identity=json_decode($listing['payload'],true,512,JSON_THROW_ON_ERROR);if(($identity['shop']??'')!==($config['local']?'local':$config['shop'])){throw new RuntimeException('The Shopify store differs from the original listing. Restore its configuration before retrying.');}}
    if($action==='export'){
        if(!$listing){
            if(!preg_match('/^\d{1,8}(\.\d{1,2})?$/',(string)($input['price']??'')) || (float)$input['price']<=0){throw new InvalidArgumentException('Enter a positive USD price.');}
            $pdo->beginTransaction();
            $stmt=$pdo->prepare('SELECT * FROM recovery_devices WHERE id=? FOR UPDATE');$stmt->execute([$device['id']]);$device=$stmt->fetch(PDO::FETCH_ASSOC);
            $pending=$pdo->prepare("SELECT id FROM recovery_reroutes WHERE recovery_device_id=? AND status='PENDING'");$pending->execute([$device['id']]);
            if($device['recovery_type']!=='REC_WHATNOT' || !$device['erp_available'] || $device['outcome_at'] || $pending->fetchColumn()){throw new InvalidArgumentException('Use an available Whatnot device with no pending movement.');}
            $key='recovery-'.bin2hex(random_bytes(16));
            $payload=['listing_key'=>$key,'shop'=>$config['local']?'local':$config['shop'],'handle'=>$key,'sku'=>'REC-ERP-'.$device['erp_device_id'],'price'=>number_format((float)$input['price'],2,'.',''),
                'title'=>($device['product_description']?:$device['product_id']?:'Recovery device').' · '.$serial,
                'description'=>'Serialized recovered device. Serial: '.$serial.'. Grade: '.($device['grade']?:'Not recorded').'. Review condition before publication.',
                'device_id'=>(int)$device['erp_device_id'],'serial_number'=>$serial];
            $pdo->prepare('INSERT INTO recovery_commerce_listings(recovery_device_id,listing_key,provider,payload) VALUES (?,?,?,?)')->execute([$device['id'],$key,$provider,json_encode($payload,JSON_THROW_ON_ERROR)]);
            $listingId=(int)$pdo->lastInsertId();$pdo->commit();$listing=['id'=>$listingId,'listing_key'=>$key,'payload'=>json_encode($payload),'state'=>'PENDING','variant_id'=>null];
        }
        $payload=json_decode($listing['payload'],true,512,JSON_THROW_ON_ERROR);
        if(isset($input['price']) && number_format((float)$input['price'],2,'.','')!==$payload['price']){throw new InvalidArgumentException('Retry using the original export price. Edit published pricing inside Shopify.');}
        if(!$listing['variant_id']){
            $bridge->request('POST','/disposition/commerce-reserve',['request_key'=>$payload['listing_key'].':reserve','listing_key'=>$payload['listing_key'],'device_id'=>$payload['device_id'],'serial_number'=>$serial],$_SESSION['erp_token']);
            $export=$adapter->export($payload);
            $pdo->prepare("UPDATE recovery_commerce_listings SET product_id=?,variant_id=?,state='EXPORTED' WHERE id=?")->execute([$export['product_id'],$export['variant_id'],$listing['id']]);
            $listing['variant_id']=$export['variant_id'];
        }
        $result=['success'=>true,'message'=>$config['local']?'Local export simulated. ERP local serial is reserved.':'Shopify draft exported. Review condition and publish in Shopify.','listing_key'=>$listing['listing_key'],'variant_id'=>$listing['variant_id']];
    } elseif($action==='simulate-paid'){
        if(!$config['local'] || !$listing || !$listing['variant_id'] || $listing['state']==='COMPLETED'){throw new InvalidArgumentException('Local simulation requires an exported, open listing.');}
        $payload=json_decode($listing['payload'],true,512,JSON_THROW_ON_ERROR);$order='local-order-'.$listing['listing_key'];
        $pdo->prepare("INSERT INTO recovery_commerce_sales(provider,order_id,line_id,variant_id,gross_recovery,status) VALUES ('local',?,'1',?,?,'PAID') ON DUPLICATE KEY UPDATE order_id=VALUES(order_id)")->execute([$order,$listing['variant_id'],$payload['price']]);
        $result=['success'=>true,'message'=>'Local paid sale received. Inventory remains in ERP until serial scan-out.','order_id'=>$order];
    } else {
        if(!$listing || !$listing['variant_id']){throw new InvalidArgumentException('Export this serial before scan-out.');}
        if(!preg_match('/^\d{1,9}(\.\d{1,2})?$/',(string)($input['direct_costs']??''))){throw new InvalidArgumentException('Enter confirmed direct costs in USD, including zero if none.');}
        $location=strtoupper(trim((string)($input['location_scan']??'')));$order=(string)($input['order_id']??'');
        $costs=number_format((float)$input['direct_costs'],2,'.','');
        if($listing['exit_payload']){
            $payload=json_decode($listing['exit_payload'],true,512,JSON_THROW_ON_ERROR);
            if($payload['location_scan']!==$location || $payload['direct_costs']!==$costs || !str_starts_with($payload['order_reference'],$order.'|')){throw new InvalidArgumentException('Retry with the original order, location and direct costs.');}
        } else {
            $sale=$adapter->paidSale($order,$listing['variant_id']);
            $payload=['request_key'=>$listing['listing_key'].':exit','listing_key'=>$listing['listing_key'],'device_id'=>(int)$device['erp_device_id'],'serial_number'=>$serial,
                'location_scan'=>$location,'order_reference'=>$sale['order_id'].'|'.$sale['line_id'],'gross_recovery'=>$sale['gross_recovery'],'direct_costs'=>$costs,'currency'=>$sale['currency']];
            $pdo->prepare('UPDATE recovery_commerce_listings SET exit_payload=? WHERE id=?')->execute([json_encode($payload,JSON_THROW_ON_ERROR),$listing['id']]);
        }
        $receipt=$bridge->request('GET','/receipts?request_key='.rawurlencode($payload['request_key']))['receipt'];
        if(!$receipt){
            // Recheck payment after an interrupted attempt, unless ERP already committed the immutable receipt.
            $sale=$adapter->paidSale($order,$listing['variant_id']);
            if($sale['order_id'].'|'.$sale['line_id']!==$payload['order_reference'] || number_format((float)$sale['gross_recovery'],2,'.','')!==number_format((float)$payload['gross_recovery'],2,'.','')){throw new RuntimeException('The commercial transaction changed. Review the frozen exit request before proceeding.');}
            $receipt=$bridge->request('POST','/disposition/commerce',$payload,$_SESSION['erp_token']);
        }
        if(($receipt['request_key']??'')!==$payload['request_key'] || (int)($receipt['device_id']??0)!==$payload['device_id']){throw new RuntimeException('ERP commerce receipt mismatch.');}
        $pdo->beginTransaction();storeRecoveryOutcome($pdo,$receipt);
        $pdo->prepare("UPDATE recovery_commerce_listings SET state='COMPLETED',receipt=? WHERE id=?")->execute([json_encode($receipt,JSON_THROW_ON_ERROR),$listing['id']]);$pdo->commit();
        $result=['success'=>true,'message'=>'Serial scan-out confirmed in ERP. Net recovery USD '.$receipt['net_recovery'],'receipt'=>$receipt];
    }
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    if($e instanceof ERPBridgeException && $e->httpStatus===422 && $action==='scan-out' && isset($listing['id'])){
        $pdo->prepare('UPDATE recovery_commerce_listings SET exit_payload=NULL WHERE id=? AND receipt IS NULL')->execute([$listing['id']]);
    }
    http_response_code($e instanceof InvalidArgumentException?422:502);
    if($e instanceof PDOException)error_log($e->getMessage());
    echo json_encode(['success'=>false,'message'=>$e instanceof PDOException?'Commerce data could not be saved. Retry the same operation.':$e->getMessage()]);
} finally {if($lock){$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}}
