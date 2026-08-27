-- =============================================================================
-- hotfix_026_v2_inbound_putaway.sql
-- Adds stock_locations.pallet_function (PICK_FACE/RESERVE) for v2 putaway fidelity.
-- Mirrors v2 putaway.service: palletFunctionFor(location) = level A -> PICK_FACE,
-- otherwise RESERVE. Feed the 3D rack map badge (BULK / PICK FACE).
-- Idempotent: safe to run multiple times.
-- =============================================================================
USE sanchaya;

ALTER TABLE `stock_locations`
ADD COLUMN IF NOT EXISTS `pallet_function` VARCHAR(20) NULL
COMMENT 'PICK_FACE | RESERVE — derived from target location level' AFTER `lpn_code`;