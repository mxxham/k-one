-- Expand check_scans.scan_type to support Location, Qty, Lot, Expiry checks
ALTER TABLE check_scans
    MODIFY COLUMN scan_type ENUM('LPN','SKU','LOCATION','QTY','LOT','EXPIRY') NOT NULL;
