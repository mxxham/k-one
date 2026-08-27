<?php
declare(strict_types=1);

/**
 * TaskAssignmentService — assigns pick tasks to operators.
 * Uses the operators table from migration 023.
 */
class TaskAssignmentService
{
    /**
     * Create a new operator record.
     */
    public static function createOperator(array $data): int
    {
        $db = db();
        $name     = trim((string)($data['name'] ?? ''));
        $userId   = (int)($data['user_id'] ?? 0);
        $capacity = (int)($data['capacity'] ?? 50);
        if ($name === '') throw new ApiException('Operator name is required', 400);
        if ($userId <= 0)  throw new ApiException('user_id is required', 400);

        $stmt = $db->prepare(
            "INSERT INTO operators (user_id, name, capacity, is_active) VALUES (?, ?, ?, 1)"
        );
        $stmt->execute([$userId, $name, $capacity]);
        return (int)$db->lastInsertId();
    }

    /**
     * Get a single operator.
     */
    public static function getOperator(int $id): array
    {
        $db = db();
        $stmt = $db->prepare("SELECT o.*, u.username, u.full_name FROM operators o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) throw new ApiException('Operator not found', 404);
        return $row;
    }

    /**
     * List all active operators.
     */
    public static function listOperators(): array
    {
        $db = db();
        return $db->query(
            "SELECT o.*, u.username, u.full_name FROM operators o LEFT JOIN users u ON u.id = o.user_id WHERE o.is_active = 1 ORDER BY o.name"
        )->fetchAll();
    }

    /**
     * Assign pick tasks from a picklist to operators.
     * strategy: 'round_robin' — distribute items evenly across operators.
     *           'fewest_items' — assign to operator with fewest active tasks.
     */
    public static function assign(int $picklistId, string $strategy = 'round_robin'): array
    {
        $db = db();
        $db->beginTransaction();
        try {
            // Get picklist
            $pl = $db->prepare("SELECT * FROM picklists WHERE id = ?");
            $pl->execute([$picklistId]);
            $picklist = $pl->fetch();
            if (!$picklist) throw new ApiException('Picklist not found', 404);

            // Get unassigned items
            $itemStmt = $db->prepare(
                "SELECT pi.* FROM picklist_items pi WHERE pi.picklist_id = ? AND (pi.assigned_to IS NULL OR pi.assigned_to = 0) ORDER BY pi.id"
            );
            $itemStmt->execute([$picklistId]);
            $items = $itemStmt->fetchAll();
            if (empty($items)) throw new ApiException('No unassigned items in picklist', 409);

            // Get active operators
            $opStmt = $db->query("SELECT id, capacity FROM operators WHERE is_active = 1");
            $operators = $opStmt->fetchAll();
            if (empty($operators)) throw new ApiException('No active operators available', 409);

            $assigned = [];
            if ($strategy === 'fewest_items') {
                // Sort operators by current active task count
                foreach ($operators as &$op) {
                    $cnt = $db->prepare("SELECT COUNT(*) FROM picklist_items WHERE assigned_to = ? AND status NOT IN ('Picked','Cancelled')");
                    $cnt->execute([(int)$op['id']]);
                    $op['active_count'] = (int)$cnt->fetchColumn();
                }
                unset($op);
                usort($operators, fn($a, $b) => $a['active_count'] <=> $b['active_count']);
            }

            $opIdx = 0;
            $opCount = count($operators);
            foreach ($items as $item) {
                $operatorId = (int)$operators[$opIdx % $opCount]['id'];
                $db->prepare(
                    "UPDATE picklist_items SET assigned_to = ?, status = 'Pending' WHERE id = ? AND (assigned_to IS NULL OR assigned_to = 0)"
                )->execute([$operatorId, (int)$item['id']]);
                $assigned[] = [
                    'picklist_item_id' => (int)$item['id'],
                    'operator_id'      => $operatorId,
                ];
                $opIdx++;
            }

            $db->commit();
            return ['assigned' => $assigned, 'strategy' => $strategy, 'count' => count($assigned)];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
