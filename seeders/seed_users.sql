DELETE FROM users;

INSERT INTO users (username, password, full_name, email, role, is_active, created_at) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin@trianawms.com', 'admin', 1, NOW()),
('warehouse', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Warehouse Manager', 'warehouse@trianawms.com', 'warehouse', 1, NOW()),
('supervisor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Warehouse Supervisor', 'supervisor@trianawms.com', 'supervisor', 1, NOW()),
('operator', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Warehouse Operator', 'operator@trianawms.com', 'operator', 1, NOW());
