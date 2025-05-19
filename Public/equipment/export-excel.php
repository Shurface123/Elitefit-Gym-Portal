<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require EquipmentManager role to access this page
requireRole('EquipmentManager');

// Get report parameters
$reportType = isset($_GET['type']) ? $_GET['type'] : 'equipment';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$category = isset($_GET['category']) ? $_GET['category'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

// Connect to database
$conn = connectDB();

// Set filename based on report type
$filename = strtolower(str_replace(' ', '_', $reportType)) . '_report_' . date('Y-m-d') . '.xlsx';

// Get report data based on type
$reportData = [];
$reportTitle = '';

switch ($reportType) {
    case 'equipment':
        $reportTitle = 'Equipment Report';
        
        // Build query
        $query = "
            SELECT e.*, 
                   COALESCE(m.last_maintenance_date, 'Never') as last_maintenance,
                   u.name as updated_by_name
            FROM equipment e
            LEFT JOIN (
                SELECT equipment_id, MAX(scheduled_date) as last_maintenance_date
                FROM maintenance_schedule
                WHERE status = 'Completed'
                GROUP BY equipment_id
            ) m ON e.id = m.equipment_id
            LEFT JOIN users u ON e.updated_by = u.id
            WHERE 1=1
        ";
        $params = [];
        
        if (!empty($category)) {
            $query .= " AND e.type = :category";
            $params[':category'] = $category;
        }
        
        if (!empty($status)) {
            $query .= " AND e.status = :status";
            $params[':status'] = $status;
        }
        
        $query .= " ORDER BY e.name ASC";
        
        $stmt = $conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Define columns for Excel
        $columns = [
            'Name' => 'name',
            'Type' => 'type',
            'Status' => 'status',
            'Location' => 'location',
            'Last Maintenance' => 'last_maintenance',
            'Updated By' => 'updated_by_name',
            'Created At' => 'created_at'
        ];
        break;
        
    case 'maintenance':
        $reportTitle = 'Maintenance Report';
        
        // Build query
        $query = "
            SELECT m.*, e.name as equipment_name, e.type as equipment_type
            FROM maintenance_schedule m
            JOIN equipment e ON m.equipment_id = e.id
            WHERE m.scheduled_date BETWEEN :date_from AND :date_to
        ";
        $params = [
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo
        ];
        
        if (!empty($category)) {
            $query .= " AND e.type = :category";
            $params[':category'] = $category;
        }
        
        if (!empty($status)) {
            $query .= " AND m.status = :status";
            $params[':status'] = $status;
        }
        
        $query .= " ORDER BY m.scheduled_date DESC";
        
        $stmt = $conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Define columns for Excel
        $columns = [
            'Equipment' => 'equipment_name',
            'Type' => 'equipment_type',
            'Scheduled Date' => 'scheduled_date',
            'Description' => 'description',
            'Status' => 'status',
            'Created At' => 'created_at'
        ];
        break;
        
    case 'inventory':
        $reportTitle = 'Inventory Report';
        
        // Build query
        $query = "
            SELECT i.*, u.name as updated_by_name
            FROM inventory i
            LEFT JOIN users u ON i.updated_by = u.id
            WHERE 1=1
        ";
        $params = [];
        
        if (!empty($category)) {
            $query .= " AND i.category = :category";
            $params[':category'] = $category;
        }
        
        if (!empty($status)) {
            switch ($status) {
                case 'low':
                    $query .= " AND i.quantity <= i.min_quantity AND i.quantity > 0";
                    break;
                case 'out':
                    $query .= " AND i.quantity = 0";
                    break;
                case 'in':
                    $query .= " AND i.quantity > i.min_quantity";
                    break;
            }
        }
        
        $query .= " ORDER BY i.name ASC";
        
        $stmt = $conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Define columns for Excel
        $columns = [
            'Name' => 'name',
            'Category' => 'category',
            'Quantity' => 'quantity',
            'Min. Quantity' => 'min_quantity',
            'Unit Price' => 'unit_price',
            'Value' => function($row) { return $row['quantity'] * $row['unit_price']; },
            'Location' => 'location',
            'Updated By' => 'updated_by_name'
        ];
        break;
        
    case 'activity':
        $reportTitle = 'Activity Log Report';
        
        // Build query
        $query = "
            SELECT a.*, u.name as user_name, e.name as equipment_name
            FROM activity_log a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN equipment e ON a.equipment_id = e.id
            WHERE a.timestamp BETWEEN :date_from AND :date_to
        ";
        $params = [
            ':date_from' => $dateFrom . ' 00:00:00',
            ':date_to' => $dateTo . ' 23:59:59'
        ];
        
        $query .= " ORDER BY a.timestamp DESC";
        
        $stmt = $conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Define columns for Excel
        $columns = [
            'Timestamp' => 'timestamp',
            'User' => 'user_name',
            'Equipment' => 'equipment_name',
            'Action' => 'action'
        ];
        break;
}

// Generate Excel file
require_once __DIR__ . '/../../vendor/autoload.php'; // Assuming PHPSpreadsheet is installed via Composer

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Create new Spreadsheet object
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set document properties
$spreadsheet->getProperties()
    ->setCreator('EliteFit Gym')
    ->setLastModifiedBy('EliteFit Gym')
    ->setTitle($reportTitle)
    ->setSubject($reportTitle)
    ->setDescription('Report generated from EliteFit Gym Equipment Manager Dashboard')
    ->setKeywords('elitefit gym equipment report')
    ->setCategory('Report');

// Set report title
$sheet->setCellValue('A1', $reportTitle);
$sheet->mergeCells('A1:G1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

// Set report date range if applicable
if ($reportType === 'maintenance' || $reportType === 'activity') {
    $sheet->setCellValue('A2', "Date Range: $dateFrom to $dateTo");
    $sheet->mergeCells('A2:G2');
    $sheet->getStyle('A2')->getFont()->setSize(12);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $rowIndex = 4;
} else {
    $rowIndex = 3;
}

// Set column headers
$colIndex = 'A';
foreach ($columns as $header => $field) {
    $sheet->setCellValue($colIndex . $rowIndex, $header);
    $sheet->getStyle($colIndex . $rowIndex)->getFont()->setBold(true);
    $sheet->getStyle($colIndex . $rowIndex)->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setRGB('DDDDDD');
    $colIndex++;
}

// Fill data
$rowIndex++;
foreach ($reportData as $row) {
    $colIndex = 'A';
    foreach ($columns as $header => $field) {
        if (is_callable($field)) {
            $value = $field($row);
        } else {
            $value = $row[$field] ?? 'N/A';
            
            // Format dates
            if (strpos($field, 'date') !== false || strpos($field, 'timestamp') !== false || $field === 'created_at') {
                if ($value !== 'N/A' && $value !== 'Never') {
                    $value = date('M d, Y', strtotime($value));
                }
            }
            
            // Format currency
            if ($field === 'unit_price' || $header === 'Value') {
                if (is_numeric($value)) {
                    $value = '$' . number_format($value, 2);
                }
            }
        }
        
        $sheet->setCellValue($colIndex . $rowIndex, $value);
        $colIndex++;
    }
    $rowIndex++;
}

// Auto size columns
foreach (range('A', $colIndex) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Set borders for all cells
$styleArray = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
        ],
    ],
];
$sheet->getStyle('A' . ($reportType === 'maintenance' || $reportType === 'activity' ? '4' : '3') . ':' . $colIndex . ($rowIndex - 1))->applyFromArray($styleArray);

// Create writer and output file
$writer = new Xlsx($spreadsheet);

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Save file to output
$writer->save('php://output');
exit;
