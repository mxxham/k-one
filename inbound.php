<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Inbound.php';
require_once __DIR__ . '/classes/Product.php';
require_once __DIR__ . '/classes/ExcelExport.php';
require_once __DIR__ . '/classes/ActivityLogger.php';
Auth::requireAuth();
$canWrite = Auth::canWrite();
$canAdmin = Auth::canAdmin();

if (isset($_GET['export'])) {
    $status  = $_GET['status'] ?? null;
    $odNo    = trim($_GET['od_no'] ?? '') ?: null;
    $inbounds = Inbound::getAll($status, 2000, 0, $odNo);
    ExcelExport::exportInbound($inbounds);
    exit;
}

$pageTitle = 'Inbound';
$currentPage = 'inbound';

$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireWrite();
    try {
        if (isset($_POST['create_inbound'])) {
            $data = [
                'order_date'      => $_POST['order_date'],
                'carrier_name'    => trim($_POST['carrier_name'] ?? '') ?: null,
                'po_number'       => $_POST['po_number']       ?? null,
                'shipment_no'     => $_POST['shipment_no']     ?? null,
                'do_number'       => $_POST['do_number']       ?? null,
                'container_no'    => $_POST['container_no']    ?? null,
                'armada_no'       => $_POST['armada_no']       ?? null,
                'production_date' => $_POST['production_date'] ?? null,
                'expected_date'   => $_POST['expected_date']   ?? null,
                'received_by'     => $_POST['received_by_id'] ?? null,
                'received_date'   => $_POST['received_date']   ?? null,
                'status'          => $_POST['status']          ?? 'Draft',
                'notes'           => $_POST['notes']           ?? null,
                'items'           => $_POST['items']           ?? []
            ];
            $id = Inbound::create($data);
            ActivityLogger::log('CREATE_INBOUND', 'inbound', 'Inbound', (int)$id,
                null, "Buat inbound baru, PO: " . ($data['po_number'] ?? '—'));
            header('Location: inbound.php?action=view&id=' . $id . '&success=created');
            exit;
        }
        if (isset($_POST['update_inbound'])) {
            // Ambil status saat ini dari DB — tidak boleh diubah via form edit
            $curStatus = db()->prepare("SELECT status FROM inbound_orders WHERE id=?");
            $curStatus->execute([(int)$_POST['id']]);
            $keepStatus = $curStatus->fetchColumn() ?: 'Draft';
            $data = [
                'order_date'      => $_POST['order_date'],
                'carrier_name'    => trim($_POST['carrier_name'] ?? '') ?: null,
                'po_number'       => $_POST['po_number']       ?? null,
                'shipment_no'     => $_POST['shipment_no']     ?? null,
                'do_number'       => $_POST['do_number']       ?? null,
                'container_no'    => $_POST['container_no']    ?? null,
                'armada_no'       => $_POST['armada_no']       ?? null,
                'production_date' => $_POST['production_date'] ?? null,
                'expected_date'   => $_POST['expected_date']   ?? null,
                'status'          => $keepStatus,
                'notes'           => $_POST['notes']           ?? null,
                'received_by'     => $_POST['received_by_id']  ?? null,
                'received_date'   => $_POST['received_date']   ?? null,
            ];
            Inbound::update($_POST['id'], $data);
            ActivityLogger::log('UPDATE_INBOUND', 'inbound', 'Inbound', (int)$_POST['id'],
                null, "Edit inbound ID " . $_POST['id']);
            header('Location: inbound.php?action=view&id=' . $_POST['id'] . '&success=updated');
            exit;
        }
        if (isset($_POST['add_item'])) {
            
            $palletLocations = [];
            if (!empty($_POST['pallet_locations_json'])) {
                $decoded = json_decode($_POST['pallet_locations_json'], true);
                if (is_array($decoded)) $palletLocations = $decoded;
            }

            
            $inProcessStatus = $_POST['in_process_status'] ?? 'Dues In';
            
            $stockStatusMap  = [
                'Dues In'        => 'Pending',
                'Goods Received' => 'Pending',
                'ATP'            => 'Accepted',
                'Unserviceable'  => 'Rejected',
            ];
            $autoStockStatus = $stockStatusMap[$inProcessStatus] ?? 'Pending';

            
            $location = $_POST['location'] ?? null;
            if ($inProcessStatus === 'Unserviceable') {
                $location = 'QUA_SHELL';
            }

            Inbound::addItem($_POST['inbound_id'], [
                'product_id'        => $_POST['product_id'],
                'batch_number'      => $_POST['batch_number'],
                'od_number'         => $_POST['od_number']  ?? null,
                'so_number'         => $_POST['so_number']  ?? null,
                'location'          => $location,
                'quantity'          => $_POST['quantity'],
                'uom'               => $_POST['uom'],
                'actual_qty'        => $_POST['quantity'],
                'pallet'            => $_POST['pallet'],
                'pallet_no'         => $_POST['pallet_no'] ?? null,
                'cartons_per_pallet'=> $_POST['cartons_per_pallet'] ?? null,
                'manufacture_date'  => $_POST['manufacture_date'] ?? null,
                'exp_date'          => $_POST['exp_date'] ?? null,
                'stock_status'      => $autoStockStatus,
                'in_process_status' => $inProcessStatus,
                'notes'             => $_POST['notes'] ?? null,
                'pallet_locations'  => $palletLocations,
            ]);
            ActivityLogger::log('ADD_INBOUND_ITEM', 'inbound', 'Inbound', (int)$_POST['inbound_id'],
                null, "Tambah item: produk ID " . ($_POST['product_id']??'?') . ", qty " . ($_POST['quantity']??0));
            header('Location: inbound.php?action=view&id=' . $_POST['inbound_id'] . '&success=item_added');
            exit;
        }
        if (isset($_POST['update_item_qty'])) {
            $iid = (int)($_POST['item_id'] ?? 0);
            $bid = (int)($_POST['inbound_id'] ?? 0);
            $newQty = (float)($_POST['new_qty'] ?? 0);
            if ($iid && $bid && $newQty > 0) {
                Inbound::updateItemQty($iid, $newQty);
                ActivityLogger::log('UPDATE_INBOUND_ITEM_QTY', 'inbound', 'Inbound', $bid,
                    null, "Edit qty item ID $iid → $newQty");
            }
            header('Location: inbound.php?action=view&id=' . $bid . '&success=qty_updated');
            exit;
        }
        if (isset($_POST['delete_item'])) {
            // Block if parent order is locked
            $parentOrder = db()->prepare("SELECT status FROM inbound_orders WHERE id=?");
            $parentOrder->execute([(int)$_POST['inbound_id']]);
            $parentStatus = $parentOrder->fetchColumn();
            if (in_array($parentStatus, ['Completed', 'Cancelled'])) {
                throw new Exception('Order sudah ' . $parentStatus . ' dan tidak dapat diedit.');
            }
            // Goods Received items: admin only
            $delItem = db()->prepare("SELECT in_process_status FROM inbound_items WHERE id=?");
            $delItem->execute([(int)$_POST['item_id']]);
            $delIps = $delItem->fetchColumn();
            if ($delIps === 'Goods Received' && !$canAdmin) {
                throw new Exception('Hanya Admin yang dapat menghapus item berstatus Goods Received.');
            }
            Inbound::deleteItem($_POST['item_id']);
            ActivityLogger::log('DELETE_INBOUND_ITEM', 'inbound', 'Inbound', (int)$_POST['inbound_id'],
                null, "Hapus item ID " . $_POST['item_id'] . " dari inbound ID " . $_POST['inbound_id']);
            header('Location: inbound.php?action=view&id=' . $_POST['inbound_id'] . '&success=item_deleted');
            exit;
        }
        if (isset($_POST['update_pallet_no'])) {
            $iid = (int)($_POST['item_id'] ?? 0);
            $bid = (int)($_POST['inbound_id'] ?? 0);
            if ($iid && $bid) {
                Inbound::updateItemPalletNo($iid, $_POST['pallet_no'] ?? null);
                ActivityLogger::log('UPDATE_PALLET_NO', 'inbound', 'Inbound', $bid,
                    null, "Update pallet no item ID $iid → " . ($_POST['pallet_no'] ?? '—'));
            }
            header('Location: inbound.php?action=view&id=' . $bid . '&success=pallet_no_updated');
            exit;
        }
        if (isset($_POST['update_item_dates'])) {
            $iid = (int)($_POST['item_id'] ?? 0);
            $bid = (int)($_POST['inbound_id'] ?? 0);
            if ($iid && $bid) {
                Inbound::updateItemDates($iid, $_POST['manufacture_date'] ?? null, $_POST['exp_date'] ?? null);
                ActivityLogger::log('UPDATE_ITEM_DATES', 'inbound', 'Inbound', $bid,
                    null, "Update tanggal item ID $iid: mfg=" . ($_POST['manufacture_date'] ?? '—') . " exp=" . ($_POST['exp_date'] ?? '—'));
            }
            header('Location: inbound.php?action=view&id=' . $bid . '&success=dates_updated');
            exit;
        }
        if (isset($_POST['update_item_status'])) {
            $allowedProcess = ['Dues In','Goods Received','Unserviceable','ATP'];
            $newProcess = trim($_POST['update_item_status'] ?? '');
            if (in_array($newProcess, $allowedProcess)) {
                $db = db();

                $stockBadge = [
                    'Dues In'        => 'Pending',
                    'Goods Received' => 'Pending',
                    'ATP'            => 'Accepted',
                    'Unserviceable'  => 'Rejected',
                ];
                $newBadge = $stockBadge[$newProcess] ?? 'Pending';

                // Fetch current item + inbound info BEFORE updating
                $itRow = $db->prepare("SELECT ii.*, p.uom_per_pallet,
                        io.order_number, io.order_date, io.id AS io_id
                    FROM inbound_items ii
                    JOIN products p ON p.id = ii.product_id
                    JOIN inbound_orders io ON io.id = ii.inbound_order_id
                    WHERE ii.id = ?");
                $itRow->execute([$_POST['item_id']]);
                $it         = $itRow->fetch();
                $oldProcess = $it['in_process_status'] ?? 'Dues In';
                $pid        = $it['product_id'];
                $batch      = $it['batch_number'] ?? $it['batch_no'] ?? null;
                $totalQty   = floatval($it['actual_qty'] ?? $it['quantity'] ?? 0);
                $uomPerPlt  = max(1, intval($it['uom_per_pallet'] ?? 4));

                // 1. Update inbound_items
                if ($newProcess === 'Unserviceable') {
                    $db->prepare("UPDATE inbound_items
                        SET in_process_status=?, stock_status=?, location='QUA_SHELL'
                        WHERE id=?")
                       ->execute([$newProcess, $newBadge, $_POST['item_id']]);
                } elseif ($oldProcess === 'Unserviceable') {
                    // Rolling back from Unserviceable — clear location
                    $db->prepare("UPDATE inbound_items SET in_process_status=?, stock_status=?, location=NULL WHERE id=?")
                       ->execute([$newProcess, $newBadge, $_POST['item_id']]);
                } else {
                    $db->prepare("UPDATE inbound_items SET in_process_status=?, stock_status=? WHERE id=?")
                       ->execute([$newProcess, $newBadge, $_POST['item_id']]);
                }

                // 2. Stock + ledger operations (always, not gated by inbound status)
                // Helper: get running balance for ledger
                $balRow = $db->prepare("SELECT COALESCE(SUM(quantity_in),0) - COALESCE(SUM(quantity_out),0) AS bal
                    FROM stock_ledger WHERE product_id=? AND (location IS NULL OR location != 'QUA_SHELL')
                    AND transaction_type NOT IN ('TRANSFER_IN','TRANSFER_OUT')");
                $balRow->execute([$pid]);
                $currentBal = floatval($balRow->fetchColumn());
                $plt = (int)ceil($totalQty / $uomPerPlt);

                // Helper: delete existing ledger entry for this item (before re-writing)
                $delLedger = $db->prepare("DELETE FROM stock_ledger
                    WHERE reference_type='Inbound' AND reference_id=?
                    AND product_id=? AND batch_number<=>?");

                if ($newProcess === 'ATP') {
                    // Remove any previously linked stock (rollback safety)
                    $db->prepare("DELETE s FROM stock s
                        JOIN stock_locations sl ON sl.stock_id = s.id
                        WHERE sl.inbound_item_id=?")
                       ->execute([$_POST['item_id']]);
                    $db->prepare("UPDATE stock_locations SET stock_id=NULL WHERE inbound_item_id=?")
                       ->execute([$_POST['item_id']]);
                    if ($oldProcess === 'Unserviceable') {
                        $db->prepare("DELETE FROM stock
                            WHERE product_id=? AND batch_number<=>? AND location='QUA_SHELL' AND stock_status='Rejected'")
                           ->execute([$pid, $batch]);
                    }
                    // Stock is created only when order is Completed
                    // Replace any previous ledger entry (GR/Unserviceable) with ATP entry
                    $delLedger->execute([$it['io_id'], $pid, $batch]);
                    $balRow->execute([$pid]);
                    $currentBal = floatval($balRow->fetchColumn());
                    $db->prepare("INSERT INTO stock_ledger
                        (transaction_date,product_id,transaction_type,reference_type,reference_id,
                         reference_number,batch_number,quantity_in,quantity_out,uom,pallet,balance,location,notes)
                        VALUES (?,?,'IN','Inbound',?,?,?,?,0,?,?,?,?,?)")
                       ->execute([date('Y-m-d'),$pid,$it['io_id'],$it['order_number'],$batch,
                                  $totalQty,$it['uom'],$plt,
                                  $currentBal + $totalQty,
                                  $it['location'] ?? null,
                                  '[Inbound] ATP | In-Process: ATP | ' . $it['order_number']]);

                } elseif ($newProcess === 'Goods Received') {
                    // Remove Available stock if rolling back from ATP
                    if ($oldProcess === 'ATP') {
                        $db->prepare("DELETE s FROM stock s
                            JOIN stock_locations sl ON sl.stock_id = s.id
                            WHERE sl.inbound_item_id=?")
                           ->execute([$_POST['item_id']]);
                        $db->prepare("UPDATE stock_locations SET stock_id=NULL WHERE inbound_item_id=?")
                           ->execute([$_POST['item_id']]);
                        $db->prepare("DELETE FROM stock
                            WHERE product_id=? AND batch_number<=>? AND stock_status='Available'")
                           ->execute([$pid, $batch]);
                    }
                    if ($oldProcess === 'Unserviceable') {
                        $db->prepare("DELETE FROM stock
                            WHERE product_id=? AND batch_number<=>? AND location='QUA_SHELL' AND stock_status='Rejected'")
                           ->execute([$pid, $batch]);
                    }
                    // Replace any previous ledger entry with GR entry
                    $delLedger->execute([$it['io_id'], $pid, $batch]);
                    $balRow->execute([$pid]);
                    $currentBal = floatval($balRow->fetchColumn());
                    $db->prepare("INSERT INTO stock_ledger
                        (transaction_date,product_id,transaction_type,reference_type,reference_id,
                         reference_number,batch_number,quantity_in,quantity_out,uom,pallet,balance,location,notes)
                        VALUES (?,?,'IN','Inbound',?,?,?,?,0,?,?,?,?,?)")
                       ->execute([date('Y-m-d'),$pid,$it['io_id'],$it['order_number'],$batch,
                                  $totalQty,$it['uom'],$plt,
                                  $currentBal + $totalQty,
                                  $it['location'] ?? null,
                                  '[Inbound] Goods Received | In-Process: Goods Received | ' . $it['order_number']]);

                } elseif ($newProcess === 'Unserviceable') {
                    // Remove linked Available stock
                    $db->prepare("DELETE s FROM stock s
                        JOIN stock_locations sl ON sl.stock_id = s.id
                        WHERE sl.inbound_item_id=?")
                       ->execute([$_POST['item_id']]);
                    $db->prepare("DELETE FROM stock_locations WHERE inbound_item_id=?")
                       ->execute([$_POST['item_id']]);
                    $db->prepare("DELETE FROM stock
                        WHERE product_id=? AND batch_number<=>? AND stock_status IN ('Available','Dues In','Pending')")
                       ->execute([$pid, $batch]);
                    // Create Rejected stock at QUA_SHELL
                    $db->prepare("INSERT INTO stock
                        (product_id,batch_number,location,quantity,uom,pallet,manufacture_date,expiry_date,stock_status)
                        VALUES (?,?,'QUA_SHELL',?,?,?,?,?,'Rejected')")
                       ->execute([$pid,$batch,$totalQty,$it['uom'],$plt,$it['manufacture_date'],$it['exp_date']]);
                    // Replace any previous ledger entry with Unserviceable entry
                    $delLedger->execute([$it['io_id'], $pid, $batch]);
                    $balRow->execute([$pid]);
                    $currentBal = floatval($balRow->fetchColumn());
                    $db->prepare("INSERT INTO stock_ledger
                        (transaction_date,product_id,transaction_type,reference_type,reference_id,
                         reference_number,batch_number,quantity_in,quantity_out,uom,pallet,balance,location,notes)
                        VALUES (?,?,'IN','Inbound',?,?,?,?,0,?,?,?,'QUA_SHELL',?)")
                       ->execute([date('Y-m-d'),$pid,$it['io_id'],$it['order_number'],$batch,
                                  $totalQty,$it['uom'],$plt,
                                  $currentBal,
                                  '[Inbound] Unserviceable (QUA_SHELL) | In-Process: Unserviceable | ' . $it['order_number']]);

                } elseif ($newProcess === 'Dues In') {
                    // Rollback: remove stock + delete ledger entry for this item
                    $db->prepare("DELETE s FROM stock s
                        JOIN stock_locations sl ON sl.stock_id = s.id
                        WHERE sl.inbound_item_id=?")
                       ->execute([$_POST['item_id']]);
                    $db->prepare("UPDATE stock_locations SET stock_id=NULL WHERE inbound_item_id=?")
                       ->execute([$_POST['item_id']]);
                    $db->prepare("DELETE FROM stock
                        WHERE product_id=? AND batch_number<=>?
                        AND stock_status IN ('Available','Dues In','Pending','Rejected')")
                       ->execute([$pid, $batch]);
                    $delLedger->execute([$it['io_id'], $pid, $batch]);
                }

                ActivityLogger::log('UPDATE_ITEM_STATUS', 'inbound', 'Inbound',
                    (int)$_POST['inbound_id'], null,
                    "Status item ID {$_POST['item_id']}: $oldProcess → $newProcess");
            }
            header('Location: inbound.php?action=view&id=' . $_POST['inbound_id']);
            exit;
        }

        if (isset($_POST['save_pallet_locations'])) {
            $palletJson = $_POST['pallet_locations_json'] ?? '[]';
            $pallets    = json_decode($palletJson, true);
            if ($pallets && is_array($pallets) && $_POST['item_id']) {
                $db = db();
                $specialLocs = ['QUA_SHELL','STAGING'];
                // Validate all pallet locations against master
                $invalidLocs = [];
                foreach ($pallets as $p) {
                    $pLoc = strtoupper(trim($p['location_code'] ?? ''));
                    if ($pLoc && !in_array($pLoc, $specialLocs)) {
                        $locCheck = $db->prepare("SELECT id FROM location_master WHERE location_code=? AND is_active=1 LIMIT 1");
                        $locCheck->execute([$pLoc]);
                        if (!$locCheck->fetch()) {
                            $invalidLocs[] = $pLoc;
                        }
                    }
                }
                if ($invalidLocs) {
                    $_SESSION['flash_error'] = "Lokasi tidak valid: " . implode(', ', $invalidLocs);
                    header('Location: inbound.php?action=view&id=' . $_POST['inbound_id']);
                    exit;
                }

                $db->prepare("DELETE FROM stock_locations WHERE inbound_item_id=?")
                   ->execute([$_POST['item_id']]);
                $itRow = $db->prepare("SELECT * FROM inbound_items WHERE id=?");
                $itRow->execute([$_POST['item_id']]);
                $it    = $itRow->fetch();
                $batch = $it['batch_number'] ?? $it['batch_no'] ?? null;
                $firstLoc = $pallets[0]['location_code'] ?? null;
                $db->prepare("UPDATE inbound_items SET location=? WHERE id=?")
                   ->execute([$firstLoc, $_POST['item_id']]);
                foreach ($pallets as $p) {
                    $pQty = floatval($p['quantity'] ?? 0);
                    $db->prepare("INSERT INTO stock_locations
                        (inbound_item_id, location_code, pallet_seq, quantity, original_quantity, uom, is_full_pallet, batch_number, status)
                        VALUES (?,?,?,?,?,?,?,?,'Available')")
                       ->execute([
                           $_POST['item_id'],
                           $p['location_code'],
                           $p['pallet_seq'],
                           $pQty,
                           $pQty,
                           $it['uom'] ?? 'Drum',
                           !empty($p['is_full']) ? 1 : 0,
                           $batch,
                       ]);
                }
                ActivityLogger::log('SAVE_PALLET_LOCATIONS', 'inbound', 'Inbound', (int)$_POST['inbound_id'],
                    null, "Simpan " . count($pallets) . " pallet location untuk item ID " . $_POST['item_id']);
            }
            header('Location: inbound.php?action=view&id=' . $_POST['inbound_id']);
            exit;
        }

        if (isset($_POST['save_item_location'])) {
            $loc = strtoupper(trim($_POST['assign_location'] ?? ''));
            $specialLocs = ['QUA_SHELL','STAGING'];
            if ($loc && $_POST['item_id']) {
                $db = db();
                // Validate location against master unless it's a special location
                if (!in_array($loc, $specialLocs)) {
                    $locCheck = $db->prepare("SELECT id FROM location_master WHERE location_code=? AND is_active=1 LIMIT 1");
                    $locCheck->execute([$loc]);
                    if (!$locCheck->fetch()) {
                        $_SESSION['flash_error'] = "Lokasi '$loc' tidak ditemukan di master lokasi.";
                        header('Location: inbound.php?action=view&id=' . $_POST['inbound_id']);
                        exit;
                    }
                }
                $db->prepare("UPDATE inbound_items SET location=? WHERE id=?")->execute([$loc, $_POST['item_id']]);
                $db->prepare("UPDATE stock_locations SET location_code=? WHERE inbound_item_id=?")->execute([$loc, $_POST['item_id']]);
                $ibSt = $db->prepare("SELECT status FROM inbound_orders WHERE id=?");
                $ibSt->execute([$_POST['inbound_id']]);
                if ($ibSt->fetchColumn() === 'Completed') {
                    $it = $db->prepare("SELECT * FROM inbound_items WHERE id=?");
                    $it->execute([$_POST['item_id']]);
                    $itRow = $it->fetch();
                    $batch = $itRow['batch_number'] ?? $itRow['batch_no'] ?? null;
                    $db->prepare("UPDATE stock SET location=? WHERE product_id=? AND batch_number<=>?")->execute([$loc, $itRow['product_id'], $batch]);
                }
                ActivityLogger::log('ASSIGN_LOCATION', 'inbound', 'Inbound', (int)$_POST['inbound_id'],
                    null, "Assign lokasi item ID " . $_POST['item_id'] . " → $loc");
            }
            header('Location: inbound.php?action=view&id=' . $_POST['inbound_id']);
            exit;
        }
        if (isset($_POST['advance_status'])) {
            $advId     = (int)($_POST['id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? '';
            $validAdvance = ['Dues In', 'Receiving'];
            if ($advId && in_array($newStatus, $validAdvance)) {
                if ($newStatus === 'Receiving') {
                    $receivedById = (int)($_POST['received_by_id'] ?? 0);
                    $receivedDate = trim($_POST['received_date'] ?? '');
                    if (empty($receivedById) || empty($receivedDate)) {
                        throw new Exception('Received By dan Received Date wajib diisi saat Start Receiving.');
                    }
                    db()->prepare("UPDATE inbound_orders SET status=?, received_by=?, received_date=? WHERE id=?")
                       ->execute([$newStatus, $receivedById, $receivedDate ?: null, $advId]);
                } else {
                    db()->prepare("UPDATE inbound_orders SET status=? WHERE id=?")
                       ->execute([$newStatus, $advId]);
                }
                ActivityLogger::log('ADVANCE_INBOUND_STATUS', 'inbound', 'Inbound', $advId,
                    null, "Status inbound → $newStatus");
            }
            header('Location: inbound.php?action=view&id=' . $advId . '&success=updated');
            exit;
        }
        if (isset($_POST['complete_inbound'])) {
            $cid = (int)$_POST['id'];
            // Validasi: semua item harus ATP atau Unserviceable
            $pendingCheck = db()->prepare("
                SELECT COUNT(*) FROM inbound_items
                WHERE inbound_order_id = ?
                  AND in_process_status NOT IN ('ATP','Unserviceable')
            ");
            $pendingCheck->execute([$cid]);
            $pendingCount = (int)$pendingCheck->fetchColumn();
            if ($pendingCount > 0) {
                throw new Exception("Tidak dapat complete: masih ada $pendingCount item yang belum ATP atau Unserviceable. Update status setiap item terlebih dahulu.");
            }
            set_time_limit(300);
            Inbound::complete($cid);
            ActivityLogger::log('COMPLETE_INBOUND', 'inbound', 'Inbound', $cid,
                null, "Inbound ID $cid diselesaikan");
            header('Location: inbound.php?action=view&id=' . $cid . '&success=completed');
            exit;
        }
        if (isset($_POST['repair_ledger'])) {
            $rid = (int)$_POST['id'];
            Inbound::regenerateLedger($rid);
            ActivityLogger::log('REPAIR_LEDGER', 'inbound', 'Inbound', $rid,
                null, "Regenerasi ledger inbound ID $rid");
            header('Location: inbound.php?action=view&id=' . $rid . '&success=ledger_repaired');
            exit;
        }
        if (isset($_POST['delete_inbound'])) {
            $delCheck = db()->prepare("SELECT status FROM inbound_orders WHERE id=?");
            $delCheck->execute([(int)$_POST['id']]);
            $delStatus = $delCheck->fetchColumn();
            if (in_array($delStatus, ['Completed', 'Cancelled'])) {
                throw new Exception('Order sudah ' . $delStatus . ' dan tidak dapat dihapus.');
            }
            Inbound::delete($_POST['id']);
            ActivityLogger::log('DELETE_INBOUND', 'inbound', 'Inbound', (int)$_POST['id'],
                null, "Hapus inbound ID " . $_POST['id']);
            header('Location: inbound.php?action=list&success=deleted');
            exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$inbound      = $id ? Inbound::getById($id) : null;
$inboundItems = $inbound ? Inbound::getItems($id) : [];

// Pallet count per item: use location count if assigned, else master data
$itemPalletCounts = [];
foreach ($inboundItems as $_it) {
    $locs = Inbound::getItemLocations($_it['id']);
    $itemPalletCounts[$_it['id']] = !empty($locs) ? count($locs) : (int)ceil($_it['pallet'] ?? 0);
}
$totalPalletCount = array_sum($itemPalletCounts);

$searchOdNo   = trim($_GET['od_no'] ?? '');
$perPage      = 50;
$page         = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($page - 1) * $perPage;
$totalCount   = Inbound::countAll(null, $searchOdNo ?: null);
$totalPages   = max(1, (int)ceil($totalCount / $perPage));
$page         = min($page, $totalPages);
$inboundList  = Inbound::getAll(null, $perPage, $offset, $searchOdNo ?: null);
$products     = Product::getAll();
$usersList    = db()->query("SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll();

$hasEditableItems = true; 
$canEdit = $canWrite && $inbound && !in_array($inbound['status'], ['Completed','Cancelled']);

require_once __DIR__ . '/includes/header.php';
?>

<style>
:root {
  --ib-primary:   #026766;
  --ib-accent:    #026766;
  --ib-light:     #e0f7f7;
  --ib-success:   #026766;
  --ib-warning:   #f57c00;
  --ib-danger:    #014f4e;
  --ib-muted:     #607d8b;
  --ib-border:    #dde5ee;
  --ib-bg:        #f2f5f9;
  --ib-card:      #ffffff;
  --ib-radius:    10px;
  --ib-shadow:    0 1px 4px rgba(2,103,102,.07), 0 4px 16px rgba(2,103,102,.05);
}

.ib-page { font-family: 'Inter','Segoe UI', system-ui, sans-serif; background: var(--ib-bg); color: #013d3c; }

.ib-hero {
  background: linear-gradient(135deg, #013d3c 0%, #026766 55%, #02908f 100%);
  border-radius: var(--ib-radius);
  padding: 24px 30px;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  box-shadow: 0 4px 20px rgba(13,59,110,.28);
  flex-wrap: wrap;
}
.ib-hero-title { font-size: 1.45rem; font-weight: 700; letter-spacing: -.3px; }
.ib-hero-sub   { font-size: .82rem; opacity: .75; margin-top: 3px; }
.ib-stat-pill {
  background: rgba(255,255,255,.13);
  border: 1px solid rgba(255,255,255,.22);
  border-radius: 8px;
  padding: 9px 16px;
  text-align: center;
  min-width: 76px;
}
.ib-stat-pill .num  { font-size: 1.4rem; font-weight: 700; line-height: 1; }
.ib-stat-pill .lbl  { font-size: .68rem; opacity: .72; margin-top: 2px; letter-spacing: .04em; text-transform: uppercase; }

.ib-card {
  background: var(--ib-card);
  border-radius: var(--ib-radius);
  border: 1px solid var(--ib-border);
  box-shadow: var(--ib-shadow);
  overflow: hidden;
  margin-bottom: 18px;
}
.ib-card-header {
  padding: 14px 22px;
  border-bottom: 1px solid var(--ib-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #f0fbfb;
}
.ib-card-header h2 { font-size: .95rem; font-weight: 700; color: var(--ib-primary); margin: 0; }
.ib-card-body  { padding: 22px; }

.ib-section {
  background: #f0fbfb;
  border: 1px solid var(--ib-border);
  border-radius: 8px;
  padding: 18px 20px;
  margin-bottom: 14px;
}
.ib-section-title {
  font-size: .72rem;
  font-weight: 700;
  letter-spacing: .07em;
  text-transform: uppercase;
  color: var(--ib-primary);
  margin-bottom: 14px;
  display: flex;
  align-items: center;
  gap: 7px;
  padding-bottom: 10px;
  border-bottom: 1px solid var(--ib-border);
}

.ib-label {
  display: block;
  font-size: .75rem;
  font-weight: 600;
  color: #455a64;
  margin-bottom: 4px;
  letter-spacing: .01em;
}
.ib-label .req { color: var(--ib-danger); margin-left: 2px; }

.ib-input, .ib-select, .ib-textarea {
  width: 100%;
  padding: 8px 12px;
  border: 1.5px solid #cdd6e0;
  border-radius: 7px;
  font-size: .875rem;
  color: #013d3c;
  background: #fff;
  transition: border-color .15s, box-shadow .15s;
  outline: none;
  box-sizing: border-box;
}
.ib-input:focus, .ib-select:focus, .ib-textarea:focus {
  border-color: var(--ib-accent);
  box-shadow: 0 0 0 3px rgba(25,118,210,.1);
}
.ib-input::placeholder { color: #80b2b2; }
.ib-input-hint { font-size: .71rem; color: var(--ib-muted); margin-top: 3px; }

.ib-input-icon { position: relative; }
.ib-input-icon .ib-input { padding-left: 32px; }
.ib-input-icon i {
  position: absolute; left: 10px; top: 50%;
  transform: translateY(-50%);
  color: #90a4ae; font-size: .77rem;
}

.ib-input-ro {
  background: #f4f7fa;
  color: #013d3c;
  font-weight: 600;
  cursor: default;
}

.ib-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 18px; border-radius: 7px; font-size: .845rem;
  font-weight: 600; cursor: pointer; border: none; transition: .15s;
  text-decoration: none; white-space: nowrap;
}
.ib-btn-primary { background: var(--ib-accent); color: #fff; }
.ib-btn-primary:hover { background: #014f4e; }
.ib-btn-success { background: var(--ib-success); color: #fff; }
.ib-btn-success:hover { background: #00796b; }
.ib-btn-ghost   { background: #edf1f5; color: #546e7a; }
.ib-btn-ghost:hover { background: #dde4ea; }
.ib-btn-danger  { background: #e0f7f7; color: var(--ib-danger); }
.ib-btn-danger:hover { background: #b2e5e5; }
.ib-btn-warning { background: #fff3e0; color: #e65100; }
.ib-btn-warning:hover { background: #fff3e0; }
.ib-btn-sm { padding: 5px 12px; font-size: .78rem; }
.ib-btn-outline {
  background: transparent; border: 1.5px solid var(--ib-accent);
  color: var(--ib-accent);
}
.ib-btn-outline:hover { background: var(--ib-light); }

.ib-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 11px; border-radius: 20px;
  font-size: .7rem; font-weight: 700; letter-spacing: .03em;
}
.ib-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.ib-badge-draft     { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
.ib-badge-draft::before { background: #94a3b8; }
.ib-badge-dues      { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.ib-badge-dues::before { background: #3b82f6; }
.ib-badge-receiving { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
.ib-badge-receiving::before { background: #f97316; }
.ib-badge-received  { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.ib-badge-received::before { background: #22c55e; }
.ib-badge-completed { background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; }
.ib-badge-completed::before { background: #10b981; }
.ib-badge-atp       { background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; }
.ib-badge-atp::before { background: #10b981; }
.ib-badge-cancelled { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.ib-badge-cancelled::before { background: #ef4444; }

.ib-table { width: 100%; border-collapse: collapse; font-size: .858rem; }
.ib-table thead tr { background: #e6f7f7; }
.ib-table th {
  padding: 10px 13px; text-align: left;
  font-size: .69rem; font-weight: 700; letter-spacing: .06em;
  text-transform: uppercase; color: var(--ib-muted);
  border-bottom: 2px solid var(--ib-border);
  white-space: nowrap;
}
.ib-table td { padding: 10px 13px; border-bottom: 1px solid #e6f7f7; color: #013d3c; vertical-align: top; }
.ib-table tbody tr:hover { background: #f7fafd; }
.ib-table tbody tr:last-child td { border-bottom: none; }

.ib-uom {
  display: inline-block; padding: 2px 8px; border-radius: 5px;
  font-size: .68rem; font-weight: 700;
}
.ib-uom-drum   { background: #e0f7f7; color: #013d3c; }
.ib-uom-carton { background: #e0f7f7; color: #013d3c; }
.ib-uom-pail   { background: #fff8e1; color: #e65100; }
.ib-uom-ea     { background: #e0f7f7; color: #013d3c; }
.ib-uom-bags   { background: #e0f7f7; color: #013d3c; }

.ib-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(175px, 1fr)); gap: 14px; }
.ib-info-box .key {
  font-size: .69rem; color: var(--ib-muted); font-weight: 700;
  text-transform: uppercase; letter-spacing: .05em; margin-bottom: 3px;
}
.ib-info-box .val { font-size: .875rem; color: #013d3c; font-weight: 600; }

.ib-ref-chip {
  display: inline-block; padding: 2px 8px; border-radius: 4px;
  font-size: .69rem; font-weight: 700; font-family: monospace;
  margin: 1px 2px;
}
.ib-ref-od { background: #e0f7f7; color: #013d3c; border: 1px solid #b2e5e5; }
.ib-ref-so { background: #e0f7f7; color: #283593; border: 1px solid #c5cae9; }

.ib-pallet-ok  { background: #e0f7f7; border-color: #a5d6a7 !important; color: #013d3c; font-weight: 700; }
.ib-pallet-err { background: #e0f7f7; border-color: #80d2d2 !important; color: #014f4e; font-weight: 700; }

.ib-alert { padding: 11px 15px; border-radius: 7px; font-size: .858rem; display: flex; align-items: center; gap: 10px; }
.ib-alert-success { background: #e0f7f7; border-left: 4px solid var(--ib-success); color: #013d3c; }
.ib-alert-error   { background: #e0f7f7; border-left: 4px solid var(--ib-danger); color: #014f4e; }
.ib-alert-info    { background: #e0f7f7; border-left: 4px solid var(--ib-accent); color: #013d3c; }

.ib-empty { padding: 48px 16px; text-align: center; color: var(--ib-muted); }
.ib-empty i { font-size: 2.5rem; opacity: .3; margin-bottom: 12px; display: block; }

.ib-workflow { display: flex; overflow: hidden; border-radius: 6px; border: 1px solid var(--ib-border); }
.ib-wf-step {
  flex: 1; text-align: center; padding: 8px 4px;
  font-size: .67rem; font-weight: 700; letter-spacing: .05em;
  text-transform: uppercase; background: #e6f7f7; color: #90a4ae;
  position: relative;
}
.ib-wf-step::after {
  content: ''; position: absolute; right: -8px; top: 0; bottom: 0; width: 0;
  border-left: 8px solid #e6f7f7;
  border-top: 18px solid transparent;
  border-bottom: 18px solid transparent;
  z-index: 1;
}
.ib-wf-step:last-child::after { display: none; }
.ib-wf-active { background: var(--ib-primary); color: #fff; }
.ib-wf-active::after { border-left-color: var(--ib-primary); }
.ib-wf-done   { background: var(--ib-success); color: #fff; }
.ib-wf-done::after { border-left-color: var(--ib-success); }

/* Item status pill in table */
.ib-ips-pill {
  display: inline-flex; align-items: center; gap: 5px;
  border-radius: 20px; padding: 4px 11px; font-size: .72rem; font-weight: 700;
  border: 1.5px solid;
}
.ib-ips-dues      { background:#fefce8; color:#854d0e; border-color:#fde68a; }
.ib-ips-gr        { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
.ib-ips-atp       { background:#ecfdf5; color:#065f46; border-color:#6ee7b7; }
.ib-ips-unserv    { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }

@media (max-width: 700px) {
  .ib-hero { flex-direction: column; align-items: flex-start; }
  .ib-card-body { padding: 16px; }
  .ib-info-grid { grid-template-columns: 1fr 1fr; }
}
</style>

<script>
/* ============================================================
   Pallet Location Modal — editable per-pallet, auto-suggest
   ============================================================ */
var _plItemId = null, _plRows = [], _plUom, _plUpp, _plQty;

function openPalletLocModal(itemId, nPallets, uom, upp, qty) {
    _plItemId = String(itemId);
    _plUom = uom; _plUpp = Math.max(parseFloat(upp)||1, 0.01); _plQty = parseFloat(qty)||0;

    // Load existing saved locations if any, else start with one row = full qty
    var saved = window['LOC_SAVED_' + itemId] || [];
    if (saved.length > 0) {
        _plRows = saved.map(function(r, i) {
            return { pallet_seq: i+1, location_code: r.location_code||'', quantity: parseFloat(r.quantity)||0 };
        });
    } else {
        _plRows = [{ pallet_seq:1, location_code:'', quantity: _plQty }];
    }
    renderPlRows();
    document.getElementById('palletLocModal').style.display = 'flex';
}

/* Auto-suggest: split qty into full pallets + remainder */
function plAutoSuggest() {
    var auto = window['LOC_AUTO_' + _plItemId] || [];
    _plRows = [];
    var nFull = Math.floor(_plQty / _plUpp);
    var rem   = Math.round((_plQty - nFull * _plUpp) * 1000) / 1000;
    for (var i = 0; i < nFull; i++) {
        _plRows.push({ pallet_seq: i+1, location_code: (auto[i] && auto[i].location_code)||'', quantity: _plUpp });
    }
    if (rem > 0) {
        _plRows.push({ pallet_seq: nFull+1, location_code: (auto[nFull] && auto[nFull].location_code)||'', quantity: rem });
    }
    if (_plRows.length === 0) {
        _plRows = [{ pallet_seq:1, location_code:'', quantity: _plQty }];
    }
    renderPlRows();
}

/* Add blank row with remaining qty */
function plAddRow() {
    var used = _plRows.reduce(function(s,r){ return s + parseFloat(r.quantity||0); }, 0);
    var rem  = Math.max(0, Math.round((_plQty - used)*1000)/1000);
    _plRows.push({ pallet_seq: _plRows.length+1, location_code:'', quantity: rem });
    renderPlRows();
}

function plRemoveRow(idx) {
    if (_plRows.length <= 1) return;
    _plRows.splice(idx, 1);
    _plRows.forEach(function(r,i){ r.pallet_seq = i+1; });
    renderPlRows();
}

function plUpdateQty(idx, val) {
    var v = parseFloat(val);
    _plRows[idx].quantity = isNaN(v) ? 0 : Math.max(0, v);
    plUpdateSummary();
}

function plPickLoc(idx, code) {
    _plRows[idx].location_code = code;
    _plRows[idx]._invalid = false;
    _plRows[idx]._typedValue = '';
    var inp = document.getElementById('plLoc_' + _plItemId + '_' + idx);
    if (inp) {
        inp.value = code;
        inp.style.border = '2px solid #10b981';
        inp.style.background = '#f0fdf4';
    }
    var dd = document.getElementById('plDd_' + _plItemId + '_' + idx);
    if (dd) dd.style.display = 'none';
    var warnEl = document.getElementById('plWarn_' + _plItemId + '_' + idx);
    if (warnEl) warnEl.remove();
    plUpdateSummary();
    // Do NOT call renderPlRows() — it destroys the entire DOM mid-event
}

function plShowDd(idx) {
    document.querySelectorAll('[id^="plDd_"]').forEach(function(d){ d.style.display='none'; });
    var dd = document.getElementById('plDd_' + _plItemId + '_' + idx);
    if (dd) dd.style.display = 'block';
}

function plFilterDd(idx, q) {
    var dd = document.getElementById('plDd_' + _plItemId + '_' + idx);
    if (!dd) return;
    dd.style.display = 'block';
    var ql = q.toUpperCase();
    var opts = dd.querySelectorAll('.plopt');
    opts.forEach(function(o){
        o.style.display = (!ql || o.dataset.code.indexOf(ql) >= 0) ? '' : 'none';
    });
}

/* Called oninput — clear any existing warning while user is still typing */
function plClearLocWarning(idx) {
    _plRows[idx]._invalid = false;  // reset invalid flag while actively editing
    var warnEl = document.getElementById('plWarn_' + _plItemId + '_' + idx);
    if (warnEl) warnEl.remove();
    var inp = document.getElementById('plLoc_' + _plItemId + '_' + idx);
    if (inp && !_plRows[idx].location_code) {
        inp.style.border = '2px solid #d1d5db';
        inp.style.background = '#fff';
    }
    plUpdateSummary();
}

/* Called onblur — validate typed text against master data */
function plBlurLoc(idx) {
    var inp = document.getElementById('plLoc_' + _plItemId + '_' + idx);
    /* Input may have been replaced by renderPlRows — skip silently */
    if (!inp) return;

    /* If location already confirmed (e.g. via mousedown pick), just refresh styling and exit */
    var row = _plRows[idx];
    if (row && row.location_code && !row._invalid) {
        inp.style.border = '2px solid #10b981';
        inp.style.background = '#f0fdf4';
        plUpdateSummary();
        return;
    }

    var typed = inp.value.trim().toUpperCase();

    /* Close dropdown */
    var dd = document.getElementById('plDd_' + _plItemId + '_' + idx);
    if (dd) dd.style.display = 'none';

    /* Empty — clear state */
    if (!typed) {
        _plRows[idx].location_code = '';
        _plRows[idx]._invalid = false;
        inp.style.border = '2px solid #d1d5db';
        inp.style.background = '#fff';
        var oldW = document.getElementById('plWarn_' + _plItemId + '_' + idx);
        if (oldW) oldW.remove();
        plUpdateSummary();
        return;
    }

    /* Validate typed text against LOC_DATA */
    var locs = window['LOC_DATA_' + _plItemId] || [];
    var match = null;
    for (var li = 0; li < locs.length; li++) {
        if (locs[li].code.toUpperCase() === typed) { match = locs[li]; break; }
    }

    if (match) {
        /* Valid — confirm */
        _plRows[idx].location_code = match.code;
        _plRows[idx]._invalid = false;
        _plRows[idx]._typedValue = '';
        inp.value = match.code;
        inp.style.border = '2px solid #10b981';
        inp.style.background = '#f0fdf4';
        var w = document.getElementById('plWarn_' + _plItemId + '_' + idx);
        if (w) w.remove();
    } else {
        /* Invalid — show warning, block save */
        var typedVal = inp.value.trim();
        _plRows[idx].location_code = '';
        _plRows[idx]._invalid = true;
        _plRows[idx]._typedValue = typedVal;
        inp.style.border = '2px solid #ef4444';
        inp.style.background = '#fef2f2';
        var oldWarn = document.getElementById('plWarn_' + _plItemId + '_' + idx);
        if (oldWarn) oldWarn.remove();
        var warnEl = document.createElement('div');
        warnEl.id = 'plWarn_' + _plItemId + '_' + idx;
        warnEl.style.cssText = 'color:#b91c1c;font-size:.68rem;font-weight:600;margin-top:3px;'
            + 'background:#fef2f2;border:1px solid #fecaca;border-radius:5px;padding:3px 8px;'
            + 'display:flex;align-items:center;gap:4px';
        warnEl.innerHTML = '<i class="fas fa-exclamation-circle"></i>&nbsp;"<b>'
            + typedVal + '</b>" tidak ada di master lokasi — pilih dari daftar';
        inp.parentNode.appendChild(warnEl);
    }
    plUpdateSummary();
}

function renderPlRows() {
    var locs = window['LOC_DATA_' + _plItemId] || [];
    var html = '';

    _plRows.forEach(function(row, i) {
        var qty    = parseFloat(row.quantity) || 0;
        var isFull = qty >= _plUpp;
        var hasLoc = !!row.location_code;
        var locBorder = hasLoc ? '#10b981' : '#d1d5db';
        var locBg     = hasLoc ? '#f0fdf4' : '#fff';

        /* Location dropdown options */
        var ddHtml = '<div style="padding:4px 10px;font-size:.65rem;font-weight:700;color:#6b7280;background:#f9fafb;border-bottom:1px solid #e5e7eb">🟢 Full · 🟡 Partial · 🔴 Terisi</div>';
        locs.forEach(function(l) {
            var isSelected = l.code === row.location_code;
            var unavail    = l.occupied && !isSelected;
            var dot  = l.occupied ? '🔴' : (l.row==='A'?'🟡':'🟢');
            var col  = l.row==='A' ? '#d97706' : '#065f46';
            var hint = l.occupied ? ((l.occ_prod?l.occ_prod+' ':'')+l.occ_qty+' '+l.occ_uom) : 'available';
            // Use onmousedown+preventDefault so plPickLoc fires BEFORE blur destroys the dropdown
            // Use this.dataset.code to avoid HTML double-quote escaping issues
            ddHtml += '<div class="plopt" data-code="'+l.code+'"'
                + (unavail ? ' data-unavail="1"' : '')
                + ' onmousedown="event.preventDefault();if(!this.dataset.unavail)plPickLoc('+i+',this.dataset.code)"'
                + ' style="padding:6px 12px;cursor:'+(unavail?'not-allowed':'pointer')+';'
                + 'opacity:'+(unavail?'0.35':'1')+';background:'+(isSelected?'#ecfdf5':'#fff')+';'
                + 'display:flex;justify-content:space-between;border-bottom:1px solid #f3f4f6;font-size:.78rem">'
                + '<span>'+dot+' <b style="font-family:monospace;color:'+col+'">'+l.code+'</b>'
                + ' <small style="color:#9ca3af;margin-left:4px">'+(l.row==='A'?'Partial':'Full')+'</small></span>'
                + '<small style="color:#6b7280">'+hint+'</small></div>';
        });
        if (!locs.length) ddHtml += '<div style="padding:10px;color:#9ca3af;text-align:center;font-size:.8rem">Tidak ada lokasi</div>';

        html += '<div style="display:grid;grid-template-columns:34px 1fr 96px 56px 30px;'
            + 'gap:8px;align-items:center;padding:10px 0;border-bottom:1px solid #f3f4f6">'

            /* No. pallet */
            + '<div style="font-size:.75rem;font-weight:800;color:#64748b;text-align:center">P'+(i+1)+'</div>'

            /* Location search */
            var isInvalid = !!row._invalid;
            var locBorderEff = isInvalid ? '#ef4444' : locBorder;
            var locBgEff     = isInvalid ? '#fef2f2' : locBg;
            var locIcon = hasLoc
                ? '<span style="position:absolute;right:9px;top:50%;transform:translateY(-50%);color:#10b981;font-size:.8rem">✓</span>'
                : (isInvalid ? '<span style="position:absolute;right:9px;top:50%;transform:translateY(-50%);color:#ef4444;font-size:.8rem"><i class="fas fa-exclamation-circle"></i></span>' : '');
            var locDisplayVal = isInvalid ? (row._typedValue||'') : (row.location_code||'');

            html += '<div style="position:relative">'
            + '<input type="text" id="plLoc_'+_plItemId+'_'+i+'" value="'+locDisplayVal+'"'
            + ' placeholder="Cari atau ketik kode lokasi..." autocomplete="off" class="plLocInp"'
            + ' oninput="plFilterDd('+i+',this.value);plClearLocWarning('+i+')"'
            + ' onfocus="plShowDd('+i+')" onclick="plShowDd('+i+')"'
            + ' onblur="plBlurLoc('+i+')"'
            + ' style="width:100%;padding:7px 10px;border:2px solid '+locBorderEff+';background:'+locBgEff+';'
            + 'border-radius:8px;font-family:monospace;font-size:.83rem;font-weight:700;outline:none;box-sizing:border-box">'
            + locIcon
            + '<div id="plDd_'+_plItemId+'_'+i+'" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:99999;'
            + 'background:#fff;border:1px solid #e5e7eb;border-radius:8px;'
            + 'box-shadow:0 6px 20px rgba(0,0,0,.15);max-height:220px;overflow-y:auto;margin-top:2px">'
            + ddHtml + '</div></div>'

            /* Qty input */
            + '<input type="number" value="'+qty+'" min="0.01" step="any"'
            + ' oninput="plUpdateQty('+i+',this.value)"'
            + ' style="padding:7px 8px;border:1.5px solid #e2e8f0;border-radius:7px;'
            + 'font-size:.875rem;font-weight:700;text-align:right;outline:none;width:100%;box-sizing:border-box">'

            /* Pallet count — each row = 1 pallet */
            + '<div style="text-align:center;font-size:.72rem;color:#64748b;font-weight:600;'
            + 'background:#f8fafc;border-radius:5px;padding:3px 0">'
            + '1 plt</div>'

            /* Delete row */
            + '<button type="button" onclick="plRemoveRow('+i+')"'
            + ' '+ (_plRows.length<=1?'disabled':'')+''
            + ' style="width:28px;height:28px;border-radius:6px;border:1px solid '
            + (_plRows.length<=1?'#e2e8f0':'#fecaca')+';background:'
            + (_plRows.length<=1?'#f8fafc':'#fef2f2')+';color:'
            + (_plRows.length<=1?'#cbd5e1':'#ef4444')
            + ';cursor:'+(_plRows.length<=1?'not-allowed':'pointer')
            + ';display:flex;align-items:center;justify-content:center;font-size:.7rem">'
            + '<i class="fas fa-times"></i></button>'
            + '</div>';
    });

    document.getElementById('plPalletRows').innerHTML = html;
    plUpdateSummary();
}

function plUpdateSummary() {
    var total = _plRows.reduce(function(s,r){ return s + (parseFloat(r.quantity)||0); }, 0);
    total = Math.round(total * 1000) / 1000;
    var diff  = Math.round((total - _plQty) * 1000) / 1000;
    var el    = document.getElementById('plSummary');
    var btn   = document.getElementById('plSaveBtn');
    if (!el) return;

    var missingLoc  = _plRows.filter(function(r){ return !r.location_code && !r._invalid; });
    var invalidLoc  = _plRows.filter(function(r){ return !!r._invalid; });
    var qtyOk       = diff === 0;
    var locOk       = missingLoc.length === 0 && invalidLoc.length === 0;
    var ok          = qtyOk && locOk;

    var msgs = [];
    if (!qtyOk) {
        var excess = diff > 0;
        msgs.push(excess
            ? '<span style="color:#b91c1c"><i class="fas fa-exclamation-triangle mr-1"></i>Kelebihan '+diff+' '+_plUom+' (target: '+_plQty+')</span>'
            : '<span style="color:#d97706"><i class="fas fa-exclamation-triangle mr-1"></i>Kurang '+Math.abs(diff)+' '+_plUom+' dari target '+_plQty+'</span>');
    }
    if (invalidLoc.length > 0) {
        msgs.push('<span style="color:#b91c1c"><i class="fas fa-map-marker-alt mr-1"></i>'
            + invalidLoc.length + ' lokasi tidak valid (bukan master data)</span>');
    }
    if (missingLoc.length > 0) {
        msgs.push('<span style="color:#d97706"><i class="fas fa-map-marker-alt mr-1"></i>'
            + missingLoc.length + ' pallet belum dipilih lokasi</span>');
    }
    if (ok) {
        msgs.push('<span style="color:#065f46"><i class="fas fa-check-circle mr-1"></i>Total: '+total+' '+_plUom+' — Semua lokasi valid!</span>');
    }
    el.innerHTML = '<div style="display:flex;flex-direction:column;gap:2px;font-weight:700;font-size:.8rem">'
        + msgs.join('') + '</div>';

    if (btn) {
        btn.disabled = !ok;
        btn.style.opacity = ok ? '1' : '.45';
        btn.style.cursor  = ok ? 'pointer' : 'not-allowed';
    }
}

function savePalletLocs() {
    var total = Math.round(_plRows.reduce(function(s,r){ return s+(parseFloat(r.quantity)||0); },0)*1000)/1000;
    if (total !== _plQty) { alert('Total qty harus tepat '+_plQty+' '+_plUom+'!'); return; }
    var invalid = _plRows.filter(function(r){ return !!r._invalid; });
    if (invalid.length) {
        alert('Terdapat '+invalid.length+' lokasi yang tidak valid (bukan master data).\nHapus atau pilih ulang dari daftar lokasi.');
        return;
    }
    var miss = _plRows.filter(function(r){ return !r.location_code; });
    if (miss.length) { alert(miss.length+' pallet belum dipilih lokasi! Ketik kode dan pilih dari daftar.'); return; }
    var final = _plRows.map(function(r,i){
        return { pallet_seq:i+1, location_code:r.location_code,
                 quantity:parseFloat(r.quantity), is_full:parseFloat(r.quantity)>=_plUpp };
    });
    document.getElementById('palletLocJson_'+_plItemId).value = JSON.stringify(final);
    document.getElementById('palletLocForm_'+_plItemId).submit();
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.plLocInp') && !e.target.closest('[id^="plDd_"]')) {
        document.querySelectorAll('[id^="plDd_"]').forEach(function(d){ d.style.display='none'; });
    }
});

/* Fetch fresh location data then open modal */
async function openPalletLocModal_withFetch(itemId) {
    var btn = document.getElementById('plBtn_' + itemId);
    var origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:4px"></i>Loading...';
    }
    try {
        var res  = await fetch('api_locations.php?action=modal_data&item_id=' + encodeURIComponent(itemId));
        var data = await res.json();
        if (!data.success) throw new Error(data.message || 'Gagal memuat data lokasi');

        window['LOC_DATA_' + itemId]  = data.locations;
        window['LOC_AUTO_' + itemId]  = data.auto;
        window['LOC_SAVED_' + itemId] = data.saved;

        var nPallets = data.saved.length > 0 ? data.saved.length : data.auto.length;
        openPalletLocModal(
            String(itemId),
            nPallets,
            data.item.uom,
            data.item.uom_per_pallet,
            data.item.qty
        );
    } catch(e) {
        alert('Error: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    }
}
</script>

<div class="ib-page space-y-5">

<?php

function ibBadge($status) {
    $map = [
        'Draft'          => ['cls' => 'draft',     'label' => 'Draft'],
        'Dues In'        => ['cls' => 'dues',      'label' => 'Dues In'],
        'Receiving'      => ['cls' => 'receiving', 'label' => 'Receiving'],
        'Good Received'  => ['cls' => 'received',  'label' => 'Goods Received'],
        'Goods Received' => ['cls' => 'received',  'label' => 'Goods Received'],
        'Completed'      => ['cls' => 'completed', 'label' => 'Completed'],
        'ATP'            => ['cls' => 'atp',       'label' => 'ATP'],
        'Cancelled'      => ['cls' => 'cancelled', 'label' => 'Cancelled'],
    ];
    $entry = $map[$status] ?? ['cls' => 'draft', 'label' => $status];
    return "<span class=\"ib-badge ib-badge-{$entry['cls']}\">" . htmlspecialchars($entry['label']) . "</span>";
}
function ibUomTag($uom) {
    $u = strtolower($uom ?? '');
    if (strpos($u,'drum') !== false)   $cls = 'drum';
    elseif (strpos($u,'carton') !== false) $cls = 'carton';
    elseif (strpos($u,'pail') !== false)   $cls = 'pail';
    else $cls = 'ea';
    return "<span class=\"ib-uom ib-uom-$cls\">" . htmlspecialchars(strtoupper($uom ?? '-')) . "</span>";
}
?>

<div class="ib-hero">
    <div>
        <div class="ib-hero-title"><i class="fas fa-truck-loading mr-2" style="opacity:.85"></i>Inbound Management</div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <?php
        $stats = Inbound::getStats();
        $totalPallets = array_sum(array_column($inboundList, 'total_pallet'));
        foreach ([
            ['Orders',     count($inboundList)],
            ['Dues In',    $stats['dues_in'] ?? 0],
            ['Receiving',  $stats['receiving'] ?? 0],
            ['Pallets',    round($totalPallets, 1)],
        ] as [$lbl,$val]):
        ?>
        <div class="ib-stat-pill">
            <div class="num"><?= $val ?></div>
            <div class="lbl"><?= $lbl ?></div>
        </div>
        <?php endforeach; ?>

    </div>
</div>

<?php if (isset($_GET['success'])): ?>
<div class="ib-alert ib-alert-success">
    <i class="fas fa-check-circle"></i>
    <?= ['created'=>'Inbound order created.','updated'=>'Order updated.','completed'=>'Order completed — stock updated.','deleted'=>'Order deleted.','item_added'=>'Item added.','item_deleted'=>'Item removed.','dates_updated'=>'Tanggal produksi & expiry diperbarui.','qty_updated'=>'Qty item diperbarui.','ledger_repaired'=>'Stock ledger berhasil diregenerasi.'][$_GET['success']] ?? 'Success.' ?>
</div>
<?php endif; ?>
<?php if (isset($error)): ?>
<div class="ib-alert ib-alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($action === 'list'): ?>

<div class="ib-card">
    <div class="ib-card-header">
        <h2><i class="fas fa-list-ul mr-2"></i>All Inbound Orders</h2>
        <div style="display:flex;gap:8px;align-items:center">
            <?php if($canWrite): ?><a href="import.php?type=inbound" class="ib-btn ib-btn-ghost ib-btn-sm" style="background:#026766;color:#fff;border:none"><i class="fas fa-file-import mr-1"></i> Import</a><?php endif; ?>
            <a href="<?= 'inbound.php?' . http_build_query(array_merge($_GET, ['export' => '1'])) ?>" class="ib-btn ib-btn-ghost ib-btn-sm" style="background:#013d3c;color:#fff;border:none"><i class="fas fa-download mr-1"></i> Export</a>
            <?php if($canWrite): ?><a href="?action=create" class="ib-btn ib-btn-primary ib-btn-sm"><i class="fas fa-plus mr-1"></i> New Inbound</a><?php endif; ?>
        </div>
    </div>
    
    <div style="padding:14px 20px;border-bottom:1px solid #e6f7f7;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;flex:1">
        <div style="position:relative;min-width:180px">
            <i class="fas fa-hashtag" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#026766;font-size:.85rem"></i>
            <input type="text" name="od_no" id="ibOdSearch" value="<?= htmlspecialchars($searchOdNo) ?>"
                   placeholder="Cari OD No..."
                   style="width:100%;padding:8px 12px 8px 32px;border:2px solid #026766;border-radius:8px;font-size:.85rem;outline:none;font-family:monospace">
        </div>
        <div style="position:relative;flex:1;min-width:180px">
            <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#90a4ae;font-size:.85rem"></i>
            <input type="text" id="ibSearch" placeholder="Cari order no, carrier, container..."
                   oninput="filterIbTable()"
                   style="width:100%;padding:8px 12px 8px 32px;border:1px solid #cce8e8;border-radius:8px;font-size:.85rem;outline:none">
        </div>
        <button type="submit" style="padding:8px 16px;background:#026766;color:#fff;border:none;border-radius:8px;font-size:.85rem;cursor:pointer;white-space:nowrap">
            <i class="fas fa-search mr-1"></i>Cari
        </button>
        <?php if($searchOdNo): ?>
        <a href="?action=list" style="padding:8px 12px;background:#e6f7f7;color:#64748b;border-radius:8px;font-size:.82rem;text-decoration:none">
            <i class="fas fa-times"></i>
        </a>
        <?php endif; ?>
        </form>
        <select id="ibStatusFilter" onchange="filterIbTable()"
                style="padding:8px 12px;border:1px solid #cce8e8;border-radius:8px;font-size:.85rem;color:#546e7a;background:#fff">
            <option value="">Semua Status</option>
            <option value="Draft">Draft</option>
            <option value="Dues In">Dues In</option>
            <option value="Receiving">Receiving</option>
            <option value="Completed">Completed</option>
            <option value="Cancelled">Cancelled</option>
        </select>
        <select id="ibMonthFilter" onchange="filterIbTable()"
                style="padding:8px 12px;border:1px solid #cce8e8;border-radius:8px;font-size:.85rem;color:#546e7a;background:#fff">
            <option value="">Semua Bulan</option>
            <?php
            $months = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
                       '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
            $currentYear = date('Y');
            for($y = $currentYear; $y >= $currentYear-1; $y--) {
                foreach($months as $mn => $ml) {
                    echo "<option value='{$y}-{$mn}'>{$ml} {$y}</option>";
                }
            }
            ?>
        </select>
        <span id="ibCount" style="font-size:.8rem;color:#90a4ae;white-space:nowrap"></span>
    </div>
    <div style="overflow-x:auto">
        <table class="ib-table" id="ibTable">
            <thead>
                <tr>
                    <th>Shipment No.</th>
                    <th>Date</th>
                    <th>Carrier</th>
                    <th>Container</th>
                    <th style="text-align:center">Items</th>
                    <th style="text-align:right">Pallets</th>
                    <th>Status</th>
                    <th style="text-align:center">Actions</th>
                </tr>
            </thead>
            <tbody id="ibTbody">
            <?php if (empty($inboundList)): ?>
            <tr><td colspan="8">
                <div class="ib-empty">
                    <div><i class="fas fa-truck-loading"></i></div>
                    <div>No inbound orders yet.</div>
                    <a href="?action=create" class="ib-btn ib-btn-primary ib-btn-sm" style="margin-top:12px">Create First Order</a>
                </div>
            </td></tr>
            <?php else: ?>
            <?php foreach ($inboundList as $item): ?>
            <tr data-order="<?= strtolower(trim($item['shipment_no'] ?? $item['order_number'] ?? $item['inbound_number'] ?? '')) ?>" data-carrier="<?= strtolower($item['carrier_name'] ?? '') ?>" data-container="<?= strtolower($item['container_no'] ?? '') ?>" data-od="<?= strtolower($item['od_numbers'] ?? '') ?>" data-status="<?= $item['status'] ?>" data-date="<?= substr($item['order_date'],0,7) ?>">
                <td>
                    <a href="?action=view&id=<?= $item['id'] ?>"
                       style="font-weight:600;color:var(--ib-accent);text-decoration:none">
                        <?= htmlspecialchars(trim($item['shipment_no'] ?? '') ?: ($item['order_number'] ?? $item['inbound_number'] ?? '-')) ?>
                    </a>
                    <?php if (!empty($item['order_number']) && trim((string)($item['shipment_no'] ?? '')) === ''): ?>
                    <div style="font-size:.68rem;color:#90a4ae;font-family:monospace">
                        <?= htmlspecialchars($item['order_number']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($item['od_numbers'])): ?>
                    <div style="font-size:.7rem;font-family:monospace;color:#026766;margin-top:2px">
                        OD: <?= htmlspecialchars($item['od_numbers']) ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td style="color:#546e7a"><?= date('d M Y', strtotime($item['order_date'])) ?></td>
                <td><?= htmlspecialchars($item['carrier_name'] ?? '-') ?></td>
                <td style="font-family:monospace;font-size:.8rem;color:#546e7a"><?= htmlspecialchars($item['container_no'] ?? '-') ?></td>
                <td style="text-align:center;font-weight:600"><?= (int)($item['total_items'] ?? 0) ?></td>
                <td style="text-align:right;font-weight:600"><?= (int)ceil($item['total_pallet'] ?? 0) ?></td>
                <td><?= ibBadge($item['status']) ?></td>
                <td style="text-align:center">
                    <a href="?action=view&id=<?= $item['id'] ?>" class="ib-btn ib-btn-ghost ib-btn-sm" style="margin-right:4px">
                        <i class="fas fa-eye"></i>
                    </a>
                    <?php if($canWrite && !in_array($item['status'], ['Completed','Cancelled'])): ?><button onclick="confirmDelete(<?= $item['id'] ?>, '<?= htmlspecialchars($item['order_number'] ?? $item['inbound_number'] ?? '-') ?>')"
                            class="ib-btn ib-btn-danger ib-btn-sm">
                        <i class="fas fa-trash"></i>
                    </button><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-top:1px solid #e6f7f7;flex-wrap:wrap;gap:8px">
        <span style="font-size:.82rem;color:#90a4ae">
            Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalCount)) ?> of <?= number_format($totalCount) ?> orders
        </span>
        <div style="display:flex;gap:4px;align-items:center">
            <?php if ($page > 1): ?>
            <a href="?action=list&od_no=<?= urlencode($searchOdNo) ?>&page=<?= $page - 1 ?>"
               style="padding:6px 12px;background:#e6f7f7;color:#026766;border-radius:6px;text-decoration:none;font-size:.82rem">
                &laquo; Prev
            </a>
            <?php endif; ?>
            <?php
            $rangeStart = max(1, $page - 2);
            $rangeEnd   = min($totalPages, $page + 2);
            for ($p = $rangeStart; $p <= $rangeEnd; $p++):
            ?>
            <a href="?action=list&od_no=<?= urlencode($searchOdNo) ?>&page=<?= $p ?>"
               style="padding:6px 10px;background:<?= $p === $page ? '#026766' : '#e6f7f7' ?>;color:<?= $p === $page ? '#fff' : '#026766' ?>;border-radius:6px;text-decoration:none;font-size:.82rem;font-weight:<?= $p === $page ? '700' : '400' ?>">
                <?= $p ?>
            </a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?action=list&od_no=<?= urlencode($searchOdNo) ?>&page=<?= $page + 1 ?>"
               style="padding:6px 12px;background:#e6f7f7;color:#026766;border-radius:6px;text-decoration:none;font-size:.82rem">
                Next &raquo;
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($action === 'create'): ?>

<div class="ib-card">
    <div class="ib-card-header">
        <h2><i class="fas fa-plus-circle mr-2"></i>Create New Inbound Order</h2>
        <a href="?action=list" class="ib-btn ib-btn-ghost ib-btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
    <div class="ib-card-body">
    <form method="POST" class="space-y-5">

        
        <div class="ib-section">
            <div class="ib-section-title"><i class="fas fa-file-alt"></i> Order Information</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px">
                <div style="grid-column:span 2">
                    <label class="ib-label">Shipment No <span class="req">*</span> <small style="font-weight:400;color:#607d8b">(digunakan sebagai nomor order)</small></label>
                    <div class="ib-input-icon">
                        <i class="fas fa-ship"></i>
                        <input type="text" name="shipment_no" class="ib-input" placeholder="e.g. 109294012" required
                               style="font-family:monospace;font-weight:600">
                    </div>
                </div>
                <div>
                    <label class="ib-label">Order Date <span class="req">*</span></label>
                    <input type="date" name="order_date" required class="ib-input" value="<?= date('Y-m-d') ?>">
                </div>
                <div>
                    <label class="ib-label">Carrier / Transporter</label>
                    <div class="ib-input-icon">
                        <i class="fas fa-truck-moving"></i>
                        <input type="text" name="carrier_name" class="ib-input" placeholder="e.g. PT Maju Jaya Logistics">
                    </div>
                </div>
                <div>
                    <label class="ib-label">Received By</label>
                    <input type="hidden" name="status" value="Draft">
                    <select name="received_by_id" class="ib-select">
                        <option value="">— Pilih penerima (opsional) —</option>
                        <?php foreach ($usersList as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="ib-label">Received Date</label>
                    <input type="date" name="received_date" class="ib-input">
                </div>
            </div>
        </div>

        
        <div class="ib-section">
            <div class="ib-section-title"><i class="fas fa-shipping-fast"></i> Shipment Information</div>
            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px">
                <div>
                    <label class="ib-label">Container No</label>
                    <div class="ib-input-icon">
                        <i class="fas fa-box"></i>
                        <input type="text" name="container_no" class="ib-input" placeholder="e.g. HLBU-9876543">
                    </div>
                </div>
                <div>
                    <label class="ib-label">Armada / Vehicle No</label>
                    <div class="ib-input-icon">
                        <i class="fas fa-truck"></i>
                        <input type="text" name="armada_no" class="ib-input" placeholder="e.g. B 1234 XYZ">
                    </div>
                </div>
            </div>
        </div>

        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div class="ib-section">
                <div class="ib-section-title"><i class="fas fa-sticky-note"></i> Internal Notes</div>
                <textarea name="notes" rows="3" class="ib-textarea" placeholder="Notes for internal use..."></textarea>
            </div>
            <div class="ib-section">
                <div class="ib-section-title"><i class="fas fa-comment-alt"></i> Remarks</div>
                <textarea name="remarks" rows="3" class="ib-textarea" placeholder="Additional remarks..."></textarea>
            </div>
        </div>

        
        <div style="display:flex;gap:12px">
            <button type="submit" name="create_inbound" class="ib-btn ib-btn-success" style="flex:1;justify-content:center;padding:12px">
                <i class="fas fa-save"></i> Save Inbound Order
            </button>
            <a href="?action=list" class="ib-btn ib-btn-ghost" style="flex:1;justify-content:center;padding:12px">
                <i class="fas fa-times"></i> Cancel
            </a>
        </div>
    </form>
    </div>
</div>

<?php elseif ($action === 'view' && $inbound): ?>

<?php
// Stepper logic
$ibStatus = $inbound['status'] ?? 'Draft';
$steps = [
    ['key' => 'Draft',     'label' => 'Draft',      'icon' => 'fa-file-alt',       'desc' => 'Order dibuat'],
    ['key' => 'Dues In',   'label' => 'Dues In',    'icon' => 'fa-clock',          'desc' => 'Dijadwalkan masuk'],
    ['key' => 'Receiving', 'label' => 'Receiving',  'icon' => 'fa-truck-loading',  'desc' => 'Proses penerimaan'],
    ['key' => 'Completed', 'label' => 'Completed',  'icon' => 'fa-check-double',   'desc' => 'Stok masuk'],
];
$legacyToStep = ['Good Received' => 'Receiving', 'Goods Received' => 'Receiving', 'ATP' => 'Completed', 'Unserviceable' => 'Completed'];
$normalizedStatus = $legacyToStep[$ibStatus] ?? $ibStatus;
$stepKeys = array_column($steps, 'key');
$currentStepIdx = array_search($normalizedStatus, $stepKeys);
if ($currentStepIdx === false) $currentStepIdx = 0;
?>

<div class="ib-card" style="margin-bottom:14px">
    <div class="ib-card-body" style="padding:18px 24px">

        <!-- Title row -->
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px">
            <div>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:5px;flex-wrap:wrap">
                    <a href="?action=list" style="color:#94a3b8;text-decoration:none;font-size:.8rem">
                        <i class="fas fa-arrow-left"></i> Inbound
                    </a>
                    <span style="color:#cbd5e1">/</span>
                    <span style="font-size:1.25rem;font-weight:800;color:#013d3c;font-family:monospace">
                        <?= htmlspecialchars(trim($inbound['shipment_no'] ?? '') ?: ($inbound['order_number'] ?? '-')) ?>
                    </span>
                    <?= ibBadge($ibStatus) ?>
                    <?php if(!empty($inbound['order_number']) && trim((string)($inbound['shipment_no'] ?? '')) === ''):?>
                    <span style="font-size:.7rem;color:#94a3b8;font-family:monospace"><?= htmlspecialchars($inbound['order_number']) ?></span>
                    <?php endif;?>
                </div>
                <div style="font-size:.83rem;color:#64748b;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
                    <span><i class="fas fa-truck-moving" style="margin-right:5px;color:#94a3b8"></i><?= htmlspecialchars($inbound['carrier_name'] ?? '-') ?></span>
                    <span><i class="fas fa-calendar" style="margin-right:5px;color:#94a3b8"></i><?= date('d M Y', strtotime($inbound['order_date'])) ?></span>
                    <span><i class="fas fa-boxes" style="margin-right:5px;color:#94a3b8"></i><?= count($inboundItems) ?> item &middot; <?= $totalPalletCount ?? (int)ceil(array_sum(array_column($inboundItems,'pallet'))) ?> pallet</span>
                </div>
            </div>
            <!-- Utility buttons -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <a href="print_inbound.php?id=<?= $inbound['id'] ?>" target="_blank" class="ib-btn ib-btn-ghost ib-btn-sm">
                    <i class="fas fa-print"></i> Print
                </a>
                <a href="putaway_sheet.php?id=<?= $inbound['id'] ?>" target="_blank" class="ib-btn ib-btn-ghost ib-btn-sm">
                    <i class="fas fa-map-marker-alt"></i> Putaway Sheet
                </a>
                <?php if ($canAdmin && !in_array($ibStatus, ['Completed','Cancelled'])): ?>
                <button onclick="confirmDelete(<?= $inbound['id'] ?>, '<?= htmlspecialchars($inbound['order_number'] ?? '-') ?>')"
                        class="ib-btn ib-btn-danger ib-btn-sm">
                    <i class="fas fa-trash"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Progress Stepper -->
        <div style="display:flex;align-items:stretch;gap:0;margin-bottom:20px">
            <?php foreach ($steps as $si => $step):
                $isDone   = $si < $currentStepIdx;
                $isActive = $si === $currentStepIdx;
                $isFuture = $si > $currentStepIdx;
                if ($isDone) {
                    $bg = '#ecfdf5'; $color = '#065f46'; $borderC = '#6ee7b7'; $numBg = '#10b981'; $numCol = '#fff';
                } elseif ($isActive) {
                    $bg = '#eff6ff'; $color = '#1d4ed8'; $borderC = '#3b82f6'; $numBg = '#3b82f6'; $numCol = '#fff';
                } else {
                    $bg = '#f8fafc'; $color = '#94a3b8'; $borderC = '#e2e8f0'; $numBg = '#e2e8f0'; $numCol = '#94a3b8';
                }
            ?>
            <div style="flex:1;background:<?= $bg ?>;border:1.5px solid <?= $borderC ?>;
                        <?= $si === 0 ? 'border-radius:10px 0 0 10px' : ($si === count($steps)-1 ? 'border-radius:0 10px 10px 0;border-left:none' : 'border-left:none') ?>;
                        padding:12px 14px;position:relative;transition:.2s">
                <div style="display:flex;align-items:center;gap:10px">
                    <div style="width:28px;height:28px;border-radius:50%;background:<?= $numBg ?>;color:<?= $numCol ?>;
                                display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800;flex-shrink:0">
                        <?= $isDone ? '<i class="fas fa-check" style="font-size:.65rem"></i>' : ($si+1) ?>
                    </div>
                    <div>
                        <div style="font-size:.8rem;font-weight:700;color:<?= $color ?>"><?= $step['label'] ?></div>
                        <div style="font-size:.65rem;color:<?= $isFuture ? '#cbd5e1' : $color ?>;opacity:<?= $isFuture ? '.7' : '1' ?>"><?= $step['desc'] ?></div>
                    </div>
                </div>
                <?php if ($isActive): ?>
                <div style="position:absolute;bottom:-2px;left:50%;transform:translateX(-50%);
                            width:40px;height:3px;background:#3b82f6;border-radius:2px"></div>
                <?php endif; ?>
            </div>
            <?php if ($si < count($steps)-1): ?>
            <div style="width:0;height:auto;border-top:1.5px solid <?= $isDone ? '#6ee7b7' : '#e2e8f0' ?>;
                        align-self:center;flex:0 0 12px;margin:0"></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- Action buttons based on status -->
        <?php if ($canWrite && !in_array($ibStatus, ['Completed','Cancelled'])): ?>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <?php if ($ibStatus === 'Draft'): ?>
                <form method="POST" onsubmit="return confirm('Konfirmasi jadwal kedatangan barang ini?')">
                    <input type="hidden" name="id" value="<?= $inbound['id'] ?>">
                    <input type="hidden" name="new_status" value="Dues In">
                    <button type="submit" name="advance_status"
                            style="background:#1d4ed8;color:#fff;border:none;border-radius:8px;
                                   padding:10px 22px;font-weight:700;cursor:pointer;font-size:.875rem;
                                   display:inline-flex;align-items:center;gap:8px">
                        <i class="fas fa-arrow-right"></i> Konfirmasi Dues In
                    </button>
                </form>
                <span style="font-size:.78rem;color:#94a3b8">Klik setelah detail order lengkap</span>

            <?php elseif ($ibStatus === 'Dues In'): ?>
                <button type="button" onclick="openReceivingModal()"
                        style="background:#c2410c;color:#fff;border:none;border-radius:8px;
                               padding:10px 22px;font-weight:700;cursor:pointer;font-size:.875rem;
                               display:inline-flex;align-items:center;gap:8px;
                               box-shadow:0 2px 8px rgba(194,65,12,.3)">
                    <i class="fas fa-truck-loading"></i> Start Receiving
                </button>
                <span style="font-size:.78rem;color:#94a3b8">Klik saat truck/kontainer sudah tiba</span>

            <?php elseif (in_array($ibStatus, ['Receiving','Good Received','Goods Received'])): ?>
                <?php
                $pendingItems      = array_filter($inboundItems, fn($it) => in_array($it['in_process_status'] ?? '', ['Dues In','Goods Received']));
                $atpItems          = array_filter($inboundItems, fn($it) => ($it['in_process_status'] ?? '') === 'ATP');
                $unservItems       = array_filter($inboundItems, fn($it) => ($it['in_process_status'] ?? '') === 'Unserviceable');
                $allReady          = count($pendingItems) === 0 && count($inboundItems) > 0;
                ?>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <?php if ($allReady): ?>
                    <form method="POST" onsubmit="return confirm('Complete & Stock-In?')">
                        <input type="hidden" name="id" value="<?= $inbound['id'] ?>">
                        <button type="submit" name="complete_inbound"
                                style="background:#065f46;color:#fff;border:none;border-radius:8px;
                                       padding:10px 22px;font-weight:700;cursor:pointer;font-size:.875rem;
                                       display:inline-flex;align-items:center;gap:8px;
                                       box-shadow:0 2px 8px rgba(6,95,70,.3)">
                            <i class="fas fa-check-double"></i> Complete & Stock-In
                        </button>
                    </form>
                    <span style="background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;
                                 border-radius:6px;padding:5px 12px;font-size:.75rem;font-weight:700">
                        <i class="fas fa-check mr-1"></i><?= count($atpItems) ?> ATP · <?= count($unservItems) ?> Unserviceable — semua item siap
                    </span>
                    <?php else: ?>
                    <button disabled
                            style="background:#e2e8f0;color:#94a3b8;border:none;border-radius:8px;
                                   padding:10px 22px;font-weight:700;font-size:.875rem;cursor:not-allowed;
                                   display:inline-flex;align-items:center;gap:8px">
                        <i class="fas fa-lock"></i> Complete & Stock-In
                    </button>
                    <div style="display:flex;flex-direction:column;gap:5px">
                        <span style="background:#fefce8;color:#854d0e;border:1px solid #fde68a;
                                     border-radius:6px;padding:5px 12px;font-size:.75rem;font-weight:700;
                                     display:inline-flex;align-items:center;gap:6px">
                            <i class="fas fa-exclamation-triangle"></i>
                            <?= count($pendingItems) ?> item belum ATP — update status item di tabel bawah
                        </span>
                        <?php if (count($atpItems) > 0 || count($unservItems) > 0): ?>
                        <span style="background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;
                                     border-radius:6px;padding:4px 10px;font-size:.72rem;font-weight:600">
                            <i class="fas fa-check mr-1"></i><?= count($atpItems) ?> ATP · <?= count($unservItems) ?> Unserviceable sudah siap
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($ibStatus === 'Completed'): ?>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <div style="display:inline-flex;align-items:center;gap:10px;background:#ecfdf5;
                                border:1.5px solid #6ee7b7;border-radius:8px;padding:10px 18px">
                        <i class="fas fa-lock" style="color:#065f46;font-size:.9rem"></i>
                        <span style="font-size:.83rem;font-weight:700;color:#065f46">Order selesai — stok sudah tercatat</span>
                    </div>
                    <?php if ($canAdmin): ?>
                    <form method="POST" onsubmit="return confirm('Regenerasi entri stock ledger untuk order ini?')">
                        <input type="hidden" name="id" value="<?= $inbound['id'] ?>">
                        <button type="submit" name="repair_ledger"
                                style="background:#1d4ed8;color:#fff;border:none;border-radius:8px;
                                       padding:9px 16px;font-weight:600;cursor:pointer;font-size:.8rem;
                                       display:inline-flex;align-items:center;gap:7px">
                            <i class="fas fa-tools"></i> Repair Ledger
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<div class="ib-card">
    <div class="ib-card-header" style="display:flex;align-items:center;justify-content:space-between">
        <h2><i class="fas fa-info-circle mr-2"></i>Order Details</h2>
        <?php if ($canWrite && !in_array($inbound['status'] ?? '', ['Completed','Cancelled'])): ?>
        <button type="button" onclick="toggleEditInbound()"
                class="ib-btn ib-btn-ghost ib-btn-sm" id="editInboundBtn">
            <i class="fas fa-edit"></i> Edit
        </button>
        <?php endif; ?>
    </div>

    
    <div class="ib-card-body" id="inboundViewMode">
        <div class="ib-info-grid">
            <div class="ib-info-box"><div class="key">Shipment No</div><div class="val"><?= htmlspecialchars($inbound['shipment_no'] ?? '—') ?></div></div>
            <div class="ib-info-box"><div class="key">Container No</div><div class="val"><?= htmlspecialchars($inbound['container_no'] ?? '—') ?></div></div>
            <div class="ib-info-box"><div class="key">Armada No</div><div class="val"><?= htmlspecialchars($inbound['armada_no'] ?? '—') ?></div></div>
            <div class="ib-info-box">
                <div class="key">Received By</div>
                <div class="val">
                    <?php if (!empty($inbound['received_by_name'])): ?>
                        <?= htmlspecialchars($inbound['received_by_name']) ?>
                    <?php else: ?>
                        <span style="color:#cbd5e1;font-weight:400;font-size:.8rem">—</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ib-info-box">
                <div class="key">Received Date</div>
                <div class="val">
                    <?php if (!empty($inbound['received_date'])): ?>
                        <?= date('d M Y', strtotime($inbound['received_date'])) ?>
                    <?php else: ?>
                        <span style="color:#cbd5e1;font-weight:400;font-size:.8rem">—</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ib-info-box"><div class="key">Total Items</div><div class="val" style="color:var(--ib-accent)"><?= count($inboundItems) ?> lines</div></div>
            <div class="ib-info-box"><div class="key">Total Pallets</div><div class="val" style="color:var(--ib-success)"><?= $totalPalletCount ?? (int)ceil(array_sum(array_column($inboundItems,'pallet'))) ?> plt</div></div>
        </div>
    </div>

    
    <?php if ($canWrite && !in_array($inbound['status'] ?? '', ['Completed','Cancelled'])): ?>
    <div class="ib-card-body" id="inboundEditMode" style="display:none;border-top:2px solid var(--ib-accent)">
        <form method="POST">
            <input type="hidden" name="id" value="<?= $inbound['id'] ?>">
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px">
                <div>
                    <label class="ib-label">Order Date</label>
                    <input type="date" name="order_date" class="ib-input"
                           value="<?= htmlspecialchars($inbound['order_date'] ?? '') ?>" required>
                </div>
                <div>
                    <label class="ib-label">Carrier / Transporter</label>
                    <input type="text" name="carrier_name" class="ib-input"
                           value="<?= htmlspecialchars($inbound['carrier_name'] ?? '') ?>"
                           placeholder="e.g. PT Maju Jaya Logistics">
                </div>
                <div>
                    <label class="ib-label">Shipment No</label>
                    <input type="text" name="shipment_no" class="ib-input"
                           value="<?= htmlspecialchars($inbound['shipment_no'] ?? '') ?>"
                           placeholder="SHP-2026-001">
                </div>
                <div>
                    <label class="ib-label">Container No</label>
                    <input type="text" name="container_no" class="ib-input"
                           value="<?= htmlspecialchars($inbound['container_no'] ?? '') ?>">
                </div>
                <div>
                    <label class="ib-label">Armada No</label>
                    <input type="text" name="armada_no" class="ib-input"
                           value="<?= htmlspecialchars($inbound['armada_no'] ?? '') ?>">
                </div>
                <div>
                    <label class="ib-label">Status</label>
                    <div style="padding:8px 12px;background:#f1f5f9;border:1.5px solid #e2e8f0;border-radius:7px;
                                font-size:.875rem;color:#475569;display:flex;align-items:center;gap:8px">
                        <i class="fas fa-lock" style="font-size:.75rem;color:#94a3b8"></i>
                        <?= ibBadge($inbound['status']) ?>
                        <span style="font-size:.72rem;color:#94a3b8">dikontrol via stepper</span>
                    </div>
                </div>
                <div>
                    <label class="ib-label">Received By</label>
                    <select name="received_by_id" class="ib-select">
                        <option value="">— Opsional —</option>
                        <?php foreach ($usersList as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= ($inbound['received_by'] ?? '') == $u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="ib-label">Received Date</label>
                    <input type="date" name="received_date" class="ib-input"
                           value="<?= htmlspecialchars($inbound['received_date'] ?? '') ?>">
                </div>
                <div style="grid-column:span 4">
                    <label class="ib-label">Notes</label>
                    <input type="text" name="notes" class="ib-input"
                           value="<?= htmlspecialchars($inbound['notes'] ?? '') ?>">
                </div>
            </div>
            <div style="display:flex;gap:10px">
                <button type="submit" name="update_inbound" class="ib-btn ib-btn-primary">
                    <i class="fas fa-save"></i> Simpan Perubahan
                </button>
                <button type="button" onclick="toggleEditInbound()" class="ib-btn ib-btn-ghost">
                    Batal
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if ($canEdit): ?>
<div class="ib-card">
    <div class="ib-card-header"><h2><i class="fas fa-plus-circle mr-2"></i>Add Item</h2></div>
    <div class="ib-card-body">
    <form method="POST" class="space-y-4" onsubmit="return ensureExpDateBeforeSubmit()">
        <input type="hidden" name="inbound_id" value="<?= $inbound['id'] ?>">

        
        <div style="background:#e6f7f7;border:1px solid #bbdefb;border-radius:8px;padding:12px 14px;margin-bottom:4px">
            <div style="font-size:.71rem;font-weight:700;color:#013d3c;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">
                <i class="fas fa-hashtag mr-1"></i> Referensi Item (OD / SO)
                <span style="font-weight:400;text-transform:none;font-size:.7rem;color:#546e7a;margin-left:6px"</span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div>
                    <label class="ib-label">OD No. </label>
                    <div class="ib-input-icon">
                        <i class="fas fa-file-export"></i>
                        <input type="text" name="od_number" id="odNumberInput" class="ib-input"
                               placeholder="e.g. 530870992"
                               style="padding-left:30px;font-family:monospace"
                               oninput="this.value=this.value.replace(/\s/g,'')">
                    </div>
                </div>
                <div>
                    <label class="ib-label">SO No.</label>
                    <div class="ib-input-icon">
                        <i class="fas fa-file-invoice"></i>
                        <input type="text" name="so_number" id="soNumberInput" class="ib-input"
                               placeholder="e.g. 4549106526"
                               style="padding-left:30px;font-family:monospace"
                               oninput="this.value=this.value.replace(/\s/g,'')">
                    </div>
                </div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px">
            <!-- Row 1: Product (span 2) | Batch No | Qty -->
            <div style="grid-column:span 2;position:relative">
                <label class="ib-label">Product <span class="req">*</span></label>
                <input type="hidden" name="product_id" id="productId" required>
                <div id="productSearchWrap" style="position:relative">
                    <input type="text" id="productSearch"
                           class="ib-input" placeholder="Ketik kode atau nama produk..."
                           autocomplete="off"
                           style="padding-left:34px"
                           oninput="searchProducts(this.value)"
                           onblur="hideDropdownDelayed()"
                           onfocus="if(this.value.length>0) showDropdown()">
                    <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#90a4ae;font-size:.85rem;pointer-events:none"></i>
                    <div id="productDropdown"
                         onmousedown="event.preventDefault()"
                         style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;
                                background:#fff;border:1px solid #cce8e8;border-radius:8px;
                                box-shadow:0 6px 24px rgba(0,0,0,.12);max-height:260px;overflow-y:auto;margin-top:2px">
                    </div>
                </div>
                <div id="productInfo" class="ib-input-hint" style="margin-top:4px"></div>
            </div>
            <div>
                <label class="ib-label">Batch No <span class="req">*</span></label>
                <input type="text" name="batch_number" id="batchInput" required class="ib-input" placeholder="LOT/2026/001">
            </div>
            <div>
                <label class="ib-label">Quantity <span class="req">*</span></label>
                <input type="number" name="quantity" required min="1" step="0.01"
                       id="quantityInput" class="ib-input" placeholder="0" oninput="calculatePallet()">
                <div id="validationMsg" class="ib-input-hint"></div>
            </div>

            <!-- Row 2: UOM | Pallets | Mfg Date | Exp Date -->
            <div>
                <label class="ib-label">UOM <span class="req">*</span></label>
                <select name="uom" id="uomSelect" class="ib-select" onchange="updateUOMOptions()">
                    <option value="Drum">Drum (4/plt)</option>
                    <option value="Carton">Carton (36/44/48 per plt)</option>
                    <option value="Pail">Pail (24/plt)</option>
                    <option value="EA">EA (4/plt)</option>
                    <option value="Bags">Bags (1/plt)</option>
                </select>
                <div id="cartonPalletDiv" style="display:none;margin-top:6px">
                    <select name="cartons_per_pallet" id="cartonsPerPallet" class="ib-select" onchange="calculatePallet()">
                        <option value="36">36 ctn/plt</option>
                        <option value="44" selected>44 ctn/plt</option>
                        <option value="48">48 ctn/plt</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="ib-label">Pallets <small style="font-weight:400;color:#90a4ae">(auto)</small></label>
                <input type="text" id="palletDisplay" readonly class="ib-input ib-input-ro" placeholder="—">
                <input type="hidden" name="pallet" id="palletInput" value="0">
            </div>
            <div>
                <label class="ib-label">Manufacture Date</label>
                <input type="date" name="manufacture_date" id="manufactureDateInput" class="ib-input" oninput="autoCalculateExpDate()">
                <div class="ib-input-hint"><i class="fas fa-info-circle"></i> Exp date auto-fills +4 years</div>
            </div>
            <div>
                <label class="ib-label">Expiry Date</label>
                <input type="date" name="exp_date" id="expDateInput" class="ib-input" style="background:#fffde7">
                <div class="ib-input-hint"><i class="fas fa-info-circle"></i> Auto atau manual</div>
            </div>

            <!-- Row 3: No. Palet | In Process Status | Notes -->
            <div>
                <label class="ib-label">No. Palet</label>
                <div class="ib-input-icon">
                    <i class="fas fa-tag"></i>
                    <input type="text" name="pallet_no" class="ib-input" placeholder="e.g. PLT-001"
                           style="padding-left:30px"
                           oninput="this.value=this.value.toUpperCase()">
                </div>
                <div class="ib-input-hint"><i class="fas fa-info-circle"></i> Nomor label/fisik palet</div>
            </div>
            <div>
                <label class="ib-label">In Process Status</label>
                <select name="in_process_status" id="inProcessSelect" class="ib-select" onchange="onProcessStatusChange(this.value)">
                    <option value="Dues In" selected>⏳ Dues In — belum tiba</option>
                    <option value="Goods Received">📥 Goods Received — sudah diterima fisik</option>
                </select>
                <div class="ib-input-hint"><i class="fas fa-info-circle"></i> ATP & Unserviceable diset setelah ditambahkan</div>
            </div>
            <div style="grid-column:span 2">
                <label class="ib-label">Item Notes</label>
                <input type="text" name="notes" class="ib-input" placeholder="Optional notes...">
            </div>
        </div>

        <!-- Submit -->
        <div style="margin-top:18px;padding-top:16px;border-top:1px solid #e8f4f4">
            <button type="submit" name="add_item"
                    style="width:100%;padding:13px;background:linear-gradient(135deg,#026766,#014f4e);
                           color:#fff;border:none;border-radius:10px;font-size:.95rem;font-weight:700;
                           cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;
                           letter-spacing:.02em;box-shadow:0 2px 8px rgba(2,103,102,.25);transition:.15s"
                    onmouseover="this.style.opacity='.9'" onmouseout="this.style.opacity='1'">
                <i class="fas fa-plus-circle" style="font-size:1rem"></i>
                Add Item to Inbound
            </button>
        </div>
    </form>
    </div>
</div>
<?php endif; ?>

<div class="ib-card">
    <div class="ib-card-header">
        <h2><i class="fas fa-boxes mr-2"></i>Items (<?= count($inboundItems) ?>)</h2>
        <div style="font-size:.82rem;color:var(--ib-muted)">
            Total Qty: <strong><?= number_format(array_sum(array_column($inboundItems,'actual_qty'))) ?></strong>
            &nbsp;|&nbsp; Pallets: <strong><?= $totalPalletCount ?></strong>
        </div>
    </div>
    <div style="overflow-x:auto">
    <?php if (empty($inboundItems)): ?>
    <div class="ib-empty"><div><i class="fas fa-inbox"></i></div><div>No items yet — add an item above.</div></div>
    <?php else: ?>
    <table class="ib-table">
        <thead>
            <tr>
                <th style="width:32px">#</th>
                <th>Product</th>
                <th>Referensi</th>
                <th style="text-align:right">Qty</th>
                <th style="text-align:center">No. Palet</th>
                <th>Exp. Date</th>
                <th style="text-align:center;white-space:nowrap">Status</th>
                <th>Lokasi Putaway</th>
                <th style="text-align:center;white-space:nowrap">Stock</th>
                <?php if ($canEdit): ?>
                <th style="width:40px"></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($inboundItems as $i => $item): ?>
        <tr style="vertical-align:middle">
            <td style="color:#cbd5e1;font-size:.75rem;text-align:center"><?= $i+1 ?></td>

            <!-- Product + Batch + UOM -->
            <td style="min-width:180px">
                <div style="font-weight:700;color:#0f172a;font-size:.875rem;line-height:1.3">
                    <?= htmlspecialchars($item['product_name'] ?? '-') ?>
                </div>
                <div style="display:flex;align-items:center;gap:6px;margin-top:3px;flex-wrap:wrap">
                    <span style="font-family:monospace;font-size:.72rem;color:#64748b">
                        <?= htmlspecialchars($item['product_code'] ?? '') ?>
                    </span>
                    <?= ibUomTag($item['uom'] ?? 'Drum') ?>
                </div>
                <div style="font-family:monospace;font-size:.75rem;color:#475569;margin-top:2px;
                            background:#f8fafc;border-radius:4px;padding:1px 6px;display:inline-block">
                    <?= htmlspecialchars($item['batch_number'] ?? '—') ?>
                </div>
            </td>

            <!-- OD + SO -->
            <td style="min-width:120px">
                <?php if (!empty($item['od_number'])): ?>
                <div style="margin-bottom:3px">
                    <span style="font-size:.65rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em">OD</span>
                    <span class="ib-ref-chip ib-ref-od" style="margin-left:4px"><?= htmlspecialchars($item['od_number']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($item['so_number'])): ?>
                <div>
                    <span style="font-size:.65rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em">SO</span>
                    <span class="ib-ref-chip ib-ref-so" style="margin-left:4px"><?= htmlspecialchars($item['so_number']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (empty($item['od_number']) && empty($item['so_number'])): ?>
                <span style="color:#cbd5e1;font-size:.78rem">—</span>
                <?php endif; ?>
            </td>

            <!-- Qty + Pallets -->
            <td style="text-align:right;white-space:nowrap">
                <?php $itemQty = $item['actual_qty'] ?? $item['quantity'] ?? 0; ?>
                <div style="font-weight:700;font-size:.9rem;color:#0f172a">
                    <span class="ib-qty-display" data-item="<?= $item['id'] ?>"><?= number_format($itemQty) ?></span>
                    <?php if ($canEdit && ($item['in_process_status'] ?? '') === 'Dues In'): ?>
                    <button type="button" onclick="openQtyEdit(<?= $item['id'] ?>, <?= (float)$itemQty ?>)"
                            title="Edit qty"
                            style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:.7rem;padding:1px 3px;vertical-align:middle">
                        <i class="fas fa-pencil-alt"></i>
                    </button>
                    <form class="ib-qty-form" id="qty-form-<?= $item['id'] ?>" method="POST" style="display:none;margin-top:4px">
                        <input type="hidden" name="update_item_qty" value="1">
                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                        <input type="hidden" name="inbound_id" value="<?= $inbound['id'] ?>">
                        <div style="display:flex;gap:3px;align-items:center;justify-content:flex-end">
                            <input type="number" name="new_qty" min="0.01" step="any"
                                   value="<?= (float)$itemQty ?>"
                                   style="width:65px;font-size:.8rem;border:1.5px solid #6ee7b7;border-radius:5px;padding:2px 5px;text-align:right">
                            <button type="submit" style="font-size:.7rem;padding:2px 6px;background:#065f46;color:#fff;border:none;border-radius:4px;cursor:pointer">✓</button>
                            <button type="button" onclick="closeQtyEdit(<?= $item['id'] ?>)"
                                    style="font-size:.7rem;padding:2px 5px;background:#f1f5f9;border:none;border-radius:4px;cursor:pointer">✕</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
                <div style="font-size:.72rem;color:#94a3b8;margin-top:1px">
                    <?= $itemPalletCounts[$item['id']] ?? (int)ceil($item['pallet'] ?? 0) ?> pallet
                </div>
            </td>

            <!-- No. Palet -->
            <td style="text-align:center">
                <?php
                $pno = $item['pallet_no'] ?? '';
                $canEditPalletNo = $canWrite && in_array($item['in_process_status'] ?? 'Dues In', ['Dues In', 'Goods Received']);
                ?>
                <?php if ($canEditPalletNo): ?>
                <span class="pallet-no-badge" data-item="<?= $item['id'] ?>" title="Klik untuk edit"
                      style="cursor:pointer;display:inline-block;font-family:monospace;font-size:.75rem;border-radius:5px;padding:3px 8px;
                             <?= $pno ? 'background:#f0fdf4;border:1.5px solid #6ee7b7;color:#065f46;font-weight:700' : 'background:#f8fafc;border:1.5px dashed #e2e8f0;color:#94a3b8' ?>">
                    <?= $pno ? htmlspecialchars($pno) : '+ label' ?>
                </span>
                <form class="pallet-no-form" data-item="<?= $item['id'] ?>" method="post" style="display:none;margin:0">
                    <input type="hidden" name="update_pallet_no" value="1">
                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                    <input type="hidden" name="inbound_id" value="<?= $inbound['id'] ?>">
                    <input type="text" name="pallet_no" value="<?= htmlspecialchars($pno) ?>"
                           placeholder="P-001"
                           style="width:68px;font-family:monospace;font-size:.75rem;text-transform:uppercase;
                                  border:1.5px solid #6ee7b7;border-radius:5px;padding:3px 5px;outline:none">
                    <button type="submit" style="font-size:.7rem;padding:2px 5px;background:#065f46;color:#fff;border:none;border-radius:4px;cursor:pointer">✓</button>
                    <button type="button" class="pallet-no-cancel" data-item="<?= $item['id'] ?>"
                            style="font-size:.7rem;padding:2px 5px;background:#f1f5f9;border:none;border-radius:4px;cursor:pointer">✕</button>
                </form>
                <?php else: ?>
                <span style="font-family:monospace;font-size:.75rem;border-radius:5px;padding:3px 8px;display:inline-block;
                             <?= $pno ? 'background:#f0fdf4;border:1.5px solid #6ee7b7;color:#065f46;font-weight:700' : 'color:#cbd5e1' ?>">
                    <?= $pno ? htmlspecialchars($pno) : '—' ?>
                </span>
                <?php endif; ?>
            </td>

            <!-- Expiry Date -->
            <?php
            $hasMfg  = !empty($item['manufacture_date']);
            $hasExp  = !empty($item['exp_date']);
            $expNear = $hasExp && strtotime($item['exp_date']) < strtotime('+90 days');
            $expFmt  = $hasExp ? date('d M Y', strtotime($item['exp_date'])) : '—';
            $mfgFmt  = $hasMfg ? date('d M Y', strtotime($item['manufacture_date'])) : '—';
            ?>
            <td style="min-width:110px">
                <div style="font-size:.8rem;font-weight:600;
                            <?= $hasExp ? ($expNear ? 'color:#b91c1c' : 'color:#0f172a') : 'color:#f97316' ?>">
                    <?= $expFmt ?>
                    <?php if ($expNear): ?><span style="font-size:.65rem;margin-left:3px">⚠️</span><?php endif; ?>
                </div>
                <?php if ($hasMfg): ?>
                <div style="font-size:.68rem;color:#94a3b8;margin-top:1px">Mfg: <?= $mfgFmt ?></div>
                <?php endif; ?>
                <?php if (!$hasExp): ?>
                <div style="font-size:.68rem;color:#f97316;font-weight:600">Exp. belum diset</div>
                <?php endif; ?>
            </td>
            <?php
                $ips          = $item['in_process_status'] ?? '';
                $itemLoc      = $item['location'] ?? '';
                $itemLocs_loc = ($ips === 'ATP') ? Inbound::getItemLocations($item['id']) : [];
            ?>
            <!-- Status column -->
            <td style="text-align:center">
                <?php
                $ips = $item['in_process_status'] ?? 'Dues In';
                
                $ipColors = [
                    'Dues In'        => ['bg'=>'#fefce8','color'=>'#854d0e','border'=>'#fde68a','icon'=>'⏳','label'=>'Dues In'],
                    'Goods Received' => ['bg'=>'#eff6ff','color'=>'#1d4ed8','border'=>'#bfdbfe','icon'=>'📥','label'=>'Goods Received'],
                    'ATP'            => ['bg'=>'#ecfdf5','color'=>'#065f46','border'=>'#6ee7b7','icon'=>'✅','label'=>'ATP'],
                    'Unserviceable'  => ['bg'=>'#fef2f2','color'=>'#b91c1c','border'=>'#fecaca','icon'=>'⛔','label'=>'Unserviceable'],
                ];

                $validNext = [
                    'Dues In'        => ['Dues In','Goods Received'],
                    'Goods Received' => ['Goods Received','ATP','Unserviceable'],
                    'ATP'            => ['ATP','Unserviceable'],
                    'Unserviceable'  => ['Unserviceable','ATP'],
                ];
                $ipc = $ipColors[$ips] ?? ['bg'=>'#f3f4f6','color'=>'#374151','icon'=>'?','label'=>$ips];
                $allowedNext = $validNext[$ips] ?? array_keys($ipColors);
                if ($canEdit):
                ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="item_id"    value="<?= $item['id'] ?>">
                    <input type="hidden" name="inbound_id" value="<?= $inbound['id'] ?>">
                    <select name="update_item_status" onchange="handleItemStatusChange(this)"
                            style="border:1.5px solid <?= $ipc['border'] ?? '#e5e7eb' ?>;border-radius:20px;padding:4px 10px 4px 8px;
                                   font-size:.72rem;font-weight:700;cursor:pointer;
                                   background:<?= $ipc['bg'] ?>;color:<?= $ipc['color'] ?>;
                                   outline:none;min-width:140px">
                        <?php foreach($ipColors as $sv => $ipc2):
                            $isAllowed = in_array($sv, $allowedNext);
                        ?>
                        <option value="<?= $sv ?>"
                                <?= $ips===$sv ? 'selected' : '' ?>
                                <?= !$isAllowed ? 'disabled style="color:#d1d5db"' : '' ?>>
                            <?= $ipc2['icon'].' '.$ipc2['label'] ?>
                            <?= $ips===$sv ? ' ✓' : (!$isAllowed ? ' (locked)' : '') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php else: ?>
                <span style="display:inline-flex;align-items:center;gap:5px;border-radius:20px;padding:4px 11px;
                             font-size:.72rem;font-weight:700;
                             border:1.5px solid <?= $ipc['border'] ?? '#e5e7eb' ?>;
                             background:<?= $ipc['bg'] ?>;color:<?= $ipc['color'] ?>">
                    <?= $ipc['icon'].' '.htmlspecialchars($ips) ?>
                </span>
                <?php endif; ?>
            </td>

            <!-- Lokasi Putaway column — only interactive for ATP items -->
            <td style="min-width:160px">
            <?php if ($ips === 'Unserviceable'): ?>
                <span style="display:inline-flex;align-items:center;gap:4px;background:#fff3e0;
                             border:1px solid #fed7aa;border-radius:6px;padding:3px 9px;
                             font-size:.73rem;font-weight:700;color:#014f4e">
                    ⛔ QUA_SHELL
                </span>
            <?php elseif ($ips === 'ATP'):
                $itmQty = floatval($item['actual_qty'] ?? $item['quantity'] ?? 0);
                $itmUom = strtolower($item['uom'] ?? 'drum');
                if ($itmUom === 'drum')         $uomPlt = 4;
                elseif ($itmUom === 'pail')     $uomPlt = 24;
                elseif ($itmUom === 'carton')   $uomPlt = intval($item['uom_per_pallet'] ?? 44);
                else                            $uomPlt  = intval($item['uom_per_pallet'] ?? 4);
                if ($uomPlt <= 0) $uomPlt = 4;
                $fullPallets = (int)floor($itmQty / $uomPlt);
                $remainder   = fmod($itmQty, $uomPlt);
                $nPallets    = $fullPallets + ($remainder > 0 ? 1 : 0);
                if (!empty($itemLocs_loc)):
                    $locCount = count($itemLocs_loc);
            ?>
                <details style="cursor:pointer">
                    <summary style="list-style:none;user-select:none;cursor:pointer;
                                    font-size:.78rem;font-weight:700;color:#026766;
                                    display:flex;align-items:center;gap:5px">
                        <i class="fas fa-map-marker-alt" style="font-size:.7rem"></i>
                        <?= $locCount ?> lokasi
                        <?php if ($canEdit): ?>
                        <button type="button"
                                id="plBtn_<?= $item['id'] ?>"
                                onclick="event.preventDefault();openPalletLocModal_<?= $item['id'] ?>()"
                                style="background:#e0f7f7;border:1px solid #6ee7b7;border-radius:5px;
                                       padding:2px 8px;font-size:.68rem;cursor:pointer;color:#065f46;
                                       font-weight:700;margin-left:4px">
                            <i class="fas fa-pencil-alt mr-1"></i>Edit
                        </button>
                        <?php endif; ?>
                    </summary>
                    <div style="margin-top:5px;background:#f0fbfb;border-radius:6px;padding:6px 8px;
                                border:1px solid #cce8e8;min-width:200px">
                    <?php foreach($itemLocs_loc as $loc):
                        $dqty = floatval($loc['quantity'] ?? 0);
                        $isRemainder = ($loc['pallet_seq'] == 999);
                        $isFull = !$isRemainder && ($dqty >= $uomPlt);
                    ?>
                        <div style="font-size:.74rem;padding:3px 0;border-bottom:1px solid #e6f7f7;
                                    display:flex;align-items:center;gap:6px;
                                    <?= $isRemainder ? 'opacity:.75;font-style:italic' : '' ?>">
                            <span style="color:<?= $isRemainder ? '#9e6b00' : '#014f4e' ?>;
                                         font-weight:700;font-size:.7rem;min-width:24px">
                                <?= $isRemainder ? 'R' : 'P'.$loc['pallet_seq'] ?>
                            </span>
                            <span style="font-family:monospace;font-weight:700;
                                         color:<?= $isRemainder ? '#7a4f00' : '#013d3c' ?>">
                                <?= htmlspecialchars($loc['location_code']) ?>
                            </span>
                            <span style="color:#546e7a;margin-left:auto;white-space:nowrap;font-size:.7rem">
                                <?= number_format($dqty, 0) ?>
                                <span style="color:<?= $isFull ? '#026766' : ($isRemainder ? '#9e6b00' : '#e65100') ?>;
                                             margin-left:2px">(<?= $isRemainder ? 'sisa' : ($isFull ? 'full' : 'partial') ?>)</span>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </details>
                <?php if ($canEdit): ?>
                <form method="POST" id="palletLocForm_<?= $item['id'] ?>" style="display:none">
                    <input type="hidden" name="item_id"    value="<?= $item['id'] ?>">
                    <input type="hidden" name="inbound_id" value="<?= $inbound['id'] ?>">
                    <input type="hidden" name="pallet_locations_json" id="palletLocJson_<?= $item['id'] ?>">
                    <input type="hidden" name="save_pallet_locations" value="1">
                </form>
                <script>
                function openPalletLocModal_<?= $item['id'] ?>() {
                    openPalletLocModal_withFetch(<?= (int)$item['id'] ?>);
                }
                </script>
                <?php endif; ?>
            <?php else:
                // ATP but no locations saved yet
            ?>
                <?php if ($canEdit): ?>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                    <span style="font-size:.72rem;color:#d97706;font-weight:600">
                        <i class="fas fa-exclamation-triangle mr-1"></i><?= $nPallets ?> pallet belum di-assign
                    </span>
                    <button type="button"
                            id="plBtn_<?= $item['id'] ?>"
                            onclick="openPalletLocModal_<?= $item['id'] ?>()"
                            style="background:#026766;color:#fff;border:none;border-radius:6px;
                                   padding:4px 10px;font-size:.73rem;cursor:pointer;font-weight:700;
                                   white-space:nowrap">
                        <i class="fas fa-map-marker-alt mr-1"></i>Assign Lokasi
                    </button>
                </div>
                <?php else: ?>
                <span style="color:#94a3b8;font-size:.78rem">—</span>
                <?php endif; ?>
                <form method="POST" id="palletLocForm_<?= $item['id'] ?>" style="display:none">
                    <input type="hidden" name="item_id"    value="<?= $item['id'] ?>">
                    <input type="hidden" name="inbound_id" value="<?= $inbound['id'] ?>">
                    <input type="hidden" name="pallet_locations_json" id="palletLocJson_<?= $item['id'] ?>">
                    <input type="hidden" name="save_pallet_locations" value="1">
                </form>
                <script>
                function openPalletLocModal_<?= $item['id'] ?>() {
                    openPalletLocModal_withFetch(<?= (int)$item['id'] ?>);
                }
                </script>
            <?php endif; ?>
            <?php else: ?>
                <!-- Dues In / Goods Received — not yet ready for putaway -->
                <span style="color:#94a3b8;font-size:.78rem">—</span>
                <div style="font-size:.67rem;color:#b0bec5;margin-top:2px;font-style:italic">
                    Set ke ATP untuk assign
                </div>
            <?php endif; ?>
            </td>

            <?php
                $ss_auto = $item['stock_status'] ?? 'Pending';
                $ssColors = [
                    'Accepted' => ['bg'=>'#ecfdf5','color'=>'#065f46','border'=>'#6ee7b7','icon'=>'✅'],
                    'Rejected' => ['bg'=>'#fef2f2','color'=>'#b91c1c','border'=>'#fecaca','icon'=>'⛔'],
                    'Pending'  => ['bg'=>'#f8fafc','color'=>'#64748b','border'=>'#cbd5e1','icon'=>'⏳'],
                ];
                $ssc = $ssColors[$ss_auto] ?? ['bg'=>'#f3f4f6','color'=>'#374151','border'=>'#e5e7eb','icon'=>'?'];
            ?>
            <td style="text-align:center">
                <span style="display:inline-flex;align-items:center;gap:4px;border-radius:20px;padding:3px 10px;
                             font-size:.72rem;font-weight:700;
                             border:1.5px solid <?= $ssc['border'] ?>;
                             background:<?= $ssc['bg'] ?>;color:<?= $ssc['color'] ?>">
                    <?= $ssc['icon'].' '.htmlspecialchars($ss_auto) ?>
                </span>
            </td>
            <?php
            $itemIps        = $item['in_process_status'] ?? 'Dues In';
            $canDeleteItem  = $canEdit && ($itemIps !== 'Goods Received' || $canAdmin);
            ?>
            <?php if ($canEdit): ?>
            <td style="text-align:center">
                <?php if ($canDeleteItem): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                    <input type="hidden" name="inbound_id" value="<?= $inbound['id'] ?>">
                    <button type="submit" name="delete_item"
                            onclick="return confirm('Remove this item?')"
                            class="ib-btn ib-btn-danger ib-btn-sm" style="padding:4px 9px">
                        <i class="fas fa-times"></i>
                    </button>
                </form>
                <?php else: ?>
                <span title="Hanya Admin yang bisa hapus item Goods Received"
                      style="color:#d1d5db;font-size:.8rem;cursor:default">
                    <i class="fas fa-lock"></i>
                </span>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    </div>
</div>

</div>
<?php endif; ?>

<script>
function openQtyEdit(itemId, currentQty) {
    document.querySelectorAll('.ib-qty-form').forEach(f => f.style.display = 'none');
    document.querySelectorAll('.ib-qty-display').forEach(s => s.style.display = '');
    var form = document.getElementById('qty-form-' + itemId);
    var disp = document.querySelector('.ib-qty-display[data-item="' + itemId + '"]');
    if (form) { form.style.display = 'block'; form.querySelector('input[name=new_qty]').focus(); }
    if (disp) disp.style.display = 'none';
}
function closeQtyEdit(itemId) {
    var form = document.getElementById('qty-form-' + itemId);
    var disp = document.querySelector('.ib-qty-display[data-item="' + itemId + '"]');
    if (form) form.style.display = 'none';
    if (disp) disp.style.display = '';
}
function toggleReceivedRequired(statusVal) {
    var byInput   = document.getElementById('receivedByInput');
    var dtInput   = document.getElementById('receivedDateInput');
    var byMark    = document.getElementById('receivedByReqMark');
    var dtMark    = document.getElementById('receivedDateReqMark');
    var required  = (statusVal !== 'Draft' && statusVal !== 'Dues In');
    if (byInput)  { byInput.required  = required; }
    if (dtInput)  { dtInput.required  = required; }
    if (byMark)   { byMark.style.display   = required ? 'inline' : 'none'; }
    if (dtMark)   { dtMark.style.display   = required ? 'inline' : 'none'; }
}
// Init on page load
(function(){
    var sel = document.getElementById('inboundStatusSelect');
    if (sel) toggleReceivedRequired(sel.value);
})();

function toggleEditInbound() {
    var view = document.getElementById('inboundViewMode');
    var edit = document.getElementById('inboundEditMode');
    var btn  = document.getElementById('editInboundBtn');
    if (!view || !edit) return;
    if (edit.style.display === 'none') {
        view.style.display = 'none';
        edit.style.display = 'block';
        btn.innerHTML = '<i class="fas fa-times"></i> Batal';
    } else {
        view.style.display = 'block';
        edit.style.display = 'none';
        btn.innerHTML = '<i class="fas fa-edit"></i> Edit';
    }
}
function filterIbTable() {
    const q = document.getElementById('ibSearch').value.toLowerCase();
    const st = document.getElementById('ibStatusFilter').value;
    const mo = document.getElementById('ibMonthFilter').value;
    const rows = document.querySelectorAll('#ibTbody tr[data-order]');
    let visible = 0;
    rows.forEach(row => {
        const matchQ  = !q  || row.dataset.order.includes(q) || row.dataset.carrier.includes(q) || row.dataset.container.includes(q) || (row.dataset.od||'').includes(q);
        const matchSt = !st || row.dataset.status === st;
        const matchMo = !mo || row.dataset.date === mo;
        const show = matchQ && matchSt && matchMo;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const cnt = document.getElementById('ibCount');
    if (cnt) cnt.textContent = visible + ' order ditampilkan';
}

function confirmDelete(id, number) {
    document.getElementById('delIbId').value = id;
    document.getElementById('delIbNumber').textContent = number;
    document.getElementById('deleteInboundModal').style.display = 'flex';
}

let productSearchTimer = null;
let selectedProduct = null;

let ibProductMap = {};

function searchProducts(q) {
    clearTimeout(productSearchTimer);
    const dd = document.getElementById('productDropdown');
    if (q.length < 1) { dd.style.display='none'; return; }
    dd.style.display='block';
    dd.innerHTML = '<div style="padding:10px 14px;color:#90a4ae;font-size:.83rem"><i class="fas fa-spinner fa-spin mr-1"></i> Mencari...</div>';
    productSearchTimer = setTimeout(async () => {
        try {
            const res = await fetch('inbound_api.php?action=search_products&q='+encodeURIComponent(q));
            const data = await res.json();
            if (!data.results || data.results.length === 0) {
                dd.innerHTML = '<div style="padding:10px 14px;color:#90a4ae;font-size:.83rem"><i class="fas fa-box-open mr-1"></i> Produk tidak ditemukan</div>';
                return;
            }
            ibProductMap = {};
            data.results.forEach(p => { ibProductMap[p.id] = p; });
            dd.innerHTML = data.results.map(p => `
                <div data-pid="${p.id}"
                     style="padding:10px 14px;cursor:pointer;border-bottom:1px solid #e6f7f7;font-size:.85rem;
                            display:flex;justify-content:space-between;align-items:center;user-select:none"
                     onmouseover="this.style.background='#e6f7f7'" onmouseout="this.style.background=''">
                    <div>
                        <div style="font-weight:600;color:#1a237e">${p.product_code}</div>
                        <div style="color:#546e7a;font-size:.8rem">${p.product_name}</div>
                    </div>
                    <div style="text-align:right;font-size:.75rem;color:#78909c">
                        <div>${p.uom}</div>
                        ${p.stock_qty > 0 ? `<div style="color:#026766;font-weight:600">Stok: ${p.stock_qty}</div>` : '<div style="color:#bbb">No stock</div>'}
                    </div>
                </div>`).join('');
        } catch(e) {
            dd.innerHTML = '<div style="padding:10px 14px;color:#026766;font-size:.83rem">Error memuat produk</div>';
        }
    }, 200);
}

document.addEventListener('DOMContentLoaded', () => {
    const dd = document.getElementById('productDropdown');
    if (dd) {
        dd.addEventListener('mousedown', (e) => {
            e.preventDefault(); 
            const item = e.target.closest('[data-pid]');
            if (item) selectProduct(ibProductMap[item.dataset.pid]);
        });
    }
});

function selectProduct(p) {
    if (!p) return;
    selectedProduct = p;
    document.getElementById('productId').value = p.id;
    document.getElementById('productSearch').value = p.product_code + ' — ' + p.product_name;
    document.getElementById('productDropdown').style.display = 'none';
    updateProductInfo();
    document.getElementById('productSearch').focus();
}

function showDropdown() {
    const dd = document.getElementById('productDropdown');
    if (dd.innerHTML.trim() !== '') dd.style.display = 'block';
}

function hideDropdownDelayed() {
    setTimeout(() => {
        document.getElementById('productDropdown').style.display = 'none';
    }, 200);
}

function updateProductInfo() {
    const p = selectedProduct;
    const info = document.getElementById('productInfo');
    if (!p) { info.innerHTML = ''; return; }
    const uom = p.uom || 'Drum';
    const upp = p.uom_per_pallet || 4;
    document.getElementById('uomSelect').value = uom;
    document.getElementById('uomSelect').dataset.uomPerPallet = upp;
    info.innerHTML = `<span style="color:#026766">UOM: <b>${uom}</b></span> &nbsp;·&nbsp; ${upp}/pallet`;
    if (uom === 'Carton') {
        const cartSel = document.getElementById('cartonsPerPallet');
        const uppInt = parseInt(upp);
        const validOpts = [36, 44, 48];
        const best = validOpts.includes(uppInt) ? uppInt : validOpts.reduce((a,b) => Math.abs(b-uppInt) < Math.abs(a-uppInt) ? b : a);
        cartSel.value = best;
    }
    updateUOMOptions(); calculatePallet();
}

function updateUOMOptions() {
    const uom = document.getElementById('uomSelect').value;
    document.getElementById('cartonPalletDiv').style.display = uom === 'Carton' ? 'block' : 'none';
    calculatePallet();
}

function calculatePallet() {
    const qty = parseFloat(document.getElementById('quantityInput').value) || 0;
    const uom = document.getElementById('uomSelect').value;
    const msg = document.getElementById('validationMsg');
    const disp = document.getElementById('palletDisplay');
    const maxTrans = parseInt(document.getElementById('productSelect')?.options[document.getElementById('productSelect')?.selectedIndex]?.dataset.maxTrans) || 99999;

    const uomRates = { 'Drum': 4, 'Pail': 24, 'EA': 4, 'Bags': 1 };
    const perPallet = uom === 'Carton'
        ? (parseInt(document.getElementById('cartonsPerPallet').value) || 44)
        : (uomRates[uom] ?? 4);

    const rawPallet  = qty > 0 ? (qty / perPallet) : 0;
    const palletCeil = Math.ceil(rawPallet);
    const full       = Math.floor(rawPallet);
    const remUnits   = qty % perPallet;

    document.getElementById('palletInput').value = palletCeil;

    let breakdown = '—';
    if (qty > 0) {
        if (remUnits === 0) {
            breakdown = `${palletCeil} plt (${full} full @ ${perPallet} ${uom}/plt)`;
        } else {
            breakdown = `${palletCeil} plt = ${full} full + 1 partial (${remUnits} ${uom})`;
        }
    }
    disp.value = breakdown;

    if (qty > maxTrans) {
        disp.className = 'ib-input ib-pallet-err';
        msg.innerHTML = '<span style="color:#014f4e">⚠ Exceeds max transaction (' + maxTrans + ')</span>';
    } else if (qty > 0) {
        disp.className = 'ib-input ib-pallet-ok';
        msg.innerHTML = '<span style="color:#013d3c">✓ Within limit</span>';
    } else {
        disp.className = 'ib-input ib-input-ro';
        msg.innerHTML = '';
    }
}

function getUomPerPallet() {
    const uom = document.getElementById('uomSelect').value;
    const uomRates = { 'Drum': 4, 'Pail': 24, 'EA': 4, 'Bags': 1 };
    return uom === 'Carton'
        ? (parseInt(document.getElementById('cartonsPerPallet').value) || 44)
        : (uomRates[uom] ?? 4);
}

function autoCalculateExpDate() {
    const mfgDateInput = document.getElementById('manufactureDateInput');
    const expDateInput = document.getElementById('expDateInput');
    if (!mfgDateInput || !expDateInput) return;

    if (!mfgDateInput.value) {
        expDateInput.value = '';
        return;
    }

    try {
        const mfgDate = new Date(mfgDateInput.value);
        if (isNaN(mfgDate.getTime())) return;

        
        const expDate = new Date(mfgDate);
        expDate.setFullYear(expDate.getFullYear() + 4);

        const year  = expDate.getFullYear();
        const month = String(expDate.getMonth() + 1).padStart(2, '0');
        const day   = String(expDate.getDate()).padStart(2, '0');
        expDateInput.value = `${year}-${month}-${day}`;
    } catch (e) {}
}

function ensureExpDateBeforeSubmit() {
    const mfgDateInput = document.getElementById('manufactureDateInput');
    const expDateInput = document.getElementById('expDateInput');

    
    if (mfgDateInput && mfgDateInput.value && expDateInput) {
        try {
            const mfgDate = new Date(mfgDateInput.value);
            if (!isNaN(mfgDate.getTime())) {
                const expDate = new Date(mfgDate);
                expDate.setFullYear(expDate.getFullYear() + 4);
                const year  = expDate.getFullYear();
                const month = String(expDate.getMonth() + 1).padStart(2, '0');
                const day   = String(expDate.getDate()).padStart(2, '0');
                expDateInput.value = `${year}-${month}-${day}`;
            }
        } catch (e) {}
    }
    
    
    return true;
}

const PROCESS_STATUS_MAP = {
    'Dues In':         { stock: 'Pending',  locHint: 'STAGING',   locForce: false },
    'Goods Received':  { stock: 'Pending',  locHint: '',          locForce: false },
    'ATP':             { stock: 'Accepted', locHint: '',          locForce: false },
    'Unserviceable':   { stock: 'Rejected', locHint: 'QUA_SHELL', locForce: true  },
};

const STOCK_STATUS_COLORS = {
    'Accepted': { bg: '#e0f7f7', color: '#013d3c', icon: '✅' },
    'Rejected': { bg: '#e0f7f7', color: '#013d3c', icon: '✗'  },
    'Pending':  { bg: '#fef9c3', color: '#f57c00', icon: '⏳' },
};

function handleItemStatusChange(sel) {
    var val  = sel.value;
    var form = sel.closest('form');
    
    if (val === 'Unserviceable') {
        sel.style.background = '#e0f7f7';
        sel.style.color      = '#013d3c';
        
        var t = document.createElement('div');
        t.textContent = '⛔ Auto → QUA_SHELL';
        t.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#013d3c;color:#fff;'
                        + 'padding:10px 16px;border-radius:8px;font-weight:700;z-index:99999;font-size:.82rem';
        document.body.appendChild(t);
        setTimeout(function(){ document.body.removeChild(t); }, 2000);
    } else if (val === 'ATP') {
        sel.style.background = '#e0f7f7';
        sel.style.color      = '#013d3c';
    } else {
        sel.style.background = '';
        sel.style.color      = '';
    }
    form.submit();
}

function onProcessStatusChange(val) {
    const map    = PROCESS_STATUS_MAP[val] || {};
    const badge  = document.getElementById('autoStockStatusBadge');
    const txt    = document.getElementById('autoStockStatusText');

    const stockVal = map.stock || 'Pending';
    const sc       = STOCK_STATUS_COLORS[stockVal] || STOCK_STATUS_COLORS['Pending'];
    if (badge) {
        badge.style.background = sc.bg;
        badge.style.color      = sc.color;
    }
    if (txt) txt.textContent = sc.icon + ' ' + stockVal;
}


document.querySelectorAll('.pallet-no-badge').forEach(function(badge) {
    badge.addEventListener('click', function() {
        var id = this.dataset.item;
        this.style.display = 'none';
        document.querySelector('.pallet-no-form[data-item="' + id + '"]').style.display = 'inline-flex';
        document.querySelector('.pallet-no-form[data-item="' + id + '"] input[name="pallet_no"]').focus();
    });
});
document.querySelectorAll('.pallet-no-cancel').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id = this.dataset.item;
        document.querySelector('.pallet-no-form[data-item="' + id + '"]').style.display = 'none';
        document.querySelector('.pallet-no-badge[data-item="' + id + '"]').style.display = 'inline-block';
    });
});

</script>

<div id="palletLocModal"
     style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:2147483647;
            background:rgba(0,0,0,.55);align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;width:620px;max-width:96vw;max-height:90vh;
              display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.35)">

    <!-- Header -->
    <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;flex-shrink:0">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
        <h3 style="margin:0;font-size:1rem;font-weight:800;color:#013d3c">
          <i class="fas fa-map-marker-alt mr-2"></i>Alokasi Lokasi Per Pallet
        </h3>
        <button onclick="document.getElementById('palletLocModal').style.display='none'"
                style="background:#f3f4f6;border:none;border-radius:8px;padding:5px 10px;
                       cursor:pointer;color:#374151;font-size:.85rem">✕ Tutup</button>
      </div>
      <!-- Column headers -->
      <div style="display:grid;grid-template-columns:34px 1fr 96px 56px 30px;gap:8px;
                  padding:6px 0;font-size:.68rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em">
        <div style="text-align:center">#</div>
        <div>Lokasi</div>
        <div style="text-align:right">Qty</div>
        <div style="text-align:center">Pallet</div>
        <div></div>
      </div>
    </div>

    <!-- Rows -->
    <div id="plPalletRows" style="overflow-y:auto;padding:4px 20px;flex:1;min-height:80px"></div>

    <!-- Footer -->
    <div style="padding:12px 20px;border-top:1px solid #e5e7eb;flex-shrink:0">
      <!-- Summary + add row -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px">
        <div id="plSummary" style="font-size:.82rem"></div>
        <div style="display:flex;gap:8px">
          <button type="button" onclick="plAutoSuggest()"
                  style="background:#eff6ff;color:#1d4ed8;border:1.5px solid #bfdbfe;border-radius:7px;
                         padding:6px 12px;font-size:.78rem;font-weight:700;cursor:pointer;
                         display:inline-flex;align-items:center;gap:5px">
            <i class="fas fa-magic"></i> Auto-Suggest
          </button>
          <button type="button" onclick="plAddRow()"
                  style="background:#ecfdf5;color:#065f46;border:1.5px solid #6ee7b7;border-radius:7px;
                         padding:6px 12px;font-size:.78rem;font-weight:700;cursor:pointer;
                         display:inline-flex;align-items:center;gap:5px">
            <i class="fas fa-plus"></i> Tambah Baris
          </button>
        </div>
      </div>
      <!-- Action buttons -->
      <div style="display:flex;gap:10px">
        <button id="plSaveBtn" onclick="savePalletLocs()"
                style="flex:1;background:#026766;color:#fff;border:none;border-radius:8px;
                       padding:11px;font-weight:700;cursor:pointer;font-size:.875rem;
                       display:inline-flex;align-items:center;justify-content:center;gap:8px">
          <i class="fas fa-check"></i> Simpan Lokasi
        </button>
        <button onclick="document.getElementById('palletLocModal').style.display='none'"
                style="background:#f1f5f9;color:#475569;border:none;border-radius:8px;
                       padding:11px 20px;cursor:pointer;font-weight:600;font-size:.875rem">Batal</button>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

<!-- Modal: Start Receiving -->
<div id="receivingModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;
     z-index:99999;background:rgba(0,0,0,.5);align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px 32px;max-width:460px;width:92%;
              box-shadow:0 24px 64px rgba(0,0,0,.25)">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
      <div style="background:#fff7ed;border-radius:50%;width:46px;height:46px;flex-shrink:0;
                  display:flex;align-items:center;justify-content:center">
        <i class="fas fa-truck-loading" style="color:#c2410c;font-size:1.1rem"></i>
      </div>
      <div>
        <div style="font-size:1.05rem;font-weight:700;color:#1e293b">Start Receiving</div>
        <div style="font-size:.8rem;color:#64748b">Isi data penerimaan barang</div>
      </div>
      <button onclick="closeReceivingModal()" style="margin-left:auto;background:none;border:none;
              cursor:pointer;color:#94a3b8;font-size:1.1rem;padding:4px">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form method="POST">
      <input type="hidden" name="id" value="<?= $inbound['id'] ?? '' ?>">
      <input type="hidden" name="new_status" value="Receiving">
      <div style="display:flex;flex-direction:column;gap:14px">
        <div>
          <label style="display:block;font-size:.78rem;font-weight:700;color:#374151;margin-bottom:5px">
            Received By <span style="color:#ef4444">*</span>
          </label>
          <select name="received_by_id" id="rcvByInput" required class="ib-select" style="font-size:.9rem">
            <option value="">— Pilih Penerima —</option>
            <?php foreach ($usersList as $u): ?>
            <option value="<?= $u['id'] ?>"
              <?= (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $u['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($u['full_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:.78rem;font-weight:700;color:#374151;margin-bottom:5px">
            Received Date <span style="color:#ef4444">*</span>
          </label>
          <input type="date" name="received_date" id="rcvDateInput" required
                 class="ib-input" value="<?= date('Y-m-d') ?>"
                 style="font-size:.9rem">
        </div>
        <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:10px 14px;
                    font-size:.78rem;color:#92400e">
          <i class="fas fa-info-circle" style="margin-right:5px"></i>
          Setelah Start Receiving, status order berubah ke <strong>Receiving</strong> dan tim dapat mulai update status setiap item.
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px">
        <button type="submit" name="advance_status"
                style="flex:1;background:#c2410c;color:#fff;border:none;border-radius:8px;
                       padding:11px;font-weight:700;cursor:pointer;font-size:.9rem;
                       display:inline-flex;align-items:center;justify-content:center;gap:8px">
          <i class="fas fa-truck-loading"></i> Confirm Start Receiving
        </button>
        <button type="button" onclick="closeReceivingModal()"
                style="padding:11px 20px;background:#f1f5f9;color:#475569;border:none;
                       border-radius:8px;font-weight:600;cursor:pointer;font-size:.9rem">
          Batal
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openReceivingModal() {
    document.getElementById('receivingModal').style.display = 'flex';
    setTimeout(function(){ document.getElementById('rcvByInput').focus(); }, 100);
}
function closeReceivingModal() {
    document.getElementById('receivingModal').style.display = 'none';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeReceivingModal();
});
</script>

<div id="deleteInboundModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;
     z-index:99999;background:rgba(0,0,0,.55);align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px;max-width:440px;width:92%;
              box-shadow:0 24px 64px rgba(0,0,0,.3)">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
      <div style="background:#e6f7f7;border-radius:50%;width:46px;height:46px;flex-shrink:0;
                  display:flex;align-items:center;justify-content:center">
        <i class="fas fa-exclamation-triangle" style="color:#026766;font-size:1.1rem"></i>
      </div>
      <div>
        <div style="font-weight:800;font-size:1rem;color:#111">Hapus Inbound Order?</div>
        <div style="font-family:monospace;font-size:.85rem;color:#6b7280;font-weight:600" id="delIbNumber"></div>
      </div>
    </div>
    <div style="background:#e6f7f7;border:1px solid #b2e5e5;border-radius:8px;padding:10px 14px;font-size:.8rem;color:#013d3c;margin-bottom:12px">
      <i class="fas fa-database mr-1"></i>
      <b>Semua data terkait akan dihapus permanen:</b><br>
      &bull; Stock yang sudah masuk dari inbound ini akan <b>dikurangi</b><br>
      &bull; Ledger entries IN akan dihapus<br>
      &bull; Data lokasi & pallet dihapus<br>
      &bull; Item-item inbound dihapus
    </div>
    <div style="font-size:.82rem;color:#374151;margin-bottom:16px">Aksi ini <b>tidak bisa dibatalkan</b>. Lanjutkan?</div>
    <form method="POST" style="display:flex;gap:10px">
      <input type="hidden" name="delete_inbound" value="1">
      <input type="hidden" name="id" id="delIbId">
      <button type="submit"
              style="flex:1;background:#026766;color:#fff;border:none;border-radius:8px;
                     padding:10px;font-weight:700;cursor:pointer;font-size:.88rem">
        <i class="fas fa-trash mr-1"></i>Ya, Hapus + Reverse Stock
      </button>
      <button type="button"
              onclick="document.getElementById('deleteInboundModal').style.display='none'"
              style="flex:1;background:#f3f4f6;color:#374151;border:none;border-radius:8px;
                     padding:10px;font-weight:600;cursor:pointer;font-size:.88rem">Batal</button>
    </form>
  </div>
</div>
