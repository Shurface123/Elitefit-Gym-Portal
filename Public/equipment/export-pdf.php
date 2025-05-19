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
$filename = strtolower(str_replace(' ', '_', $reportType)) . '_report_' . date('Y-m-d') . '.pdf';

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
        
        // Define columns for PDF
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
        
        // Define columns for PDF
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
        
        // Define columns for PDF
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
        
        // Define columns for PDF
        $columns = [
            'Timestamp' => 'timestamp',
            'User' => 'user_name',
            'Equipment' => 'equipment_name',
            'Action' => 'action'
        ];
        break;
}

// Generate PDF file
require_once __DIR__ . '/../../vendor/autoload.php'; // Assuming TCPDF is installed via Composer

// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('EliteFit Gym');
$pdf->SetAuthor('EliteFit Gym');
$pdf->SetTitle($reportTitle);
$pdf->SetSubject($reportTitle);
$pdf->SetKeywords('elitefit, gym, equipment, report');

// Set default header data
$pdf->SetHeaderData('', 0, $reportTitle, 'Generated on: ' . date('F j, Y, g:i a'));

// Set header and footer fonts
$pdf->setHeaderFont(['helvetica', '', 10]);
$pdf->setFooterFont(['helvetica', '', 8]);

// Set default monospaced font
$pdf->SetDefaultMonospacedFont('courier');

// Set margins
$pdf->SetMargins(15, 20, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 15);

// Set image scale factor
$pdf->setImageScale(1.25);

// Set font
$pdf->SetFont('helvetica', '', 10);

// Add a page
$pdf->AddPage();

// Add company information
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'EliteFit Gym', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 6, '123 Fitness Street, Healthville', 0, 1, 'L');
$pdf->Cell(0, 6, 'Phone: (555) 123-4567', 0, 1, 'L');
$pdf->Cell(0, 6, 'Email: info@elitefit.example.com', 0, 1, 'L');
$pdf->Ln(10);

// Add date range if applicable
if ($reportType === 'maintenance' || $reportType === 'activity') {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, "Date Range: $dateFrom to $dateTo", 0, 1, 'C');
    $pdf->Ln(5);
}

// Create table
$pdf->SetFont('helvetica', 'B', 10);

// Calculate column widths based on number of columns
$columnCount = count($columns);
$pageWidth = $pdf->getPageWidth() - 30; // Adjust for margins
$columnWidth = $pageWidth / $columnCount;

// Add table header
$pdf->SetFillColor(221, 221, 221);
$pdf->SetTextColor(0);
$pdf->SetDrawColor(0);
$pdf->SetLineWidth(0.3);

foreach ($columns as $header => $field) {
    $pdf->Cell($columnWidth, 7, $header, 1, 0, 'C', 1);
}
$pdf->Ln();

// Add table rows
$pdf->SetFont('helvetica', '', 9);
$pdf->SetFillColor(245, 245, 245);
$fill = false;

foreach ($reportData as $row) {
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
        
        // Truncate long text
        if (strlen($value) > 30) {
            $value = substr($value, 0, 27) . '...';
        }
        
        $pdf->Cell($columnWidth, 6, $value, 1, 0, 'L', $fill);
    }
    $pdf->Ln();
    $fill = !$fill;
}

// Add summary information
$pdf->Ln(10);
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 10, 'Report Summary', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 6, 'Total Records: ' . count($reportData), 0, 1, 'L');

if ($reportType === 'inventory') {
    // Calculate inventory statistics
    $totalItems = count($reportData);
    $totalQuantity = 0;
    $totalValue = 0;
    $lowStockCount = 0;
    $outOfStockCount = 0;
    
    foreach ($reportData as $item) {
        $totalQuantity += $item['quantity'];
        $totalValue += $item['quantity'] * $item['unit_price'];
        
        if ($item['quantity'] <= 0) {
            $outOfStockCount++;
        } elseif ($item['quantity'] <= $item['min_quantity']) {
            $lowStockCount++;
        }
    }
    
    $pdf->Cell(0, 6, 'Total Quantity: ' . $totalQuantity, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Total Value: $' . number_format($totalValue, 2), 0, 1, 'L');
    $pdf->Cell(0, 6, 'Low Stock Items: ' . $lowStockCount, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Out of Stock Items: ' . $outOfStockCount, 0, 1, 'L');
}

// Close and output PDF
$pdf->Output($filename, 'D');
exit;
