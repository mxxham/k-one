ALTER TABLE `inbound_orders`
    MODIFY COLUMN `status`
        ENUM('Draft','Dues In','Good Received','Unserviceable','Picked','ATP','Completed','Cancelled')
        NOT NULL DEFAULT 'Draft';

UPDATE `inbound_orders` SET status = 'Draft'
WHERE status = 'Dues In';

SELECT status, COUNT(*) as jumlah FROM inbound_orders GROUP BY status;
