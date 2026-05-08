ALTER TABLE `inbound_items`
    ADD COLUMN `in_process_status`
        ENUM('Dues In','Goods Received','Unserviceable','Picked','ATP')
        NOT NULL DEFAULT 'Dues In'
        COMMENT 'In Process Status dari Excel WMS'
        AFTER `stock_status`;

ALTER TABLE `inbound_items`
    MODIFY COLUMN `stock_status`
        ENUM('Accepted','Rejected','Pending')
        NOT NULL DEFAULT 'Pending'
        COMMENT 'Stock acceptance status - auto-set from in_process_status';

UPDATE `inbound_items`
    SET in_process_status = 'Goods Received'
    WHERE stock_status = 'Accepted';

UPDATE `inbound_items`
    SET in_process_status = 'Unserviceable'
    WHERE stock_status = 'Rejected';

SELECT in_process_status, stock_status, COUNT(*) as jumlah
FROM inbound_items
GROUP BY in_process_status, stock_status;
