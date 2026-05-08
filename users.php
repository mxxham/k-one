<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';

require_once __DIR__ . '/classes/ActivityLogger.php';
Auth::requireAuth();
Auth::requireRole(['admin']);

$pageTitle = 'User Management';
$currentPage = 'users';

$ROLES = [
    'admin'    => ['label' => 'Admin',    'desc' => 'Full system access including user management', 'color' => '#013d3c', 'bg' => '#e0f7f7', 'icon' => 'fa-shield-alt'],
    'operator' => ['label' => 'Operator', 'desc' => 'Create and edit all operational orders',        'color' => '#014f4e', 'bg' => '#e6f4f4', 'icon' => 'fa-edit'],
    'viewer'   => ['label' => 'Viewer',   'desc' => 'Read-only access',                              'color' => '#374151', 'bg' => '#f3f4f6', 'icon' => 'fa-eye'],
];

$success = $_GET['success'] ?? null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = db();

        if (isset($_POST['create_user'])) {
            if (empty($_POST['password'])) throw new Exception('Password is required');
            $stmt = $db->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                trim($_POST['username']),
                password_hash($_POST['password'], PASSWORD_DEFAULT),
                trim($_POST['full_name']),
                trim($_POST['email'] ?? ''),
                $_POST['role'] ?? 'viewer'
            ]);
            $newId = (int)$db->lastInsertId();
            ActivityLogger::log('CREATE_USER', 'user', 'User', $newId,
                trim($_POST['username']), "Buat user baru: " . trim($_POST['full_name']) . " (" . ($_POST['role'] ?? 'viewer') . ")");
            header('Location: users.php?success=created'); exit;
        }

        if (isset($_POST['update_user'])) {
            $sql    = "UPDATE users SET username = ?, full_name = ?, email = ?, role = ?";
            $params = [trim($_POST['username']), trim($_POST['full_name']), trim($_POST['email'] ?? ''), $_POST['role'] ?? 'viewer'];
            if (!empty($_POST['password'])) {
                $sql .= ", password = ?";
                $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }
            $sql .= " WHERE id = ?";
            $params[] = (int)$_POST['id'];
            $db->prepare($sql)->execute($params);
            ActivityLogger::log('UPDATE_USER', 'user', 'User', (int)$_POST['id'],
                trim($_POST['username']), "Edit user: " . trim($_POST['full_name']) . " → role " . ($_POST['role'] ?? 'viewer') . (!empty($_POST['password']) ? ', password diubah' : ''));
            header('Location: users.php?success=updated'); exit;
        }

        if (isset($_POST['delete_user'])) {
            $userId = (int)$_POST['id'];
            if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
                throw new Exception("You cannot delete your own account.");
            }

            $db->beginTransaction();
            try {
                
                $nullableCols = [
                    'inbound_orders'  => ['created_by', 'received_by'],
                    'outbound_orders' => ['created_by', 'shipped_by'],
                    'stock_takes'     => ['created_by'],
                    'picklists'       => ['created_by'],
                    'stock_ledger'    => ['created_by'],
                    'email_logs'      => ['created_by'],
                ];
                foreach ($nullableCols as $tbl => $cols) {
                    foreach ($cols as $col) {
                        try {
                            $db->prepare("UPDATE `$tbl` SET `$col` = NULL WHERE `$col` = ?")->execute([$userId]);
                        } catch (PDOException $e) {
                            
                        }
                    }
                }
                $delUser = $db->prepare("SELECT username, full_name FROM users WHERE id=?");
                $delUser->execute([$userId]);
                $delInfo = $delUser->fetch();
                $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
                $db->commit();
                ActivityLogger::log('DELETE_USER', 'user', 'User', $userId,
                    $delInfo['username'] ?? null, "Hapus user: " . ($delInfo['full_name'] ?? $userId));
            } catch (Exception $e) {
                $db->rollBack();
                throw new Exception("Delete failed. Run migrations/revision_002.sql first to fix FK constraints. Details: " . $e->getMessage());
            }
            header('Location: users.php?success=deleted'); exit;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$users = db()->query("SELECT id, username, full_name, email, role, created_at FROM users ORDER BY created_at DESC")->fetchAll();
require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-5">

  
  <div class="wms-banner" style="background:linear-gradient(135deg,#013d3c 0%,#013d3c 100%)">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
      <div>
        <h1><i class="fas fa-users-cog mr-2"></i>User Management</h1>
      </div>
      <button onclick="openUserModal()" class="wms-btn" style="background:#fff;color:#013d3c;font-weight:700">
        <i class="fas fa-plus"></i> New User
      </button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:16px">
      <div class="stat-pill"><div class="num"><?= count($users) ?></div><div class="lbl">Total Users</div></div>
      <?php foreach ($ROLES as $rk => $rv): ?>
      <div class="stat-pill">
        <div class="num"><?= count(array_filter($users, fn($u) => $u['role'] === $rk)) ?></div>
        <div class="lbl"><?= $rv['label'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($success): ?>
  <div class="wms-alert-success"><i class="fas fa-check-circle mr-2"></i>
    <?= match($success) { 'created'=>'User created successfully.','updated'=>'User updated.','deleted'=>'User deleted.', default=>'' } ?>
  </div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="wms-alert-error"><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  

  
  <div class="wms-card">
    <div class="wms-card-header"><i class="fas fa-list mr-2" style="color:#026766"></i> All Users</div>
    <div style="overflow-x:auto">
      <table class="wms-table">
        <thead><tr>
          <th>Username</th>
          <th>Full Name</th>
          <th>Email</th>
          <th class="tc">Access Level</th>
          <th class="tc">Created</th>
          <th class="tc">Actions</th>
        </tr></thead>
        <tbody>
          <?php foreach ($users as $u):
            $role = $ROLES[$u['role']] ?? ['label'=>$u['role'],'color'=>'#374151','bg'=>'#f3f4f6'];
            $isSelf = $u['id'] == ($_SESSION['user_id'] ?? 0);
          ?>
          <tr>
            <td><span style="font-family:monospace;font-weight:600;color:#374151"><?= htmlspecialchars($u['username']) ?></span>
              <?php if ($isSelf): ?><span style="font-size:.7rem;background:#e0f7f7;color:#014f4e;padding:1px 6px;border-radius:99px;margin-left:5px">You</span><?php endif; ?>
            </td>
            <td style="font-weight:600"><?= htmlspecialchars($u['full_name']) ?></td>
            <td style="color:#6b7280;font-size:.85rem"><?= htmlspecialchars($u['email'] ?? '-') ?></td>
            <td class="tc">
              <span style="background:<?= $role['bg'] ?>;color:<?= $role['color'] ?>;padding:4px 10px;border-radius:99px;font-size:.75rem;font-weight:700;display:inline-flex;align-items:center;gap:4px">
                <i class="fas <?= $role['icon'] ?? 'fa-user' ?>" style="font-size:.65rem"></i>
                <?= $role['label'] ?>
              </span>
            </td>
            <td class="tc" style="color:#6b7280;font-size:.82rem"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
            <td class="tc">
              <div style="display:flex;gap:6px;justify-content:center">
                <button onclick='editUser(<?= json_encode(['id'=>$u['id'],'username'=>$u['username'],'full_name'=>$u['full_name'],'email'=>$u['email']??'','role'=>$u['role']]) ?>)'
                  class="wms-btn wms-btn-ghost wms-btn-sm"><i class="fas fa-edit"></i></button>
                <?php if (!$isSelf): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete user <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?')">
                  <input type="hidden" name="id" value="<?= $u['id'] ?>">
                  <button type="submit" name="delete_user" class="wms-btn wms-btn-danger wms-btn-sm"><i class="fas fa-trash"></i></button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<div id="userModal" class="wms-modal-overlay" style="display:none">
  <div class="wms-modal" style="max-width:500px">
    <div class="wms-modal-header">
      <span id="uModalTitle">New User</span>
      <button onclick="document.getElementById('userModal').style.display='none'" style="background:none;border:none;color:#9ca3af;cursor:pointer;font-size:1.2rem"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" style="padding:24px;display:grid;gap:14px">
      <input type="hidden" name="id" id="userId">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label class="wms-label">Username <span class="wms-req">*</span></label>
          <input type="text" name="username" id="uUsername" class="wms-input" required autocomplete="off">
        </div>
        <div>
          <label class="wms-label">Full Name <span class="wms-req">*</span></label>
          <input type="text" name="full_name" id="uFullName" class="wms-input" required>
        </div>
      </div>

      <div>
        <label class="wms-label">Email</label>
        <input type="email" name="email" id="uEmail" class="wms-input">
      </div>

      <div>
        <label class="wms-label">Access Level <span class="wms-req">*</span></label>
        <select name="role" id="uRole" class="wms-input" onchange="updateRoleHint()">
          <?php foreach ($ROLES as $rk => $rv): ?>
          <option value="<?= $rk ?>"><?= $rv['label'] ?></option>
          <?php endforeach; ?>
        </select>
        <div id="roleHint" style="margin-top:5px;font-size:.78rem;color:#6b7280;padding:6px 10px;background:#f9fafb;border-radius:6px"></div>
      </div>

      <div>
        <label class="wms-label">Password <span class="wms-req" id="pwReq">*</span></label>
        <input type="password" name="password" id="uPassword" class="wms-input" autocomplete="new-password">
        <div id="pwHint" style="display:none;font-size:.75rem;color:#6b7280;margin-top:3px">Leave blank to keep current password</div>
      </div>

      <div class="wms-modal-footer">
        <button type="button" onclick="document.getElementById('userModal').style.display='none'" class="wms-btn wms-btn-ghost">Cancel</button>
        <button type="submit" id="uSaveBtn" name="create_user" class="wms-btn wms-btn-primary">
          <i class="fas fa-save mr-1"></i> Save User
        </button>
      </div>
    </form>
  </div>
</div>

<script>
const ROLES = <?= json_encode($ROLES) ?>;

function updateRoleHint() {
  const v = document.getElementById('uRole').value;
  document.getElementById('roleHint').textContent = ROLES[v]?.desc || '';
}

function openUserModal() {
  document.getElementById('uModalTitle').textContent = 'New User';
  document.getElementById('userId').value = '';
  document.getElementById('uUsername').value = '';
  document.getElementById('uFullName').value = '';
  document.getElementById('uEmail').value = '';
  document.getElementById('uRole').value = 'viewer';
  document.getElementById('uPassword').value = '';
  document.getElementById('uPassword').required = true;
  document.getElementById('pwReq').style.display = 'inline';
  document.getElementById('pwHint').style.display = 'none';
  document.getElementById('uSaveBtn').name = 'create_user';
  document.getElementById('userModal').style.display = 'flex';
  updateRoleHint();
}

function editUser(u) {
  document.getElementById('uModalTitle').textContent = 'Edit User';
  document.getElementById('userId').value = u.id;
  document.getElementById('uUsername').value = u.username;
  document.getElementById('uFullName').value = u.full_name;
  document.getElementById('uEmail').value = u.email || '';
  document.getElementById('uRole').value = u.role;
  document.getElementById('uPassword').value = '';
  document.getElementById('uPassword').required = false;
  document.getElementById('pwReq').style.display = 'none';
  document.getElementById('pwHint').style.display = 'block';
  document.getElementById('uSaveBtn').name = 'update_user';
  document.getElementById('userModal').style.display = 'flex';
  updateRoleHint();
}
updateRoleHint();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
