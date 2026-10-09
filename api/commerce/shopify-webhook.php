<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/commerce_adapter.php';
$config=require __DIR__.'/../../config/commerce.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
$body=file_get_contents('php://input',false,null,0,2097153);
if(strlen($body)>2097152){http_response_code(413);exit;}
if(!recoveryVerifyShopifyWebhook($body,$_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256']??'',$_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN']??'',$config)){http_response_code(401);exit;}
if(($_SERVER['HTTP_X_SHOPIFY_TOPIC']??'')!=='orders/paid'){http_response_code(204);exit;}
try {
    $order=json_decode($body,true,512,JSON_THROW_ON_ERROR);
    if(!isset($order['id'],$order['line_items']) || !is_array($order['line_items'])){http_response_code(400);exit;}
    require __DIR__.'/../../config/database_connection.php';
    $pdo->beginTransaction();
    foreach($order['line_items'] as $line){
        if(empty($line['id']) || empty($line['variant_id']))continue;
        $variant='gid://shopify/ProductVariant/'.$line['variant_id'];
        $known=$pdo->prepare("SELECT id FROM recovery_commerce_listings WHERE provider='shopify' AND variant_id=?");$known->execute([$variant]);
        if(!$known->fetchColumn())continue;
        // Store identity only. Price and payment are revalidated directly with Shopify at scan-out.
        $pdo->prepare("INSERT INTO recovery_commerce_sales(provider,order_id,line_id,variant_id,status) VALUES ('shopify',?,?,?,'PAID') ON DUPLICATE KEY UPDATE order_id=VALUES(order_id)")
            ->execute(['gid://shopify/Order/'.$order['id'],'gid://shopify/LineItem/'.$line['id'],$variant]);
    }
    $pdo->commit();http_response_code(204);
} catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('Recovery webhook: '.$e->getMessage());http_response_code(500);}
