<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/ActivityLogger.php';
require_once __DIR__ . '/classes/SecurityAudit.php';
Auth::requireAuth();
Auth::requireRole('admin');

// Handle Excel export
if (isset($_GET['export'])) {
    $filters = [];
    if (!empty($_GET['event_type'])) $filters['event_type'] = $_GET['event_type'];
    if (!empty($_GET['user_id']))    $filters['user_id'] = (int)$_GET['user_id'];
    if (!empty($_GET['date_from']))  $filters['date_from'] = $_GET['date_from'];
    if (!empty($_GET['date_to']))    $filters['date_to'] = $_GET['date_to'];
    if (!empty($_GET['search']))     $filters['search'] = $_GET['search'];
    SecurityAudit::exportAuditLog($filters);
}

$pageTitle   = 'Security Audit';
$currentPage = 'security_audit';

// Build filters from GET params
$filters = [];
$eventType = $_GET['event_type'] ?? '';
$userId    = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
$dateFrom  = $_GET['date_from'] ?? '';
$dateTo    = $_GET['date_to'] ?? '';
$search    = $_GET['search'] ?? '';
$page      = max(1, (int)($_GET['p'] ?? 1));
$perPage   = 50;
$offset    = ($page - 1) * $perPage;

if (!empty($eventType)) $filters['event_type'] = $eventType;
if ($userId)             $filters['user_id'] = $userId;
if (!empty($dateFrom))   $filters['date_from'] = $dateFrom . ' 00:00:00';
if (!empty($dateTo))     $filters['date_to'] = $dateTo . ' 23:59:59';
if (!empty($search))     $filters['search'] = $search;

$logs  = SecurityAudit::getAuditLog($filters, $perPage, $offset);
$total = SecurityAudit::countAuditLog($filters);
$pages = max(1, ceil($total / $perPage));

$stats = SecurityAudit::getAuditStats();
$eventTypes = SecurityAudit::getDistinctEventTypes();

$db    = db();
$users = $db->query("SELECT id, full_name, username, role FROM users ORDER BY full_name")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>
<style>
.sa-page{font-family:'Inter',system-ui,sans-serif;max-width:1200px;margin:0 auto;padding:16px}
.sa-hero{background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%);border-radius:10px;padding:20px 26px;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px;box-shadow:0 4px 18px rgba(26,26,46,.3)}
.sa-hero-title{font-size:1.25rem;font-weight:700}
.sa-stat{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:7px;padding:10px 14px;text-align:center;min-width:80px}
.sa-stat .n{font-size:1.3rem;font-weight:700}
.sa-stat .l{font-size:.65rem;opacity:.7;margin-top:2px}
.sa-stat-danger .n{color:#ef5350}
.sa-stat-success .n{color:#66bb6a}
.sa-stat-warning .n{color:#ffa726}
.sa-card{background:#fff;border-radius:10px;border:1px solid #e0e0e0;box-shadow:0 2px 8px rgba(0,0,0,.05);overflow:hidden;margin-bottom:16px}
.sa-ch{padding:11px 18px;border-bottom:1px solid #e0e0e0;background:#fafafa;display:flex;align-items:center;justify-content:space-between}
.sa-ch h2{font-size:.92rem;font-weight:600;color:#0f3460}
.sa-tbl{width:100%;border-collapse:collapse;font-size:.82rem}
.sa-tbl thead tr{background:#eceff1}
.sa-tbl th{padding:8px 10px;text-align:left;font-size:.67rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#607d8b;border-bottom:2px solid #b2d8d8}
.sa-tbl td{padding:7px 10px;border-bottom:1px solid #f5f5f5;vertical-align:top}
.sa-tbl tbody tr:hover{background:#fafafa}
.sa-tbl tbody tr.sa-failed{background:#fff5f5}
.sa-badge{display:inline-block;padding:2px 8px;border-radius:14px;font-size:.67rem;font-weight:700;white-space:nowrap}
.sa-badge-login{background:#e3f2fd;color:#1565c0}
.sa-badge-logout{background:#f3e5f5;color:#7b1fa2}
.sa-badge-privilege{background:#fce4ec;color:#c62828}
.sa-badge-config{background:#fff3e0;color:#e65100}
.sa-badge-export{background:#e8f5e9;color:#2e7d32}
.sa-badge-password{background:#fff8e1;color:#f57f17}
.sa-badge-api{background:#ede7f6;color:#4527a0}
.sa-badge-data{background:#e0f2f1;color:#00695c}
.sa-badge-default{background:#eceff1;color:#37474f}
.sa-filter{display:flex;gap:8px;flex-wrap:wrap;padding:14px 18px;border-bottom:1px solid #e0e0e0;background:#fafafa;align-items:flex-end}
.sa-filter-group{display:flex;flex-direction:column;gap:3px}
.sa-filter-group label{font-size:.68rem;font-weight:600;color:#607d8b;text-transform:uppercase;letter-spacing:.3px}
.sa-sel,.sa-inp{padding:7px 10px;border:1px solid #b2d8d8;border-radius:6px;font-size:.82rem;color:#1a1a2e;background:#fff}
.sa-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;color:#fff;text-decoration:none}
.sa-btn-primary{background:#0f3460}
.sa-btn-primary:hover{background:#1a1a2e}
.sa-btn-export{background:#2e7d32}
.sa-btn-export:hover{background:#1b5e20}
.sa-btn-reset{background:#90a4ae}
.sa-btn-reset:hover{background:#78909c}
.sa-pager{display:flex;gap:6px;padding:12px 18px;align-items:center}
.sa-pager a{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none;background:#eceff1;color:#0f3460;padding:0 6px}
.sa-pager a.active{background:#0f3460;color:#fff}
.sa-pager a:hover:not(.active){background:#b2d8d8}
.sa-empty{text-align:center;padding:40px;color:#90a4ae}
.sa-success-badge{display:inline-block;padding:1px 6px;border-radius:10px;font-size:.65rem;font-weight:600}
.sa-yes{background:#e8f5e9;color:#2e7d32}
.sa-no{background:#ffebee;color:#c62828}
.sa-na{background:#f5f5f5;color:#9e9e9e}
</style>

<div class="sa-page">

<div class="sa-hero">
  <div>
    <div class="sa-hero-title"><i class="fas fa-shield-alt mr-2"></i>Security Audit Log</div>
    <div style="font-size:.72rem;opacity:.55;margin-top:2px">Forensic trail of sensitive operations and access events</div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <div class="sa-stat"><div class="n"><?=number_format($stats['total'])?></div><div class="l">Total Events</div></div>
    <div class="sa-stat sa-stat-danger"><div class="n"><?=number_format($stats['failed_logins_24h'])?></div><div class="l">Failed Logins (24h)</div></div>
    <div class="sa-stat sa-stat-success"><div class="n"><?=number_format($stats['successful_logins_24h'])?></div><div class="l">Logins (24h)</div></div>
    <div class="sa-stat sa-stat-warning"><div class="n"><?=number_format($stats['exports_24h'])?></div><div class="l">Exports (24h)</div></div>
    <div class="sa-stat"><div class="n"><?=number_format($stats['privilege_changes_24h'])?></div><div class="l">Role Changes (24h)</div></div>
  </div>
</div>

<?php if(!empty($stats['by_type'])):?>
<div class="sa-card">
  <div class="sa-ch"><h2><i class="fas fa-chart-pie mr-2" style="color:#0f3460"></i>Event Distribution</h2></div>
  <div style="padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap">
    <?php foreach($stats['by_type'] as $bt):
      $btype = $bt['event_type'];
      $badgeClass = 'sa-badge-default';
      if($btype==='login') $badgeClass='sa-badge-login';
      elseif($btype==='logout') $badgeClass='sa-badge-logout';
      elseif($btype==='privilege_change') $badgeClass='sa-badge-privilege';
      elseif($btype==='config_change') $badgeClass='sa-badge-config';
      elseif($btype==='data_export') $badgeClass='sa-badge-export';
      elseif($btype==='password_change') $badgeClass='sa-badge-password';
      elseif($btype==='api_key_usage') $badgeClass='sa-badge-api';
      elseif($btype==='data_access') $badgeClass='sa-badge-data';
    ?>
    <span class="sa-badge <?=$badgeClass?>">
      <?=ucwords(str_replace('_',' ',$btype))?>
      <strong style="margin-left:4px"><?=number_format($bt['cnt'])?></strong>
    </span>
    <?php endforeach;?>
  </div>
</div>
<?php endif;?>

<div class="sa-card">
  <form method="GET" class="sa-filter">
    <div class="sa-filter-group">
      <label>Event Type</label>
      <select name="event_type" class="sa-sel" style="min-width:150px">
        <option value="">— All Events —</option>
        <?php foreach($eventTypes as $et):?>
        <option value="<?=$et['event_type']?>" <?=$eventType===$et['event_type']?'selected':''?>>
          <?=ucwords(str_replace('_',' ',$et['event_type']))?> (<?=$et['cnt']?>)
        </option>
        <?php endforeach;?>
      </select>
    </div>
    <div class="sa-filter-group">
      <label>User</label>
      <select name="user_id" class="sa-sel" style="min-width:140px">
        <option value="">— All Users —</option>
        <?php foreach($users as $u):?>
        <option value="<?=$u['id']?>" <?=$userId==$u['id']?'selected':''?>>
          <?=htmlspecialchars($u['full_name'])?> (<?=$u['role']?>)
        </option>
        <?php endforeach;?>
      </select>
    </div>
    <div class="sa-filter-group">
      <label>Date From</label>
      <input type="date" name="date_from" class="sa-inp" value="<?=htmlspecialchars($dateFrom)?>">
    </div>
    <div class="sa-filter-group">
      <label>Date To</label>
      <input type="date" name="date_to" class="sa-inp" value="<?=htmlspecialchars($dateTo)?>">
    </div>
    <div class="sa-filter-group">
      <label>Search</label>
      <input type="text" name="search" class="sa-inp" placeholder="Username, endpoint..." value="<?=htmlspecialchars($search)?>" style="min-width:140px">
    </div>
    <button type="submit" class="sa-btn sa-btn-primary"><i class="fas fa-filter"></i> Filter</button>
    <a href="security_audit.php" class="sa-btn sa-btn-reset"><i class="fas fa-times"></i> Reset</a>
    <a href="security_audit.php?export=1<?=!empty($eventType)?'&event_type='.urlencode($eventType):''?><?=$userId?'&user_id='.$userId:''?><?=!empty($dateFrom)?'&date_from='.urlencode($dateFrom):''?><?=!empty($dateTo)?'&date_to='.urlencode($dateTo):''?><?=!empty($search)?'&search='.urlencode($search):''?>" class="sa-btn sa-btn-export" style="margin-left:auto">
      <i class="fas fa-file-excel"></i> Export Excel
    </a>
  </form>

  <?php if(empty($logs)):?>
  <div class="sa-empty"><i class="fas fa-shield-alt" style="font-size:2rem;display:block;margin-bottom:10px"></i>No audit log entries found.</div>
  <?php else:?>
  <div style="overflow-x:auto">
  <table class="sa-tbl" id="sa-table">
    <thead><tr>
      <th>Timestamp</th>
      <th>Event</th>
      <th>User</th>
      <th>Result</th>
      <th>IP Address</th>
      <th>Details</th>
      <th>Config / Role</th>
    </tr></thead>
    <tbody>
    <?php foreach($logs as $lg):
      $evType = $lg['event_type'] ?? '';
      $badgeClass = 'sa-badge-default';
      if($evType==='login') $badgeClass='sa-badge-login';
      elseif($evType==='logout') $badgeClass='sa-badge-logout';
      elseif($evType==='privilege_change') $badgeClass='sa-badge-privilege';
      elseif($evType==='config_change') $badgeClass='sa-badge-config';
      elseif($evType==='data_export') $badgeClass='sa-badge-export';
      elseif($evType==='password_change') $badgeClass='sa-badge-password';
      elseif($evType==='api_key_usage') $badgeClass='sa-badge-api';
      elseif($evType==='data_access') $badgeClass='sa-badge-data';

      $isFailed = ($evType === 'login' && ($lg['success'] ?? 1) == 0);
    ?>
    <tr class="<?=$isFailed?'sa-failed':''?>">
      <td style="white-space:nowrap;color:#607d8b;font-size:.74rem">
        <?=date('d M Y', strtotime($lg['created_at']))?>
        <div style="color:#90a4ae;font-size:.7rem"><?=date('H:i:s', strtotime($lg['created_at']))?></div>
      </td>
      <td><span class="sa-badge <?=$badgeClass?>"><?=ucwords(str_replace('_',' ',$evType))?></span></td>
      <td>
        <div style="font-weight:600;font-size:.83rem"><?=htmlspecialchars($lg['user_full_name'] ?? $lg['username'] ?? '—')?></div>
        <?php if(($lg['user_full_name'] ?? '') && ($lg['username'] ?? '') !== ($lg['user_full_name'] ?? '')):?>
        <div style="font-size:.67rem;color:#90a4ae">@<?=htmlspecialchars($lg['username'] ?? '')?></div>
        <?php endif;?>
      </td>
      <td>
        <?php if(($lg['success'] ?? null) !== null):?>
          <?php if($lg['success']):?>
            <span class="sa-success-badge sa-yes"><i class="fas fa-check"></i> OK</span>
          <?php else:?>
            <span class="sa-success-badge sa-no"><i class="fas fa-times"></i> FAIL</span>
          <?php endif;?>
        <?php else:?>
          <span class="sa-success-badge sa-na">—</span>
        <?php endif;?>
      </td>
      <td style="font-size:.74rem;color:#607d8b;font-family:monospace"><?=htmlspecialchars($lg['ip_address'] ?? '')?></td>
      <td style="font-size:.8rem;color:#37474f;max-width:280px">
        <?php if($lg['endpoint'] ?? ''):?>
          <div style="font-family:monospace;font-size:.75rem;font-weight:600;color:#0f3460">
            <?=htmlspecialchars($lg['http_method'] ?? '')?> <?=htmlspecialchars($lg['endpoint'])?>
          </div>
        <?php endif;?>
        <?php if($lg['export_type'] ?? ''):?>
          <div style="font-size:.75rem">Export: <strong><?=htmlspecialchars($lg['export_type'])?></strong> from <?=htmlspecialchars($lg['module'] ?? '')?>
            <?=($lg['record_count'] ?? 0) > 0 ? ' ('.number_format($lg['record_count']).' records)' : ''?>
            as <?=htmlspecialchars($lg['format'] ?? 'xlsx')?>
          </div>
        <?php endif;?>
        <?php if($lg['details'] ?? ''):?>
          <div style="font-size:.75rem;color:#546e7a"><?=htmlspecialchars($lg['details'])?></div>
        <?php endif;?>
        <?php if($lg['user_agent'] ?? ''):?>
          <div style="font-size:.67rem;color:#90a4ae;word-break:break-all;margin-top:2px" title="<?=htmlspecialchars($lg['user_agent'])?>">
            <?=htmlspecialchars(mb_strimwidth($lg['user_agent'], 0, 60, '...'))?>
          </div>
        <?php endif;?>
      </td>
      <td style="font-size:.78rem">
        <?php if($lg['config_key'] ?? ''):?>
          <div style="font-weight:600;color:#e65100"><?=htmlspecialchars($lg['config_key'])?></div>
          <?php if($lg['old_value'] ?? ''):?>
          <div style="font-size:.7rem;color:#9e9e9e;text-decoration:line-through">Old: <?=htmlspecialchars(mb_strimwidth($lg['old_value'], 0, 40, '...'))?></div>
          <?php endif;?>
          <?php if($lg['new_value'] ?? ''):?>
          <div style="font-size:.7rem;color:#2e7d32">New: <?=htmlspecialchars(mb_strimwidth($lg['new_value'], 0, 40, '...'))?></div>
          <?php endif;?>
        <?php endif;?>
        <?php if($lg['old_role'] ?? ''):?>
          <div style="font-size:.75rem">
            <span style="color:#c62828;text-decoration:line-through"><?=htmlspecialchars($lg['old_role'])?></span>
            <i class="fas fa-arrow-right" style="font-size:.6rem;margin:0 3px;color:#90a4ae"></i>
            <span style="color:#2e7d32;font-weight:600"><?=htmlspecialchars($lg['new_role'])?></span>
          </div>
        <?php endif;?>
        <?php if($lg['target_user_id'] ?? ''):?>
          <div style="font-size:.68rem;color:#90a4ae">Target: User #<?=htmlspecialchars($lg['target_user_id'])?></div>
        <?php endif;?>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  </div>

  <?php if($pages > 1):?>
  <div class="sa-pager">
    <?php if($page>1):?><a href="?p=<?=$page-1?>&event_type=<?=urlencode($eventType)?>&user_id=<?=$userId?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>&search=<?=urlencode($search)?>"><i class="fas fa-chevron-left"></i></a><?php endif;?>
    <?php
    $start = max(1, $page-3);
    $end   = min($pages, $page+3);
    for($p=$start;$p<=$end;$p++):
    ?>
    <a href="?p=<?=$p?>&event_type=<?=urlencode($eventType)?>&user_id=<?=$userId?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>&search=<?=urlencode($search)?>" class="<?=$p===$page?'active':''?>"><?=$p?></a>
    <?php endfor;?>
    <?php if($page<$pages):?><a href="?p=<?=$page+1?>&event_type=<?=urlencode($eventType)?>&user_id=<?=$userId?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>&search=<?=urlencode($search)?>"><i class="fas fa-chevron-right"></i></a><?php endif;?>
    <span style="margin-left:auto;font-size:.78rem;color:#90a4ae">Hal <?=$page?> dari <?=$pages?> &middot; <?=number_format($total)?> entri</span>
  </div>
  <?php endif;?>
  <?php endif;?>
</div>

</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
