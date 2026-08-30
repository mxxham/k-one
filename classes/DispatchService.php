<?php
declare(strict_types=1);

/**
 * DispatchService — manage Good Issue dispatch flow.
 * Scans consolidated LPNs, validates references, marks dispatched.
 */
class DispatchService
{
    /**
     * Scan an LPN for dispatch — validates and marks as dispatched.
     */
    public static function scanDispatch(array $data): array
    {
        $db = db();
        $db->beginTransaction();
        try {
            $lpnCode   = trim((string)($data['lpn_code'] ?? ''));
            $doNumber  = trim((string)($data['do_number'] ?? ''));
            $truckNo   = trim((string)($data['truck_no'] ?? ''));
            $driverName = trim((string)($data['driver_name'] ?? ''));
            $operatorId = (int)($data['operator_id'] ?? 0);

            if ($lpnCode === '') throw new ApiException('lpn_code is required', 400);

            // Validate LPN exists in stock_locations
            $locStmt = $db->prepare(
                "SELECT sl.*, s.product_id, p.product_name
                 FROM stock_locations sl
                 JOIN stock s ON s.id = sl.stock_id
                 JOIN products p ON p.id = s.product_id
                 WHERE sl.lpn_code = ?"
            );
            $locStmt->execute([$lpnCode]);
            $location = $locStmt->fetch();
            if (!$location) throw new InvalidLpnException($lpnCode, 'Not found in stock_locations');

            // Validate DO number not already dispatched
            if ($doNumber !== '') {
                $existStmt = $db->prepare(
                    "SELECT id FROM gi_exports WHERE do_number = ? AND status = 'Dispatched' LIMIT 1"
                );
                $existStmt->execute([$doNumber]);
                if ($existStmt->fetch()) {
                    throw new DispatchReferenceConflictException($doNumber, 'already dispatched');
                }
            }

            // Check for picklist reference conflict
            $pickRefStmt = $db->prepare(
                "SELECT pi.id, p.picklist_number
                 FROM picklist_items pi
                 JOIN picklists p ON p.id = pi.picklist_id
                 WHERE pi.lpn_code = ? AND pi.status = 'Picked'"
            );
            $pickRefStmt->execute([$lpnCode]);
            $pickRef = $pickRefStmt->fetch();
            if ($pickRef) {
                // Has pick reference — check if it's been consolidated/staged
                $stagedStmt = $db->prepare(
                    "SELECT id FROM staging WHERE lpn_code = ? LIMIT 1"
                );
                $stagedStmt->execute([$lpnCode]);
                if (!$stagedStmt->fetch()) {
                    throw new PickReferenceConflictException(
                        $lpnCode,
                        'Picklist item still in Picked status — must be staged first'
                    );
                }
            }

            // Resolve operator → user_id for FK constraints
            $opStmt = $db->prepare("SELECT user_id FROM operators WHERE id = ?");
            $opStmt->execute([$operatorId]);
            $userIdForAudit = (int)($opStmt->fetchColumn() ?: $operatorId);

            // Create dispatch record
            $giNumber = generate_number(
                'gi_exports', 'gi_number',
                'GI-' . date('Ym') . '-', 'GI-' . date('Ym') . '-', 4
            );

            $stmt = $db->prepare(
                "INSERT INTO gi_exports
                    (gi_number, lpn_code, do_number, truck_no, driver_name, status,
                     operator_id, dispatched_at, created_at)
                 VALUES (?, ?, ?, ?, ?, 'Dispatched', ?, NOW(), NOW())"
            );
            $stmt->execute([$giNumber, $lpnCode, $doNumber, $truckNo, $driverName, $userIdForAudit]);
            $giId = (int)$db->lastInsertId();

            // Update stock location status
            $db->prepare("UPDATE stock_locations SET status = 'Dispatched' WHERE lpn_code = ?")
               ->execute([$lpnCode]);

            $db->prepare(
                "INSERT INTO audit_log (module, module_id, action, user_id, details, created_at)
                 VALUES ('dispatch', ?, 'DISPATCH', ?, ?, NOW())"
            )->execute([
                $giId,
                $userIdForAudit,
                json_encode([
                    'lpn_code'   => $lpnCode,
                    'do_number'  => $doNumber,
                    'gi_number'  => $giNumber,
                    'truck_no'   => $truckNo,
                    'driver'     => $driverName,
                ]),
            ]);

            $db->commit();
            return [
                'gi_id'      => $giId,
                'gi_number'  => $giNumber,
                'lpn_code'   => $lpnCode,
                'do_number'  => $doNumber,
                'status'     => 'Dispatched',
            ];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Get dispatch details.
     */
    public static function getDispatch(int $id): array
    {
        $db = db();
        $stmt = $db->prepare("SELECT * FROM gi_exports WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) throw new ApiException('Dispatch not found', 404);
        return $row;
    }

    /**
     * List dispatches with pagination.
     */
    public static function listDispatches(int $page = 1, int $perPage = 50): array
    {
        $db = db();
        $total = (int)$db->query("SELECT COUNT(*) FROM gi_exports")->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = $db->prepare("SELECT * FROM gi_exports ORDER BY dispatched_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$perPage, $offset]);
        return [
            'rows'     => $stmt->fetchAll(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }
}
