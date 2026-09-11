<?php
/**
 * Excel Parser for Allocator
 * Parses the same Excel format as K-one auto import
 */

require_once __DIR__ . '/../../config/database.php';

class ExcelParser
{
    private $db;
    private $sheets = [];
    private $errors = [];

    private const EXPECTED_UPP = [
        'Drum'     => [4],
        'Carton'   => [36, 44, 48],
        'Pail'     => [24],
        'Fluidbag' => [1],
        'IBC'      => [1],
        'EA'       => [4],
        'Bags'     => [1],
    ];

    private const DEFAULT_UPP = [
        'Drum'     => 4,
        'Carton'   => 44,
        'Pail'     => 24,
        'Fluidbag' => 1,
        'IBC'      => 1,
        'EA'       => 4,
        'Bags'     => 1,
    ];

    public function __construct()
    {
        $this->db = db();
    }

    /**
     * Load Excel file and parse all sheets
     * @param string $filePath Path to file (usually a temp path)
     * @param string|null $originalName Original filename used for extension check
     */
    public function load(string $filePath, ?string $originalName = null): bool
    {
        try {
            $checkName = $originalName ?? $filePath;
            $ext = strtolower(pathinfo($checkName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'xls'])) {
                $this->errors[] = 'Format file harus .xlsx atau .xls';
                return false;
            }

            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xlsx' ? 'Xlsx' : 'Xls');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);

            foreach ($spreadsheet->getSheetNames() as $sheetName) {
                $sheet = $spreadsheet->getSheetByName($sheetName);
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);
                    $rowData = [];
                    foreach ($cellIterator as $cell) {
                        $val = $cell->getValue();
                        // Replace formulas with blank (same as auto import)
                        if (is_string($val) && strlen($val) > 0 && $val[0] === '=') {
                            $val = '';
                        }
                        $rowData[] = $val;
                    }
                    $rows[] = $rowData;
                }
                if (!empty($rows)) {
                    $this->sheets[$sheetName] = $rows;
                }
            }

            return true;
        } catch (\Throwable $e) {
            $this->errors[] = 'Error membaca file: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Get parsed sheets
     */
    public function getSheets(): array
    {
        return $this->sheets;
    }

    /**
     * Get errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Parse "Schedule of the day" sheet → order lines
     * Columns: A=Order No, D=Shipment Number, G=Material, I=Delivery quantity
     */
    public function parseSchedule(): array
    {
        $orders = [];
        foreach ($this->sheets as $name => $rows) {
            if (!str_contains(strtolower($name), 'schedule')) continue;

            // Find header row
            $headerIdx = 0;
            foreach ($rows as $idx => $row) {
                $rowStr = strtolower(implode(' ', array_map(fn($v) => (string)($v ?? ''), $row)));
                if (str_contains($rowStr, 'order no') && str_contains($rowStr, 'material')) {
                    $headerIdx = $idx;
                    break;
                }
            }

            // Parse data rows
            $lastNo = '';
            for ($i = $headerIdx + 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $orderNo = trim((string)($row[0] ?? ''));
                $shipmentNo = trim((string)($row[3] ?? '')); // Column D
                $destination = trim((string)($row[4] ?? '')); // Column E
                $shipToLocation = trim((string)($row[5] ?? '')); // Column F
                $material = trim((string)($row[6] ?? '')); // Column G
                $qty = (int)($row[8] ?? 0); // Column I
                $no = trim((string)($row[17] ?? '')); // Column R (NO)
                if ($no !== '') $lastNo = $no;

                if ($orderNo === '' || $material === '' || $qty <= 0) continue;

                $orders[] = [
                    'order_no' => $orderNo,
                    'shipment_no' => $shipmentNo,
                    'no' => $lastNo,
                    'destination' => $destination,
                    'ship_to_location' => $shipToLocation,
                    'material' => $material,
                    'quantity' => $qty,
                ];
            }
            break;
        }
        return $orders;
    }

    /**
     * Parse "data putaway" sheet → current stock in bins
     * Columns: A=BIN Location, B=Item Code, C=ACTUAL QTY, D=Batch No, E=Expired Date
     */
    public function parsePutaway(): array
    {
        $stock = [];
        foreach ($this->sheets as $name => $rows) {
            if (!str_contains(strtolower($name), 'putaway')) continue;

            // Find header row
            $headerIdx = 0;
            foreach ($rows as $idx => $row) {
                $rowStr = strtolower(implode(' ', array_map(fn($v) => (string)($v ?? ''), $row)));
                if (str_contains($rowStr, 'bin') || str_contains($rowStr, 'lokasi') || str_contains($rowStr, 'item')) {
                    $headerIdx = $idx;
                    break;
                }
            }

            // Parse data rows
            for ($i = $headerIdx + 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $bin = trim((string)($row[0] ?? ''));
                $itemCode = trim((string)($row[1] ?? ''));
                $qty = (int)($row[2] ?? 0);
                $batch = trim((string)($row[3] ?? ''));
                $expiry = $this->parseDate($row[4] ?? null);

                if ($bin === '' || $itemCode === '' || $qty <= 0) continue;

                $stock[] = [
                    'location' => strtoupper($bin),
                    'item_code' => $itemCode,
                    'quantity' => $qty,
                    'batch_number' => $batch ?: null,
                    'expiry_date' => $expiry,
                ];
            }
            break;
        }
        return $stock;
    }

    /**
     * Parse "Master SKU" sheet → product UPP and UOM
     * Columns: B=Material, I=Pallet (UPP), L=TYPE (DRUM/CAR/PAIL/IBC/FLUID BAG)
     */
    public function parseMasterSku(): array
    {
        $products = [];
        foreach ($this->sheets as $name => $rows) {
            // Specifically look for "Master SKU" sheet, not just any sheet with "master"
            if (strtolower($name) !== 'master sku') continue;

            // Find header row
            $headerIdx = 0;
            foreach ($rows as $idx => $row) {
                $rowStr = strtolower(implode(' ', array_map(fn($v) => (string)($v ?? ''), $row)));
                if (str_contains($rowStr, 'material') && (str_contains($rowStr, 'upp') || str_contains($rowStr, 'type'))) {
                    $headerIdx = $idx;
                    break;
                }
            }

            // Parse data rows
            for ($i = $headerIdx + 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $material = trim((string)($row[1] ?? '')); // Column B
                $upp = (int)($row[8] ?? 0); // Column I (Pallet)
                $type = strtoupper(trim((string)($row[11] ?? ''))); // Column L

                if ($material === '') continue;

                // Normalize UOM type
                $uomType = match ($type) {
                    'DRUM' => 'Drum',
                    'CAR', 'CARTON' => 'Carton',
                    'PAIL' => 'Pail',
                    'IBC' => 'IBC',
                    'FLUID BAG', 'FLUIDBAG' => 'Fluidbag',
                    default => $type !== '' ? ucfirst(strtolower($type)) : 'Drum',
                };

                // Validate UPP against UOM type — fix mismatches from Excel source data
                $validUpps = self::EXPECTED_UPP[$uomType] ?? [];
                if ($upp <= 0 || !in_array($upp, $validUpps, true)) {
                    $upp = self::DEFAULT_UPP[$uomType] ?? 4;
                }

                $products[$material] = [
                    'material' => $material,
                    'upp' => $upp,
                    'uom_type' => $uomType,
                ];
            }
            break;
        }
        return $products;
    }

    /**
     * Parse "WMS" sheet → bin locations with stock
     * Columns: H=Location, I=Batch, K=Expired Date, L=Item Code, AE=On Hand Qty, AL=UOM
     */
    public function parseWmsLocations(): array
    {
        $locations = [];
        foreach ($this->sheets as $name => $rows) {
            if (strtolower($name) !== 'wms') continue;

            // Find header row (row 4 has headers)
            $headerIdx = 3; // Row 4 (0-indexed = 3)

            // Parse data rows
            for ($i = $headerIdx + 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $location = trim((string)($row[7] ?? '')); // Column H (Lokasi)
                $batch = trim((string)($row[8] ?? '')); // Column I (Batch)
                $expiry = $this->parseDate($row[10] ?? null); // Column K (Expired Date)
                $itemCode = trim((string)($row[11] ?? '')); // Column L (Item)
                $onHand = (int)($row[13] ?? 0); // Column N (Qty)
                $uom = trim((string)($row[37] ?? '')); // Column AL (UOM)

                // Validate location format (e.g., CA01A01)
                if (!preg_match('/^[A-Z]{2}\d+[A-E]\d{2}$/', $location)) continue;

                // Extract level from location (5th character)
                $level = $location[4];

                $locations[] = [
                    'location' => $location,
                    'level' => $level,
                    'item_code' => $itemCode,
                    'batch_number' => $batch ?: null,
                    'expiry_date' => $expiry,
                    'uom' => $uom,
                    'on_hand' => $onHand,
                    'is_pickface' => ($level === 'A'),
                    'is_bulk' => ($level !== 'A'),
                ];
            }
            break;
        }
        return $locations;
    }

    /**
     * Parse date from Excel serial or string
     */
    private function parseDate($val): ?string
    {
        if ($val === null || $val === '' || $val === '0') return null;

        if (is_numeric($val) && (float)$val > 40000) {
            try {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$val);
                $y = (int)$dt->format('Y');
                if ($y < 1990 || $y > 2100) return null;
                return $dt->format('Y-m-d');
            } catch (\Exception $e) {}
        }

        $val = trim((string)$val);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return $val;
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $val, $m)) {
            $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        $ts = strtotime($val);
        if ($ts && $ts > 0) {
            $y = (int)date('Y', $ts);
            if ($y >= 1990 && $y <= 2100) return date('Y-m-d', $ts);
        }

        return null;
    }
}
