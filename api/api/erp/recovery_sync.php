<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/graphql_client.php';
require_once __DIR__ . '/../../config/database.php';

function respond(array $data, int $status = 200): never
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function numericOrNullSync(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (float) $value : null;
}

function parseDefectCodesSync(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    $codes = is_array($decoded)
        ? $decoded
        : explode(',', $raw);

    $result = [];

    foreach ($codes as $code) {
        $code = strtoupper(trim((string) $code));

        if ($code !== '') {
            $result[$code] = $code;
        }
    }

    return array_values($result);
}

$dryRun = !isset($_GET['commit']) || $_GET['commit'] !== '1';

$limit = isset($_GET['limit'])
    ? max(1, min(100, (int) $_GET['limit']))
    : 5;

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$query = <<<'GRAPHQL'
query RecoveryDevices($first: Int!, $page: Int!) {
    devices(
        first: $first
        page: $page
        where: {
            column: "recovery_type_id"
            operator: IS_NOT_NULL
        }
    ) {
        data {
            id
            serial_number

            status
            recovery_type_id

            recoveryType {
                id
                recovery_type
                description
                default_disposition
                is_visible
            }

            real_cost
            processing_cost

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

   			 category {
    			    name
    			    product_type
    			}
		}


            product {
                product_unique_id
                description

                category {
                    name
                    product_type
                }
            }
        }

        paginatorInfo {
            currentPage
            lastPage
            perPage
            total
            hasMorePages
        }
    }
}
GRAPHQL;

try {
    $client = new ERPGraphQLClient();

    $result = $client->query($query, [
        'first' => $limit,
        'page'  => $page,
    ]);
} catch (Throwable $e) {
    respond([
        'success' => false,
        'error' => 'Unable to communicate with ERP.',
        'details' => $e->getMessage(),
    ], 500);
}

if (!empty($result['curl_error'])) {
    respond([
        'success' => false,
        'error' => 'ERP connection error.',
        'details' => $result['curl_error'],
    ], 502);
}

if (
    ($result['http_code'] ?? 0) < 200 ||
    ($result['http_code'] ?? 0) >= 300
) {
    respond([
        'success' => false,
        'error' => 'ERP returned an unexpected HTTP status.',
        'http_code' => $result['http_code'] ?? 0,
    ], 502);
}

$response = $result['response'] ?? null;

if (!is_array($response)) {
    respond([
        'success' => false,
        'error' => 'ERP returned invalid JSON.',
    ], 502);
}

if (!empty($response['errors'])) {
    respond([
        'success' => false,
        'error' => 'ERP GraphQL error.',
        'details' => array_column($response['errors'], 'message'),
    ], 502);
}

$devices = $response['data']['devices']['data'] ?? [];
$paginator = $response['data']['devices']['paginatorInfo'] ?? [];

if (!is_array($devices)) {
    $devices = [];
}

$allowedPaths = [
    'REC_WHATNOT',
    'REC_WHOLESALE',
    'REC_REPAIR',
    'REC_HARVEST',
];

$stats = [
    'received_from_erp' => count($devices),
    'eligible' => 0,
    'inserted' => 0,
    'updated' => 0,
    'skipped' => 0,
    'defects_synced' => 0,
];

$preview = [];
$errors = [];

$upsertDevice = $pdo->prepare(
    '
    INSERT INTO recovery_devices (
        erp_device_id,
        serial_number,
        product_id,
        product_description,
        product_category,
        product_type,
        grade,
        recovery_type,
        recovery_status,
        erp_location_id,
        location_name,
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
        :recovery_type,
        :recovery_status,
        :erp_location_id,
        :location_name,
        :cost_basis,
        NULL,
        NOW()
    )
    ON DUPLICATE KEY UPDATE
        product_id = VALUES(product_id),
        product_description = VALUES(product_description),
        product_category = VALUES(product_category),
        product_type = VALUES(product_type),
        grade = VALUES(grade),
        recovery_type = VALUES(recovery_type),
        recovery_status = VALUES(recovery_status),
        erp_location_id = VALUES(erp_location_id),
        location_name = VALUES(location_name),
        cost_basis = VALUES(cost_basis),
        erp_synced_at = NOW()
    '
);

$findRecoveryDevice = $pdo->prepare(
    'SELECT id FROM recovery_devices WHERE erp_device_id = :erp_device_id LIMIT 1'
);

$existsBefore = $pdo->prepare(
    'SELECT id FROM recovery_devices WHERE erp_device_id = :erp_device_id LIMIT 1'
);

$deleteDefects = $pdo->prepare(
    '
    DELETE FROM recovery_device_defects
    WHERE recovery_device_id = :recovery_device_id
      AND defect_source IN ("initial", "determined")
    '
);

$insertDefect = $pdo->prepare(
    '
    INSERT INTO recovery_device_defects (
        recovery_device_id,
        defect_code,
        defect_description,
        defect_source
    )
    SELECT
        :recovery_device_id,
        :defect_code,
        COALESCE(
            NULLIF(TRIM(dl.short_definition_en), ""),
            NULLIF(TRIM(dl.definition_en), ""),
            NULLIF(TRIM(dl.erp_description), ""),
            :defect_code_fallback
        ),
        :defect_source
    FROM (SELECT 1) AS x
    LEFT JOIN defect_library dl
        ON dl.defect_code = :defect_code_lookup
    '
);

foreach ($devices as $device) {
    try {
        $erpDeviceId = isset($device['id'])
            ? (int) $device['id']
            : 0;

        $serial = trim((string) ($device['serial_number'] ?? ''));

        $recovery = is_array($device['recoveryType'] ?? null)
            ? $device['recoveryType']
            : [];

        $recoveryPath = strtoupper(
            trim((string) ($recovery['recovery_type'] ?? ''))
        );

        if (
            $erpDeviceId <= 0 ||
            $serial === '' ||
            !in_array($recoveryPath, $allowedPaths, true)
        ) {
            $stats['skipped']++;

            $errors[] = [
                'erp_device_id' => $erpDeviceId ?: null,
                'serial_number' => $serial ?: null,
                'reason' => 'Device does not contain a valid MVP Recovery Path.',
                'recovery_type' => $recoveryPath ?: null,
            ];

            continue;
        }

	$determinedProduct = is_array($device['determinedProduct'] ?? null)
    ? $device['determinedProduct']
    : [];

$originalProduct = is_array($device['product'] ?? null)
    ? $device['product']
    : [];

	/*
	|--------------------------------------------------------------------------
	| Recovery Product
	|--------------------------------------------------------------------------
	|
	| Once diagnostics have determined the actual product, Recovery Hub must
	| use determinedProduct as the authoritative product.
	|
	| product is retained only as a fallback for devices where no determined
	| product exists.
	|
	*/

	$product = !empty($determinedProduct)
    ? $determinedProduct
    : $originalProduct;

        $category = is_array($product['category'] ?? null)
            ? $product['category']
            : [];

        $location = is_array($device['location'] ?? null)
            ? $device['location']
            : [];

        $grade =
            $device['finale_grade']
            ?? $device['determined_overall_grade']
            ?? $device['initial_overall_grade']
            ?? null;

        $initialDefects = parseDefectCodesSync(
            $device['initial_defect_codes'] ?? null
        );

        $determinedDefects = parseDefectCodesSync(
            $device['determined_defect_codes'] ?? null
        );

        $stats['eligible']++;

        $preview[] = [
            'erp_device_id' => $erpDeviceId,
            'serial_number' => $serial,
            'product_id' => $product['product_unique_id'] ?? null,
            'category' => $category['name'] ?? null,
            'product_type' => $category['product_type'] ?? null,
            'grade' => $grade,
            'recovery_type' => $recoveryPath,
            'location_id' => isset($location['id'])
                ? (int) $location['id']
                : null,
            'location' => $location['name'] ?? null,
            'cost_basis' => numericOrNullSync($device['real_cost'] ?? null),
            'initial_defects' => $initialDefects,
            'determined_defects' => $determinedDefects,
        ];

        if ($dryRun) {
            continue;
        }

        $pdo->beginTransaction();

        $existsBefore->execute([
            ':erp_device_id' => $erpDeviceId,
        ]);

        $alreadyExists = (bool) $existsBefore->fetchColumn();

        $upsertDevice->execute([
            ':erp_device_id' => $erpDeviceId,
            ':serial_number' => $serial,
            ':product_id' => $product['product_unique_id'] ?? null,
            ':product_description' => $product['description'] ?? null,
            ':product_category' => $category['name'] ?? null,
            ':product_type' => $category['product_type'] ?? null,
            ':grade' => $grade,
            ':recovery_type' => $recoveryPath,
            ':recovery_status' => 'OPEN',
            ':erp_location_id' => isset($location['id'])
                ? (int) $location['id']
                : null,
            ':location_name' => $location['name'] ?? null,
            ':cost_basis' => numericOrNullSync($device['real_cost'] ?? null),
        ]);

        $findRecoveryDevice->execute([
            ':erp_device_id' => $erpDeviceId,
        ]);

        $recoveryDeviceId = (int) $findRecoveryDevice->fetchColumn();

        if ($recoveryDeviceId <= 0) {
            throw new RuntimeException(
                "Unable to locate Recovery Hub device after upsert."
            );
        }

        $deleteDefects->execute([
            ':recovery_device_id' => $recoveryDeviceId,
        ]);

        foreach ($initialDefects as $code) {
            $insertDefect->execute([
                ':recovery_device_id' => $recoveryDeviceId,
                ':defect_code' => $code,
                ':defect_code_fallback' => $code,
                ':defect_source' => 'initial',
                ':defect_code_lookup' => $code,
            ]);

            $stats['defects_synced']++;
        }

        foreach ($determinedDefects as $code) {
            $insertDefect->execute([
                ':recovery_device_id' => $recoveryDeviceId,
                ':defect_code' => $code,
                ':defect_code_fallback' => $code,
                ':defect_source' => 'determined',
                ':defect_code_lookup' => $code,
            ]);

            $stats['defects_synced']++;
        }

        $pdo->commit();

        if ($alreadyExists) {
            $stats['updated']++;
        } else {
            $stats['inserted']++;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $stats['skipped']++;

        $errors[] = [
            'erp_device_id' => $device['id'] ?? null,
            'serial_number' => $device['serial_number'] ?? null,
            'reason' => $e->getMessage(),
        ];
    }
}

respond([
    'success' => true,
    'mode' => $dryRun ? 'DRY_RUN' : 'COMMIT',
    'stats' => $stats,
    'pagination' => $paginator,
    'devices' => $preview,
    'errors' => $errors,
]);
