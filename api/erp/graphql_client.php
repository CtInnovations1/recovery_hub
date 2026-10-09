<?php

class ERPGraphQLClient
{

    private string $url;
    private array $headers;


    public function __construct()
    {
        if (PHP_SAPI !== 'cli') {
            require_once __DIR__.'/../../includes/auth.php';
            requireRecoveryOperator();
        }

        $config = require __DIR__ . "/../config/erp.php";


        $this->url = getenv('RECOVERY_ERP_GRAPHQL_URL') ?: $config["graphql_url"];
        $integration = require __DIR__.'/../../config/integration.php';
        $host = parse_url($this->url, PHP_URL_HOST);
        if ($integration['environment'] === 'local' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Local mode requires a local ERP GraphQL endpoint.');
        }


        $this->headers = [

            "Content-Type: application/json",

            "Accept: application/json",

            "User-Agent: Recovery-Hub-V4"

        ];

        if (!empty($_SESSION['erp_token'])) {
            $this->headers[] = 'Authorization: Bearer '.$_SESSION['erp_token'];
        }

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

            CURLOPT_SSL_VERIFYPEER => true,

            CURLOPT_SSL_VERIFYHOST => 2

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
