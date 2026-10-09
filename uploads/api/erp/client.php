<?php
declare(strict_types=1);


const ERP_GRAPHQL_URL = 'https://api.creativeerpdev.com/graphql';

const ERP_API_KEY = '@Laptop2025';


function erpGraphQL(
    string $query,
    array $variables = []
): array {


    $payload = json_encode([
        'query' => $query,
        'variables' => $variables
    ]);


    $curl = curl_init();


    curl_setopt_array($curl,[

        CURLOPT_URL => ERP_GRAPHQL_URL,

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS => $payload,

        CURLOPT_HTTPHEADER => [

            'Content-Type: application/json',

            'Accept: application/json',

            'X-Recovery-Key: '.ERP_API_KEY

        ]

    ]);


    $response = curl_exec($curl);


    if($response === false){

        throw new Exception(
            curl_error($curl)
        );

    }


    curl_close($curl);


    $data=json_decode(
        $response,
        true
    );


    if(!is_array($data)){

        throw new Exception(
            'Invalid GraphQL response'
        );

    }


    return $data;

}
