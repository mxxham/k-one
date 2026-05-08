-- Migration: Add "Receiving" to inbound_orders.status ENUM
-- Hotfix 023 - Inbound Receiving Status Flow

-- Step 1: Ubah ke VARCHAR dulu
ALTER TABLE `inbound_orders` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'Draft';

-- Step 2: ALTER ke ENUM baru dengan "Receiving"
ALTER TABLE `inbound_orders`
MODIFY COLUMN `status`
ENUM('Draft','Dues In','Receiving','Good Received','Goods Received','Unserviceable','Picked','ATP','Completed','Cancelled')
NOT NULL DEFAULT 'Draft';
