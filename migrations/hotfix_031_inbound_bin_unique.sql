-- Hotfix 031: Add uniqueness constraint on inbound_pickface_bin_id
-- Only applies if no duplicates exist; skips gracefully otherwise

SET @dup = (SELECT COUNT(*) FROM (
    SELECT inbound_pickface_bin_id
    FROM sku_pickface_config
    WHERE inbound_pickface_bin_id IS NOT NULL
    GROUP BY inbound_pickface_bin_id
    HAVING COUNT(*) > 1
) d);

SET @sql = IF(@dup = 0,
    'ALTER TABLE sku_pickface_config ADD UNIQUE KEY uk_inbound_bin_onlyone (inbound_pickface_bin_id)',
    'SELECT "SKIPPED: duplicates exist" AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
