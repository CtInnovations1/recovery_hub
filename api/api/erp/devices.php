<?php
declare(strict_types=1);

require_once __DIR__ . '/client.php';

header('Content-Type: application/json');


try {

    $serial = trim(
        $_GET['serial'] ?? ''
    );

    $product = trim(
        $_GET['product_id'] ?? ''
    );


    $result = erpRequest(
        '/devices/search',
        [
            'serial' => $serial,
            'product_id' => $product
        ]
    );


    echo json_encode(
        [
            'success' => true,
            'data' => $result
        ],
        JSON_PRETTY_PRINT
    );


} catch(Throwable $e){

    http_response_code(500);

    echo json_encode(
        [
            'success'=>false,
            'error'=>$e->getMessage()
        ]
    );

}
