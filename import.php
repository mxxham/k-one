<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';

Auth::requireRole(['admin', 'operator']);

$pageTitle = 'Import Inbound';
$currentPage = 'import';

require_once __DIR__ . '/includes/header.php';

?>
<style>
.drop-zone{border:3px dashed #026766;border-radius:16px;padding:48px;text-align:center;cursor:pointer;transition:.2s;background:#f0fbfb}
.drop-zone:hover,.drop-zone.drag{border-color:#5b21b6;background:#e0f7f7}
.drop-zone.has-file{border-color:#059669;background:#e6f7f7}
</style>

<div class="space-y-5">

  <div style="background:linear-gradient(135deg,#013d3c 0%,#026766 100%);border-radius:16px;padding:28px 32px;color:#fff;box-shadow:0 4px 20px rgba(2,103,102,.35)">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
      <div>
        <h1 style="font-size:1.75rem;font-weight:800;margin-bottom:4px"><i class="fas fa-file-import mr-2"></i>Import Inbound Orders</h1>
        <p style="opacity:.8;font-size:.875rem">Bulk import inbound orders dari file Excel (.xlsx / .xls)</p>
      </div>
      <a href="inbound.php" style="background:rgba(255,255,255,.2);color:#fff;padding:8px 18px;border-radius:8px;font-size:.875rem;text-decoration:none;display:inline-flex;align-items:center;gap:7px">
        <i class="fas fa-arrow-left"></i> Back to Inbound
      </a>
    </div>
  </div>

  <!-- Step 1: Template -->
  <div style="background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:24px">
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px">
      <span style="background:#013d3c;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem">1</span>
      Download Template Excel
    </h2>
    <a href="import_template_excel.php?type=inbound"
       style="background:#1b7a34;color:#fff;padding:10px 20px;border-radius:8px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:8px">
      <i class="fas fa-file-excel"></i> Download Template
    </a>
  </div>

  <!-- Step 2: Upload -->
  <div style="background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:24px">
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px">
      <span style="background:#013d3c;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem">2</span>
      Upload File Excel
    </h2>

    <div id="dropZone" class="drop-zone" onclick="document.getElementById('excelFile').click()">
      <input type="file" id="excelFile" accept=".xlsx,.xls,.csv" style="display:none" onchange="fileSelected(this)">
      <i class="fas fa-cloud-upload-alt" style="font-size:3rem;color:#026766;margin-bottom:12px;display:block"></i>
      <p style="font-size:1.1rem;font-weight:600;color:#4b5563">Drag & drop atau klik untuk pilih file</p>
      <p style="color:#9ca3af;font-size:.875rem;margin-top:4px">Format: .xlsx, .xls, atau .csv • Max 10MB</p>
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

    <!-- Carrier -->
    <div style="margin-top:16px">
      <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:6px">
        <i class="fas fa-truck-moving" style="margin-right:6px;color:#026766"></i>Carrier / Transporter
      </label>
      <input type="text" id="carrierName" placeholder="e.g. PT Maju Jaya Logistics"
             style="width:100%;padding:9px 12px;border:1.5px solid #80d2d2;border-radius:8px;font-size:.875rem;color:#1f2937;box-sizing:border-box">
    </div>

    <!-- Validation Options -->
    <div style="background:#f9fafb;border-radius:8px;padding:16px;margin-top:12px">
      <p style="font-size:.875rem;font-weight:600;color:#374151;margin-bottom:10px">Validation Options</p>
      <div style="display:flex;flex-wrap:wrap;gap:16px">
        <label style="display:flex;align-items:center;gap:8px;font-size:.875rem;cursor:pointer">
          <input type="checkbox" id="optValidateQty" checked style="width:16px;height:16px">
          Validate quantity limits (max SKU 44, max trans 80)
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:.875rem;cursor:pointer">
          <input type="checkbox" id="optAutoCalcPallet" checked style="width:16px;height:16px">
          Auto-calculate pallets
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:.875rem;cursor:pointer">
          <input type="checkbox" id="optContinueOnError" style="width:16px;height:16px">
          Continue on error (skip invalid rows)
        </label>
      </div>
    </div>

    <button id="importBtn" onclick="startImport()" disabled
            style="width:100%;margin-top:16px;padding:14px;background:linear-gradient(135deg,#013d3c,#026766);color:#fff;border:none;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;opacity:.5">
      <i class="fas fa-file-import mr-2"></i> Upload & Import
    </button>
  </div>

  <!-- Progress -->
  <div id="progressSection" style="display:none;background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:24px">
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px"><i class="fas fa-spinner fa-spin mr-2" style="color:#013d3c"></i>Mengimport...</h2>
    <div style="background:#f3f4f6;border-radius:8px;height:12px;overflow:hidden">
      <div id="progressBar" style="background:linear-gradient(90deg,#013d3c,#026766);height:100%;width:0%;transition:.3s;border-radius:8px"></div>
    </div>
    <p id="progressText" style="margin-top:8px;font-size:.875rem;color:#6b7280;text-align:center">Membaca file...</p>
  </div>

  <!-- Results -->
  <div id="resultsSection" style="display:none;background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:24px">
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px;color:#013d3c"><i class="fas fa-check-circle mr-2"></i>Import Selesai</h2>
    <div id="statsCards" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px"></div>
    <div id="resultsLog" style="max-height:300px;overflow-y:auto;background:#f9fafb;border-radius:8px;padding:12px;font-size:.8rem;font-family:monospace;color:#374151"></div>
    <div style="margin-top:16px;display:flex;gap:10px">
      <a href="inbound.php" target="_blank" style="flex:1;background:#013d3c;color:#fff;padding:10px;border-radius:8px;text-align:center;text-decoration:none;font-weight:600"><i class="fas fa-external-link-alt mr-2"></i>Lihat Inbound</a>
      <button onclick="resetImport()" style="flex:1;background:linear-gradient(135deg,#013d3c,#026766);color:#fff;border:none;padding:10px;border-radius:8px;cursor:pointer;font-weight:700"><i class="fas fa-file-import mr-2"></i>Import Lagi</button>
    </div>
  </div>


</div>

<script>
let selectedFile = null;

const dz = document.getElementById('dropZone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag'); });
dz.addEventListener('dragleave', () => dz.classList.remove('drag'));
dz.addEventListener('drop', e => {
  e.preventDefault();
  dz.classList.remove('drag');
  const f = e.dataTransfer.files[0];
  if (f && (f.name.endsWith('.xlsx') || f.name.endsWith('.xls') || f.name.endsWith('.csv'))) {
    setFile(f);
  } else {
    alert('Pilih file .xlsx, .xls, atau .csv');
  }
});

function fileSelected(input) {
  if (input.files[0]) setFile(input.files[0]);
}

function setFile(file) {
  selectedFile = file;
  document.getElementById('fileName').textContent = file.name;
  document.getElementById('fileSize').textContent = (file.size / 1024).toFixed(1) + ' KB';
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

  const carrierName = (document.getElementById('carrierName')?.value || '').trim();
  const fd = new FormData();
  fd.append('excel_file', selectedFile, selectedFile.name);
  fd.append('carrier_name', carrierName);
  fd.append('validate_qty', document.getElementById('optValidateQty').checked ? '1' : '');
  fd.append('auto_calc_pallet', document.getElementById('optAutoCalcPallet').checked ? '1' : '');
  fd.append('continue_on_error', document.getElementById('optContinueOnError').checked ? '1' : '');

  try {
    document.getElementById('progressBar').style.width = '50%';
    document.getElementById('progressText').textContent = 'Memproses data...';

    const resp = await fetch('import_process.php', { method: 'POST', body: fd });
    const data = await resp.json();

    document.getElementById('progressBar').style.width = '100%';
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('resultsSection').style.display = 'block';

    if (data.success) {
      const s = data.stats || {};
      document.getElementById('statsCards').innerHTML = `
        <div style="background:#e6f7f7;border-radius:8px;padding:14px;text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:#013d3c">${s.items_imported ?? data.processed ?? 0}</div>
          <div style="font-size:.8rem;color:#6b7280">Items Diimport</div>
        </div>
        <div style="background:#fef9c3;border-radius:8px;padding:14px;text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:#854d0e">${s.rows_skipped ?? 0}</div>
          <div style="font-size:.8rem;color:#6b7280">Baris Dilewati</div>
        </div>
        <div style="background:${(data.errors?.length || s.errors) ? '#fee2e2' : '#e6f7f7'};border-radius:8px;padding:14px;text-align:center">
          <div style="font-size:1.8rem;font-weight:800;color:${(data.errors?.length || s.errors) ? '#991b1b' : '#013d3c'}">${data.errors?.length ?? s.errors ?? 0}</div>
          <div style="font-size:.8rem;color:#6b7280">Warnings</div>
        </div>
      `;
      const msg = `<div style="padding:4px 0;color:#059669;font-weight:600"><i class="fas fa-check-circle mr-1"></i>${data.message}</div>`;
      const errors = data.errors?.length
        ? data.errors.map(e => `<div style="padding:2px 0;border-bottom:1px solid #fee2e2;color:#dc2626">⚠ ${e}</div>`).join('')
        : '';
      document.getElementById('resultsLog').innerHTML = msg + errors;
    } else {
      document.getElementById('statsCards').innerHTML = `
        <div style="grid-column:1/-1;background:#fee2e2;border-radius:8px;padding:14px;color:#991b1b">
          <i class="fas fa-exclamation-triangle mr-2"></i>${data.message}
        </div>`;
      document.getElementById('resultsLog').innerHTML = '';
    }
  } catch (err) {
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('resultsSection').style.display = 'block';
    document.getElementById('statsCards').innerHTML = `
      <div style="grid-column:1/-1;background:#fee2e2;border-radius:8px;padding:14px;color:#991b1b">
        <i class="fas fa-exclamation-triangle mr-2"></i>Error: ${err.message}
      </div>`;
  } finally {
    document.getElementById('importBtn').disabled = false;
    document.getElementById('importBtn').style.opacity = '1';
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
