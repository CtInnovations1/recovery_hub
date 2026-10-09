<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/erp/graphql_client.php';

$currentPage = 'intake';

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jsonOut(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function normalizeSerial(string $serial): string
{
    return trim($serial);
}

function parseDefectCodes(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    $items = is_array($decoded) ? $decoded : preg_split('/[,|]+/', $raw);

    $result = [];

    foreach ($items ?: [] as $item) {
        $code = strtoupper(trim((string)$item));

        if ($code !== '') {
            $result[$code] = $code;
        }
    }

    return array_values($result);
}

function numericOrNullIntake(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (float)$value : null;
}

function pickGrade(array $device): ?string
{
    foreach ([
        $device['finale_grade'] ?? null,
        $device['determined_overall_grade'] ?? null,
        $device['initial_overall_grade'] ?? null,
    ] as $value) {
        $value = trim((string)$value);

        if ($value !== '') {
            return $value;
        }
    }

    return null;
}

function pickCost(array $device): ?float
{
    foreach ([
        $device['real_cost'] ?? null,
        $device['determined_cost'] ?? null,
        $device['initial_cost'] ?? null,
    ] as $value) {
        $number = numericOrNullIntake($value);

        if ($number !== null) {
            return $number;
        }
    }

    return null;
}

function allowedRecoveryType(?string $type): string
{
    $type = strtoupper(trim((string)$type));

    $allowed = [
        'REC_WHATNOT',
        'REC_WHOLESALE',
        'REC_REPAIR',
        'REC_HARVEST',
        'REVIEW_REQUIRED',
    ];

    return in_array($type, $allowed, true)
        ? $type
        : 'REVIEW_REQUIRED';
}

function recoveryLabel(string $type): string
{
    return match ($type) {
        'REC_WHATNOT'     => 'Whatnot',
        'REC_WHOLESALE'   => 'Wholesale',
        'REC_REPAIR'      => 'Repair',
        'REC_HARVEST'     => 'Harvest',
        'REVIEW_REQUIRED' => 'Review Required',
        default           => $type,
    };
}

function erpLookupExact(string $serial): array
{
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

            determinedProduct {
                product_unique_id
                description

                category {
                    name
                    product_type
                }
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

    $client = new ERPGraphQLClient();

    $result = $client->query($query, [
        'serial' => $serial,
    ]);

    if (!empty($result['curl_error'])) {
        throw new RuntimeException(
            'ERP connection error: ' . $result['curl_error']
        );
    }

    $httpCode = (int)($result['http_code'] ?? 0);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException(
            'ERP returned HTTP ' . $httpCode
        );
    }

    $response = $result['response'] ?? null;

    if (!is_array($response)) {
        throw new RuntimeException('ERP returned invalid JSON.');
    }

    if (!empty($response['errors'])) {
        $messages = [];

        foreach ($response['errors'] as $error) {
            if (!empty($error['message'])) {
                $messages[] = (string)$error['message'];
            }
        }

        throw new RuntimeException(
            'ERP GraphQL error: ' .
            ($messages ? implode(' | ', $messages) : 'Unknown GraphQL error')
        );
    }

    $devices = $response['data']['devices']['data'] ?? [];

    if (!is_array($devices) || !$devices) {
        return [
            'found' => false,
            'device' => null,
        ];
    }

    $device = $devices[0];

    $returnedSerial = trim((string)($device['serial_number'] ?? ''));

    if (
        $returnedSerial === '' ||
        strcasecmp($returnedSerial, $serial) !== 0
    ) {
        throw new RuntimeException(
            'ERP returned a different serial number.'
        );
    }

    $product = $device['determinedProduct'] ?? null;

    if (!is_array($product) || empty($product['product_unique_id'])) {
        $product = $device['product'] ?? [];
    }

    $recoveryType = allowedRecoveryType(
        $device['recoveryType']['recovery_type'] ?? null
    );

    $determinedDefects = parseDefectCodes(
        isset($device['determined_defect_codes'])
            ? (string)$device['determined_defect_codes']
            : null
    );

    $initialDefects = parseDefectCodes(
        isset($device['initial_defect_codes'])
            ? (string)$device['initial_defect_codes']
            : null
    );

    $defects = $determinedDefects ?: $initialDefects;

    return [
        'found' => true,
        'device' => [
            'erp_device_id' => isset($device['id'])
                ? (int)$device['id']
                : null,

            'serial_number' => $returnedSerial,

            'product_id' =>
                $product['product_unique_id'] ?? null,

            'product_description' =>
                $product['description'] ?? null,

            'product_category' =>
                $product['category']['name'] ?? null,

            'product_type' =>
                $product['category']['product_type'] ?? null,

            'grade' => pickGrade($device),

            'recovery_type' => $recoveryType,

            'recovery_status' => 'PENDING_RECOVERY_REVIEW',

            'erp_location_id' =>
                isset($device['location']['id'])
                    ? (int)$device['location']['id']
                    : null,

            'location_name' =>
                $device['location']['name'] ?? null,

            'cost_basis' => pickCost($device),

            'defect_codes' => $defects,

            'erp_status' =>
                $device['status'] ?? null,

            'diagnostic_status' =>
                $device['diagnostic_status'] ?? null,
        ],
    ];
}

function defectDescriptionMap(PDO $pdo, array $codes): array
{
    $codes = array_values(
        array_unique(
            array_filter(
                array_map(
                    static fn($v) => strtoupper(trim((string)$v)),
                    $codes
                )
            )
        )
    );

    if (!$codes) {
        return [];
    }

    $placeholders = implode(
        ',',
        array_fill(0, count($codes), '?')
    );

    $stmt = $pdo->prepare("
        SELECT
            defect_code,
            COALESCE(
                NULLIF(TRIM(short_definition_en), ''),
                NULLIF(TRIM(definition_en), ''),
                NULLIF(TRIM(erp_description), ''),
                defect_code
            ) AS description
        FROM defect_library
        WHERE defect_code IN ({$placeholders})
    ");

    $stmt->execute($codes);

    $map = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[strtoupper((string)$row['defect_code'])] =
            (string)$row['description'];
    }

    return $map;
}

function recoveryDeviceBySerial(PDO $pdo, string $serial): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            id,
            erp_device_id,
            serial_number,
            product_id,
            product_description,
            recovery_type,
            recovery_status,
            entered_recovery_at
        FROM recovery_devices
        WHERE serial_number = :serial
        LIMIT 1
    ");

    $stmt->execute([
        'serial' => $serial,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function insertErpDevice(PDO $pdo, array $device): int
{
    $stmt = $pdo->prepare("
        INSERT INTO recovery_devices (
            erp_device_id,
            serial_number,
            product_id,
            product_description,
            product_category,
            product_type,
            grade,
            source_type,
            source_reference,
            recovery_type,
            recovery_status,
            erp_location_id,
            location_name,
            location_type,
            cost_basis,
            entered_recovery_at,
            erp_synced_at
        ) VALUES (
            :erp_device_id,
            :serial_number,
            :product_id,
            :product_description,
            :product_category,
            :product_type,
            :grade,
            'ERP',
            :source_reference,
            :recovery_type,
            :recovery_status,
            :erp_location_id,
            :location_name,
            NULL,
            :cost_basis,
            NOW(),
            NOW()
        )
    ");

    $stmt->execute([
        'erp_device_id' => $device['erp_device_id'],
        'serial_number' => $device['serial_number'],
        'product_id' => $device['product_id'],
        'product_description' => $device['product_description'],
        'product_category' => $device['product_category'],
        'product_type' => $device['product_type'],
        'grade' => $device['grade'],
        'source_reference' =>
            $device['erp_device_id'] !== null
                ? 'ERP_DEVICE:' . $device['erp_device_id']
                : 'ERP',
        'recovery_type' => $device['recovery_type'],
        'recovery_status' => $device['recovery_status'],
        'erp_location_id' => $device['erp_location_id'],
        'location_name' => $device['location_name'],
        'cost_basis' => $device['cost_basis'],
    ]);

    $recoveryDeviceId = (int)$pdo->lastInsertId();

    $defectCodes = $device['defect_codes'] ?? [];

    if ($defectCodes) {
        $descriptionMap = defectDescriptionMap($pdo, $defectCodes);

        $defectStmt = $pdo->prepare("
            INSERT INTO recovery_device_defects (
                recovery_device_id,
                defect_code,
                defect_description,
                defect_source
            ) VALUES (
                :recovery_device_id,
                :defect_code,
                :defect_description,
                'ERP'
            )
        ");

        foreach ($defectCodes as $code) {
            $code = strtoupper(trim((string)$code));

            if ($code === '') {
                continue;
            }

            $defectStmt->execute([
                'recovery_device_id' => $recoveryDeviceId,
                'defect_code' => $code,
                'defect_description' =>
                    $descriptionMap[$code] ?? null,
            ]);
        }
    }

    return $recoveryDeviceId;
}


/*
|--------------------------------------------------------------------------
| AJAX Actions
|--------------------------------------------------------------------------
*/

$action = trim((string)($_POST['action'] ?? ''));

if ($action !== '') {

    /*
    |--------------------------------------------------------------------------
    | Lookup one serial
    |--------------------------------------------------------------------------
    */

    if ($action === 'lookup') {

        $serial = normalizeSerial(
            (string)($_POST['serial'] ?? '')
        );

        if ($serial === '') {
            jsonOut([
                'success' => false,
                'queue_status' => 'ERROR',
                'error' => 'Serial number is required.',
            ], 400);
        }

        if (strlen($serial) > 100) {
            jsonOut([
                'success' => false,
                'queue_status' => 'ERROR',
                'error' => 'Serial number is too long.',
            ], 400);
        }

        $existing = recoveryDeviceBySerial($pdo, $serial);

        if ($existing) {
            jsonOut([
                'success' => true,
                'queue_status' => 'ALREADY_IN_RECOVERY',
                'serial' => $serial,
                'existing' => $existing,
            ]);
        }

        try {
            $lookup = erpLookupExact($serial);

            if (!$lookup['found']) {
                jsonOut([
                    'success' => true,
                    'queue_status' => 'MANUAL_REQUIRED',
                    'serial' => $serial,
                ]);
            }

            jsonOut([
                'success' => true,
                'queue_status' => 'READY',
                'serial' => $serial,
                'device' => $lookup['device'],
            ]);

        } catch (Throwable $e) {
            jsonOut([
                'success' => false,
                'queue_status' => 'ERROR',
                'serial' => $serial,
                'error' => $e->getMessage(),
            ], 502);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Import all READY serials
    |--------------------------------------------------------------------------
    */

    if ($action === 'import_ready') {

        $rawSerials = $_POST['serials'] ?? '[]';

        $serials = json_decode(
            is_string($rawSerials) ? $rawSerials : '[]',
            true
        );

        if (!is_array($serials)) {
            jsonOut([
                'success' => false,
                'error' => 'Invalid serial list.',
            ], 400);
        }

        $serials = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn($v) => normalizeSerial((string)$v),
                        $serials
                    )
                )
            )
        );

        if (!$serials) {
            jsonOut([
                'success' => false,
                'error' => 'No READY devices were supplied.',
            ], 400);
        }

        $results = [];
        $imported = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($serials as $serial) {

            try {

                $existing = recoveryDeviceBySerial($pdo, $serial);

                if ($existing) {
                    $skipped++;

                    $results[] = [
                        'serial' => $serial,
                        'status' => 'ALREADY_IN_RECOVERY',
                        'message' => 'Device already exists in Recovery.',
                    ];

                    continue;
                }

                $lookup = erpLookupExact($serial);

                if (!$lookup['found']) {
                    $skipped++;

                    $results[] = [
                        'serial' => $serial,
                        'status' => 'MANUAL_REQUIRED',
                        'message' => 'Serial was not found in ERP.',
                    ];

                    continue;
                }

                $pdo->beginTransaction();

                $recoveryDeviceId = insertErpDevice(
                    $pdo,
                    $lookup['device']
                );

                $pdo->commit();

                $imported++;

                $results[] = [
                    'serial' => $serial,
                    'status' => 'IMPORTED',
                    'recovery_device_id' => $recoveryDeviceId,
                    'message' => 'Imported successfully.',
                ];

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors++;

                $results[] = [
                    'serial' => $serial,
                    'status' => 'ERROR',
                    'message' => $e->getMessage(),
                ];
            }
        }

        jsonOut([
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'results' => $results,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Manual Intake
    |--------------------------------------------------------------------------
    */

    if ($action === 'manual_import') {

        $serial = normalizeSerial(
            (string)($_POST['serial'] ?? '')
        );

        if ($serial === '') {
            jsonOut([
                'success' => false,
                'error' => 'Serial number is required.',
            ], 400);
        }

        if (strlen($serial) > 100) {
            jsonOut([
                'success' => false,
                'error' => 'Serial number is too long.',
            ], 400);
        }

        $existing = recoveryDeviceBySerial($pdo, $serial);

        if ($existing) {
            jsonOut([
                'success' => false,
                'error' => 'This serial is already in Recovery.',
                'existing' => $existing,
            ], 409);
        }

        /*
         * Extra protection:
         * Manual Intake is only allowed after ERP confirms "not found".
         * A connection/API error must NOT create a manual device.
         */
        try {
            $lookup = erpLookupExact($serial);

            if ($lookup['found']) {
                jsonOut([
                    'success' => false,
                    'error' =>
                        'This serial now exists in ERP. Add it as an ERP device instead.',
                    'device' => $lookup['device'],
                ], 409);
            }
        } catch (Throwable $e) {
            jsonOut([
                'success' => false,
                'error' =>
                    'Manual Intake blocked because ERP could not be verified.',
                'details' => $e->getMessage(),
            ], 502);
        }

        $productId = trim(
            (string)($_POST['product_id'] ?? '')
        );

        $productDescription = trim(
            (string)($_POST['product_description'] ?? '')
        );

        $productCategory = trim(
            (string)($_POST['product_category'] ?? '')
        );

        $productType = trim(
            (string)($_POST['product_type'] ?? '')
        );

        $grade = trim(
            (string)($_POST['grade'] ?? '')
        );

        $recoveryType = allowedRecoveryType(
            (string)($_POST['recovery_type'] ?? 'REVIEW_REQUIRED')
        );

        $locationName = trim(
            (string)($_POST['location_name'] ?? '')
        );

        $costBasis = numericOrNullIntake(
            $_POST['cost_basis'] ?? null
        );

        $defectCode = strtoupper(
            trim((string)($_POST['defect_code'] ?? ''))
        );

        $defectDescription = trim(
            (string)($_POST['defect_description'] ?? '')
        );

        try {

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO recovery_devices (
                    erp_device_id,
                    serial_number,
                    product_id,
                    product_description,
                    product_category,
                    product_type,
                    grade,
                    source_type,
                    source_reference,
                    recovery_type,
                    recovery_status,
                    location_name,
                    cost_basis,
                    entered_recovery_at
                ) VALUES (
                    NULL,
                    :serial_number,
                    :product_id,
                    :product_description,
                    :product_category,
                    :product_type,
                    :grade,
                    'MANUAL',
                    'MANUAL_INTAKE',
                    :recovery_type,
                    'PENDING_RECOVERY_REVIEW',
                    :location_name,
                    :cost_basis,
                    NOW()
                )
            ");

            $stmt->execute([
                'serial_number' => $serial,
                'product_id' => $productId !== '' ? $productId : null,
                'product_description' =>
                    $productDescription !== ''
                        ? $productDescription
                        : null,
                'product_category' =>
                    $productCategory !== ''
                        ? $productCategory
                        : null,
                'product_type' =>
                    $productType !== ''
                        ? $productType
                        : null,
                'grade' =>
                    $grade !== ''
                        ? $grade
                        : null,
                'recovery_type' => $recoveryType,
                'location_name' =>
                    $locationName !== ''
                        ? $locationName
                        : null,
                'cost_basis' => $costBasis,
            ]);

            $recoveryDeviceId = (int)$pdo->lastInsertId();

            if ($defectCode !== '') {

                if ($defectDescription === '') {
                    $map = defectDescriptionMap(
                        $pdo,
                        [$defectCode]
                    );

                    $defectDescription =
                        $map[$defectCode] ?? '';
                }

                $defectStmt = $pdo->prepare("
                    INSERT INTO recovery_device_defects (
                        recovery_device_id,
                        defect_code,
                        defect_description,
                        defect_source
                    ) VALUES (
                        :recovery_device_id,
                        :defect_code,
                        :defect_description,
                        'MANUAL'
                    )
                ");

                $defectStmt->execute([
                    'recovery_device_id' => $recoveryDeviceId,
                    'defect_code' => $defectCode,
                    'defect_description' =>
                        $defectDescription !== ''
                            ? $defectDescription
                            : null,
                ]);
            }

            $pdo->commit();

            jsonOut([
                'success' => true,
                'status' => 'IMPORTED',
                'serial' => $serial,
                'recovery_device_id' => $recoveryDeviceId,
            ]);

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            jsonOut([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    jsonOut([
        'success' => false,
        'error' => 'Unknown Intake action.',
    ], 400);
}


/*
|--------------------------------------------------------------------------
| Defect list for Manual Intake
|--------------------------------------------------------------------------
*/

$manualDefects = $pdo->query("
    SELECT
        defect_code,
        COALESCE(
            NULLIF(TRIM(short_definition_en), ''),
            NULLIF(TRIM(definition_en), ''),
            NULLIF(TRIM(erp_description), ''),
            defect_code
        ) AS description
    FROM defect_library
    WHERE is_active = 1
    ORDER BY defect_code
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Recovery Intake | Recovery Hub</title>

    <link
        rel="stylesheet"
        href="/assets/css/erp-theme-v5.css"
    >

    <style>
        .intake-page {
            max-width:1600px;
            margin:0 auto;
        }

        .intake-intro {
            margin-bottom:18px;
        }

        .intake-intro h1 {
            margin-bottom:4px;
        }

        .intake-intro p {
            color:#596174;
            margin:0;
        }

        .intake-grid {
            display:grid;
            grid-template-columns:minmax(0,1fr) 320px;
            gap:18px;
            align-items:start;
        }

        .intake-card {
            overflow:hidden;
        }

        .card-head {
            padding:17px 18px;
            border-bottom:1px solid #e4e7ec;
        }

        .card-title {
            color:#0f1b3d;
            font-size:15px;
            font-weight:800;
        }

        .card-subtitle {
            margin-top:4px;
            color:#858998;
            font-size:12px;
            line-height:1.45;
        }

        .card-body {
            padding:18px;
        }

        .scan-area {
            width:100%;
            min-height:118px;
            resize:vertical;
            padding:12px;
            border:1px solid #d9dde5;
            border-radius:6px;
            color:#26304a;
            background:#fff;
            outline:none;
            font-family:inherit;
            font-size:14px;
            line-height:1.5;
        }

        .scan-area:focus {
            border-color:#1683f8;
            box-shadow:0 0 0 2px rgba(22,131,248,.10);
        }

        .scan-help {
            margin-top:7px;
            color:#858998;
            font-size:11px;
        }

        .button-row {
            display:flex;
            gap:8px;
            margin-top:12px;
            flex-wrap:wrap;
        }

        .btn {
            min-height:40px;
            border-radius:5px;
            padding:0 16px;
            cursor:pointer;
            font-weight:700;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border:1px solid transparent;
            font-family:inherit;
        }

        .btn:disabled {
            opacity:.55;
            cursor:not-allowed;
        }

        .btn-primary {
            background:#1976d2;
            color:#fff;
            border-color:#1976d2;
        }

        .btn-success {
            background:#168653;
            color:#fff;
            border-color:#168653;
        }

        .btn-secondary {
            background:#fff;
            color:#26304a;
            border-color:#d9dde5;
        }

        .btn-danger {
            background:#fff;
            color:#b42318;
            border-color:#f1c5c1;
        }

        .summary-grid {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:10px;
        }

        .summary-box {
            padding:13px;
            border:1px solid #e4e7ec;
            border-radius:7px;
            background:#fff;
        }

        .summary-number {
            font-size:24px;
            line-height:1;
            font-weight:800;
            color:#17203a;
        }

        .summary-label {
            margin-top:6px;
            color:#72798a;
            font-size:11px;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:.25px;
        }

        .queue-wrap {
            margin-top:18px;
        }

        .queue-toolbar {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:10px;
        }

        .queue-title {
            color:#26304a;
            font-size:14px;
            font-weight:800;
        }

        .queue-meta {
            color:#858998;
            font-size:12px;
        }

        .queue-table-wrap {
            overflow:auto;
            border:1px solid #e4e7ec;
            border-radius:7px;
            background:#fff;
        }

        .queue-table {
            width:100%;
            border-collapse:collapse;
            min-width:1000px;
        }

        .queue-table th {
            padding:10px 11px;
            text-align:left;
            background:#f8f9fb;
            border-bottom:1px solid #e4e7ec;
            color:#72798a;
            font-size:10px;
            text-transform:uppercase;
            letter-spacing:.35px;
            white-space:nowrap;
        }

        .queue-table td {
            padding:11px;
            border-bottom:1px solid #edf0f3;
            color:#26304a;
            font-size:12px;
            vertical-align:middle;
        }

        .queue-table tr:last-child td {
            border-bottom:0;
        }

        .serial-value {
            font-weight:800;
            letter-spacing:.15px;
        }

        .muted {
            color:#858998;
        }

        .status-badge,
        .route-badge {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:24px;
            padding:3px 9px;
            border-radius:999px;
            font-size:10px;
            font-weight:800;
            white-space:nowrap;
        }

        .status-ready {
            background:#e9f9ef;
            color:#087a36;
            border:1px solid #bde9cb;
        }

        .status-existing {
            background:#fff6dc;
            color:#7d5b00;
            border:1px solid #f3dd98;
        }

        .status-manual {
            background:#eaf4ff;
            color:#075a9c;
            border:1px solid #beddf6;
        }

        .status-error {
            background:#fff0ef;
            color:#a72a21;
            border:1px solid #f3cbc7;
        }

        .status-loading {
            background:#f1f3f5;
            color:#596174;
            border:1px solid #d9dde5;
        }

        .status-imported {
            background:#dff8e8;
            color:#076a30;
            border:1px solid #9edfb5;
        }

        .route-whatnot {
            background:#B9FFCC;
            color:#087A36;
            border:1px solid #72F59A;
        }

        .route-wholesale {
            background:#C7E8FF;
            color:#075A9C;
            border:1px solid #72C8FF;
        }

        .route-repair {
            background:#FFE2A8;
            color:#9A4B00;
            border:1px solid #FFBE55;
        }

        .route-harvest {
            background:#E4CCFF;
            color:#6521A8;
            border:1px solid #C58BFF;
        }

        .route-review {
            background:#FFF59A;
            color:#786000;
            border:1px solid #F4DC52;
        }

        .defect-list {
            display:flex;
            gap:4px;
            flex-wrap:wrap;
        }

        .defect-pill {
            display:inline-flex;
            padding:3px 7px;
            border-radius:5px;
            background:#f2f4f7;
            border:1px solid #e1e5eb;
            color:#485063;
            font-size:10px;
            font-weight:700;
        }

        .row-actions {
            display:flex;
            gap:6px;
            white-space:nowrap;
        }

        .row-btn {
            height:30px;
            border-radius:5px;
            padding:0 10px;
            cursor:pointer;
            font-weight:700;
            font-size:11px;
            border:1px solid #d9dde5;
            background:#fff;
            color:#26304a;
        }

        .row-btn-primary {
            border-color:#1976d2;
            color:#1976d2;
        }

        .empty-queue {
            padding:38px 20px;
            text-align:center;
            color:#858998;
            font-size:13px;
        }

        .message {
            display:none;
            margin-top:12px;
            padding:11px 12px;
            border-radius:6px;
            font-size:12px;
            line-height:1.45;
        }

        .message.show {
            display:block;
        }

        .message-success {
            background:#eef9f2;
            color:#12613b;
            border:1px solid #c9e9d5;
        }

        .message-error {
            background:#fff2f1;
            color:#9d2921;
            border:1px solid #efcfcc;
        }

        .progress-line {
            height:3px;
            background:#edf0f3;
            overflow:hidden;
            border-radius:999px;
            margin-top:12px;
            display:none;
        }

        .progress-line.active {
            display:block;
        }

        .progress-bar {
            height:100%;
            width:0%;
            background:#1976d2;
            transition:width .15s ease;
        }

        .modal-backdrop {
            position:fixed;
            inset:0;
            background:rgba(15,27,61,.45);
            display:none;
            align-items:center;
            justify-content:center;
            padding:20px;
            z-index:9999;
        }

        .modal-backdrop.show {
            display:flex;
        }

        .modal {
            width:min(780px,100%);
            max-height:90vh;
            overflow:auto;
            background:#fff;
            border-radius:9px;
            box-shadow:0 20px 60px rgba(15,27,61,.22);
        }

        .modal-head {
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:12px;
            padding:18px 20px;
            border-bottom:1px solid #e4e7ec;
        }

        .modal-title {
            font-size:16px;
            color:#0f1b3d;
            font-weight:800;
        }

        .modal-subtitle {
            margin-top:4px;
            color:#858998;
            font-size:12px;
        }

        .modal-close {
            border:0;
            background:transparent;
            color:#72798a;
            font-size:24px;
            line-height:1;
            cursor:pointer;
        }

        .modal-body {
            padding:20px;
        }

        .form-grid {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:13px;
        }

        .field-wide {
            grid-column:1/-1;
        }

        .field label {
            display:block;
            margin-bottom:6px;
            color:#596174;
            font-size:11px;
            font-weight:700;
        }

        .field input,
        .field select,
        .field textarea {
            width:100%;
            border:1px solid #d9dde5;
            border-radius:5px;
            background:#fff;
            color:#26304a;
            padding:0 10px;
            outline:none;
            font-family:inherit;
        }

        .field input,
        .field select {
            height:40px;
        }

        .field textarea {
            min-height:82px;
            resize:vertical;
            padding-top:10px;
        }

        .field input:focus,
        .field select:focus,
        .field textarea:focus {
            border-color:#1683f8;
            box-shadow:0 0 0 2px rgba(22,131,248,.10);
        }

        .modal-actions {
            display:flex;
            justify-content:flex-end;
            gap:8px;
            padding:16px 20px;
            border-top:1px solid #e4e7ec;
        }

        @media (max-width:1050px) {
            .intake-grid {
                grid-template-columns:1fr;
            }
        }

        @media (max-width:650px) {
            .summary-grid,
            .form-grid {
                grid-template-columns:1fr;
            }

            .field-wide {
                grid-column:auto;
            }

            .button-row .btn {
                flex:1;
            }
        }
    </style>
</head>

<body class="recovery-app">
<?php require __DIR__.'/../includes/header.php'; ?>

<div class="erp-content">
    <div class="intake-page">

        <div class="intake-intro">
            <h1>Recovery Intake</h1>

            <p>
                Scan continuously. Recovery Hub validates each serial,
                builds the queue, and only writes devices when you commit them.
            </p>
        </div>

        <div class="intake-grid">

            <div class="card intake-card">

                <div class="card-head">
                    <div class="card-title">Smart Intake Queue</div>

                    <div class="card-subtitle">
                        Scan one serial at a time or paste a batch separated
                        by new lines, commas, spaces, or semicolons.
                    </div>
                </div>

                <div class="card-body">

                    <textarea
                        id="serialInput"
                        class="scan-area"
                        placeholder="Scan or paste serial numbers here..."
                        autofocus
                    ></textarea>

                    <div class="scan-help">
                        Scanning does not import inventory. Devices are validated first.
                    </div>

                    <div class="button-row">
                        <button
                            id="addQueueBtn"
                            type="button"
                            class="btn btn-primary"
                        >
                            Add to Queue
                        </button>

                        <button
                            id="clearInputBtn"
                            type="button"
                            class="btn btn-secondary"
                        >
                            Clear Input
                        </button>

                        <button
                            id="clearQueueBtn"
                            type="button"
                            class="btn btn-danger"
                        >
                            Clear Queue
                        </button>
                    </div>

                    <div
                        id="progressLine"
                        class="progress-line"
                    >
                        <div
                            id="progressBar"
                            class="progress-bar"
                        ></div>
                    </div>

                    <div
                        id="mainMessage"
                        class="message"
                    ></div>

                </div>
            </div>

            <div>

                <div class="summary-grid">

                    <div class="summary-box">
                        <div
                            id="readyCount"
                            class="summary-number"
                        >0</div>

                        <div class="summary-label">
                            Ready
                        </div>
                    </div>

                    <div class="summary-box">
                        <div
                            id="existingCount"
                            class="summary-number"
                        >0</div>

                        <div class="summary-label">
                            In Recovery
                        </div>
                    </div>

                    <div class="summary-box">
                        <div
                            id="manualCount"
                            class="summary-number"
                        >0</div>

                        <div class="summary-label">
                            Manual Required
                        </div>
                    </div>

                    <div class="summary-box">
                        <div
                            id="errorCount"
                            class="summary-number"
                        >0</div>

                        <div class="summary-label">
                            Errors
                        </div>
                    </div>

                </div>

                <div class="button-row">
                    <button
                        id="importReadyBtn"
                        type="button"
                        class="btn btn-success"
                        style="width:100%;"
                        disabled
                    >
                        Import Ready Devices
                    </button>
                </div>

            </div>

        </div>

        <div class="queue-wrap">

            <div class="queue-toolbar">
                <div class="queue-title">
                    Intake Queue
                </div>

                <div
                    id="queueMeta"
                    class="queue-meta"
                >
                    0 devices
                </div>
            </div>

            <div class="queue-table-wrap">

                <table class="queue-table">

                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Serial</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Grade</th>
                            <th>Defects</th>
                            <th>Recovery Path</th>
                            <th>Location</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody id="queueBody">
                        <tr>
                            <td colspan="9">
                                <div class="empty-queue">
                                    Scan or paste serial numbers to begin.
                                </div>
                            </td>
                        </tr>
                    </tbody>

                </table>

            </div>

        </div>

    </div>
</div>


<div
    id="manualModal"
    class="modal-backdrop"
>
    <div class="modal">

        <div class="modal-head">
            <div>
                <div class="modal-title">
                    Manual Intake
                </div>

                <div class="modal-subtitle">
                    ERP confirmed that this serial does not exist.
                </div>
            </div>

            <button
                id="manualCloseBtn"
                type="button"
                class="modal-close"
                aria-label="Close"
            >
                ×
            </button>
        </div>

        <form id="manualForm">

            <div class="modal-body">

                <div class="form-grid">

                    <div class="field">
                        <label>Serial Number</label>

                        <input
                            id="manualSerial"
                            name="serial"
                            type="text"
                            readonly
                            required
                        >
                    </div>

                    <div class="field">
                        <label>Product ID</label>

                        <input
                            name="product_id"
                            type="text"
                        >
                    </div>

                    <div class="field field-wide">
                        <label>Product Description</label>

                        <input
                            name="product_description"
                            type="text"
                        >
                    </div>

                    <div class="field">
                        <label>Product Category</label>

                        <input
                            name="product_category"
                            type="text"
                        >
                    </div>

                    <div class="field">
                        <label>Product Type</label>

                        <input
                            name="product_type"
                            type="text"
                        >
                    </div>

                    <div class="field">
                        <label>Grade</label>

                        <input
                            name="grade"
                            type="text"
                        >
                    </div>

                    <div class="field">
                        <label>Recovery Path</label>

                        <select name="recovery_type">
                            <option value="REVIEW_REQUIRED">
                                Review Required
                            </option>
                            <option value="REC_WHATNOT">
                                Whatnot
                            </option>
                            <option value="REC_WHOLESALE">
                                Wholesale
                            </option>
                            <option value="REC_REPAIR">
                                Repair
                            </option>
                            <option value="REC_HARVEST">
                                Harvest
                            </option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Location</label>

                        <input
                            name="location_name"
                            type="text"
                        >
                    </div>

                    <div class="field">
                        <label>Cost Basis</label>

                        <input
                            name="cost_basis"
                            type="number"
                            min="0"
                            step="0.01"
                        >
                    </div>

                    <div class="field">
                        <label>Defect Code</label>

                        <select name="defect_code">
                            <option value="">
                                No defect selected
                            </option>

                            <?php foreach ($manualDefects as $defect): ?>
                                <option
                                    value="<?= h((string)$defect['defect_code']) ?>"
                                >
                                    <?= h((string)$defect['defect_code']) ?>
                                    —
                                    <?= h((string)$defect['description']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field field-wide">
                        <label>Defect Description / Intake Notes</label>

                        <textarea
                            name="defect_description"
                            placeholder="Optional manual defect description..."
                        ></textarea>
                    </div>

                </div>

                <div
                    id="manualMessage"
                    class="message"
                ></div>

            </div>

            <div class="modal-actions">

                <button
                    id="manualCancelBtn"
                    type="button"
                    class="btn btn-secondary"
                >
                    Cancel
                </button>

                <button
                    id="manualSaveBtn"
                    type="submit"
                    class="btn btn-primary"
                >
                    Import Manual Device
                </button>

            </div>

        </form>

    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {

    const queue = new Map();

    const serialInput = document.getElementById('serialInput');
    const addQueueBtn = document.getElementById('addQueueBtn');
    const clearInputBtn = document.getElementById('clearInputBtn');
    const clearQueueBtn = document.getElementById('clearQueueBtn');
    const importReadyBtn = document.getElementById('importReadyBtn');

    const queueBody = document.getElementById('queueBody');
    const queueMeta = document.getElementById('queueMeta');

    const readyCount = document.getElementById('readyCount');
    const existingCount = document.getElementById('existingCount');
    const manualCount = document.getElementById('manualCount');
    const errorCount = document.getElementById('errorCount');

    const mainMessage = document.getElementById('mainMessage');

    const progressLine = document.getElementById('progressLine');
    const progressBar = document.getElementById('progressBar');

    const manualModal = document.getElementById('manualModal');
    const manualForm = document.getElementById('manualForm');
    const manualSerial = document.getElementById('manualSerial');
    const manualMessage = document.getElementById('manualMessage');
    const manualCloseBtn = document.getElementById('manualCloseBtn');
    const manualCancelBtn = document.getElementById('manualCancelBtn');
    const manualSaveBtn = document.getElementById('manualSaveBtn');

    let isProcessing = false;

    function normalizeKey(value) {
        return String(value || '').trim().toUpperCase();
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showMessage(element, text, type) {
        element.className =
            'message show ' +
            (type === 'error'
                ? 'message-error'
                : 'message-success');

        element.textContent = text;
    }

    function hideMessage(element) {
        element.className = 'message';
        element.textContent = '';
    }

    function parseSerials(raw) {
        const items = String(raw || '')
            .split(/[\s,;]+/)
            .map(function (value) {
                return value.trim();
            })
            .filter(Boolean);

        const unique = [];
        const seen = new Set();

        items.forEach(function (serial) {
            const key = normalizeKey(serial);

            if (!seen.has(key)) {
                seen.add(key);
                unique.push(serial);
            }
        });

        return unique;
    }

    function routeClass(route) {
        switch (route) {
            case 'REC_WHATNOT':
                return 'route-whatnot';

            case 'REC_WHOLESALE':
                return 'route-wholesale';

            case 'REC_REPAIR':
                return 'route-repair';

            case 'REC_HARVEST':
                return 'route-harvest';

            default:
                return 'route-review';
        }
    }

    function routeLabel(route) {
        switch (route) {
            case 'REC_WHATNOT':
                return 'Whatnot';

            case 'REC_WHOLESALE':
                return 'Wholesale';

            case 'REC_REPAIR':
                return 'Repair';

            case 'REC_HARVEST':
                return 'Harvest';

            default:
                return 'Review Required';
        }
    }

    function statusInfo(status) {
        switch (status) {
            case 'READY':
                return ['Ready', 'status-ready'];

            case 'ALREADY_IN_RECOVERY':
                return ['Already in Recovery', 'status-existing'];

            case 'MANUAL_REQUIRED':
                return ['Manual Required', 'status-manual'];

            case 'ERROR':
                return ['Error', 'status-error'];

            case 'IMPORTED':
                return ['Imported', 'status-imported'];

            default:
                return ['Checking...', 'status-loading'];
        }
    }

    function renderQueue() {

        const items = Array.from(queue.values());

        if (!items.length) {
            queueBody.innerHTML = `
                <tr>
                    <td colspan="9">
                        <div class="empty-queue">
                            Scan or paste serial numbers to begin.
                        </div>
                    </td>
                </tr>
            `;
        } else {

            queueBody.innerHTML = items.map(function (item) {

                const status = statusInfo(item.status);
                const device = item.device || {};
                const existing = item.existing || {};

                const product =
                    device.product_id ||
                    existing.product_id ||
                    '—';

                const description =
                    device.product_description ||
                    existing.product_description ||
                    '';

                const category =
                    device.product_category ||
                    '—';

                const grade =
                    device.grade ||
                    '—';

                const route =
                    device.recovery_type ||
                    existing.recovery_type ||
                    '';

                const location =
                    device.location_name ||
                    '—';

                const defects =
                    Array.isArray(device.defect_codes)
                        ? device.defect_codes
                        : [];

                const defectHtml = defects.length
                    ? defects.map(function (code) {
                        return `
                            <span class="defect-pill">
                                ${escapeHtml(code)}
                            </span>
                        `;
                    }).join('')
                    : '<span class="muted">—</span>';

                let actionHtml = '';

                if (item.status === 'MANUAL_REQUIRED') {
                    actionHtml = `
                        <button
                            type="button"
                            class="row-btn row-btn-primary"
                            data-manual="${escapeHtml(item.serial)}"
                        >
                            Manual Intake
                        </button>
                    `;
                }

                if (item.status === 'ERROR') {
                    actionHtml = `
                        <button
                            type="button"
                            class="row-btn"
                            data-retry="${escapeHtml(item.serial)}"
                        >
                            Retry
                        </button>
                    `;
                }

                actionHtml += `
                    <button
                        type="button"
                        class="row-btn"
                        data-remove="${escapeHtml(item.serial)}"
                    >
                        Remove
                    </button>
                `;

                return `
                    <tr>
                        <td>
                            <span class="status-badge ${status[1]}">
                                ${escapeHtml(status[0])}
                            </span>
                        </td>

                        <td>
                            <div class="serial-value">
                                ${escapeHtml(item.serial)}
                            </div>

                            ${
                                item.message
                                    ? `<div class="muted" style="margin-top:3px;">
                                           ${escapeHtml(item.message)}
                                       </div>`
                                    : ''
                            }
                        </td>

                        <td>
                            <div>${escapeHtml(product)}</div>

                            ${
                                description
                                    ? `<div class="muted" style="margin-top:3px; max-width:310px;">
                                           ${escapeHtml(description)}
                                       </div>`
                                    : ''
                            }
                        </td>

                        <td>${escapeHtml(category)}</td>

                        <td>${escapeHtml(grade)}</td>

                        <td>
                            <div class="defect-list">
                                ${defectHtml}
                            </div>
                        </td>

                        <td>
                            ${
                                route
                                    ? `<span class="route-badge ${routeClass(route)}">
                                           ${escapeHtml(routeLabel(route))}
                                       </span>`
                                    : '<span class="muted">—</span>'
                            }
                        </td>

                        <td>${escapeHtml(location)}</td>

                        <td>
                            <div class="row-actions">
                                ${actionHtml}
                            </div>
                        </td>
                    </tr>
                `;

            }).join('');
        }

        updateCounts();
        bindRowActions();
    }

    function updateCounts() {

        let ready = 0;
        let existing = 0;
        let manual = 0;
        let errors = 0;

        queue.forEach(function (item) {

            switch (item.status) {
                case 'READY':
                    ready++;
                    break;

                case 'ALREADY_IN_RECOVERY':
                    existing++;
                    break;

                case 'MANUAL_REQUIRED':
                    manual++;
                    break;

                case 'ERROR':
                    errors++;
                    break;
            }
        });

        readyCount.textContent = ready;
        existingCount.textContent = existing;
        manualCount.textContent = manual;
        errorCount.textContent = errors;

        queueMeta.textContent =
            queue.size.toLocaleString() +
            (queue.size === 1 ? ' device' : ' devices');

        importReadyBtn.disabled =
            ready === 0 || isProcessing;

        importReadyBtn.textContent =
            ready > 0
                ? 'Import ' + ready + ' Ready Device' + (ready === 1 ? '' : 's')
                : 'Import Ready Devices';
    }

    function bindRowActions() {

        queueBody
            .querySelectorAll('[data-remove]')
            .forEach(function (button) {

                button.addEventListener('click', function () {

                    const serial = button.dataset.remove || '';
                    queue.delete(normalizeKey(serial));
                    renderQueue();
                });
            });

        queueBody
            .querySelectorAll('[data-retry]')
            .forEach(function (button) {

                button.addEventListener('click', async function () {

                    const serial = button.dataset.retry || '';
                    await lookupSerial(serial, true);
                });
            });

        queueBody
            .querySelectorAll('[data-manual]')
            .forEach(function (button) {

                button.addEventListener('click', function () {

                    openManualModal(
                        button.dataset.manual || ''
                    );
                });
            });
    }

    async function postAction(data) {

        const body = new URLSearchParams();

        Object.keys(data).forEach(function (key) {

            const value = data[key];

            body.append(
                key,
                typeof value === 'string'
                    ? value
                    : JSON.stringify(value)
            );
        });

        const response = await fetch(
            window.location.pathname,
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            }
        );

        let json;

        try {
            json = await response.json();
        } catch (error) {
            throw new Error(
                'Recovery Hub returned an invalid response.'
            );
        }

        if (!response.ok && !json.error) {
            throw new Error(
                'Request failed with HTTP ' + response.status
            );
        }

        return json;
    }

    async function lookupSerial(serial, force) {

        serial = String(serial || '').trim();

        if (!serial) {
            return;
        }

        const key = normalizeKey(serial);

        if (!force && queue.has(key)) {
            return;
        }

        queue.set(key, {
            serial: serial,
            status: 'LOADING',
            message: ''
        });

        renderQueue();

        try {

            const result = await postAction({
                action: 'lookup',
                serial: serial
            });

            const item = {
                serial: serial,
                status: result.queue_status || 'ERROR',
                message: result.error || ''
            };

            if (result.device) {
                item.device = result.device;
                item.serial =
                    result.device.serial_number || serial;
            }

            if (result.existing) {
                item.existing = result.existing;
            }

            queue.set(key, item);

        } catch (error) {

            queue.set(key, {
                serial: serial,
                status: 'ERROR',
                message:
                    error && error.message
                        ? error.message
                        : 'Unable to validate serial.'
            });
        }

        renderQueue();
    }

    async function addInputToQueue() {

        if (isProcessing) {
            return;
        }

        hideMessage(mainMessage);

        const serials = parseSerials(serialInput.value);

        if (!serials.length) {
            showMessage(
                mainMessage,
                'Scan or paste at least one serial number.',
                'error'
            );

            serialInput.focus();
            return;
        }

        isProcessing = true;
        addQueueBtn.disabled = true;
        clearQueueBtn.disabled = true;
        importReadyBtn.disabled = true;

        progressLine.classList.add('active');
        progressBar.style.width = '0%';

        let processed = 0;

        for (const serial of serials) {

            const key = normalizeKey(serial);

            if (!queue.has(key)) {
                await lookupSerial(serial, false);
            }

            processed++;

            progressBar.style.width =
                Math.round(
                    (processed / serials.length) * 100
                ) + '%';
        }

        serialInput.value = '';

        isProcessing = false;
        addQueueBtn.disabled = false;
        clearQueueBtn.disabled = false;

        updateCounts();

        setTimeout(function () {
            progressLine.classList.remove('active');
            progressBar.style.width = '0%';
        }, 350);

        serialInput.focus();
    }

    async function importReady() {

        if (isProcessing) {
            return;
        }

        const readySerials = [];

        queue.forEach(function (item) {
            if (item.status === 'READY') {
                readySerials.push(item.serial);
            }
        });

        if (!readySerials.length) {
            return;
        }

        hideMessage(mainMessage);

        isProcessing = true;
        updateCounts();

        addQueueBtn.disabled = true;
        clearQueueBtn.disabled = true;
        importReadyBtn.disabled = true;

        importReadyBtn.textContent = 'Importing...';

        try {

            const result = await postAction({
                action: 'import_ready',
                serials: readySerials
            });

            const results = Array.isArray(result.results)
                ? result.results
                : [];

            results.forEach(function (entry) {

                const key = normalizeKey(entry.serial);

                const current = queue.get(key);

                if (!current) {
                    return;
                }

                current.status = entry.status || 'ERROR';
                current.message = entry.message || '';

                queue.set(key, current);
            });

            showMessage(
                mainMessage,
                (result.imported || 0) +
                    ' device(s) imported. ' +
                    (result.skipped || 0) +
                    ' skipped. ' +
                    (result.errors || 0) +
                    ' error(s).',
                (result.errors || 0) > 0
                    ? 'error'
                    : 'success'
            );

        } catch (error) {

            showMessage(
                mainMessage,
                error && error.message
                    ? error.message
                    : 'Unable to import devices.',
                'error'
            );
        }

        isProcessing = false;
        addQueueBtn.disabled = false;
        clearQueueBtn.disabled = false;

        renderQueue();
    }

    function openManualModal(serial) {

        hideMessage(manualMessage);

        manualForm.reset();
        manualSerial.value = serial;

        manualModal.classList.add('show');
    }

    function closeManualModal() {
        manualModal.classList.remove('show');
        hideMessage(manualMessage);
    }

    async function saveManual(event) {

        event.preventDefault();

        hideMessage(manualMessage);

        manualSaveBtn.disabled = true;
        manualSaveBtn.textContent = 'Importing...';

        const data = new FormData(manualForm);
        const payload = {
            action: 'manual_import'
        };

        data.forEach(function (value, key) {
            payload[key] = value;
        });

        try {

            const result = await postAction(payload);

            if (!result.success) {
                throw new Error(
                    result.error || 'Manual Intake failed.'
                );
            }

            const key = normalizeKey(result.serial);

            const current = queue.get(key) || {
                serial: result.serial
            };

            current.status = 'IMPORTED';
            current.message = 'Manual Intake completed.';

            queue.set(key, current);

            closeManualModal();
            renderQueue();

            showMessage(
                mainMessage,
                result.serial +
                    ' was imported manually into Recovery.',
                'success'
            );

        } catch (error) {

            showMessage(
                manualMessage,
                error && error.message
                    ? error.message
                    : 'Manual Intake failed.',
                'error'
            );
        }

        manualSaveBtn.disabled = false;
        manualSaveBtn.textContent = 'Import Manual Device';
    }


    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    */

    addQueueBtn.addEventListener(
        'click',
        addInputToQueue
    );

    clearInputBtn.addEventListener(
        'click',
        function () {
            serialInput.value = '';
            serialInput.focus();
        }
    );

    clearQueueBtn.addEventListener(
        'click',
        function () {

            if (isProcessing) {
                return;
            }

            queue.clear();
            hideMessage(mainMessage);
            renderQueue();
            serialInput.focus();
        }
    );

    importReadyBtn.addEventListener(
        'click',
        importReady
    );

    manualForm.addEventListener(
        'submit',
        saveManual
    );

    manualCloseBtn.addEventListener(
        'click',
        closeManualModal
    );

    manualCancelBtn.addEventListener(
        'click',
        closeManualModal
    );

    manualModal.addEventListener(
        'click',
        function (event) {

            if (event.target === manualModal) {
                closeManualModal();
            }
        }
    );

    /*
     * Barcode-scanner behavior:
     * Enter adds the current serial/batch without inserting a newline.
     */
    serialInput.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key === 'Enter' &&
                !event.shiftKey
            ) {
                event.preventDefault();
                addInputToQueue();
            }
        }
    );

    renderQueue();
});
</script>

</body>
</html>
