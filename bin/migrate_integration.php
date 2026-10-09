<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../config/database.php';

// Additive, repeatable migration. Preserve existing data and historical timestamps.
$changes = [
    'recovery_reroutes' => [
        'request_key' => 'VARCHAR(100) NULL', 'confirmation_payload' => 'LONGTEXT NULL',
        'erp_receipt' => 'LONGTEXT NULL', 'last_error' => 'VARCHAR(500) NULL',
    ],
    'recovery_devices' => [
        'erp_available' => 'TINYINT(1) NULL', 'erp_updated_at' => 'DATETIME NULL',
        'first_observed_at' => 'DATETIME NULL',
    ],
    'recovery_outcomes' => [
        'request_key' => 'VARCHAR(100) NULL', 'gross_recovery' => 'DECIMAL(12,2) NULL',
        'direct_costs' => 'DECIMAL(12,2) NULL', 'net_recovery' => 'DECIMAL(12,2) NULL',
        'currency' => 'VARCHAR(3) NULL', 'performed_by_user_id' => 'BIGINT UNSIGNED NULL',
        'performed_by_user_name' => 'VARCHAR(191) NULL', 'final_disposition' => 'VARCHAR(500) NULL',
        'initial_recovery_type' => 'VARCHAR(50) NULL', 'cost_basis_snapshot' => 'DECIMAL(12,2) NULL',
        'source_type_snapshot' => 'VARCHAR(100) NULL', 'source_reference_snapshot' => 'VARCHAR(191) NULL',
        'vendor_name_snapshot' => 'VARCHAR(191) NULL',
    ],
];
foreach ($changes as $table => $columns) {
    $existing = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($columns as $column => $definition) {
        if (!in_array($column, $existing, true)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo "Added $table.$column\n";
        }
    }
}
$pdo->exec('CREATE TABLE IF NOT EXISTS recovery_sync_state (
    name VARCHAR(100) PRIMARY KEY, value TEXT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec('CREATE TABLE IF NOT EXISTS recovery_exit_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, request_key VARCHAR(100) NOT NULL UNIQUE,
    recovery_device_id BIGINT UNSIGNED NOT NULL, operation VARCHAR(30) NOT NULL,
    payload LONGTEXT NOT NULL, receipt LONGTEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY device_operation (recovery_device_id,operation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$indexes = $pdo->query("SHOW INDEX FROM recovery_outcomes WHERE Key_name='uq_outcome_request'")->fetchAll();
if (!$indexes) { $pdo->exec('ALTER TABLE recovery_outcomes ADD UNIQUE KEY uq_outcome_request (request_key)'); }
$pdo->exec('CREATE TABLE IF NOT EXISTS recovery_commerce_listings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, recovery_device_id BIGINT UNSIGNED NOT NULL,
    listing_key VARCHAR(70) NOT NULL UNIQUE, provider VARCHAR(20) NOT NULL, payload LONGTEXT NOT NULL,
    product_id VARCHAR(191) NULL, variant_id VARCHAR(191) NULL, state VARCHAR(30) NOT NULL DEFAULT \'PENDING\',
    exit_payload LONGTEXT NULL, receipt LONGTEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY device_provider (recovery_device_id,provider), UNIQUE KEY variant_provider (variant_id,provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec('CREATE TABLE IF NOT EXISTS recovery_commerce_sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, provider VARCHAR(20) NOT NULL,
    order_id VARCHAR(100) NOT NULL, line_id VARCHAR(100) NOT NULL, variant_id VARCHAR(191) NOT NULL,
    gross_recovery DECIMAL(12,2) NULL, status VARCHAR(30) NOT NULL, received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY sale_line (provider,order_id,line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
echo "Recovery integration schema ready.\n";
