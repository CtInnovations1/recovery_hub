<?php

class ERPGraphQLClient
{

    private string $url;
    private array $headers;


    public function __construct()
    {

        $config = require __DIR__ . "/../config/erp.php";


        $this->url = $config["graphql_url"];


        $this->headers = [

            "Content-Type: application/json",

            "Accept: application/json",

            "User-Agent: Recovery-Hub-V4"

        ];

    }



    public function query($query, $variables = [])
    {


        $payload = json_encode([

            "query" => $query,

            "variables" => $variables

        ]);



        $ch = curl_init($this->url);



        curl_setopt_array($ch, [

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_POST => true,

            CURLOPT_HTTPHEADER => $this->headers,

            CURLOPT_POSTFIELDS => $payload,

            CURLOPT_TIMEOUT => 30,

            CURLOPT_CONNECTTIMEOUT => 10,

            CURLOPT_SSL_VERIFYPEER => false,

            CURLOPT_SSL_VERIFYHOST => false

        ]);



        $response = curl_exec($ch);



        $httpCode = curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


        $curlError = curl_error($ch);



        curl_close($ch);



        return [

            "http_code" => $httpCode,

            "curl_error" => $curlError,

            "response" => json_decode(
                $response,
                true
            ),

            "raw" => $response

        ];

    }


}
