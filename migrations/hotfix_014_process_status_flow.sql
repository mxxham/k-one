ALTER TABLE `inbound_items`
    MODIFY COLUMN `in_process_status`
        ENUM('Dues In','Goods Received','ATP','Unserviceable','Picked')
        NOT NULL DEFAULT 'Dues In';

ALTER TABLE `outbound_items`
    ADD COLUMN `in_process_status`
        ENUM('Goods Received','ATP','Unserviceable')
        DEFAULT 'Goods Received'
        COMMENT 'Outbound item process status'
        AFTER `location`,
    ADD COLUMN `gr_plan_no`     VARCHAR(100) DEFAULT NULL COMMENT 'GR Plan Number' AFTER `in_process_status`,
    ADD COLUMN `transaction_no` VARCHAR(100) DEFAULT NULL COMMENT 'Transaction Number' AFTER `gr_plan_no`;

UPDATE `inbound_items` SET in_process_status = 'Goods Received'
WHERE in_process_status = 'Good Received';

UPDATE `inbound_items`
SET in_process_status = 'ATP'
WHERE stock_status = 'Accepted' AND in_process_status IN ('Dues In','Goods Received');

UPDATE `outbound_items` oi
JOIN `outbound_orders` oo ON oi.outbound_order_id = oo.id
SET oi.in_process_status = CASE
    WHEN oo.status IN ('Completed','Shipped','Picked') THEN 'ATP'
    ELSE 'Goods Received'
END
WHERE oi.in_process_status IS NULL OR oi.in_process_status = 'Goods Received';

SELECT in_process_status, stock_status, COUNT(*) as jumlah
FROM inbound_items GROUP BY in_process_status, stock_status;
