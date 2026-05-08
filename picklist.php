<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Picklist.php';

Auth::requireAuth();
$canWrite = Auth::canWrite();
$canAdmin = Auth::canAdmin();

$pageTitle = 'Picklist Management';
$currentPage = 'picklist';

$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireWrite();
    try {
        if (isset($_POST['generate_picklist'])) {
            $picklistId = Picklist::createFromOutbound($_POST['outbound_id']);
            header('Location: picklist.php?action=view&id=' . $picklistId . '&success=created');
            exit;
        }

        if (isset($_POST['confirm_picklist'])) {
            Picklist::confirm($_POST['id']);
            header('Location: picklist.php?action=view&id=' . $_POST['id'] . '&success=confirmed');
            exit;
        }

        if (isset($_POST['complete_picklist'])) {
            Picklist::complete($_POST['id']);
            header('Location: picklist.php?action=view&id=' . $_POST['id'] . '&success=completed');
            exit;
        }

        if (isset($_POST['update_item'])) {
            Picklist::updateItem($_POST['item_id'], [
                'picked_quantity' => $_POST['picked_quantity'] ?? 0,
                'status'          => $_POST['status'] ?? 'Pending',
                'notes'           => $_POST['notes'] ?? null,
            ]);
            
            $db2 = db();
            $pickId = (int)$_POST['picklist_id'];
            $rows = $db2->prepare("SELECT status FROM picklist_items WHERE picklist_id = ?");
            $rows->execute([$pickId]);
            $statuses = $rows->fetchAll(PDO::FETCH_COLUMN);
            $noPending = !empty($statuses) && !in_array('Pending', $statuses);
            if ($noPending) {
                $db2->prepare("UPDATE picklists SET status = 'Picked', picked_at = NOW() WHERE id = ? AND status != 'Completed'")->execute([$pickId]);
            }
            header('Location: picklist.php?action=view&id=' . $_POST['picklist_id'] . '&success=item_updated');
            exit;
        }

        if (isset($_POST['delete_picklist'])) {
            Picklist::delete($_POST['id']);
            header('Location: picklist.php?action=list&success=deleted');
            exit;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$picklist = $id ? Picklist::getById($id) : null;
$picklistItems = $picklist ? Picklist::getItems($id) : [];

$_plStatusFilter = $_GET['status'] ?? '';
$_plPerPage      = 50;
$_plPage         = max(1, (int)($_GET['page'] ?? 1));
$_plOffset       = ($_plPage - 1) * $_plPerPage;
$_plStats        = Picklist::getStats();
$_plTotal        = Picklist::countAll($_plStatusFilter ?: null);
$_plTotalPages   = max(1, (int)ceil($_plTotal / $_plPerPage));
$_plPage         = min($_plPage, $_plTotalPages);
$_plOffset       = ($_plPage - 1) * $_plPerPage;
$picklistList    = Picklist::getAll($_plStatusFilter ?: null, $_plPerPage, $_plOffset);

$db = db();
$outboundOrders = $db->query("SELECT o.*, c.customer_name
                                FROM outbound_orders o
                                JOIN customers c ON o.customer_id = c.id
                                WHERE o.status IN ('Open', 'Picking', 'Picked')
                                AND NOT EXISTS (SELECT 1 FROM picklists WHERE picklists.outbound_order_id = o.id)
                                ORDER BY o.order_date DESC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<style>
.gradient-purple {
    background: linear-gradient(135deg, #026766 0%, #014f4e 100%);
}

.status-draft {
    @apply bg-gray-100 text-gray-800 px-3 py-1 rounded-full text-sm font-medium;
}
.status-confirmed {
    @apply bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm font-medium;
}
.status-picked {
    @apply bg-yellow-100 text-yellow-800 px-3 py-1 rounded-full text-sm font-medium;
}
.status-completed {
    @apply bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium;
}

@media print {
    .no-print { display: none !important; }
    .print-only { display: block !important; }
    body { background: white !important; }
    .print-container { box-shadow: none !important; border: 1px solid #ddd !important; }
}
</style>

<div class="space-y-6">
    
    <div class="gradient-purple rounded-2xl shadow-lg p-8 text-white no-print">
        <h1 class="text-3xl font-bold mb-2">
            <i class="fas fa-clipboard-list mr-3"></i>Picklist Management
        </h1>
        <p class="text-purple-100">Generate and manage picking lists for warehouse operations</p>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6">
            <div class="bg-white/20 backdrop-blur-sm rounded-xl p-4">
                <div class="text-2xl font-bold"><?= $_plStats['total'] ?></div>
                <div class="text-sm text-purple-100">Total Picklists</div>
            </div>
            <div class="bg-white/20 backdrop-blur-sm rounded-xl p-4">
                <div class="text-2xl font-bold"><?= $_plStats['pending'] ?></div>
                <div class="text-sm text-purple-100">Pending</div>
            </div>
            <div class="bg-white/20 backdrop-blur-sm rounded-xl p-4">
                <div class="text-2xl font-bold"><?= $_plStats['picking'] ?></div>
                <div class="text-sm text-purple-100">Being Picked</div>
            </div>
            <div class="bg-white/20 backdrop-blur-sm rounded-xl p-4">
                <div class="text-2xl font-bold"><?= $_plStats['completed'] ?></div>
                <div class="text-sm text-purple-100">Completed</div>
            </div>
        </div>
    </div>

    
    <?php if (isset($_GET['success'])): ?>
    <div class="bg-green-50 border border-green-200 text-green-700 px-6 py-4 rounded-xl no-print">
        <i class="fas fa-check-circle mr-2"></i>
        <?= htmlspecialchars($_GET['success']) ?>
    </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-6 py-4 rounded-xl no-print">
        <i class="fas fa-exclamation-circle mr-2"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
    
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="p-6 border-b border-gray-200 flex justify-between items-center no-print">
            <h2 class="text-xl font-bold text-gray-800">
                <i class="fas fa-list mr-2 text-purple-500"></i>All Picklists
            </h2>
            <div class="flex gap-2">
                <button onclick="document.getElementById('generateModal').classList.remove('hidden')"
                        class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-plus mr-2"></i>Generate Picklist
                </button>
            </div>
        </div>
        
        <div style="padding:12px 16px;border-bottom:1px solid #f3e8ff;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <div style="position:relative;flex:1;min-width:180px">
                <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#90a4ae;font-size:.85rem"></i>
                <input type="text" id="plSearch" placeholder="Cari picklist no, outbound, customer..."
                       oninput="filterPlTable()"
                       style="width:100%;padding:7px 12px 7px 32px;border:1px solid #80d2d2;border-radius:8px;font-size:.85rem;outline:none">
            </div>
            <select onchange="location='picklist.php?action=list&status='+this.value"
                    style="padding:7px 12px;border:1px solid #80d2d2;border-radius:8px;font-size:.85rem;color:#6b7280;background:#fff">
                <option value="" <?= !$_plStatusFilter ? 'selected' : '' ?>>Semua Status</option>
                <option value="Draft"     <?= $_plStatusFilter === 'Draft'     ? 'selected' : '' ?>>Draft</option>
                <option value="Confirmed" <?= $_plStatusFilter === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                <option value="Picked"    <?= $_plStatusFilter === 'Picked'    ? 'selected' : '' ?>>Picked</option>
                <option value="Completed" <?= $_plStatusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
            </select>
            <span id="plCount" style="font-size:.8rem;color:#90a4ae;white-space:nowrap">
                <?= number_format($_plTotal) ?> picklist<?= $_plStatusFilter ? ' (' . htmlspecialchars($_plStatusFilter) . ')' : '' ?>
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full" id="plTable">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="text-left py-3 px-4 font-semibold text-gray-700">Picklist No</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700">Date</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700">Outbound Order</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700">Customer</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700">SO / DO</th>
                        <th class="text-right py-3 px-4 font-semibold text-gray-700">Items</th>
                        <th class="text-right py-3 px-4 font-semibold text-gray-700">Total Qty</th>
                        <th class="text-right py-3 px-4 font-semibold text-gray-700">Pallet</th>
                        <th class="text-left py-3 px-4 font-semibold text-gray-700">Status</th>
                        <th class="text-center py-3 px-4 font-semibold text-gray-700 no-print">Actions</th>
                    </tr>
                </thead>
                <tbody id="plTbody">
                    <?php foreach ($picklistList as $item): ?>
                    <?php
                    $soNo = $item['so_number'] ?? '';
                    $doNo = $item['do_number'] ?? '';
                    ?>
                    <tr class="border-b border-gray-100 hover:bg-purple-50/50"
                        data-pl="<?=strtolower($item['picklist_number'])?>"
                        data-ob="<?=strtolower($item['outbound_number']??'')?>"
                        data-cust="<?=strtolower($item['customer_name']??'')?>"
                        data-so="<?=strtolower($soNo)?>"
                        data-do="<?=strtolower($doNo)?>"
                        data-status="<?=$item['status']?>">
                        <td class="py-3 px-4 font-medium text-purple-600">
                            <?= htmlspecialchars($item['picklist_number']) ?>
                        </td>
                        <td class="py-3 px-4 text-gray-600">
                            <?= date('d M Y', strtotime($item['created_date'])) ?>
                        </td>
                        <td class="py-3 px-4 text-gray-600 font-mono text-sm">
                            <?= htmlspecialchars($item['outbound_number']) ?>
                        </td>
                        <td class="py-3 px-4 text-gray-600">
                            <?= $item['customer_name'] ? htmlspecialchars($item['customer_name']) : '<span class="text-gray-400">—</span>' ?>
                        </td>
                        <td class="py-3 px-4 text-gray-600 text-sm">
                            <?php if ($soNo): ?><div><span class="text-gray-400 text-xs">SO</span> <span class="font-mono"><?= htmlspecialchars($soNo) ?></span></div><?php endif; ?>
                            <?php if ($doNo): ?><div><span class="text-gray-400 text-xs">DO</span> <span class="font-mono"><?= htmlspecialchars($doNo) ?></span></div><?php endif; ?>
                            <?php if (!$soNo && !$doNo): ?><span class="text-gray-400">—</span><?php endif; ?>
                        </td>
                        <td class="py-3 px-4 text-right text-gray-600">
                            <?= number_format($item['total_items']) ?>
                        </td>
                        <td class="py-3 px-4 text-right text-gray-600">
                            <?= number_format($item['total_qty'], 0) ?>
                        </td>
                        <td class="py-3 px-4 text-right text-gray-600 font-medium">
                            <?= (int)ceil((float)($item['total_pallet'] ?? 0)) ?>
                        </td>
                        <td class="py-3 px-4">
                            <?php
                            $statusClass = 'status-draft';
                            if ($item['status'] === 'Confirmed') $statusClass = 'status-confirmed';
                            elseif ($item['status'] === 'Picked') $statusClass = 'status-picked';
                            elseif ($item['status'] === 'Completed') $statusClass = 'status-completed';
                            ?>
                            <span class="<?= $statusClass ?>"><?= htmlspecialchars($item['status']) ?></span>
                        </td>
                        <td class="py-3 px-4 text-center no-print">
                            <a href="?action=view&id=<?= $item['id'] ?>"
                               class="text-purple-500 hover:text-purple-700 mr-2">
                                <i class="fas fa-eye"></i>
                            </a>
                            <button onclick="confirmDelete(<?= $item['id'] ?>, '<?= htmlspecialchars($item['picklist_number']) ?>')"
                                    class="text-red-500 hover:text-red-700">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (empty($picklistList)): ?>
                    <tr>
                        <td colspan="9" class="py-8 text-center text-gray-500">
                            <i class="fas fa-clipboard-list text-4xl mb-2"></i>
                            <p>No picklists found</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($_plTotalPages > 1): ?>
        <div style="display:flex;gap:6px;padding:12px 16px;align-items:center;flex-wrap:wrap;border-top:1px solid #f3e8ff">
            <?php
            $plBase = 'picklist.php?action=list' . ($_plStatusFilter ? '&status=' . urlencode($_plStatusFilter) : '');
            ?>
            <?php if ($_plPage > 1): ?>
            <a href="<?= $plBase ?>&page=<?= $_plPage - 1 ?>" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none;background:#f3e8ff;color:#7c3aed">&lsaquo;</a>
            <?php endif; ?>
            <?php
            $plStart = max(1, $_plPage - 2);
            $plEnd   = min($_plTotalPages, $_plPage + 2);
            if ($plStart > 1) echo '<span style="padding:0 4px;color:#9ca3af">…</span>';
            for ($pi = $plStart; $pi <= $plEnd; $pi++):
            ?>
            <a href="<?= $plBase ?>&page=<?= $pi ?>"
               style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none;<?= $pi === $_plPage ? 'background:#7c3aed;color:#fff' : 'background:#f3e8ff;color:#7c3aed' ?>">
                <?= $pi ?>
            </a>
            <?php endfor; ?>
            <?php if ($plEnd < $_plTotalPages) echo '<span style="padding:0 4px;color:#9ca3af">…</span>'; ?>
            <?php if ($_plPage < $_plTotalPages): ?>
            <a href="<?= $plBase ?>&page=<?= $_plPage + 1 ?>" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none;background:#f3e8ff;color:#7c3aed">&rsaquo;</a>
            <?php endif; ?>
            <span style="font-size:.8rem;color:#9ca3af;margin-left:4px">Hal <?= $_plPage ?> / <?= $_plTotalPages ?> (<?= number_format($_plTotal) ?> total)</span>
        </div>
        <?php endif; ?>
    </div>


    <div id="generateModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 class="font-semibold">Generate Picklist</h3>
                <button onclick="document.getElementById('generateModal').classList.add('hidden')"
                        class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="p-4">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Outbound Order</label>
                    <select name="outbound_id" required class="w-full px-4 py-2 border rounded-lg">
                        <option value="">Select Order</option>
                        <?php foreach ($outboundOrders as $order): ?>
                        <option value="<?= $order['id'] ?>">
                            <?= htmlspecialchars($order['order_number']) ?> - <?= htmlspecialchars($order['customer_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" name="generate_picklist"
                            class="flex-1 bg-purple-500 text-white py-2 rounded-lg hover:bg-purple-600">
                        Generate
                    </button>
                    <button type="button" onclick="document.getElementById('generateModal').classList.add('hidden')"
                            class="flex-1 bg-gray-200 text-gray-700 py-2 rounded-lg hover:bg-gray-300">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($action === 'view' && $picklist): ?>
    
    <div class="print-container bg-white rounded-xl shadow-lg p-8 mb-6">
        
        <div class="text-center mb-6 border-b pb-4">
            <h1 class="text-2xl font-bold text-gray-800">PICKLIST</h1>
            <div class="text-gray-600">
                <div class="text-lg font-semibold text-purple-600">
                    <?= htmlspecialchars($picklist['picklist_number']) ?>
                </div>
                <div class="text-sm mt-1">
                    Date: <?= date('d F Y', strtotime($picklist['created_date'])) ?>
                    <?php if ($picklist['confirmed_at']): ?>
                    | Confirmed: <?= date('H:i', strtotime($picklist['confirmed_at'])) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>


        <?php
        // Collect unique per-item customers and SO numbers
        $itemCustomers = array_unique(array_filter(array_column($picklistItems, 'item_customer_name')));
        $itemSoNumbers = array_unique(array_filter(array_column($picklistItems, 'item_so_number')));
        $itemDoNumbers = [];
        $headerCustomer = $picklist['customer_name'] ?? null;
        $headerSo = $picklist['so_number'] ?? null;
        $headerDo = $picklist['do_number'] ?? null;
        // Merge: prefer per-item data when available
        $allCustomers = array_unique(array_filter(array_merge($itemCustomers ?: ($headerCustomer ? [$headerCustomer] : []))));
        $allSo = array_unique(array_filter(array_merge($itemSoNumbers ?: ($headerSo ? [$headerSo] : []))));
        $allDo = array_unique(array_filter(array_merge($itemDoNumbers ?: ($headerDo ? [$headerDo] : []))));
        ?>
        <div class="grid grid-cols-2 gap-4 mb-6">
            <div>
                <h3 class="text-sm font-semibold text-gray-700 mb-1">Customer</h3>
                <?php if (!empty($allCustomers)): ?>
                    <?php foreach ($allCustomers as $cust): ?>
                    <p class="font-medium text-gray-800"><?= htmlspecialchars($cust) ?></p>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-gray-400">—</p>
                <?php endif; ?>
                <?php if ($picklist['address'] ?? null): ?>
                <p class="text-sm text-gray-600"><?= htmlspecialchars($picklist['address']) ?></p>
                <?php endif; ?>
                <?php if ($picklist['city'] ?? null): ?>
                <p class="text-sm text-gray-600"><?= htmlspecialchars($picklist['city']) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-700 mb-1">Order Info</h3>
                <p class="text-sm"><span class="font-medium">Outbound:</span> <?= htmlspecialchars($picklist['outbound_number']) ?></p>
                <p class="text-sm"><span class="font-medium">SO No:</span>
                    <?= !empty($allSo) ? htmlspecialchars(implode(', ', $allSo)) : '<span class="text-gray-400">—</span>' ?>
                </p>
                <p class="text-sm"><span class="font-medium">DO No:</span>
                    <?= !empty($allDo) ? htmlspecialchars(implode(', ', $allDo)) : '<span class="text-gray-400">—</span>' ?>
                </p>
                <?php if ($picklist['armada_no'] ?? null): ?>
                <p class="text-sm"><span class="font-medium">Armada:</span> <?= htmlspecialchars($picklist['armada_no']) ?></p>
                <?php endif; ?>
                <?php if ($picklist['container_no'] ?? null): ?>
                <p class="text-sm"><span class="font-medium">Container:</span> <?= htmlspecialchars($picklist['container_no']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        
        <div class="mb-6">
            <h3 class="text-lg font-bold text-gray-800 mb-3">Items to Pick</h3>
            <table class="w-full" id="items-table">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="text-left py-2 px-3 border text-sm font-semibold">No</th>
                        <th class="text-left py-2 px-3 border text-sm font-semibold">Product</th>
                        <th class="text-left py-2 px-3 border text-sm font-semibold">Customer</th>
                        <th class="text-left py-2 px-3 border text-sm font-semibold">SO / OD</th>
                        <th class="text-left py-2 px-3 border text-sm font-semibold">Batch</th>
                        <th class="text-left py-2 px-3 border text-sm font-semibold">Location</th>
                        <th class="text-right py-2 px-3 border text-sm font-semibold">Qty</th>
                        <th class="text-right py-2 px-3 border text-sm font-semibold">Pallet</th>
                        <th class="text-left py-2 px-3 border text-sm font-semibold no-print">Status</th>
                        <th class="text-center py-2 px-3 border text-sm font-semibold print-only">Picked</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($picklistItems as $index => $item): ?>
                    <tr class="border-b" data-item-id="<?= $item['id'] ?>">
                        <td class="py-2 px-3 border text-center font-medium"><?= $index + 1 ?></td>
                        <td class="py-2 px-3 border">
                            <div class="font-medium text-sm"><?= htmlspecialchars($item['product_name']) ?></div>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($item['product_code']) ?></div>
                        </td>
                        <td class="py-2 px-3 border text-sm">
                            <?php $ic = $item['item_customer_name'] ?? null; ?>
                            <?= $ic ? htmlspecialchars($ic) : '<span class="text-gray-400">—</span>' ?>
                            <?php if ($item['item_ship_to'] ?? null): ?>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($item['item_ship_to']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-2 px-3 border text-sm font-mono">
                            <?php $hasSo = $item['item_so_number'] ?? null; $hasOd = $item['item_od_number'] ?? null; ?>
                            <?php if ($hasSo): ?><div><span class="text-gray-400 text-xs font-sans">SO</span> <?= htmlspecialchars($hasSo) ?></div><?php endif; ?>
                            <?php if ($hasOd): ?><div><span class="text-gray-400 text-xs font-sans">OD</span> <?= htmlspecialchars($hasOd) ?></div><?php endif; ?>
                            <?php if (!$hasSo && !$hasOd): ?><span class="text-gray-400">—</span><?php endif; ?>
                        </td>
                        <td class="py-2 px-3 border text-sm">
                            <?= htmlspecialchars($item['batch_no']) ?>
                        </td>
                        <td class="py-2 px-3 border text-sm font-mono">
                            <?= htmlspecialchars($item['location']) ?>
                        </td>
                        <td class="py-2 px-3 border text-right text-sm font-medium">
                            <?= number_format($item['quantity'], 0) ?>
                        </td>
                        <td class="py-2 px-3 border text-right text-sm">
                            <?= (int)ceil(floatval($item['pallet'] ?? 0)) ?>
                        </td>
                        <td class="py-2 px-3 border text-center no-print">
                            <?php
                            $statusClass = 'bg-yellow-100 text-yellow-800';
                            if ($item['status'] === 'Picked') $statusClass = 'bg-green-100 text-green-800';
                            elseif ($item['status'] === 'Verified') $statusClass = 'bg-blue-100 text-blue-800';
                            ?>
                            <span class="item-status-badge <?= $statusClass ?> px-2 py-1 rounded text-xs"><?= $item['status'] ?></span>
                        </td>
                        <td class="py-2 px-3 border text-center print-only">
                            <div class="w-6 h-6 border-2 border-gray-300 rounded"></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        
        <div class="flex justify-between items-center border-t pt-4">
            <div>
                <p class="text-sm text-gray-600">Picker: _______________________</p>
                <p class="text-sm text-gray-600 mt-2">Verifier: _____________________</p>
            </div>
            <div class="text-right">
                <p class="text-sm"><span class="font-medium">Total Items:</span> <?= count($picklistItems) ?></p>
                <p class="text-sm"><span class="font-medium">Total Qty:</span> <?= array_sum(array_column($picklistItems, 'quantity')) ?></p>
            </div>
        </div>
    </div>

    
    <div class="flex gap-4 no-print">
        <?php if ($picklist['status'] === 'Draft'): ?>
        <form method="POST" onsubmit="return confirm('Confirm this picklist?');">
            <input type="hidden" name="id" value="<?= $picklist['id'] ?>">
            <button type="submit" name="confirm_picklist"
                    class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-3 rounded-lg">
                <i class="fas fa-check mr-2"></i>Confirm Picklist
            </button>
        </form>
        <?php endif; ?>

        <?php if ($picklist['status'] === 'Confirmed' || $picklist['status'] === 'Picked'): ?>
        <form method="POST" onsubmit="return confirm('Complete this picklist?');">
            <input type="hidden" name="id" value="<?= $picklist['id'] ?>">
            <button type="submit" name="complete_picklist"
                    class="flex-1 bg-green-500 hover:bg-green-600 text-white py-3 rounded-lg">
                <i class="fas fa-check-double mr-2"></i>Complete Picklist
            </button>
        </form>
        <?php endif; ?>

        <a href="print_picklist.php?id=<?= $picklist['id'] ?>" target="_blank"
           class="flex-1 bg-purple-500 hover:bg-purple-600 text-white py-3 rounded-lg text-center">
            <i class="fas fa-print mr-2"></i>Print / PDF
        </a>

        <a href="?action=list"
           class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 py-3 rounded-lg text-center">
            <i class="fas fa-arrow-left mr-2"></i>Back
        </a>

        <button onclick="confirmDelete(<?= $picklist['id'] ?>, '<?= htmlspecialchars($picklist['picklist_number']) ?>')"
                class="bg-red-100 hover:bg-red-200 text-red-600 px-4 py-3 rounded-lg">
            <i class="fas fa-trash"></i>
        </button>
    </div>

    
    <?php if ($picklist['status'] !== 'Completed'): ?>
    <div class="bg-white rounded-xl shadow p-6 mt-6 no-print" id="updateSection">
        <h3 class="font-bold text-gray-800 mb-4">
            <i class="fas fa-edit mr-2 text-purple-500"></i>Update Item Status
        </h3>
        <div class="space-y-3">
            <?php foreach ($picklistItems as $item): ?>
            <form method="POST" action="picklist.php?action=view&id=<?= $picklist['id'] ?>" 
                  class="flex items-center gap-4 p-3 rounded-lg border transition-all"
                  style="background:<?= $item['status']==='Picked' ? '#e6f7f7' : ($item['status']==='Verified' ? '#e6f7f7' : '#f9fafb') ?>;
                         border-color:<?= $item['status']==='Picked' ? '#b2e5e5' : ($item['status']==='Verified' ? '#e0f7f7' : '#e5e7eb') ?>">
                <input type="hidden" name="update_item" value="1">
                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                <input type="hidden" name="picklist_id" value="<?= $picklist['id'] ?>">
                <div class="flex-1">
                    <span class="font-semibold text-gray-800"><?= htmlspecialchars($item['product_name']) ?></span>
                    <span class="text-xs text-gray-400 ml-1"><?= htmlspecialchars($item['product_code']) ?></span>
                    <div class="text-sm text-gray-500 mt-0.5">
                        Batch: <code class="bg-gray-100 px-1 rounded text-xs"><?= htmlspecialchars($item['batch_no']) ?></code>
                        &nbsp;·&nbsp; Lokasi: <code class="bg-gray-100 px-1 rounded text-xs"><?= htmlspecialchars($item['location']) ?></code>
                        &nbsp;·&nbsp; Qty: <strong><?= number_format($item['quantity'], 0) ?></strong>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-xs text-gray-500">Status:</label>
                    <select name="status" class="px-3 py-2 border rounded-lg text-sm font-medium">
                        <option value="Pending"  <?= $item['status']==='Pending'  ? 'selected' : '' ?>>⏳ Pending</option>
                        <option value="Picked"   <?= $item['status']==='Picked'   ? 'selected' : '' ?>>✅ Picked</option>
                        <option value="Verified" <?= $item['status']==='Verified' ? 'selected' : '' ?>>🔵 Verified</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-xs text-gray-500">Qty Picked:</label>
                    <input type="number" name="picked_quantity"
                           value="<?= $item['picked_quantity'] ?? 0 ?>"
                           class="w-24 px-3 py-2 border rounded-lg text-sm"
                           min="0" max="<?= $item['quantity'] ?>" step="0.01">
                </div>
                <button type="submit"
                        class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition whitespace-nowrap">
                    <i class="fas fa-save mr-1"></i> Simpan
                </button>
            </form>
            <?php endforeach; ?>
        </div>

    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function filterPlTable() {
    const q    = document.getElementById('plSearch').value.toLowerCase();
    const rows = document.querySelectorAll('#plTbody tr[data-pl]');
    let visible = 0;
    rows.forEach(row => {
        const match = !q || row.dataset.pl.includes(q) || row.dataset.ob.includes(q)
                         || row.dataset.cust.includes(q) || (row.dataset.so||'').includes(q)
                         || (row.dataset.do||'').includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const cnt = document.getElementById('plCount');
    if (cnt && q) cnt.textContent = visible + ' ditampilkan dari <?= count($picklistList) ?>';
}

function confirmDelete(id, number) {
    if (confirm('Are you sure you want to delete Picklist "' + number + '"?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="delete_picklist" value="1"><input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
