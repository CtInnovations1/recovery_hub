<?php
declare(strict_types=1);
/** Get a short-lived token for an app installed in its own Shopify organization. */
function recoveryShopifyToken(array $config, ?callable $exchange = null, ?int $clock = null): string
{
    if ($config['local'] ?? false) { throw new RuntimeException('Live Shopify requests are disabled in local mode.'); }
    if (!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $config['shop'] ?? '')) { throw new RuntimeException('Configure the Shopify domain.'); }
    if (!empty($config['token'])) { return $config['token']; }
    if (empty($config['client_id']) || empty($config['client_secret'])) { throw new RuntimeException('Configure Shopify app credentials on the server.'); }
    $fingerprint=hash('sha256',$config['shop'].'|'.$config['client_id'].'|'.$config['client_secret']);
    $path=$config['token_cache'] ?: sys_get_temp_dir().'/recovery-shopify-'.$fingerprint.'.json';
    $lock=fopen($path,'c+');
    if (!$lock) { throw new RuntimeException('Shopify token cache is unavailable.'); }
    chmod($path,0600);
    try {
        if (!flock($lock,LOCK_EX)) { throw new RuntimeException('Unable to lock Shopify token cache.'); }
        $now=$clock ?? time();$cached=json_decode(stream_get_contents($lock),true);
        if (($cached['fingerprint']??'')===$fingerprint && ($cached['expires_at']??0)>$now+120 && !empty($cached['access_token'])) { return $cached['access_token']; }
        if ($exchange) { $result=$exchange($config); }
        else {
            $curl=curl_init('https://'.$config['shop'].'/admin/oauth/access_token');
            curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'client_credentials','client_id'=>$config['client_id'],'client_secret'=>$config['client_secret']])]);
            $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
            if($raw===false || $status!==200){throw new RuntimeException('Shopify authentication failed. Check app credentials and installation.');}
            $result=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        }
        if(empty($result['access_token']) || (int)($result['expires_in']??0)<=120){throw new RuntimeException('Shopify returned an invalid token lifetime.');}
        $cached=['fingerprint'=>$fingerprint,'access_token'=>$result['access_token'],'expires_at'=>$now+(int)$result['expires_in']];
        $json=json_encode($cached,JSON_THROW_ON_ERROR);rewind($lock);
        if(!ftruncate($lock,0) || fwrite($lock,$json)!==strlen($json) || !fflush($lock)){throw new RuntimeException('Unable to save Shopify token.');}
        return $cached['access_token'];
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}
