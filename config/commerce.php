<?php
declare(strict_types=1);
return (static function (): array {
$integration = require __DIR__.'/integration.php';
$config = [
    'local' => $integration['environment'] === 'local',
    'shop' => getenv('RECOVERY_SHOPIFY_DOMAIN') ?: '',
    'token' => getenv('RECOVERY_SHOPIFY_TOKEN') ?: '',
    'secret' => getenv('RECOVERY_SHOPIFY_WEBHOOK_SECRET') ?: '',
    'location' => getenv('RECOVERY_SHOPIFY_LOCATION_ID') ?: '',
    'client_id' => getenv('RECOVERY_SHOPIFY_CLIENT_ID') ?: '',
    'client_secret' => getenv('RECOVERY_SHOPIFY_CLIENT_SECRET') ?: '',
    'token_cache' => '',
    'version' => '2026-10',
];
$local = __DIR__.'/commerce.local.php';
if (is_file($local)) { $overrides = require $local; if (is_array($overrides)) $config = array_replace($config,$overrides); }
$config['local'] = $integration['environment'] === 'local';
return $config;
})();
