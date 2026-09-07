-- Add inbound_pickface_bin_id to sku_pickface_config
-- Paired with pickface_bin_id (outbound) — inbound partial pallets go here, never outbound pickface
ALTER TABLE sku_pickface_config
    ADD COLUMN inbound_pickface_bin_id int(11) DEFAULT NULL AFTER pickface_bin_id,
    ADD CONSTRAINT fk_sku_inbound_pickface
        FOREIGN KEY (inbound_pickface_bin_id) REFERENCES location_master (id)
        ON DELETE SET NULL;
