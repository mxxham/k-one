<?php

class Customer {
    public static function getAll() {
        $db = db();
        return $db->query("SELECT * FROM customers ORDER BY customer_name")->fetchAll();
    }

    public static function getCount(string $search = ''): int {
        $db = db();
        if ($search) {
            $term = "%$search%";
            $stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code LIKE ? OR customer_name LIKE ? OR city LIKE ?");
            $stmt->execute([$term, $term, $term]);
        } else {
            $stmt = $db->query("SELECT COUNT(*) FROM customers");
        }
        return (int)$stmt->fetchColumn();
    }

    public static function getTypeStats(): array {
        $db     = db();
        $active   = (int)$db->query("SELECT COUNT(*) FROM customers WHERE is_active = 1")->fetchColumn();
        $inactive = (int)$db->query("SELECT COUNT(*) FROM customers WHERE is_active = 0")->fetchColumn();
        return array_filter(['Active' => $active, 'Inactive' => $inactive]);
    }

    public static function getPaginated(string $search, int $perPage, int $offset): array {
        $db     = db();
        $where  = '';
        $params = [];
        if ($search) {
            $term   = "%$search%";
            $where  = " WHERE customer_code LIKE ? OR customer_name LIKE ? OR city LIKE ?";
            $params = [$term, $term, $term];
        }
        $sql  = "SELECT * FROM customers{$where} ORDER BY customer_name LIMIT " . intval($perPage) . " OFFSET " . intval($offset);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
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
