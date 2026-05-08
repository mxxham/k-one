<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/ActivityLogger.php';
Auth::requireAuth();
Auth::requireRole('admin');

// API mode: return new logs as JSON since a given id
if (isset($_GET['api'])) {
    $sinceId = (int)($_GET['since_id'] ?? 0);
    $db = db();
    $stmt = $db->prepare("
        SELECT al.*, u.role AS user_role
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE al.id > ?
        ORDER BY al.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$sinceId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(['rows' => $rows, 'ts' => date('H:i:s')]);
    exit;
}

$pageTitle   = 'Activity Log';
$currentPage = 'activity_log';

$module  = $_GET['module']  ?? '';
$userId  = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$logs  = ActivityLogger::getRecent($perPage, $offset, $module ?: null, $userId ?: null);
$total = ActivityLogger::countRecent($module ?: null, $userId ?: null);
$pages = max(1, ceil($total / $perPage));

$db    = db();
$users = $db->query("SELECT id, full_name, username, role FROM users ORDER BY full_name")->fetchAll();

$modStats = $db->query("SELECT module, COUNT(*) AS cnt FROM activity_log GROUP BY module ORDER BY cnt DESC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>
<style>
.al-page{font-family:'Segoe UI',system-ui,sans-serif;max-width:1150px;margin:0 auto;padding:16px}
.al-hero{background:linear-gradient(135deg,#013d3c 0%,#026766 100%);border-radius:10px;padding:20px 26px;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px;box-shadow:0 4px 18px rgba(38,50,56,.2)}
.al-hero-title{font-size:1.25rem;font-weight:700}
.al-stat{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:7px;padding:8px 14px;text-align:center}
.al-stat .n{font-size:1.2rem;font-weight:700}
.al-stat .l{font-size:.67rem;opacity:.75;margin-top:1px}
.al-card{background:#fff;border-radius:10px;border:1px solid #e0e0e0;box-shadow:0 2px 8px rgba(0,0,0,.05);overflow:hidden;margin-bottom:16px}
.al-ch{padding:11px 18px;border-bottom:1px solid #e0e0e0;background:#fafafa;display:flex;align-items:center;justify-content:space-between}
.al-ch h2{font-size:.92rem;font-weight:600;color:#026766}
.al-tbl{width:100%;border-collapse:collapse;font-size:.84rem}
.al-tbl thead tr{background:#eceff1}
.al-tbl th{padding:8px 12px;text-align:left;font-size:.68rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#607d8b;border-bottom:2px solid #b2d8d8}
.al-tbl td{padding:8px 12px;border-bottom:1px solid #f5f5f5;vertical-align:top}
.al-tbl tbody tr:hover{background:#fafafa}
.al-badge{display:inline-block;padding:2px 8px;border-radius:14px;font-size:.67rem;font-weight:700}
.al-mod-inbound{background:#e0f7f7;color:#013d3c}
.al-mod-outbound{background:#e0f7f7;color:#013d3c}
.al-mod-bin_transfer{background:#e0f2f1;color:#013d3c}
.al-mod-stock{background:#fff8e1;color:#e65100}
.al-mod-user{background:#e0f7f7;color:#013d3c}
.al-filter{display:flex;gap:10px;flex-wrap:wrap;padding:14px 18px;border-bottom:1px solid #e0e0e0;background:#fafafa}
.al-sel,.al-inp{padding:6px 10px;border:1px solid #b2d8d8;border-radius:6px;font-size:.82rem;color:#026766}
.al-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;background:#026766;color:#fff;text-decoration:none}
.al-btn:hover{background:#013d3c}
.al-pager{display:flex;gap:6px;padding:12px 18px;align-items:center}
.al-pager a{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none;background:#eceff1;color:#026766}
.al-pager a.active{background:#026766;color:#fff}
.al-pager a:hover:not(.active){background:#b2d8d8}
.al-empty{text-align:center;padding:40px;color:#90a4ae}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
@keyframes fadeIn{from{background:#d0f5e8}to{background:transparent}}
.al-new{animation:fadeIn 2.5s ease forwards}
</style>

<div class="al-page">

<div class="al-hero">
  <div>
    <div class="al-hero-title"><i class="fas fa-history mr-2"></i>Activity Log
      <span id="live-badge" style="display:inline-flex;align-items:center;gap:4px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:20px;padding:2px 10px;font-size:.65rem;font-weight:600;margin-left:8px;vertical-align:middle">
        <span id="live-dot" style="width:6px;height:6px;border-radius:50%;background:#4cff91;animation:blink 1.4s infinite"></span> LIVE
      </span>
    </div>
    <div style="font-size:.75rem;opacity:.6;margin-top:2px"><span id="last-update">—</span></div>
  </div>
  <div style="display:flex;gap:9px;flex-wrap:wrap">
    <div class="al-stat"><div class="n"><?=number_format($total)?></div><div class="l">Total Log</div></div>
    <?php foreach($modStats as $ms):
      $mc = str_replace('_','-',$ms['module']);
    ?>
    <div class="al-stat"><div class="n"><?=number_format($ms['cnt'])?></div><div class="l"><?=ucfirst($ms['module'])?></div></div>
    <?php endforeach;?>
  </div>
</div>

<div class="al-card">
  <form method="GET" class="al-filter">
    <select name="module" class="al-sel">
      <option value="">— Semua Modul —</option>
      <option value="inbound"      <?=$module==='inbound'?'selected':''?>>📥 Inbound</option>
      <option value="outbound"     <?=$module==='outbound'?'selected':''?>>📤 Outbound</option>
      <option value="bin_transfer" <?=$module==='bin_transfer'?'selected':''?>>🔄 Bin Transfer</option>
      <option value="stock"        <?=$module==='stock'?'selected':''?>>📦 Stock</option>
      <option value="user"         <?=$module==='user'?'selected':''?>>👤 User</option>
    </select>
    <select name="user_id" class="al-sel">
      <option value="">— Semua User —</option>
      <?php foreach($users as $u):?>
      <option value="<?=$u['id']?>" <?=$userId==$u['id']?'selected':''?>>
        <?=htmlspecialchars($u['full_name'])?> (<?=$u['role']?>)
      </option>
      <?php endforeach;?>
    </select>
    <button type="submit" class="al-btn"><i class="fas fa-filter"></i> Filter</button>
    <a href="activity_log.php" class="al-btn" style="background:#90a4ae"><i class="fas fa-times"></i> Reset</a>
    <span style="margin-left:auto;font-size:.8rem;color:#90a4ae;align-self:center">
      <?=number_format($total)?> entri <?=$module?"· modul: <strong>$module</strong>":''?>
    </span>
  </form>

  <?php if(empty($logs)):?>
  <div class="al-empty"><i class="fas fa-history" style="font-size:2rem;display:block;margin-bottom:10px"></i>Belum ada log.</div>
  <?php else:?>
  <div style="overflow-x:auto">
  <table class="al-tbl" id="al-table">
    <thead><tr>
      <th>Waktu</th>
      <th>User</th>
      <th>Modul</th>
      <th>Aksi</th>
      <th>Referensi</th>
      <th>Keterangan</th>
      <th>IP</th>
    </tr></thead>
    <tbody>
    <?php foreach($logs as $lg):
      $mod = $lg['module'] ?? 'other';
      $modClass = 'al-mod-' . str_replace('_','-', $mod);
    ?>
    <tr data-id="<?= (int)$lg['id'] ?>">
      <td style="white-space:nowrap;color:#90a4ae;font-size:.75rem">
        <?=date('d M Y', strtotime($lg['created_at']))?>
        <div style="color:#80b2b2"><?=date('H:i:s', strtotime($lg['created_at']))?></div>
      </td>
      <td>
        <div style="font-weight:600;font-size:.83rem"><?=htmlspecialchars($lg['full_name']??$lg['username']??'—')?></div>
        <?php if($lg['user_role']??''):?>
        <div style="font-size:.68rem;color:#90a4ae"><?=$lg['user_role']?></div>
        <?php endif;?>
      </td>
      <td>
        <span class="al-badge <?=$modClass?>"><?=ucfirst($mod)?></span>
      </td>
      <td style="font-size:.8rem;font-weight:600;white-space:nowrap">
        <?=ActivityLogger::actionLabel($lg['action']??'')?>
      </td>
      <td>
        <?php if($lg['reference_no']??''):?>
        <div style="font-family:monospace;font-size:.78rem;font-weight:600;color:#026766">
          <?=htmlspecialchars($lg['reference_no'])?>
        </div>
        <?php endif;?>
        <?php if($lg['reference_type']??''):?>
        <div style="font-size:.68rem;color:#90a4ae"><?=htmlspecialchars($lg['reference_type'])?> #<?=$lg['reference_id']??''?></div>
        <?php endif;?>
      </td>
      <td style="font-size:.8rem;color:#546e7a;max-width:280px"><?=htmlspecialchars($lg['description']??'')?></td>
      <td style="font-size:.72rem;color:#80b2b2"><?=htmlspecialchars($lg['ip_address']??'')?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  </div>

  
  <?php if($pages > 1):?>
  <div class="al-pager">
    <?php if($page>1):?><a href="?p=<?=$page-1?>&module=<?=urlencode($module)?>&user_id=<?=$userId?>"><i class="fas fa-chevron-left"></i></a><?php endif;?>
    <?php
    $start = max(1, $page-3);
    $end   = min($pages, $page+3);
    for($p=$start;$p<=$end;$p++):
    ?>
    <a href="?p=<?=$p?>&module=<?=urlencode($module)?>&user_id=<?=$userId?>" class="<?=$p===$page?'active':''?>"><?=$p?></a>
    <?php endfor;?>
    <?php if($page<$pages):?><a href="?p=<?=$page+1?>&module=<?=urlencode($module)?>&user_id=<?=$userId?>"><i class="fas fa-chevron-right"></i></a><?php endif;?>
    <span style="margin-left:auto;font-size:.78rem;color:#90a4ae">Hal <?=$page?> dari <?=$pages?></span>
  </div>
  <?php endif;?>
  <?php endif;?>
</div>

</div>
<script>

(function(){
  // find highest id currently on page
  function maxId(){
    var rows = document.querySelectorAll('#al-table tbody tr[data-id]');
    var m = 0;
    rows.forEach(function(r){ var v=parseInt(r.dataset.id)||0; if(v>m) m=v; });
    return m;
  }

  var lastId = maxId();

  function buildRow(lg){
    var mod = (lg.module||'other').replace(/_/g,'-');
    var dt  = lg.created_at ? lg.created_at.replace('T',' ') : '';
    var d='',t='';
    if(dt){ var p=dt.split(' '); d=p[0]||''; t=p[1]||''; }
    // format date dd Mon YYYY
    var months=['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    if(d){ var dp=d.split('-'); d=dp[2]+' '+months[parseInt(dp[1])-1]+' '+dp[0]; }

    var action = (lg.action||'').replace(/_/g,' ').toLowerCase().replace(/\b\w/g,c=>c.toUpperCase());

    return '<tr data-id="'+parseInt(lg.id)+'" class="al-new">'
      +'<td style="white-space:nowrap;color:#90a4ae;font-size:.75rem">'+d+'<div style="color:#80b2b2">'+t+'</div></td>'
      +'<td><div style="font-weight:600;font-size:.83rem">'+(lg.full_name||lg.username||'—')+'</div>'+(lg.user_role?'<div style="font-size:.68rem;color:#90a4ae">'+lg.user_role+'</div>':'')+'</td>'
      +'<td><span class="al-badge al-mod-'+mod+'">'+((lg.module||'other').charAt(0).toUpperCase()+(lg.module||'other').slice(1))+'</span></td>'
      +'<td style="font-size:.8rem;font-weight:600;white-space:nowrap">'+action+'</td>'
      +'<td>'+(lg.reference_no?'<div style="font-family:monospace;font-size:.78rem;font-weight:600;color:#026766">'+lg.reference_no+'</div>':'')
            +(lg.reference_type?'<div style="font-size:.68rem;color:#90a4ae">'+lg.reference_type+' #'+(lg.reference_id||'')+'</div>':'')+'</td>'
      +'<td style="font-size:.8rem;color:#546e7a;max-width:280px">'+(lg.description||'')+'</td>'
      +'<td style="font-size:.72rem;color:#80b2b2">'+(lg.ip_address||'')+'</td>'
      +'</tr>';
  }

  function poll(){
    fetch('activity_log.php?api=1&since_id='+lastId)
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(data.rows && data.rows.length){
          var tbody = document.querySelector('#al-table tbody');
          if(tbody){
            // remove empty placeholder if present
            var empty = document.querySelector('.al-empty');
            if(empty) empty.remove();

            var html = '';
            data.rows.forEach(function(row){ html += buildRow(row); });
            tbody.insertAdjacentHTML('afterbegin', html);
            lastId = maxId();
          }
        }
        var el = document.getElementById('last-update');
        if(el) el.textContent = 'Update terakhir: '+data.ts+' WIB';
      })
      .catch(function(){
        var el = document.getElementById('live-dot');
        if(el) el.style.background='#ff6b6b';
      });
  }

  // init last-update text
  var el = document.getElementById('last-update');
  if(el) el.textContent = 'Auto-refresh setiap 20 detik';

  setInterval(poll, 20000);
  // also poll once after 3s to catch any immediate new entries
  setTimeout(poll, 3000);
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
