<?php
// Prevent any output before headers
ob_start();
session_start();

// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require EquipmentManager role to access this page
requireRole('EquipmentManager');

// Include theme helper
require_once 'dashboard-theme-helper.php';

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Get theme preference (default to dark)
$theme = getThemePreference($userId) ?: 'dark';
$themeClasses = getThemeClasses($theme);

// Connect to database
$conn = connectDB();

// Handle form submissions
$message = '';
$messageType = '';

// Helper function to safely format numbers
function safeNumberFormat($value, $decimals = 0) {
    return number_format($value ?? 0, $decimals);
}

// Helper function to safely escape HTML
function safeHtmlSpecialChars($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Create necessary tables if they don't exist
$createEquipmentTableStmt = $conn->prepare("
CREATE TABLE IF NOT EXISTS equipment (
id INT NOT NULL AUTO_INCREMENT,
name VARCHAR(255) NOT NULL,
type VARCHAR(100) NOT NULL,
brand VARCHAR(100) DEFAULT NULL,
model VARCHAR(100) DEFAULT NULL,
serial_number VARCHAR(100) DEFAULT NULL,
status ENUM('Available', 'In Use', 'Maintenance', 'Out of Order', 'Retired') DEFAULT 'Available',
location VARCHAR(255) DEFAULT NULL,
purchase_date DATE DEFAULT NULL,
warranty_expiry DATE DEFAULT NULL,
cost DECIMAL(10,2) DEFAULT 0.00,
manufacturer VARCHAR(255) DEFAULT NULL,
condition_rating INT DEFAULT 5,
usage_hours INT DEFAULT 0,
max_weight DECIMAL(8,2) DEFAULT NULL,
dimensions VARCHAR(100) DEFAULT NULL,
power_requirements VARCHAR(100) DEFAULT NULL,
safety_features TEXT,
maintenance_schedule VARCHAR(100) DEFAULT 'Monthly',
last_maintenance_date DATE DEFAULT NULL,
next_maintenance_date DATE DEFAULT NULL,
qr_code VARCHAR(255) DEFAULT NULL,
image_url VARCHAR(500) DEFAULT NULL,
manual_url VARCHAR(500) DEFAULT NULL,
notes TEXT,
is_active BOOLEAN DEFAULT TRUE,
created_by INT DEFAULT NULL,
updated_by INT DEFAULT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (id),
INDEX idx_type (type),
INDEX idx_status (status),
INDEX idx_location (location),
INDEX idx_serial (serial_number),
INDEX idx_active (is_active),
FULLTEXT(name, type, brand, model, manufacturer, notes)
)
");
$createEquipmentTableStmt->execute();

// Create maintenance table if it doesn't exist
$createMaintenanceTableStmt = $conn->prepare("
CREATE TABLE IF NOT EXISTS maintenance (
id INT NOT NULL AUTO_INCREMENT,
equipment_id INT NOT NULL,
maintenance_type ENUM('Preventive', 'Corrective', 'Emergency', 'Inspection') DEFAULT 'Preventive',
description TEXT NOT NULL,
scheduled_date DATE NOT NULL,
completed_date DATE DEFAULT NULL,
technician_name VARCHAR(255) DEFAULT NULL,
technician_id INT DEFAULT NULL,
status ENUM('Scheduled', 'In Progress', 'Completed', 'Cancelled', 'Overdue') DEFAULT 'Scheduled',
priority ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Medium',
estimated_duration INT DEFAULT NULL,
actual_duration INT DEFAULT NULL,
cost DECIMAL(10,2) DEFAULT 0.00,
parts_used TEXT,
notes TEXT,
before_images TEXT,
after_images TEXT,
next_maintenance_date DATE DEFAULT NULL,
warranty_affected BOOLEAN DEFAULT FALSE,
downtime_hours DECIMAL(5,2) DEFAULT 0.00,
created_by INT DEFAULT NULL,
updated_by INT DEFAULT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (id),
FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
INDEX idx_equipment (equipment_id),
INDEX idx_status (status),
INDEX idx_scheduled_date (scheduled_date),
INDEX idx_technician (technician_id),
INDEX idx_type (maintenance_type),
INDEX idx_priority (priority)
)
");
$createMaintenanceTableStmt->execute();

// Create equipment usage table if it doesn't exist
$createUsageTableStmt = $conn->prepare("
CREATE TABLE IF NOT EXISTS equipment_usage (
id INT NOT NULL AUTO_INCREMENT,
equipment_id INT NOT NULL,
user_id INT DEFAULT NULL,
member_id INT DEFAULT NULL,
recorded_by INT DEFAULT NULL,
start_time DATETIME NOT NULL,
end_time DATETIME DEFAULT NULL,
usage_date DATE NOT NULL,
duration_minutes INT DEFAULT NULL,
usage_type ENUM('Training', 'Maintenance', 'Testing', 'Demo') DEFAULT 'Training',
intensity_level ENUM('Low', 'Medium', 'High') DEFAULT 'Medium',
calories_burned INT DEFAULT NULL,
distance DECIMAL(8,2) DEFAULT NULL,
notes TEXT,
session_rating INT DEFAULT NULL,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (id),
FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
INDEX idx_equipment (equipment_id),
INDEX idx_user (user_id),
INDEX idx_member (member_id),
INDEX idx_start_time (start_time),
INDEX idx_usage_date (usage_date),
INDEX idx_usage_type (usage_type)
)
");
$createUsageTableStmt->execute();

// Get report parameters
$reportType = isset($_GET['type']) ? $_GET['type'] : 'overview';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$equipmentType = isset($_GET['equipment_type']) ? $_GET['equipment_type'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$location = isset($_GET['location']) ? $_GET['location'] : '';
$exportFormat = isset($_GET['export']) ? $_GET['export'] : '';

// Handle export requests
if (!empty($exportFormat)) {
    switch ($exportFormat) {
        case 'excel':
            exportToExcel($conn, $reportType, $startDate, $endDate, $equipmentType, $status, $location);
            break;
        case 'pdf':
            exportToPDF($conn, $reportType, $startDate, $endDate, $equipmentType, $status, $location);
            break;
        case 'csv':
            exportToCSV($conn, $reportType, $startDate, $endDate, $equipmentType, $status, $location);
            break;
    }
    exit();
}

// Get equipment types for filter
$equipmentTypes = [];
$typeStmt = $conn->prepare("SELECT DISTINCT type FROM equipment WHERE is_active = TRUE ORDER BY type");
$typeStmt->execute();
$equipmentTypes = $typeStmt->fetchAll(PDO::FETCH_COLUMN);

// Get locations for filter
$locations = [];
$locationStmt = $conn->prepare("SELECT DISTINCT location FROM equipment WHERE is_active = TRUE AND location IS NOT NULL AND location != '' ORDER BY location");
$locationStmt->execute();
$locations = $locationStmt->fetchAll(PDO::FETCH_COLUMN);

// Get equipment statistics
$statsStmt = $conn->prepare("
SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) as available,
    SUM(CASE WHEN status = 'In Use' THEN 1 ELSE 0 END) as in_use,
    SUM(CASE WHEN status = 'Maintenance' THEN 1 ELSE 0 END) as maintenance,
    SUM(CASE WHEN status = 'Out of Order' THEN 1 ELSE 0 END) as out_of_order,
    SUM(CASE WHEN status = 'Retired' THEN 1 ELSE 0 END) as retired,
    COALESCE(SUM(cost), 0) as total_value,
    COALESCE(AVG(condition_rating), 0) as avg_condition,
    COALESCE(SUM(usage_hours), 0) as total_usage_hours
FROM equipment WHERE is_active = TRUE
");
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get maintenance statistics
$maintenanceStatsStmt = $conn->prepare("
SELECT 
    COUNT(*) as total_maintenance,
    SUM(CASE WHEN status = 'Scheduled' THEN 1 ELSE 0 END) as scheduled,
    SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'Overdue' THEN 1 ELSE 0 END) as overdue,
    COALESCE(SUM(cost), 0) as total_maintenance_cost,
    COALESCE(AVG(actual_duration), 0) as avg_duration,
    COALESCE(SUM(downtime_hours), 0) as total_downtime
FROM maintenance 
WHERE scheduled_date BETWEEN :start_date AND :end_date
");
$maintenanceStatsStmt->bindParam(':start_date', $startDate);
$maintenanceStatsStmt->bindParam(':end_date', $endDate);
$maintenanceStatsStmt->execute();
$maintenanceStats = $maintenanceStatsStmt->fetch(PDO::FETCH_ASSOC);

// Get usage statistics
$usageStatsStmt = $conn->prepare("
SELECT 
    COUNT(*) as total_sessions,
    COALESCE(SUM(duration_minutes), 0) as total_duration,
    COALESCE(AVG(duration_minutes), 0) as avg_duration,
    COALESCE(SUM(calories_burned), 0) as total_calories,
    COALESCE(AVG(session_rating), 0) as avg_rating,
    COUNT(DISTINCT equipment_id) as equipment_used,
    COUNT(DISTINCT member_id) as unique_users
FROM equipment_usage 
WHERE usage_date BETWEEN :start_date AND :end_date
");
$usageStatsStmt->bindParam(':start_date', $startDate);
$usageStatsStmt->bindParam(':end_date', $endDate);
$usageStatsStmt->execute();
$usageStats = $usageStatsStmt->fetch(PDO::FETCH_ASSOC);

// Get report data based on type
function getReportData($conn, $reportType, $startDate, $endDate, $equipmentType, $status, $location) {
    $data = [];
    
    switch ($reportType) {
        case 'overview':
            // Get overview data combining multiple metrics
            $query = "SELECT 
                e.id, e.name, e.type, e.status, e.location, 
                COALESCE(e.cost, 0) as cost, 
                COALESCE(e.condition_rating, 0) as condition_rating, 
                COALESCE(e.usage_hours, 0) as usage_hours,
                (SELECT COUNT(*) FROM maintenance m WHERE m.equipment_id = e.id AND m.scheduled_date BETWEEN :start_date AND :end_date) as maintenance_count,
                (SELECT COALESCE(SUM(m.cost), 0) FROM maintenance m WHERE m.equipment_id = e.id AND m.scheduled_date BETWEEN :start_date AND :end_date) as maintenance_cost,
                (SELECT COUNT(*) FROM equipment_usage u WHERE u.equipment_id = e.id AND u.usage_date BETWEEN :start_date AND :end_date) as usage_sessions,
                (SELECT COALESCE(SUM(u.duration_minutes), 0) FROM equipment_usage u WHERE u.equipment_id = e.id AND u.usage_date BETWEEN :start_date AND :end_date) as total_usage_time
                FROM equipment e WHERE e.is_active = TRUE";
            break;
            
        case 'inventory':
            $query = "SELECT 
                e.*, 
                (SELECT COUNT(*) FROM maintenance m WHERE m.equipment_id = e.id AND m.status = 'Scheduled') as pending_maintenance,
                (SELECT MAX(m.completed_date) FROM maintenance m WHERE m.equipment_id = e.id AND m.status = 'Completed') as last_maintenance
                FROM equipment e WHERE e.is_active = TRUE";
            break;
            
        case 'maintenance':
            $query = "SELECT 
                m.*, e.name as equipment_name, e.type as equipment_type, e.location
                FROM maintenance m
                JOIN equipment e ON m.equipment_id = e.id
                WHERE m.scheduled_date BETWEEN :start_date AND :end_date";
            break;
            
        case 'usage':
            $query = "SELECT 
                eu.*, e.name as equipment_name, e.type as equipment_type, e.location,
                COALESCE(us.name, 'Unknown User') as user_name
                FROM equipment_usage eu
                JOIN equipment e ON eu.equipment_id = e.id
                LEFT JOIN users us ON eu.recorded_by = us.id
                WHERE eu.usage_date BETWEEN :start_date AND :end_date";
            break;
            
        case 'cost':
            $query = "SELECT 
                e.id, e.name, e.type, 
                COALESCE(e.cost, 0) as purchase_cost, 
                e.purchase_date,
                (SELECT COALESCE(SUM(m.cost), 0) FROM maintenance m WHERE m.equipment_id = e.id AND m.scheduled_date BETWEEN :start_date AND :end_date) as maintenance_cost,
                (SELECT COUNT(*) FROM maintenance m WHERE m.equipment_id = e.id AND m.scheduled_date BETWEEN :start_date AND :end_date) as maintenance_count
                FROM equipment e WHERE e.is_active = TRUE";
            break;
            
        case 'performance':
            $query = "SELECT 
                e.id, e.name, e.type, 
                COALESCE(e.condition_rating, 0) as condition_rating, 
                COALESCE(e.usage_hours, 0) as usage_hours,
                (SELECT COUNT(*) FROM equipment_usage u WHERE u.equipment_id = e.id AND u.usage_date BETWEEN :start_date AND :end_date) as usage_count,
                (SELECT COALESCE(SUM(u.duration_minutes), 0) FROM equipment_usage u WHERE u.equipment_id = e.id AND u.usage_date BETWEEN :start_date AND :end_date) as total_duration,
                (SELECT COUNT(*) FROM maintenance m WHERE m.equipment_id = e.id AND m.scheduled_date BETWEEN :start_date AND :end_date) as maintenance_count,
                (SELECT COALESCE(AVG(u.session_rating), 0) FROM equipment_usage u WHERE u.equipment_id = e.id AND u.usage_date BETWEEN :start_date AND :end_date) as avg_rating
                FROM equipment e WHERE e.is_active = TRUE";
            break;
    }
    
    // Add filters
    $params = [];
    if ($reportType !== 'inventory') {
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate;
    }
    
    if (!empty($equipmentType)) {
        $query .= " AND e.type = :equipment_type";
        $params[':equipment_type'] = $equipmentType;
    }
    
    if (!empty($status)) {
        if ($reportType === 'maintenance') {
            $query .= " AND m.status = :status";
        } else {
            $query .= " AND e.status = :status";
        }
        $params[':status'] = $status;
    }
    
    if (!empty($location)) {
        $query .= " AND e.location = :location";
        $params[':location'] = $location;
    }
    
    $query .= " ORDER BY e.name";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get chart data
function getChartData($conn, $startDate, $endDate) {
    $chartData = [];
    
    // Equipment by type
    $typeStmt = $conn->prepare("SELECT type, COUNT(*) as count FROM equipment WHERE is_active = TRUE GROUP BY type ORDER BY count DESC");
    $typeStmt->execute();
    $chartData['equipmentByType'] = $typeStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Equipment by status
    $statusStmt = $conn->prepare("SELECT status, COUNT(*) as count FROM equipment WHERE is_active = TRUE GROUP BY status");
    $statusStmt->execute();
    $chartData['equipmentByStatus'] = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Maintenance trends (last 6 months)
    $maintenanceTrendStmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(scheduled_date, '%Y-%m') as month,
            COUNT(*) as count,
            COALESCE(SUM(cost), 0) as total_cost,
            COALESCE(AVG(actual_duration), 0) as avg_duration
        FROM maintenance 
        WHERE scheduled_date BETWEEN DATE_SUB(:end_date, INTERVAL 6 MONTH) AND :end_date
        GROUP BY month
        ORDER BY month
    ");
    $maintenanceTrendStmt->bindParam(':end_date', $endDate);
    $maintenanceTrendStmt->execute();
    $chartData['maintenanceTrend'] = $maintenanceTrendStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Usage trends (last 30 days)
    $usageTrendStmt = $conn->prepare("
        SELECT 
            usage_date as date,
            COUNT(*) as sessions,
            COALESCE(SUM(duration_minutes), 0) as total_duration,
            COUNT(DISTINCT equipment_id) as equipment_used
        FROM equipment_usage 
        WHERE usage_date BETWEEN DATE_SUB(:end_date, INTERVAL 30 DAY) AND :end_date
        GROUP BY usage_date
        ORDER BY usage_date
    ");
    $usageTrendStmt->bindParam(':end_date', $endDate);
    $usageTrendStmt->execute();
    $chartData['usageTrend'] = $usageTrendStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Cost analysis by type
    $costStmt = $conn->prepare("
        SELECT 
            e.type,
            COALESCE(SUM(e.cost), 0) as purchase_cost,
            (SELECT COALESCE(SUM(m.cost), 0) FROM maintenance m WHERE m.equipment_id = e.id) as maintenance_cost
        FROM equipment e
        WHERE e.is_active = TRUE
        GROUP BY e.type
        ORDER BY (COALESCE(SUM(e.cost), 0) + COALESCE((SELECT SUM(m.cost) FROM maintenance m WHERE m.equipment_id = e.id), 0)) DESC
    ");
    $costStmt->execute();
    $chartData['costByType'] = $costStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $chartData;
}

// Get report data
$reportData = getReportData($conn, $reportType, $startDate, $endDate, $equipmentType, $status, $location);
$chartData = getChartData($conn, $startDate, $endDate);

// Export functions
function exportToExcel($conn, $reportType, $startDate, $endDate, $equipmentType, $status, $location) {
    // Clean any previous output
    ob_clean();
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="equipment_report_' . $reportType . '_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    $data = getReportData($conn, $reportType, $startDate, $endDate, $equipmentType, $status, $location);
    
    echo "<table border='1'>";
    echo "<tr><td colspan='12' style='font-weight: bold; font-size: 16px; text-align: center;'>Equipment Report - " . ucfirst($reportType) . "</td></tr>";
    echo "<tr><td colspan='12' style='text-align: center;'>Generated on: " . date('Y-m-d H:i:s') . "</td></tr>";
    echo "<tr><td colspan='12'>&nbsp;</td></tr>";
    
    if (!empty($data)) {
        // Headers
        echo "<tr style='background-color: #f0f0f0; font-weight: bold;'>";
        foreach (array_keys($data[0]) as $header) {
            echo "<td>" . safeHtmlSpecialChars(ucwords(str_replace('_', ' ', $header))) . "</td>";
        }
        echo "</tr>";
        
        // Data
        foreach ($data as $row) {
            echo "<tr>";
            foreach ($row as $key => $cell) {
                // Handle different data types
                if (is_numeric($cell) && strpos($key, 'cost') !== false) {
                    echo "<td>" . safeNumberFormat($cell, 2) . "</td>";
                } elseif (is_numeric($cell) && strpos($key, 'rating') !== false) {
                    echo "<td>" . safeNumberFormat($cell, 1) . "</td>";
                } elseif (is_numeric($cell)) {
                    echo "<td>" . safeNumberFormat($cell) . "</td>";
                } else {
                    echo "<td>" . safeHtmlSpecialChars($cell) . "</td>";
                }
            }
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='12' style='text-align: center; padding: 20px;'>No data found for the selected criteria.</td></tr>";
    }
    echo "</table>";
    
    exit();
}

function exportToCSV($conn, $reportType, $startDate, $endDate, $equipmentType, $status, $location) {
    // Clean any previous output
    ob_clean();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="equipment_report_' . $reportType . '_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: max-age=0');
    
    $data = getReportData($conn, $reportType, $startDate, $endDate, $equipmentType, $status, $location);
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add report header
    fputcsv($output, ['Equipment Report - ' . ucfirst($reportType)]);
    fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    
    if (!empty($data)) {
        // Headers
        $headers = array_map(function($header) {
            return ucwords(str_replace('_', ' ', $header));
        }, array_keys($data[0]));
        fputcsv($output, $headers);
        
        // Data
        foreach ($data as $row) {
            $cleanRow = array_map(function($cell) {
                return $cell ?? '';
            }, $row);
            fputcsv($output, $cleanRow);
        }
    } else {
        fputcsv($output, ['No data found for the selected criteria.']);
    }
    
    fclose($output);
    exit();
}

function exportToPDF($conn, $reportType, $startDate, $endDate, $equipmentType, $status, $location) {
    // Clean any previous output
    ob_clean();
    
    // Check if TCPDF is available
    if (!class_exists('TCPDF')) {
        // Simple HTML to PDF conversion
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="equipment_report_' . $reportType . '_' . date('Y-m-d') . '.pdf"');
        header('Cache-Control: max-age=0');
        
        // Create a simple PDF-like output using HTML
        $data = getReportData($conn, $reportType, $startDate, $endDate, $equipmentType, $status, $location);
        
        echo "%PDF-1.4\n";
        echo "1 0 obj\n";
        echo "<<\n";
        echo "/Type /Catalog\n";
        echo "/Pages 2 0 R\n";
        echo ">>\n";
        echo "endobj\n";
        
        echo "2 0 obj\n";
        echo "<<\n";
        echo "/Type /Pages\n";
        echo "/Kids [3 0 R]\n";
        echo "/Count 1\n";
        echo ">>\n";
        echo "endobj\n";
        
        echo "3 0 obj\n";
        echo "<<\n";
        echo "/Type /Page\n";
        echo "/Parent 2 0 R\n";
        echo "/MediaBox [0 0 612 792]\n";
        echo "/Contents 4 0 R\n";
        echo ">>\n";
        echo "endobj\n";
        
        $content = "BT\n";
        $content .= "/F1 12 Tf\n";
        $content .= "100 700 Td\n";
        $content .= "(Equipment Report - " . ucfirst($reportType) . ") Tj\n";
        $content .= "0 -20 Td\n";
        $content .= "(Generated on: " . date('Y-m-d H:i:s') . ") Tj\n";
        $content .= "ET\n";
        
        echo "4 0 obj\n";
        echo "<<\n";
        echo "/Length " . strlen($content) . "\n";
        echo ">>\n";
        echo "stream\n";
        echo $content;
        echo "endstream\n";
        echo "endobj\n";
        
        echo "xref\n";
        echo "0 5\n";
        echo "0000000000 65535 f \n";
        echo "0000000009 00000 n \n";
        echo "0000000074 00000 n \n";
        echo "0000000120 00000 n \n";
        echo "0000000179 00000 n \n";
        echo "trailer\n";
        echo "<<\n";
        echo "/Size 5\n";
        echo "/Root 1 0 R\n";
        echo ">>\n";
        echo "startxref\n";
        echo "492\n";
        echo "%%EOF\n";
        
        exit();
    } else {
        // Use TCPDF if available
        require_once('tcpdf/tcpdf.php');
        
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        $pdf->SetCreator('EliteFit Gym Equipment Manager');
        $pdf->SetAuthor('Equipment Manager');
        $pdf->SetTitle('Equipment Report - ' . ucfirst($reportType));
        $pdf->SetSubject('Equipment Report');
        
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
        
        $pdf->AddPage();
        
        $html = '<h1>Equipment Report - ' . ucfirst($reportType) . '</h1>';
        $html .= '<p>Generated on: ' . date('Y-m-d H:i:s') . '</p>';
        
        $data = getReportData($conn, $reportType, $startDate, $endDate, $equipmentType, $status, $location);
        
        if (!empty($data)) {
            $html .= '<table border="1" cellpadding="4">';
            $html .= '<tr style="background-color: #f0f0f0;">';
            foreach (array_keys($data[0]) as $header) {
                $html .= '<th>' . safeHtmlSpecialChars(ucwords(str_replace('_', ' ', $header))) . '</th>';
            }
            $html .= '</tr>';
            
            foreach ($data as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td>' . safeHtmlSpecialChars($cell) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
        } else {
            $html .= '<p>No data found for the selected criteria.</p>';
        }
        
        $pdf->writeHTML($html, true, false, true, false, '');
        
        $pdf->Output('equipment_report_' . $reportType . '_' . date('Y-m-d') . '.pdf', 'D');
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Reports - EliteFit Gym Equipment Manager</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-orange: #ff6600;
            --primary-orange-dark: #e55a00;
            --primary-orange-light: #ff8533;
            --black: #000000;
            --dark-gray: #1a1a1a;
            --medium-gray: #2d2d2d;
            --light-gray: #3d3d3d;
            --text-light: #ffffff;
            --text-dark: #000000;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --border-radius: 12px;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            --shadow-dark: 0 4px 20px rgba(0, 0, 0, 0.3);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: var(--text-dark);
            min-height: 100vh;
            transition: var(--transition);
        }
        
        body.dark-theme {
            background: linear-gradient(135deg, var(--black) 0%, var(--dark-gray) 100%);
            color: var(--text-light);
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, var(--black) 0%, var(--dark-gray) 100%);
            color: var(--text-light);
            padding: 25px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: var(--transition);
            box-shadow: var(--shadow-dark);
        }
        
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar::-webkit-scrollbar-track {
            background: var(--medium-gray);
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: var(--primary-orange);
            border-radius: 3px;
        }
        
        .sidebar-header {
            display: flex;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--primary-orange);
        }
        
        .sidebar-header .logo {
            width: 50px;
            height: 50px;
            background: linear-gradient(45deg, var(--primary-orange), var(--primary-orange-light));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        
        .sidebar-header h2 {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--text-light);
            margin: 0;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 8px;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 15px 18px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }
        
        .sidebar-menu a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 102, 0, 0.1), transparent);
            transition: var(--transition);
        }
        
        .sidebar-menu a:hover::before {
            left: 100%;
        }
        
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background: linear-gradient(45deg, var(--primary-orange), var(--primary-orange-light));
            color: var(--text-light);
            transform: translateX(5px);
            box-shadow: 0 8px 25px rgba(255, 102, 0, 0.3);
        }
        
        .sidebar-menu a i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }
        
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            transition: var(--transition);
            min-height: 100vh;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 20px 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .dark-theme .header {
            background: rgba(45, 45, 45, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--black);
            margin: 0;
            background: linear-gradient(45deg, var(--black), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .dark-theme .header h1 {
            background: linear-gradient(45deg, var(--text-light), var(--primary-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .header-controls {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .theme-switch {
            display: flex;
            align-items: center;
            background: var(--medium-gray);
            padding: 8px;
            border-radius: 25px;
            transition: var(--transition);
        }
        
        .theme-switch label {
            margin: 0 10px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 20px;
            transition: var(--transition);
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .theme-switch label.active {
            background: var(--primary-orange);
            color: white;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            position: relative;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            margin-right: 12px;
            border: 3px solid var(--primary-orange);
            transition: var(--transition);
        }
        
        .user-avatar:hover {
            transform: scale(1.1);
        }
        
        .user-details h4 {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 600;
        }
        
        .user-details p {
            margin: 0;
            font-size: 0.8rem;
            opacity: 0.7;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .dark-theme .stat-card {
            background: rgba(45, 45, 45, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-orange), var(--primary-orange-light));
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card .icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(45deg, var(--primary-orange), var(--primary-orange-light));
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            color: white;
            font-size: 1.5rem;
        }
        
        .stat-card h3 {
            font-size: 2rem;
            font-weight: 700;
            margin: 0 0 5px 0;
            color: var(--primary-orange);
        }
        
        .stat-card p {
            margin: 0;
            opacity: 0.8;
            font-weight: 500;
        }
        
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
            transition: var(--transition);
        }
        
        .dark-theme .card {
            background: rgba(45, 45, 45, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .card-header {
            padding: 25px 30px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, rgba(255, 102, 0, 0.05), rgba(255, 102, 0, 0.02));
        }
        
        .dark-theme .card-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            background: linear-gradient(135deg, rgba(255, 102, 0, 0.1), rgba(255, 102, 0, 0.05));
        }
        
        .card-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--black);
        }
        
        .dark-theme .card-header h3 {
            color: var(--text-light);
        }
        
        .card-body {
            padding: 30px;
        }
        
        .report-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 30px;
            padding: 20px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .dark-theme .report-nav {
            background: rgba(45, 45, 45, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .report-nav-item {
            padding: 12px 20px;
            background: rgba(255, 255, 255, 0.8);
            border: 2px solid rgba(0, 0, 0, 0.1);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-dark);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }
        
        .dark-theme .report-nav-item {
            background: rgba(45, 45, 45, 0.8);
            border: 2px solid rgba(255, 255, 255, 0.1);
            color: var(--text-light);
        }
        
        .report-nav-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 102, 0, 0.1), transparent);
            transition: var(--transition);
        }
        
        .report-nav-item:hover::before {
            left: 100%;
        }
        
        .report-nav-item:hover, .report-nav-item.active {
            background: linear-gradient(45deg, var(--primary-orange), var(--primary-orange-light));
            color: white;
            border-color: var(--primary-orange);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 102, 0, 0.3);
        }
        
        .filters-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }
        
        .dark-theme .filters-section {
            background: rgba(45, 45, 45, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .filters-header {
            padding: 20px 30px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            background: linear-gradient(135deg, rgba(255, 102, 0, 0.05), rgba(255, 102, 0, 0.02));
        }
        
        .dark-theme .filters-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            background: linear-gradient(135deg, rgba(255, 102, 0, 0.1), rgba(255, 102, 0, 0.05));
        }
        
        .filters-body {
            padding: 30px;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--black);
            font-size: 0.9rem;
        }
        
        .dark-theme .form-group label {
            color: var(--text-light);
        }
        
        .form-control {
            padding: 12px 16px;
            border: 2px solid rgba(0, 0, 0, 0.1);
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-size: 0.95rem;
            background: rgba(255, 255, 255, 0.8);
        }
        
        .dark-theme .form-control {
            background: rgba(45, 45, 45, 0.8);
            border: 2px solid rgba(255, 255, 255, 0.1);
            color: var(--text-light);
        }
        
        .form-control:focus {
            border-color: var(--primary-orange);
            outline: 0;
            box-shadow: 0 0 0 3px rgba(255, 102, 0, 0.1);
            background: white;
        }
        
        .dark-theme .form-control:focus {
            background: var(--medium-gray);
        }
        
        .btn {
            padding: 12px 24px;
            background: linear-gradient(45deg, var(--primary-orange), var(--primary-orange-light));
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.95rem;
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: var(--transition);
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 102, 0, 0.3);
        }
        
        .btn-sm {
            padding: 8px 16px;
            font-size: 0.85rem;
        }
        
        .btn-secondary {
            background: linear-gradient(45deg, var(--medium-gray), var(--light-gray));
        }
        
        .btn-success {
            background: linear-gradient(45deg, var(--success), #34ce57);
        }
        
        .btn-danger {
            background: linear-gradient(45deg, var(--danger), #e74c3c);
        }
        
        .btn-warning {
            background: linear-gradient(45deg, var(--warning), #f39c12);
            color: var(--black);
        }
        
        .btn-info {
            background: linear-gradient(45deg, var(--info), #3498db);
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .chart-container {
            position: relative;
            height: 350px;
            width: 100%;
        }
        
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.1);
        }
        
        .dark-theme .table-container {
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.1);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
            background: white;
        }
        
        .dark-theme .table {
            background: var(--medium-gray);
        }
        
        .table th {
            padding: 18px 20px;
            text-align: left;
            font-weight: 700;
            color: var(--black);
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-bottom: 2px solid var(--primary-orange);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .dark-theme .table th {
            color: var(--text-light);
            background: linear-gradient(135deg, var(--light-gray), var(--medium-gray));
        }
        
        .table td {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: var(--transition);
        }
        
        .dark-theme .table td {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .table tbody tr {
            transition: var(--transition);
        }
        
        .table tbody tr:hover {
            background: rgba(255, 102, 0, 0.05);
            transform: scale(1.01);
        }
        
        .dark-theme .table tbody tr:hover {
            background: rgba(255, 102, 0, 0.1);
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-success {
            background: linear-gradient(45deg, var(--success), #27ae60);
        }
        
        .badge-warning {
            background: linear-gradient(45deg, var(--warning), #f39c12);
            color: var(--black);
        }
        
        .badge-info {
            background: linear-gradient(45deg, var(--info), #3498db);
        }
        
        .badge-danger {
            background: linear-gradient(45deg, var(--danger), #e74c3c);
        }
        
        .badge-secondary {
            background: linear-gradient(45deg, var(--medium-gray), var(--light-gray));
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .export-btn {
            padding: 8px 16px;
            border-radius: var(--border-radius);
            text-decoration: none;
            color: white;
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
        }
        
        .export-btn:hover {
            transform: translateY(-2px);
            color: white;
        }
        
        .export-excel {
            background: linear-gradient(45deg, #1D6F42, #2E8B57);
        }
        
        .export-pdf {
            background: linear-gradient(45deg, #F40F02, #DC143C);
        }
        
        .export-csv {
            background: linear-gradient(45deg, #217346, #228B22);
        }
        
        .export-print {
            background: linear-gradient(45deg, #5C6BC0, #7986CB);
        }
        
        .progress-ring {
            width: 60px;
            height: 60px;
            position: relative;
            display: inline-block;
        }
        
        .progress-ring svg {
            width: 100%;
            height: 100%;
            transform: rotate(-90deg);
        }
        
        .progress-ring circle {
            fill: none;
            stroke-width: 4;
            stroke-linecap: round;
        }
        
        .progress-ring .background {
            stroke: rgba(255, 255, 255, 0.1);
        }
        
        .progress-ring .progress {
            stroke: var(--primary-orange);
            stroke-dasharray: 157;
            stroke-dashoffset: 157;
            transition: stroke-dashoffset 0.5s ease;
        }
        
        .floating-action-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            background: linear-gradient(45deg, var(--primary-orange), var(--primary-orange-light));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 8px 25px rgba(255, 102, 0, 0.3);
            cursor: pointer;
            transition: var(--transition);
            z-index: 1000;
            border: none;
        }
        
        .floating-action-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 12px 35px rgba(255, 102, 0, 0.4);
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 25px;
            border-left: 4px solid;
            animation: slideInDown 0.3s ease;
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
            border-left-color: var(--success);
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
            border-left-color: var(--danger);
        }
        
        .alert-warning {
            background: rgba(255, 193, 7, 0.1);
            color: #856404;
            border-left-color: var(--warning);
        }
        
        .alert-info {
            background: rgba(23, 162, 184, 0.1);
            color: var(--info);
            border-left-color: var(--info);
        }
        
        @keyframes slideInDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                padding: 20px 10px;
            }
            
            .sidebar-header h2,
            .sidebar-menu a span {
                display: none;
            }
            
            .main-content {
                margin-left: 70px;
                padding: 20px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .header-controls {
                align-self: flex-end;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .report-nav {
                flex-direction: column;
            }
            
            .export-buttons {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body class="<?php echo $theme === 'light' ? '' : 'dark-theme'; ?>">
<div class="dashboard-container">
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-chart-line"></i>
            </div>
            <h2>EliteFit Gym</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
            <li><a href="equipment.php"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
            <li><a href="maintenance.php"><i class="fas fa-tools"></i> <span>Maintenance</span></a></li>
            <li><a href="inventory.php"><i class="fas fa-clipboard-list"></i> <span>Inventory</span></a></li>
            <li><a href="calendar.php" class="active"><i class="fas fa-calendar-alt"></i> <span>Calendar</span></a></li>
            <li><a href="report.php" class="active"><i class="fas fa-chart-line"></i> <span>Reports</span></a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
        </ul>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <h1>Advanced Reports & Analytics</h1>
            <div class="header-controls">
                <div class="theme-switch">
                    <label class="<?php echo $theme === 'light' ? 'active' : ''; ?>" onclick="switchTheme('light')">
                        <i class="fas fa-sun"></i> Light
                    </label>
                    <label class="<?php echo $theme === 'dark' ? 'active' : ''; ?>" onclick="switchTheme('dark')">
                        <i class="fas fa-moon"></i> Dark
                    </label>
                </div>
                <div class="user-info">
                    <img src="https://randomuser.me/api/portraits/men/3.jpg" alt="User Avatar" class="user-avatar">
                    <div class="user-details">
                        <h4><?php echo safeHtmlSpecialChars($userName); ?></h4>
                        <p>Equipment Manager</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Alert Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> animate__animated animate__fadeInDown">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'danger' ? 'exclamation-circle' : 'exclamation-triangle'); ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics Overview -->
        <div class="stats-grid">
            <div class="stat-card animate__animated animate__fadeInUp">
                <div class="icon">
                    <i class="fas fa-dumbbell"></i>
                </div>
                <h3><?php echo safeNumberFormat($stats['total']); ?></h3>
                <p>Total Equipment</p>
            </div>
            <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.1s;">
                <div class="icon" style="background: linear-gradient(45deg, var(--success), #27ae60);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h3><?php echo safeNumberFormat($stats['available']); ?></h3>
                <p>Available</p>
            </div>
            <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
                <div class="icon" style="background: linear-gradient(45deg, var(--info), #3498db);">
                    <i class="fas fa-play-circle"></i>
                </div>
                <h3><?php echo safeNumberFormat($stats['in_use']); ?></h3>
                <p>In Use</p>
            </div>
            <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.3s;">
                <div class="icon" style="background: linear-gradient(45deg, var(--warning), #f39c12);">
                    <i class="fas fa-tools"></i>
                </div>
                <h3><?php echo safeNumberFormat($stats['maintenance']); ?></h3>
                <p>Maintenance</p>
            </div>
            <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.4s;">
                <div class="icon" style="background: linear-gradient(45deg, var(--danger), #e74c3c);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3><?php echo safeNumberFormat($stats['out_of_order']); ?></h3>
                <p>Out of Order</p>
            </div>
            <div class="stat-card animate__animated animate__fadeInUp" style="animation-delay: 0.5s;">
                <div class="icon" style="background: linear-gradient(45deg, #2ecc71, #27ae60);">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <h3>$<?php echo safeNumberFormat($stats['total_value'], 0); ?></h3>
                <p>Total Value</p>
            </div>
        </div>
        
        <!-- Report Navigation -->
        <div class="report-nav animate__animated animate__fadeInUp" style="animation-delay: 0.6s;">
            <a href="?type=overview" class="report-nav-item <?php echo $reportType === 'overview' ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i>
                <span>Overview</span>
            </a>
            <a href="?type=inventory" class="report-nav-item <?php echo $reportType === 'inventory' ? 'active' : ''; ?>">
                <i class="fas fa-boxes"></i>
                <span>Inventory</span>
            </a>
            <a href="?type=maintenance" class="report-nav-item <?php echo $reportType === 'maintenance' ? 'active' : ''; ?>">
                <i class="fas fa-tools"></i>
                <span>Maintenance</span>
            </a>
            <a href="?type=usage" class="report-nav-item <?php echo $reportType === 'usage' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i>
                <span>Usage Analytics</span>
            </a>
            <a href="?type=cost" class="report-nav-item <?php echo $reportType === 'cost' ? 'active' : ''; ?>">
                <i class="fas fa-dollar-sign"></i>
                <span>Cost Analysis</span>
            </a>
            <a href="?type=performance" class="report-nav-item <?php echo $reportType === 'performance' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Performance</span>
            </a>
        </div>
        
        <!-- Filters Section -->
        <div class="filters-section animate__animated animate__fadeInUp" style="animation-delay: 0.7s;">
            <div class="filters-header">
                <h3><i class="fas fa-filter"></i> Report Filters</h3>
            </div>
            <div class="filters-body">
                <form action="" method="GET">
                    <input type="hidden" name="type" value="<?php echo $reportType; ?>">
                    <div class="filters-grid">
                        <?php if ($reportType !== 'inventory'): ?>
                        <div class="form-group">
                            <label for="start_date">Start Date</label>
                            <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo $startDate; ?>">
                        </div>
                        <div class="form-group">
                            <label for="end_date">End Date</label>
                            <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo $endDate; ?>">
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label for="equipment_type">Equipment Type</label>
                            <select id="equipment_type" name="equipment_type" class="form-control">
                                <option value="">All Types</option>
                                <?php foreach ($equipmentTypes as $type): ?>
                                    <option value="<?php echo safeHtmlSpecialChars($type); ?>" <?php echo $equipmentType === $type ? 'selected' : ''; ?>>
                                        <?php echo safeHtmlSpecialChars($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status" class="form-control">
                                <option value="">All Statuses</option>
                                <option value="Available" <?php echo $status === 'Available' ? 'selected' : ''; ?>>Available</option>
                                <option value="In Use" <?php echo $status === 'In Use' ? 'selected' : ''; ?>>In Use</option>
                                <option value="Maintenance" <?php echo $status === 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                <option value="Out of Order" <?php echo $status === 'Out of Order' ? 'selected' : ''; ?>>Out of Order</option>
                                <option value="Retired" <?php echo $status === 'Retired' ? 'selected' : ''; ?>>Retired</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="location">Location</label>
                            <select id="location" name="location" class="form-control">
                                <option value="">All Locations</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?php echo safeHtmlSpecialChars($loc); ?>" <?php echo $location === $loc ? 'selected' : ''; ?>>
                                        <?php echo safeHtmlSpecialChars($loc); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Charts Section -->
        <div class="charts-grid animate__animated animate__fadeInUp" style="animation-delay: 0.8s;">
            <?php if ($reportType === 'overview' || $reportType === 'inventory'): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie"></i> Equipment by Type</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="equipmentTypeChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-doughnut"></i> Status Distribution</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($reportType === 'maintenance' || $reportType === 'overview'): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line"></i> Maintenance Trends</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="maintenanceTrendChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($reportType === 'usage' || $reportType === 'overview'): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-area"></i> Usage Analytics</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="usageChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($reportType === 'cost' || $reportType === 'overview'): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-bar"></i> Cost Analysis</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="costChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Report Data Table -->
        <div class="card animate__animated animate__fadeInUp" style="animation-delay: 0.9s;">
            <div class="card-header">
                <h3>
                    <i class="fas fa-table"></i>
                    <?php 
                    switch ($reportType) {
                        case 'overview':
                            echo 'Equipment Overview Report';
                            break;
                        case 'inventory':
                            echo 'Inventory Report';
                            break;
                        case 'maintenance':
                            echo 'Maintenance Report';
                            break;
                        case 'usage':
                            echo 'Usage Analytics Report';
                            break;
                        case 'cost':
                            echo 'Cost Analysis Report';
                            break;
                        case 'performance':
                            echo 'Performance Report';
                            break;
                    }
                    ?>
                </h3>
                <div class="export-buttons">
                    <a href="?type=<?php echo $reportType; ?>&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>&equipment_type=<?php echo $equipmentType; ?>&status=<?php echo $status; ?>&location=<?php echo $location; ?>&export=excel" class="export-btn export-excel">
                        <i class="fas fa-file-excel"></i> Excel
                    </a>
                    <a href="?type=<?php echo $reportType; ?>&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>&equipment_type=<?php echo $equipmentType; ?>&status=<?php echo $status; ?>&location=<?php echo $location; ?>&export=pdf" class="export-btn export-pdf">
                        <i class="fas fa-file-pdf"></i> PDF
                    </a>
                    <a href="?type=<?php echo $reportType; ?>&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>&equipment_type=<?php echo $equipmentType; ?>&status=<?php echo $status; ?>&location=<?php echo $location; ?>&export=csv" class="export-btn export-csv">
                        <i class="fas fa-file-csv"></i> CSV
                    </a>
                    <button onclick="printReport()" class="export-btn export-print">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <?php if ($reportType === 'overview'): ?>
                                    <th>ID</th>
                                    <th>Equipment Name</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Location</th>
                                    <th>Condition</th>
                                    <th>Usage Hours</th>
                                    <th>Maintenance Count</th>
                                    <th>Usage Sessions</th>
                                    <th>Total Cost</th>
                                <?php elseif ($reportType === 'inventory'): ?>
                                    <th>ID</th>
                                    <th>Equipment Name</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Location</th>
                                    <th>Purchase Date</th>
                                    <th>Cost</th>
                                    <th>Condition</th>
                                    <th>Last Maintenance</th>
                                    <th>Pending Maintenance</th>
                                <?php elseif ($reportType === 'maintenance'): ?>
                                    <th>ID</th>
                                    <th>Equipment</th>
                                    <th>Type</th>
                                    <th>Maintenance Type</th>
                                    <th>Scheduled Date</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Technician</th>
                                    <th>Cost</th>
                                    <th>Duration</th>
                                <?php elseif ($reportType === 'usage'): ?>
                                    <th>ID</th>
                                    <th>Equipment</th>
                                    <th>Type</th>
                                    <th>User</th>
                                    <th>Start Time</th>
                                    <th>Duration (min)</th>
                                    <th>Usage Type</th>
                                    <th>Intensity</th>
                                    <th>Calories</th>
                                    <th>Rating</th>
                                <?php elseif ($reportType === 'cost'): ?>
                                    <th>ID</th>
                                    <th>Equipment</th>
                                    <th>Type</th>
                                    <th>Purchase Date</th>
                                    <th>Purchase Cost</th>
                                    <th>Maintenance Cost</th>
                                    <th>Maintenance Count</th>
                                    <th>Total Cost</th>
                                    <th>Cost per Use</th>
                                <?php elseif ($reportType === 'performance'): ?>
                                    <th>ID</th>
                                    <th>Equipment</th>
                                    <th>Type</th>
                                    <th>Condition Rating</th>
                                    <th>Usage Count</th>
                                    <th>Total Duration</th>
                                    <th>Maintenance Count</th>
                                    <th>Avg Rating</th>
                                    <th>Performance Score</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                                <tr>
                                    <td colspan="10" class="text-center" style="padding: 40px;">
                                        <i class="fas fa-search fa-3x" style="color: #ccc; margin-bottom: 15px;"></i>
                                        <h4>No data found</h4>
                                        <p>Try adjusting your filters or date range.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reportData as $item): ?>
                                    <tr>
                                        <?php if ($reportType === 'overview'): ?>
                                            <td><?php echo $item['id']; ?></td>
                                            <td><?php echo safeHtmlSpecialChars($item['name']); ?></td>
                                            <td><span class="badge badge-info"><?php echo safeHtmlSpecialChars($item['type']); ?></span></td>
                                            <td>
                                                <?php 
                                                $statusClass = '';
                                                switch ($item['status']) {
                                                    case 'Available': $statusClass = 'badge-success'; break;
                                                    case 'In Use': $statusClass = 'badge-info'; break;
                                                    case 'Maintenance': $statusClass = 'badge-warning'; break;
                                                    case 'Out of Order': $statusClass = 'badge-danger'; break;
                                                    case 'Retired': $statusClass = 'badge-secondary'; break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $statusClass; ?>"><?php echo $item['status']; ?></span>
                                            </td>
                                            <td><?php echo safeHtmlSpecialChars($item['location'] ?? 'N/A'); ?></td>
                                            <td>
                                                <div class="progress-ring">
                                                    <svg>
                                                        <circle class="background" cx="30" cy="30" r="25"></circle>
                                                        <circle class="progress" cx="30" cy="30" r="25" style="stroke-dashoffset: <?php echo 157 - (157 * (($item['condition_rating'] ?? 0) / 10)); ?>;"></circle>
                                                    </svg>
                                                </div>
                                                <small><?php echo $item['condition_rating'] ?? 0; ?>/10</small>
                                            </td>
                                            <td><?php echo safeNumberFormat($item['usage_hours']); ?> hrs</td>
                                            <td><?php echo safeNumberFormat($item['maintenance_count']); ?></td>
                                            <td><?php echo safeNumberFormat($item['usage_sessions']); ?></td>
                                            <td>$<?php echo safeNumberFormat(($item['cost'] ?? 0) + ($item['maintenance_cost'] ?? 0), 2); ?></td>
                                        <?php elseif ($reportType === 'inventory'): ?>
                                            <td><?php echo $item['id']; ?></td>
                                            <td><?php echo safeHtmlSpecialChars($item['name']); ?></td>
                                            <td><span class="badge badge-info"><?php echo safeHtmlSpecialChars($item['type']); ?></span></td>
                                            <td>
                                                <?php 
                                                $statusClass = '';
                                                switch ($item['status']) {
                                                    case 'Available': $statusClass = 'badge-success'; break;
                                                    case 'In Use': $statusClass = 'badge-info'; break;
                                                    case 'Maintenance': $statusClass = 'badge-warning'; break;
                                                    case 'Out of Order': $statusClass = 'badge-danger'; break;
                                                    case 'Retired': $statusClass = 'badge-secondary'; break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $statusClass; ?>"><?php echo $item['status']; ?></span>
                                            </td>
                                            <td><?php echo safeHtmlSpecialChars($item['location'] ?? 'N/A'); ?></td>
                                            <td><?php echo $item['purchase_date'] ? date('M d, Y', strtotime($item['purchase_date'])) : 'N/A'; ?></td>
                                            <td>$<?php echo safeNumberFormat($item['cost'], 2); ?></td>
                                            <td><?php echo safeNumberFormat($item['condition_rating']); ?>/10</td>
                                            <td><?php echo $item['last_maintenance'] ? date('M d, Y', strtotime($item['last_maintenance'])) : 'Never'; ?></td>
                                            <td>
                                                <?php if (($item['pending_maintenance'] ?? 0) > 0): ?>
                                                    <span class="badge badge-warning"><?php echo safeNumberFormat($item['pending_maintenance']); ?></span>
                                                <?php else: ?>
                                                    <span class="badge badge-success">None</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php elseif ($reportType === 'maintenance'): ?>
                                            <td><?php echo $item['id']; ?></td>
                                            <td><?php echo safeHtmlSpecialChars($item['equipment_name']); ?></td>
                                            <td><span class="badge badge-info"><?php echo safeHtmlSpecialChars($item['equipment_type']); ?></span></td>
                                            <td><?php echo safeHtmlSpecialChars($item['maintenance_type']); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($item['scheduled_date'])); ?></td>
                                            <td>
                                                <?php 
                                                $statusClass = '';
                                                switch ($item['status']) {
                                                    case 'Scheduled': $statusClass = 'badge-info'; break;
                                                    case 'In Progress': $statusClass = 'badge-warning'; break;
                                                    case 'Completed': $statusClass = 'badge-success'; break;
                                                    case 'Cancelled': $statusClass = 'badge-danger'; break;
                                                    case 'Overdue': $statusClass = 'badge-danger'; break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $statusClass; ?>"><?php echo $item['status']; ?></span>
                                            </td>
                                            <td>
                                                <?php 
                                                $priorityClass = '';
                                                switch ($item['priority']) {
                                                    case 'Low': $priorityClass = 'badge-success'; break;
                                                    case 'Medium': $priorityClass = 'badge-warning'; break;
                                                    case 'High': $priorityClass = 'badge-danger'; break;
                                                    case 'Critical': $priorityClass = 'badge-danger'; break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $priorityClass; ?>"><?php echo $item['priority']; ?></span>
                                            </td>
                                            <td><?php echo safeHtmlSpecialChars($item['technician_name'] ?? 'N/A'); ?></td>
                                            <td>$<?php echo safeNumberFormat($item['cost'], 2); ?></td>
                                            <td><?php echo $item['actual_duration'] ? safeNumberFormat($item['actual_duration']) . ' min' : 'N/A'; ?></td>
                                        <?php elseif ($reportType === 'usage'): ?>
                                            <td><?php echo $item['id']; ?></td>
                                            <td><?php echo safeHtmlSpecialChars($item['equipment_name']); ?></td>
                                            <td><span class="badge badge-info"><?php echo safeHtmlSpecialChars($item['equipment_type']); ?></span></td>
                                            <td><?php echo safeHtmlSpecialChars($item['user_name']); ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($item['start_time'])); ?></td>
                                            <td><?php echo safeNumberFormat($item['duration_minutes']); ?></td>
                                            <td><?php echo safeHtmlSpecialChars($item['usage_type']); ?></td>
                                            <td>
                                                <?php 
                                                $intensityClass = '';
                                                switch ($item['intensity_level']) {
                                                    case 'Low': $intensityClass = 'badge-success'; break;
                                                    case 'Medium': $intensityClass = 'badge-warning'; break;
                                                    case 'High': $intensityClass = 'badge-danger'; break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $intensityClass; ?>"><?php echo $item['intensity_level']; ?></span>
                                            </td>
                                            <td><?php echo safeNumberFormat($item['calories_burned']); ?></td>
                                            <td>
                                                <?php if ($item['session_rating']): ?>
                                                    <div style="color: #ffc107;">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i class="fas fa-star <?php echo $i <= $item['session_rating'] ? '' : 'text-muted'; ?>"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                        <?php elseif ($reportType === 'cost'): ?>
                                            <td><?php echo $item['id']; ?></td>
                                            <td><?php echo safeHtmlSpecialChars($item['name']); ?></td>
                                            <td><span class="badge badge-info"><?php echo safeHtmlSpecialChars($item['type']); ?></span></td>
                                            <td><?php echo $item['purchase_date'] ? date('M d, Y', strtotime($item['purchase_date'])) : 'N/A'; ?></td>
                                            <td>$<?php echo safeNumberFormat($item['purchase_cost'], 2); ?></td>
                                            <td>$<?php echo safeNumberFormat($item['maintenance_cost'], 2); ?></td>
                                            <td><?php echo safeNumberFormat($item['maintenance_count']); ?></td>
                                            <td>$<?php echo safeNumberFormat(($item['purchase_cost'] ?? 0) + ($item['maintenance_cost'] ?? 0), 2); ?></td>
                                            <td>
                                                <?php 
                                                $totalCost = ($item['purchase_cost'] ?? 0) + ($item['maintenance_cost'] ?? 0);
                                                $usageCount = $item['usage_sessions'] ?? 1;
                                                $costPerUse = $usageCount > 0 ? $totalCost / $usageCount : $totalCost;
                                                ?>
                                                $<?php echo safeNumberFormat($costPerUse, 2); ?>
                                            </td>
                                        <?php elseif ($reportType === 'performance'): ?>
                                            <td><?php echo $item['id']; ?></td>
                                            <td><?php echo safeHtmlSpecialChars($item['name']); ?></td>
                                            <td><span class="badge badge-info"><?php echo safeHtmlSpecialChars($item['type']); ?></span></td>
                                            <td>
                                                <div class="progress-ring">
                                                    <svg>
                                                        <circle class="background" cx="30" cy="30" r="25"></circle>
                                                        <circle class="progress" cx="30" cy="30" r="25" style="stroke-dashoffset: <?php echo 157 - (157 * (($item['condition_rating'] ?? 0) / 10)); ?>;"></circle>
                                                    </svg>
                                                </div>
                                                <small><?php echo safeNumberFormat($item['condition_rating']); ?>/10</small>
                                            </td>
                                            <td><?php echo safeNumberFormat($item['usage_count']); ?></td>
                                            <td><?php echo safeNumberFormat($item['total_duration']); ?> min</td>
                                            <td><?php echo safeNumberFormat($item['maintenance_count']); ?></td>
                                            <td>
                                                <?php if ($item['avg_rating']): ?>
                                                    <div style="color: #ffc107;">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i class="fas fa-star <?php echo $i <= round($item['avg_rating']) ? '' : 'text-muted'; ?>"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                    <small><?php echo safeNumberFormat($item['avg_rating'], 1); ?></small>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $usageCount = $item['usage_count'] ?? 0;
                                                $maintenanceCount = $item['maintenance_count'] ?? 0;
                                                $conditionRating = $item['condition_rating'] ?? 5;
                                                $performanceScore = $maintenanceCount > 0 ? 
                                                    round(($usageCount * $conditionRating) / ($maintenanceCount * 10), 2) : 
                                                    round($usageCount * $conditionRating / 10, 2);
                                                
                                                $scoreClass = '';
                                                if ($performanceScore >= 8) $scoreClass = 'badge-success';
                                                elseif ($performanceScore >= 5) $scoreClass = 'badge-warning';
                                                else $scoreClass = 'badge-danger';
                                                ?>
                                                <span class="badge <?php echo $scoreClass; ?>"><?php echo $performanceScore; ?></span>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Additional Analytics Cards -->
        <?php if ($reportType === 'overview'): ?>
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-bar"></i> Maintenance Summary</h3>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid" style="grid-template-columns: 1fr;">
                            <div class="stat-card">
                                <div class="icon" style="background: linear-gradient(45deg, var(--warning), #f39c12);">
                                    <i class="fas fa-tools"></i>
                                </div>
                                <h3><?php echo safeNumberFormat($maintenanceStats['total_maintenance']); ?></h3>
                                <p>Total Maintenance</p>
                            </div>
                            <div class="stat-card">
                                <div class="icon" style="background: linear-gradient(45deg, var(--success), #27ae60);">
                                    <i class="fas fa-check"></i>
                                </div>
                                <h3><?php echo safeNumberFormat($maintenanceStats['completed']); ?></h3>
                                <p>Completed</p>
                            </div>
                            <div class="stat-card">
                                <div class="icon" style="background: linear-gradient(45deg, var(--danger), #e74c3c);">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <h3><?php echo safeNumberFormat($maintenanceStats['overdue']); ?></h3>
                                <p>Overdue</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Usage Summary</h3>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid" style="grid-template-columns: 1fr;">
                            <div class="stat-card">
                                <div class="icon" style="background: linear-gradient(45deg, var(--info), #3498db);">
                                    <i class="fas fa-play"></i>
                                </div>
                                <h3><?php echo safeNumberFormat($usageStats['total_sessions']); ?></h3>
                                <p>Total Sessions</p>
                            </div>
                            <div class="stat-card">
                                <div class="icon" style="background: linear-gradient(45deg, #9b59b6, #8e44ad);">
                                    <i class="fas fa-users"></i>
                                </div>
                                <h3><?php echo safeNumberFormat($usageStats['unique_users']); ?></h3>
                                <p>Unique Users</p>
                            </div>
                            <div class="stat-card">
                                <div class="icon" style="background: linear-gradient(45deg, #e67e22, #d35400);">
                                    <i class="fas fa-fire"></i>
                                </div>
                                <h3><?php echo safeNumberFormat($usageStats['total_calories']); ?></h3>
                                <p>Calories Burned</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-dollar-sign"></i> Cost Summary</h3>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid" style="grid-template-columns: 1fr;">
                            <div class="stat-card">
                                <div class="icon" style="background: linear-gradient(45deg, #2ecc71, #27ae60);">
                                    <i class="fas fa-shopping-cart"></i>
                                </div>
                                <h3>$<?php echo safeNumberFormat($stats['total_value']); ?></h3>
                                <p>Equipment Value</p>
                            </div>
                            <div class="stat-card">
                                <div class="icon" style="background: linear-gradient(45deg, var(--warning), #f39c12);">
                                    <i class="fas fa-wrench"></i>
                                </div>
                                <h3>$<?php echo safeNumberFormat($maintenanceStats['total_maintenance_cost']); ?></h3>
                                <p>Maintenance Cost</p>
                            </div>
                            <div class="stat-card">
                                <div class="icon" style="background: linear-gradient(45deg, var(--info), #3498db);">
                                    <i class="fas fa-calculator"></i>
                                </div>
                                <h3>$<?php echo safeNumberFormat(($stats['total_value'] ?? 0) + ($maintenanceStats['total_maintenance_cost'] ?? 0)); ?></h3>
                                <p>Total Investment</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Floating Action Button -->
<button class="floating-action-btn" onclick="refreshData()" title="Refresh Data">
    <i class="fas fa-sync-alt"></i>
</button>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Theme switching
    function switchTheme(theme) {
        // Update UI immediately
        document.body.classList.toggle('dark-theme', theme === 'dark');
        
        // Update active labels
        document.querySelectorAll('.theme-switch label').forEach(label => {
            label.classList.remove('active');
        });
        event.target.classList.add('active');
        
        // Save theme preference via AJAX
        $.ajax({
            url: 'save-theme.php',
            type: 'POST',
            data: { theme: theme },
            dataType: 'json',
            success: function(response) {
                if (!response.success) {
                    console.error('Failed to save theme preference');
                }
            },
            error: function() {
                console.error('Error saving theme preference');
            }
        });
    }
    
    // Chart initialization
    $(document).ready(function() {
        const isDarkTheme = document.body.classList.contains('dark-theme');
        
        // Set Chart.js defaults
        Chart.defaults.color = isDarkTheme ? '#ffffff' : '#333333';
        Chart.defaults.borderColor = isDarkTheme ? '#444444' : '#dddddd';
        
        const chartColors = [
            '#ff6600', '#e55a00', '#ff8533', '#cc5200', '#ff9966',
            '#b34700', '#ffb399', '#994000', '#ffccb3', '#802d00'
        ];
        
        // Equipment by Type Chart
        <?php if (!empty($chartData['equipmentByType'])): ?>
        const typeCtx = document.getElementById('equipmentTypeChart');
        if (typeCtx) {
            new Chart(typeCtx, {
                type: 'doughnut',
                data: {
                    labels: [
                        <?php foreach ($chartData['equipmentByType'] as $item): ?>
                            '<?php echo addslashes($item['type']); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        data: [
                            <?php foreach ($chartData['equipmentByType'] as $item): ?>
                                <?php echo $item['count']; ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: chartColors,
                        borderWidth: 2,
                        borderColor: isDarkTheme ? '#2d2d2d' : '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                usePointStyle: true,
                                padding: 20
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>
        
        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            new Chart(statusCtx, {
                type: 'bar',
                data: {
                    labels: ['Available', 'In Use', 'Maintenance', 'Out of Order', 'Retired'],
                    datasets: [{
                        label: 'Equipment Count',
                        data: [
                            <?php echo $stats['available'] ?? 0; ?>,
                            <?php echo $stats['in_use'] ?? 0; ?>,
                            <?php echo $stats['maintenance'] ?? 0; ?>,
                            <?php echo $stats['out_of_order'] ?? 0; ?>,
                            <?php echo $stats['retired'] ?? 0; ?>
                        ],
                        backgroundColor: [
                            '#28a745', '#17a2b8', '#ffc107', '#dc3545', '#6c757d'
                        ],
                        borderWidth: 2,
                        borderColor: isDarkTheme ? '#2d2d2d' : '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }
        
        // Maintenance Trend Chart
        <?php if (!empty($chartData['maintenanceTrend'])): ?>
        const maintenanceCtx = document.getElementById('maintenanceTrendChart');
        if (maintenanceCtx) {
            new Chart(maintenanceCtx, {
                type: 'line',
                data: {
                    labels: [
                        <?php foreach ($chartData['maintenanceTrend'] as $item): ?>
                            '<?php echo date('M Y', strtotime($item['month'] . '-01')); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        label: 'Maintenance Count',
                        data: [
                            <?php foreach ($chartData['maintenanceTrend'] as $item): ?>
                                <?php echo $item['count']; ?>,
                            <?php endforeach; ?>
                        ],
                        borderColor: '#ff6600',
                        backgroundColor: 'rgba(255, 102, 0, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#ff6600',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 6
                    }, {
                        label: 'Cost ($)',
                        data: [
                            <?php foreach ($chartData['maintenanceTrend'] as $item): ?>
                                <?php echo $item['total_cost'] ?? 0; ?>,
                            <?php endforeach; ?>
                        ],
                        borderColor: '#e55a00',
                        backgroundColor: 'rgba(229, 90, 0, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: false,
                        pointBackgroundColor: '#e55a00',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 6,
                        yAxisID: 'y1'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            beginAtZero: true,
                            grid: {
                                drawOnChartArea: false,
                            },
                            ticks: {
                                callback: function(value) {
                                    return '$' + value;
                                }
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>
        
        // Usage Analytics Chart
        <?php if (!empty($chartData['usageTrend'])): ?>
        const usageCtx = document.getElementById('usageChart');
        if (usageCtx) {
            new Chart(usageCtx, {
                type: 'line',
                data: {
                    labels: [
                        <?php foreach ($chartData['usageTrend'] as $item): ?>
                            '<?php echo date('M d', strtotime($item['date'])); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        label: 'Sessions',
                        data: [
                            <?php foreach ($chartData['usageTrend'] as $item): ?>
                                <?php echo $item['sessions']; ?>,
                            <?php endforeach; ?>
                        ],
                        borderColor: '#ff6600',
                        backgroundColor: 'rgba(255, 102, 0, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'Equipment Used',
                        data: [
                            <?php foreach ($chartData['usageTrend'] as $item): ?>
                                <?php echo $item['equipment_used']; ?>,
                            <?php endforeach; ?>
                        ],
                        borderColor: '#e55a00',
                        backgroundColor: 'rgba(229, 90, 0, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>
        
        // Cost Analysis Chart
        <?php if (!empty($chartData['costByType'])): ?>
        const costCtx = document.getElementById('costChart');
        if (costCtx) {
            new Chart(costCtx, {
                type: 'bar',
                data: {
                    labels: [
                        <?php foreach ($chartData['costByType'] as $item): ?>
                            '<?php echo addslashes($item['type']); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        label: 'Purchase Cost',
                        data: [
                            <?php foreach ($chartData['costByType'] as $item): ?>
                                <?php echo $item['purchase_cost'] ?? 0; ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: '#ff6600',
                        borderWidth: 2,
                        borderColor: isDarkTheme ? '#2d2d2d' : '#ffffff'
                    }, {
                        label: 'Maintenance Cost',
                        data: [
                            <?php foreach ($chartData['costByType'] as $item): ?>
                                <?php echo $item['maintenance_cost'] ?? 0; ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: '#e55a00',
                        borderWidth: 2,
                        borderColor: isDarkTheme ? '#2d2d2d' : '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true,
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '$' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': $' + context.raw.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>
    });
    
    // Utility functions
    function refreshData() {
        const btn = document.querySelector('.floating-action-btn');
        const icon = btn.querySelector('i');
        
        icon.classList.add('fa-spin');
        
        setTimeout(() => {
            location.reload();
        }, 1000);
    }
    
    function printReport() {
        window.print();
    }
    
    // Auto-refresh every 5 minutes
    setInterval(function() {
        const now = new Date();
        if (now.getMinutes() % 5 === 0 && now.getSeconds() === 0) {
            refreshData();
        }
    }, 1000);
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+R to refresh
        if (e.ctrlKey && e.key === 'r') {
            e.preventDefault();
            refreshData();
        }
        
        // Ctrl+P to print
        if (e.ctrlKey && e.key === 'p') {
            e.preventDefault();
            printReport();
        }
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-20px)';
            setTimeout(() => alert.remove(), 300);
        });
    }, 5000);
</script>
</body>
</html>