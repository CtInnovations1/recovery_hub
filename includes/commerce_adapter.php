<?php
declare(strict_types=1);

require_once __DIR__.'/shopify_token.php';

interface RecoveryCommerceAdapter
{
    public function export(array $listing): array;
    public function paidSale(string $orderId, string $variantId): array;
}

final class ShopifyRecoveryAdapter implements RecoveryCommerceAdapter
{
    public function __construct(private readonly array $config) {}

    private function request(string $query, array $variables): array
    {
        if ($this->config['local']) { throw new RuntimeException('Live Shopify requests are disabled in local mode.'); }
        if (!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $this->config['shop'])) {
            throw new RuntimeException('Configure the Shopify myshopify.com domain and Admin API token.');
        }
        $token = recoveryShopifyToken($this->config);
        $curl = curl_init('https://'.$this->config['shop'].'/admin/api/'.$this->config['version'].'/graphql.json');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>40,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Shopify-Access-Token: '.$token],
            CURLOPT_POSTFIELDS=>json_encode(['query'=>$query,'variables'=>(object)$variables],JSON_THROW_ON_ERROR)]);
        $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        if($raw===false || $status!==200){throw new RuntimeException('Shopify response unavailable. Retry the same export; its identity is preserved.');}
        $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!empty($data['errors']) || !isset($data['data'])){throw new RuntimeException('Shopify rejected the API request. Check API access and version.');}
        return $data['data'];
    }

    public function export(array $listing): array
    {
        $shop=$this->request('query{shop{currencyCode}}',[]);
        if(($shop['shop']['currencyCode']??'')!=='USD'){throw new RuntimeException('Configure a USD Shopify store; currency conversion is not supported by this release.');}
        $fields='id handle metafield(namespace:"recovery_hub",key:"listing_key"){value} variants(first:2){nodes{id sku}}';
        $data=$this->request('query($identifier:ProductIdentifierInput!){productByIdentifier(identifier:$identifier){'.$fields.'}}', ['identifier'=>['handle'=>$listing['handle']]]);
        $product=$data['productByIdentifier'];
        // After a lost response, recover the existing mapping without resetting price, publication or inventory.
        if(!$product){
            if(!preg_match('~^gid://shopify/Location/\d+$~',$this->config['location'])){throw new RuntimeException('Configure the Shopify inventory location GID.');}
            $input=['handle'=>$listing['handle'],'title'=>$listing['title'],'status'=>'DRAFT',
                'descriptionHtml'=>'<p>'.htmlspecialchars($listing['description'],ENT_QUOTES,'UTF-8').'</p>',
                'metafields'=>[['namespace'=>'recovery_hub','key'=>'listing_key','type'=>'single_line_text_field','value'=>$listing['listing_key']]],
                'productOptions'=>[['name'=>'Title','values'=>[['name'=>'Default Title']]]],
                'variants'=>[['optionValues'=>[['optionName'=>'Title','name'=>'Default Title']],'sku'=>$listing['sku'],'price'=>$listing['price'],
                    'inventoryPolicy'=>'DENY','inventoryItem'=>['tracked'=>true],
                    'inventoryQuantities'=>[['locationId'=>$this->config['location'],'name'=>'available','quantity'=>1]]]]];
            // Handle is the stable upsert identifier; all retries are serialized by the Hub listing lock.
            $data=$this->request('mutation($input:ProductSetInput!,$identifier:ProductSetIdentifiers!){productSet(input:$input,identifier:$identifier,synchronous:true){product{'.$fields.'} userErrors{message}}}', ['input'=>$input,'identifier'=>['handle'=>$listing['handle']]]);
            if(!empty($data['productSet']['userErrors'])){throw new RuntimeException('Shopify export: '.implode('; ',array_column($data['productSet']['userErrors'],'message')));}
            $product=$data['productSet']['product'];
        }
        if(!$product || ($product['metafield']['value']??'')!==$listing['listing_key'] || count($product['variants']['nodes'])!==1 || $product['variants']['nodes'][0]['sku']!==$listing['sku']) {
            throw new RuntimeException('Shopify identity mismatch. Review the reserved listing before retrying.');
        }
        return ['product_id'=>$product['id'],'variant_id'=>$product['variants']['nodes'][0]['id'],'mode'=>'SHOPIFY_DRAFT'];
    }

    public function paidSale(string $orderId, string $variantId): array
    {
        if(!preg_match('~^gid://shopify/Order/\d+$~',$orderId)){throw new InvalidArgumentException('Invalid Shopify order identifier.');}
        $data=$this->request('query($id:ID!){order(id:$id){id cancelledAt displayFinancialStatus refunds{id} lineItems(first:100){pageInfo{hasNextPage} nodes{id quantity currentQuantity variant{id} discountedTotalSet{shopMoney{amount currencyCode}}}}}}',['id'=>$orderId]);
        $order=$data['order']??null;
        if(!$order || $order['cancelledAt'] || $order['displayFinancialStatus']!=='PAID' || $order['refunds'] || $order['lineItems']['pageInfo']['hasNextPage']){throw new RuntimeException('Order must be paid, uncancelled, without refunds, and have at most 100 lines. Review exceptions in Shopify.');}
        $lines=array_values(array_filter($order['lineItems']['nodes'],fn($line)=>($line['variant']['id']??'')===$variantId));
        if(count($lines)!==1 || $lines[0]['quantity']!==1 || $lines[0]['currentQuantity']!==1){throw new RuntimeException('A unique sale of exactly one serialized variant is required.');}
        $money=$lines[0]['discountedTotalSet']['shopMoney'];
        if($money['currencyCode']!=='USD'){throw new RuntimeException('This Recovery release supports USD outcomes only.');}
        return ['order_id'=>$orderId,'line_id'=>$lines[0]['id'],'gross_recovery'=>$money['amount'],'currency'=>'USD'];
    }
}

final class LocalRecoveryCommerceAdapter implements RecoveryCommerceAdapter
{
    public function __construct(private readonly PDO $pdo) {}
    public function export(array $listing): array
    {
        return ['product_id'=>'local-product-'.$listing['listing_key'],'variant_id'=>'local-variant-'.$listing['listing_key'],'mode'=>'LOCAL_SIMULATION'];
    }
    public function paidSale(string $orderId, string $variantId): array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM recovery_commerce_sales WHERE order_id=? AND variant_id=? AND status='PAID' AND provider='local'");$stmt->execute([$orderId,$variantId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row){throw new RuntimeException('Simulate a paid local sale first.');}
        return ['order_id'=>$orderId,'line_id'=>$row['line_id'],'gross_recovery'=>$row['gross_recovery'],'currency'=>'USD'];
    }
}

function recoveryCommerceAdapter(PDO $pdo, array $config): RecoveryCommerceAdapter
{
    return $config['local'] ? new LocalRecoveryCommerceAdapter($pdo) : new ShopifyRecoveryAdapter($config);
}

function recoveryVerifyShopifyWebhook(string $body,string $hmac,string $shop,array $config): bool
{
    return !$config['local'] && strlen($config['secret'])>=16 && $shop===$config['shop'] &&
        hash_equals(base64_encode(hash_hmac('sha256',$body,$config['secret'],true)),$hmac);
}
