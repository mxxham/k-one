USE trianawms;

SELECT sl.id AS sl_id, sl.stock_id, sl.location_code, sl.pallet_seq,
       sl.quantity, sl.inbound_item_id,
       s.location AS stock_location, s.product_id, s.batch_number
FROM stock_locations sl
JOIN stock s ON s.id = sl.stock_id
WHERE sl.pallet_seq = 999
  AND sl.location_code != 'UNALLOCATED';

UPDATE stock s
JOIN stock_locations sl ON sl.stock_id = s.id
SET s.location = 'UNALLOCATED'
WHERE sl.pallet_seq = 999
  AND sl.location_code != 'UNALLOCATED';

UPDATE stock_locations
SET location_code = 'UNALLOCATED'
WHERE pallet_seq = 999
  AND location_code != 'UNALLOCATED';

SELECT sl.id, sl.stock_id, sl.location_code, sl.pallet_seq
FROM stock_locations sl
WHERE sl.pallet_seq = 999
  AND sl.location_code != 'UNALLOCATED';
