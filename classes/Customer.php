<?php

class Customer {
    public static function getAll() {
        $db = db();
        return $db->query("SELECT * FROM customers ORDER BY customer_name")->fetchAll();
    }

    public static function getById($id) {
        $db = db();
        $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public static function create($data) {
        $db = db();
        $stmt = $db->prepare("INSERT INTO customers (customer_code, customer_name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?, ?)");
        return $stmt->execute([
            $data['customer_code'],
            $data['customer_name'],
            $data['contact_person'] ?? null,
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['address'] ?? null
        ]);
    }

    public static function update($id, $data) {
        $db = db();
        $stmt = $db->prepare("UPDATE customers SET customer_code = ?, customer_name = ?, contact_person = ?, phone = ?, email = ?, address = ? WHERE id = ?");
        return $stmt->execute([
            $data['customer_code'],
            $data['customer_name'],
            $data['contact_person'] ?? null,
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['address'] ?? null,
            $id
        ]);
    }

    public static function delete($id) {
        $db = db();
        $stmt = $db->prepare("DELETE FROM customers WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
?>
