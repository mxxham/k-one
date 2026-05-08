<?php

require_once __DIR__ . '/../config/database.php';

$db = db();

$db->exec("DELETE FROM customers");

$customers = [
    [
        'customer_code' => 'CUST-001',
        'customer_name' => 'PT Petro Indonesia',
        'contact_person' => 'Dedi Kurniawan',
        'phone' => '021-45678901',
        'email' => 'dedi@petro.co.id',
        'address' => 'Graha Petro, Jl. Rasuna Said Kav. 10, Jakarta 12950'
    ],
    [
        'customer_code' => 'CUST-002',
        'customer_name' => 'CV Energy Mandiri',
        'contact_person' => 'Eko Prasetyo',
        'phone' => '031-56789012',
        'email' => 'eko@energymandiri.com',
        'address' => 'Jl. Industri Raya No. 88, Surabaya 60111'
    ],
    [
        'customer_code' => 'CUST-003',
        'customer_name' => 'PT Global Fuel',
        'contact_person' => 'Feri Susanto',
        'phone' => '021-67890123',
        'email' => 'feri@globalfuel.co.id',
        'address' => 'Biz Park, Jl. Boulevard Raya, Jakarta 14240'
    ],
    [
        'customer_code' => 'CUST-004',
        'customer_name' => 'UD Jaya Abadi',
        'contact_person' => 'Gunawan',
        'phone' => '024-78901234',
        'email' => 'gunawan@jayaabadi.com',
        'address' => 'Jl. Semarang-Solo Km. 12, Semarang 50222'
    ]
];

$stmt = $db->prepare("INSERT INTO customers (customer_code, customer_name, contact_person, phone, email, address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");

foreach ($customers as $customer) {
    $stmt->execute([
        $customer['customer_code'],
        $customer['customer_name'],
        $customer['contact_person'],
        $customer['phone'],
        $customer['email'],
        $customer['address']
    ]);
    echo "✓ Customer created: {$customer['customer_code']} - {$customer['customer_name']}\n";
}

echo "\n✅ Customers seeded successfully! Total: " . count($customers) . " customers\n";
?>