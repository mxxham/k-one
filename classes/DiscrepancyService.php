<?php
declare(strict_types=1);

/**
 * DiscrepancyService — log and track outbound discrepancies.
 */
class DiscrepancyService
{
    /**
     * Log a discrepancy (missing item, wrong qty, wrong product, damaged).
     */
    public static function logDiscrepancy(array $data): int
    {
        $db = db();
        $picklistItemId = (int)($data['picklist_item_id'] ?? 0);
        $type           = trim((string)($data['type'] ?? 'Missing'));
        $description    = trim((string)($data['description'] ?? ''));
        $operatorId     = (int)($data['operator_id'] ?? 0);
        $discrepancyQty = (float)($data['discrepancy_qty'] ?? 0);

        if ($picklistItemId <= 0) throw new ApiException('picklist_item_id is required', 400);
        $validTypes = ['Missing', 'Wrong Qty', 'Wrong Product', 'Damaged', 'Expired'];
        if (!in_array($type, $validTypes)) {
            throw new ApiException('Invalid type: ' . $type . '. Must be one of: ' . implode(', ', $validTypes), 400);
        }

        // Resolve operator → user_id for FK constraint
        $opStmt = $db->prepare("SELECT user_id FROM operators WHERE id = ?");
        $opStmt->execute([$operatorId]);
        $userIdForAudit = (int)($opStmt->fetchColumn() ?: $operatorId);

        $stmt = $db->prepare(
            "INSERT INTO discrepancies
                (picklist_item_id, type, description, discrepancy_qty, operator_id, reported_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$picklistItemId, $type, $description, $discrepancyQty, $userIdForAudit]);
        $id = (int)$db->lastInsertId();

        // Audit log — resolve operator → user_id for FK
        $opStmt = $db->prepare("SELECT user_id FROM operators WHERE id = ?");
        $opStmt->execute([$operatorId]);
        $userIdForAudit = (int)($opStmt->fetchColumn() ?: $operatorId);

        $db->prepare(
            "INSERT INTO audit_log (module, module_id, action, user_id, details, created_at)
             VALUES ('discrepancy', ?, 'DISCREPANCY_REPORTED', ?, ?, NOW())"
        )->execute([
            $id,
            $userIdForAudit,
            json_encode([
                'picklist_item_id' => $picklistItemId,
                'type'             => $type,
                'qty'              => $discrepancyQty,
            ]),
        ]);

        return $id;
    }

    /**
     * Get a single discrepancy.
     */
    public static function get(int $id): array
    {
        $db = db();
        $stmt = $db->prepare(
            "SELECT d.*, pi.lpn_code, pi.bin_location, pi.quantity AS expected_qty,
                    p.picklist_number, o.order_number
             FROM discrepancies d
             LEFT JOIN picklist_items pi ON pi.id = d.picklist_item_id
             LEFT JOIN picklists p ON p.id = pi.picklist_id
             LEFT JOIN outbound_orders o ON o.id = p.outbound_order_id
             WHERE d.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) throw new ApiException('Discrepancy not found', 404);
        return $row;
    }

    /**
     * List discrepancies with optional status filter.
     */
    public static function list(int $page = 1, int $perPage = 50, ?string $type = null): array
    {
        $db = db();
        $conditions = [];
        $params = [];
        if ($type) {
            $conditions[] = 'd.type = ?';
            $params[] = $type;
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $totalStmt = $db->prepare("SELECT COUNT(*) FROM discrepancies d $where");
        $totalStmt->execute($params);
        $total = (int)$totalStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $db->prepare(
            "SELECT d.*, p.picklist_number, o.order_number
             FROM discrepancies d
             LEFT JOIN picklist_items pi ON pi.id = d.picklist_item_id
             LEFT JOIN picklists p ON p.id = pi.picklist_id
             LEFT JOIN outbound_orders o ON o.id = p.outbound_order_id
             $where
             ORDER BY d.reported_at DESC
             LIMIT ? OFFSET ?"
        );
        $allParams = array_merge($params, [$perPage, $offset]);
        $stmt->execute($allParams);

        return [
            'rows'     => $stmt->fetchAll(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }
}
