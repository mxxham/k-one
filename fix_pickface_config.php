<?php
/**
 * One-time reconciliation: regenerate sku_pickface_config from actual physical stock.
 *
 * Run once against sanchaya. Creates a backup table first.
 *
 * Usage: php fix_pickface_config.php
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/PickfaceSplitter.php';

$db = db();

// ── Step 0: Backup ──────────────────────────────────────────────────────────
$db->exec("DROP TABLE IF EXISTS sku_pickface_config_backup_pre_reconcile");
$db->exec("CREATE TABLE sku_pickface_config_backup_pre_reconcile AS SELECT * FROM sku_pickface_config");
$backupCount = $db->query("SELECT COUNT(*) FROM sku_pickface_config_backup_pre_reconcile")->fetchColumn();
echo "Backup created: sku_pickface_config_backup_pre_reconcile ({$backupCount} rows)\n\n";

// ── Step 1: Manual-review SKUs (skip these) ────────────────────────────────
$manualReviewSkus = [13388, 13420, 13501];

// ── Step 2: Reconcile each SKU ─────────────────────────────────────────────
$skus = $db->query("SELECT sku_id, pickface_bin_id AS old_bin_id FROM sku_pickface_config")->fetchAll();

// Dry-run mode: set to false to actually execute updates
$DRY_RUN = false;

$report = [
    'reassigned'   => [],
    'empty_claimed' => [],
    'manual_review' => [],
    'unchanged'     => [],
];

foreach ($skus as $row) {
    $skuId = (int)$row['sku_id'];

    // Skip manual-review SKUs
    if (in_array($skuId, $manualReviewSkus, true)) {
        $report['manual_review'][] = $skuId;
        continue;
    }

    // Find every A-level bin currently holding real, available stock of this SKU
    $stmt = $db->prepare(
        "SELECT lm.id AS location_id, lm.location_code, SUM(s.quantity) AS total_qty
         FROM stock s
         JOIN location_master lm ON lm.location_code = s.location
         WHERE s.product_id = ?
           AND s.stock_status = 'Available'
           AND (s.hold_status IS NULL OR s.hold_status = 'available')
           AND s.quantity > 0
           AND lm.location_code REGEXP '[A-Z]{2}[0-9]{2}A[0-9]{2}$'
           AND lm.is_active = 1
         GROUP BY lm.id, lm.location_code
         ORDER BY total_qty DESC"
    );
    $stmt->execute([$skuId]);
    $candidates = $stmt->fetchAll();

    if (count($candidates) === 0) {
        // No A-level stock at all — claim a fresh empty A01 bin
        $prodStmt = $db->prepare("SELECT uom_type, uom_per_pallet FROM products WHERE id = ?");
        $prodStmt->execute([$skuId]);
        $product = $prodStmt->fetch();
        if (!$product) {
            $report['manual_review'][] = $skuId;
            continue;
        }
        $uomPerPallet = max((int)$product['uom_per_pallet'], 1);

        // Compute pickface_min from UOM type
        $pickfaceMin = match ($product['uom_type']) {
            'Carton', 'CAR' => 10,
            'Drum'          => 1,
            'Pail'          => 5,
            default         => 1,
        };

        $emptyBinStmt = $db->prepare(
            "SELECT lm.id AS location_id, lm.location_code
             FROM location_master lm
             WHERE lm.location_code REGEXP '[A-Z]{2}[0-9]{2}A01$'
               AND lm.is_active = 1
               AND lm.id NOT IN (SELECT pickface_bin_id FROM sku_pickface_config)
               AND NOT EXISTS (
                   SELECT 1 FROM stock s WHERE s.location = lm.location_code AND s.quantity > 0
               )
             ORDER BY lm.location_code ASC
             LIMIT 1"
        );
        $emptyBinStmt->execute();
        $emptyBin = $emptyBinStmt->fetch();

        if ($emptyBin) {
            // UPDATE (not INSERT) — SKU already has a config row
            if ($DRY_RUN) {
                echo "  [DRY-RUN] UPDATE sku_pickface_config SET pickface_bin_id = {$emptyBin['location_id']}, pickface_max = {$uomPerPallet}, pickface_min = {$pickfaceMin} WHERE sku_id = {$skuId}; -- claim empty bin {$emptyBin['location_code']} (uom={$product['uom_type']}, min={$pickfaceMin})\n";
            } else {
                $db->prepare(
                    "UPDATE sku_pickface_config SET pickface_bin_id = ?, pickface_max = ?, pickface_min = ? WHERE sku_id = ?"
                )->execute([$emptyBin['location_id'], $uomPerPallet, $pickfaceMin, $skuId]);
            }
            $report['empty_claimed'][] = [$skuId, $emptyBin['location_code']];
        } else {
            $report['manual_review'][] = $skuId;
        }
        continue;
    }

    $best = $candidates[0]; // highest quantity wins

    if ((int)$best['location_id'] === (int)$row['old_bin_id']) {
        $report['unchanged'][] = $skuId;
        continue;
    }

    // Reassign to the bin where the stock actually is
    if ($DRY_RUN) {
        echo "  [DRY-RUN] UPDATE sku_pickface_config SET pickface_bin_id = {$best['location_id']} WHERE sku_id = {$skuId}; -- stock at {$best['location_code']} ({$best['total_qty']})\n";
    } else {
        $db->prepare(
            "UPDATE sku_pickface_config SET pickface_bin_id = ? WHERE sku_id = ?"
        )->execute([(int)$best['location_id'], $skuId]);
    }

    $report['reassigned'][] = [
        $skuId,
        $row['old_bin_id'],
        $best['location_code'],
        (float)$best['total_qty'],
    ];
}

// ── Step 3: Print report ───────────────────────────────────────────────────
echo "=== Reconciliation complete ===\n";
echo "Reassigned:        " . count($report['reassigned']) . "\n";
echo "Empty bins claimed: " . count($report['empty_claimed']) . "\n";
echo "Unchanged:         " . count($report['unchanged']) . "\n";
echo "Manual review:     " . count($report['manual_review']) . " — " . implode(', ', $report['manual_review']) . "\n";

if (!empty($report['reassigned'])) {
    echo "\n--- Reassigned SKUs ---\n";
    echo "| SKU | Old Bin ID | New Bin | Stock Qty |\n";
    echo "|-----|-----------|---------|-----------|\n";
    foreach ($report['reassigned'] as $r) {
        echo "| #{$r[0]} | {$r[1]} | {$r[2]} | {$r[3]} |\n";
    }
}

if (!empty($report['empty_claimed'])) {
    echo "\n--- Empty bins claimed ---\n";
    foreach ($report['empty_claimed'] as $r) {
        echo "  SKU #{$r[0]} → {$r[1]}\n";
    }
}

echo "\nTo rollback: mysql -u root sanchaya -e \"DROP TABLE IF EXISTS sku_pickface_config; RENAME TABLE sku_pickface_config_backup_pre_reconcile TO sku_pickface_config;\"\n";
