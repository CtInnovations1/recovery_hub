<?php
// Copy to commerce.local.php on the server. Keep secrets out of source control.
return [
    'shop' => 'YOUR_STORE.myshopify.com',
    'client_id' => 'SET_ON_SERVER',
    'client_secret' => 'SET_ON_SERVER',
    'secret' => 'THE_SAME_APP_CLIENT_SECRET_FOR_WEBHOOK_HMAC',
    'location' => 'gid://shopify/Location/LOCATION_ID',
    'token_cache' => '/PRIVATE_DIRECTORY_OUTSIDE_PUBLIC_HTML/shopify-token.json',
];
