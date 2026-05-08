<?php

require_once __DIR__ . '/../config/database.php';

$db = db();

$db->exec("DELETE FROM users");

$users = [
    [
        'username' => 'admin',
        'password' => password_hash('admin123', PASSWORD_DEFAULT),
        'full_name' => 'Administrator',
        'email' => 'admin@sanchaya.com',
        'role' => 'admin',
        'is_active' => 1
    ],
    [
        'username' => 'warehouse',
        'password' => password_hash('warehouse123', PASSWORD_DEFAULT),
        'full_name' => 'Warehouse Manager',
        'email' => 'warehouse@sanchaya.com',
        'role' => 'warehouse',
        'is_active' => 1
    ],
    [
        'username' => 'operator',
        'password' => password_hash('operator123', PASSWORD_DEFAULT),
        'full_name' => 'Warehouse Operator',
        'email' => 'operator@sanchaya.com',
        'role' => 'operator',
        'is_active' => 1
    ],
    [
        'username' => 'supervisor',
        'password' => password_hash('supervisor123', PASSWORD_DEFAULT),
        'full_name' => 'Warehouse Supervisor',
        'email' => 'supervisor@sanchaya.com',
        'role' => 'supervisor',
        'is_active' => 1
    ]
];

$stmt = $db->prepare("INSERT INTO users (username, password, full_name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");

foreach ($users as $user) {
    $stmt->execute([
        $user['username'],
        $user['password'],
        $user['full_name'],
        $user['email'],
        $user['role'],
        $user['is_active']
    ]);
    echo "✓ User created: {$user['username']} ({$user['full_name']})\n";
}

echo "\n✅ Users seeded successfully! Total: " . count($users) . " users\n";
echo "\n📝 Login credentials:\n";
echo "   - admin / admin123\n";
echo "   - warehouse / warehouse123\n";
echo "   - supervisor / supervisor123\n";
echo "   - operator / operator123\n";
?>