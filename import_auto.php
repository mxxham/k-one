<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';

Auth::requireAuth();
Auth::requireRole(['admin', 'operator']);

$pageTitle = 'Auto Import';
$currentPage = 'import_auto';

require_once __DIR__ . '/includes/header.php';
?>
<style>
.drop-zone{border:3px dashed #026766;border-radius:16px;padding:48px;text-align:center;cursor:pointer;transition:.2s;background:#f0fbfb}
.drop-zone:hover,.drop-zone.drag{border-color:#5b21b6;background:#e0f7f7}
.drop-zone.has-file{border-color:#059669;background:#e6f7f7}
.sheet-chip{display:inline-block;background:#e6f7f7;border:1px solid #b2dfdb;color:#013d3c;border-radius:14px;padding:3px 12px;font-size:.72rem;font-weight:600;margin:3px 4px 0 0}
.sheet-chip.skip{background:#f3f4f6;border-color:#e5e7eb;color:#6b7280}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:20px}
</style>

<div class="space-y-5">

  <div style="background:linear-gradient(135deg,#013d3c 0%,#026766 100%);border-radius:16px;padding:28px 32px;color:#fff;box-shadow:0 4px 20px rgba(2,103,102,.35)">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
      <div>
        <h1 style="font-size:1.75rem;font-weight:800;margin-bottom:4px"><i class="fas fa-magic mr-2"></i>Auto Import</h1>
        <p style="opacity:.75;font-size:.875rem">Satu file Excel &rarr; Master data, Stock, Inbound (GR), &amp; Outbound otomatis</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="inbound.php" style="background:rgba(255,255,255,.2);color:#fff;padding:8px 14px;border-radius:8px;font-size:.8rem;text-decoration:none;display:inline-flex;align-items:center;gap:6px"><i class="fas fa-arrow-right"></i> Inbound</a>
        <a href="outbound.php" style="background:rgba(255,255,255,.2);color:#fff;padding:8px 14px;border-radius:8px;font-size:.8rem;text-decoration:none;display:inline-flex;align-items:center;gap:6px"><i class="fas fa-arrow-right"></i> Outbound</a>
        <a href="stock.php" style="background:rgba(255,255,255,.2);color:#fff;padding:8px 14px;border-radius:8px;font-size:.8rem;text-decoration:none;display:inline-flex;align-items:center;gap:6px"><i class="fas fa-arrow-right"></i> Stock</a>
      </div>
    </div>
  </div>

  <div style="background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:20px 24px">
    <h2 style="font-size:.95rem;font-weight:700;margin-bottom:10px;color:#013d3c"><i class="fas fa-th-list mr-2"></i>Sheet yang diproses otomatis</h2>
    <div style="line-height:1.9">
      <span class="sheet-chip">master data → Produk</span>
      <span class="sheet-chip">WMS → Stock + Inbound (group by GR date)</span>
      <span class="sheet-chip">data putaway → Stock</span>
      <span class="sheet-chip">schedule of the day → Outbound (FEFO)</span>
      <span class="sheet-chip skip">data level A / summary / SAP vs unrest / picking → dilewati</span>
    </div>
  </div>

  <div style="background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:24px">
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px">
      <span style="background:#013d3c;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem">1</span>
      Upload File Excel (boleh multi-sheet)
    </h2>

    <div id="dropZone" class="drop-zone" onclick="document.getElementById('excelFile').click()">
      <input type="file" id="excelFile" accept=".xlsx,.xls" style="display:none" onchange="fileSelected(this)">
      <i class="fas fa-cloud-upload-alt" style="font-size:3rem;color:#026766;margin-bottom:12px;display:block"></i>
      <p style="font-size:1.1rem;font-weight:600;color:#4b5563">Drag & drop atau klik untuk pilih file</p>
      <p style="color:#9ca3af;font-size:.875rem;margin-top:4px">Format: .xlsx atau .xls • Max 10MB</p>
    </div>

    <div id="fileInfo" style="display:none;background:#e6f7f7;border-radius:8px;padding:12px 16px;margin-top:12px;align-items:center;justify-content:space-between">
      <div style="display:flex;align-items:center;gap:10px">
        <i class="fas fa-file-excel" style="color:#059669;font-size:1.5rem"></i>
        <div>
          <div id="fileName" style="font-weight:600;color:#111827"></div>
          <div id="fileSize" style="font-size:.8rem;color:#6b7280"></div>
        </div>
      </div>
      <button onclick="clearFile()" style="background:none;border:none;color:#026766;cursor:pointer;font-size:.875rem"><i class="fas fa-times"></i> Hapus</button>
    </div>

    <button id="importBtn" onclick="startImport()" disabled style="width:100%;margin-top:16px;padding:14px;background:linear-gradient(135deg,#013d3c,#026766);color:#fff;border:none;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;opacity:.5">
      <i class="fas fa-magic mr-2"></i> Proses Auto Import Sekali Jalan
    </button>
  </div>

  <div id="progressSection" style="display:none;background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:24px">
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px"><i class="fas fa-spinner fa-spin mr-2" style="color:#013d3c"></i>Memproses semua sheet...</h2>
    <div style="background:#f3f4f6;border-radius:8px;height:12px;overflow:hidden">
      <div id="progressBar" style="background:linear-gradient(90deg,#013d3c,#026766);height:100%;width:0%;transition:.3s;border-radius:8px"></div>
    </div>
    <p id="progressText" style="margin-top:8px;font-size:.875rem;color:#6b7280;text-align:center">Membaca file...</p>
  </div>

  <div id="resultsSection" style="display:none;background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:24px">
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px;color:#013d3c"><i class="fas fa-check-circle mr-2"></i>Auto Import Selesai</h2>
    <div id="statsCards" class="stat-grid"></div>
    <div id="skippedSheets" style="margin-bottom:12px;font-size:.8rem;color:#6b7280"></div>
    <div id="resultsLog" style="max-height:340px;overflow-y:auto;background:#f9fafb;border-radius:8px;padding:12px;font-size:.78rem;font-family:monospace;color:#374151"></div>
    <div style="margin-top:16px;display:flex;gap:10px">
      <button onclick="resetImport()" style="flex:1;background:linear-gradient(135deg,#013d3c,#026766);color:#fff;border:none;padding:10px;border-radius:8px;cursor:pointer;font-weight:700"><i class="fas fa-redo mr-2"></i>Import Lagi</button>
    </div>
  </div>
</div>

<script>
let selectedFile = null;

const dz = document.getElementById('dropZone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag'); });
dz.addEventListener('dragleave', () => dz.classList.remove('drag'));
dz.addEventListener('drop', e => {
  e.preventDefault(); dz.classList.remove('drag');
  const f = e.dataTransfer.files[0];
  if (f && (f.name.endsWith('.xlsx') || f.name.endsWith('.xls'))) setFile(f);
  else alert('Pilih file .xlsx atau .xls');
});

function fileSelected(input) { if (input.files[0]) setFile(input.files[0]); }

function setFile(file) {
  selectedFile = file;
  document.getElementById('fileName').textContent = file.name;
  document.getElementById('fileSize').textContent = (file.size/1024).toFixed(1) + ' KB';
  document.getElementById('fileInfo').style.display = 'flex';
  document.getElementById('dropZone').classList.add('has-file');
  document.getElementById('importBtn').disabled = false;
  document.getElementById('importBtn').style.opacity = '1';
}

function clearFile() {
  selectedFile = null;
  document.getElementById('excelFile').value = '';
  document.getElementById('fileInfo').style.display = 'none';
  document.getElementById('dropZone').classList.remove('has-file');
  document.getElementById('importBtn').disabled = true;
  document.getElementById('importBtn').style.opacity = '.5';
}

async function startImport() {
  if (!selectedFile) return;
  document.getElementById('progressSection').style.display = 'block';
  document.getElementById('importBtn').disabled = true;
  document.getElementById('progressBar').style.width = '20%';
  document.getElementById('progressText').textContent = 'Mengirim file...';

  const fd = new FormData();
  fd.append('excel_file', selectedFile);

  try {
    document.getElementById('progressBar').style.width = '50%';
    document.getElementById('progressText').textContent = 'Memproses semua sheet (master, stock, inbound, outbound)...';

    const resp = await fetch('api/index.php?module=import&action=auto', { method: 'POST', body: fd });
    const data = await resp.json();

    document.getElementById('progressBar').style.width = '100%';
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('resultsSection').style.display = 'block';

    if (data.success && data.stats) {
      const s = data.stats;
      document.getElementById('statsCards').innerHTML = `
        <div style="background:#e6f7f7;border-radius:8px;padding:14px;text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:#013d3c">${s.products_created||0}</div>
          <div style="font-size:.78rem;color:#6b7280">Produk Baru</div>
        </div>
        <div style="background:#e0f7f7;border-radius:8px;padding:14px;text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:#013d3c">${s.stock_imported||0}</div>
          <div style="font-size:.78rem;color:#6b7280">Stok Diimport</div>
        </div>
        <div style="background:#e6f7f7;border-radius:8px;padding:14px;text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:#013d3c">${s.inbound_orders||0}</div>
          <div style="font-size:.78rem;color:#6b7280">Inbound (GR)</div>
        </div>
        <div style="background:#e0f7f7;border-radius:8px;padding:14px;text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:#013d3c">${s.outbound_orders||0}</div>
          <div style="font-size:.78rem;color:#6b7280">Outbound</div>
        </div>
      `;
      const skipped = (s.skipped_sheets||[]).map(n => `<span class="sheet-chip skip">${n}</span>`).join('');
      document.getElementById('skippedSheets').innerHTML = skipped ? '<b>Sheet dilewati:</b> ' + skipped : '';
      document.getElementById('resultsLog').innerHTML = (data.log||[]).map(l => `<div style="padding:2px 0;border-bottom:1px solid #e5e7eb">${l}</div>`).join('');
    } else {
      document.getElementById('statsCards').innerHTML = `<div style="grid-column:1/-1;background:#ffebee;border-radius:8px;padding:14px;color:#c62828"><i class="fas fa-exclamation-triangle mr-2"></i>${data.message || 'Import gagal'}</div>`;
    }
  } catch (err) {
    document.getElementById('progressSection').style.display = 'none';
    alert('Error: ' + err.message);
  }
}

function resetImport() {
  clearFile();
  document.getElementById('resultsSection').style.display = 'none';
  document.getElementById('progressBar').style.width = '0%';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
