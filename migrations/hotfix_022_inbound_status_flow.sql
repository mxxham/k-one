  -- Step 1: Ubah ke VARCHAR dulu                                                                                                                           
  ALTER TABLE `inbound_orders` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'Draft';                                                                 
                                                                                                                                                            
  -- Step 2: Konversi semua nilai yang tidak ada di ENUM asli                                                                                               
  UPDATE `inbound_orders` SET status = 'Dues In'                                                                                                            
  WHERE status NOT IN ('Draft','Dues In','Good Received','Goods Received','Unserviceable','Picked','ATP','Completed','Cancelled');                          
                                                                                                                                                            
  -- Step 3: ALTER ke ENUM asli                                                                                                                             
  ALTER TABLE `inbound_orders`
  MODIFY COLUMN `status`
  ENUM('Draft','Dues In','Good Received','Goods Received','Unserviceable','Picked','ATP','Completed','Cancelled')
  NOT NULL DEFAULT 'Draft';