<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../../config/database.php';

/*
|--------------------------------------------------------------------------
| ERP Recovery Locations Sync
|--------------------------------------------------------------------------
|
| ERP is the source of truth for Recovery physical locations.
| This script synchronizes the ERP Recovery locations into the local
| recovery_locations cache used by Recovery Hub.
|
| It intentionally does NOT include RMA locations.
|
*/

const ERP_RECOVERY_LOCATIONS_URL =
    'https://creativeerpdev.com/erp_api/src/public/api/recovery/locations';


function respondLocations(array $data, int $status = 200): never
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Fetch Recovery Locations From ERP
|--------------------------------------------------------------------------
*/

$curl = curl_init();

curl_setopt_array(
    $curl,
    [
        CURLOPT_URL => ERP_RECOVERY_LOCATIONS_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-Recovery-Key: ' . ERP_API_KEY,
        ],
    ]
);

$responseBody = curl_exec($curl);

$curlError =
    $responseBody === false
        ? curl_error($curl)
        : '';

$httpCode =
    (int) curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

curl_close($curl);


if ($curlError !== '') {

    respondLocations(
        [
            'success' => false,
            'error' => 'Unable to communicate with ERP.',
            'details' => $curlError,
        ],
        502
    );
}


if (
    $httpCode < 200
    ||
    $httpCode >= 300
) {

    respondLocations(
        [
            'success' => false,
            'error' => 'ERP returned an unexpected HTTP status.',
            'http_code' => $httpCode,
            'response' => $responseBody,
        ],
        502
    );
}


$data =
    json_decode(
        (string) $responseBody,
        true
    );


if (!is_array($data)) {

    respondLocations(
        [
            'success' => false,
            'error' => 'ERP returned invalid JSON.',
        ],
        502
    );
}


if (
    ($data['success'] ?? false) !== true
    ||
    !isset($data['locations'])
    ||
    !is_array($data['locations'])
) {

    respondLocations(
        [
            'success' => false,
            'error' => 'ERP returned an unexpected locations payload.',
            'erp_response' => $data,
        ],
        502
    );
}


$locations =
    $data['locations'];


/*
|--------------------------------------------------------------------------
| Safety Check
|--------------------------------------------------------------------------
|
| Recovery currently has ERP-managed locations.
| An empty payload is treated as an error so a temporary ERP/API problem
| cannot accidentally deactivate the entire local location cache.
|
*/

if (count($locations) === 0) {

    respondLocations(
        [
            'success' => false,
            'error' => 'ERP returned zero Recovery locations. Sync aborted.',
        ],
        409
    );
}


/*
|--------------------------------------------------------------------------
| Load Existing Local ERP Location IDs
|--------------------------------------------------------------------------
*/

$existingRows =
    $pdo
        ->query(
            '
            SELECT erp_location_id
            FROM recovery_locations
            '
        )
        ->fetchAll(PDO::FETCH_COLUMN);


$existingIds = [];

foreach ($existingRows as $existingId) {

    $existingIds[
        (int) $existingId
    ] = true;
}


/*
|--------------------------------------------------------------------------
| Prepare Upsert
|--------------------------------------------------------------------------
*/

$upsert =
    $pdo->prepare(
        '
        INSERT INTO recovery_locations (
            erp_location_id,
            location_name,
            location_status,
            purpose,
            recovery_area,
            is_visible,
            is_active,
            erp_updated_at,
            last_synced_at,
            created_at,
            updated_at
        ) VALUES (
            :erp_location_id,
            :location_name,
            :location_status,
            :purpose,
            :recovery_area,
            :is_visible,
            1,
            :erp_updated_at,
            NOW(),
            NOW(),
            NOW()
        )

        ON DUPLICATE KEY UPDATE

            location_name =
                VALUES(location_name),

            location_status =
                VALUES(location_status),

            purpose =
                VALUES(purpose),

            recovery_area =
                VALUES(recovery_area),

            is_visible =
                VALUES(is_visible),

            is_active =
                1,

            erp_updated_at =
                VALUES(erp_updated_at),

            last_synced_at =
                NOW(),

            updated_at =
                NOW()
        '
    );


$stats = [
    'received_from_erp' => count($locations),
    'inserted' => 0,
    'updated' => 0,
    'deactivated' => 0,
    'skipped' => 0,
];

$syncedIds = [];


/*
|--------------------------------------------------------------------------
| Synchronize
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();


    foreach ($locations as $location) {

        if (!is_array($location)) {

            $stats['skipped']++;
            continue;
        }


        $erpLocationId =
            isset($location['erp_location_id'])
                ? (int) $location['erp_location_id']
                : 0;

        $locationName =
            trim(
                (string) (
                    $location['location_name']
                    ?? ''
                )
            );

        $locationStatus =
            trim(
                (string) (
                    $location['location_status']
                    ?? ''
                )
            );

        $purpose =
            isset($location['purpose'])
                ? trim((string) $location['purpose'])
                : null;

        $recoveryArea =
            trim(
                (string) (
                    $location['recovery_area']
                    ?? ''
                )
            );

        $isVisible =
            !empty(
                $location['is_visible']
            )
                ? 1
                : 0;

        $erpUpdatedAt =
            !empty(
                $location['updated_at']
            )
                ? (string) $location['updated_at']
                : null;


        /*
        |--------------------------------------------------------------------------
        | Validate Required Values
        |--------------------------------------------------------------------------
        */

        if (
            $erpLocationId <= 0
            ||
            $locationName === ''
            ||
            $recoveryArea === ''
        ) {

            $stats['skipped']++;
            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | Extra Recovery Area Protection
        |--------------------------------------------------------------------------
        */

        $allowedAreas = [
            'RETAIL_ELIGIBLE',
            'WHOLESALE',
            'REPAIR',
            'HARVEST',
        ];


        if (
            !in_array(
                $recoveryArea,
                $allowedAreas,
                true
            )
        ) {

            $stats['skipped']++;
            continue;
        }


        $upsert->execute(
            [
                ':erp_location_id' =>
                    $erpLocationId,

                ':location_name' =>
                    $locationName,

                ':location_status' =>
                    $locationStatus !== ''
                        ? $locationStatus
                        : 'free',

                ':purpose' =>
                    $purpose,

                ':recovery_area' =>
                    $recoveryArea,

                ':is_visible' =>
                    $isVisible,

                ':erp_updated_at' =>
                    $erpUpdatedAt,
            ]
        );


        $syncedIds[] =
            $erpLocationId;


        if (
            isset(
                $existingIds[
                    $erpLocationId
                ]
            )
        ) {

            $stats['updated']++;

        } else {

            $stats['inserted']++;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Deactivate Missing ERP Locations
    |--------------------------------------------------------------------------
    |
    | We do not delete local records because Recovery history may reference
    | them. A location missing from the current ERP payload becomes inactive.
    |
    */

    $syncedIds =
        array_values(
            array_unique(
                $syncedIds
            )
        );


    if (count($syncedIds) === 0) {

        throw new RuntimeException(
            'No valid Recovery locations were available to synchronize.'
        );
    }


    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($syncedIds),
                '?'
            )
        );


    $deactivate =
        $pdo->prepare(
            "
            UPDATE recovery_locations
            SET
                is_active = 0,
                last_synced_at = NOW(),
                updated_at = NOW()
            WHERE
                is_active = 1
                AND erp_location_id NOT IN ($placeholders)
            "
        );


    $deactivate->execute(
        $syncedIds
    );


    $stats['deactivated'] =
        $deactivate->rowCount();


    $pdo->commit();


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {

        $pdo->rollBack();
    }


    respondLocations(
        [
            'success' => false,
            'error' => 'Unable to synchronize Recovery locations.',
            'details' => $e->getMessage(),
        ],
        500
    );
}


/*
|--------------------------------------------------------------------------
| Success
|--------------------------------------------------------------------------
*/

respondLocations(
    [
        'success' => true,
        'message' => 'Recovery locations synchronized successfully.',
        'stats' => $stats,
        'erp_count' => count($locations),
        'synced_erp_location_ids' => $syncedIds,
        'synced_at' => date('Y-m-d H:i:s'),
    ],
    200
);
