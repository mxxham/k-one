SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'outbound_items'
      AND COLUMN_NAME  = 'od_number'
);

SET @sql1 = IF(@col_exists = 0,
    'ALTER TABLE `outbound_items` ADD COLUMN `od_number` varchar(100) DEFAULT NULL COMMENT ''OD Number per item'' AFTER `notes`',
    'SELECT ''od_number column already exists — skipped'' AS info'
);
PREPARE stmt1 FROM @sql1; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

SET @col2 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'outbound_items'
      AND COLUMN_NAME  = 'so_number'
);
SET @sql2 = IF(@col2 = 0,
    'ALTER TABLE `outbound_items` ADD COLUMN `so_number` varchar(100) DEFAULT NULL COMMENT ''SO Number per item'' AFTER `od_number`',
    'SELECT ''so_number column already exists — skipped'' AS info'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

SET @col3 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'outbound_items'
      AND COLUMN_NAME  = 'destination_id'
);
SET @sql3 = IF(@col3 = 0,
    'ALTER TABLE `outbound_items` ADD COLUMN `destination_id` int(11) DEFAULT NULL COMMENT ''FK outbound_destinations'' AFTER `so_number`',
    'SELECT ''destination_id column already exists — skipped'' AS info'
);
PREPARE stmt3 FROM @sql3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;

ALTER TABLE `outbound_items`
  ADD INDEX IF NOT EXISTS `idx_ob_od_number` (`od_number`),
  ADD INDEX IF NOT EXISTS `idx_ob_so_number` (`so_number`),
  ADD INDEX IF NOT EXISTS `idx_ob_dest_id`   (`destination_id`);
