<?php

return [
    'store_url' => env('WOOCOMMERCE_STORE_URL'),
    'consumer_key' => env('WOOCOMMERCE_CONSUMER_KEY'),
    'consumer_secret' => env('WOOCOMMERCE_CONSUMER_SECRET'),
    'api_version' => env('WOOCOMMERCE_API_VERSION', 'wc/v3'),
    'verify_ssl' => env('WOOCOMMERCE_VERIFY_SSL', true),
];
