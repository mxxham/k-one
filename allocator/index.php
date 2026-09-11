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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="http://localhost/k-one/includes/wms-style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; }
        /* ── Allocator-specific components (not in wms-style.css) ── */

        .alloc-container {
            max-width: 800px;
            margin: 24px auto;
            padding: 0 16px;
        }

        /* Drop zone */
        .alloc-dropzone {
            border: 3px dashed var(--wms-primary);
            border-radius: var(--radius-lg);
            padding: 48px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            background: var(--wms-lighter);
        }
        .alloc-dropzone:hover,
        .alloc-dropzone.is-drag {
            border-color: var(--wms-info);
            background: var(--wms-light);
        }
        .alloc-dropzone.has-file {
            border-color: var(--wms-success);
            background: var(--wms-light);
        }
        .alloc-dropzone:focus-visible {
            outline: 2px solid var(--wms-primary);
            outline-offset: 2px;
        }
        .alloc-dropzone-icon {
            font-size: 3rem;
            color: var(--wms-primary);
            margin-bottom: 12px;
            display: block;
        }
        .alloc-dropzone-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--wms-gray-600);
        }
        .alloc-dropzone-hint {
            color: var(--wms-gray-400);
            font-size: 0.875rem;
            margin-top: 4px;
        }

        /* File info */
        .alloc-file-info {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            background: var(--wms-light);
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            margin-top: 12px;
        }
        .alloc-file-info.is-visible {
            display: flex;
        }
        .alloc-file-info-file {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alloc-file-info-icon {
            color: var(--wms-success);
            font-size: 1.5rem;
        }
        .alloc-file-info-name {
            font-weight: 600;
            color: var(--wms-heading);
        }
        .alloc-file-info-size {
            font-size: 0.8rem;
            color: var(--wms-gray-400);
        }
        .alloc-file-info-remove {
            background: none;
            border: none;
            color: var(--wms-primary);
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 6px;
            transition: background 0.15s;
        }
        .alloc-file-info-remove:hover {
            background: var(--wms-lighter);
        }
        .alloc-file-info-remove:focus-visible {
            outline: 2px solid var(--wms-primary);
            outline-offset: 2px;
        }

        /* Generate button (full-width CTA) */
        .alloc-generate-btn {
            width: 100%;
            margin-top: 16px;
            padding: 14px;
            font-size: 1rem;
        }
        .alloc-generate-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Sheet chips */
        .alloc-sheet-chip {
            display: inline-block;
            background: var(--wms-light);
            border: 1px solid var(--wms-gray-200);
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 0.8rem;
            color: var(--wms-primary);
            margin: 4px 4px 4px 0;
        }
        .alloc-sheet-chips {
            line-height: 1.9;
        }

        /* Step number badge */
        .alloc-step-num {
            background: var(--wms-primary);
            color: #fff;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 700;
        }

        /* Progress bar */
        .alloc-progress-wrap {
            background: var(--wms-gray-200);
            border-radius: var(--radius-sm);
            height: 12px;
            overflow: hidden;
        }
        .alloc-progress-fill {
            background: linear-gradient(90deg, var(--wms-dark), var(--wms-primary));
            height: 100%;
            width: 0%;
            transition: width 0.3s;
            border-radius: var(--radius-sm);
        }
        .alloc-progress-text {
            margin-top: 8px;
            font-size: 0.875rem;
            color: var(--wms-gray-400);
            text-align: center;
        }

        /* Stat grid in results */
        .alloc-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .alloc-stat {
            border-radius: var(--radius-sm);
            padding: 14px;
            text-align: center;
        }
        .alloc-stat .num {
            font-size: 1.8rem;
            font-weight: 800;
            line-height: 1.1;
        }
        .alloc-stat .lbl {
            font-size: 0.78rem;
            color: var(--wms-gray-400);
            margin-top: 2px;
        }

        /* Pick card */
        .alloc-pick {
            background: var(--wms-gray-50);
            border: 1px solid var(--wms-gray-200);
            border-radius: var(--radius-sm);
            padding: 12px;
            margin-bottom: 8px;
            font-size: 0.875rem;
        }
        .alloc-pick--full {
            border-left: 4px solid var(--wms-success);
        }
        .alloc-pick--pickface {
            border-left: 4px solid var(--wms-info);
        }
        .alloc-pick--replenish {
            border-left: 4px solid var(--wms-warn);
        }

        /* Section title (within results card) */
        .alloc-section-title {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--wms-heading);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Error card */
        .alloc-error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            border-radius: var(--radius-sm);
            padding: 12px;
            margin-bottom: 8px;
            color: #991b1b;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Picks scroll area */
        .alloc-picks-scroll {
            max-height: 400px;
            overflow-y: auto;
        }

        /* Action button row */
        .alloc-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .alloc-actions .wms-btn,
        .alloc-actions a.wms-btn {
            flex: 1;
            min-width: 140px;
            justify-content: center;
        }

        /* Error grid message */
        .alloc-error-grid {
            grid-column: 1 / -1;
            background: #fee2e2;
            border-radius: var(--radius-sm);
            padding: 14px;
            color: #991b1b;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }
    </style>
</head>
<body style="background:var(--wms-gray-100)">

<!-- ── Hero Banner ── -->
<div class="alloc-container">
    <div class="wms-banner" style="margin-bottom:20px">
        <h1><i class="fas fa-magic" style="margin-right:8px"></i>Allocator</h1>
        <p>Generate picklist dari Excel order dengan alokasi FEFO</p>
    </div>
</div>

<!-- ── Main Content ── -->
<div class="alloc-container">

    <!-- Sheet Info Card -->
    <div class="wms-card" style="margin-bottom:20px">
        <div class="wms-card-header">
            <h2><i class="fas fa-th-list"></i> Sheet yang diproses</h2>
        </div>
        <div class="wms-card-body">
            <div class="alloc-sheet-chips">
                <span class="alloc-sheet-chip">schedule of the day → Order lines</span>
                <span class="alloc-sheet-chip">wms → Stock fisik &amp; lokasi bin</span>
                <span class="alloc-sheet-chip">data putaway → Batch &amp; expiry</span>
                <span class="alloc-sheet-chip">master sku → UPP &amp; UOM per item</span>
            </div>
        </div>
    </div>

    <!-- Upload Card -->
    <div class="wms-card" style="margin-bottom:20px">
        <div class="wms-card-header">
            <h2>
                <span class="alloc-step-num">1</span>
                Upload File Excel
            </h2>
        </div>
        <div class="wms-card-body">

            <div id="dropZone"
                 class="alloc-dropzone"
                 tabindex="0"
                 role="button"
                 aria-label="Upload file Excel — drag and drop atau klik untuk memilih file"
                 onclick="document.getElementById('excelFile').click()"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();document.getElementById('excelFile').click()}">
                <input type="file"
                       id="excelFile"
                       accept=".xlsx,.xls"
                       style="display:none"
                       onchange="fileSelected(this)"
                       aria-hidden="true">
                <i class="fas fa-cloud-upload-alt alloc-dropzone-icon" aria-hidden="true"></i>
                <p class="alloc-dropzone-title">Drag &amp; drop atau klik untuk pilih file</p>
                <p class="alloc-dropzone-hint">Format: .xlsx atau .xls &bull; Max 10MB</p>
            </div>

            <div id="fileInfo" class="alloc-file-info" role="status">
                <div class="alloc-file-info-file">
                    <i class="fas fa-file-excel alloc-file-info-icon" aria-hidden="true"></i>
                    <div>
                        <div id="fileName" class="alloc-file-info-name"></div>
                        <div id="fileSize" class="alloc-file-info-size"></div>
                    </div>
                </div>
                <button class="alloc-file-info-remove"
                        onclick="clearFile()"
                        aria-label="Hapus file yang dipilih">
                    <i class="fas fa-times" aria-hidden="true"></i> Hapus
                </button>
            </div>

            <button id="importBtn"
                    class="wms-btn wms-btn-primary alloc-generate-btn"
                    onclick="startAllocation()"
                    disabled>
                <i class="fas fa-magic" aria-hidden="true"></i> Generate Picklist
            </button>

        </div>
    </div>

    <!-- Progress Card (hidden by default) -->
    <div id="progressSection" class="wms-card" style="margin-bottom:20px;display:none">
        <div class="wms-card-header">
            <h2><i class="fas fa-spinner fa-spin"></i> Memproses allocation...</h2>
        </div>
        <div class="wms-card-body">
            <div class="alloc-progress-wrap"
                 role="progressbar"
                 aria-valuenow="0"
                 aria-valuemin="0"
                 aria-valuemax="100"
                 aria-label="Progres allocation">
                <div id="progressBar" class="alloc-progress-fill"></div>
            </div>
            <p id="progressText" class="alloc-progress-text">Membaca file...</p>
        </div>
    </div>

    <!-- Results Card (hidden by default) -->
    <div id="resultsSection" class="wms-card" style="margin-bottom:20px;display:none">
        <div class="wms-card-header">
            <h2><i class="fas fa-check-circle"></i> Allocation Selesai</h2>
        </div>
        <div class="wms-card-body">

            <div id="statsCards" class="alloc-stat-grid"></div>

            <div id="errorsSection" style="display:none;margin-bottom:16px">
                <div class="alloc-section-title" style="color:var(--wms-danger)">
                    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Errors
                </div>
                <div id="errorsList" role="alert"></div>
            </div>

            <div id="picksSection" style="margin-bottom:16px">
                <div class="alloc-section-title">
                    <i class="fas fa-list" style="color:var(--wms-primary)" aria-hidden="true"></i> Picks
                </div>
                <div id="picksList" class="alloc-picks-scroll"></div>
            </div>

            <div id="replenishmentsSection" style="display:none;margin-bottom:16px">
                <div class="alloc-section-title" style="color:var(--wms-warn)">
                    <i class="fas fa-exchange-alt" aria-hidden="true"></i> Replenishments
                </div>
                <div id="replenishmentsList"></div>
            </div>

            <div class="alloc-actions">
                <a id="downloadBtn" href="#" class="wms-btn wms-btn-success">
                    <i class="fas fa-download" aria-hidden="true"></i> Download Excel
                </a>
                <a id="printBtn" href="#" target="_blank" class="wms-btn wms-btn-primary">
                    <i class="fas fa-print" aria-hidden="true"></i> Print Picklist
                </a>
                <button onclick="resetAllocation()" class="wms-btn wms-btn-ghost">
                    <i class="fas fa-redo" aria-hidden="true"></i> Allocation Lagi
                </button>
            </div>

        </div>
    </div>

</div>

<script>
let selectedFile = null;

const dz = document.getElementById('dropZone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('is-drag'); });
dz.addEventListener('dragleave', () => dz.classList.remove('is-drag'));
dz.addEventListener('drop', e => {
  e.preventDefault(); dz.classList.remove('is-drag');
  const f = e.dataTransfer.files[0];
  if (f && (f.name.endsWith('.xlsx') || f.name.endsWith('.xls'))) setFile(f);
  else alert('Pilih file .xlsx atau .xls');
});

function fileSelected(input) { if (input.files[0]) setFile(input.files[0]); }

function setFile(file) {
  selectedFile = file;
  document.getElementById('fileName').textContent = file.name;
  document.getElementById('fileSize').textContent = (file.size/1024).toFixed(1) + ' KB';
  document.getElementById('fileInfo').classList.add('is-visible');
  dz.classList.add('has-file');
  document.getElementById('importBtn').disabled = false;
}

function clearFile() {
  selectedFile = null;
  document.getElementById('excelFile').value = '';
  document.getElementById('fileInfo').classList.remove('is-visible');
  dz.classList.remove('has-file');
  document.getElementById('importBtn').disabled = true;
}

async function startAllocation() {
  if (!selectedFile) return;

  document.getElementById('progressSection').style.display = 'block';
  document.getElementById('resultsSection').style.display = 'none';
  document.getElementById('importBtn').disabled = true;
  const progressBar = document.getElementById('progressBar');
  const progressWrap = progressBar.parentElement;
  progressBar.style.width = '20%';
  progressWrap.setAttribute('aria-valuenow', '20');
  document.getElementById('progressText').textContent = 'Mengirim file...';

  const fd = new FormData();
  fd.append('excel_file', selectedFile);

  try {
    progressBar.style.width = '50%';
    progressWrap.setAttribute('aria-valuenow', '50');
    document.getElementById('progressText').textContent = 'Memproses allocation...';

    const resp = await fetch('api.php', { method: 'POST', body: fd });
    const data = await resp.json();

    progressBar.style.width = '100%';
    progressWrap.setAttribute('aria-valuenow', '100');
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('resultsSection').style.display = 'block';

    if (data.success) {
      const s = data.summary;
      document.getElementById('statsCards').innerHTML = `
        <div class="alloc-stat" style="background:var(--wms-light)"><div class="num" style="color:var(--wms-primary)">${s.total_orders}</div><div class="lbl">Shipments</div></div>
        <div class="alloc-stat" style="background:var(--wms-lighter)"><div class="num" style="color:var(--wms-primary)">${s.total_deliveries}</div><div class="lbl">Deliveries</div></div>
        <div class="alloc-stat" style="background:var(--wms-light)"><div class="num" style="color:var(--wms-primary)">${s.total_items}</div><div class="lbl">Items</div></div>
        <div class="alloc-stat" style="background:var(--wms-lighter)"><div class="num" style="color:var(--wms-success)">${s.full_pallet_picks}</div><div class="lbl">Full Pallet</div></div>
        <div class="alloc-stat" style="background:var(--wms-light)"><div class="num" style="color:var(--wms-info)">${s.pickface_picks}</div><div class="lbl">Pickface</div></div>
        <div class="alloc-stat" style="background:#fef9c3"><div class="num" style="color:var(--wms-warn)">${s.replenishments}</div><div class="lbl">Replenish</div></div>
      `;

      if (data.errors && data.errors.length > 0) {
        document.getElementById('errorsSection').style.display = 'block';
        document.getElementById('errorsList').innerHTML = data.errors.map(e =>
          `<div class="alloc-error"><i class="fas fa-exclamation-circle" aria-hidden="true"></i>${e}</div>`
        ).join('');
      }

      document.getElementById('downloadBtn').href = 'download.php?file=' + encodeURIComponent(data.picklist_file);
      if (data.result_id) {
        document.getElementById('printBtn').href = 'print_picklist.php?id=' + data.result_id;
      }
    } else {
      document.getElementById('statsCards').innerHTML = `
        <div class="alloc-error-grid">
          <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>${data.message || 'Allocation gagal'}
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
  document.getElementById('progressBar').parentElement.setAttribute('aria-valuenow', '0');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>
</body>
</html>
