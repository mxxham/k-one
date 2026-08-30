<?php
declare(strict_types=1);

/**
 * QualityService — quality inspection management for the warehouse.
 *
 * Handles creating inspections, recording results, approving/rejecting.
 */
class QualityService
{
    /**
     * Generate a unique inspection number: QI-YYYYMMDD-NNNN.
     */
    public static function generateNumber(): string
    {
        return generate_number(
            'quality_inspections',
            'inspection_number',
            'QI-' . today_compact() . '-',
            'QI-' . today_compact() . '-'
        );
    }

    /**
     * Create a new quality inspection.
     */
    public static function createInspection(int $stockId, string $inspectionType, int $inspector): int
    {
        $db = db();

        // Validate stock exists
        $stockStmt = $db->prepare("SELECT id FROM stock WHERE id = ? LIMIT 1");
        $stockStmt->execute([$stockId]);
        if (!$stockStmt->fetch()) {
            throw new Exception('Stock not found', 404);
        }

        $validTypes = ['Incoming', 'Outgoing', 'Periodic', 'Ad-hoc'];
        if (!in_array($inspectionType, $validTypes)) {
            throw new Exception(
                'Invalid inspection type: ' . $inspectionType . '. Must be one of: ' . implode(', ', $validTypes),
                400
            );
        }

        // Validate inspector exists
        $inspectorStmt = $db->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        $inspectorStmt->execute([$inspector]);
        if (!$inspectorStmt->fetch()) {
            throw new Exception('Inspector not found', 404);
        }

        $inspectionNumber = self::generateNumber();

        $stmt = $db->prepare(
            "INSERT INTO quality_inspections
                (inspection_number, stock_id, inspection_type, inspector, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'Pending', NOW(), NOW())"
        );
        $stmt->execute([$inspectionNumber, $stockId, $inspectionType, $inspector]);

        return (int)$db->lastInsertId();
    }

    /**
     * Record inspection results for an existing inspection.
     *
     * @param int   $inspectionId
     * @param array $results  Array of ['parameter' => string, 'value' => string, 'unit' => string|null, 'pass_fail' => string|null]
     */
    public static function recordResult(int $inspectionId, array $results): void
    {
        $db = db();

        $inspection = self::getInspectionRaw($inspectionId);
        if ($inspection['status'] !== 'Pending') {
            throw new Exception('Inspection is not in Pending status', 409);
        }

        if (empty($results) || !is_array($results)) {
            throw new Exception('Results must be a non-empty array', 400);
        }

        $validPassFail = ['Pass', 'Fail', null];
        $stmt = $db->prepare(
            "INSERT INTO quality_results (inspection_id, parameter, value, unit, pass_fail, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );

        foreach ($results as $row) {
            $parameter = trim((string)($row['parameter'] ?? ''));
            if ($parameter === '') {
                throw new Exception('Each result must have a non-empty parameter', 400);
            }
            $value    = trim((string)($row['value'] ?? ''));
            $unit     = !empty($row['unit']) ? trim((string)$row['unit']) : null;
            $passFail = $row['pass_fail'] ?? null;

            if ($passFail !== null && !in_array($passFail, $validPassFail)) {
                throw new Exception(
                    'Invalid pass_fail value: ' . $passFail . '. Must be Pass, Fail, or null',
                    400
                );
            }

            $stmt->execute([$inspectionId, $parameter, $value, $unit, $passFail]);
        }

        // Update inspection status to 'In Progress'
        $db->prepare("UPDATE quality_inspections SET status = 'In Progress', updated_at = NOW() WHERE id = ?")
           ->execute([$inspectionId]);
    }

    /**
     * Approve a quality inspection.
     */
    public static function approveInspection(int $inspectionId): void
    {
        $db = db();

        $inspection = self::getInspectionRaw($inspectionId);
        if ($inspection['status'] === 'Approved') {
            throw new Exception('Inspection is already approved', 409);
        }
        if ($inspection['status'] === 'Rejected') {
            throw new Exception('Cannot approve a rejected inspection', 409);
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);

        $db->prepare(
            "UPDATE quality_inspections
             SET status = 'Approved', result = 'Pass', approved_by = ?, approved_at = NOW(), updated_at = NOW()
             WHERE id = ?"
        )->execute([$userId, $inspectionId]);
    }

    /**
     * Reject a quality inspection with a reason.
     */
    public static function rejectInspection(int $inspectionId, string $reason): void
    {
        $db = db();

        if (trim($reason) === '') {
            throw new Exception('Rejection reason is required', 400);
        }

        $inspection = self::getInspectionRaw($inspectionId);
        if ($inspection['status'] === 'Rejected') {
            throw new Exception('Inspection is already rejected', 409);
        }
        if ($inspection['status'] === 'Approved') {
            throw new Exception('Cannot reject an approved inspection', 409);
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);

        $db->prepare(
            "UPDATE quality_inspections
             SET status = 'Rejected', result = 'Fail', approved_by = ?, approved_at = NOW(),
                 notes = CONCAT(COALESCE(notes, ''), '\nRejection reason: ', ?), updated_at = NOW()
             WHERE id = ?"
        )->execute([$userId, trim($reason), $inspectionId]);
    }

    /**
     * Get inspection details with results.
     */
    public static function getInspection(int $inspectionId): array
    {
        $row = self::getInspectionRaw($inspectionId);

        $db = db();

        // Fetch results
        $resultsStmt = $db->prepare(
            "SELECT id, parameter, value, unit, pass_fail, created_at
             FROM quality_results
             WHERE inspection_id = ?
             ORDER BY id"
        );
        $resultsStmt->execute([$inspectionId]);
        $row['results'] = $resultsStmt->fetchAll();

        // Fetch stock details
        $stockStmt = $db->prepare(
            "SELECT s.id, s.product_id, s.quantity, s.batch_number, s.stock_status,
                    p.product_code, p.product_name
             FROM stock s
             LEFT JOIN products p ON s.product_id = p.id
             WHERE s.id = ?"
        );
        $stockStmt->execute([$row['stock_id']]);
        $row['stock'] = $stockStmt->fetch();

        // Fetch inspector name
        $inspectorStmt = $db->prepare("SELECT id, full_name FROM users WHERE id = ?");
        $inspectorStmt->execute([$row['inspector']]);
        $row['inspector_name'] = $inspectorStmt->fetchColumn(1) ?: null;

        // Fetch approver name
        if (!empty($row['approved_by'])) {
            $approverStmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
            $approverStmt->execute([$row['approved_by']]);
            $row['approved_by_name'] = $approverStmt->fetchColumn() ?: null;
        }

        return $row;
    }

    /**
     * List inspections with optional filters.
     *
     * @param array $filters  Accepted keys: status, inspection_type, inspector, stock_id, search, page, per_page
     */
    public static function listInspections(array $filters = []): array
    {
        $db = db();

        $page    = max(1, (int)($filters['page'] ?? 1));
        $perPage = max(1, min(200, (int)($filters['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        $conditions = [];
        $params     = [];

        if (!empty($filters['status'])) {
            $conditions[] = "qi.status = ?";
            $params[]     = $filters['status'];
        }
        if (!empty($filters['inspection_type'])) {
            $conditions[] = "qi.inspection_type = ?";
            $params[]     = $filters['inspection_type'];
        }
        if (!empty($filters['inspector'])) {
            $conditions[] = "qi.inspector = ?";
            $params[]     = (int)$filters['inspector'];
        }
        if (!empty($filters['stock_id'])) {
            $conditions[] = "qi.stock_id = ?";
            $params[]     = (int)$filters['stock_id'];
        }
        if (!empty($filters['search'])) {
            $conditions[] = "(qi.inspection_number LIKE ? OR p.product_code LIKE ? OR p.product_name LIKE ?)";
            $like = '%' . $filters['search'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Total count
        $countSql = "SELECT COUNT(*)
                     FROM quality_inspections qi
                     LEFT JOIN stock s ON qi.stock_id = s.id
                     LEFT JOIN products p ON s.product_id = p.id
                     $where";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Fetch rows
        $sql = "SELECT qi.*,
                       u.full_name AS inspector_name,
                       s.product_id, s.batch_number, s.stock_status,
                       p.product_code, p.product_name,
                       au.full_name AS approved_by_name
                FROM quality_inspections qi
                LEFT JOIN users u ON qi.inspector = u.id
                LEFT JOIN stock s ON qi.stock_id = s.id
                LEFT JOIN products p ON s.product_id = p.id
                LEFT JOIN users au ON qi.approved_by = au.id
                $where
                ORDER BY qi.created_at DESC
                LIMIT ? OFFSET ?";
        $allParams = array_merge($params, [$perPage, $offset]);
        $stmt = $db->prepare($sql);
        $stmt->execute($allParams);
        $rows = $stmt->fetchAll();

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Private helpers                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Raw inspection row without joined details.
     */
    private static function getInspectionRaw(int $id): array
    {
        $db   = db();
        $stmt = $db->prepare("SELECT * FROM quality_inspections WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new Exception('Inspection not found', 404);
        }
        return $row;
    }
}
