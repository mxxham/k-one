<?php
date_default_timezone_set('Asia/Jakarta');

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

elseif (file_exists(__DIR__ . '/../lib/PhpSpreadsheet/autoload.php')) {
    require_once __DIR__ . '/../lib/PhpSpreadsheet/autoload.php';
} else {
    
    die('
    <div style="padding: 20px; font-family: Arial; max-width: 600px; margin: 50px auto;">
        <h2 style="color: #026766;">PhpSpreadsheet Not Installed</h2>
        <p>Excel export feature requires PhpSpreadsheet library.</p>
        <p><strong>To install, run one of these commands:</strong></p>
        <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px;">
Option 1 (Recommended): Double-click install_composer.bat

Option 2: composer require phpoffice/phpspreadsheet

Option 3: Download from https://github.com/PHPOffice/PhpSpreadsheet/releases
           and extract to: lib\PhpSpreadsheet\
        </pre>
    </div>
    ');
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ExcelExport {
    private $spreadsheet;
    private $sheet;
    private $row;

    public function __construct() {
        $this->spreadsheet = new Spreadsheet();
        $this->sheet = $this->spreadsheet->getActiveSheet();
        $this->row = 1;
    }

    

    public function setTitle($title) {
        $this->sheet->setCellValue('A1', $title);
        $this->sheet->mergeCells('A1:H1');
        $this->sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'd32f2f']]
        ]);
        $this->sheet->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
        $this->row = 2;
        return $this;
    }

    

    public function setSubtitle($subtitle) {
        $this->sheet->setCellValue('A' . $this->row, $subtitle);
        $this->sheet->getStyle('A' . $this->row)->applyFromArray([
            'font' => ['italic' => true, 'size' => 10],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);
        $this->row++;
        return $this;
    }

    

    private $headers = [];

    public function setHeaders($headers) {
        $this->headers = $headers;
        $col = 1;
        foreach ($headers as $header) {
            $cell = Coordinate::stringFromColumnIndex($col) . $this->row;
            $this->sheet->setCellValue($cell, $header);
            $col++;
        }

        
        $lastCol = $col - 1;
        $range = 'A' . $this->row . ':' . Coordinate::stringFromColumnIndex($lastCol) . $this->row;
        $this->sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1976D2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        $this->row++;
        return $this;
    }

    

    public function addRow($data) {
        $col = 1;
        foreach ($data as $value) {
            $cell = Coordinate::stringFromColumnIndex($col) . $this->row;
            $this->sheet->setCellValue($cell, $value);
            $col++;
        }

        
        $lastCol = $col - 1;
        $range = 'A' . $this->row . ':' . Coordinate::stringFromColumnIndex($lastCol) . $this->row;
        $this->sheet->getStyle($range)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        
        if (($this->row - 2) % 2 == 0) {
            $this->sheet->getStyle($range)
                ->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('F5F5F5');
        }

        $this->row++;
        return $this;
    }

    

    public function addRows($rows) {
        foreach ($rows as $row) {
            $this->addRow($row);
        }
        return $this;
    }

    

    public function addSummary($label, $values) {
        $this->sheet->setCellValue('A' . $this->row, $label);
        $this->sheet->getStyle('A' . $this->row)->applyFromArray([
            'font' => ['bold' => true]
        ]);

        $col = 2;
        foreach ($values as $value) {
            $cell = Coordinate::stringFromColumnIndex($col) . $this->row;
            $this->sheet->setCellValue($cell, $value);
            $col++;
        }
        $this->row++;
        return $this;
    }

    

    public function autoSize() {
        foreach (range(1, count($this->headers)) as $col) {
            $column = Coordinate::stringFromColumnIndex($col);
            $this->sheet->getColumnDimension($column)->setAutoSize(true);
        }
        return $this;
    }

    

    public function download($filename) {
        $this->autoSize();
        $this->spreadsheet->setActiveSheetIndex(0);
        self::_download($this->spreadsheet, $filename);
    }

    

    private static function _download(Spreadsheet $sp, string $filename): void {
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        (new Xlsx($sp))->save('php://output');
        exit;
    }

    

    private function getColumnLetter($number) {
        $letters = '';
        while ($number > 0) {
            $number--;
            $letters = chr($number % 26 + 65) . $letters;
            $number = intval($number / 26);
        }
        return $letters;
    }

    private function getHeaders() {
        return $this->headers;
    }

    

    public static function exportInbound($orders) {
        if (empty($orders)) {
            die('Tidak ada data inbound untuk di-export.');
        }

        $db = db();

        
        $orderIds     = array_column($orders, 'id');
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $db->prepare("
            SELECT  ii.*,
                    COALESCE(ii.batch_number, ii.batch_no) AS resolved_batch,
                    p.product_code, p.product_name,
                    io.shipment_no, io.order_number, io.po_number, io.do_number,
                    io.container_no, io.armada_no, io.received_date,
                    io.status AS order_status,
                    io.carrier_name
            FROM    inbound_items ii
            JOIN    products p        ON ii.product_id = p.id
            JOIN    inbound_orders io ON ii.inbound_order_id = io.id
            WHERE   ii.inbound_order_id IN ($placeholders)
            ORDER   BY io.id, ii.id
        ");
        $stmt->execute($orderIds);
        $items = $stmt->fetchAll();

        $sp    = new Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $sheet->setTitle('Inbound Report');

        $headerColor = '013D3C';
        $subColor    = '026766';
        $totalOrders = count($orders);
        $totalItems  = count($items);

        
        $headers = [
            'No','Shipment No','OD Number',
            'Received Date','Carrier','Container No','Armada No',
            'Product Code','Product Name','Batch No','Qty','UOM','Pallet',
            'Mfg. Date','Exp. Date','Sisa Hari',
            'In Process Status','Stock Status','Location','Order Status','Notes'
        ];
        $totalCols     = count($headers);
        $lastColLetter = Coordinate::stringFromColumnIndex($totalCols);

        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1', 'K-one — Inbound Report   |   Dicetak: ' . date('d F Y H:i') . ' WIB   |   ' . $totalOrders . ' order, ' . $totalItems . ' item');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $headerColor]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        
        foreach ($headers as $c => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . '2', $h);
        }
        $sheet->getStyle("A2:{$lastColLetter}2")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $subColor]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '014F4E']]],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->freezePane('A3');

        
        $row = 3;
        $shipmentNo = 0;
        $lastShipment = null;
        foreach ($items as $i => $item) {
            $qty     = (float)($item['actual_qty'] ?: $item['quantity'] ?: 0);
            $pallet  = (float)($item['pallet'] ?? 0);
            $expDate = $item['exp_date'] ?? null;
            $mfgDate = $item['manufacture_date'] ?? null;

            $daysLeft = '';
            if ($expDate) {
                $daysLeft = (int)floor((strtotime($expDate) - time()) / 86400);
            }

            $currentShipment = $item['shipment_no'] ?? '';
            $isNewShipment = $currentShipment !== $lastShipment;
            if ($isNewShipment) {
                $shipmentNo++;
                $lastShipment = $currentShipment;
            }

            $rowData = [
                $isNewShipment ? $shipmentNo : '',
                $item['shipment_no']    ?? '',
                $item['od_number']      ?? '',
                $item['received_date']  ? date('d/m/Y', strtotime($item['received_date'])) : '',
                $item['carrier_name']   ?? '',
                $item['container_no']   ?? '',
                $item['armada_no']      ?? '',
                $item['product_code']   ?? '',
                $item['product_name']   ?? '',
                $item['resolved_batch'] ?? '',
                $qty,
                $item['uom']            ?? '',
                $pallet > 0 ? $pallet : '',
                $mfgDate ? date('d/m/Y', strtotime($mfgDate)) : '',
                $expDate ? date('d/m/Y', strtotime($expDate)) : '',
                $daysLeft,
                $item['in_process_status'] ?? '',
                $item['stock_status']      ?? '',
                $item['location']          ?? '',
                $item['order_status']      ?? '',
                $item['notes']             ?? '',
            ];

            foreach ($rowData as $c => $val) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . $row, $val);
            }

            $rangeFull = "A{$row}:{$lastColLetter}{$row}";
            $sheet->getStyle($rangeFull)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
                'font'    => ['size' => 9],
            ]);
            if ($row % 2 === 0) {
                $sheet->getStyle($rangeFull)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0FDFC');
            }

            
            $inpCell  = Coordinate::stringFromColumnIndex(18) . $row;
            $inpValue = $item['in_process_status'] ?? '';
            if ($inpValue === 'ATP')
                $sheet->getStyle($inpCell)->getFont()->getColor()->setRGB('166534');
            elseif ($inpValue === 'Dues In')
                $sheet->getStyle($inpCell)->getFont()->getColor()->setRGB('1E40AF');
            elseif ($inpValue === 'Unserviceable')
                $sheet->getStyle($inpCell)->getFont()->getColor()->setRGB('991B1B');

            
            if ($daysLeft !== '') {
                $dayCell = Coordinate::stringFromColumnIndex(17) . $row;
                if ($daysLeft < 0)
                    $sheet->getStyle($dayCell)->getFont()->getColor()->setRGB('991B1B');
                elseif ($daysLeft <= 90)
                    $sheet->getStyle($dayCell)->getFont()->getColor()->setRGB('92400E');
            }

            $row++;
        }

        
        $sheet->setCellValue("A{$row}", 'TOTAL');
        $sheet->setCellValue(Coordinate::stringFromColumnIndex(12) . $row,
            array_sum(array_column($items, 'actual_qty') ?: array_column($items, 'quantity')));
        $sheet->getStyle("A{$row}:{$lastColLetter}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $headerColor]],
        ]);

        
        foreach (range(1, $totalCols) as $c) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        self::_download($sp, 'Inbound_Report_' . date('Y-m-d_His') . '.xlsx');
    }

    

    public static function exportInboundWithItems($inbound, $items) {
        $excel = new self();
        $subtitle = 'Carrier: ' . ($inbound['carrier_name'] ?? '—')
                  . ' | Date: ' . date('d M Y', strtotime($inbound['order_date']));
        if (!empty($inbound['po_number']))   $subtitle .= ' | PO: '       . $inbound['po_number'];
        if (!empty($inbound['shipment_no'])) $subtitle .= ' | Shipment: ' . $inbound['shipment_no'];
        if (!empty($inbound['do_number']))   $subtitle .= ' | DO: '       . $inbound['do_number'];

        $excel->setTitle('Inbound Order: ' . ($inbound['order_number'] ?? '-'))
              ->setSubtitle($subtitle)
              ->setHeaders([
                  'Product', 'Batch', 'Qty', 'UOM', 'Pallets',
                  'Mfg Date', 'Expiry Date', 'Location',
                  'In Process Status', 'Stock Status', 'Notes'
              ]);

        foreach ($items as $item) {
            $uomPerPallet = $item['uom_per_pallet'] ?? 4;
            $excel->addRow([
                ($item['product_code'] ?? '') . ' - ' . ($item['product_name'] ?? ''),
                $item['batch_number'] ?? '-',
                $item['actual_qty'] ?? $item['quantity'] ?? 0,
                $item['uom'] ?? '-',
                (int)ceil(($item['actual_qty'] ?? $item['quantity'] ?? 0) / max(1, $uomPerPallet)),
                $item['manufacture_date'] ? date('d M Y', strtotime($item['manufacture_date'])) : '-',
                $item['exp_date'] ? date('d M Y', strtotime($item['exp_date'])) : '-',
                $item['location'] ?? '-',
                $item['in_process_status'] ?? '-',
                $item['stock_status'] ?? '-',
                $item['notes'] ?? '-'
            ]);
        }

        $totalQty = array_sum(array_column($items, 'quantity')) + array_sum(array_column($items, 'actual_qty'));
        $excel->addSummary('Total Items', [count($items)]);
        $excel->addSummary('Total Qty', [$totalQty]);
        $excel->addSummary('Total Pallets', [number_format($totalQty / 4, 2)]);

        $excel->download(($inbound['order_number'] ?? 'inbound') . '_detail.xlsx');
    }

    

    public static function exportOutbound($orders) {
        if (empty($orders)) {
            die('Tidak ada data outbound untuk di-export.');
        }

        $db = db();

        
        $orderIds     = array_column($orders, 'id');
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $db->prepare("
            SELECT  oi.*,
                    COALESCE(oi.batch_number, oi.batch_no)             AS resolved_batch,
                    p.product_code, p.product_name,
                    COALESCE(p.liters_per_unit, 0)                     AS liters_per_unit,
                    o.order_number, o.shipment_number,
                    o.so_number AS order_so,
                    o.order_date,  o.expected_date, o.shipped_date,
                    o.armada_no,   o.container_no,  o.jenis_armada,
                    o.status AS order_status,
                    COALESCE(ci.customer_name, co.customer_name) AS customer_name,
                    COALESCE(ci.customer_code, co.customer_code) AS customer_code,
                    COALESCE(ci.city, co.city)                   AS customer_city,
                    COALESCE(NULLIF(od.ship_to_name,''),
                             NULLIF(ci.customer_name,''),
                             NULLIF(co.customer_name,''))              AS dest_name,
                    COALESCE(NULLIF(od.ship_to_location,''),
                             NULLIF(od.kota,''),
                             NULLIF(ci.city,''),
                             NULLIF(co.city,''))                       AS dest_location,
                    NULLIF(od.ship_to_street,'')                       AS dest_street,
                    COALESCE(NULLIF(od.kota,''),
                             NULLIF(ci.city,''),
                             NULLIF(co.city,''))                       AS dest_kota,
                    COALESCE(od.seq, 0)                                AS dest_seq
            FROM    outbound_items oi
            JOIN    products p             ON oi.product_id  = p.id
            JOIN    outbound_orders o      ON oi.outbound_order_id = o.id
            LEFT JOIN customers co         ON o.customer_id  = co.id
            LEFT JOIN customers ci         ON oi.customer_id = ci.id
            LEFT JOIN outbound_destinations od ON oi.destination_id = od.id
            WHERE   oi.outbound_order_id IN ($placeholders)
            ORDER   BY o.order_date ASC, o.id ASC, COALESCE(od.seq,0) ASC, oi.id ASC
        ");
        $stmt->execute($orderIds);
        $allItems = $stmt->fetchAll();

        
        $groups = [];
        foreach ($allItems as $item) {
            $groups[$item['outbound_order_id']][] = $item;
        }

        $sp    = new Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $sheet->setTitle('Outbound Report');

        $colDark  = '013D3C';
        $colMid   = '026766';
        $colTotal = '014F4E';
        $colLight = 'E6F4F4';

        
        
        
        
        
        
        
        
        
        
        $headers = [
            'NO',
            'Shipment Number',
            'Order No (OD)',
            'Ship-to Party',
            'Material',
            'Description',
            'Delivery Quantity',
            'Sales Unit',
            'Name of Ship-To Party',
            'Customer',
            'Location of Ship-To Party',
            'Street / Address',
            'Goods Issue Date',
            'SO Number',
            'TRANSPORT',
            'Volume (L)',
            'Batch No',
            'Exp. Date',
            'Sisa Hari',
            'Pallet',
            'Type Truck',
            'Container No',
            'Warehouse Location',
            'Status',
            'KET',
        ];
        $totalCols     = count($headers);            
        $lastColLetter = Coordinate::stringFromColumnIndex($totalCols);

        
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1',
            'K-one — Outbound Export   |   Dicetak: ' . date('d F Y H:i') . ' WIB');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colDark]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                            'vertical'   => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(26);

        
        $sheet->getRowDimension(2)->setRowHeight(4);

        
        $sheet->mergeCells("A3:{$lastColLetter}3");
        $sheet->setCellValue('A3',
            count($groups) . ' shipment   |   ' . count($allItems) . ' item   |   Generated: ' . date('d M Y H:i'));
        $sheet->getStyle('A3')->applyFromArray([
            'font'      => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '555555']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);

        
        foreach ($headers as $c => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . '4', $h);
        }
        $sheet->getStyle("A4:{$lastColLetter}4")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colMid]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                            'vertical'   => Alignment::VERTICAL_CENTER,
                            'wrapText'   => true],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                             'color'       => ['rgb' => '014F4E']]],
        ]);
        $sheet->getRowDimension(4)->setRowHeight(28);
        $sheet->freezePane('A5');

        
        $row       = 5;
        $shipmentNo = 0;

        foreach ($groups as $orderId => $items) {
            $shipmentNo++;
            $first = $items[0];

            $groupQty    = 0;
            $groupVolume = 0.0;
            $startRow    = $row;

            foreach ($items as $idx => $item) {
                $qty      = (float)($item['actual_qty'] ?: $item['quantity'] ?: 0);
                $litres   = (float)($item['liters_per_unit'] ?? 0);
                $volume   = $qty * $litres;
                $expDate  = $item['exp_date'] ?? null;
                $daysLeft = $expDate
                    ? (int)floor((strtotime($expDate) - time()) / 86400)
                    : '';

                $groupQty    += $qty;
                $groupVolume += $volume;

                
                $sheet->setCellValue('A' . $row, $idx === 0 ? $shipmentNo : '');

                
                
                
                
                
                
                
                
                $giDate = $item['shipped_date'] ?: ($item['expected_date'] ?? null);
                $rowData = [
                    2  => $item['shipment_number']  ?: ($item['order_number'] ?? ''),
                    3  => $item['od_number']        ?: '',
                    4  => $item['dest_name']        ?: ($item['customer_name'] ?? ''),
                    5  => $item['product_code']     ?? '',
                    6  => $item['product_name']     ?? '',
                    7  => $qty,
                    8  => $item['uom']              ?? '',
                    9  => $item['dest_name']        ?? '',
                    10 => trim(
                        ((($item['customer_id'] ?? '') !== '') ? ('ID ' . $item['customer_id'] . ' | ') : '') .
                        (string)($item['customer_name'] ?? '') .
                        ((($item['customer_code'] ?? '') !== '') ? ' (' . $item['customer_code'] . ')' : '')
                    ),
                    11 => $item['dest_location']    ?? '',
                    12 => $item['dest_street']      ?? '',
                    13 => $giDate ? date('Y-m-d', strtotime($giDate)) : '',
                    14 => $item['so_number']        ?: ($item['order_so'] ?? ''),
                    15 => $item['armada_no']        ?? '',
                    16 => $volume > 0 ? round($volume, 2) : '',
                    17 => $item['resolved_batch']   ?? '',
                    18 => $expDate ? date('d/m/Y', strtotime($expDate)) : '',
                    19 => $daysLeft,
                    20 => (float)($item['pallet'] ?? 0) > 0 ? (float)$item['pallet'] : '',
                    21 => $item['jenis_armada']     ?? '',
                    22 => $item['container_no']     ?? '',
                    23 => $item['location']         ?? '',
                    24 => $item['order_status']     ?? '',
                    25 => $item['notes']            ?? '',
                ];
                foreach ($rowData as $col => $val) {
                    $sheet->setCellValue(
                        Coordinate::stringFromColumnIndex($col) . $row, $val);
                }

                
                $rangeFull = "A{$row}:{$lastColLetter}{$row}";
                $sheet->getStyle($rangeFull)->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                                   'color'       => ['rgb' => 'D1D5DB']]],
                    'font'    => ['size' => 9],
                ]);
                if ($idx % 2 === 0) {
                    $sheet->getStyle($rangeFull)->getFill()
                          ->setFillType(Fill::FILL_SOLID)
                          ->getStartColor()->setRGB($colLight);
                }

                
                if ($daysLeft !== '') {
                    $dayCell = Coordinate::stringFromColumnIndex(19) . $row;
                    if ($daysLeft < 0)
                        $sheet->getStyle($dayCell)->getFont()->getColor()->setRGB('991B1B');
                    elseif ($daysLeft <= 90)
                        $sheet->getStyle($dayCell)->getFont()->getColor()->setRGB('92400E');
                    else
                        $sheet->getStyle($dayCell)->getFont()->getColor()->setRGB('166534');
                }

                $row++;
            }

            
            $sheet->setCellValue("A{$row}", 'TOTAL');
            $sheet->setCellValue(
                Coordinate::stringFromColumnIndex(7) . $row, $groupQty);
            if ($groupVolume > 0) {
                $sheet->setCellValue(
                    Coordinate::stringFromColumnIndex(16) . $row, round($groupVolume, 2));
            }
            $sheet->getStyle("A{$row}:{$lastColLetter}{$row}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID,
                                'startColor' => ['rgb' => $colTotal]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                                 'color'       => ['rgb' => '013D3C']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
            ]);
            $sheet->getStyle("A{$row}")->getAlignment()
                  ->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $row++;

            
            $sheet->getRowDimension($row)->setRowHeight(6);
            $row++;
        }

        
        $colWidths = [
            1  => 5,   // NO
            2  => 18,  // Shipment Number
            3  => 14,  // Order No (OD)
            4  => 14,  // Ship-to Party
            5  => 16,  // Material
            6  => 36,  // Description
            7  => 12,  // Delivery Quantity
            8  => 10,  // Sales Unit
            9  => 28,  // Name of Ship-To Party
            10 => 22,  // Customer
            11 => 22,  // Location of Ship-To Party
            12 => 30,  // Street / Address
            13 => 14,  // Goods Issue Date
            14 => 22,  // SO Number
            15 => 12,  // TRANSPORT
            16 => 12,  // Volume (L)
            17 => 16,  // Batch No
            18 => 12,  // Exp. Date
            19 => 10,  // Sisa Hari
            20 => 8,   // Pallet
            21 => 12,  // Type Truck
            22 => 16,  // Container No
            23 => 14,  // Warehouse Location
            24 => 14,  // Status
            25 => 28,  // KET
        ];
        foreach ($colWidths as $c => $w) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth($w);
        }

        
        foreach ([1, 7, 8, 16, 19, 20] as $c) {
            $sheet->getStyle(
                Coordinate::stringFromColumnIndex($c) . '5:' .
                Coordinate::stringFromColumnIndex($c) . $row
            )->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        self::_download($sp, 'Outbound_Export_' . date('Y-m-d_His') . '.xlsx');
    }

    

    public static function exportOutboundWithItems($outbound, $items) {
        $excel = new self();
        $excel->setTitle('Outbound Order: ' . ($outbound['order_number'] ?? $outbound['shipment_number'] ?? '-'))
              ->setSubtitle('Customer: ' . ($outbound['customer_name'] ?? 'N/A') . ' | Date: ' . date('d M Y', strtotime($outbound['order_date'])))
              ->setHeaders(['Product', 'Qty', 'Pallets', 'Batch', 'Location', 'Notes']);

        foreach ($items as $item) {
            $uomPerPallet = $item['uom_per_pallet'] ?? 4;
            $qty = $item['quantity'] ?? $item['actual_qty'] ?? 0;
            $excel->addRow([
                $item['product_code'] . ' - ' . $item['product_name'],
                $qty,
                number_format($qty / $uomPerPallet, 2),
                $item['batch_number'] ?? '-',
                $item['location'] ?? '-',
                $item['notes'] ?? '-'
            ]);
        }

        $totalQty = array_sum(array_column($items, 'quantity')) + array_sum(array_column($items, 'actual_qty'));
        $excel->addSummary('Total Items', [count($items)]);
        $excel->addSummary('Total Qty', [$totalQty]);
        $excel->addSummary('Total Pallets', [number_format($totalQty / 4, 2)]);

        $excel->download(($outbound['order_number'] ?? $outbound['shipment_number'] ?? 'outbound') . '_detail.xlsx');
    }

    

    public static function exportStock($data) {
        $excel = new self();
        $excel->setTitle('K-one - Stock Report - Shell CKB')
              ->setSubtitle('Generated on: ' . date('d F Y H:i:s'))
              ->setHeaders(['Product Code', 'Product Name', 'Batch', 'Qty', 'Pallets', 'Expiry Date', 'Days Until Expiry', 'Location', 'Status']);

        foreach ($data as $item) {
            $daysUntil = $item['expiry_date'] ? floor((strtotime($item['expiry_date']) - time()) / 86400) : '-';

            $excel->addRow([
                $item['product_code'],
                $item['product_name'],
                $item['batch_number'] ?? '-',
                $item['quantity'],
                number_format($item['pallet'], 2),
                $item['expiry_date'] ? date('d M Y', strtotime($item['expiry_date'])) : '-',
                $daysUntil,
                $item['location'] ?? '-',
                $item['stock_status']
            ]);
        }

        $excel->download('Stock_Report_' . date('Y-m-d_His') . '.xlsx');
    }

    

    public static function exportLedger($data) {
        $excel = new self();
        $excel->setTitle('K-one - Stock Ledger - Shell CKB')
              ->setSubtitle('Generated on: ' . date('d F Y H:i:s'))
              ->setHeaders(['Date', 'Product', 'Type', 'Reference', 'Qty (Drums)', 'Balance (Drums)', 'Batch', 'Expiry Date', 'Notes']);

        foreach ($data as $item) {
            $qtyIn = $item['quantity_in'] ?? 0;
            $qtyOut = $item['quantity_out'] ?? 0;
            $balance = $item['balance'] ?? $item['balance_drums'] ?? 0;

            $excel->addRow([
                date('d M Y H:i', strtotime($item['transaction_date'] . ' ' . ($item['created_at'] ?? ''))),
                $item['product_code'] . ' - ' . $item['product_name'],
                $item['transaction_type'],
                $item['reference_number'],
                ($item['transaction_type'] === 'IN' ? '+' : '-') . ($qtyIn + $qtyOut),
                $balance,
                $item['batch_number'] ?? '-',
                $item['expiry_date'] ? date('d M Y', strtotime($item['expiry_date'])) : '-',
                $item['notes'] ?? '-'
            ]);
        }

        $excel->download('Stock_Ledger_' . date('Y-m-d_His') . '.xlsx');
    }

    

    public static function exportProducts($data) {
        $excel = new self();
        $excel->setTitle('K-one - Products - Shell CKB')
              ->setSubtitle('Generated on: ' . date('d F Y H:i:s'))
              ->setHeaders(['Product Code', 'Product Name', 'Category', 'Description', 'Drums/Pallet', 'Current Stock (Drums)', 'Current Stock (Pallets)']);

        foreach ($data as $item) {
            $excel->addRow([
                $item['product_code'],
                $item['product_name'],
                $item['category'] ?? '-',
                $item['description'] ?? '-',
                $item['drums_per_pallet'],
                $item['total_drums'] ?? 0,
                number_format($item['total_pallets'] ?? 0, 1)
            ]);
        }

        $excel->download('Products_' . date('Y-m-d_His') . '.xlsx');
    }

    


    public static function exportCustomers($data) {
        $excel = new self();
        $excel->setTitle('K-one - Customers - Shell CKB')
              ->setSubtitle('Generated on: ' . date('d F Y H:i:s'))
              ->setHeaders(['Customer Code', 'Customer Name', 'Contact Person', 'Phone', 'Email', 'Address']);

        foreach ($data as $item) {
            $excel->addRow([
                $item['customer_code'],
                $item['customer_name'],
                $item['contact_person'] ?? '-',
                $item['phone'] ?? '-',
                $item['email'] ?? '-',
                $item['address'] ?? '-'
            ]);
        }

        $excel->download('Customers_' . date('Y-m-d_His') . '.xlsx');
    }

    

    public static function exportDailyReport($report) {
        $excel = new self();
        $excel->setTitle('K-one - Daily Report - Shell CKB')
              ->setSubtitle('Report Date: ' . date('d F Y', strtotime($report['date'])));

        
        $excel->setHeaders(['Product Code', 'Product Name', 'Batches', 'Qty', 'Pallets', 'Nearest Expiry']);
        foreach ($report['stock_summary'] as $item) {
            $excel->addRow([
                $item['product_code'],
                $item['product_name'],
                $item['batches'],
                $item['total_drums'],
                number_format($item['total_pallets'], 1),
                $item['nearest_expiry'] ? date('d M Y', strtotime($item['nearest_expiry'])) : '-'
            ]);
        }

        $excel->download('Daily_Report_' . $report['date'] . '.xlsx');
    }

    

    public static function exportExpiringItems($data) {
        $excel = new self();
        $excel->setTitle('K-one - Expiring Items Alert - Shell CKB')
              ->setSubtitle('Generated on: ' . date('d F Y H:i:s'))
              ->setHeaders(['Product', 'Batch', 'Expiry Date', 'Days Left', 'Qty', 'Pallets', 'Location']);

        foreach ($data as $item) {
            $uomPerPallet = $item['uom_per_pallet'] ?? 4;
            $excel->addRow([
                $item['product_code'] . ' - ' . $item['product_name'],
                $item['batch_number'],
                date('d M Y', strtotime($item['expiry_date'])),
                $item['days_until_expiry'],
                $item['quantity'],
                number_format($item['pallet'], 2),
                $item['location'] ?? '-'
            ]);
        }

        $excel->download('Expiring_Items_' . date('Y-m-d_His') . '.xlsx');
    }

    

    public static function exportStockTake($stockTake, $items, $accuracy) {
        $excel = new self();
        $excel->setTitle('K-one - Stock Take - Shell CKB')
              ->setSubtitle('Stock Take No: ' . $stockTake['take_number'] . ' | Date: ' . date('d M Y', strtotime($stockTake['take_date'])));

        
        $excel->setHeaders(['Description', 'Value']);

        $excel->addRow(['Total Stock Take', $accuracy['total_stock_take']]);
        $excel->addRow(['Minus', $accuracy['minus']]);
        $excel->addRow(['Plus', $accuracy['plus']]);
        $excel->addRow(['Clear', $accuracy['clear']]);
        $excel->addRow(['Accuracy', $accuracy['accuracy'] . '%']);

        
        $excel->addRow(['', '']);

        
        $excel->setHeaders(['Product', 'Batch Number', 'Location', 'Qty System', 'Qty Physical', 'Difference', 'Status', 'Notes']);

        foreach ($items as $item) {
            $excel->addRow([
                $item['product_code'] . ' - ' . $item['product_name'],
                $item['batch_number'] ?? '-',
                $item['location'] ?? '-',
                $item['qty_system'],
                $item['qty_physical'],
                $item['difference'],
                $item['status'],
                $item['notes'] ?? '-'
            ]);
        }

        $excel->download($stockTake['take_number'] . '_Accuracy_' . date('Y-m-d_His') . '.xlsx');
    }

    // =========================================================================
    // Advanced Report Exports
    // =========================================================================

    /**
     * Export Stock Valuation report to Excel.
     *
     * @param array $data  Result from Report::getStockValuation()
     */
    public static function exportStockValuation(array $data) {
        if (empty($data['items'])) {
            die('Tidak ada data stock untuk valuation report.');
        }

        $sp    = new Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $sheet->setTitle('Stock Valuation');

        $headerColor = '013D3C';
        $subColor    = '026766';
        $hasCost     = $data['has_cost_data'] ?? false;
        $summary     = $data['summary'] ?? [];

        $headers = [
            'No', 'Product Code', 'Product Name', 'Category', 'UOM Type',
            'Batches', 'Total Qty', 'Total Pallets',
            'Nearest Expiry', 'Locations',
        ];
        if ($hasCost) {
            $headers[] = 'Unit Cost';
            $headers[] = 'Total Value';
        }

        $totalCols     = count($headers);
        $lastColLetter = Coordinate::stringFromColumnIndex($totalCols);

        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1',
            'K-one — Stock Valuation Report   |   Generated: ' . ($data['generated_at'] ?? date('Y-m-d H:i')) .
            '   |   ' . ($summary['total_products'] ?? 0) . ' products, ' .
            ($summary['total_batches'] ?? 0) . ' batches');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $headerColor]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getRowDimension(2)->setRowHeight(4);

        foreach ($headers as $c => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . '3', $h);
        }
        $sheet->getStyle("A3:{$lastColLetter}3")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $subColor]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '014F4E']]],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(20);
        $sheet->freezePane('A4');

        $row = 4;
        $num = 0;
        foreach ($data['items'] as $item) {
            $num++;
            $nearestExpiry = $item['nearest_expiry'] ? date('d M Y', strtotime($item['nearest_expiry'])) : '-';
            $rowData = [
                $num, $item['product_code'], $item['product_name'],
                $item['category'] ?? '-', $item['uom_type'] ?? '-',
                $item['batch_count'], $item['total_qty'], round($item['total_pallets'], 2),
                $nearestExpiry, $item['location_count'],
            ];
            if ($hasCost) {
                $rowData[] = $item['unit_cost'] > 0 ? number_format($item['unit_cost'], 2) : '-';
                $rowData[] = $item['total_value'] > 0 ? number_format($item['total_value'], 2) : '-';
            }
            foreach ($rowData as $c => $val) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . $row, $val);
            }
            $rangeFull = "A{$row}:{$lastColLetter}{$row}";
            $sheet->getStyle($rangeFull)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
                'font'    => ['size' => 9],
            ]);
            if ($row % 2 === 0) {
                $sheet->getStyle($rangeFull)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0FDFC');
            }
            $row++;
        }

        $totalRowData = ['', 'TOTAL', '', '', '',
            $summary['total_batches'] ?? 0, $summary['total_qty'] ?? 0,
            round($summary['total_pallets'] ?? 0, 2), '', '',
        ];
        if ($hasCost) { $totalRowData[] = ''; $totalRowData[] = number_format($summary['total_value'] ?? 0, 2); }
        foreach ($totalRowData as $c => $val) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . $row, $val);
        }
        $sheet->getStyle("A{$row}:{$lastColLetter}{$row}")->applyFromArray([
            'font'    => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $headerColor]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '013D3C']]],
        ]);

        foreach (range(1, $totalCols) as $c) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
        $sheet->getStyle('G4:G' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('H4:H' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');

        self::_download($sp, 'Stock_Valuation_' . date('Y-m-d_His') . '.xlsx');
    }

    /**
     * Export Inbound Receipt Summary to Excel.
     */
    public static function exportInboundSummary(array $data, string $dateFrom, string $dateTo) {
        $sp    = new Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $sheet->setTitle('Inbound Summary');

        $headerColor = '013D3C';
        $subColor    = '026766';
        $summary     = $data['summary'] ?? [];

        $sheet->mergeCells('A1:G1');
        $sheet->setCellValue('A1',
            'K-one — Inbound Receipt Summary   |   ' . $dateFrom . ' to ' . $dateTo .
            '   |   Generated: ' . date('d M Y H:i'));
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $headerColor]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        // Summary KPIs
        $kpis = [
            ['A3' => 'Total Orders',     'B3' => $summary['total_orders'] ?? 0,       'D3' => 'Total Qty Received', 'E3' => number_format($summary['total_qty_received'] ?? 0, 0)],
            ['A4' => 'Completed',        'B4' => $summary['completed_orders'] ?? 0,    'D4' => 'Total Pallets',      'E4' => number_format($summary['total_pallets'] ?? 0, 2)],
            ['A5' => 'In Progress',      'B5' => $summary['in_progress_orders'] ?? 0,  'D5' => 'Total Products',     'E5' => $summary['total_products'] ?? 0],
            ['A6' => 'Pending',          'B6' => $summary['pending_orders'] ?? 0],
        ];
        foreach ($kpis as $kpi) {
            foreach ($kpi as $cell => $val) {
                $sheet->setCellValue($cell, $val);
            }
        }
        foreach (['A3','A4','A5','A6','D3','D4','D5'] as $cell) {
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        // Daily Breakdown
        $row = 8;
        $sheet->setCellValue("A{$row}", 'DAILY BREAKDOWN');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
        $row++;

        $dailyHeaders = ['Date', 'Orders', 'Total Qty', 'Total Pallets'];
        self::_writeHeaderRow($sheet, $row, $dailyHeaders, $subColor);
        $row++;

        foreach (($data['daily_breakdown'] ?? []) as $d) {
            $sheet->setCellValue("A{$row}", $d['receipt_date'] ? date('d M Y', strtotime($d['receipt_date'])) : '-');
            $sheet->setCellValue("B{$row}", (int)$d['order_count']);
            $sheet->setCellValue("C{$row}", (float)$d['total_qty']);
            $sheet->setCellValue("D{$row}", round((float)$d['total_pallets'], 2));
            self::_styleDataRow($sheet, $row, 4, $row % 2 === 0);
            $row++;
        }

        // Product Breakdown
        $row += 2;
        $sheet->setCellValue("A{$row}", 'PRODUCT BREAKDOWN');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
        $row++;

        $prodHeaders = ['Product Code', 'Product Name', 'UOM', 'Orders', 'Total Qty', 'Total Pallets', 'Receipt Days'];
        self::_writeHeaderRow($sheet, $row, $prodHeaders, $subColor);
        $row++;

        foreach (($data['product_breakdown'] ?? []) as $p) {
            $sheet->setCellValue("A{$row}", $p['product_code']);
            $sheet->setCellValue("B{$row}", $p['product_name']);
            $sheet->setCellValue("C{$row}", $p['uom_type'] ?? '-');
            $sheet->setCellValue("D{$row}", (int)$p['order_count']);
            $sheet->setCellValue("E{$row}", (float)$p['total_qty']);
            $sheet->setCellValue("F{$row}", round((float)$p['total_pallets'], 2));
            $sheet->setCellValue("G{$row}", (int)$p['receipt_days']);
            self::_styleDataRow($sheet, $row, 7, $row % 2 === 0);
            $row++;
        }

        foreach (range(1, 7) as $c) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        self::_download($sp, 'Inbound_Summary_' . $dateFrom . '_to_' . $dateTo . '.xlsx');
    }

    /**
     * Export Outbound Shipment Summary to Excel.
     */
    public static function exportOutboundSummary(array $data, string $dateFrom, string $dateTo) {
        $sp    = new Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $sheet->setTitle('Outbound Summary');

        $headerColor = '013D3C';
        $subColor    = '026766';
        $summary     = $data['summary'] ?? [];

        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1',
            'K-one — Outbound Shipment Summary   |   ' . $dateFrom . ' to ' . $dateTo .
            '   |   Generated: ' . date('d M Y H:i'));
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $headerColor]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        // Summary KPIs
        $kpis = [
            ['A3' => 'Total Orders',       'B3' => $summary['total_orders'] ?? 0,      'D3' => 'Total Qty Shipped',   'E3' => number_format($summary['total_qty_shipped'] ?? 0, 0)],
            ['A4' => 'Shipped/Completed',  'B4' => $summary['shipped_orders'] ?? 0,    'D4' => 'Total Pallets',       'E4' => number_format($summary['total_pallets'] ?? 0, 2)],
            ['A5' => 'Pending',            'B5' => $summary['pending_orders'] ?? 0,    'D5' => 'Unique Customers',    'E5' => $summary['total_customers'] ?? 0],
            ['A6' => 'Total Products',     'B6' => $summary['total_products'] ?? 0],
        ];
        foreach ($kpis as $kpi) {
            foreach ($kpi as $cell => $val) {
                $sheet->setCellValue($cell, $val);
            }
        }
        foreach (['A3','A4','A5','A6','D3','D4','D5'] as $cell) {
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        // Daily Breakdown
        $row = 8;
        $sheet->setCellValue("A{$row}", 'DAILY BREAKDOWN');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
        $row++;

        $dailyHeaders = ['Date', 'Orders', 'Total Qty', 'Total Pallets', 'Customers'];
        self::_writeHeaderRow($sheet, $row, $dailyHeaders, $subColor);
        $row++;

        foreach (($data['daily_breakdown'] ?? []) as $d) {
            $sheet->setCellValue("A{$row}", $d['ship_date'] ? date('d M Y', strtotime($d['ship_date'])) : '-');
            $sheet->setCellValue("B{$row}", (int)$d['order_count']);
            $sheet->setCellValue("C{$row}", (float)$d['total_qty']);
            $sheet->setCellValue("D{$row}", round((float)$d['total_pallets'], 2));
            $sheet->setCellValue("E{$row}", (int)$d['customer_count']);
            self::_styleDataRow($sheet, $row, 5, $row % 2 === 0);
            $row++;
        }

        // Product Breakdown
        $row += 2;
        $sheet->setCellValue("A{$row}", 'PRODUCT BREAKDOWN');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
        $row++;

        $prodHeaders = ['Product Code', 'Product Name', 'UOM', 'Orders', 'Total Qty', 'Total Pallets', 'Customers'];
        self::_writeHeaderRow($sheet, $row, $prodHeaders, $subColor);
        $row++;

        foreach (($data['product_breakdown'] ?? []) as $p) {
            $sheet->setCellValue("A{$row}", $p['product_code']);
            $sheet->setCellValue("B{$row}", $p['product_name']);
            $sheet->setCellValue("C{$row}", $p['uom_type'] ?? '-');
            $sheet->setCellValue("D{$row}", (int)$p['order_count']);
            $sheet->setCellValue("E{$row}", (float)$p['total_qty']);
            $sheet->setCellValue("F{$row}", round((float)$p['total_pallets'], 2));
            $sheet->setCellValue("G{$row}", (int)$p['customer_count']);
            self::_styleDataRow($sheet, $row, 7, $row % 2 === 0);
            $row++;
        }

        // Customer Breakdown
        $row += 2;
        $sheet->setCellValue("A{$row}", 'CUSTOMER BREAKDOWN');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
        $row++;

        $custHeaders = ['Customer Code', 'Customer Name', 'Orders', 'Total Qty', 'Total Pallets'];
        self::_writeHeaderRow($sheet, $row, $custHeaders, $subColor);
        $row++;

        foreach (($data['customer_breakdown'] ?? []) as $c) {
            $sheet->setCellValue("A{$row}", $c['customer_code']);
            $sheet->setCellValue("B{$row}", $c['customer_name']);
            $sheet->setCellValue("C{$row}", (int)$c['order_count']);
            $sheet->setCellValue("D{$row}", (float)$c['total_qty']);
            $sheet->setCellValue("E{$row}", round((float)$c['total_pallets'], 2));
            self::_styleDataRow($sheet, $row, 5, $row % 2 === 0);
            $row++;
        }

        foreach (range(1, 7) as $c) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        self::_download($sp, 'Outbound_Summary_' . $dateFrom . '_to_' . $dateTo . '.xlsx');
    }

    /**
     * Export Inventory Turnover report to Excel.
     */
    public static function exportInventoryTurnover(array $data, string $dateFrom, string $dateTo) {
        if (empty($data['items'])) {
            die('Tidak ada data turnover untuk periode ini.');
        }

        $sp    = new Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $sheet->setTitle('Inventory Turnover');

        $headerColor = '013D3C';
        $subColor    = '026766';
        $summary     = $data['summary'] ?? [];
        $periodDays  = $data['period_days'] ?? 1;

        $sheet->mergeCells('A1:K1');
        $sheet->setCellValue('A1',
            'K-one — Inventory Turnover Report   |   ' . $dateFrom . ' to ' . $dateTo .
            ' (' . $periodDays . ' days)   |   Generated: ' . date('d M Y H:i'));
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $headerColor]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        // Summary KPIs
        $kpis = [
            ['A3' => 'Products Analyzed',  'B3' => $summary['products_analyzed'] ?? 0,  'D3' => 'Avg Turnover Rate',   'E3' => $summary['avg_turnover_rate'] ?? 0],
            ['A4' => 'Total Outbound',     'B4' => number_format($summary['total_outbound'] ?? 0, 0), 'D4' => 'Avg Daily Outbound', 'E4' => number_format($summary['avg_daily_outbound'] ?? 0, 2)],
            ['A5' => 'Total Inbound',      'B5' => number_format($summary['total_inbound'] ?? 0, 0),  'D5' => 'Current Stock',       'E5' => number_format($summary['total_current_stock'] ?? 0, 0)],
        ];
        foreach ($kpis as $kpi) {
            foreach ($kpi as $cell => $val) {
                $sheet->setCellValue($cell, $val);
            }
        }
        foreach (['A3','A4','A5','D3','D4','D5'] as $cell) {
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        // Turnover table
        $row = 7;
        $headers = [
            'No', 'Product Code', 'Product Name', 'UOM',
            'Total Outbound', 'Total Inbound', 'Current Stock',
            'Avg Daily Out', 'Turnover Rate', 'Days of Stock', 'Net Movement',
        ];
        self::_writeHeaderRow($sheet, $row, $headers, $subColor);
        $sheet->getRowDimension($row)->setRowHeight(28);
        $sheet->freezePane('A' . ($row + 1));
        $row++;

        $num = 0;
        foreach ($data['items'] as $item) {
            $num++;
            $daysOfStock = $item['days_of_stock'] !== null ? $item['days_of_stock'] : 'N/A';

            $rowData = [
                $num, $item['product_code'], $item['product_name'], $item['uom_type'] ?? '-',
                $item['total_outbound'], $item['total_inbound'], $item['current_stock'],
                $item['avg_daily_outbound'], $item['turnover_rate'], $daysOfStock, $item['net_movement'],
            ];
            foreach ($rowData as $c => $val) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . $row, $val);
            }

            $rangeFull = "A{$row}:K{$row}";
            $sheet->getStyle($rangeFull)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
                'font'    => ['size' => 9],
            ]);
            if ($row % 2 === 0) {
                $sheet->getStyle($rangeFull)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0FDFC');
            }

            // Color-code turnover rate
            if ($item['turnover_rate'] >= 1.0)
                $sheet->getStyle('I' . $row)->getFont()->getColor()->setRGB('166534');
            elseif ($item['turnover_rate'] >= 0.5)
                $sheet->getStyle('I' . $row)->getFont()->getColor()->setRGB('92400E');
            else
                $sheet->getStyle('I' . $row)->getFont()->getColor()->setRGB('991B1B');

            // Color-code days of stock
            if ($daysOfStock !== 'N/A') {
                if ($daysOfStock > 180)
                    $sheet->getStyle('J' . $row)->getFont()->getColor()->setRGB('991B1B');
                elseif ($daysOfStock > 90)
                    $sheet->getStyle('J' . $row)->getFont()->getColor()->setRGB('92400E');
                else
                    $sheet->getStyle('J' . $row)->getFont()->getColor()->setRGB('166534');
            }

            $row++;
        }

        foreach ([5, 6, 7, 8, 11] as $c) {
            $colLetter = Coordinate::stringFromColumnIndex($c);
            $sheet->getStyle("{$colLetter}8:{$colLetter}" . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
        }
        $sheet->getStyle("I8:I" . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("J8:J" . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.0');

        foreach (range(1, 11) as $c) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        self::_download($sp, 'Inventory_Turnover_' . $dateFrom . '_to_' . $dateTo . '.xlsx');
    }

    // =========================================================================
    // PDF Export (browser-printable HTML)
    // =========================================================================

    /**
     * Export a report as a browser-printable HTML page (PDF-ready).
     * Uses well-formatted HTML+CSS that renders via browser Print > Save as PDF.
     *
     * @param string $html     Full HTML body content for the report
     * @param string $filename Download filename (without extension)
     */
    public static function exportPdfReport(string $html, string $filename) {
        if (ob_get_length()) ob_end_clean();

        $fullHtml = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . htmlspecialchars($filename) . '</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial,Helvetica,sans-serif;font-size:11px;color:#1a1a1a;background:#fff;padding:20px}
.rpt-hdr{background:#013D3C;color:#fff;padding:16px 24px;margin-bottom:16px;border-radius:4px}
.rpt-hdr h1{font-size:18px;font-weight:700;margin-bottom:4px}
.rpt-hdr .sub{font-size:11px;opacity:.85}
.rpt-hdr .gen{font-size:10px;opacity:.7;margin-top:4px}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px}
.kpi-card{background:#f0fdfc;border:1px solid #d1d5db;border-radius:4px;padding:12px 16px;text-align:center}
.kpi-val{font-size:22px;font-weight:700;color:#013D3C}
.kpi-lbl{font-size:10px;color:#555;margin-top:4px;text-transform:uppercase;letter-spacing:.5px}
table{width:100%;border-collapse:collapse;margin-bottom:20px;font-size:10px}
th{background:#026766;color:#fff;padding:8px 10px;text-align:left;font-weight:600;border:1px solid #014F4E}
td{padding:6px 10px;border:1px solid #d1d5db}
tr:nth-child(even) td{background:#f0fdfc}
.total td{background:#013D3C!important;color:#fff;font-weight:700}
.sec-title{font-size:14px;font-weight:700;color:#013D3C;margin:20px 0 10px 0;padding-bottom:4px;border-bottom:2px solid #026766}
.txt-r{text-align:right}.txt-c{text-align:center}
.tg{color:#166534}.ta{color:#92400E}.tr{color:#991B1B}
@media print{body{padding:0}.no-print{display:none}}
</style>
</head>
<body>
' . $html . '
<div class="no-print" style="text-align:center;margin-top:20px;padding:10px;background:#f5f5f5;border-radius:4px">
<button onclick="window.print()" style="padding:10px 24px;font-size:14px;background:#013D3C;color:#fff;border:none;border-radius:4px;cursor:pointer">
Print / Save as PDF
</button>
</div>
</body></html>';

        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: inline;filename="' . $filename . '.html"');
        echo $fullHtml;
        exit;
    }

    // =========================================================================
    // Shared formatting helpers for advanced exports
    // =========================================================================

    /**
     * Write a styled header row.
     */
    private static function _writeHeaderRow($sheet, int $row, array $headers, string $bgColor) {
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        foreach ($headers as $c => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . $row, $h);
        }
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '014F4E']]],
        ]);
    }

    /**
     * Style a data row with borders and optional zebra stripe.
     */
    private static function _styleDataRow($sheet, int $row, int $colCount, bool $isEven) {
        $lastCol = Coordinate::stringFromColumnIndex($colCount);
        $range = "A{$row}:{$lastCol}{$row}";
        $sheet->getStyle($range)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
            'font'    => ['size' => 9],
        ]);
        if ($isEven) {
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0FDFC');
        }
    }
}
?>
