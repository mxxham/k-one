USE trianawms;

ALTER TABLE `stock_ledger`
    MODIFY COLUMN `transaction_type`
        ENUM('IN','OUT','ADJUSTMENT','TRANSFER','TRANSFER_IN','TRANSFER_OUT') NOT NULL;

UPDATE `location_master`
    SET `is_active` = 0
    WHERE `aisle` IN ('CF', 'CG');

INSERT IGNORE INTO `location_master`
    (location_code, aisle, rack, row_name, position, zone, is_active)
VALUES
    ('QUA_SHELL', 'QUA', 'SHELL', 'A', '01', 'Quarantine', 1);
