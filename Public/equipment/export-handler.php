<?php
// Prevent any output before headers
ob_start();

/**
 * Export Handler for Equipment Manager Reports
 * Handles exporting reports in various formats (Excel, PDF, CSV)
 */

// Function to export report data
function exportReport($conn, $reportType, $startDate, $endDate, $equipmentType, $status, $exportFormat) {
    // Get report data
    $reportData = getReportData($conn, $reportType, $startDate, $endDate, $equipmentType, $status);
    
    // Set filename
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "{$reportType}_report_{$timestamp}";
    
    // Export based on format
    switch ($exportFormat) {
        case 'excel':
            exportExcel($reportData, $reportType, $filename);
            break;
        case 'pdf':
            exportPDF($reportData, $reportType, $filename);
            break;
        case 'csv':
            exportCSV($reportData, $reportType, $filename);
            break;
        default:
            // Invalid format
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['error' => 'Invalid export format']);
            exit();
    }
}

// Function to get report data
function getReportData($conn, $reportType, $startDate, $endDate, $equipmentType, $status) {
    $data = [];
    
    try {
        switch ($reportType) {
            case 'inventory':
                $query = "SELECT e.*, 
                         (SELECT COUNT(*) FROM maintenance m WHERE m.equipment_id = e.id AND m.status = 'pending') as pending_maintenance,
                         (SELECT MAX(maintenance_date) FROM maintenance m WHERE m.equipment_id = e.id AND m.status = 'completed') as last_maintenance
                         FROM equipment e WHERE 1=1";
                
                if (!empty($equipmentType)) {
                    $query .= " AND e.type = :equipmentType";
                }
                
                if (!empty($status)) {
                    $query .= " AND e.status = :status";
                }
                
                $query .= " ORDER BY e.name";
                
                $stmt = $conn->prepare($query);
                
                if (!empty($equipmentType)) {
                    $stmt->bindParam(':equipmentType', $equipmentType);
                }
                
                if (!empty($status)) {
                    $stmt->bindParam(':status', $status);
                }
                
                $stmt->execute();
                break;
                
            case 'maintenance':
                $query = "SELECT m.*, e.name as equipment_name, e.type as equipment_type 
                         FROM maintenance m
                         JOIN equipment e ON m.equipment_id = e.id
                         WHERE m.maintenance_date BETWEEN :startDate AND :endDate";
                
                if (!empty($equipmentType)) {
                    $query .= " AND e.type = :equipmentType";
                }
                
                if (!empty($status)) {
                    $query .= " AND m.status = :status";
                }
                
                $query .= " ORDER BY m.maintenance_date DESC";
                
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':startDate', $startDate);
                $stmt->bindParam(':endDate', $endDate);
                
                if (!empty($equipmentType)) {
                    $stmt->bindParam(':equipmentType', $equipmentType);
                }
                
                if (!empty($status)) {
                    $stmt->bindParam(':status', $status);
                }
                
                $stmt->execute();
                break;
                
            case 'usage':
                $query = "SELECT eu.*, e.name as equipment_name, e.type as equipment_type, 
                         u.username as user_name
                         FROM equipment_usage eu
                         JOIN equipment e ON eu.equipment_id = e.id
                         LEFT JOIN users u ON eu.user_id = u.id
                         WHERE eu.usage_date BETWEEN :startDate AND :endDate";
                
                if (!empty($equipmentType)) {
                    $query .= " AND e.type = :equipmentType";
                }
                
                $query .= " ORDER BY eu.usage_date DESC";
                
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':startDate', $startDate);
                $stmt->bindParam(':endDate', $endDate);
                
                if (!empty($equipmentType)) {
                    $stmt->bindParam(':equipmentType', $equipmentType);
                }
                
                $stmt->execute();
                break;
                
            case 'cost':
                $query = "SELECT e.id, e.name, e.type, e.purchase_date, e.purchase_cost,
                         (SELECT SUM(cost) FROM maintenance m WHERE m.equipment_id = e.id AND m.maintenance_date BETWEEN :startDate AND :endDate) as maintenance_cost,
                         (SELECT COUNT(*) FROM maintenance m WHERE m.equipment_id = e.id AND m.maintenance_date BETWEEN :startDate AND :endDate) as maintenance_count
                         FROM equipment e WHERE 1=1";
                
                if (!empty($equipmentType)) {
                    $query .= " AND e.type = :equipmentType";
                }
                
                if (!empty($status)) {
                    $query .= " AND e.status = :status";
                }
                
                $query .= " ORDER BY e.name";
                
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':startDate', $startDate);
                $stmt->bindParam(':endDate', $endDate);
                
                if (!empty($equipmentType)) {
                    $stmt->bindParam(':equipmentType', $equipmentType);
                }
                
                if (!empty($status)) {
                    $stmt->bindParam(':status', $status);
                }
                
                $stmt->execute();
                break;
                
            case 'performance':
                $query = "SELECT e.id, e.name, e.type, 
                         (SELECT COUNT(*) FROM equipment_usage eu WHERE eu.equipment_id = e.id AND eu.usage_date BETWEEN :startDate AND :endDate) as usage_count,
                         (SELECT SUM(duration) FROM equipment_usage eu WHERE eu.equipment_id = e.id AND eu.usage_date BETWEEN :startDate AND :endDate) as total_duration,
                         (SELECT COUNT(*) FROM maintenance m WHERE m.equipment_id = e.id AND m.maintenance_date BETWEEN :startDate AND :endDate) as maintenance_count
                         FROM equipment e WHERE 1=1";
                
                if (!empty($equipmentType)) {
                    $query .= " AND e.type = :equipmentType";
                }
                
                if (!empty($status)) {
                    $query .= " AND e.status = :status";
                }
                
                $query .= " ORDER BY usage_count DESC";
                
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':startDate', $startDate);
                $stmt->bindParam(':endDate', $endDate);
                
                if (!empty($equipmentType)) {
                    $stmt->bindParam(':equipmentType', $equipmentType);
                }
                
                if (!empty($status)) {
                    $stmt->bindParam(':status', $status);
                }
                
                $stmt->execute();
                break;
        }
        
        if (isset($stmt) && $stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data[] = $row;
            }
        }
    } catch (PDOException $e) {
        error_log("Error in getReportData: " . $e->getMessage());
    }
    
    return $data;
}

// Function to export data as Excel
function exportExcel($data, $reportType, $filename) {
    // Clear any previous output
    if (ob_get_length()) ob_clean();
    
    // Require PhpSpreadsheet library
    require_once '../../vendor/autoload.php';
    
    // Create new Spreadsheet object
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Set document properties
    $spreadsheet->getProperties()
        ->setCreator('EliteFit Gym')
        ->setLastModifiedBy('EliteFit Gym')
        ->setTitle('Equipment Manager Report')
        ->setSubject($reportType . ' Report')
        ->setDescription('Report generated from Equipment Manager')
        ->setKeywords('equipment report')
        ->setCategory('Reports');
    
    // Set headers based on report type
    $headers = getReportHeaders($reportType);
    $columns = array_keys($headers);
    
    // Add headers to first row
    $col = 1;
    foreach ($headers as $header) {
        $sheet->setCellValueByColumnAndRow($col++, 1, $header);
    }
    
    // Style header row
    $headerStyle = [
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'FF6600'],
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
            ],
        ],
    ];
    
    $sheet->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . '1')->applyFromArray($headerStyle);
    
    // Add data
    $row = 2;
    foreach ($data as $item) {
        $col = 1;
        foreach ($columns as $column) {
            $value = isset($item[$column]) ? $item[$column] : '';
            
            // Format dates
            if (strpos($column, 'date') !== false && !empty($value)) {
                $value = date('Y-m-d', strtotime($value));
                // Set as Excel date
                $sheet->getCellByColumnAndRow($col, $row)->setValueExplicit(
                    \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($value),
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                );
                $sheet->getStyleByColumnAndRow($col, $row)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            } else if (strpos($column, 'cost') !== false && !empty($value)) {
                // Format currency
                $sheet->setCellValueByColumnAndRow($col, $row, (float)$value);
                $sheet->getStyleByColumnAndRow($col, $row)->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_CURRENCY_USD_SIMPLE);
            } else {
                $sheet->setCellValueByColumnAndRow($col, $row, $value);
            }
            $col++;
        }
        $row++;
    }
    
    // Auto size columns
    foreach (range(1, count($headers)) as $col) {
        $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
    }
    
    // Style data cells
    $dataStyle = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                'color' => ['rgb' => 'DDDDDD'],
            ],
        ],
    ];
    
    if (count($data) > 0) {
        $sheet->getStyle('A2:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . ($row - 1))->applyFromArray($dataStyle);
    }
    
    // Create Excel file
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    
    // Set headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');
    header('Expires: 0');
    header('Pragma: public');
    
    // Save file to output
    $writer->save('php://output');
    exit();
}

// Function to export data as PDF
function exportPDF($data, $reportType, $filename) {
    // Clear any previous output that might interfere with PDF headers
    if (ob_get_length()) ob_clean();
    
    // Require TCPDF library
    require_once '../../vendor/tecnickcom/tcpdf/tcpdf.php';
    
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Disable default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set document information
    $pdf->SetCreator('EliteFit Gym');
    $pdf->SetAuthor('EliteFit Gym');
    $pdf->SetTitle('Equipment Manager Report');
    $pdf->SetSubject($reportType . ' Report');
    $pdf->SetKeywords('equipment, report');
    
    // Set margins
    $pdf->SetMargins(15, 15, 15);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Set image scale factor
    $pdf->setImageScale(1.25);
    
    // Set font
    $pdf->SetFont('helvetica', '', 10);
    
    // Add a page
    $pdf->AddPage();
    
    // Add custom header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'EliteFit Gym - Equipment Manager', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'I', 12);
    $pdf->Cell(0, 10, ucfirst($reportType) . ' Report - ' . date('Y-m-d H:i:s'), 0, 1, 'C');
    $pdf->Ln(5);
    
    // Get headers based on report type
    $headers = getReportHeaders($reportType);
    $columns = array_keys($headers);
    
    // Create table header
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(255, 102, 0);
    $pdf->SetTextColor(255);
    
    // Calculate column width
    $pageWidth = $pdf->getPageWidth() - 30; // 30 = left margin + right margin
    $colWidth = $pageWidth / count($headers);
    
    // Add header row
    foreach ($headers as $header) {
        $pdf->Cell($colWidth, 7, $header, 1, 0, 'C', 1);
    }
    $pdf->Ln();
    
    // Add data rows
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor(248, 249, 250);
    $pdf->SetTextColor(0);
    
    $fill = false;
    foreach ($data as $item) {
        foreach ($columns as $column) {
            $value = isset($item[$column]) ? $item[$column] : '';
            
            // Format dates
            if (strpos($column, 'date') !== false && !empty($value)) {
                $value = date('Y-m-d', strtotime($value));
            }
            
            // Format currency
            if (strpos($column, 'cost') !== false && !empty($value)) {
                $value = '$' . number_format($value, 2);
            }
            
            $pdf->Cell($colWidth, 6, $value, 1, 0, 'L', $fill);
        }
        $pdf->Ln();
        $fill = !$fill; // Alternate row colors
    }
    
    // Close and output PDF document
    $pdf->Output($filename . '.pdf', 'D');
    exit();
}

// Function to export data as CSV
function exportCSV($data, $reportType, $filename) {
    // Get headers based on report type
    $headers = getReportHeaders($reportType);
    $columns = array_keys($headers);
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Set headers for download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment;filename="' . $filename . '.csv"');
    
    // Add headers to CSV
    fputcsv($output, array_values($headers));
    
    // Add data rows
    foreach ($data as $item) {
        $row = [];
        
        foreach ($columns as $column) {
            $value = isset($item[$column]) ? $item[$column] : '';
            
            // Format dates
            if (strpos($column, 'date') !== false && !empty($value)) {
                $value = date('Y-m-d', strtotime($value));
            }
            
            $row[] = $value;
        }
        
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

// Function to get report headers based on report type
function getReportHeaders($reportType) {
    switch ($reportType) {
        case 'inventory':
            return [
                'id' => 'ID',
                'name' => 'Name',
                'type' => 'Type',
                'status' => 'Status',
                'purchase_date' => 'Purchase Date',
                'purchase_cost' => 'Purchase Cost',
                'last_maintenance' => 'Last Maintenance',
                'pending_maintenance' => 'Pending Maintenance'
            ];
            
        case 'maintenance':
            return [
                'id' => 'ID',
                'equipment_name' => 'Equipment',
                'equipment_type' => 'Type',
                'maintenance_date' => 'Date',
                'description' => 'Description',
                'technician' => 'Technician',
                'status' => 'Status',
                'cost' => 'Cost'
            ];
            
        case 'usage':
            return [
                'id' => 'ID',
                'equipment_name' => 'Equipment',
                'equipment_type' => 'Type',
                'user_name' => 'User',
                'usage_date' => 'Date',
                'duration' => 'Duration (min)',
                'notes' => 'Notes'
            ];
            
        case 'cost':
            return [
                'id' => 'ID',
                'name' => 'Equipment',
                'type' => 'Type',
                'purchase_date' => 'Purchase Date',
                'purchase_cost' => 'Purchase Cost',
                'maintenance_cost' => 'Maintenance Cost',
                'maintenance_count' => 'Maintenance Count'
            ];
            
        case 'performance':
            return [
                'id' => 'ID',
                'name' => 'Equipment',
                'type' => 'Type',
                'usage_count' => 'Usage Count',
                'total_duration' => 'Total Duration (min)',
                'maintenance_count' => 'Maintenance Count'
            ];
            
        default:
            return [];
    }
}
