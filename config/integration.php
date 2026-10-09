<?php
declare(strict_types=1);

return (static function (): array {
$config = [
    'environment' => getenv('RECOVERY_ENV') ?: 'production',
    'erp_base_url' => getenv('RECOVERY_ERP_BASE_URL') ?: '', // https://ERP_HOST/api/recovery/v1
    'erp_key' => getenv('RECOVERY_HUB_API_KEY') ?: '',
    'instance_id' => getenv('RECOVERY_INSTANCE_ID') ?: '',
];
$local = __DIR__ . '/integration.local.php';
if (is_file($local)) {
    $overrides = require $local;
    if (is_array($overrides)) {
        $config = array_replace($config, $overrides);
    }
}
return $config;
})();
