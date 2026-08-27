<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Auth.php';

$user = Auth::user();
$_isViewer = $user && $user['role'] === 'viewer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'K-one' ?> - K-one Management</title>

    
    <script src="https://cdn.tailwindcss.com"></script>

    
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    
    <link rel="stylesheet" href="<?= BASE_URL ?>/includes/wms-style.css">

    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        shell: {
                            red: '#026766',
                            yellow: '#ffc107',
                            dark: '#1a1a1a'
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
                    }
                }
            }
        }
    </script>

    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f5; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

        
        .sidebar-base { background: #0d1f1f; }
        .sidebar-link {
            color: #94a3a3;
            transition: background .15s, color .15s;
        }
        .sidebar-link:hover {
            background: rgba(2,103,102,.18);
            color: #e0f2f1;
        }
        .sidebar-link.active {
            background: linear-gradient(90deg, #026766 0%, #014f4e 100%);
            color: #fff;
            box-shadow: 0 2px 10px rgba(2,103,102,.35);
        }
        .sidebar-link.active i { color: #fff !important; }
        .sidebar-link i { color: #6b9e9e; }

        
        .topbar { border-left: 4px solid #026766; }

        
        .stat-card:hover { transform: translateY(-5px); }
        .table-row:hover { background-color: #e6f7f6; }
    </style>
</head>
<body class="bg-[#f1f5f5]">

    <div id="modal-root" style="position:relative;z-index:2147483647"></div>
    <?php if ($_isViewer): ?>
    <div style="width:100%;background:#f59e0b;color:#1c1917;padding:6px 16px;font-size:.8rem;font-weight:600;text-align:center;display:flex;align-items:center;justify-content:center;gap:8px;flex-shrink:0">
        <i class="fas fa-eye"></i> Mode View Only — Anda tidak dapat membuat atau mengubah data
    </div>
    <?php endif; ?>
    <?php if (Auth::check()): ?>
    <div class="flex overflow-hidden" style="flex:1;min-height:0">
        
        <aside class="sidebar-base w-64 text-white flex-shrink-0 overflow-y-auto flex flex-col">
            
            <div class="px-5 py-4 border-b border-white/10">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:rgba(2,103,102,.5)">
                        <i class="fas fa-warehouse text-xs" style="color:#7de8e7"></i>
                    </div>
                    <div>
                        <h1 class="text-base font-bold text-white tracking-wide">K-one</h1>
                        <p class="text-[10px] mt-0.5" style="color:#4d9e9e">Warehouse Management</p>
                    </div>
                </div>
            </div>

            <nav class="p-3 space-y-0.5 flex-1 overflow-y-auto">
                <p class="px-3 text-[10px] font-semibold uppercase tracking-widest mb-1.5 mt-1" style="color:#3d8080">Operations</p>
                <a href="<?= BASE_URL ?>/dashboard.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'dashboard' ? 'active' : '' ?>">
                    <i class="fas fa-tachometer-alt w-4 text-center"></i><span>Dashboard</span>
                </a>
                <a href="<?= BASE_URL ?>/inbound.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'inbound' ? 'active' : '' ?>">
                    <i class="fas fa-truck-loading w-4 text-center"></i><span>Inbound</span>
                </a>
                <a href="<?= BASE_URL ?>/outbound.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'outbound' ? 'active' : '' ?>">
                    <i class="fas fa-shipping-fast w-4 text-center"></i><span>Outbound</span>
                </a>
                <a href="<?= BASE_URL ?>/stock.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'stock' ? 'active' : '' ?>">
                    <i class="fas fa-boxes w-4 text-center"></i><span>Stock</span>
                </a>
                <a href="<?= BASE_URL ?>/ledger.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'ledger' ? 'active' : '' ?>">
                    <i class="fas fa-book w-4 text-center"></i><span>Stock Ledger</span>
                </a>
                <a href="<?= BASE_URL ?>/stocktake.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'stocktake' ? 'active' : '' ?>">
                    <i class="fas fa-clipboard-check w-4 text-center"></i><span>Stock Take</span>
                </a>

                <div class="border-t my-2" style="border-color:rgba(255,255,255,.07)"></div>
                <p class="px-3 text-[10px] font-semibold uppercase tracking-widest mb-1.5" style="color:#3d8080">Master Data</p>
                <a href="<?= BASE_URL ?>/products.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'products' ? 'active' : '' ?>">
                    <i class="fas fa-box w-4 text-center"></i><span>Products</span>
                </a>
                <a href="<?= BASE_URL ?>/customers.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'customers' ? 'active' : '' ?>">
                    <i class="fas fa-users w-4 text-center"></i><span>Customers</span>
                </a>

                <div class="border-t my-2" style="border-color:rgba(255,255,255,.07)"></div>
                <p class="px-3 text-[10px] font-semibold uppercase tracking-widest mb-1.5" style="color:#3d8080">Reports & Tools</p>
                <a href="<?= BASE_URL ?>/reports.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'reports' ? 'active' : '' ?>">
                    <i class="fas fa-chart-bar w-4 text-center"></i><span>Reports</span>
                </a>
                <?php if (Auth::canWrite()): ?>
                <a href="<?= BASE_URL ?>/import.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'import' ? 'active' : '' ?>">
                    <i class="fas fa-file-import w-4 text-center"></i><span>Import Inbound</span>
                </a>
                <a href="<?= BASE_URL ?>/import_outbound.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'import_outbound' ? 'active' : '' ?>">
                    <i class="fas fa-file-export w-4 text-center"></i><span>Import Outbound</span>
                </a>
                <a href="<?= BASE_URL ?>/import_auto.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'import_auto' ? 'active' : '' ?>">
                    <i class="fas fa-magic w-4 text-center"></i><span>Auto Import</span>
                </a>
                <?php endif; ?>
                <?php if (Auth::hasRole('admin')): ?>
                <a href="<?= BASE_URL ?>/import_stock.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'import_stock' ? 'active' : '' ?>">
                    <i class="fas fa-boxes w-4 text-center"></i><span>Import Stock</span>
                </a>
                <a href="<?= BASE_URL ?>/users.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'users' ? 'active' : '' ?>">
                    <i class="fas fa-user-cog w-4 text-center"></i><span>Users</span>
                </a>
                <?php endif; ?>
            </nav>

            
            <div class="px-3 py-3 border-t" style="border-color:rgba(255,255,255,.07)">
                <a href="<?= BASE_URL ?>/logout.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm" style="color:#f87171" onmouseover="this.style.background='rgba(220,38,38,.15)'" onmouseout="this.style.background=''">
                    <i class="fas fa-sign-out-alt w-4 text-center" style="color:#f87171"></i><span>Logout</span>
                </a>
            </div>
        </aside>

        
        <div class="flex-1 flex flex-col overflow-hidden">
            
            <header class="bg-white border-b border-gray-200 px-6 py-3 flex justify-between items-center" style="box-shadow:0 1px 4px rgba(0,0,0,.06)">
                <div class="flex items-center gap-3">
                    <div class="w-1 h-6 rounded-full" style="background:#026766"></div>
                    <h2 class="text-lg font-semibold text-gray-800"><?= $pageTitle ?? 'Dashboard' ?></h2>
                </div>
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg" style="background:#f0f9f9">
                        <i class="fas fa-user-circle text-sm" style="color:#026766"></i>
                        <span class="text-sm text-gray-600 font-medium"><?= htmlspecialchars($user['full_name']) ?></span>
                    </div>
                </div>
            </header>

            
            <main class="flex-1 overflow-y-auto p-6">
    <?php else: ?>
    <div class="min-h-screen" style="background:#f1f5f5">
    <?php endif; ?>
