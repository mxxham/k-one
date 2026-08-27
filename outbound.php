<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Outbound.php';
require_once __DIR__ . '/classes/Product.php';
require_once __DIR__ . '/classes/Customer.php';
require_once __DIR__ . '/classes/ExcelExport.php';
require_once __DIR__ . '/classes/ActivityLogger.php';
Auth::requireAuth();
$canWrite = Auth::canWrite();
$canAdmin = Auth::canAdmin();

if (isset($_GET['export'])) {
    $filterStatus = $_GET['status'] ?? null;
    $odNo         = trim($_GET['od_no'] ?? '') ?: null;
    $outbounds    = Outbound::getAll($filterStatus, 2000, 0, $odNo);
    ExcelExport::exportOutbound($outbounds);
    exit;
}

$pageTitle   = 'Outbound';
$currentPage = 'outbound';

$action = $_GET['action'] ?? 'list';
$id     = $_GET['id']     ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireWrite();
    try {
        if (isset($_POST['create_outbound'])) {
            $rawItems = $_POST['items'] ?? [];
            // Pre-validate: skip items with zero available stock
            $validItems   = [];
            $skippedItems = [];
            $dbChk = db();
            foreach ($rawItems as $item) {
                $pid = (int)($item['product_id'] ?? 0);
                if (!$pid) continue;
                $avail = Outbound::getTotalAvailableQty($pid);
                if ($avail <= 0) {
                    $pRow = $dbChk->prepare("SELECT product_code, product_name FROM products WHERE id=?");
                    $pRow->execute([$pid]);
                    $p = $pRow->fetch();
                    $skippedItems[] = ($p['product_code'] ?? 'ID:'.$pid) . ' – ' . ($p['product_name'] ?? 'Unknown');
                } else {
                    $validItems[] = $item;
                }
            }
            if (!empty($rawItems) && empty($validItems)) {
                throw new Exception("Semua produk tidak ada di stok. Order tidak dibuat.\nDilewati: " . implode(', ', $skippedItems));
            }
            $data = [
                'order_date'      => $_POST['order_date'],
                'customer_id'     => !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null,
                'so_number'       => $_POST['so_number']      ?? null,
                'do_number'       => $_POST['do_number']       ?? null,
                'shipment_number' => $_POST['shipment_number'] ?? null,
                'destination'     => $_POST['destination']     ?? null,
                'kota'            => $_POST['kota']            ?? null,
                'armada_no'       => $_POST['armada_no']       ?? null,
                'container_no'    => $_POST['container_no']    ?? null,
                'jenis_armada'    => $_POST['jenis_armada']    ?? null,
                'expected_date'   => !empty($_POST['expected_date']) ? $_POST['expected_date'] : null,
                'status'          => $_POST['status']          ?? 'Open',
                'notes'           => $_POST['notes']           ?? null,
                'items'           => $validItems,
            ];
            $id = Outbound::create($data);
            if (!empty($_POST['dest_name']) && is_array($_POST['dest_name'])) {
                Outbound::saveDestinations((int)$id, $_POST['dest_name'], $_POST['dest_location']??[], $_POST['dest_street']??[], $_POST['dest_kota']??[], $_POST['dest_notes']??[]);
            }
            ActivityLogger::log('CREATE_OUTBOUND', 'outbound', 'Outbound', (int)$id,
                null, "Buat outbound, customer ID " . ($data['customer_id']??'—') . ", SO: " . ($data['so_number']??'—'));
            if (!empty($skippedItems)) {
                $_SESSION['ob_warnings'] = $skippedItems;
            }
            header('Location: outbound.php?action=view&id=' . $id . '&success=created'); exit;
        }
        if (isset($_POST['update_outbound'])) {
            $obCheck = Outbound::getById((int)$_POST['id']);
            if (($obCheck['status'] ?? '') === 'Completed') {
                throw new Exception('Order sudah Completed dan tidak dapat diedit.');
            }
            $data = [
                'order_date'    => $_POST['order_date'],
                'customer_id'   => !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null,
                'so_number'     => $_POST['so_number']     ?? null,
                'do_number'     => $_POST['do_number']     ?? null,
                'shipment_number'  => $_POST['shipment_number']  ?? null,
                'destination'   => $_POST['destination']   ?? null,
                'kota'          => $_POST['kota']          ?? null,
                'armada_no'     => $_POST['armada_no']     ?? null,
                'container_no'  => $_POST['container_no']  ?? null,
                'jenis_armada'  => $_POST['jenis_armada']  ?? null,
                'expected_date' => !empty($_POST['expected_date']) ? $_POST['expected_date'] : null,
                'status'        => $_POST['status']        ?? 'Open',
                'notes'         => $_POST['notes']         ?? null,
            ];
            Outbound::update($_POST['id'], $data);
            if (!empty($_POST['dest_name']) && is_array($_POST['dest_name'])) {
                Outbound::saveDestinations((int)$_POST['id'], $_POST['dest_name'], $_POST['dest_location']??[], $_POST['dest_street']??[], $_POST['dest_kota']??[], $_POST['dest_notes']??[]);
            }
            ActivityLogger::log('UPDATE_OUTBOUND', 'outbound', 'Outbound', (int)$_POST['id'],
                null, "Edit outbound ID " . $_POST['id']);
            header('Location: outbound.php?action=view&id=' . $_POST['id'] . '&success=updated'); exit;
        }
        if (isset($_POST['add_item'])) {
            $obCheck = Outbound::getById((int)$_POST['outbound_id']);
            if (($obCheck['status'] ?? '') === 'Completed') {
                throw new Exception('Order sudah Completed dan tidak dapat diedit.');
            }

            $manualLocs = null;
            $manualLoc  = null;
            $locMode    = $_POST['loc_mode'] ?? 'auto';
            if ($locMode === 'manual') {
                if (!empty($_POST['manual_locs'])) {
                    $decoded = json_decode($_POST['manual_locs'], true);
                    if (is_array($decoded) && count($decoded) > 0) {
                        $manualLocs = $decoded;
                    }
                }
                if (!$manualLocs && !empty($_POST['manual_location'])) {
                    $manualLoc = trim($_POST['manual_location']);
                }
            }
            try {
                Outbound::addItemWithFEFO($_POST['outbound_id'], [
                    'product_id'      => $_POST['product_id'],
                    'quantity'        => $_POST['quantity'],
                    'uom'             => $_POST['uom'] ?? 'Drum',
                    'actual_qty'      => $_POST['actual_qty'] ?? $_POST['quantity'],
                    'manual_location' => $manualLoc,
                    'manual_locs'     => $manualLocs,
                    'notes'           => $_POST['notes'] ?? null,
                    'od_number'       => trim($_POST['od_number'] ?? '') ?: null,
                    'so_number'       => trim($_POST['so_number'] ?? '') ?: null,
                    'destination_id'  => (isset($_POST['destination_id']) && (int)$_POST['destination_id'] > 0) ? (int)$_POST['destination_id'] : null,
                    'customer_id'     => (isset($_POST['item_customer_id']) && (int)$_POST['item_customer_id'] > 0) ? (int)$_POST['item_customer_id'] : null,
                ]);

                
                $itemShipToName   = trim($_POST['item_ship_to_name']   ?? '');
                $itemShipToLoc    = trim($_POST['item_ship_to_location'] ?? '');
                $itemShipToStreet = trim($_POST['item_ship_to_street']  ?? '');
                if (!empty($itemShipToName) || !empty($itemShipToLoc)) {
                    $db2 = db();
                    
                    $lastItemStmt = $db2->prepare("SELECT id FROM outbound_items WHERE outbound_order_id=? ORDER BY id DESC LIMIT 1");
                    $lastItemStmt->execute([(int)$_POST['outbound_id']]);
                    $lastItem = $lastItemStmt->fetch();
                    
                    $seqStmt = $db2->prepare("SELECT COALESCE(MAX(seq),0)+1 as next_seq FROM outbound_destinations WHERE outbound_id=?");
                    $seqStmt->execute([(int)$_POST['outbound_id']]);
                    $nextSeq = $seqStmt->fetch()['next_seq'] ?? 1;
                    
                    $existsDest = $db2->prepare("SELECT id FROM outbound_destinations WHERE outbound_id=? AND ship_to_name=? LIMIT 1");
                    $existsDest->execute([(int)$_POST['outbound_id'], $itemShipToName]);
                    $existingDest = $existsDest->fetch();
                    if (!$existingDest) {
                        try {
                            $db2->prepare("INSERT INTO outbound_destinations (outbound_id, seq, ship_to_name, ship_to_location, kota, ship_to_street, notes) VALUES (?,?,?,?,?,?,?)")
                                ->execute([(int)$_POST['outbound_id'], $nextSeq, $itemShipToName, $itemShipToLoc, $itemShipToLoc, $itemShipToStreet, null]);
                            $newDestId = $db2->lastInsertId();
                            
                            if ($lastItem) {
                                try {
                                    $db2->prepare("UPDATE outbound_items SET destination_id=? WHERE id=?")
                                        ->execute([$newDestId, $lastItem['id']]);
                                } catch (\PDOException $e2) {}
                            }
                        } catch (\PDOException $e3) {}
                    } elseif ($lastItem) {
                        try {
                            $db2->prepare("UPDATE outbound_items SET destination_id=? WHERE id=?")
                                ->execute([$existingDest['id'], $lastItem['id']]);
                        } catch (\PDOException $e4) {}
                    }
                }
                $locSummary = $manualLocs ? implode(', ', array_column($manualLocs, 'location')) : ($manualLoc ?: 'FEFO auto');
                ActivityLogger::log('ADD_OUTBOUND_ITEM', 'outbound', 'Outbound', (int)$_POST['outbound_id'],
                    null, "Tambah item produk ID " . ($_POST['product_id']??'?') . " qty " . ($_POST['quantity']??0) . " lokasi: $locSummary");
                header('Location: outbound.php?action=view&id=' . $_POST['outbound_id'] . '&success=item_added'); exit;
            } catch (Exception $e) {
                $errorMsg = $e->getMessage();
                $action = 'view';
                $id     = $_POST['outbound_id'] ?? $id;
            }
        }
        if (isset($_POST['pick_items'])) {
            $ob = Outbound::getById((int)$_POST['id']);
            if (empty($ob['expected_date'])) {
                throw new Exception('Expected Date wajib diisi sebelum Pick Items.');
            }
            Outbound::pickItems($_POST['id']);
            ActivityLogger::log('PICK_OUTBOUND', 'outbound', 'Outbound', (int)$_POST['id'],
                $ob['order_number']??null, "Pick outbound " . ($ob['order_number']??$_POST['id']));
            header('Location: outbound.php?action=view&id=' . $_POST['id'] . '&success=picked'); exit;
        }
        if (isset($_POST['ship_outbound'])) {
            $ob = Outbound::getById((int)$_POST['id']);
            Outbound::ship($_POST['id']);
            ActivityLogger::log('SHIP_OUTBOUND', 'outbound', 'Outbound', (int)$_POST['id'],
                $ob['order_number']??null, "Kirim outbound " . ($ob['order_number']??$_POST['id']));
            header('Location: outbound.php?action=view&id=' . $_POST['id'] . '&success=shipped'); exit;
        }
        if (isset($_POST['complete_outbound'])) {
            $ob = Outbound::getById((int)$_POST['id']);
            Outbound::complete($_POST['id']);
            ActivityLogger::log('COMPLETE_OUTBOUND', 'outbound', 'Outbound', (int)$_POST['id'],
                $ob['order_number']??null, "Selesai outbound " . ($ob['order_number']??$_POST['id']));
            header('Location: outbound.php?action=view&id=' . $_POST['id'] . '&success=completed'); exit;
        }
        if (isset($_POST['delete_outbound'])) {
            $ob = Outbound::getById((int)$_POST['id']);
            if (in_array($ob['status'] ?? '', ['Completed','Cancelled','Shipped','Delivered'])) {
                throw new Exception('Order sudah ' . $ob['status'] . ' dan tidak dapat dihapus.');
            }
            Outbound::delete($_POST['id']);
            ActivityLogger::log('DELETE_OUTBOUND', 'outbound', 'Outbound', (int)$_POST['id'],
                $ob['order_number']??null, "Hapus outbound " . ($ob['order_number']??$_POST['id']));
            header('Location: outbound.php?action=list&success=deleted'); exit;
        }
        if (isset($_POST['delete_item'])) {
            $obCheck = Outbound::getById((int)$_POST['outbound_id']);
            if (($obCheck['status'] ?? '') === 'Completed') {
                throw new Exception('Order sudah Completed dan tidak dapat diedit.');
            }
            Outbound::deleteItem($_POST['item_id']);
            ActivityLogger::log('DELETE_OUTBOUND_ITEM', 'outbound', 'Outbound', (int)$_POST['outbound_id'],
                null, "Hapus item ID " . $_POST['item_id'] . " dari outbound ID " . $_POST['outbound_id']);
            header('Location: outbound.php?action=view&id=' . $_POST['outbound_id'] . '&success=item_deleted'); exit;
        }
        if (isset($_POST['update_ob_item_status'])) {
            $allowed = ['Goods Received','ATP','Unserviceable'];
            $newSt   = trim($_POST['update_ob_item_status'] ?? '');
            if (in_array($newSt, $allowed)) {
                $db  = db();
                $iid = (int)$_POST['item_id'];


                $itRow = $db->prepare("SELECT oi.*, oo.status AS order_status
                    FROM outbound_items oi
                    JOIN outbound_orders oo ON oo.id = oi.outbound_order_id
                    WHERE oi.id = ?");
                $itRow->execute([$iid]);
                $it = $itRow->fetch();


                // ATP hanya boleh jika batch sudah ada di stock Available
                if ($newSt === 'ATP' && ($it['in_process_status'] ?? '') !== 'ATP') {
                    $stockCheck = $db->prepare(
                        "SELECT COUNT(*) FROM stock
                         WHERE product_id = ?
                           AND batch_number <=> COALESCE(?, ?)
                           AND stock_status = 'Available'
                           AND quantity > 0
                           AND (location IS NULL OR location NOT IN ('QUA_SHELL','STAGING'))"
                    );
                    $stockCheck->execute([$it['product_id'], $it['batch_number'] ?? null, $it['batch_no'] ?? null]);
                    if ($stockCheck->fetchColumn() == 0) {
                        header('Location: outbound.php?action=view&id=' . $_POST['outbound_id'] . '&error=inbound_not_atp');
                        exit;
                    }
                }

                $db->beginTransaction();
                try {
                    
                    if ($newSt === 'Unserviceable') {
                        if (in_array($it['order_status'] ?? '', ['Picking','Shipped'])) {
                            $batch = $it['batch_no'] ?? $it['batch_number'] ?? null;
                            $qty   = floatval($it['actual_qty'] ?? $it['quantity'] ?? 0);
                            $loc   = $it['location'] ?? null;

                            
                            
                            

                            
                            if ($loc && $loc !== 'QUA_SHELL') {
                                $db->prepare("UPDATE stock
                                    SET quantity = GREATEST(0, quantity - ?)
                                    WHERE product_id=? AND batch_number<=>? AND location=?
                                    AND stock_status='Available'")
                                   ->execute([$qty, $it['product_id'], $batch, $loc]);
                                $db->prepare("DELETE FROM stock WHERE quantity<=0 AND location=? AND stock_status='Available'")
                                   ->execute([$loc]);
                            }

                            
                            $db->prepare("INSERT INTO stock
                                (product_id, batch_number, location, quantity, uom, pallet, stock_status)
                                VALUES (?,?, 'QUA_SHELL', ?,?,?, 'Rejected')
                                ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity), pallet=pallet+VALUES(pallet)")
                               ->execute([$it['product_id'], $batch, $qty,
                                          $it['uom']??'Drum', floatval($it['pallet']??0)]);

                            
                            $db->prepare("UPDATE outbound_items SET location='QUA_SHELL' WHERE id=?")
                               ->execute([$iid]);

                            
                            $db->prepare("DELETE FROM outbound_item_locations WHERE outbound_item_id=?")
                               ->execute([$iid]);
                        }
                    }

                    
                    if ($newSt === 'ATP' && ($it['in_process_status'] ?? '') === 'Unserviceable') {
                        $batch = $it['batch_no'] ?? $it['batch_number'] ?? null;
                        $qty   = floatval($it['actual_qty'] ?? $it['quantity'] ?? 0);
                        
                        $db->prepare("UPDATE stock SET quantity=GREATEST(0,quantity-?)
                            WHERE product_id=? AND batch_number<=>? AND location='QUA_SHELL'
                            AND stock_status='Rejected'")
                           ->execute([$qty, $it['product_id'], $batch]);
                        $db->prepare("DELETE FROM stock WHERE quantity<=0 AND stock_status='Rejected'")
                           ->execute();
                        
                        $db->prepare("UPDATE outbound_items SET location=NULL WHERE id=? AND location='QUA_SHELL'")
                           ->execute([$iid]);
                    }

                    
                    $db->prepare("UPDATE outbound_items SET in_process_status=? WHERE id=?")
                       ->execute([$newSt, $iid]);

                    $db->commit();
                } catch (Exception $ex) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $ex;
                }
            }
            ActivityLogger::log('UPDATE_ITEM_STATUS', 'outbound', 'Outbound', (int)$_POST['outbound_id'],
                null, "Item ID " . $_POST['item_id'] . " status → $newSt");
            header('Location: outbound.php?action=view&id=' . $_POST['outbound_id']); exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$outbound      = $id ? Outbound::getById($id) : null;
$outboundItems = $outbound ? Outbound::getItems($id) : [];
$_obOdFilter   = trim($_GET['od_no'] ?? '');
$_obPerPage    = 50;
$_obPage       = max(1, (int)($_GET['page'] ?? 1));
$_obOffset     = ($_obPage - 1) * $_obPerPage;
$_obTotal      = Outbound::countAll(null, $_obOdFilter ?: null);
$_obTotalPages = max(1, (int)ceil($_obTotal / $_obPerPage));
$_obPage       = min($_obPage, $_obTotalPages);
$outboundList  = Outbound::getAll(null, $_obPerPage, $_obOffset, $_obOdFilter ?: null);
$customers     = Customer::getAll();
$products      = Product::getAll();

$itemPickLocations = [];
$db = db();
foreach ($outboundItems as $oi) {
    
    $locs = Outbound::getItemPickedLocations($oi['id']);
    if (!empty($locs)) {
        $itemPickLocations[$oi['id']] = $locs;
        continue;
    }

    
    
    $orderQty = floatval($oi['actual_qty'] ?: $oi['quantity']);
    $savedLoc = $oi['location'] ?? null;  

    if ($oi['product_id'] && $orderQty > 0) {
        
        if ($savedLoc && strpos($savedLoc, ',') === false) {
            
            $stmt = $db->prepare("
                SELECT sl.location_code, sl.quantity AS picked_qty,
                       sl.pallet_seq, sl.is_full_pallet, sl.uom
                FROM stock_locations sl
                JOIN stock s ON sl.stock_id = s.id
                WHERE s.product_id = ?
                  AND s.location = ?
                  AND s.quantity > 0
                  AND sl.status IN ('Available','Reserved')
                ORDER BY sl.pallet_seq
                LIMIT 50
            ");
            $stmt->execute([$oi['product_id'], $savedLoc]);
            $allSl = $stmt->fetchAll();
        } elseif ($savedLoc && strpos($savedLoc, ',') !== false) {
            
            $locs = array_map('trim', explode(',', $savedLoc));
            $placeholders = implode(',', array_fill(0, count($locs), '?'));
            $params = array_merge([$oi['product_id']], $locs);
            $stmt = $db->prepare("
                SELECT sl.location_code, sl.quantity AS picked_qty,
                       sl.pallet_seq, sl.is_full_pallet, sl.uom
                FROM stock_locations sl
                JOIN stock s ON sl.stock_id = s.id
                WHERE s.product_id = ?
                  AND s.location IN ($placeholders)
                  AND s.quantity > 0
                  AND sl.status IN ('Available','Reserved')
                ORDER BY s.location, sl.pallet_seq
                LIMIT 50
            ");
            $stmt->execute($params);
            $allSl = $stmt->fetchAll();
        } else {
            
            $fefoAlloc = Outbound::getFEFOAllocation($oi['product_id'], $orderQty);
            $allocLocs = array_unique(array_column($fefoAlloc['allocation'], 'location'));
            if (empty($allocLocs)) { continue; }
            $placeholders = implode(',', array_fill(0, count($allocLocs), '?'));
            $params = array_merge([$oi['product_id']], $allocLocs);
            $stmt = $db->prepare("
                SELECT sl.location_code, sl.quantity AS picked_qty,
                       sl.pallet_seq, sl.is_full_pallet, sl.uom
                FROM stock_locations sl
                JOIN stock s ON sl.stock_id = s.id
                WHERE s.product_id = ?
                  AND s.location IN ($placeholders)
                  AND s.quantity > 0
                  AND sl.status IN ('Available','Reserved')
                ORDER BY s.location, sl.pallet_seq
                LIMIT 50
            ");
            $stmt->execute($params);
            $allSl = $stmt->fetchAll();
        }

        
        if (!empty($allSl)) {
            $uomPerPallet = max(1, intval($oi['uom_per_pallet'] ?? 4));

            $neededPlt  = (int)ceil($orderQty / $uomPerPallet);
            $limitedSl  = [];
            $totalPlt   = 0;
            foreach ($allSl as $sl) {
                if ($totalPlt >= $neededPlt) break;
                $limitedSl[] = $sl;
                $totalPlt++;
            }
            $itemPickLocations[$oi['id']] = !empty($limitedSl) ? $limitedSl : $allSl;
        }
    }
}

$outboundDestinations = [];
$itemsByDest = []; 
if ($outbound) {
    $dstStmt = $db->prepare("SELECT * FROM outbound_destinations WHERE outbound_id = ? ORDER BY seq");
    $dstStmt->execute([$outbound['id']]);
    $outboundDestinations = $dstStmt->fetchAll();

    
    foreach ($outboundItems as $obItem) {
        $dId = isset($obItem['destination_id']) && $obItem['destination_id'] ? (int)$obItem['destination_id'] : 0;
        $itemsByDest[$dId][] = $obItem;
    }
}

$outboundActivityLog = [];
if ($outbound) {
    $outboundActivityLog = ActivityLogger::getForReference('Outbound', (int)$outbound['id']);
}

$stockByProduct = [];
if ($outbound && in_array($outbound['status'] ?? '', ['Open'])) {
    
    $sfStmt = $db->prepare("
        SELECT s.id AS stock_id, s.product_id, s.location, s.batch_number,
               s.quantity AS total_qty, s.uom,
               COALESCE(
                 (SELECT ii_exp.exp_date
                  FROM stock_locations sl_exp
                  JOIN inbound_items ii_exp ON ii_exp.id = sl_exp.inbound_item_id
                  WHERE sl_exp.stock_id = s.id
                  ORDER BY ii_exp.id DESC
                  LIMIT 1),
                 s.expiry_date
               ) AS expiry_date
        FROM stock s
        WHERE (s.stock_status IN ('Available','Dues In') OR s.stock_status IS NULL OR s.stock_status = '')
          AND s.quantity > 0
          AND (s.location IS NULL OR s.location NOT IN ('QUA_SHELL','STAGING'))
        ORDER BY s.product_id, s.location,
            CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END, expiry_date ASC
    ");
    $sfStmt->execute();
    $allStockRows = $sfStmt->fetchAll();

    if ($allStockRows) {
        
        $stockIds   = array_column($allStockRows, 'stock_id');
        $holders    = implode(',', array_fill(0, count($stockIds), '?'));
        $palStmt    = $db->prepare("
            SELECT stock_id, id AS sl_id, pallet_seq, quantity AS pallet_qty, is_full_pallet
            FROM stock_locations
            WHERE stock_id IN ($holders) AND status = 'Available' AND quantity > 0
            ORDER BY pallet_seq ASC
        ");
        $palStmt->execute($stockIds);
        $palletsByStock = [];
        foreach ($palStmt->fetchAll() as $p) {
            $palletsByStock[$p['stock_id']][] = $p;
        }

        foreach ($allStockRows as $s) {
            $pallets = $palletsByStock[$s['stock_id']] ?? [];
            if (empty($pallets)) {
                
                $stockByProduct[$s['product_id']][] = [
                    'product_id'  => $s['product_id'],
                    'location'    => $s['location'],
                    'batch_number'=> $s['batch_number'],
                    'quantity'    => $s['total_qty'],
                    'uom'         => $s['uom'],
                    'expiry_date' => $s['expiry_date'],
                    'pallet_seq'  => null,
                    'is_full'     => null,
                ];
            } else {
                
                foreach ($pallets as $pal) {
                    $stockByProduct[$s['product_id']][] = [
                        'product_id'  => $s['product_id'],
                        'location'    => $s['location'],
                        'batch_number'=> $s['batch_number'],
                        'quantity'    => $pal['pallet_qty'],
                        'uom'         => $s['uom'],
                        'expiry_date' => $s['expiry_date'],
                        'pallet_seq'  => $pal['pallet_seq'],
                        'is_full'     => $pal['is_full_pallet'],
                    ];
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
:root{
  --ob-primary:#026766;--ob-accent:#026766;--ob-teal:#026766;--ob-orange:#f57c00;
  --ob-border:#b2e5e5;--ob-bg:#e6f7f7;--ob-card:#fff;--ob-muted:#78909c;
  --ob-r:10px;--ob-sh:0 1px 4px rgba(92,26,142,.06),0 4px 14px rgba(92,26,142,.05);
}
.ob-page{font-family:'Inter','Segoe UI',system-ui,sans-serif;background:var(--ob-bg);color:#013d3c}
.ob-hero{background:linear-gradient(135deg,#013d3c 0%,#026766 55%,#013d3c 100%);
  border-radius:var(--ob-r);padding:22px 28px;color:#fff;display:flex;align-items:center;
  justify-content:space-between;gap:16px;box-shadow:0 4px 20px rgba(2,103,102,.28);flex-wrap:wrap}
.ob-hero-title{font-size:1.4rem;font-weight:700;letter-spacing:-.3px}
.ob-hero-sub{font-size:.8rem;opacity:.75;margin-top:3px}
.ob-stat{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:8px;
  padding:9px 15px;text-align:center;min-width:72px}
.ob-stat .n{font-size:1.3rem;font-weight:700;line-height:1}
.ob-stat .l{font-size:.67rem;opacity:.72;margin-top:2px;text-transform:uppercase;letter-spacing:.04em}
.ob-card{background:#fff;border-radius:var(--ob-r);border:1px solid var(--ob-border);
  box-shadow:var(--ob-sh);overflow:hidden;margin-bottom:16px}
.ob-ch{padding:13px 20px;border-bottom:1px solid var(--ob-border);display:flex;
  align-items:center;justify-content:space-between;background:#f0fbfb}
.ob-ch h2{font-size:.92rem;font-weight:700;color:var(--ob-primary);margin:0}
.ob-cb{padding:20px}
.ob-sec{background:#f0fbfb;border:1px solid var(--ob-border);border-radius:8px;padding:16px 18px;margin-bottom:14px}
.ob-sec-title{font-size:.7rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
  color:var(--ob-primary);margin-bottom:12px;display:flex;align-items:center;gap:6px;
  padding-bottom:9px;border-bottom:1px solid var(--ob-border)}
.ob-lbl{display:block;font-size:.75rem;font-weight:600;color:#455a64;margin-bottom:4px;letter-spacing:.01em}
.ob-lbl .r{color:#014f4e;margin-left:2px}
.ob-inp,.ob-sel,.ob-ta{width:100%;padding:8px 12px;border:1.5px solid #80d2d2;border-radius:7px;
  font-size:.875rem;color:#013d3c;background:#fff;transition:border-color .15s,box-shadow .15s;
  outline:none;box-sizing:border-box}
.ob-inp:focus,.ob-sel:focus,.ob-ta:focus{border-color:var(--ob-accent);box-shadow:0 0 0 3px rgba(123,31,162,.1)}
.ob-inp::placeholder{color:#80b2b2}
.ob-hint{font-size:.71rem;color:var(--ob-muted);margin-top:3px}
.ob-ico{position:relative}.ob-ico .ob-inp{padding-left:32px}
.ob-ico i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#80d2d2;font-size:.77rem}
.ob-ro{background:#e6f7f7;color:#013d3c;font-weight:600;cursor:default}
.ob-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:7px;font-size:.84rem;
  font-weight:600;cursor:pointer;border:none;transition:.15s;text-decoration:none;white-space:nowrap}
.ob-bp{background:var(--ob-accent);color:#fff}.ob-bp:hover{background:#026766}
.ob-ba{background:#026766;color:#fff}.ob-ba:hover{background:#013d3c}
.ob-bt{background:var(--ob-teal);color:#fff}.ob-bt:hover{background:#00796b}
.ob-bo{background:var(--ob-orange);color:#fff}.ob-bo:hover{background:#e65100}
.ob-bg{background:#e0f7f7;color:#013d3c}.ob-bg:hover{background:#b2e5e5}
.ob-bd{background:#e0f7f7;color:#014f4e}.ob-bd:hover{background:#b2e5e5}
.ob-sm{padding:5px 11px;font-size:.77rem}
.ob-out{background:transparent;border:1.5px solid rgba(255,255,255,.7);color:#fff}
.ob-out:hover{background:rgba(255,255,255,.15)}
.ob-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.69rem;font-weight:700;letter-spacing:.03em}
.ob-open{background:#e0f7f7;color:#013d3c;border:1px solid #bbdefb}
.ob-picking{background:#fff8e1;color:#e65100;border:1px solid #ffecb3}
.ob-picked{background:#e0f7f7;color:#013d3c;border:1px solid #b2e5e5}
.ob-shipped{background:#fff3e0;color:#014f4e;border:1px solid #fff3e0}
.ob-completed{background:#e0f7f7;color:#013d3c;border:1px solid #b2e5e5}
.ob-cancelled{background:#e0f7f7;color:#013d3c;border:1px solid #e0f7f7}
.ob-tbl{width:100%;border-collapse:collapse;font-size:.858rem}
.ob-tbl thead tr{background:#e6f7f7}
.ob-tbl th{padding:9px 12px;text-align:left;font-size:.68rem;font-weight:700;letter-spacing:.06em;
  text-transform:uppercase;color:var(--ob-muted);border-bottom:2px solid var(--ob-border);white-space:nowrap}
.ob-tbl td{padding:9px 12px;border-bottom:1px solid #e6f7f7;color:#013d3c;vertical-align:top}
.ob-tbl tbody tr:hover{background:#f0fbfb}
.ob-tbl tbody tr:last-child td{border-bottom:none}
.ob-uom{display:inline-block;padding:2px 7px;border-radius:4px;font-size:.67rem;font-weight:700}
.uom-drum{background:#e0f7f7;color:#013d3c}.uom-carton{background:#e0f7f7;color:#013d3c}
.uom-pail{background:#fff8e1;color:#e65100}.uom-ea{background:#e0f7f7;color:#013d3c}
.uom-bags{background:#e0f7f7;color:#013d3c}
.ob-fefo{background:#e6f7f7;border:1.5px solid #80d2d2;border-radius:8px;padding:11px 14px;margin-top:10px}
.ob-fefo-row{display:flex;justify-content:space-between;align-items:center;padding:5px 9px;
  background:#fff;border-radius:6px;margin-bottom:4px;border:1px solid #80d2d2;font-size:.79rem}
.ob-ig{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:13px}
.ob-ib .k{font-size:.68rem;color:var(--ob-muted);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px}
.ob-ib .v{font-size:.875rem;color:#013d3c;font-weight:600}
.ob-wf{display:flex;overflow:hidden;border-radius:6px;border:1px solid var(--ob-border)}
.ob-ws{flex:1;text-align:center;padding:8px 3px;font-size:.66rem;font-weight:700;letter-spacing:.05em;
  text-transform:uppercase;background:#e6f7f7;color:#80d2d2;position:relative}
.ob-ws::after{content:'';position:absolute;right:-8px;top:0;bottom:0;width:0;
  border-left:8px solid #e6f7f7;border-top:18px solid transparent;border-bottom:18px solid transparent;z-index:1}
.ob-ws:last-child::after{display:none}
.ob-wa{background:var(--ob-accent);color:#fff}.ob-wa::after{border-left-color:var(--ob-accent)}
.ob-wd{background:var(--ob-teal);color:#fff}.ob-wd::after{border-left-color:var(--ob-teal)}
.ob-alert{padding:10px 14px;border-radius:7px;font-size:.858rem;display:flex;align-items:center;gap:9px}
.ob-ok{background:#e0f7f7;border-left:4px solid var(--ob-teal);color:#013d3c}
.ob-err{background:#e0f7f7;border-left:4px solid #026766;color:#014f4e}
.ob-info{background:#e0f7f7;border-left:4px solid var(--ob-accent);color:#283593}
.ob-warn{background:#fff8e1;border-left:4px solid var(--ob-orange);color:#e65100}
.ob-empty{padding:44px 16px;text-align:center;color:var(--ob-muted)}
.ob-empty i{font-size:2.2rem;opacity:.28;margin-bottom:10px;display:block}
.stock-bin-chip{transition:.12s}
.stock-bin-chip:hover{background:#b2e5e5!important;border-color:#81c784!important}
</style>

<?php
function obBadge($s){
  $m=['Open'=>'ob-open','Picking'=>'ob-picking','Shipped'=>'ob-shipped','Completed'=>'ob-completed','Cancelled'=>'ob-cancelled','Delivered'=>'ob-completed','Picked'=>'ob-picking'];
  return '<span class="ob-badge '.($m[$s]??'ob-open').'">'.htmlspecialchars($s).'</span>';
}
function obUom($u){
  $ul = strtolower($u ?? '');
  if (strpos($ul,'drum') !== false)   $c = 'drum';
  elseif (strpos($ul,'carton') !== false) $c = 'carton';
  elseif (strpos($ul,'pail') !== false)   $c = 'pail';
  else $c = 'ea';
  return '<span class="ob-uom uom-'.$c.'">'.htmlspecialchars(strtoupper($u??'-')).'</span>';
}
function obCalcPallet(array $item): int {
  $qty = floatval($item['actual_qty'] ?? $item['quantity'] ?? 0);
  $upp = max(1, intval($item['uom_per_pallet'] ?? 4));
  return (int)ceil($qty / $upp);
}
function obWorkflow($s){
  $steps=['Open','Picking','Shipped','Completed'];
  $idx=array_search($s,$steps);
  echo '<div class="ob-wf">';
  foreach($steps as $i=>$st){
    $cls=$i<$idx?'ob-wd':($i===$idx?'ob-wa':'');
    echo "<div class=\"ob-ws $cls\">$st</div>";
  }
  echo '</div>';
}
?>

<div class="ob-page space-y-4">

<div class="ob-hero">
  <div>
    <div class="ob-hero-title"><i class="fas fa-shipping-fast mr-2" style="opacity:.85"></i>Outbound Management</div>
  </div>
  <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap">
    <?php
    $stats=Outbound::getStats();
    foreach([['Total Orders',$_obTotal],['Pending',$stats['pending']??0],['This Month',$stats['this_month']??0]] as [$l,$v]):?>
    <div class="ob-stat"><div class="n"><?=$v?></div><div class="l"><?=$l?></div></div>
    <?php endforeach;?>

  </div>
</div>

<?php if(isset($_GET['success'])):?>
<div class="ob-alert ob-ok"><i class="fas fa-check-circle"></i>
  <?=['created'=>'Outbound order created.','updated'=>'Order updated.','completed'=>'Order completed — stock deducted.','deleted'=>'Order deleted.','item_added'=>'Item added (FEFO applied).','item_deleted'=>'Item removed.','picked'=>'Items picked.','shipped'=>'Order marked as shipped.'][$_GET['success']]??'Success.'?>
</div>
<?php endif;?>
<?php
$_obWarnings = $_SESSION['ob_warnings'] ?? [];
unset($_SESSION['ob_warnings']);
if (!empty($_obWarnings)):
?>
<div class="ob-alert" style="background:#fff8e1;border-left:4px solid #f59e0b;color:#92400e">
  <i class="fas fa-exclamation-triangle" style="color:#f59e0b"></i>
  <strong>Item dilewati (tidak ada stok):</strong>
  <ul style="margin:4px 0 0 18px;font-size:.85rem">
    <?php foreach($_obWarnings as $w): ?>
    <li><?= htmlspecialchars($w) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php if(isset($error)):?>
<div class="ob-alert ob-err"><i class="fas fa-exclamation-circle"></i><?=htmlspecialchars($error)?></div>
<?php endif;?>
<?php if(isset($errorMsg)):?>
<div class="ob-alert ob-err"><i class="fas fa-exclamation-circle"></i><?=htmlspecialchars($errorMsg)?></div>
<?php endif;?>
<?php if(($_GET['error']??'')==='inbound_not_atp'):?>
<div class="ob-alert ob-err"><i class="fas fa-exclamation-circle"></i>
  Tidak bisa ubah ke ATP — barang belum tersedia di stock. Selesaikan proses inbound (putaway) terlebih dahulu agar barang masuk ke stock.
</div>
<?php endif;?>

<?php if($action==='list'):?>

<div class="ob-card">
  <div class="ob-ch">
    <h2><i class="fas fa-list-ul mr-2"></i>All Outbound Orders</h2>
    <div style="display:flex;gap:7px;align-items:center">
      <a href="import_outbound.php" class="ob-btn ob-sm" style="background:#026766;color:#fff;border:none"><i class="fas fa-file-import"></i> Import</a>
      <a href="<?= '?' . http_build_query(array_merge($_GET, ['export' => '1'])) ?>" class="ob-btn ob-sm" style="background:#013d3c;color:#fff;border:none"><i class="fas fa-download"></i> Export Excel</a>
      <?php if($canWrite): ?><a href="?action=create" class="ob-btn ob-bp ob-sm"><i class="fas fa-plus"></i> New Outbound</a><?php endif; ?>
    </div>
  </div>
  
  <div style="padding:14px 20px;border-bottom:1px solid #e6f7f7;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;min-width:220px">
      <input type="hidden" name="action" value="list">
      <div style="position:relative;min-width:180px">
          <i class="fas fa-hashtag" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#026766;font-size:.85rem"></i>
          <input type="text" name="od_no" value="<?= htmlspecialchars($_GET['od_no'] ?? '') ?>"
                 placeholder="Cari OD No..."
                 style="width:100%;padding:8px 12px 8px 32px;border:2px solid #026766;border-radius:8px;font-size:.85rem;outline:none;font-family:monospace">
      </div>
      <button type="submit" style="padding:8px 14px;background:#026766;color:#fff;border:none;border-radius:8px;font-size:.85rem;cursor:pointer;white-space:nowrap"><i class="fas fa-search mr-1"></i>Cari</button>
      <?php if(!empty($_GET['od_no'])): ?>
      <a href="?action=list" style="padding:8px 12px;background:#e6f7f7;color:#64748b;border-radius:8px;font-size:.82rem;text-decoration:none"><i class="fas fa-times"></i></a>
      <?php endif; ?>
      </form>
      <div style="position:relative;flex:1;min-width:200px">
          <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#90a4ae;font-size:.85rem"></i>
          <input type="text" id="obSearch" placeholder="Cari order no, customer, shipment, tujuan..."
                 oninput="filterObTable()"
                 style="width:100%;padding:8px 12px 8px 32px;border:1px solid #cce8e8;border-radius:8px;font-size:.85rem;outline:none">
      </div>
      <select id="obStatusFilter" onchange="filterObTable()"
              style="padding:8px 12px;border:1px solid #cce8e8;border-radius:8px;font-size:.85rem;color:#546e7a;background:#fff">
          <option value="">Semua Status</option>
          <option value="Open">Open</option>
          <option value="Picking">Picking</option>
          <option value="Shipped">Shipped</option>
          <option value="Completed">Completed</option>
      </select>
      <select id="obMonthFilter" onchange="filterObTable()"
              style="padding:8px 12px;border:1px solid #cce8e8;border-radius:8px;font-size:.85rem;color:#546e7a;background:#fff">
          <option value="">Semua Bulan</option>
          <?php
          $months = ['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mei','06'=>'Jun',
                     '07'=>'Jul','08'=>'Ags','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Des'];
          $currentYear = date('Y');
          for($y = $currentYear; $y >= $currentYear-1; $y--) {
              foreach($months as $mn => $ml) {
                  echo "<option value='{$y}-{$mn}'>{$ml} {$y}</option>";
              }
          }
          ?>
      </select>
      <span id="obCount" style="font-size:.8rem;color:#90a4ae;white-space:nowrap"></span>
  </div>
  <div style="overflow-x:auto">
  <table class="ob-tbl" id="obTable">
    <thead><tr>
      <th>Order No.</th><th>Shipment No.</th><th>Date</th><th>Destination</th>
      <th style="text-align:center">Items</th><th style="text-align:right">Pallets</th>
      <th>Status</th><th style="text-align:center">Actions</th>
    </tr></thead>
    <tbody id="obTbody">
    <?php if(empty($outboundList)):?>
    <tr><td colspan="8"><div class="ob-empty"><div><i class="fas fa-shipping-fast"></i></div><div>No outbound orders yet.</div>
      <a href="?action=create" class="ob-btn ob-bp ob-sm" style="margin-top:12px">Create First Order</a></div></td></tr>
    <?php else: foreach($outboundList as $it):?>
    <tr data-order="<?=strtolower($it['order_number']??$it['outbound_number']??'')?>" data-customer="<?=strtolower($it['customer_name']??$it['ship_to_name']??'')?>" data-shipment="<?=strtolower($it['shipment_number']??'')?>" data-dest="<?=strtolower($it['ship_to_location']??$it['kota']??'')?>" data-od="<?=strtolower($it['od_numbers']??'')?>" data-status="<?=$it['status']?>" data-date="<?=substr($it['order_date'],0,7)?>">
      <td><a href="?action=view&id=<?=$it['id']?>" style="font-weight:600;color:var(--ob-primary);text-decoration:none">
        <?=htmlspecialchars($it['shipment_number'] ?? $it['order_number'] ?? $it['outbound_number'] ?? '-')?>
      </a>
      <?php if(!empty($it['order_number']) && $it['order_number'] !== ($it['shipment_number']??'')):?>
      <div style="font-size:.68rem;color:#90a4ae;font-family:monospace"><?=htmlspecialchars($it['order_number'])?></div>
      <?php endif;?>
      <?php if(!empty($it['od_numbers'])):?>
      <div style="font-size:.7rem;color:#026766;font-family:monospace;font-weight:600">OD: <?=htmlspecialchars($it['od_numbers'])?></div>
      <?php endif;?>
      </td>
      <td style="font-family:monospace;font-size:.83rem;color:#013d3c"><?=htmlspecialchars($it['shipment_number']??'—')?></td>
      <td style="color:#546e7a"><?=date('d M Y',strtotime($it['order_date']))?></td>
      <td style="font-size:.83rem;color:#546e7a"><?=htmlspecialchars($it['ship_to_location']??$it['kota']??$it['destination']??'—')?></td>
      <td style="text-align:center;font-weight:600"><?=(int)($it['total_items']??0)?></td>
      <td style="text-align:right;font-weight:600"><?=(int)ceil($it['total_pallet']??0)?></td>
      <td><?=obBadge($it['status'])?></td>
      <td style="text-align:center">
        <a href="?action=view&id=<?=$it['id']?>" class="ob-btn ob-bg ob-sm" style="margin-right:3px"><i class="fas fa-eye"></i></a>
        <?php if($canWrite && !in_array($it['status']??'', ['Completed','Cancelled','Shipped','Delivered'])): ?><button onclick="confirmDelete(<?=$it['id']?>,'<?=htmlspecialchars($it['order_number']??$it['outbound_number']??'-')?>')"
                data-ob-del-id="<?=$it['id']?>" data-status="<?=htmlspecialchars($it['status']??'')?>"
                class="ob-btn ob-bd ob-sm"><i class="fas fa-trash"></i></button><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; endif;?>
    </tbody>
  </table>
  </div>
  <?php if ($_obTotalPages > 1): ?>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-top:1px solid #e6f7f7;flex-wrap:wrap;gap:8px">
      <span style="font-size:.82rem;color:#90a4ae">
          Showing <?= number_format($_obOffset + 1) ?>–<?= number_format(min($_obOffset + $_obPerPage, $_obTotal)) ?> of <?= number_format($_obTotal) ?> orders
      </span>
      <div style="display:flex;gap:4px;align-items:center">
          <?php if ($_obPage > 1): ?>
          <a href="?action=list&od_no=<?= urlencode($_obOdFilter) ?>&page=<?= $_obPage - 1 ?>"
             style="padding:6px 12px;background:#e6f7f7;color:#026766;border-radius:6px;text-decoration:none;font-size:.82rem">&laquo; Prev</a>
          <?php endif; ?>
          <?php for ($p = max(1, $_obPage-2); $p <= min($_obTotalPages, $_obPage+2); $p++): ?>
          <a href="?action=list&od_no=<?= urlencode($_obOdFilter) ?>&page=<?= $p ?>"
             style="padding:6px 10px;background:<?= $p===$_obPage?'#026766':'#e6f7f7' ?>;color:<?= $p===$_obPage?'#fff':'#026766' ?>;border-radius:6px;text-decoration:none;font-size:.82rem;font-weight:<?= $p===$_obPage?'700':'400' ?>">
              <?= $p ?>
          </a>
          <?php endfor; ?>
          <?php if ($_obPage < $_obTotalPages): ?>
          <a href="?action=list&od_no=<?= urlencode($_obOdFilter) ?>&page=<?= $_obPage + 1 ?>"
             style="padding:6px 12px;background:#e6f7f7;color:#026766;border-radius:6px;text-decoration:none;font-size:.82rem">Next &raquo;</a>
          <?php endif; ?>
      </div>
  </div>
  <?php endif; ?>
</div>

<?php elseif($action==='create'):?>

<div class="ob-card">
  <div class="ob-ch">
    <h2><i class="fas fa-plus-circle mr-2"></i>Create New Outbound Order</h2>
    <a href="?action=list" class="ob-btn ob-bg ob-sm"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
  <div class="ob-cb">

  <div style="background:#e6f7f7;border:1px solid #80d2d2;border-radius:8px;padding:10px 15px;margin-bottom:18px;font-size:.83rem;color:#013d3c;display:flex;gap:8px;align-items:center">
    <i class="fas fa-clock" style="color:#026766"></i>
    <span><strong>FEFO Auto-applied:</strong> Items will be allocated from nearest expiry first when added.</span>
  </div>

  <form method="POST" class="space-y-4">

    
    <div class="ob-sec">
      <div class="ob-sec-title"><i class="fas fa-file-alt"></i> Order Information</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div>
          <label class="ob-lbl">Order Date <span class="r">*</span></label>
          <input type="date" name="order_date" required class="ob-inp" value="<?=date('Y-m-d')?>">
        </div>
        <div>
          <label class="ob-lbl">Status</label>
          <input type="hidden" name="status" value="Open">
          <div class="ob-inp" style="background:#f0fbfb;color:#026766;font-weight:700;display:flex;align-items:center;gap:7px;cursor:default">
            <i class="fas fa-circle" style="font-size:.55rem;color:#16a34a"></i> Open
          </div>
        </div>
      </div>
    </div>

    
    <div class="ob-sec">
      <div class="ob-sec-title"><i class="fas fa-hashtag"></i> Order References</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        
        <div style="grid-column:span 2">
          <label class="ob-lbl">Shipment Number <span style="color:#026766;font-size:.78rem;font-weight:600">(digunakan sebagai nomor order)</span></label>
          <div class="ob-ico"><i class="fas fa-barcode"></i>
            <input type="text" name="shipment_number" id="shipment_number" class="ob-inp"
                   placeholder="e.g. 109294012" oninput="lookupShipment(this.value)"
                   style="font-family:monospace;font-weight:600" required>
          </div>
          <div id="shipment_lookup_result" style="margin-top:5px;font-size:.8rem;color:#026766;display:none">
            <i class="fas fa-check-circle"></i> <span id="shipment_found_text"></span>
          </div>
        </div>
        
        <input type="hidden" name="customer_id" value="">
      </div>
    </div>

    
    <div class="ob-sec">
      <div class="ob-sec-title"><i class="fas fa-truck"></i> Shipment Information</div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px">
        <div>
          <label class="ob-lbl">Expected Delivery Date <span style="color:#ef4444">*</span></label>
          <input type="date" name="expected_date" class="ob-inp" required>
        </div>
        <div>
          <label class="ob-lbl">Armada / Vehicle No</label>
          <div class="ob-ico"><i class="fas fa-truck"></i>
            <input type="text" name="armada_no" class="ob-inp" placeholder="e.g. B 1234 XYZ">
          </div>
        </div>
        <div>
          <label class="ob-lbl">Container No</label>
          <div class="ob-ico"><i class="fas fa-box"></i>
            <input type="text" name="container_no" class="ob-inp" placeholder="Container number">
          </div>
        </div>
        <div>
          <label class="ob-lbl">Vehicle Type</label>
          <select name="jenis_armada" class="ob-sel">
            <option value="">— Select —</option>
            <option value="Truck">Truck</option>
            <option value="Container">Container</option>
            <option value="Van">Van</option>
            <option value="Pickup">Pickup</option>
          </select>
        </div>
      </div>
    </div>

    
    <div class="ob-sec">
      <div class="ob-sec-title"><i class="fas fa-sticky-note"></i> Notes</div>
      <textarea name="notes" rows="3" class="ob-ta" placeholder="Additional notes or instructions..."></textarea>
    </div>

    <div style="display:flex;gap:12px">
      <button type="submit" name="create_outbound" class="ob-btn ob-ba" style="flex:1;justify-content:center;padding:11px">
        <i class="fas fa-save"></i> Save Outbound Order
      </button>
      <a href="?action=list" class="ob-btn ob-bg" style="flex:1;justify-content:center;padding:11px">
        <i class="fas fa-times"></i> Cancel
      </a>
    </div>
  </form>
  </div>
</div>

<?php elseif($action==='view'&&$outbound):?>

<?php
$cdStmt = db()->prepare("SELECT COUNT(*) FROM inbound_items ii
        WHERE ii.cross_dock_outbound_order_id = ?");
$cdStmt->execute([$outbound['id']]);
$cdCount = (int)$cdStmt->fetchColumn();
$cdPicklists = db()->prepare("SELECT id, picklist_no, status FROM picklists
        WHERE notes = 'CROSS-DOCK' AND outbound_order_id = ?");
$cdPicklists->execute([$outbound['id']]);
$cdPlList = $cdPicklists->fetchAll();
$waveLink = db()->prepare("SELECT id, wave_no, status FROM waves w
        JOIN wave_orders wo ON wo.wave_id = w.id WHERE wo.outbound_order_id = ?");
$waveLink->execute([$outbound['id']]);
$waveRow = $waveLink->fetch();
?>

<div class="ob-card">
  <div class="ob-cb" style="padding:14px 22px"><?php obWorkflow($outbound['status']);?></div>
</div>

<div class="ob-card">
  <div class="ob-cb" style="padding:18px 22px">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">
      <div>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:5px">
          <span style="font-size:1.25rem;font-weight:700;color:var(--ob-primary)">
            <?=htmlspecialchars($outbound['order_number']??$outbound['outbound_number']??'-')?>
          </span>
          <?=obBadge($outbound['status'])?>
          <?php if($waveRow):?>
          <a href="waves.php?action=detail&id=<?=(int)$waveRow['id']?>"
             style="display:inline-flex;align-items:center;gap:5px;background:#f3e8ff;color:#7e22ce;
                    border:1px solid #d8b4fe;border-radius:6px;padding:2px 9px;font-size:.68rem;
                    font-weight:700;text-decoration:none">
            <i class="fas fa-layer-group" style="font-size:.62rem"></i> WAVE <?=htmlspecialchars($waveRow['wave_no'])?>
          </a>
          <?php endif;?>
          <?php if($cdCount > 0):?>
          <span style="display:inline-flex;align-items:center;gap:5px;background:#eef2ff;color:#4338ca;
                       border:1px solid #c7d2fe;border-radius:6px;padding:2px 9px;font-size:.68rem;font-weight:700">
            <i class="fas fa-arrows-split-up-and-left" style="font-size:.62rem"></i>
            CROSS-DOCK ×<?=$cdCount?>
          </span>
          <?php endif;?>
        </div>
        <div style="font-size:.83rem;color:#607d8b">
          <i class="fas fa-calendar mr-1"></i><?=date('d F Y',strtotime($outbound['order_date']))?>
          <?php if($outbound['shipment_number']??''):?>
          <span style="margin:0 7px;opacity:.4">|</span>
          <i class="fas fa-barcode mr-1"></i><strong><?=htmlspecialchars($outbound['shipment_number'])?></strong>
          <?php endif;?>
        </div>
      </div>
      <div style="display:flex;gap:7px;flex-wrap:wrap">
        <?php if($outbound['status']==='Open' && $canWrite):?>
        <?php
        
        $unservCount = 0;
        $pickableCount = 0;
        foreach ($outboundItems as $obIt) {
            $ips = $obIt['in_process_status'] ?? '';
            if ($ips === 'Unserviceable') $unservCount++;
            elseif ($ips === 'ATP') $pickableCount++;
        }
        ?>
        <?php if($unservCount > 0):?>
        <div style="background:#fff3e0;border:1px solid #ffcc80;border-radius:8px;
                    padding:6px 12px;font-size:.78rem;color:#e65100;font-weight:600">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            <?=$unservCount?> item Unserviceable — selesaikan dulu sebelum pick
        </div>
        <?php endif;?>
        <?php if($pickableCount > 0):?>
        <?php if($unservCount > 0):?>
        <button type="button" class="ob-btn ob-bp" disabled
                style="opacity:.45;cursor:not-allowed"
                title="Tidak bisa pick: ada <?=$unservCount?> item Unserviceable. Ubah status item tersebut terlebih dahulu.">
            <i class="fas fa-ban mr-1"></i> Pick Items
        </button>
        <?php else:?>
        <form method="POST" onsubmit="return confirmPick()">
          <input type="hidden" name="id" value="<?=$outbound['id']?>">
          <button type="submit" name="pick_items" class="ob-btn ob-bp"><i class="fas fa-boxes"></i> Pick Items</button>
        </form>
        <?php endif;?>
        <?php endif;?>
        <?php endif;?>
        <?php if($outbound['status']==='Picking' && $canWrite):?>
        <form method="POST" onsubmit="return confirm('Mark as shipped?')">
          <input type="hidden" name="id" value="<?=$outbound['id']?>">
          <button type="submit" name="ship_outbound" class="ob-btn ob-bo"><i class="fas fa-truck"></i> Mark Shipped</button>
        </form>
        <?php endif;?>
        <?php if($outbound['status']==='Shipped' && $canWrite):?>
        <form method="POST" onsubmit="return confirm('Complete this order?')">
          <input type="hidden" name="id" value="<?=$outbound['id']?>">
          <button type="submit" name="complete_outbound" class="ob-btn ob-bt"><i class="fas fa-check-double"></i> Complete</button>
        </form>
        <?php endif;?>
        <?php if(in_array($outbound['status'],['Completed','Shipped','Delivered'])):?>
        <a href="surat_jalan.php?id=<?=$outbound['id']?>" target="_blank"
           class="ob-btn" style="background:#013d3c;color:#fff">
          <i class="fas fa-file-alt"></i> Surat Jalan
        </a>
        <?php endif;?>
        <a href="picklist_pdf.php?outbound_id=<?=$outbound['id']?>" target="_blank"
           class="ob-btn" style="background:#013d3c;color:#fff">
          <i class="fas fa-clipboard-list"></i> Picklist
        </a>
        <?php if(!empty($cdPlList)):?>
        <div style="width:100%;display:flex;gap:7px;flex-wrap:wrap;align-items:center">
          <span style="font-size:.7rem;font-weight:700;color:#4338ca;display:inline-flex;align-items:center;gap:5px">
            <i class="fas fa-arrows-split-up-and-left"></i> Cross-dock picklists:
          </span>
          <?php foreach($cdPlList as $cdp):?>
          <a href="picklist.php?action=view&id=<?=(int)$cdp['id']?>" class="ob-btn" style="background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe">
            <?=htmlspecialchars($cdp['picklist_no'])?>
          </a>
          <?php endforeach;?>
        </div>
        <?php endif;?>
        <?php if($canWrite && !in_array($outbound['status']??'', ['Completed','Cancelled','Shipped','Delivered'])): ?><button onclick="confirmDelete(<?=$outbound['id']?>,'<?=htmlspecialchars($outbound['order_number']??$outbound['outbound_number']??'-')?>')"
                data-ob-del-id="<?=$outbound['id']?>" data-status="<?=htmlspecialchars($outbound['status']??'')?>"
                class="ob-btn ob-bd"><i class="fas fa-trash"></i> Delete</button>
        <?php endif; ?>
        <a href="?action=list" class="ob-btn ob-bg"><i class="fas fa-arrow-left"></i> Back</a>
      </div>
    </div>
  </div>
</div>

<div class="ob-card">
  <div class="ob-ch" style="display:flex;align-items:center;justify-content:space-between">
    <h2><i class="fas fa-info-circle mr-2"></i>Order Details</h2>
    <?php if($canWrite && !in_array($outbound['status']??'', ['Completed','Cancelled','Shipped','Delivered'])):?>
    <button type="button" onclick="toggleEditOutbound()"
            id="editOutboundBtn" class="ob-btn ob-bg ob-sm">
      <i class="fas fa-edit"></i> Edit
    </button>
    <?php endif;?>
  </div>

  
  <div class="ob-cb" id="outboundViewMode">
    <div class="ob-ig">
      <?php if($outbound['shipment_number']??''):?>
      <div class="ob-ib" style="grid-column:span 2;background:#e0f7f7;border-radius:6px;padding:10px 14px">
        <div class="k"><i class="fas fa-barcode mr-1" style="color:#026766"></i>Shipment Number</div>
        <div class="v" style="font-family:monospace;font-size:1rem;color:#013d3c"><?=htmlspecialchars($outbound['shipment_number'])?></div>
      </div>
      <?php endif;?>
      <div class="ob-ib"><div class="k">Vehicle Type</div><div class="v"><?=htmlspecialchars($outbound['jenis_armada']??'—')?></div></div>
      <div class="ob-ib"><div class="k">Armada No</div><div class="v"><?=htmlspecialchars($outbound['armada_no']??'—')?></div></div>
      <div class="ob-ib"><div class="k">Container No</div><div class="v"><?=htmlspecialchars($outbound['container_no']??'—')?></div></div>
      <div class="ob-ib"><div class="k">Expected Date</div><div class="v"><?=$outbound['expected_date']?date('d M Y',strtotime($outbound['expected_date'])):'—'?></div></div>
      <div class="ob-ib"><div class="k">Total Items</div><div class="v" style="color:var(--ob-primary)"><?=count($outboundItems)?> lines</div></div>
      <div class="ob-ib"><div class="k">Total Pallets</div><div class="v" style="color:var(--ob-teal)"><?=array_sum(array_map('obCalcPallet', $outboundItems))?> plt</div></div>
    </div>
  </div>

  
  <?php if($canWrite && !in_array($outbound['status']??'', ['Completed','Cancelled','Shipped','Delivered'])):?>
  <div class="ob-cb" id="outboundEditMode" style="display:none;border-top:2px solid var(--ob-accent)">
    <form method="POST">
      <input type="hidden" name="id" value="<?=$outbound['id']?>">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:16px">
        <div>
          <label class="ob-lbl">Order Date</label>
          <input type="date" name="order_date" class="ob-inp"
                 value="<?=htmlspecialchars($outbound['order_date']??'')?>" required>
        </div>
        
        <input type="hidden" name="customer_id" value="">
        <div>
          <label class="ob-lbl">Shipment Number</label>
          <input type="text" name="shipment_number" class="ob-inp"
                 value="<?=htmlspecialchars($outbound['shipment_number']??'')?>">
        </div>
        <div>
          <label class="ob-lbl">Armada No</label>
          <input type="text" name="armada_no" class="ob-inp"
                 value="<?=htmlspecialchars($outbound['armada_no']??'')?>"
                 placeholder="e.g. B 1234 XYZ">
        </div>
        <div>
          <label class="ob-lbl">Jenis Armada</label>
          <select name="jenis_armada" class="ob-sel">
            <?php foreach(['','Tronton','Engkel','CDD','Pickup','Container 20ft','Container 40ft'] as $ja):?>
            <option value="<?=$ja?>" <?=$outbound['jenis_armada']===$ja?'selected':''?>><?=$ja?:'-Pilih-'?></option>
            <?php endforeach;?>
          </select>
        </div>
        <div>
          <label class="ob-lbl">Container No</label>
          <input type="text" name="container_no" class="ob-inp"
                 value="<?=htmlspecialchars($outbound['container_no']??'')?>">
        </div>
        <div>
          <label class="ob-lbl">Expected Date <span style="color:#ef4444">*</span></label>
          <input type="date" name="expected_date" id="editExpectedDate" class="ob-inp"
                 value="<?=htmlspecialchars($outbound['expected_date']??'')?>" required>
        </div>
        <input type="hidden" name="status" value="<?=htmlspecialchars($outbound['status']??'Open')?>">
        <div style="grid-column:span 2">
          <label class="ob-lbl">Notes</label>
          <input type="text" name="notes" class="ob-inp"
                 value="<?=htmlspecialchars($outbound['notes']??'')?>">
        </div>
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" name="update_outbound" class="ob-btn ob-bp">
          <i class="fas fa-save"></i> Simpan
        </button>
        <button type="button" onclick="toggleEditOutbound()" class="ob-btn ob-bg">Batal</button>
      </div>
    </form>
  </div>
  <?php endif;?>
</div>

<?php if($outbound['status']==='Open' && $canWrite):?>
<div class="ob-card">
  <div class="ob-ch">
    <h2><i class="fas fa-plus-circle mr-2"></i>Add Item</h2>
    <span style="font-size:.76rem;color:#026766;font-weight:600"><i class="fas fa-clock mr-1"></i>FEFO auto-applied</span>
  </div>
  <div class="ob-cb">
  <form method="POST" class="space-y-3">
    <input type="hidden" name="outbound_id" value="<?=$outbound['id']?>">
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:13px">
      <div style="position:relative">
        <label class="ob-lbl">Product <span class="r">*</span></label>
        <input type="hidden" name="product_id" id="productId" required>
        <div style="position:relative">
            <input type="text" id="productSearch"
                   class="ob-inp" placeholder="Ketik kode atau nama produk..."
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
        <div id="fefoInfo" style="margin-top:4px;font-size:.78rem;color:#026766"></div>
      </div>
      <div>
        <label class="ob-lbl">Quantity <span class="r">*</span></label>
        <input type="number" name="quantity" required min="1" step="0.01" id="quantityInput"
               class="ob-inp" placeholder="0" onchange="calculatePallet();checkFEFOStock()">
      </div>
      <div>
        <label class="ob-lbl">UOM</label>
        <select name="uom" id="uomSelect" class="ob-sel">
          <option value="Drum">Drum (4/plt)</option>
          <option value="Carton">Carton (36/44/48 per plt)</option>
          <option value="Pail">Pail (24/plt)</option>
          <option value="EA">EA (4/plt)</option>
          <option value="Bags">Bags (1/plt)</option>
        </select>
      </div>
      <div>
        <label class="ob-lbl">Pallets <small style="font-weight:400;color:#80b2b2">(auto)</small></label>
        <input type="text" id="palletDisplay" readonly class="ob-inp ob-ro" placeholder="—">
        <input type="hidden" name="pallet" id="palletInput" value="0">
      </div>
    </div>

    
    <div style="background:#f0fbfb;border:1px solid #b2e5e5;border-radius:8px;padding:12px 14px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
        <span style="font-size:.74rem;font-weight:700;color:var(--ob-primary);text-transform:uppercase;letter-spacing:.04em">
          <i class="fas fa-map-marker-alt mr-1"></i> Lokasi / Bin
        </span>
        <div style="display:flex;gap:8px;align-items:center">
          <label style="font-size:.75rem;color:#546e7a;cursor:pointer;display:flex;align-items:center;gap:4px">
            <input type="radio" name="loc_mode" value="auto" checked onchange="toggleLocMode(this.value)">
            <i class="fas fa-magic" style="color:#026766"></i> Otomatis (FEFO)
          </label>
          <label style="font-size:.75rem;color:#546e7a;cursor:pointer;display:flex;align-items:center;gap:4px">
            <input type="radio" name="loc_mode" value="manual" onchange="toggleLocMode(this.value)">
            <i class="fas fa-hand-pointer" style="color:#0277bd"></i> Pilih Manual
          </label>
        </div>
      </div>

      
      <div id="autoLocPanel">
        <div id="autoAllocPanel" style="font-size:.78rem;color:#546e7a">
          <i class="fas fa-info-circle"></i> Pilih produk &amp; qty untuk melihat alokasi FEFO per bin
        </div>
      </div>

      
      <div id="manualLocPanel" style="display:none">
        <input type="hidden" name="manual_locs" id="manualLocsJson" value="[]">
        <input type="hidden" name="manual_location" id="manualLocValue">
        <div id="manualBinBuilder" style="display:flex;flex-direction:column;gap:8px"></div>
        <button type="button" onclick="addManualBinRow()" id="addBinRowBtn" style="display:none;
                margin-top:8px;padding:5px 12px;border:1.5px dashed #026766;border-radius:6px;
                background:#e0f7f7;color:#026766;font-size:.78rem;font-weight:600;cursor:pointer">
          <i class="fas fa-plus"></i> Tambah Bin Lain
        </button>
        <div id="manualTotalCheck" style="margin-top:8px;font-size:.78rem;font-weight:600;display:none"></div>
      </div>
    </div>

    
    <?php
    
    $destMapJs = [];
    foreach ($outboundDestinations as $dd) {
        $destMapJs[] = [
            'id'       => (int)$dd['id'],
            'name'     => $dd['ship_to_name'] ?? '',
            'location' => $dd['ship_to_location'] ?? $dd['kota'] ?? '',
            'street'   => $dd['ship_to_street'] ?? '',
            'kota'     => $dd['kota'] ?? '',
        ];
    }
    $customerMapJs = [];
    foreach ($customers as $cu) {
        $customerMapJs[] = [
            'id' => (int)($cu['id'] ?? 0),
            'code' => $cu['customer_code'] ?? '',
            'name' => $cu['customer_name'] ?? '',
            'address' => $cu['address'] ?? '',
            'city' => $cu['city'] ?? '',
        ];
    }
    ?>
    <div style="background:#e6f4f4;border:1.5px solid #80cece;border-radius:10px;padding:13px 15px;margin-top:4px">
      <div style="font-size:.74rem;font-weight:700;color:#013d3c;margin-bottom:10px;text-transform:uppercase;letter-spacing:.04em">
        <i class="fas fa-map-marker-alt mr-1"></i> Ship-to Party &amp; Order Reference
      </div>
      <div style="display:grid;grid-template-columns:2fr 1.2fr 1.2fr 1fr 1fr;gap:12px;align-items:end">
        <div>
          <label class="ob-lbl">Ship-to Party <small style="font-weight:400;color:#026766">(nama tujuan pengiriman)</small></label>
          <div class="ob-ico"><i class="fas fa-building"></i>
            <input type="text" name="item_ship_to_name" id="itemShipToName" class="ob-inp"
                   list="destNameList" placeholder="Nama tujuan / ship-to party..."
                   oninput="onShipToNameInput(this.value)">
          </div>
          <datalist id="destNameList">
            <?php foreach ($outboundDestinations as $dd): ?>
            <option value="<?= htmlspecialchars($dd['ship_to_name'] ?? '') ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label class="ob-lbl">Location / Kota</label>
          <div class="ob-ico"><i class="fas fa-map-marker-alt"></i>
            <input type="text" name="item_ship_to_location" id="itemShipToLocation" class="ob-inp" placeholder="Kota...">
          </div>
        </div>
        <div>
          <label class="ob-lbl">Customer <span class="r">*</span></label>
          <select name="item_customer_id" id="itemCustomerId" class="ob-sel" required onchange="onCustomerChange(this.value)">
            <option value="">— Pilih —</option>
            <?php foreach($customers as $cu): ?>
            <option value="<?=$cu['id']?>">
              <?=htmlspecialchars('ID ' . ($cu['id'] ?? '-') . ' | ' . ($cu['customer_code'] ?? '-') . ' | ' . ($cu['customer_name'] ?? ''))?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="ob-lbl">OD No. <small style="font-weight:400;color:#026766">(per item)</small></label>
          <input type="text" name="od_number" class="ob-inp" placeholder="e.g. 530870992" style="font-family:monospace">
        </div>
        <div>
          <label class="ob-lbl">SO No. <small style="font-weight:400;color:#026766">(per item)</small></label>
          <input type="text" name="so_number" class="ob-inp" placeholder="e.g. 4549106526" style="font-family:monospace">
        </div>
      </div>
      <div style="margin-top:10px;display:grid;grid-template-columns:2fr 1fr 2fr auto;gap:12px;align-items:end">
        <div>
          <label class="ob-lbl">Street / Alamat <small style="font-weight:400;color:#6b7280">(opsional)</small></label>
          <div class="ob-ico"><i class="fas fa-road"></i>
            <input type="text" name="item_ship_to_street" id="itemShipToStreet" class="ob-inp" placeholder="Alamat lengkap...">
          </div>
        </div>
        <div>
          <label class="ob-lbl">Notes</label>
          <input type="text" name="notes" class="ob-inp" placeholder="Optional...">
        </div>
        <?php if (!empty($outboundDestinations)): ?>
        <div>
          <label class="ob-lbl">Atau pilih tujuan yang sudah ada:</label>
          <select name="destination_id" id="destIdSelect" class="ob-sel" onchange="onDestSelectChange(this.value)">
            <option value="0">— Ketik nama baru di atas, atau pilih tujuan yang sudah ada —</option>
            <?php foreach ($outboundDestinations as $dd): ?>
            <option value="<?= $dd['id'] ?>"><?= htmlspecialchars(($dd['ship_to_name'] ?? '—') . ($dd['kota'] ? ' ('.$dd['kota'].')' : '')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <div></div>
        <?php endif; ?>
        <button type="submit" name="add_item" class="ob-btn ob-ba" style="padding:10px 26px;white-space:nowrap">
          <i class="fas fa-plus"></i> Add Item
        </button>
      </div>
    </div>
    <script>
    const _destMap = <?= json_encode($destMapJs, JSON_HEX_TAG|JSON_UNESCAPED_UNICODE) ?>;
    const _customerMap = <?= json_encode($customerMapJs, JSON_HEX_TAG|JSON_UNESCAPED_UNICODE) ?>;
    function onShipToNameInput(val) {
        
        const match = _destMap.find(d => d.name.toLowerCase() === val.toLowerCase());
        if (match) {
            document.getElementById('itemShipToLocation').value = match.location || match.kota || '';
            document.getElementById('itemShipToStreet').value   = match.street || '';
            const sel = document.getElementById('destIdSelect');
            if (sel) sel.value = match.id;
        } else {
            const sel = document.getElementById('destIdSelect');
            if (sel) sel.value = 0;
        }
    }
    function onDestSelectChange(val) {
        if (!val || val == 0) return;
        const match = _destMap.find(d => d.id == val);
        if (match) {
            document.getElementById('itemShipToName').value     = match.name;
            document.getElementById('itemShipToLocation').value = match.location || match.kota || '';
            document.getElementById('itemShipToStreet').value   = match.street || '';
        }
    }
    function onCustomerChange(val) {
        if (!val) return;
        const match = _customerMap.find(c => String(c.id) === String(val));
        if (!match) return;
        const locEl = document.getElementById('itemShipToLocation');
        const strEl = document.getElementById('itemShipToStreet');
        const destSel = document.getElementById('destIdSelect');
        
        if (destSel && destSel.value && destSel.value !== '0') return;
        if (locEl && !locEl.value.trim()) locEl.value = match.city || '';
        if (strEl && !strEl.value.trim()) strEl.value = match.address || '';
    }
    </script>

    
    <div id="fefoPreview" style="display:none" class="ob-fefo">
      <div style="font-size:.74rem;font-weight:700;color:#013d3c;margin-bottom:7px;letter-spacing:.05em;text-transform:uppercase">
        <i class="fas fa-clock mr-1"></i>FEFO Allocation Preview
      </div>
      <div id="fefoDetails"></div>
    </div>
  </form>
  </div>
</div>
<?php endif;?>

<div class="ob-card">
  <div class="ob-ch">
    <h2><i class="fas fa-boxes mr-2"></i>Items (<?=count($outboundItems)?>)</h2>
    <div style="font-size:.8rem;color:var(--ob-muted)">
      Qty: <strong><?=number_format(array_sum(array_column($outboundItems,'quantity')))?></strong>
      &nbsp;|&nbsp; Pallets: <strong><?=array_sum(array_map('obCalcPallet', $outboundItems))?></strong>
    </div>
  </div>
  <div style="overflow-x:auto">
  <?php if(empty($outboundItems)):?>
  <div class="ob-empty"><div><i class="fas fa-inbox"></i></div><div>No items yet — add an item above.</div></div>
  <?php else:?>
  <table class="ob-tbl">
    <thead><tr>
      <th>#</th><th>OD No.</th><th>SO No.</th><th>Product</th><th>Batch (FEFO)</th><th>Location</th>
      <th>Ship-to Party</th><th>Customer</th><th>Street / Address</th>
      <th style="text-align:center">UOM</th><th style="text-align:right">Qty</th>
      <th style="text-align:right">Pallets</th><th>Expiry</th>
      <th style="text-align:center;white-space:nowrap">Process Status</th>
      <?php if($outbound['status']==='Open' && $canWrite):?><th style="text-align:center">Del</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach($outboundItems as $i=>$it):
      $dispBatch  = $it['batch_number'] ?? $it['batch_no'] ?? '—';
      $dispLoc    = $it['location'] ?? '—';
      $dispExpiry = $it['exp_date'] ?? $it['expiry_date'] ?? null;
      $ne         = $dispExpiry && strtotime($dispExpiry) < strtotime('+90 days');
      $dispPlt    = obCalcPallet($it);
      $pickLocs   = $itemPickLocations[$it['id']] ?? [];
      $isPicked   = in_array($outbound['status'] ?? '', ['Picking','Shipped','Completed']);
    ?><tr>
      <td style="color:#80b2b2;font-size:.75rem"><?=$i+1?></td>
      <td>
        <?php if(!empty($it['od_number'])):?>
        <span style="display:inline-block;background:#e0f7f7;color:#026766;border:1px solid #80d2d2;
                     border-radius:5px;padding:2px 7px;font-family:monospace;font-size:.78rem;font-weight:700">
          <?=htmlspecialchars($it['od_number'])?>
        </span>
        <?php else:?><span style="color:#b2e5e5;font-size:.75rem">—</span><?php endif;?>
      </td>
      <td>
        <?php if(!empty($it['so_number'])):?>
        <span style="display:inline-block;background:#e0f7f7;color:#026766;border:1px solid #b2e5e5;
                     border-radius:5px;padding:2px 7px;font-family:monospace;font-size:.78rem;font-weight:700">
          <?=htmlspecialchars($it['so_number'])?>
        </span>
        <?php else:?><span style="color:#b2e5e5;font-size:.75rem">—</span><?php endif;?>
      </td>
      <td>
        <div style="font-weight:600;color:#013d3c"><?=htmlspecialchars($it['product_name']??'-')?></div>
        <div style="font-size:.72rem;color:#90a4ae;font-family:monospace"><?=htmlspecialchars($it['product_code']??'')?></div>
      </td>
      <td>
        <span style="font-family:monospace;font-size:.8rem"><?=htmlspecialchars($dispBatch)?></span>
        <?php if($dispBatch!=='—'):?><span style="font-size:.65rem;color:#026766;margin-left:4px;font-weight:700">FEFO</span><?php endif;?>
      </td>
      <td>
        <?php if(!empty($pickLocs)):
          $locCount = count($pickLocs);
          $locKey   = 'ob_loc_'.$it['id'];
          $accentColor = $isPicked ? '#026766' : '#014f4e';
          $bgColor     = $isPicked ? '#e0f7f7'  : '#e0f7f7';

          
          $uomPerPlt = max(1, intval($it['uom_per_pallet'] ?? 4));
          $totalPickPlt = obCalcPallet($it);

          
          $uniqLocs = [];
          foreach ($pickLocs as $pl) {
              $lc = $pl['location_code'] ?? '';
              if ($lc && !in_array($lc, $uniqLocs)) $uniqLocs[] = $lc;
          }
          $locSummaryDisplay = count($uniqLocs) === 1 ? $uniqLocs[0] : count($uniqLocs).' lokasi';
        ?>
        <details style="cursor:pointer">
          <summary style="list-style:none;cursor:pointer;user-select:none">
            <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap">
              <span style="font-family:monospace;font-weight:700;font-size:.82rem;
                           color:<?=$accentColor?>"><?=htmlspecialchars($locSummaryDisplay)?></span>
              <?php if($totalPickPlt > 0):?>
              <span style="background:<?=$bgColor?>;color:<?=$accentColor?>;border-radius:12px;
                           padding:1px 7px;font-size:.68rem;font-weight:700;white-space:nowrap">
                <?=$totalPickPlt?> plt
              </span>
              <?php endif;?>
              <?php if($locCount > 1):?>
              <i class="fas fa-chevron-down" style="font-size:.6rem;color:<?=$accentColor?>"></i>
              <?php endif;?>
            </div>
          </summary>
          <?php if($locCount >= 1):?>
          <div style="margin-top:5px;background:#f0fbfb;border-radius:6px;padding:6px 8px;
                      border:1px solid #cce8e8;min-width:220px">
            <?php foreach($pickLocs as $pi=>$pl):
              $plQty    = floatval($pl['picked_qty'] ?? $pl['quantity'] ?? 0);
              $plPlt    = $plQty > 0 ? (int)ceil($plQty / $uomPerPlt) : 1;
              $isFull   = isset($pl['is_full_pallet']) ? (bool)$pl['is_full_pallet'] : ($plQty >= $uomPerPlt);
              $plSeq    = $pl['pallet_seq'] ?? ($pi + 1);
              $plLoc    = $pl['location_code'] ?? $pl['location'] ?? '—';
            ?>
            <div style="font-size:.75rem;padding:4px 0;border-bottom:1px solid #e6f7f7;
                        display:flex;align-items:center;gap:6px">
              <span style="color:<?=$accentColor?>;font-weight:700;font-size:.72rem;
                           min-width:26px">P<?=$plSeq?></span>
              <span style="font-family:monospace;font-weight:600;color:#013d3c">
                <?=htmlspecialchars($plLoc)?>
              </span>
              <span style="color:#546e7a;margin-left:auto;white-space:nowrap">
                <?=number_format($plQty,0)?> <?=htmlspecialchars($it['uom']??'Drum')?>
                <span style="background:<?=$isFull?'#e0f7f7':'#fff3e0'?>;color:<?=$isFull?'#026766':'#e65100'?>;
                             font-size:.65rem;margin-left:3px;padding:0 4px;border-radius:8px;font-weight:700">
                  <?=$isFull?'full':'partial'?>
                </span>
              </span>
            </div>
            <?php endforeach;?>
          </div>
          <?php endif;?>
        </details>
        <?php elseif($dispLoc !== '—' && $dispLoc !== null && $dispLoc !== ''):
          
          $savedPlt   = (int)ceil(floatval($it['pallet'] ?? 0));
          $locParts   = array_map('trim', explode(',', $dispLoc));
        ?>
        <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap">
          <?php foreach($locParts as $lpi => $lp):?>
          <span style="font-family:monospace;font-weight:700;font-size:.82rem;color:#014f4e">
            <?=htmlspecialchars($lp)?>
          </span>
          <?php if($lpi < count($locParts)-1):?><span style="color:#80b2b2;font-size:.7rem">+</span><?php endif;?>
          <?php endforeach;?>
          <?php if($savedPlt > 0):?>
          <span style="background:#e0f7f7;color:#014f4e;border-radius:12px;
                       padding:1px 7px;font-size:.68rem;font-weight:700;white-space:nowrap">
            <?=$savedPlt?> plt
          </span>
          <?php endif;?>
        </div>
        <?php else:?>
        <span style="font-family:monospace;font-size:.8rem;color:#80b2b2">—</span>
        <?php endif;?>
      </td>
      <?php
        $itShipTo   = $it['item_ship_to_name']     ?? '';
        $itShipLoc  = $it['item_ship_to_location']  ?? $it['item_ship_to_kota'] ?? '';
        $itShipStr  = $it['item_ship_to_street']    ?? '';
        $itCustomer = $it['order_customer_name'] ?? '';
        $itCustomerCode = $it['order_customer_code'] ?? '';
        $itCustomerId = $it['customer_id'] ?? '';
        $itCustomerDisplay = trim(
          ($itCustomerId !== '' ? ('ID '.$itCustomerId.' | ') : '') .
          ($itCustomerCode !== '' ? ($itCustomerCode.' | ') : '') .
          $itCustomer,
          ' |'
        );
      ?>
      <td>
        <?php if($itShipTo): ?>
        <div style="font-weight:600;font-size:.82rem;color:#013d3c"><?=htmlspecialchars($itShipTo)?></div>
        <?php if($itShipLoc): ?><div style="font-size:.72rem;color:#78909c"><?=htmlspecialchars($itShipLoc)?></div><?php endif; ?>
        <?php else: ?><span style="color:#b2e5e5;font-size:.75rem">—</span><?php endif; ?>
      </td>
      <td>
        <?php if($itCustomer): ?>
        <span style="background:#e0f7f7;color:#026766;border:1px solid #80d2d2;
                     border-radius:5px;padding:2px 7px;font-size:.78rem;font-weight:600">
          <?=htmlspecialchars($itCustomerDisplay)?>
        </span>
        <?php else: ?><span style="color:#b2e5e5;font-size:.75rem">—</span><?php endif; ?>
      </td>
      <td style="font-size:.78rem;color:#546e7a;max-width:160px">
        <?=htmlspecialchars($itShipStr ?: '—')?>
      </td>
      <td style="text-align:center"><?=obUom($it['uom']??'Drum')?></td>
      <td style="text-align:right;font-weight:600"><?=number_format($it['quantity']??0)?></td>
      <td style="text-align:right"><?=$dispPlt?></td>
      <td style="font-size:.8rem<?=$ne?';color:#014f4e;font-weight:600':''?>">
        <?php if($ne):?><i class="fas fa-exclamation-triangle" style="font-size:.68rem;margin-right:2px"></i><?php endif;?>
        <?=$dispExpiry ? date('d M Y',strtotime($dispExpiry)) : '—'?>
      </td>
      <?php
        $obIps = $it['in_process_status'] ?? 'Goods Received';
        $obIpMap = [
            'Goods Received' => ['bg'=>'#e0f7f7','color'=>'#1e3a8a','icon'=>'📥'],
            'ATP'            => ['bg'=>'#e0f7f7','color'=>'#013d3c','icon'=>'🟢'],
            'Unserviceable'  => ['bg'=>'#e0f7f7','color'=>'#013d3c','icon'=>'⛔'],
        ];
        $obIpStyles = [
            'ATP'            => ['bg'=>'#dcfce7','color'=>'#166534','icon'=>'🟢'],
            'Goods Received' => ['bg'=>'#fef9c3','color'=>'#854d0e','icon'=>'📥'],
            'Unserviceable'  => ['bg'=>'#fee2e2','color'=>'#b91c1c','icon'=>'⛔'],
        ];
        $obIpc = $obIpStyles[$obIps] ?? ['bg'=>'#f3f4f6','color'=>'#374151','icon'=>'?'];
        $obTitle = $obIps === 'Goods Received' ? 'Barang belum masuk stock — tidak bisa di-pick' : '';
      ?>
      <td style="text-align:center">
        <span style="display:inline-block;border-radius:20px;padding:3px 10px;
                     font-size:.72rem;font-weight:700;white-space:nowrap;
                     background:<?=$obIpc['bg']?>;color:<?=$obIpc['color']?>"
              title="<?=htmlspecialchars($obTitle)?>">
          <?=$obIpc['icon']?> <?=htmlspecialchars($obIps)?>
        </span>
      </td>
      <?php if($outbound['status']==='Open' && $canWrite):?>
      <td style="text-align:center">
        <form method="POST" style="display:inline">
          <input type="hidden" name="item_id" value="<?=$it['id']?>">
          <input type="hidden" name="outbound_id" value="<?=$outbound['id']?>">
          <button type="submit" name="delete_item" onclick="return confirm('Remove this item?')"
                  class="ob-btn ob-bd ob-sm" style="padding:3px 8px"><i class="fas fa-times"></i></button>
        </form>
      </td>
      <?php endif;?>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  <?php endif;?>
  </div>
</div>

<?php 
if (!empty($outboundDestinations) || !empty($itemsByDest[0])):

function obDestItemsTable(array $items): void {
    if (empty($items)) {
        echo '<p style="font-size:.78rem;color:#90a4ae;font-style:italic;padding:8px 0">— Belum ada item untuk tujuan ini —</p>';
        return;
    }
    $totalQ = 0; $totalP = 0;
    echo '<table style="width:100%;border-collapse:collapse;font-size:.78rem;margin-top:8px">';
    echo '<thead><tr style="background:rgba(0,105,92,.08)">';
    echo '<th style="padding:5px 8px;text-align:left;font-weight:700;color:#026766;font-size:.7rem;letter-spacing:.03em;text-transform:uppercase">OD No.</th>';
    echo '<th style="padding:5px 8px;text-align:left;font-weight:700;color:#026766;font-size:.7rem;letter-spacing:.03em;text-transform:uppercase">SO No.</th>';
    echo '<th style="padding:5px 8px;text-align:left;font-weight:700;color:#026766;font-size:.7rem;letter-spacing:.03em;text-transform:uppercase">Product</th>';
    echo '<th style="padding:5px 8px;text-align:left;font-weight:700;color:#026766;font-size:.7rem;letter-spacing:.03em;text-transform:uppercase">Batch</th>';
    echo '<th style="padding:5px 8px;text-align:right;font-weight:700;color:#026766;font-size:.7rem;letter-spacing:.03em;text-transform:uppercase">Qty</th>';
    echo '<th style="padding:5px 8px;text-align:center;font-weight:700;color:#026766;font-size:.7rem;letter-spacing:.03em;text-transform:uppercase">UOM</th>';
    echo '<th style="padding:5px 8px;text-align:right;font-weight:700;color:#026766;font-size:.7rem;letter-spacing:.03em;text-transform:uppercase">Plt</th>';
    echo '</tr></thead><tbody>';
    foreach ($items as $i => $it) {
        $bg = $i % 2 === 0 ? '#fff' : '#e6f7f7';
        $qty = floatval($it['actual_qty'] ?? $it['quantity'] ?? 0);
        $plt = obCalcPallet($it);
        $totalQ += $qty; $totalP += $plt;
        $dispBatch = $it['batch_number'] ?? $it['batch_no'] ?? '—';
        echo "<tr style=\"background:{$bg}\">";
        echo '<td style="padding:5px 8px;border-bottom:1px solid #e0f2f1">';
        if (!empty($it['od_number'])) {
            echo '<span style="background:#e0f7f7;color:#026766;border-radius:4px;padding:1px 6px;font-family:monospace;font-size:.75rem;font-weight:700">'.htmlspecialchars($it['od_number']).'</span>';
        } else echo '<span style="color:#b2e5e5;font-size:.72rem">—</span>';
        echo '</td>';
        echo '<td style="padding:5px 8px;border-bottom:1px solid #e0f2f1">';
        if (!empty($it['so_number'])) {
            echo '<span style="background:#e0f7f7;color:#026766;border-radius:4px;padding:1px 6px;font-family:monospace;font-size:.75rem;font-weight:700">'.htmlspecialchars($it['so_number']).'</span>';
        } else echo '<span style="color:#b2e5e5;font-size:.72rem">—</span>';
        echo '</td>';
        echo '<td style="padding:5px 8px;border-bottom:1px solid #e0f2f1">';
        echo '<div style="font-weight:600;color:#1e293b;font-size:.8rem">'.htmlspecialchars($it['product_name'] ?? '—').'</div>';
        echo '<div style="font-size:.68rem;color:#94a3b8;font-family:monospace">'.htmlspecialchars($it['product_code'] ?? '').'</div>';
        echo '</td>';
        echo '<td style="padding:5px 8px;border-bottom:1px solid #e0f2f1;font-family:monospace;font-size:.75rem">'.htmlspecialchars($dispBatch).'</td>';
        echo '<td style="padding:5px 8px;border-bottom:1px solid #e0f2f1;text-align:right;font-weight:600">'.number_format($qty, 0).'</td>';
        echo '<td style="padding:5px 8px;border-bottom:1px solid #e0f2f1;text-align:center">'.htmlspecialchars($it['uom'] ?? '—').'</td>';
        echo '<td style="padding:5px 8px;border-bottom:1px solid #e0f2f1;text-align:right;font-weight:600">'.$plt.'</td>';
        echo '</tr>';
    }
    
    echo '<tr style="background:rgba(0,105,92,.06);font-weight:700">';
    echo '<td colspan="4" style="padding:5px 8px;color:#026766;font-size:.78rem">Total</td>';
    echo '<td style="padding:5px 8px;text-align:right;color:#026766">'.number_format($totalQ, 0).'</td>';
    echo '<td></td>';
    echo '<td style="padding:5px 8px;text-align:right;color:#026766">'.$totalP.'</td>';
    echo '</tr>';
    echo '</tbody></table>';
}
?>
<div class="ob-card">
  <div class="ob-ch">
    <h2><i class="fas fa-map-signs mr-2"></i>Tujuan Pengiriman & Produk</h2>
    <?php
      $hasPrimaryDest = !empty($itemsByDest[0]);
      $destCount = count($outboundDestinations) + ($hasPrimaryDest ? 1 : 0);
    ?>
    <span style="font-size:.76rem;color:var(--ob-muted)"><?= $destCount ?> tujuan</span>
  </div>
  <div style="padding:14px 20px;display:flex;flex-direction:column;gap:14px">

    
    <?php if ($hasPrimaryDest): ?>
    <div style="border:1px solid #b2e5e5;border-radius:10px;overflow:hidden">
      <div style="background:linear-gradient(90deg,#e0f2f1,#e6f7f7);padding:10px 16px;display:flex;align-items:flex-start;gap:10px">
        <span style="background:#026766;color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex-shrink:0;margin-top:1px">1</span>
        <div style="flex:1">
          <div style="font-weight:700;font-size:.9rem;color:#013d3c">
            <?= !empty($outbound['ship_to_name']) ? htmlspecialchars($outbound['ship_to_name']) : 'Tujuan Utama' ?>
          </div>
          <?php if (!empty($outbound['ship_to_location']) || !empty($outbound['kota'])): ?>
          <div style="font-size:.78rem;color:#607d8b;margin-top:1px">
            <i class="fas fa-map-marker-alt" style="color:#026766;margin-right:3px"></i>
            <?= htmlspecialchars(($outbound['ship_to_location'] ?? '') . (!empty($outbound['kota']) ? ' — ' . $outbound['kota'] : '')) ?>
          </div>
          <?php endif; ?>
        </div>
        <span style="font-size:.68rem;color:#026766;font-weight:700;background:#b2e5e5;padding:2px 8px;border-radius:10px">Tujuan Utama</span>
      </div>
      <div style="padding:10px 16px;background:#fff">
        <?php obDestItemsTable($itemsByDest[0] ?? []); ?>
      </div>
    </div>
    <?php endif; ?>

    
    <?php foreach($outboundDestinations as $di=>$dst):
      $labelNo = ($hasPrimaryDest ? 2 : 1) + $di;
      $dstItems = $itemsByDest[$dst['id']] ?? [];
    ?>
    <div style="border:1px solid #b2e5e5;border-radius:10px;overflow:hidden">
      <div style="background:linear-gradient(90deg,#e0f7f7,#e6f7f7);padding:10px 16px;display:flex;align-items:flex-start;gap:10px">
        <span style="background:#026766;color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex-shrink:0;margin-top:1px"><?=$labelNo?></span>
        <div style="flex:1">
          <div style="font-weight:700;font-size:.9rem;color:#013d3c"><?=htmlspecialchars($dst['ship_to_name']??'—')?></div>
          <?php if($dst['ship_to_location']??$dst['kota']??''):?>
          <div style="font-size:.78rem;color:#607d8b;margin-top:1px">
            <i class="fas fa-map-marker-alt" style="color:#026766;margin-right:3px"></i>
            <?=htmlspecialchars(($dst['ship_to_location']??'').($dst['kota']?' — '.$dst['kota']:''))?>
          </div>
          <?php endif;?>
          <?php if($dst['ship_to_street']??''):?>
          <div style="font-size:.75rem;color:#90a4ae;margin-top:1px"><?=htmlspecialchars($dst['ship_to_street'])?></div>
          <?php endif;?>
          <?php if($dst['notes']??''):?>
          <div style="font-size:.74rem;color:#78909c;margin-top:2px;font-style:italic"><?=htmlspecialchars($dst['notes'])?></div>
          <?php endif;?>
        </div>
        <span style="font-size:.68rem;color:#026766;font-weight:700;background:#b2e5e5;padding:2px 8px;border-radius:10px">Tujuan <?=$labelNo?></span>
      </div>
      <div style="padding:10px 16px;background:#fff">
        <?php obDestItemsTable($dstItems); ?>
      </div>
    </div>
    <?php endforeach;?>

  </div>
</div>
<?php endif;?>

<?php 
if (!empty($outboundActivityLog)): ?>
<div class="ob-card">
  <div class="ob-ch">
    <h2><i class="fas fa-history mr-2"></i>Riwayat Aktivitas</h2>
    <span style="font-size:.76rem;color:var(--ob-muted)"><?=count($outboundActivityLog)?> entri</span>
  </div>
  <div style="overflow-x:auto">
  <table class="ob-tbl">
    <thead><tr>
      <th style="white-space:nowrap">Waktu</th>
      <th>Admin / User</th>
      <th>Aksi</th>
      <th>Keterangan</th>
    </tr></thead>
    <tbody>
    <?php foreach($outboundActivityLog as $lg):?>
    <tr>
      <td style="font-size:.75rem;color:#90a4ae;white-space:nowrap">
        <?=date('d M Y H:i',strtotime($lg['created_at']))?>
      </td>
      <td>
        <div style="font-weight:600;font-size:.83rem"><?=htmlspecialchars($lg['full_name']??$lg['username']??'—')?></div>
        <?php if($lg['user_role']??''):?>
        <div style="font-size:.68rem;color:#90a4ae"><?=$lg['user_role']?></div>
        <?php endif;?>
      </td>
      <td>
        <span style="font-size:.75rem;font-weight:700;color:var(--ob-primary)">
          <?=ActivityLogger::actionLabel($lg['action']??'')?>
        </span>
      </td>
      <td style="font-size:.8rem;color:#546e7a"><?=htmlspecialchars($lg['description']??'')?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  </div>
</div>
<?php endif;?>

<?php endif;?>
</div>

<script>
function handleObStatusChange(sel) {
    var val = sel.value;
    if (val === 'Unserviceable') {
        sel.style.background = '#e0f7f7';
        sel.style.color      = '#013d3c';
        var t = document.createElement('div');
        t.textContent = '⛔ Unserviceable → QUA_SHELL';
        t.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#013d3c;color:#fff;'
                        + 'padding:10px 16px;border-radius:8px;font-weight:700;z-index:99999;font-size:.82rem';
        document.body.appendChild(t);
        setTimeout(function(){ if(document.body.contains(t)) document.body.removeChild(t); }, 2500);
    } else if (val === 'ATP') {
        sel.style.background = '#e0f7f7';
        sel.style.color      = '#013d3c';
    }
    sel.closest('form').submit();
}

function confirmPick() {
    return confirm('Pick items for this order?');
}

function toggleEditOutbound() {
    var view = document.getElementById('outboundViewMode');
    var edit = document.getElementById('outboundEditMode');
    var btn  = document.getElementById('editOutboundBtn');
    if (!view || !edit) return;
    if (edit.style.display === 'none') {
        view.style.display = 'none';
        edit.style.display = 'block';
        if (btn) btn.innerHTML = '<i class="fas fa-times"></i> Batal';
    } else {
        view.style.display = 'block';
        edit.style.display = 'none';
        if (btn) btn.innerHTML = '<i class="fas fa-edit"></i> Edit';
    }
}
function filterObTable() {
    const q  = document.getElementById('obSearch').value.toLowerCase();
    const st = document.getElementById('obStatusFilter').value;
    const mo = document.getElementById('obMonthFilter').value;
    const rows = document.querySelectorAll('#obTbody tr[data-order]');
    let visible = 0;
    rows.forEach(row => {
        const matchQ  = !q  || row.dataset.order.includes(q) || row.dataset.customer.includes(q) || row.dataset.shipment.includes(q) || row.dataset.dest.includes(q) || (row.dataset.od||'').includes(q);
        const matchSt = !st || row.dataset.status === st;
        const matchMo = !mo || row.dataset.date === mo;
        const show = matchQ && matchSt && matchMo;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const cnt = document.getElementById('obCount');
    if (cnt) cnt.textContent = visible + ' order ditampilkan';
}

function confirmDelete(id, n) {
    document.getElementById('delObId').value = id;
    document.getElementById('delObNumber').textContent = n;
    
    var btn = document.querySelector('[data-ob-del-id="'+id+'"]');
    var status = btn ? btn.dataset.status : '';
    var warn = document.getElementById('delObStockWarn');
    warn.style.display = (status==='Picking'||status==='Shipped'||status==='Completed') ? 'block' : 'none';
    document.getElementById('deleteOutboundModal').style.display = 'flex';
}

let obProductTimer = null;
let obSelectedProduct = null;
let obProductMap = {};

function searchProducts(q) {
    clearTimeout(obProductTimer);
    const dd = document.getElementById('productDropdown');
    if (q.length < 1) { dd.style.display='none'; return; }
    dd.style.display='block';
    dd.innerHTML = '<div style="padding:10px 14px;color:#90a4ae;font-size:.83rem"><i class="fas fa-spinner fa-spin mr-1"></i> Mencari produk tersedia...</div>';
    obProductTimer = setTimeout(async () => {
        try {
            const res = await fetch('outbound_api.php?action=search_products&q='+encodeURIComponent(q));
            const data = await res.json();
            if (!data.results || data.results.length === 0) {
                dd.innerHTML = '<div style="padding:10px 14px;color:#90a4ae;font-size:.83rem"><i class="fas fa-box-open mr-1"></i> Tidak ada produk tersedia</div>';
                return;
            }
            obProductMap = {};
            data.results.forEach(p => { obProductMap[p.id] = p; });
            dd.innerHTML = data.results.map(p => `
                <div data-pid="${p.id}"
                     style="padding:10px 14px;cursor:pointer;border-bottom:1px solid #e6f7f7;font-size:.85rem;
                            display:flex;justify-content:space-between;align-items:center;user-select:none"
                     onmouseover="this.style.background='#e0f7f7'" onmouseout="this.style.background=''">
                    <div>
                        <div style="font-weight:600;color:#013d3c">${p.product_code}</div>
                        <div style="color:#546e7a;font-size:.8rem">${p.product_name}</div>
                    </div>
                    <div style="text-align:right;font-size:.75rem">
                        <div style="color:#026766">${p.uom}</div>
                        <div style="color:#026766;font-weight:600">✔ ${p.stock_qty} tersedia</div>
                    </div>
                </div>`).join('');
        } catch(e) {
            dd.innerHTML = '<div style="padding:10px 14px;color:#026766;font-size:.83rem">Error memuat produk</div>';
        }
    }, 200);
}

function selectProduct(p) {
    if (!p) return;
    obSelectedProduct = p;
    document.getElementById('productId').value = p.id;
    document.getElementById('productSearch').value = p.product_code + ' — ' + p.product_name;
    document.getElementById('productDropdown').style.display = 'none';
    updateProductInfo();
    checkFEFOStock();
    document.getElementById('productSearch').focus();
}

function showDropdown() {
    const dd = document.getElementById('productDropdown');
    if (dd.innerHTML.trim() !== '') dd.style.display = 'block';
}

function hideDropdownDelayed() {
    setTimeout(() => { document.getElementById('productDropdown').style.display = 'none'; }, 200);
}

document.addEventListener('DOMContentLoaded', () => {
    const dd = document.getElementById('productDropdown');
    if (dd) {
        dd.addEventListener('mousedown', (e) => {
            e.preventDefault();
            const item = e.target.closest('[data-pid]');
            if (item) selectProduct(obProductMap[item.dataset.pid]);
        });
    }
});

function calculatePallet(){
  const qty = parseFloat(document.getElementById('quantityInput')?.value) || 0;
  const uom = document.getElementById('uomSelect')?.value || 'Drum';

  
  const uomRates = { 'Drum': 4, 'Pail': 24, 'EA': 4, 'Bags': 1 };
  
  const fromDataset = parseInt(document.getElementById('uomSelect')?.dataset.uomPerPallet) || 44;
  const perPallet = uom === 'Carton' ? fromDataset : (uomRates[uom] ?? 4);

  const rawPlt  = qty > 0 ? (qty / perPallet) : 0;
  const plt     = Math.ceil(rawPlt);          
  const full    = Math.floor(rawPlt);
  const remUnits= qty % perPallet;
  const d = document.getElementById('palletDisplay');
  if (d) {
      let breakdown = '—';
      if (qty > 0) {
          breakdown = remUnits === 0
              ? `${plt} plt (${full} full @ ${perPallet}/plt)`
              : `${plt} plt = ${full} full + 1 partial (${remUnits})`;
      }
      d.value = breakdown;
  }
  const i = document.getElementById('palletInput');
  if (i) i.value = plt;

  
  const pid2 = document.getElementById('productId')?.value;
  if (pid2) {
      const byLoc2 = getByLocMap(pid2);
      if (Object.keys(byLoc2).length) renderAutoAlloc(byLoc2, qty);

      
      const currentMode = document.querySelector('input[name="loc_mode"]:checked')?.value;
      if (currentMode === 'manual') updateManualTotal();
  }
}
async function checkFEFOStock(){
  const pid=document.getElementById('productId')?.value;
  const qty=parseFloat(document.getElementById('quantityInput')?.value)||0;
  const pv=document.getElementById('fefoPreview');
  const dt=document.getElementById('fefoDetails');
  const addBtn=document.querySelector('button[name="add_item"]');
  if(!pid||qty===0){pv.style.display='none';if(addBtn){addBtn.disabled=false;addBtn.title='';}return;}
  try{
    const r=await fetch('outbound_api.php?action=check_stock&product_id='+pid+'&quantity='+qty);
    const d=await r.json();
    if(d.allocation&&d.allocation.length>0){
      let h='';
      d.allocation.forEach((a,i)=>{
        h+=`<div class="ob-fefo-row">
          <div><span style="font-weight:600">Batch ${i+1}:</span>
          <span style="font-family:monospace;margin:0 7px">${a.batch_number}</span>
          <span style="color:#558b2f;font-size:.76rem"><i class="fas fa-calendar-alt mr-1"></i>${a.expiry_date}</span></div>
          <div style="font-weight:600">${a.required_qty}
          <span style="color:#90a4ae;font-weight:400">/ ${a.available_qty} avail</span>
          ${a.is_partial?'<span style="color:#e65100;font-size:.72rem;margin-left:5px">Partial</span>':'<span style="color:#026766;font-size:.72rem;margin-left:5px">✓ Full</span>'}
          </div></div>`;
      });
      if(d.sufficient){
        h+='<div style="font-size:.78rem;font-weight:700;color:#026766;margin-top:5px"><i class="fas fa-check-circle mr-1"></i>Stock sufficient</div>';
        if(addBtn){addBtn.disabled=false;addBtn.style.opacity='';addBtn.title='';}
      } else {
        h+=`<div style="font-size:.78rem;font-weight:700;color:#014f4e;margin-top:5px"><i class="fas fa-exclamation-circle mr-1"></i>Stok tidak cukup! Kekurangan: ${d.shortage} unit. Tersedia: ${d.total_available ?? ''}</div>`;
        if(addBtn){addBtn.disabled=true;addBtn.style.opacity='0.5';addBtn.title='Stok tidak mencukupi, tidak dapat menambah item';}
      }
      dt.innerHTML=h;pv.style.display='block';
    } else {
      
      dt.innerHTML='<div style="font-size:.78rem;font-weight:700;color:#014f4e;margin-top:5px"><i class="fas fa-exclamation-circle mr-1"></i>Tidak ada stok tersedia untuk produk ini.</div>';
      pv.style.display='block';
      if(addBtn){addBtn.disabled=true;addBtn.style.opacity='0.5';addBtn.title='Tidak ada stok tersedia';}
    }
  } catch(e){pv.style.display='none';if(addBtn){addBtn.disabled=false;addBtn.style.opacity='';}}
}

const STOCK_BY_PRODUCT = <?php echo json_encode($stockByProduct ?? [], JSON_HEX_TAG|JSON_UNESCAPED_UNICODE); ?>;

function updateProductInfo(){
  const p = obSelectedProduct; if(!p) return;
  const uomSel = document.getElementById('uomSelect');
  uomSel.value = p.uom || 'Drum';
  uomSel.dataset.uomPerPallet = p.uom_per_pallet || 4;
  calculatePallet();
  renderStockBins(p.id);

  
  const qtyInput = document.getElementById('quantityInput');
  const addBtn   = document.querySelector('button[name="add_item"]');
  if (qtyInput && p.stock_qty !== undefined) {
    qtyInput.max = p.stock_qty;
    const hint = document.getElementById('qtyStockHint') || (() => {
      const el = document.createElement('div');
      el.id = 'qtyStockHint';
      el.style.cssText = 'font-size:.74rem;margin-top:3px;color:#026766;font-weight:600';
      qtyInput.parentNode.appendChild(el);
      return el;
    })();
    hint.innerHTML = p.stock_qty > 0
      ? `<i class="fas fa-boxes" style="color:#388e3c"></i> Stok tersedia: <b>${parseFloat(p.stock_qty).toFixed(0)}</b> ${p.uom}`
      : `<i class="fas fa-exclamation-circle" style="color:#026766"></i> <span style="color:#026766">Tidak ada stok!</span>`;
    if (addBtn) { addBtn.disabled = p.stock_qty <= 0; addBtn.style.opacity = p.stock_qty <= 0 ? '0.5' : ''; }
  }
}

function getByLocMap(pid) {
    const rows = STOCK_BY_PRODUCT[pid] || [];
    const byLoc = {};
    rows.forEach(r => {
        const baseLoc  = r.location || '(tanpa lokasi)';
        const key      = (r.pallet_seq != null) ? `${baseLoc}#P${r.pallet_seq}` : baseLoc;
        const dispLoc  = (r.pallet_seq != null) ? `${baseLoc} (P${r.pallet_seq})` : baseLoc;
        const isFull   = r.is_full;
        if (!byLoc[key]) byLoc[key] = {
            qty: 0, uom: r.uom, batches: [], earliest_expiry: null, pallet: 0,
            actualLoc: baseLoc, displayLoc: dispLoc, is_full: isFull
        };
        byLoc[key].qty += parseFloat(r.quantity);
        if (r.batch_number && !byLoc[key].batches.includes(r.batch_number))
            byLoc[key].batches.push(r.batch_number);
        if (r.expiry_date && (!byLoc[key].earliest_expiry || r.expiry_date < byLoc[key].earliest_expiry))
            byLoc[key].earliest_expiry = r.expiry_date;
    });
    const upp = parseInt(document.getElementById('uomSelect')?.dataset?.uomPerPallet || 4);
    Object.values(byLoc).forEach(info => { info.pallet = Math.ceil(info.qty / upp); });
    return byLoc;
}

function renderStockBins(pid) {
    const autoPanel = document.getElementById('autoAllocPanel');
    if (!autoPanel || !pid) return;
    const byLoc = getByLocMap(pid);
    if (!Object.keys(byLoc).length) {
        autoPanel.innerHTML = '<span style="color:#026766"><i class="fas fa-exclamation-circle mr-1"></i>Tidak ada stok tersedia</span>';
        return;
    }
    const qty = parseFloat(document.getElementById('quantityInput')?.value) || 0;
    renderAutoAlloc(byLoc, qty);
    
    const builder = document.getElementById('manualBinBuilder');
    if (builder) builder.innerHTML = '';
    const addBinBtn = document.getElementById('addBinRowBtn');
    if (addBinBtn) addBinBtn.style.display = 'none';
}

function renderAutoAlloc(byLoc, requestedQty) {
    const autoPanel = document.getElementById('autoAllocPanel');
    if (!autoPanel) return;
    const upp = parseInt(document.getElementById('uomSelect')?.dataset?.uomPerPallet || 4);

    
    const sortedByLoc = Object.entries(byLoc).sort(([, a], [, b]) => {
        if (!a.earliest_expiry && !b.earliest_expiry) return 0;
        if (!a.earliest_expiry) return 1;  
        if (!b.earliest_expiry) return -1;
        return a.earliest_expiry < b.earliest_expiry ? -1 : a.earliest_expiry > b.earliest_expiry ? 1 : 0;
    });
    let remaining = requestedQty;
    const allocResult = []; 
    sortedByLoc.forEach(([loc, info]) => {
        if (remaining <= 0) return;
        const take    = requestedQty > 0 ? Math.min(remaining, info.qty) : 0;
        const takePlt = take > 0 ? Math.ceil(take / upp) : 0;
        remaining    -= take;
        allocResult.push({ loc, info, take, takePlt, isFull: take > 0 && Math.abs(take - info.qty) < 0.01 });
    });

    
    const usedBins  = allocResult.filter(a => a.take > 0);
    const unusedBins= allocResult.filter(a => a.take <= 0);

    const reqPlt = requestedQty > 0 ? Math.ceil(requestedQty / upp) : 0;

    let html = `<div style="font-size:.73rem;font-weight:700;color:#013d3c;margin-bottom:7px;
                            letter-spacing:.04em;text-transform:uppercase">
        <i class="fas fa-magic mr-1"></i>Alokasi FEFO Otomatis
        ${requestedQty > 0 ? `<span style="font-weight:400;color:#026766;margin-left:6px">(${reqPlt} pallet diperlukan)</span>` : ''}
    </div>`;

    if (usedBins.length === 0 && requestedQty > 0) {
        html += `<div style="color:#026766;font-size:.78rem"><i class="fas fa-exclamation-circle mr-1"></i>Tidak ada stok tersedia.</div>`;
    } else if (usedBins.length > 0) {
        html += '<div style="display:flex;flex-wrap:wrap;gap:8px">';
        usedBins.forEach(({ loc, info, take, takePlt, isFull }) => {
            const cardCol = isFull ? '#e0f7f7' : '#fff8e1';
            const brdCol  = isFull ? '#026766' : '#f9a825';
            const txtCol  = isFull ? '#013d3c' : '#795548';
            const expStr  = info.earliest_expiry
                ? `<div style="font-size:.67rem;opacity:.8;margin-top:2px"><i class="fas fa-calendar-alt"></i> Exp: ${info.earliest_expiry}</div>` : '';
            const safeL   = loc.replace(/'/g,"\\'").replace(/"/g,'&quot;');
            html += `<div style="background:${cardCol};border:2px solid ${brdCol};border-radius:8px;
                                  padding:9px 13px;min-width:140px;cursor:pointer;transition:.12s"
                          class="stock-bin-chip"
                          onclick="selectAutoChip(this,'${safeL}',${take},${info.qty})"
                          title="Klik untuk switch ke mode manual dengan bin ini">
              <div style="font-family:monospace;font-weight:700;font-size:.84rem;color:${txtCol}">${loc}</div>
              <div style="font-size:.75rem;color:${txtCol};margin-top:3px">
                Ambil: <b>${take.toFixed(0)}</b> ${info.uom} &nbsp;=&nbsp;
                <b style="color:${isFull?'#013d3c':'#e65100'}">${takePlt} plt</b>
                ${isFull ? '<span style="color:#026766;margin-left:4px">&#10003; full</span>'
                         : '<span style="color:#e65100;margin-left:4px">&#9888; partial</span>'}
              </div>
              <div style="font-size:.7rem;color:#78909c;margin-top:1px">
                Stok: ${info.qty.toFixed(0)} (${info.pallet} plt total)
              </div>
              ${expStr}
            </div>`;
        });
        html += '</div>';

        
        const totalTakePlt = usedBins.reduce((s,a) => s + a.takePlt, 0);
        const totalTakeQty = usedBins.reduce((s,a) => s + a.take, 0);
        html += `<div style="margin-top:8px;font-size:.77rem;font-weight:700;
                             color:#026766;padding:5px 8px;background:#e0f7f7;
                             border-radius:6px;display:inline-block">
            <i class="fas fa-check-circle mr-1"></i>
            Total diambil: <b>${totalTakeQty.toFixed(0)}</b> unit dari <b>${usedBins.length}</b> bin
            &nbsp;=&nbsp; <b>${totalTakePlt}</b> pallet
        </div>`;

        
        if (unusedBins.length > 0) {
            html += `<details style="margin-top:6px">
                <summary style="font-size:.72rem;color:#78909c;cursor:pointer;list-style:none">
                    <i class="fas fa-eye-slash mr-1"></i>${unusedBins.length} bin lain tidak diambil
                    <i class="fas fa-chevron-down" style="font-size:.6rem;margin-left:2px"></i>
                </summary>
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">`;
            unusedBins.forEach(({ loc, info }) => {
                html += `<div style="background:#f5f5f5;border:1.5px solid #ddd;border-radius:7px;
                                     padding:7px 10px;min-width:120px;opacity:.6">
                    <div style="font-family:monospace;font-weight:700;font-size:.8rem;color:#78909c">${loc}</div>
                    <div style="font-size:.72rem;color:#bdbdbd">${info.qty.toFixed(0)} ${info.uom} / ${info.pallet} plt</div>
                </div>`;
            });
            html += '</div></details>';
        }
    }

    if (requestedQty > 0 && remaining > 0.001)
        html += `<div style="margin-top:8px;font-size:.78rem;font-weight:700;color:#014f4e">
            <i class="fas fa-exclamation-circle mr-1"></i>Stok kurang ${remaining.toFixed(0)} unit.
        </div>`;

    autoPanel.innerHTML = html;

    
    if (requestedQty > 0 && usedBins.length > 0) {
        const actualPlt   = usedBins.reduce((s, a) => s + a.takePlt, 0);
        const fullCount   = usedBins.filter(a => a.isFull).length;
        const partialCount= usedBins.filter(a => !a.isFull && a.takePlt > 0).length;
        const pInput = document.getElementById('palletInput');
        const pDisp  = document.getElementById('palletDisplay');
        if (pInput) pInput.value = actualPlt;
        if (pDisp) {
            let desc = actualPlt + ' plt';
            const parts = [];
            if (fullCount > 0)    parts.push(fullCount + ' full');
            if (partialCount > 0) parts.push(partialCount + ' partial');
            if (parts.length > 0) desc += ' = ' + parts.join(' + ');
            pDisp.value = desc;
        }
    }
}

function selectAutoChip(card, loc, take, availQty) {
    document.querySelector('input[name="loc_mode"][value="manual"]').checked = true;
    toggleLocMode('manual');
    const builder = document.getElementById('manualBinBuilder');
    const existing = builder ? builder.querySelector(`[data-loc="${CSS.escape(loc)}"]`) : null;
    if (existing) {
        existing.querySelector('.bin-qty-input').value = take > 0 ? take : availQty;
    } else {
        addManualBinRow(loc, take > 0 ? take : availQty);
    }
    updateManualTotal();
}

function initManualBinBuilder(pid) {
    const builder = document.getElementById('manualBinBuilder');
    const addBtn  = document.getElementById('addBinRowBtn');
    if (builder) builder.innerHTML = '';
    if (addBtn)  addBtn.style.display = 'none';
    const byLoc = getByLocMap(pid);
    const locs  = Object.keys(byLoc);
    if (!locs.length) return;
    if (addBtn) addBtn.style.display = 'inline-flex';

    
    const upp = parseInt(document.getElementById('uomSelect')?.dataset?.uomPerPallet || 4);
    const requestedQty = parseFloat(document.getElementById('quantityInput')?.value) || 0;

    
    const sortedLocs = Object.entries(byLoc).sort(([, a], [, b]) => {
        if (!a.earliest_expiry && !b.earliest_expiry) return 0;
        if (!a.earliest_expiry) return 1;
        if (!b.earliest_expiry) return -1;
        return a.earliest_expiry < b.earliest_expiry ? -1 : a.earliest_expiry > b.earliest_expiry ? 1 : 0;
    });

    if (requestedQty > 0) {
        
        let remaining = requestedQty;
        let anyAdded  = false;
        sortedLocs.forEach(([loc, info]) => {
            if (remaining <= 0.001) return;
            const take = Math.min(remaining, info.qty);
            addManualBinRow(loc, take);
            remaining -= take;
            anyAdded = true;
        });
        if (!anyAdded) addManualBinRow(sortedLocs[0]?.[0] || locs[0], null);
    } else {
        
        addManualBinRow(sortedLocs[0]?.[0] || locs[0], null);
    }
    updateManualTotal();
}

function addManualBinRow(presetLoc, presetQty) {
    const pid = obSelectedProduct?.id;
    if (!pid) return;
    const byLoc = getByLocMap(pid);
    const locs  = Object.keys(byLoc);
    if (!locs.length) return;
    const builder = document.getElementById('manualBinBuilder');
    if (!builder) return;
    const rowId = 'binrow_' + Date.now();
    
    const sortedLocs2 = [...locs].sort((a, b) => {
        const ea = byLoc[a]?.earliest_expiry, eb = byLoc[b]?.earliest_expiry;
        if (!ea && !eb) return 0; if (!ea) return 1; if (!eb) return -1;
        return ea < eb ? -1 : ea > eb ? 1 : 0;
    });
    
    const alreadyUsed = new Set(Array.from(builder.querySelectorAll('[data-row]')).map(r => r.dataset.loc));
    const defaultLoc  = presetLoc || sortedLocs2.find(l => !alreadyUsed.has(l)) || sortedLocs2[0] || locs[0];
    const loc   = defaultLoc;
    const info  = byLoc[loc] || {qty:0, uom:'Drum', pallet:0};

    
    let qty;
    if (presetQty !== null && presetQty !== undefined) {
        qty = presetQty;
    } else {
        const orderQty = parseFloat(document.getElementById('quantityInput')?.value) || 0;
        let assigned = 0;
        builder.querySelectorAll('.bin-qty-input').forEach(inp => { assigned += parseFloat(inp.value) || 0; });
        const remaining = Math.max(0, orderQty - assigned);
        qty = remaining > 0 ? Math.min(remaining, info.qty) : info.qty;
    }
    const upp   = parseInt(document.getElementById('uomSelect')?.dataset?.uomPerPallet || 4);
    const takePlt = qty > 0 ? Math.ceil(qty / upp) : 0;

    const actualLoc = info.actualLoc || loc;   
    const displayLoc = info.displayLoc || loc;  

    const row   = document.createElement('div');
    row.id = rowId; row.dataset.loc = loc; row.dataset.actualLoc = actualLoc;
    row.setAttribute('data-row','1');
    row.style.cssText = 'display:grid;grid-template-columns:1fr 170px 160px auto;gap:8px;align-items:center;' +
                        'background:#e0f7f7;border:1.5px solid #80d2d2;border-radius:8px;padding:9px 11px';

    
    const sortedOptLocs = [...locs].sort((a, b) => {
        const ea = byLoc[a]?.earliest_expiry, eb = byLoc[b]?.earliest_expiry;
        if (!ea && !eb) return 0;
        if (!ea) return 1;
        if (!eb) return -1;
        return ea < eb ? -1 : ea > eb ? 1 : 0;
    });
    const opts = sortedOptLocs.map(l => {
        const inf = byLoc[l];
        const fullTag = inf.is_full ? ' ✓full' : (inf.is_full === 0 ? ' ⚠partial' : '');
        const exp = inf.earliest_expiry ? ` | exp ${inf.earliest_expiry}` : '';
        return `<option value="${l}" ${l===loc?'selected':''}>${inf.displayLoc||l} — ${inf.qty.toFixed(0)} ${inf.uom}${fullTag}${exp}</option>`;
    }).join('');

    const fullBadge = info.is_full ? '<span style="font-size:.65rem;background:#c8e6c9;color:#2e7d32;border-radius:4px;padding:1px 5px;margin-left:4px">full</span>'
                    : (info.is_full === 0 ? '<span style="font-size:.65rem;background:#fff3e0;color:#e65100;border-radius:4px;padding:1px 5px;margin-left:4px">partial</span>' : '');

    row.innerHTML = `
      <div>
        <label style="font-size:.7rem;font-weight:700;color:#026766;margin-bottom:2px;display:block">
          <i class="fas fa-map-marker-alt"></i> Bin / Lokasi
        </label>
        <select class="ob-sel bin-loc-select" onchange="onBinLocChange(this,'${rowId}')"
                style="font-size:.8rem;padding:5px 8px">${opts}</select>
      </div>
      <div>
        <label style="font-size:.7rem;font-weight:700;color:#026766;margin-bottom:2px;display:block">
          Stok tersedia${fullBadge}
        </label>
        <div class="bin-avail-info" style="font-size:.8rem;padding:5px 0;font-weight:600;color:#013d3c">
          ${info.qty.toFixed(0)} <span style="font-weight:400;color:#78909c">${info.uom}</span>
          &nbsp;= <b>1 plt</b>
          ${info.earliest_expiry ? `<div style="font-size:.68rem;color:#e65100;font-weight:700;margin-top:1px"><i class="fas fa-calendar-alt"></i> Exp: ${info.earliest_expiry}</div>` : ''}
        </div>
      </div>
      <div>
        <label style="font-size:.7rem;font-weight:700;color:#026766;margin-bottom:2px;display:block">
          Qty Ambil <span style="color:#026766">*</span>
        </label>
        <input type="number" class="ob-inp bin-qty-input" min="0" step="0.01" max="${info.qty}"
               value="${qty}"
               onchange="updateManualTotal();updateBinPalletInfo('${rowId}')"
               oninput="updateManualTotal();updateBinPalletInfo('${rowId}')"
               style="padding:5px 8px;font-size:.82rem;font-weight:600;border-color:#026766">
        <div class="bin-plt-info" style="font-size:.7rem;color:#026766;margin-top:2px;font-weight:700">
          ${takePlt > 0 ? `<i class="fas fa-pallet mr-1"></i>${takePlt} pallet diambil` : ''}
        </div>
      </div>
      <div>
        <label style="font-size:.7rem;color:transparent;display:block">X</label>
        <button type="button" onclick="removeManualBinRow('${rowId}')"
                style="padding:6px 10px;border-radius:6px;background:#e0f7f7;border:1px solid #80d2d2;
                       color:#014f4e;cursor:pointer;font-size:.8rem">
          <i class="fas fa-times"></i>
        </button>
      </div>`;
    builder.appendChild(row);
    updateManualTotal();
}

function updateBinPalletInfo(rowId) {
    const row  = document.getElementById(rowId);
    if (!row) return;
    const qty  = parseFloat(row.querySelector('.bin-qty-input')?.value || 0);
    const upp  = parseInt(document.getElementById('uomSelect')?.dataset?.uomPerPallet || 4);
    const plt  = qty > 0 ? Math.ceil(qty / upp) : 0;
    const info = row.querySelector('.bin-plt-info');
    if (info) {
        info.innerHTML = plt > 0
            ? `<i class="fas fa-pallet mr-1"></i>${plt} pallet diambil`
            : '';
    }
}

function onBinLocChange(sel, rowId) {
    const pid   = obSelectedProduct?.id;
    const byLoc = pid ? getByLocMap(pid) : {};
    const loc   = sel.value;
    const info  = byLoc[loc] || {qty:0, uom:'Drum', pallet:0, actualLoc: loc};
    const row   = document.getElementById(rowId);
    if (!row) return;
    row.dataset.loc = loc;
    row.dataset.actualLoc = info.actualLoc || loc;
    const qtyInp = row.querySelector('.bin-qty-input');
    const avail  = row.querySelector('.bin-avail-info');
    if (qtyInp) { qtyInp.max = info.qty; qtyInp.value = info.qty; }
    const fullBadge = info.is_full ? ' <span style="font-size:.65rem;background:#c8e6c9;color:#2e7d32;border-radius:4px;padding:1px 5px">full</span>'
                    : (info.is_full === 0 ? ' <span style="font-size:.65rem;background:#fff3e0;color:#e65100;border-radius:4px;padding:1px 5px">partial</span>' : '');
    if (avail) avail.innerHTML = `${info.qty.toFixed(0)} <span style="font-weight:400;color:#78909c">${info.uom}</span> = <b>1 plt</b>${fullBadge}${info.earliest_expiry ? `<div style="font-size:.68rem;color:#e65100;font-weight:700;margin-top:1px"><i class="fas fa-calendar-alt"></i> Exp: ${info.earliest_expiry}</div>` : ''}`;
    updateBinPalletInfo(rowId);
    updateManualTotal();
}

function removeManualBinRow(rowId) {
    const row = document.getElementById(rowId);
    if (row) row.remove();
    updateManualTotal();
}

function updateManualTotal() {
    const builder  = document.getElementById('manualBinBuilder');
    const totalChk = document.getElementById('manualTotalCheck');
    const locsJson = document.getElementById('manualLocsJson');
    const addBtn   = document.querySelector('button[name="add_item"]');
    const reqQty   = parseFloat(document.getElementById('quantityInput')?.value) || 0;
    const upp      = parseInt(document.getElementById('uomSelect')?.dataset?.uomPerPallet || 4);
    if (!builder) return;
    const rows = builder.querySelectorAll('[data-row]');
    let total = 0; const locs = [];
    rows.forEach(row => {
        const loc = row.dataset.actualLoc || row.dataset.loc; 
        const qty = parseFloat(row.querySelector('.bin-qty-input')?.value || 0);
        if (loc && qty > 0) { locs.push({location:loc, qty}); total += qty; }
        
        updateBinPalletInfo(row.id);
    });
    if (locsJson) locsJson.value = JSON.stringify(locs);
    const legacyInp = document.getElementById('manualLocValue');
    if (legacyInp) legacyInp.value = locs.length === 1 ? locs[0].location : '';
    if (!totalChk) return;
    totalChk.style.display = 'block';
    const diff    = total - reqQty;
    const reqPlt  = reqQty > 0 ? Math.ceil(reqQty / upp) : 0;
    const totPlt  = total  > 0 ? Math.ceil(total  / upp) : 0;

    if (reqQty <= 0) {
        totalChk.innerHTML = '<span style="color:#78909c"><i class="fas fa-info-circle"></i> Masukkan qty terlebih dahulu</span>';
    } else if (Math.abs(diff) < 0.01) {
        totalChk.innerHTML = `<span style="color:#026766">
            <i class="fas fa-check-circle mr-1"></i>
            Total bin: <b>${total.toFixed(0)}</b> = Qty diminta &nbsp;|&nbsp;
            <i class="fas fa-pallet mr-1"></i><b>${totPlt}</b> pallet &mdash; Siap submit!
        </span>`;
        if (addBtn && document.querySelector('input[name="loc_mode"]:checked')?.value === 'manual') {
            addBtn.disabled = false; addBtn.style.opacity = '';
        }
    } else if (diff > 0) {
        totalChk.innerHTML = `<span style="color:#e65100">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            Total bin: <b>${total.toFixed(0)}</b> (${totPlt} plt) — Melebihi qty <b>${diff.toFixed(0)}</b>. Kurangi qty bin.
        </span>`;
        if (addBtn) { addBtn.disabled = true; addBtn.style.opacity = '0.5'; }
    } else {
        totalChk.innerHTML = `<span style="color:#014f4e">
            <i class="fas fa-info-circle mr-1"></i>
            Total bin: <b>${total.toFixed(0)}</b> (${totPlt} plt) — Kurang <b>${Math.abs(diff).toFixed(0)}</b>.
            Tambah bin atau sesuaikan qty.
        </span>`;
        if (addBtn) { addBtn.disabled = true; addBtn.style.opacity = '0.5'; }
    }
}

function toggleLocMode(mode) {
    const autoP   = document.getElementById('autoLocPanel');
    const manualP = document.getElementById('manualLocPanel');
    if (mode === 'manual') {
        if (autoP)   autoP.style.display   = 'none';
        if (manualP) manualP.style.display = 'block';
        const builder = document.getElementById('manualBinBuilder');
        if (obSelectedProduct) {
            
            initManualBinBuilder(obSelectedProduct.id);
        }
    } else {
        if (autoP)   autoP.style.display   = 'block';
        if (manualP) manualP.style.display = 'none';
        const legacyInp = document.getElementById('manualLocValue');
        if (legacyInp) legacyInp.value = '';
        const locsJson = document.getElementById('manualLocsJson');
        if (locsJson) locsJson.value = '[]';
        const addBtn = document.querySelector('button[name="add_item"]');
        if (addBtn) { addBtn.disabled = false; addBtn.style.opacity = ''; }
        
        const pid = document.getElementById('productId')?.value;
        const qty = parseFloat(document.getElementById('quantityInput')?.value) || 0;
        if (pid) {
            const byLoc = getByLocMap(pid);
            if (Object.keys(byLoc).length) renderAutoAlloc(byLoc, qty);
        }
    }
}

const SHIPMENT_DATA = <?php $sf=__DIR__.'/data_shipments.json'; echo file_exists($sf)?file_get_contents($sf):'{}'; ?>;
function lookupShipment(val) {
  val = val.trim();
  const result = document.getElementById('shipment_lookup_result');
  const found = document.getElementById('shipment_found_text');
  if (val && SHIPMENT_DATA[val]) {
    const s = SHIPMENT_DATA[val];
    
    const kotaInp = document.querySelector('input[name="kota"]');
    if (kotaInp && !kotaInp.value) kotaInp.value = s.location || '';
    found.textContent = s.name + (s.location ? ' – ' + s.location : '');
    result.style.display = 'block';
  } else {
    result.style.display = 'none';
  }
}

(function(){
  const sn = document.getElementById('shipment_number');
  if (sn && sn.value) lookupShipment(sn.value);
})();
</script>

<div id="deleteOutboundModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;
     z-index:99999;background:rgba(0,0,0,.55);align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px;max-width:440px;width:92%;
              box-shadow:0 24px 64px rgba(0,0,0,.3)">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
      <div style="background:#e6f7f7;border-radius:50%;width:46px;height:46px;flex-shrink:0;
                  display:flex;align-items:center;justify-content:center">
        <i class="fas fa-exclamation-triangle" style="color:#026766;font-size:1.1rem"></i>
      </div>
      <div>
        <div style="font-weight:800;font-size:1rem;color:#111">Hapus Outbound Order?</div>
        <div style="font-family:monospace;font-size:.85rem;color:#6b7280;font-weight:600" id="delObNumber"></div>
      </div>
    </div>
    <div id="delObStockWarn" style="display:none;background:#e6f7f7;border:1px solid #b2e5e5;
         border-radius:8px;padding:10px 14px;font-size:.8rem;color:#013d3c;margin-bottom:10px">
      <i class="fas fa-undo mr-1"></i>
      <b>Order ini sudah di-pick/shipped</b> — stock yang sudah dikurangi akan <b>dikembalikan</b> ke lokasi asal.
    </div>
    <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;
         font-size:.8rem;color:#854d0e;margin-bottom:12px">
      <i class="fas fa-database mr-1"></i>
      <b>Yang akan dihapus:</b><br>
      &bull; Order & semua item outbound<br>
      &bull; Ledger entries OUT<br>
      &bull; Data lokasi pick
    </div>
    <div style="font-size:.82rem;color:#374151;margin-bottom:16px">Aksi ini <b>tidak bisa dibatalkan</b>. Lanjutkan?</div>
    <form method="POST" style="display:flex;gap:10px">
      <input type="hidden" name="delete_outbound" value="1">
      <input type="hidden" name="id" id="delObId">
      <button type="submit"
              style="flex:1;background:#026766;color:#fff;border:none;border-radius:8px;
                     padding:10px;font-weight:700;cursor:pointer;font-size:.88rem">
        <i class="fas fa-trash mr-1"></i>Ya, Hapus
      </button>
      <button type="button"
              onclick="document.getElementById('deleteOutboundModal').style.display='none'"
              style="flex:1;background:#f3f4f6;color:#374151;border:none;border-radius:8px;
                     padding:10px;font-weight:600;cursor:pointer;font-size:.88rem">Batal</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
