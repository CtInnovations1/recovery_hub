<?php

header("Content-Type: application/json");


$config = require __DIR__ . "/../config/erp.php";


echo json_encode([

    "success"=>true,

    "message"=>"ERP connector ready",

    "endpoint"=>$config["graphql_url"]

],JSON_PRETTY_PRINT);

