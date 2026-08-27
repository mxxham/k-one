<?php
/**
 * Print module (module=print) - HTML print documents returned inline.
 * Uses output buffering to capture existing print pages.
 */

function handle_print($action) {
    try {
        switch ($action) {
            case "inbound_receipt":
                api_require_auth();
                $id = (int)(query("id") ?: 0);
                if (!$id) json_err("id wajib diisi.", 400);
                json_out(["html" => _capture_print(__DIR__ . "/../../print_inbound.php", ["id" => $id])]);
                break;
            case "putaway":
                api_require_auth();
                $id = (int)(query("id") ?: 0);
                if (!$id) json_err("id wajib diisi.", 400);
                json_out(["html" => _capture_print(__DIR__ . "/../../putaway_sheet.php", ["id" => $id])]);
                break;
            case "outbound_do":
                api_require_auth();
                $id = (int)(query("id") ?: 0);
                if (!$id) json_err("id wajib diisi.", 400);
                json_out(["html" => _capture_print(__DIR__ . "/../../print_outbound.php", ["id" => $id])]);
                break;
            case "surat_jalan":
                api_require_auth();
                $id = (int)(query("id") ?: 0);
                if (!$id) json_err("id wajib diisi.", 400);
                json_out(["html" => _capture_print(__DIR__ . "/../../surat_jalan.php", ["id" => $id])]);
                break;
            case "picklist":
                api_require_auth();
                $id = (int)(query("id") ?: 0);
                if (!$id) {
                    $outboundId = (int)(query("outbound_id") ?: 0);
                    if (!$outboundId) json_err("id atau outbound_id wajib diisi.", 400);
                    $id = Picklist::createFromOutbound($outboundId);
                }
                json_out(["html" => _capture_print(__DIR__ . "/../../print_picklist.php", ["id" => $id])]);
                break;
            case "report":
                api_require_auth();
                $type = query("type") ?: "daily";
                $date = query("date") ?: null;
                $dateTo = query("date_to") ?: null;
                json_out(["html" => _capture_print(__DIR__ . "/../../print_report.php", ["type" => $type, "date" => $date, "date_to" => $dateTo])]);
                break;
            default:
                json_err("Invalid action: " . $action, 404);
        }
    } catch (Throwable $e) {
        json_err($e->getMessage(), 400);
    }
}

function _capture_print($file, $params) {
    foreach ($params as $k => $v) {
        if ($v !== null) $_GET[$k] = $v;
    }
    ob_start();
    try {
        include $file;
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    $html = ob_get_clean();
    return $html ?: "";
}