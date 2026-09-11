<?php
session_start();
// Standalone allocator — no auth required
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Allocator — Picklist Generator</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:#f1f5f5; color:#111827; }

        .header-bar {
            background:linear-gradient(135deg,#013d3c 0%,#026766 100%);
            padding:20px 32px;
            color:#fff;
            box-shadow:0 4px 20px rgba(2,103,102,.35);
        }
        .header-bar h1 { font-size:1.5rem; font-weight:800; }
        .header-bar p { opacity:.75; font-size:.875rem; margin-top:4px; }

        .container { max-width:800px; margin:24px auto; padding:0 16px; }

        .card {
            background:#fff;
            border-radius:12px;
            box-shadow:0 1px 8px rgba(0,0,0,.08);
            padding:24px;
            margin-bottom:20px;
        }
        .card h2 {
            font-size:1rem;
            font-weight:700;
            margin-bottom:16px;
            color:#013d3c;
            display:flex;
            align-items:center;
            gap:8px;
        }

        .drop-zone {
            border:3px dashed #026766;
            border-radius:16px;
            padding:48px;
            text-align:center;
            cursor:pointer;
            transition:.2s;
            background:#f0fbfb;
        }
        .drop-zone:hover, .drop-zone.drag { border-color:#5b21b6; background:#e0f7f7; }
        .drop-zone.has-file { border-color:#059669; background:#e6f7f7; }

        .file-info {
            display:none;
            background:#e6f7f7;
            border-radius:8px;
            padding:12px 16px;
            margin-top:12px;
            align-items:center;
            justify-content:space-between;
        }

        .btn-primary {
            width:100%;
            margin-top:16px;
            padding:14px;
            background:linear-gradient(135deg,#013d3c,#026766);
            color:#fff;
            border:none;
            border-radius:10px;
            font-size:1rem;
            font-weight:700;
            cursor:pointer;
            opacity:.5;
        }
        .btn-primary:disabled { cursor:not-allowed; }
        .btn-primary:not(:disabled) { opacity:1; }

        .progress-bar-wrap {
            background:#f3f4f6;
            border-radius:8px;
            height:12px;
            overflow:hidden;
        }
        .progress-bar-fill {
            background:linear-gradient(90deg,#013d3c,#026766);
            height:100%;
            width:0%;
            transition:.3s;
            border-radius:8px;
        }

        .stat-grid {
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(130px,1fr));
            gap:12px;
            margin-bottom:20px;
        }
        .stat-card {
            border-radius:8px;
            padding:14px;
            text-align:center;
        }
        .stat-card .num { font-size:1.8rem; font-weight:800; }
        .stat-card .lbl { font-size:.78rem; color:#6b7280; }

        .error-card {
            background:#fee2e2;
            border:1px solid #fecaca;
            border-radius:8px;
            padding:12px;
            margin-bottom:8px;
            color:#991b1b;
            font-size:.875rem;
        }
        .pick-card {
            background:#f9fafb;
            border:1px solid #e5e7eb;
            border-radius:8px;
            padding:12px;
            margin-bottom:8px;
            font-size:.875rem;
        }
        .pick-card.full-pallet { border-left:4px solid #059669; }
        .pick-card.pickface { border-left:4px solid #3b82f6; }
        .pick-card.replenishment { border-left:4px solid #f59e0b; }

        .btn-row { display:flex; gap:10px; flex-wrap:wrap; margin-top:16px; }
        .btn-dl {
            flex:1; min-width:140px;
            background:linear-gradient(135deg,#059669,#10b981);
            color:#fff; border:none; padding:10px; border-radius:8px;
            cursor:pointer; font-weight:700; text-align:center; text-decoration:none;
        }
        .btn-print {
            flex:1; min-width:140px;
            background:linear-gradient(135deg,#3b82f6,#2563eb);
            color:#fff; border:none; padding:10px; border-radius:8px;
            cursor:pointer; font-weight:700; text-align:center; text-decoration:none;
        }
        .btn-reset {
            flex:1; min-width:140px;
            background:linear-gradient(135deg,#013d3c,#026766);
            color:#fff; border:none; padding:10px; border-radius:8px;
            cursor:pointer; font-weight:700;
        }

        .sheet-chip {
            display:inline-block;
            background:#e6f7f7;
            border:1px solid #b2e5e5;
            border-radius:6px;
            padding:4px 10px;
            font-size:.8rem;
            color:#013d3c;
            margin:4px 4px 4px 0;
        }

        .section-title {
            font-size:.9rem;
            font-weight:700;
            margin-bottom:8px;
        }
    </style>
</head>
<body>

<div class="header-bar">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h1><i class="fas fa-magic" style="margin-right:8px"></i>Allocator</h1>
            <p>Generate picklist dari Excel order dengan alokasi FEFO</p>
        </div>
    </div>
</div>

<div class="container">

    <div class="card">
        <h2><i class="fas fa-th-list"></i>Sheet yang diproses</h2>
        <div style="line-height:1.9">
            <span class="sheet-chip">schedule of the day → Order lines</span>
            <span class="sheet-chip">data putaway → Stock saat ini</span>
            <span class="sheet-chip">master sku → UPP & UOM per item</span>
            <span class="sheet-chip">wms → Lokasi bin & stok</span>
        </div>
    </div>

    <div class="card">
        <h2>
            <span style="background:#013d3c;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem">1</span>
            Upload File Excel
        </h2>

        <div id="dropZone" class="drop-zone" onclick="document.getElementById('excelFile').click()">
            <input type="file" id="excelFile" accept=".xlsx,.xls" style="display:none" onchange="fileSelected(this)">
            <i class="fas fa-cloud-upload-alt" style="font-size:3rem;color:#026766;margin-bottom:12px;display:block"></i>
            <p style="font-size:1.1rem;font-weight:600;color:#4b5563">Drag & drop atau klik untuk pilih file</p>
            <p style="color:#9ca3af;font-size:.875rem;margin-top:4px">Format: .xlsx atau .xls • Max 10MB</p>
        </div>

        <div id="fileInfo" class="file-info">
            <div style="display:flex;align-items:center;gap:10px">
                <i class="fas fa-file-excel" style="color:#059669;font-size:1.5rem"></i>
                <div>
                    <div id="fileName" style="font-weight:600"></div>
                    <div id="fileSize" style="font-size:.8rem;color:#6b7280"></div>
                </div>
            </div>
            <button onclick="clearFile()" style="background:none;border:none;color:#026766;cursor:pointer;font-size:.875rem"><i class="fas fa-times"></i> Hapus</button>
        </div>

        <button id="importBtn" class="btn-primary" onclick="startAllocation()" disabled>
            <i class="fas fa-magic" style="margin-right:8px"></i> Generate Picklist
        </button>
    </div>

    <div id="progressSection" class="card" style="display:none">
        <h2><i class="fas fa-spinner fa-spin" style="color:#013d3c;margin-right:8px"></i>Memproses allocation...</h2>
        <div class="progress-bar-wrap">
            <div id="progressBar" class="progress-bar-fill"></div>
        </div>
        <p id="progressText" style="margin-top:8px;font-size:.875rem;color:#6b7280;text-align:center">Membaca file...</p>
    </div>

    <div id="resultsSection" class="card" style="display:none">
        <h2 style="color:#013d3c"><i class="fas fa-check-circle" style="margin-right:8px"></i>Allocation Selesai</h2>

        <div id="statsCards" class="stat-grid"></div>

        <div id="errorsSection" style="display:none;margin-bottom:16px">
            <h3 style="font-size:.9rem;font-weight:600;color:#dc2626;margin-bottom:8px"><i class="fas fa-exclamation-triangle" style="margin-right:8px"></i>Errors</h3>
            <div id="errorsList"></div>
        </div>

        <div id="picksSection" style="margin-bottom:16px">
            <h3 class="section-title" style="color:#013d3c"><i class="fas fa-list" style="margin-right:8px"></i>Picks</h3>
            <div id="picksList" style="max-height:400px;overflow-y:auto"></div>
        </div>

        <div id="replenishmentsSection" style="display:none;margin-bottom:16px">
            <h3 class="section-title" style="color:#f59e0b"><i class="fas fa-exchange-alt" style="margin-right:8px"></i>Replenishments</h3>
            <div id="replenishmentsList"></div>
        </div>

        <div class="btn-row">
            <a id="downloadBtn" href="#" class="btn-dl"><i class="fas fa-download" style="margin-right:8px"></i>Download Excel</a>
            <a id="printBtn" href="#" target="_blank" class="btn-print"><i class="fas fa-print" style="margin-right:8px"></i>Print Picklist</a>
            <button onclick="resetAllocation()" class="btn-reset"><i class="fas fa-redo" style="margin-right:8px"></i>Allocation Lagi</button>
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
  dz.classList.add('has-file');
  document.getElementById('importBtn').disabled = false;
}

function clearFile() {
  selectedFile = null;
  document.getElementById('excelFile').value = '';
  document.getElementById('fileInfo').style.display = 'none';
  dz.classList.remove('has-file');
  document.getElementById('importBtn').disabled = true;
  document.getElementById('importBtn').style.opacity = '.5';
}

async function startAllocation() {
  if (!selectedFile) return;

  document.getElementById('progressSection').style.display = 'block';
  document.getElementById('resultsSection').style.display = 'none';
  document.getElementById('importBtn').disabled = true;
  document.getElementById('progressBar').style.width = '20%';
  document.getElementById('progressText').textContent = 'Mengirim file...';

  const fd = new FormData();
  fd.append('excel_file', selectedFile);

  try {
    document.getElementById('progressBar').style.width = '50%';
    document.getElementById('progressText').textContent = 'Memproses allocation...';

    const resp = await fetch('api.php', { method: 'POST', body: fd });
    const data = await resp.json();

    document.getElementById('progressBar').style.width = '100%';
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('resultsSection').style.display = 'block';

    if (data.success) {
      const s = data.summary;
      document.getElementById('statsCards').innerHTML = `
        <div class="stat-card" style="background:#e6f7f7"><div class="num" style="color:#013d3c">${s.total_orders}</div><div class="lbl">Orders</div></div>
        <div class="stat-card" style="background:#e0f7f7"><div class="num" style="color:#013d3c">${s.total_items}</div><div class="lbl">Items</div></div>
        <div class="stat-card" style="background:#e6f7f7"><div class="num" style="color:#059669">${s.full_pallet_picks}</div><div class="lbl">Full Pallet</div></div>
        <div class="stat-card" style="background:#e0f7f7"><div class="num" style="color:#3b82f6">${s.pickface_picks}</div><div class="lbl">Pickface</div></div>
        <div class="stat-card" style="background:#fef3c7"><div class="num" style="color:#f59e0b">${s.replenishments}</div><div class="lbl">Replenish</div></div>
      `;

      if (data.errors && data.errors.length > 0) {
        document.getElementById('errorsSection').style.display = 'block';
        document.getElementById('errorsList').innerHTML = data.errors.map(e =>
          `<div class="error-card"><i class="fas fa-exclamation-circle" style="margin-right:8px"></i>${e}</div>`
        ).join('');
      }

      document.getElementById('downloadBtn').href = 'download.php?file=' + encodeURIComponent(data.picklist_file);
      if (data.result_id) {
        document.getElementById('printBtn').href = 'print_picklist.php?id=' + data.result_id;
      }
    } else {
      document.getElementById('statsCards').innerHTML = `
        <div style="grid-column:1/-1;background:#ffebee;border-radius:8px;padding:14px;color:#c62828">
          <i class="fas fa-exclamation-triangle" style="margin-right:8px"></i>${data.message || 'Allocation gagal'}
        </div>
      `;
    }
  } catch (err) {
    document.getElementById('progressSection').style.display = 'none';
    alert('Error: ' + err.message);
  }
}

function resetAllocation() {
  clearFile();
  document.getElementById('resultsSection').style.display = 'none';
  document.getElementById('progressBar').style.width = '0%';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>
</body>
</html>
