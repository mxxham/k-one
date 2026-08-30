-- Performance Indexes Migration
-- Date: 2026-08-29

-- Stock table: frequently queried by product_id, location, status
CREATE INDEX IF NOT EXISTS idx_stock_product_location ON stock(product_id, location);
CREATE INDEX IF NOT EXISTS idx_stock_status ON stock(stock_status);
CREATE INDEX IF NOT EXISTS idx_stock_product_status ON stock(product_id, stock_status);
CREATE INDEX IF NOT EXISTS idx_stock_expiry ON stock(expiry_date);

-- Stock locations: LPN lookups, status filtering
CREATE INDEX IF NOT EXISTS idx_stock_locations_lpn ON stock_locations(lpn_code);
CREATE INDEX IF NOT EXISTS idx_stock_locations_status ON stock_locations(status);
CREATE INDEX IF NOT EXISTS idx_stock_locations_stock_id ON stock_locations(stock_id);
CREATE INDEX IF NOT EXISTS idx_stock_locations_location ON stock_locations(location_code);

-- Picklist items: status filtering, picklist lookups
CREATE INDEX IF NOT EXISTS idx_picklist_items_status ON picklist_items(status);
CREATE INDEX IF NOT EXISTS idx_picklist_items_picklist_status ON picklist_items(picklist_id, status);


-- Outbound items: order lookups, product filtering
CREATE INDEX IF NOT EXISTS idx_outbound_items_order ON outbound_items(outbound_order_id);
CREATE INDEX IF NOT EXISTS idx_outbound_items_product ON outbound_items(product_id);
CREATE INDEX IF NOT EXISTS idx_outbound_items_order_product ON outbound_items(outbound_order_id, product_id);

-- Inbound items: order lookups, product filtering
CREATE INDEX IF NOT EXISTS idx_inbound_items_order ON inbound_items(inbound_order_id);
CREATE INDEX IF NOT EXISTS idx_inbound_items_product ON inbound_items(product_id);

-- Activity log: user lookups, module filtering, date range
CREATE INDEX IF NOT EXISTS idx_activity_log_user ON activity_log(user_id);
CREATE INDEX IF NOT EXISTS idx_activity_log_module ON activity_log(module);
CREATE INDEX IF NOT EXISTS idx_activity_log_created ON activity_log(created_at);
CREATE INDEX IF NOT EXISTS idx_activity_log_module_created ON activity_log(module, created_at);

-- Outbound orders: status, customer, date
CREATE INDEX IF NOT EXISTS idx_outbound_orders_status ON outbound_orders(status);
CREATE INDEX IF NOT EXISTS idx_outbound_orders_customer ON outbound_orders(customer_id);
CREATE INDEX IF NOT EXISTS idx_outbound_orders_date ON outbound_orders(created_at);

-- Inbound orders: status, supplier, date
CREATE INDEX IF NOT EXISTS idx_inbound_orders_status ON inbound_orders(status);

CREATE INDEX IF NOT EXISTS idx_inbound_orders_date ON inbound_orders(created_at);

-- Bin transfers: status, date
CREATE INDEX IF NOT EXISTS idx_bin_transfers_status ON bin_transfers(status);
CREATE INDEX IF NOT EXISTS idx_bin_transfers_date ON bin_transfers(created_at);

-- Replenishment log: status, date, location
CREATE INDEX IF NOT EXISTS idx_replenishment_log_product ON replenishment_log(product_id);
CREATE INDEX IF NOT EXISTS idx_replenishment_log_trigger ON replenishment_log(trigger_type);
