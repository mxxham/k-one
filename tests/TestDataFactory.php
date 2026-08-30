<?php
declare(strict_types=1);

/**
 * Shared test data factory — creates products, stock, inbound/outbound orders,
 * and related records for integration and unit tests.
 *
 * Usage:
 *   TestDataFactory::createProduct(['product_code' => 'DRM001']);
 *   TestDataFactory::createStock($productId, 'CA01A01', 10);
 *   TestDataFactory::createInbound($userId, [...items...]);
 */
final class TestDataFactory
{
    private static ?int $productCounter = null;

    /** Get or init a shared product counter for unique codes. */
    private static function nextProductCode(): string
    {
        if (self::$productCounter === null) {
            self::$productCounter = (int) (microtime(true) * 1000) % 100000;
        }
        self::$productCounter++;
        return 'FAC' . str_pad((string) self::$productCounter, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Create a product with sensible defaults. Returns the new product id.
     */
    public static function createProduct(array $overrides = []): int
    {
        $code = $overrides['product_code'] ?? self::nextProductCode();
        $pdo  = ApiTestHelpers::pdo();

        $stmt = $pdo->prepare(
            "INSERT INTO products
                (product_code, product_name, category, uom_type, uom_per_pallet,
                 liters_per_unit, velocity_class, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([
            $code,
            $overrides['product_name'] ?? 'Factory Product ' . $code,
            $overrides['category']     ?? 'Drum',
            $overrides['uom_type']     ?? 'Drum',
            $overrides['uom_per_pallet'] ?? 4,
            $overrides['liters_per_unit'] ?? 209.00,
            $overrides['velocity_class'] ?? 'A',
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Insert a stock row + stock_locations row directly. Returns stock id.
     */
    public static function createStock(
        int    $productId,
        string $location  = 'CA01A01',
        float  $quantity  = 10.0,
        string $batch     = null,
        string $expiry    = '2030-12-31',
        string $status    = 'Available'
    ): int {
        $batch = $batch ?? 'B' . date('Ymd') . random_int(1000, 9999);
        $pdo   = ApiTestHelpers::pdo();

        $pallet = (int) ceil($quantity / 4);
        $stmt   = $pdo->prepare(
            "INSERT INTO stock
                (product_id, batch_number, location, quantity, uom, pallet,
                 expiry_date, stock_status, hold_status)
             VALUES (?, ?, ?, ?, 'Drum', ?, ?, ?, 'available')"
        );
        $stmt->execute([$productId, $batch, $location, $quantity, $pallet, $expiry, $status]);
        $stockId = (int) $pdo->lastInsertId();

        $sl = $pdo->prepare(
            "INSERT INTO stock_locations
                (stock_id, location_code, pallet_seq, quantity, original_quantity,
                 uom, is_full_pallet, batch_number, status)
             VALUES (?, ?, 1, ?, ?, 'Drum', 1, ?, 'Available')"
        );
        $sl->execute([$stockId, $location, $quantity, $quantity, $batch]);

        return $stockId;
    }

    /**
     * Create an inbound order with optional items. Returns inbound order id.
     */
    public static function createInbound(
        int   $createdBy,
        array $items       = [],
        array $orderOverrides = []
    ): int {
        $pdo  = ApiTestHelpers::pdo();
        $num  = 'IN-F' . date('Ymd') . '-' . sprintf('%04d', random_int(1, 9999));
        $date = $orderOverrides['order_date'] ?? date('Y-m-d');

        $stmt = $pdo->prepare(
            "INSERT INTO inbound_orders
                (order_number, order_date, carrier_name, status, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $num,
            $date,
            $orderOverrides['carrier_name'] ?? 'Test Carrier',
            $orderOverrides['status']       ?? 'Dues In',
            $orderOverrides['notes']        ?? null,
            $createdBy,
        ]);
        $inboundId = (int) $pdo->lastInsertId();

        foreach ($items as $item) {
            self::addInboundItem($inboundId, $item);
        }

        return $inboundId;
    }

    /**
     * Add a single item to an inbound order. Returns item id.
     */
    public static function addInboundItem(int $inboundId, array $item): int
    {
        $pdo       = ApiTestHelpers::pdo();
        $productId = $item['product_id'] ?? self::createProduct();

        $stmt = $pdo->prepare(
            "INSERT INTO inbound_items
                (inbound_order_id, od_number, so_number, product_id, batch_number,
                 location, quantity, uom, actual_qty, pallet, manufacture_date,
                 exp_date, stock_status, in_process_status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $inboundId,
            $item['od_number']   ?? null,
            $item['so_number']   ?? null,
            $productId,
            $item['batch_number'] ?? null,
            $item['location']    ?? null,
            $item['quantity']    ?? 10.0,
            $item['uom']         ?? 'Drum',
            $item['actual_qty']  ?? $item['quantity'] ?? 10.0,
            $item['pallet']      ?? (int) ceil(($item['quantity'] ?? 10) / 4),
            $item['manufacture_date'] ?? null,
            $item['exp_date']    ?? '2030-12-31',
            $item['stock_status']     ?? 'Accepted',
            $item['in_process_status'] ?? 'Dues In',
            $item['notes']       ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Create an outbound order. Returns outbound order id.
     */
    public static function createOutbound(
        int   $createdBy,
        array $items           = [],
        array $orderOverrides  = []
    ): int {
        $pdo  = ApiTestHelpers::pdo();
        $num  = 'OUT-F' . date('Ymd') . '-' . sprintf('%04d', random_int(1, 9999));
        $date = $orderOverrides['order_date'] ?? date('Y-m-d');

        // Ensure a customer exists
        $custId = $orderOverrides['customer_id'] ?? self::createCustomer();

        $stmt = $pdo->prepare(
            "INSERT INTO outbound_orders
                (order_number, order_date, customer_id, status, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $num,
            $date,
            $custId,
            $orderOverrides['status'] ?? 'Open',
            $orderOverrides['notes']  ?? null,
            $createdBy,
        ]);
        $outboundId = (int) $pdo->lastInsertId();

        foreach ($items as $item) {
            self::addOutboundItem($outboundId, $item);
        }

        return $outboundId;
    }

    /**
     * Add a single item to an outbound order. Returns item id.
     */
    public static function addOutboundItem(int $outboundId, array $item): int
    {
        $pdo       = ApiTestHelpers::pdo();
        $productId = $item['product_id'] ?? self::createProduct();

        $stmt = $pdo->prepare(
            "INSERT INTO outbound_items
                (outbound_order_id, product_id, quantity, uom, actual_qty, pallet,
                 batch_no, exp_date, location, in_process_status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $outboundId,
            $productId,
            $item['quantity']    ?? 5.0,
            $item['uom']         ?? 'Drum',
            $item['actual_qty']  ?? $item['quantity'] ?? 5.0,
            $item['pallet']      ?? (int) ceil(($item['quantity'] ?? 5) / 4),
            $item['batch_no']    ?? null,
            $item['exp_date']    ?? null,
            $item['location']    ?? null,
            $item['in_process_status'] ?? 'Goods Received',
            $item['notes']       ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Create a customer. Returns customer id.
     */
    public static function createCustomer(array $overrides = []): int
    {
        $pdo  = ApiTestHelpers::pdo();
        $code = $overrides['customer_code'] ?? 'CUST' . random_int(1000, 9999);

        $stmt = $pdo->prepare(
            "INSERT INTO customers (customer_code, customer_name, address, city, phone, is_active)
             VALUES (?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([
            $code,
            $overrides['customer_name'] ?? 'Factory Customer ' . $code,
            $overrides['address']       ?? '123 Test Street',
            $overrides['city']          ?? 'Jakarta',
            $overrides['phone']         ?? '021-12345678',
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Create a supplier. Returns supplier id.
     */
    public static function createSupplier(array $overrides = []): int
    {
        $pdo  = ApiTestHelpers::pdo();
        $code = $overrides['supplier_code'] ?? 'SUP' . random_int(1000, 9999);

        $stmt = $pdo->prepare(
            "INSERT INTO suppliers (supplier_code, supplier_name, contact_name, phone, email, is_active)
             VALUES (?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([
            $code,
            $overrides['supplier_name'] ?? 'Factory Supplier ' . $code,
            $overrides['contact_name']  ?? 'Test Contact',
            $overrides['phone']         ?? '021-87654321',
            $overrides['email']         ?? strtolower($code) . '@test.com',
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Create a user with specified role. Returns user id.
     */
    public static function createUser(string $role = 'operator', array $overrides = []): int
    {
        $pdo  = ApiTestHelpers::pdo();
        $user = $overrides['username'] ?? 'user' . random_int(1000, 9999);

        $stmt = $pdo->prepare(
            "INSERT INTO users (username, password, full_name, email, role, department, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([
            $user,
            password_hash($overrides['password'] ?? 'admin123', PASSWORD_BCRYPT),
            $overrides['full_name'] ?? ucfirst($role) . ' User',
            $overrides['email']     ?? $user . '@test.com',
            $role,
            $overrides['department'] ?? 'all',
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Reset the test database: truncate transactional tables + reseed bins.
     * Wraps ApiTestHelpers::resetDb() for convenience.
     */
    public static function resetDb(): void
    {
        ApiTestHelpers::resetDb();
    }
}
