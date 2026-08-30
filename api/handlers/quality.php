<?php
declare(strict_types=1);

/**
 * Quality handler — module 'quality' for the gateway.
 * Actions: create, record, approve, reject, get, list.
 */
function handle_quality($action)
{
    switch ($action) {

        case 'create':
            $user = api_require_write();
            $data = body();
            $stockId       = (int)($data['stock_id'] ?? 0);
            $inspectionType = trim((string)($data['inspection_type'] ?? 'Incoming'));
            $inspector      = (int)($data['inspector'] ?? $user['id']);

            if ($stockId <= 0) json_err('stock_id is required');

            try {
                $id = QualityService::createInspection($stockId, $inspectionType, $inspector);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }

            ActivityLogger::log(
                'CREATE_INSPECTION', 'quality', 'Quality Inspection', $id,
                null, 'Inspection created for stock #' . $stockId
            );

            // Fetch the actual inspection number that was inserted
            $db = db();
            $numStmt = $db->prepare("SELECT inspection_number FROM quality_inspections WHERE id = ?");
            $numStmt->execute([$id]);
            $inspectionNumber = $numStmt->fetchColumn() ?: '';

            json_out(['id' => $id, 'inspection_number' => $inspectionNumber]);
            break;

        case 'record':
            $user = api_require_write();
            $data = body();
            $inspectionId = (int)($data['inspection_id'] ?? 0);
            $results      = $data['results'] ?? [];

            if ($inspectionId <= 0) json_err('inspection_id is required');
            if (empty($results) || !is_array($results)) json_err('results must be a non-empty array');

            try {
                QualityService::recordResult($inspectionId, $results);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }

            ActivityLogger::log(
                'RECORD_INSPECTION', 'quality', 'Quality Inspection', $inspectionId,
                null, 'Results recorded for inspection #' . $inspectionId
            );
            json_out(['id' => $inspectionId]);
            break;

        case 'approve':
            $user = api_require_write();
            $data = body();
            $inspectionId = (int)($data['inspection_id'] ?? 0);

            if ($inspectionId <= 0) json_err('inspection_id is required');

            try {
                QualityService::approveInspection($inspectionId);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }

            ActivityLogger::log(
                'APPROVE_INSPECTION', 'quality', 'Quality Inspection', $inspectionId,
                null, 'Inspection #' . $inspectionId . ' approved'
            );
            json_out(['id' => $inspectionId]);
            break;

        case 'reject':
            $user = api_require_write();
            $data = body();
            $inspectionId = (int)($data['inspection_id'] ?? 0);
            $reason       = trim((string)($data['reason'] ?? ''));

            if ($inspectionId <= 0) json_err('inspection_id is required');
            if ($reason === '') json_err('reason is required');

            try {
                QualityService::rejectInspection($inspectionId, $reason);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }

            ActivityLogger::log(
                'REJECT_INSPECTION', 'quality', 'Quality Inspection', $inspectionId,
                null, 'Inspection #' . $inspectionId . ' rejected: ' . $reason
            );
            json_out(['id' => $inspectionId]);
            break;

        case 'get':
            api_require_auth();
            $id = (int)query('id');
            if ($id <= 0) json_err('id is required');

            try {
                json_out(QualityService::getInspection($id));
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 404);
            }
            break;

        case 'list':
            api_require_auth();
            $filters = [
                'status'          => query('status') ?: null,
                'inspection_type' => query('inspection_type') ?: null,
                'inspector'       => query('inspector') ? (int)query('inspector') : null,
                'stock_id'        => query('stock_id') ? (int)query('stock_id') : null,
                'search'          => query('search') ?: null,
                'page'            => query('page') ?: 1,
                'per_page'        => query('per_page') ?: 50,
            ];
            json_out(QualityService::listInspections($filters));
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
