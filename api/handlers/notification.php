<?php
declare(strict_types=1);

/**
 * Notification handler — module 'notification' for the gateway.
 * Actions: list, get, send, mark_read, delete.
 */
function handle_notification($action)
{
    switch ($action) {

        case 'list':
            api_require_auth();
            $db    = db();
            $page  = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 25)));
            $offset = ($page - 1) * $limit;

            $where  = '1=1';
            $params = [];

            // Status filter
            if (!empty($_GET['status'])) {
                $status = trim($_GET['status']);
                $allowed = ['Pending', 'Sent', 'Failed', 'Read'];
                if (in_array($status, $allowed, true)) {
                    $where .= ' AND status = ?';
                    $params[] = $status;
                }
            }

            // Type filter
            if (!empty($_GET['type'])) {
                $type = trim($_GET['type']);
                $allowed = ['email', 'system', 'alert'];
                if (in_array($type, $allowed, true)) {
                    $where .= ' AND type = ?';
                    $params[] = $type;
                }
            }

            // Search
            if (!empty($_GET['q'])) {
                $q = '%' . trim($_GET['q']) . '%';
                $where .= ' AND (title LIKE ? OR body LIKE ?)';
                $params[] = $q;
                $params[] = $q;
            }

            // Count
            $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE {$where}");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            // Fetch
            $sql = "SELECT id, user_id, type, title, body, status, created_at 
                    FROM notifications 
                    WHERE {$where} 
                    ORDER BY created_at DESC 
                    LIMIT {$limit} OFFSET {$offset}";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_out([
                'data'  => $rows,
                'total' => $total,
                'page'  => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ]);
            break;

        case 'get':
            api_require_auth();
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) json_err('id is required');

            $db   = db();
            $stmt = $db->prepare("SELECT id, user_id, type, title, body, status, created_at FROM notifications WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_err('Notification not found', 404);

            json_out($row);
            break;

        case 'send':
            $user = api_require_write();
            $data = body();

            $type    = trim((string)($data['type'] ?? 'system'));
            $title   = trim((string)($data['title'] ?? ''));
            $message = trim((string)($data['message'] ?? ''));
            $recipient = trim((string)($data['recipient'] ?? ''));

            if (!$title) json_err('title is required');
            if (!$message) json_err('message is required');

            $allowed = ['email', 'system', 'alert'];
            if (!in_array($type, $allowed, true)) json_err('Invalid type');

            $db = db();
            $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, body, status, created_at) VALUES (?, ?, ?, ?, 'Sent', NOW())");
            $stmt->execute([$user['id'], $type, $title, $message]);
            $id = (int) $db->lastInsertId();

            ActivityLogger::log(
                'SEND_NOTIFICATION', 'notification', 'Notification', $id,
                null, "Notification sent: {$title}"
            );

            json_out(['id' => $id]);
            break;

        case 'mark_read':
            $user = api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) json_err('id is required');

            $db = db();
            $stmt = $db->prepare("UPDATE notifications SET status = 'Read' WHERE id = ?");
            $stmt->execute([$id]);

            json_out(['success' => true]);
            break;

        case 'delete':
            $user = api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) json_err('id is required');

            $db = db();
            $stmt = $db->prepare("DELETE FROM notifications WHERE id = ?");
            $stmt->execute([$id]);

            json_out(['success' => true]);
            break;

        default:
            json_err('Unknown action: ' . $action, 400);
    }
}
