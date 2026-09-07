-- Migration 025: Consolidate pick_face_targets into sku_pickface_config
-- Date: 2026-09-01

-- Step 1: Populate sku_pickface_config from pick_face_targets
INSERT IGNORE INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_min, pickface_max)
SELECT t.product_id, t.location_id, t.min_qty, t.max_qty
FROM pick_face_targets t
WHERE t.product_id IN (SELECT id FROM products)
  AND t.location_id IN (SELECT id FROM location_master);

-- Step 2: Allow NULL on pickface_bin_id for explicit unassign
ALTER TABLE sku_pickface_config
    MODIFY COLUMN pickface_bin_id INT(11) NULL DEFAULT NULL;

-- Step 3: Drop deprecated table
DROP TABLE IF EXISTS pick_face_targets;
