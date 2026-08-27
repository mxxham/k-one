<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Auth.php';

$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'K-one' ?> - K-one</title>

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
                        red:    { 50:'#e6f7f7',100:'#b2e5e5',200:'#80d2d1',300:'#4dbfbe',400:'#26b1af',500:'#026766',600:'#025958',700:'#014f4e',800:'#013d3c',900:'#012d2c' },
                        purple: { 50:'#e6f7f7',100:'#b2e5e5',200:'#80d2d1',300:'#4dbfbe',400:'#26b1af',500:'#026766',600:'#025958',700:'#014f4e',800:'#013d3c',900:'#012d2c' },
                        blue:   { 50:'#e6f7f7',100:'#b2e5e5',200:'#80d2d1',300:'#4dbfbe',400:'#26b1af',500:'#026766',600:'#025958',700:'#014f4e',800:'#013d3c',900:'#012d2c' },
                    },
                    fontFamily: { sans: ['Inter','sans-serif'] }
                }
            }
        }
    </script>

    <style>
        body { font-family:'Inter',sans-serif; background:#f1f5f5; }

        .sidebar-link.active {
            background:linear-gradient(90deg,#026766 0%,#014f4e 100%) !important;
            color:#fff !important;
            box-shadow:0 2px 10px rgba(2,103,102,.3);
        }
        .sidebar-link:not(.active):hover {
            background:rgba(2,103,102,.16) !important;
            color:#e0f2f1 !important;
        }
        .table-row:hover { background-color:#f0f9f9; }
        input:focus, select:focus, textarea:focus {
            outline:none;
            border-color:#026766 !important;
            box-shadow:0 0 0 3px rgba(2,103,102,.12) !important;
        }
        .stat-card:hover { transform:translateY(-3px); }
        .wms-card { border:1px solid #e5e9e9; }
        aside::-webkit-scrollbar { width:4px; }
        aside::-webkit-scrollbar-track { background:transparent; }
        aside::-webkit-scrollbar-thumb { background:rgba(2,103,102,.35); border-radius:4px; }

        #sidebar {
            transition: transform .25s ease, box-shadow .25s ease;
        }

        #sidebarOverlay {
            display:none;
            position:fixed;
            inset:0;
            background:rgba(0,0,0,.5);
            z-index:98;
            backdrop-filter:blur(2px);
        }
        #sidebarOverlay.open { display:block; }

        #hamburger { display:none; }

        @media (max-width: 768px) {
            #sidebar {
                position:fixed !important;
                top:0;
                left:0;
                height:100% !important;
                z-index:99;
                transform:translateX(-100%);
                box-shadow:none;
            }
            #sidebar.open {
                transform:translateX(0);
                box-shadow:8px 0 32px rgba(0,0,0,.25);
            }
            #hamburger {
                display:flex;
                align-items:center;
                justify-content:center;
                width:36px;
                height:36px;
                border:none;
                background:rgba(2,103,102,.08);
                border-radius:8px;
                cursor:pointer;
                color:#026766;
                font-size:1rem;
                flex-shrink:0;
                transition:background .15s;
            }
            #hamburger:hover { background:rgba(2,103,102,.15); }
            #userInfoDesktop { display:none !important; }
            #mainWrapper { padding-left:0 !important; }
            main.p-6 { padding:12px !important; }
        }

        @media (min-width: 769px) {
            #sidebar { transform:none !important; }
            #sidebarOverlay { display:none !important; }
            #hamburger { display:none !important; }
        }
    </style>
</head>
<body style="background:#f1f5f5">

<?php
$_authUser = Auth::user();
$_isViewer = $_authUser && $_authUser['role'] === 'viewer';
?>
<?php if (Auth::check()): ?>
<div class="flex h-screen overflow-hidden">

<div id="sidebarOverlay" onclick="closeSidebar()"></div>

<aside id="sidebar" style="width:256px;background:#0d1f1f;color:#fff;flex-shrink:0;overflow-y:auto;display:flex;flex-direction:column">

    <div style="padding:20px 16px 16px;border-bottom:1px solid rgba(2,103,102,.4);display:flex;align-items:center;justify-content:space-between;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
            <div style="width:36px;height:36px;background:linear-gradient(135deg,#026766,#014f4e);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="fas fa-warehouse" style="color:#fff;font-size:.9rem"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#fff;line-height:1.2">K-one</div>
                <div style="font-size:.65rem;color:rgba(255,255,255,.45);letter-spacing:.5px">secondary administration</div>
            </div>
        </div>
        <button onclick="closeSidebar()" id="sidebarCloseBtn"
                style="display:none;background:rgba(255,255,255,.1);border:none;color:rgba(255,255,255,.7);width:30px;height:30px;border-radius:6px;cursor:pointer;font-size:.85rem;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <nav style="padding:12px 8px;flex:1;display:flex;flex-direction:column;gap:2px">

        <div style="padding:8px 8px 4px;font-size:.62rem;color:rgba(2,200,180,.5);text-transform:uppercase;letter-spacing:1px;font-weight:700">Main</div>

        <a href="<?= BASE_URL ?>/dashboard.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='dashboard'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-tachometer-alt" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Dashboard</span>
        </a>

        <a href="<?= BASE_URL ?>/inbound.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='inbound'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-truck-loading" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Inbound</span>
        </a>

        <a href="<?= BASE_URL ?>/outbound.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='outbound'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-shipping-fast" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Outbound</span>
        </a>

        <a href="<?= BASE_URL ?>/stock.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='stock'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-boxes" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Stock</span>
        </a>

        <a href="<?= BASE_URL ?>/ledger.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='ledger'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-book" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Stock Ledger</span>
        </a>

        <a href="<?= BASE_URL ?>/stocktake.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='stocktake'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-clipboard-check" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Stock Take</span>
        </a>

        <a href="<?= BASE_URL ?>/bin_transfer.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='bin_transfer'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-exchange-alt" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Bin Transfer</span>
        </a>

        <?php if (Auth::canWrite() || true): ?>
        <div style="padding:12px 8px 4px;font-size:.62rem;color:rgba(2,200,180,.5);text-transform:uppercase;letter-spacing:1px;font-weight:700;margin-top:6px">WMS Pro</div>

        <a href="<?= BASE_URL ?>/replenishment.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='replenishment'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-sync-alt" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Replenishment</span>
        </a>

        <a href="<?= BASE_URL ?>/waves.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='waves'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-wave-square" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Waves</span>
        </a>

        <a href="<?= BASE_URL ?>/asn.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='asn'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-file-invoice" style="width:16px;text-align:center;opacity:.8"></i>
            <span>ASN (Advance Notice)</span>
        </a>

        <a href="<?= BASE_URL ?>/cyclecount.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='cyclecount'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-recycle" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Cycle Count</span>
        </a>

        <a href="<?= BASE_URL ?>/putaway_tasks.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='putaway_tasks'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-arrow-circle-down" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Putaway Tasks</span>
        </a>

        <a href="<?= BASE_URL ?>/putaway_scan.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='putaway_scan'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-qrcode" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Putaway Scan</span>
        </a>

        <a href="<?= BASE_URL ?>/abc.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='abc'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-chart-line" style="width:16px;text-align:center;opacity:.8"></i>
            <span>ABC Analysis</span>
        </a>
        <?php endif; ?>

        <?php if (Auth::canWrite()): ?>
        <div style="padding:12px 8px 4px;font-size:.62rem;color:rgba(2,200,180,.5);text-transform:uppercase;letter-spacing:1px;font-weight:700;margin-top:6px">Master Data</div>

        <a href="<?= BASE_URL ?>/products.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='products'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-box" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Products</span>
        </a>

        <a href="<?= BASE_URL ?>/customers.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='customers'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-users" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Customers</span>
        </a>

        <a href="<?= BASE_URL ?>/location_master.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='location_master'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-map-marker-alt" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Locations</span>
        </a>
        <?php endif; ?>

        <div style="padding:12px 8px 4px;font-size:.62rem;color:rgba(2,200,180,.5);text-transform:uppercase;letter-spacing:1px;font-weight:700;margin-top:6px">Reports & Tools</div>

        <a href="<?= BASE_URL ?>/reports.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='reports'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-chart-bar" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Reports</span>
        </a>

        <?php if (Auth::canWrite()): ?>
        <a href="<?= BASE_URL ?>/import.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='import'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-file-import" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Import Inbound</span>
        </a>

        <a href="<?= BASE_URL ?>/import_outbound.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='import_outbound'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-file-export" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Import Outbound</span>
        </a>

        <a href="<?= BASE_URL ?>/import_auto.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='import_auto'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-magic" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Auto Import</span>
        </a>
        <?php endif; ?>

        <?php if (Auth::hasRole('admin')): ?>
        <div style="padding:12px 8px 4px;font-size:.62rem;color:rgba(2,200,180,.5);text-transform:uppercase;letter-spacing:1px;font-weight:700;margin-top:6px">Admin</div>

        <a href="<?= BASE_URL ?>/users.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='users'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-user-cog" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Users</span>
        </a>

        <a href="<?= BASE_URL ?>/activity_log.php" class="sidebar-link" onclick="autoCloseSidebar()" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.85rem;transition:all .15s <?= ($currentPage??'')==='activity_log'?';background:linear-gradient(90deg,#026766,#014f4e);color:#fff':'' ?>">
            <i class="fas fa-history" style="width:16px;text-align:center;opacity:.8"></i>
            <span>Activity Log</span>
        </a>
        <?php endif; ?>

    </nav>

    <div style="padding:12px 16px;border-top:1px solid rgba(2,103,102,.3);display:flex;align-items:center;gap:10px;flex-shrink:0">
        <div style="width:32px;height:32px;background:#026766;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-user" style="color:#fff;font-size:.75rem"></i>
        </div>
        <div style="flex:1;min-width:0">
            <div style="font-size:.78rem;font-weight:600;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($user['full_name'] ?? 'User') ?></div>
            <div style="font-size:.65rem;color:rgba(255,255,255,.4)"><?= htmlspecialchars($user['role'] ?? '') ?></div>
        </div>
        <a href="<?= BASE_URL ?>/logout.php" title="Logout"
           style="color:rgba(255,255,255,.4);text-decoration:none;font-size:.8rem;transition:color .15s"
           onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.4)'">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>
</aside>

<div class="flex-1 flex flex-col overflow-hidden">

    <header style="background:#fff;border-bottom:1px solid #e5e9e9;padding:0 16px;height:54px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;box-shadow:0 1px 6px rgba(0,0,0,.05)">
        <div style="display:flex;align-items:center;gap:10px">
            <button id="hamburger" onclick="openSidebar()" aria-label="Open menu">
                <i class="fas fa-bars"></i>
            </button>
            <div style="width:3px;height:20px;background:linear-gradient(180deg,#026766,#4dc8c7);border-radius:2px;flex-shrink:0"></div>
            <h2 style="font-size:.95rem;font-weight:700;color:#0d1f1f;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px"><?= $pageTitle ?? 'Dashboard' ?></h2>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
            <div id="userInfoDesktop" style="display:flex;align-items:center;gap:7px;padding:5px 12px;background:#f0f9f9;border-radius:8px;border:1px solid #d4eeed">
                <i class="fas fa-user-circle" style="color:#026766;font-size:.8rem"></i>
                <span style="font-size:.8rem;color:#374151;font-weight:500"><?= htmlspecialchars($user['full_name']) ?></span>
                <span style="font-size:.7rem;color:#026766;font-weight:600;background:#e0f2f1;padding:1px 7px;border-radius:10px;text-transform:capitalize"><?= htmlspecialchars($user['role'] ?? '') ?></span>
            </div>
            <a href="<?= BASE_URL ?>/logout.php" title="Logout"
               style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:#fff0f0;color:#ef4444;text-decoration:none;border:1px solid #fecaca;transition:all .15s;font-size:.85rem;flex-shrink:0"
               onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fff0f0'">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </header>

    <?php if ($_isViewer): ?>
    <div style="background:#026766;color:#fff;padding:4px 16px;font-size:.72rem;font-weight:700;text-align:center;display:flex;align-items:center;justify-content:center;gap:6px;flex-shrink:0;letter-spacing:.8px;border-bottom:1px solid #014f4e">
        <i class="fas fa-eye" style="font-size:.65rem;opacity:.85"></i>
        MODE VIEW ONLY
    </div>
    <?php endif; ?>

    <main class="flex-1 overflow-y-auto p-6">

<?php else: ?>
<div class="min-h-screen" style="background:#f1f5f5">
<?php endif; ?>

<script>
function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('sidebarOverlay').classList.add('open');
    var closeBtn = document.getElementById('sidebarCloseBtn');
    if (closeBtn) closeBtn.style.display = 'flex';
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('open');
    var closeBtn = document.getElementById('sidebarCloseBtn');
    if (closeBtn) closeBtn.style.display = 'none';
}
function autoCloseSidebar() {
    if (window.innerWidth <= 768) closeSidebar();
}
</script>
