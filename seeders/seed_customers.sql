DELETE FROM customers;

INSERT INTO customers (customer_code, customer_name, contact_person, phone, email, address, created_at) VALUES
('CUST-001', 'PT Petro Indonesia', 'Dedi Kurniawan', '021-45678901', 'dedi@petro.co.id', 'Graha Petro, Jl. Rasuna Said Kav. 10, Jakarta 12950', NOW()),
('CUST-002', 'CV Energy Mandiri', 'Eko Prasetyo', '031-56789012', 'eko@energymandiri.com', 'Jl. Industri Raya No. 88, Surabaya 60111', NOW()),
('CUST-003', 'PT Global Fuel', 'Feri Susanto', '021-67890123', 'feri@globalfuel.co.id', 'Biz Park, Jl. Boulevard Raya, Jakarta 14240', NOW()),
('CUST-004', 'UD Jaya Abadi', 'Gunawan', '024-78901234', 'gunawan@jayaabadi.com', 'Jl. Semarang-Solo Km. 12, Semarang 50222', NOW());
