-- =============================================================================
-- hotfix_026: Add unique constraint on sku_pickface_config.pickface_bin_id
-- =============================================================================
-- Ensures each pickface bin can only be assigned to one SKU.
-- Pre-checks for duplicate rows before attempting the ALTER to avoid hard failure.

-- Step 1: Check for existing duplicate pickface_bin_id values
SET @dup_count = (
    SELECT COUNT(*) FROM (
        SELECT pickface_bin_id
        FROM sku_pickface_config
        GROUP BY pickface_bin_id
        HAVING COUNT(*) > 1
    ) AS dups
);

-- Step 2: Only add constraint if no duplicates exist
SET @sql = IF(@dup_count = 0,
    'ALTER TABLE `sku_pickface_config` ADD UNIQUE KEY `uk_pickface_bin_onlyone` (`pickface_bin_id`)',
    'SELECT "SKIPPED: duplicate pickface_bin_id rows exist — constraint not added" AS status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
