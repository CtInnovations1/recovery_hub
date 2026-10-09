<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/graphql_client.php';

/**
 * Send JSON response and stop execution.
 */
function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

/**
 * Convert numeric values safely.
 */
function numericOrNull($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (float) $value : null;
}


/*
|--------------------------------------------------------------------------
| Validate Serial
|--------------------------------------------------------------------------
*/

$serial = isset($_GET['serial'])
    ? trim((string) $_GET['serial'])
    : '';

if ($serial === '') {
    jsonResponse([
        'success' => false,
        'found' => false,
        'error' => 'Serial number is required.'
    ], 400);
}

if (strlen($serial) > 100) {
    jsonResponse([
        'success' => false,
        'found' => false,
        'error' => 'Serial number is too long.'
    ], 400);
}


/*
|--------------------------------------------------------------------------
| GraphQL Query
|--------------------------------------------------------------------------
|
| IMPORTANT:
| We use an exact WHERE condition on serial_number.
| Do not use the generic GraphQL "filter" parameter because it was already
| confirmed that it does not reliably perform an exact serial lookup.
|
*/

$query = <<<'GRAPHQL'
query FindDeviceBySerial($serial: Mixed) {
    devices(
        first: 1
        where: {
            column: "serial_number"
            operator: EQ
            value: $serial
        }
    ) {
        data {
            id
            serial_number

            status
            initial_status
            diagnostic_status

            recovery_type_id

            recoveryType {
                id
                recovery_type
                description
                default_disposition
                is_visible
            }

            initial_cost
            determined_cost
            processing_cost
            real_cost

            initial_overall_grade
            determined_overall_grade
            finale_grade

            initial_defect_codes
            determined_defect_codes

            location {
                id
                name
            }

            product {
                product_unique_id
                description
                erp_make_name
                erp_model_number
                erp_cpu
                erp_ram
                erp_storage

                category {
                    name
                    product_type
                }
            }
        }
    }
}
GRAPHQL;


/*
|--------------------------------------------------------------------------
| Execute ERP Query
|--------------------------------------------------------------------------
*/

try {

    $client = new ERPGraphQLClient();

    $result = $client->query(
        $query,
        [
            'serial' => $serial
        ]
    );

} catch (Throwable $e) {

    jsonResponse([
        'success' => false,
        'found' => false,
        'error' => 'Unable to communicate with ERP.',
        'details' => $e->getMessage()
    ], 500);
}


/*
|--------------------------------------------------------------------------
| Validate HTTP / cURL Response
|--------------------------------------------------------------------------
*/

$httpCode = $result['http_code'] ?? 0;
$curlError = $result['curl_error'] ?? '';
$response = $result['response'] ?? null;

if ($curlError !== '') {

    jsonResponse([
        'success' => false,
        'found' => false,
        'error' => 'ERP connection error.',
        'details' => $curlError
    ], 502);
}

if ($httpCode < 200 || $httpCode >= 300) {

    jsonResponse([
        'success' => false,
        'found' => false,
        'error' => 'ERP returned an unexpected HTTP status.',
        'http_code' => $httpCode
    ], 502);
}

if (!is_array($response)) {

    jsonResponse([
        'success' => false,
        'found' => false,
        'error' => 'ERP returned an invalid JSON response.'
    ], 502);
}


/*
|--------------------------------------------------------------------------
| GraphQL Errors
|--------------------------------------------------------------------------
*/

if (!empty($response['errors'])) {

    $messages = [];

    foreach ($response['errors'] as $error) {
        if (isset($error['message'])) {
            $messages[] = $error['message'];
        }
    }

    jsonResponse([
        'success' => false,
        'found' => false,
        'error' => 'ERP GraphQL error.',
        'details' => $messages
    ], 502);
}


/*
|--------------------------------------------------------------------------
| Locate Device
|--------------------------------------------------------------------------
*/

$devices = $response['data']['devices']['data'] ?? [];

if (!is_array($devices) || count($devices) === 0) {

    jsonResponse([
        'success' => true,
        'found' => false,
        'serial_number' => $serial,
        'message' => 'Device not found in ERP.'
    ]);
}

$device = $devices[0];


/*
|--------------------------------------------------------------------------
| Extra Serial Safety Check
|--------------------------------------------------------------------------
|
| Even though GraphQL uses EQ, verify that the returned serial is actually
| the serial requested before exposing it to Recovery Hub.
|
*/

$returnedSerial = trim((string) ($device['serial_number'] ?? ''));

if (
    $returnedSerial === '' ||
    strcasecmp($returnedSerial, $serial) !== 0
) {

    jsonResponse([
        'success' => false,
        'found' => false,
        'error' => 'ERP returned a device that does not match the requested serial.'
    ], 502);
}


/*
|--------------------------------------------------------------------------
| Normalize ERP Data
|--------------------------------------------------------------------------
*/

$product = is_array($device['product'] ?? null)
    ? $device['product']
    : [];

$category = is_array($product['category'] ?? null)
    ? $product['category']
    : [];

$location = is_array($device['location'] ?? null)
    ? $device['location']
    : [];

$recoveryType = is_array($device['recoveryType'] ?? null)
    ? $device['recoveryType']
    : [];


/*
|--------------------------------------------------------------------------
| Final Recovery Hub Response
|--------------------------------------------------------------------------
*/

jsonResponse([
    'success' => true,
    'found' => true,

    'device' => [

        /*
        |------------------------------------------------------------------
        | ERP Identity
        |------------------------------------------------------------------
        */

        'erp_device_id' =>
            isset($device['id'])
                ? (int) $device['id']
                : null,

        'serial_number' =>
            $returnedSerial,


        /*
        |------------------------------------------------------------------
        | Product
        |------------------------------------------------------------------
        */

        'product_id' =>
            $product['product_unique_id'] ?? null,

        'product_description' =>
            $product['description'] ?? null,

        'category' =>
            $category['name'] ?? null,

        'product_type' =>
            $category['product_type'] ?? null,

        'make' =>
            $product['erp_make_name'] ?? null,

        'model' =>
            $product['erp_model_number'] ?? null,

        'cpu' =>
            $product['erp_cpu'] ?? null,

        'ram' =>
            $product['erp_ram'] ?? null,

        'storage' =>
            $product['erp_storage'] ?? null,


        /*
        |------------------------------------------------------------------
        | ERP Status
        |------------------------------------------------------------------
        */

        'status' =>
            $device['status'] ?? null,

        'initial_status' =>
            $device['initial_status'] ?? null,

        'diagnostic_status' =>
            $device['diagnostic_status'] ?? null,


        /*
        |------------------------------------------------------------------
        | Recovery Type
        |------------------------------------------------------------------
        |
        | ERP is the source of truth.
        | If ERP has no Recovery Type, these values remain NULL.
        |
        */

        'recovery_type_id' =>
            isset($device['recovery_type_id'])
                ? (int) $device['recovery_type_id']
                : null,

        'recovery_type' =>
            $recoveryType['recovery_type'] ?? null,

        'recovery_type_description' =>
            $recoveryType['description'] ?? null,

        'default_disposition' =>
            $recoveryType['default_disposition'] ?? null,

        'recovery_type_visible' =>
            array_key_exists('is_visible', $recoveryType)
                ? (bool) $recoveryType['is_visible']
                : null,


        /*
        |------------------------------------------------------------------
        | Location
        |------------------------------------------------------------------
        */

        'location_id' =>
            isset($location['id'])
                ? (int) $location['id']
                : null,

        'location' =>
            $location['name'] ?? null,


        /*
        |------------------------------------------------------------------
        | Defects
        |------------------------------------------------------------------
        */

        'initial_defect_codes' =>
            $device['initial_defect_codes'] ?? null,

        'determined_defect_codes' =>
            $device['determined_defect_codes'] ?? null,


        /*
        |------------------------------------------------------------------
        | Grades
        |------------------------------------------------------------------
        */

        'initial_grade' =>
            $device['initial_overall_grade'] ?? null,

        'determined_grade' =>
            $device['determined_overall_grade'] ?? null,

        'final_grade' =>
            $device['finale_grade'] ?? null,


        /*
        |------------------------------------------------------------------
        | Costs
        |------------------------------------------------------------------
        |
        | We expose the ERP values independently.
        | We are NOT defining Recovery Hub cost_basis yet.
        |
        */

        'initial_cost' =>
            numericOrNull($device['initial_cost'] ?? null),

        'determined_cost' =>
            numericOrNull($device['determined_cost'] ?? null),

        'processing_cost' =>
            numericOrNull($device['processing_cost'] ?? null),

        'real_cost' =>
            numericOrNull($device['real_cost'] ?? null)
    ]
]);