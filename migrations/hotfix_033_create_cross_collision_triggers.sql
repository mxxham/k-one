-- =============================================================================
-- hotfix_033: Recreate cross-column collision guards (hotfix_027 migration ordering fix)
-- =============================================================================
-- hotfix_027 ran before hotfix_029 (which adds inbound_pickface_bin_id), so the
-- triggers failed to create in fresh DBs. This migration recreates them now that
-- the column exists. Idempotent — safe to run on DBs where hotfix_027 already succeeded.

DROP TRIGGER IF EXISTS trg_pickface_no_cross_collision_ins;
DROP TRIGGER IF EXISTS trg_pickface_no_cross_collision_upd;

DELIMITER $$

CREATE TRIGGER trg_pickface_no_cross_collision_ins
BEFORE INSERT ON sku_pickface_config
FOR EACH ROW
BEGIN
    DECLARE conflict_sku INT DEFAULT NULL;

    -- Check if new outbound bin is used as inbound by another SKU
    SELECT sku_id INTO conflict_sku
    FROM sku_pickface_config
    WHERE inbound_pickface_bin_id = NEW.pickface_bin_id
      AND sku_id != NEW.sku_id
    LIMIT 1;

    IF conflict_sku IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cross-column collision: outbound bin is another SKU inbound bin';
    END IF;

    -- Check if new inbound bin is used as outbound by another SKU
    SET conflict_sku = NULL;
    SELECT sku_id INTO conflict_sku
    FROM sku_pickface_config
    WHERE pickface_bin_id = NEW.inbound_pickface_bin_id
      AND sku_id != NEW.sku_id
    LIMIT 1;

    IF conflict_sku IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cross-column collision: inbound bin is another SKU outbound bin';
    END IF;
END$$

CREATE TRIGGER trg_pickface_no_cross_collision_upd
BEFORE UPDATE ON sku_pickface_config
FOR EACH ROW
BEGIN
    DECLARE conflict_sku INT DEFAULT NULL;

    -- Check if updated outbound bin is used as inbound by another SKU
    SELECT sku_id INTO conflict_sku
    FROM sku_pickface_config
    WHERE inbound_pickface_bin_id = NEW.pickface_bin_id
      AND sku_id != NEW.sku_id
    LIMIT 1;

    IF conflict_sku IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cross-column collision: outbound bin is another SKU inbound bin';
    END IF;

    -- Check if updated inbound bin is used as outbound by another SKU
    SET conflict_sku = NULL;
    SELECT sku_id INTO conflict_sku
    FROM sku_pickface_config
    WHERE pickface_bin_id = NEW.inbound_pickface_bin_id
      AND sku_id != NEW.sku_id
    LIMIT 1;

    IF conflict_sku IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cross-column collision: inbound bin is another SKU outbound bin';
    END IF;
END$$

DELIMITER ;
