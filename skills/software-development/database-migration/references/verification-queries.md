# Verification Queries for Location Code Migrations

## Warehouse Location Code Verification

### Check Bay Numbers for an Aisle

```sql
SELECT DISTINCT rack FROM location_master WHERE aisle = 'CB' ORDER BY rack;
-- Expected for CB: 01, 02, ..., 20, 21, 22, ..., 40
-- Expected for CC: 01, 02, ..., 17, 18, 19, ..., 34
-- Expected for CD: 01, 02, ..., 20, 21, 22, ..., 40
-- Expected for CE: 01, 02, ..., 20, 21, 22, ..., 40
```

### Count Total Locations per Aisle

```sql
SELECT aisle, COUNT(*) as total_rows
FROM location_master
GROUP BY aisle
ORDER BY aisle;

-- Expected per aisle:
-- CB: 400 rows (40 bays × 5 levels × 2 positions = 400)
-- CC: 340 rows (34 bays × 5 levels × 2 positions = 340)
-- CD: 400 rows
-- CE: 400 rows
```

### Verify Expected Rows Per Bay Per Aisle

```sql
-- For aisles with 20 bays per side (CB, CD, CE):
SELECT COUNT(DISTINCT CONCAT(rack, row_name, position)) as expected_loc_per_aisle
FROM location_master
WHERE aisle IN ('CB', 'CD', 'CE')
HAVING expected_loc_per_aisle = 400;

-- For CC with 17 bays per side:
SELECT COUNT(DISTINCT CONCAT(rack, row_name, position)) as expected_loc_cc
FROM location_master
WHERE aisle = 'CC'
HAVING expected_loc_cc = 340;
```

### Check for Missing Bays After Migration

```sql
-- Check if CC has exactly bays 01-34 after migration:
SELECT rack 
FROM location_master 
WHERE aisle = 'CC' 
AND rack NOT IN ('01','02','03','04','05','06','07','08','09','10',
                 '11','12','13','14','15','16','17','18','19','20',
                 '21','22','23','24','25','26','27','28','29','30',
                 '31','32','33','34')
ORDER BY rack;
-- Should return empty result after correct migration
```

### Physical Layout Verification

For the two-side physical layout:
- Left side (Side A): Bays 1-N
- Right side (Side B): Bays N+1 to 2N

For CB (N=20):
- Side A: CB01-CB20
- Side B: CB21-CB40

Both sides share the same front line, running in the same direction toward the back.