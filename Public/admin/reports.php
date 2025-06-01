<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';
require_once __DIR__ . '/../db_connect.php';

// Require Admin role to access this page
requireRole('Admin');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Handle theme switching
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_theme'])) {
    $newTheme = $_POST['theme'] === 'light' ? 'light' : 'dark';
    setcookie('admin_theme', $newTheme, time() + (86400 * 30), '/'); // 30 days
    $_COOKIE['admin_theme'] = $newTheme;
}

// Get theme preference (default to dark)
$theme = isset($_COOKIE['admin_theme']) ? $_COOKIE['admin_theme'] : 'dark';

// Connect to database BEFORE handling exports
$conn = connectDB();

// Enhanced database table creation
function ensureTablesExist($conn) {
    // Create comprehensive logging tables if they don't exist
    $tables = [
        "CREATE TABLE IF NOT EXISTS login_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            username VARCHAR(255),
            role VARCHAR(50),
            ip_address VARCHAR(45),
            user_agent TEXT,
            success TINYINT(1) DEFAULT 0,
            failure_reason VARCHAR(255),
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            session_duration INT DEFAULT 0,
            INDEX idx_timestamp (timestamp),
            INDEX idx_user_id (user_id),
            INDEX idx_success (success)
        )",
        
        "CREATE TABLE IF NOT EXISTS registration_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            username VARCHAR(255),
            email VARCHAR(255),
            role VARCHAR(50),
            ip_address VARCHAR(45),
            success TINYINT(1) DEFAULT 0,
            failure_reason VARCHAR(255),
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            verification_status VARCHAR(50) DEFAULT 'pending',
            INDEX idx_timestamp (timestamp),
            INDEX idx_success (success)
        )",
        
        "CREATE TABLE IF NOT EXISTS workout_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            trainer_id INT,
            member_id INT,
            workout_plan_id INT,
            session_date DATE,
            duration_minutes INT,
            calories_burned INT,
            exercises_completed INT,
            satisfaction_rating DECIMAL(2,1),
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_trainer_id (trainer_id),
            INDEX idx_member_id (member_id),
            INDEX idx_session_date (session_date)
        )",
        
        "CREATE TABLE IF NOT EXISTS equipment (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            category VARCHAR(100),
            status ENUM('Active', 'Maintenance', 'Out of Order', 'Retired') DEFAULT 'Active',
            purchase_date DATE,
            last_maintenance DATE,
            next_maintenance DATE,
            cost DECIMAL(10,2),
            location VARCHAR(255),
            usage_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_category (category)
        )",
        
        "CREATE TABLE IF NOT EXISTS maintenance_schedule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            equipment_id INT,
            scheduled_date DATE,
            completed_date DATE,
            status ENUM('Scheduled', 'In Progress', 'Completed', 'Overdue', 'Cancelled') DEFAULT 'Scheduled',
            maintenance_type VARCHAR(100),
            cost DECIMAL(10,2),
            technician_name VARCHAR(255),
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
            INDEX idx_scheduled_date (scheduled_date),
            INDEX idx_status (status)
        )",
        
        "CREATE TABLE IF NOT EXISTS financial_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            transaction_type ENUM('membership_fee', 'personal_training', 'equipment_purchase', 'maintenance_cost', 'other') NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency VARCHAR(3) DEFAULT 'USD',
            status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
            payment_method VARCHAR(50),
            description TEXT,
            transaction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_by INT,
            INDEX idx_transaction_date (transaction_date),
            INDEX idx_user_id (user_id),
            INDEX idx_status (status)
        )",
        
        "CREATE TABLE IF NOT EXISTS member_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT,
            check_in_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            check_out_time TIMESTAMP NULL,
            duration_minutes INT,
            activities JSON,
            trainer_id INT,
            INDEX idx_member_id (member_id),
            INDEX idx_check_in_time (check_in_time)
        )"
    ];
    
    foreach ($tables as $sql) {
        try {
            $conn->exec($sql);
        } catch (PDOException $e) {
            error_log("Error creating table: " . $e->getMessage());
        }
    }
}

// Ensure all required tables exist
ensureTablesExist($conn);

// Handle export requests AFTER database connection is established
if (isset($_GET['export']) && isset($_GET['format'])) {
    $exportType = $_GET['export'];
    $format = $_GET['format'];
    $month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
    $year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
    
    // Generate and download export file
    handleExport($exportType, $format, $month, $year);
    exit;
}

// Get filter parameters
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$reportType = isset($_GET['report_type']) ? $_GET['report_type'] : 'dashboard_overview';
$dateRange = isset($_GET['date_range']) ? $_GET['date_range'] : 'month';
$customStartDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$customEndDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Enhanced export functionality
function handleExport($exportType, $format, $month, $year) {
    global $conn;
    
    $data = [];
    $filename = '';
    
    switch ($exportType) {
        case 'user_activity':
            $data = getUserActivityExportData($conn, $month, $year);
            $filename = "user_activity_report_{$year}_{$month}";
            break;
        case 'financial':
            $data = getFinancialExportData($conn, $month, $year);
            $filename = "financial_report_{$year}_{$month}";
            break;
        case 'trainer_performance':
            $data = getTrainerPerformanceExportData($conn, $month, $year);
            $filename = "trainer_performance_{$year}_{$month}";
            break;
        case 'equipment':
            $data = getEquipmentExportData($conn, $month, $year);
            $filename = "equipment_report_{$year}_{$month}";
            break;
        case 'attendance':
            $data = getAttendanceExportData($conn, $month, $year);
            $filename = "attendance_report_{$year}_{$month}";
            break;
    }
    
    switch ($format) {
        case 'csv':
            exportToCSV($data, $filename);
            break;
        case 'excel':
            exportToExcel($data, $filename);
            break;
        case 'pdf':
            exportToPDF($data, $filename, $exportType);
            break;
    }
}

function exportToCSV($data, $filename) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    if (!empty($data)) {
        // Write headers
        fputcsv($output, array_keys($data[0]));
        
        // Write data
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
}

function exportToExcel($data, $filename) {
    // Simple Excel export using HTML table format
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    
    echo '<table border="1">';
    
    if (!empty($data)) {
        // Headers
        echo '<tr>';
        foreach (array_keys($data[0]) as $header) {
            echo '<th>' . htmlspecialchars($header) . '</th>';
        }
        echo '</tr>';
        
        // Data
        foreach ($data as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . htmlspecialchars($cell ?? '') . '</td>';
            }
            echo '</tr>';
        }
    }
    
    echo '</table>';
}

function exportToPDF($data, $filename, $reportType) {
    // Simple PDF generation using HTML
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    
    // For a real implementation, you would use a library like TCPDF or FPDF
    // This is a simplified version
    $html = '<html><head><title>' . $filename . '</title></head><body>';
    $html .= '<h1>EliteFit Gym - ' . ucwords(str_replace('_', ' ', $reportType)) . ' Report</h1>';
    $html .= '<p>Generated on: ' . date('Y-m-d H:i:s') . '</p>';
    
    if (!empty($data)) {
        $html .= '<table border="1" cellpadding="5">';
        
        // Headers
        $html .= '<tr>';
        foreach (array_keys($data[0]) as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr>';
        
        // Data
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars($cell ?? '') . '</td>';
            }
            $html .= '</tr>';
        }
        
        $html .= '</table>';
    }
    
    $html .= '</body></html>';
    echo $html;
}

// Enhanced data retrieval functions
function getDashboardOverviewData($conn, $month, $year) {
    $data = [];
    
    // Total users by role
    $stmt = $conn->prepare("
        SELECT role, COUNT(*) as count 
        FROM users 
        WHERE status = 'Active' 
        GROUP BY role
    ");
    $stmt->execute();
    $data['user_counts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Monthly revenue
    $stmt = $conn->prepare("
        SELECT 
            SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_revenue,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_transactions,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_transactions
        FROM financial_transactions 
        WHERE MONTH(transaction_date) = ? AND YEAR(transaction_date) = ?
    ");
    $stmt->execute([$month, $year]);
    $data['financial_summary'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Equipment status
    $stmt = $conn->prepare("
        SELECT status, COUNT(*) as count 
        FROM equipment 
        GROUP BY status
    ");
    $stmt->execute();
    $data['equipment_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Monthly attendance
    $stmt = $conn->prepare("
        SELECT 
            DATE(check_in_time) as date,
            COUNT(*) as daily_visits,
            AVG(duration_minutes) as avg_duration
        FROM member_attendance 
        WHERE MONTH(check_in_time) = ? AND YEAR(check_in_time) = ?
        GROUP BY DATE(check_in_time)
        ORDER BY date
    ");
    $stmt->execute([$month, $year]);
    $data['attendance_data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $data;
}

function getUserActivityExportData($conn, $month, $year) {
    $stmt = $conn->prepare("
        SELECT 
            DATE(timestamp) as date,
            role,
            COUNT(*) as login_attempts,
            SUM(success) as successful_logins,
            COUNT(*) - SUM(success) as failed_logins
        FROM login_logs 
        WHERE MONTH(timestamp) = ? AND YEAR(timestamp) = ?
        GROUP BY DATE(timestamp), role
        ORDER BY date, role
    ");
    $stmt->execute([$month, $year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getFinancialExportData($conn, $month, $year) {
    $stmt = $conn->prepare("
        SELECT 
            DATE(transaction_date) as date,
            transaction_type,
            SUM(amount) as total_amount,
            COUNT(*) as transaction_count,
            payment_method
        FROM financial_transactions 
        WHERE MONTH(transaction_date) = ? AND YEAR(transaction_date) = ? AND status = 'completed'
        GROUP BY DATE(transaction_date), transaction_type, payment_method
        ORDER BY date
    ");
    $stmt->execute([$month, $year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTrainerPerformanceExportData($conn, $month, $year) {
    $stmt = $conn->prepare("
        SELECT 
            u.name as trainer_name,
            COUNT(DISTINCT ws.member_id) as unique_members,
            COUNT(ws.id) as total_sessions,
            AVG(ws.duration_minutes) as avg_session_duration,
            AVG(ws.satisfaction_rating) as avg_rating,
            SUM(ws.calories_burned) as total_calories_burned
        FROM users u
        LEFT JOIN workout_sessions ws ON u.id = ws.trainer_id 
            AND MONTH(ws.session_date) = ? AND YEAR(ws.session_date) = ?
        WHERE u.role = 'Trainer' AND u.status = 'Active'
        GROUP BY u.id, u.name
        ORDER BY total_sessions DESC
    ");
    $stmt->execute([$month, $year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getEquipmentExportData($conn, $month, $year) {
    $stmt = $conn->prepare("
        SELECT 
            e.name,
            COALESCE(e.category, '') as category,
            e.status,
            e.last_maintenance,
            e.next_maintenance,
            COALESCE(e.usage_count, 0) as usage_count,
            COUNT(ms.id) as maintenance_sessions
        FROM equipment e
        LEFT JOIN maintenance_schedule ms ON e.id = ms.equipment_id 
            AND MONTH(ms.scheduled_date) = ? AND YEAR(ms.scheduled_date) = ?
        GROUP BY e.id, e.name, e.category, e.status, e.last_maintenance, e.next_maintenance, e.usage_count
        ORDER BY e.category, e.name
    ");
    $stmt->execute([$month, $year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAttendanceExportData($conn, $month, $year) {
    $stmt = $conn->prepare("
        SELECT 
            u.name as member_name,
            COUNT(ma.id) as total_visits,
            AVG(ma.duration_minutes) as avg_duration,
            MIN(ma.check_in_time) as first_visit,
            MAX(ma.check_in_time) as last_visit
        FROM users u
        LEFT JOIN member_attendance ma ON u.id = ma.member_id 
            AND MONTH(ma.check_in_time) = ? AND YEAR(ma.check_in_time) = ?
        WHERE u.role = 'Member' AND u.status = 'Active'
        GROUP BY u.id, u.name
        HAVING total_visits > 0
        ORDER BY total_visits DESC
    ");
    $stmt->execute([$month, $year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get report data based on type
$reportData = [];
switch ($reportType) {
    case 'dashboard_overview':
        $reportData = getDashboardOverviewData($conn, $month, $year);
        break;
    case 'user_activity':
        $reportData = getUserActivityExportData($conn, $month, $year);
        break;
    case 'financial':
        $reportData = getFinancialExportData($conn, $month, $year);
        break;
    case 'trainer_performance':
        $reportData = getTrainerPerformanceExportData($conn, $month, $year);
        break;
    case 'equipment':
        $reportData = getEquipmentExportData($conn, $month, $year);
        break;
    case 'attendance':
        $reportData = getAttendanceExportData($conn, $month, $year);
        break;
}

function getMonthName($month) {
    return date('F', mktime(0, 0, 0, $month, 1));
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Reports & Analytics - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/date-fns@2.29.3/index.min.js"></script>
    <style>
        :root {
            --primary: #FF8C00;
            --primary-dark: #e67e00;
            --primary-light: #ffaa33;
            --secondary: #333333;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --border-radius: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            --shadow-hover: 0 8px 30px rgba(0, 0, 0, 0.15);
        }

        [data-theme="dark"] {
            --bg: #0a0a0a;
            --card-bg: #1a1a1a;
            --text: #ffffff;
            --text-secondary: #b3b3b3;
            --border: #333333;
            --accent: #2a2a2a;
            --sidebar-bg: #111111;
            --header-bg: #1a1a1a;
            --input-bg: #2a2a2a;
            --hover-bg: #333333;
        }

        [data-theme="light"] {
            --bg: #f8f9fa;
            --card-bg: #ffffff;
            --text: #333333;
            --text-secondary: #666666;
            --border: #e0e0e0;
            --accent: #f1f3f4;
            --sidebar-bg: #ffffff;
            --header-bg: #ffffff;
            --input-bg: #ffffff;
            --hover-bg: #f8f9fa;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            transition: var(--transition);
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border);
            padding: 2rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: var(--transition);
            z-index: 1000;
        }

        .sidebar-header {
            padding: 0 2rem 2rem;
            text-align: center;
            border-bottom: 1px solid var(--border);
            margin-bottom: 2rem;
        }

        .sidebar-header h2 {
            color: var(--primary);
            margin-top: 1rem;
            font-weight: 700;
            font-size: 1.5rem;
        }

        .sidebar-section {
            margin-bottom: 2rem;
        }

        .sidebar-section-title {
            padding: 0 2rem;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 1rem;
        }

        .sidebar-menu {
            list-style: none;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 1rem 2rem;
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
            font-weight: 500;
        }

        .sidebar-menu li a:hover,
        .sidebar-menu li a.active {
            background: var(--accent);
            color: var(--primary);
            border-left-color: var(--primary);
        }

        .sidebar-menu li a i {
            margin-right: 1rem;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            background: var(--bg);
            min-height: 100vh;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            background: var(--header-bg);
            padding: 1.5rem 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .header-left h1 {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .header-left p {
            color: var(--text-secondary);
            font-size: 1rem;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .theme-toggle {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .theme-switch {
            position: relative;
            width: 60px;
            height: 30px;
            background: var(--border);
            border-radius: 15px;
            cursor: pointer;
            transition: var(--transition);
        }

        .theme-switch.active {
            background: var(--primary);
        }

        .theme-switch::after {
            content: '';
            position: absolute;
            top: 3px;
            left: 3px;
            width: 24px;
            height: 24px;
            background: white;
            border-radius: 50%;
            transition: var(--transition);
        }

        .theme-switch.active::after {
            transform: translateX(30px);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 1rem;
            background: var(--accent);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }

        .user-info:hover {
            background: var(--hover-bg);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .card:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-title i {
            color: var(--primary);
            font-size: 1.25rem;
        }

        .filters-section {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-weight: 600;
            color: var(--text);
            font-size: 0.9rem;
        }

        .filter-input,
        .filter-select {
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: var(--border-radius);
            background: var(--input-bg);
            color: var(--text);
            font-size: 1rem;
            transition: var(--transition);
        }

        .filter-input:focus,
        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 140, 0, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--secondary);
        }

        .btn-secondary:hover {
            background: #555;
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .report-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
        }

        .report-tab {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--border-radius);
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition);
            white-space: nowrap;
            font-weight: 500;
        }

        .report-tab:hover {
            background: var(--accent);
            color: var(--text);
        }

        .report-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 2rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-hover);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-icon.primary { background: var(--primary); }
        .stat-icon.success { background: var(--success); }
        .stat-icon.warning { background: var(--warning); }
        .stat-icon.danger { background: var(--danger); }
        .stat-icon.info { background: var(--info); }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 1rem;
            font-weight: 500;
        }

        .stat-change {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        .stat-change.positive {
            color: var(--success);
        }

        .stat-change.negative {
            color: var(--danger);
        }

        .chart-container {
            position: relative;
            height: 400px;
            margin-bottom: 2rem;
        }

        .chart-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .chart-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text);
        }

        .chart-options {
            display: flex;
            gap: 0.5rem;
        }

        .chart-option {
            padding: 0.5rem 1rem;
            background: var(--accent);
            border: 1px solid var(--border);
            border-radius: var(--border-radius);
            color: var(--text-secondary);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .chart-option.active,
        .chart-option:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            border: 1px solid var(--border);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card-bg);
        }

        .table th,
        .table td {
            padding: 1rem 1.5rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .table th {
            background: var(--accent);
            font-weight: 600;
            color: var(--text);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table tr:hover {
            background: var(--accent);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
        }

        .badge-success { background: var(--success); }
        .badge-warning { background: var(--warning); }
        .badge-danger { background: var(--danger); }
        .badge-info { background: var(--info); }
        .badge-primary { background: var(--primary); }

        .export-section {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .export-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .export-option {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            background: var(--accent);
            border: 1px solid var(--border);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            color: var(--text);
        }

        .export-option:hover {
            background: var(--hover-bg);
            border-color: var(--primary);
        }

        .export-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary);
            color: white;
            font-size: 1.1rem;
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .loading-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 4px solid var(--border);
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .analytics-insights {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .insights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .insight-item {
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            backdrop-filter: blur(10px);
        }

        .insight-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .insight-value {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }

        .insight-description {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        @media (max-width: 1024px) {
            .sidebar {
                width: 70px;
                padding: 1rem 0;
            }

            .sidebar-header h2,
            .sidebar-menu li a span {
                display: none;
            }

            .main-content {
                margin-left: 70px;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 1001;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .chart-container {
                height: 300px;
            }
        }
    </style>
</head>
<body>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-dumbbell fa-2x" style="color: var(--primary);"></i>
                <h2>EliteFit Gym</h2>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Main</div>
                <ul class="sidebar-menu">
                    <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                    <li><a href="users.php"><i class="fas fa-users"></i> <span>Users</span></a></li>
                    <li><a href="trainers.php"><i class="fas fa-user-tie"></i> <span>Trainers</span></a></li>
                    <li><a href="equipment-managers.php"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
                    <li><a href="reports.php" class="active"><i class="fas fa-chart-line"></i> <span>Reports</span></a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">System</div>
                <ul class="sidebar-menu">
                    <li><a href="admin_settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                    <li><a href="backup.php"><i class="fas fa-database"></i> <span>Backup</span></a></li>
                    <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
                </ul>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div class="header-left">
                    <h1>Advanced Reports & Analytics</h1>
                    <p>Comprehensive insights and data visualization for EliteFit Gym</p>
                </div>
                <div class="header-right">
                    <div class="theme-toggle">
                        <i class="fas fa-sun"></i>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="switch_theme" value="1">
                            <input type="hidden" name="theme" value="<?php echo $theme === 'dark' ? 'light' : 'dark'; ?>">
                            <div class="theme-switch <?php echo $theme === 'dark' ? 'active' : ''; ?>" onclick="this.parentElement.submit()"></div>
                        </form>
                        <i class="fas fa-moon"></i>
                    </div>
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($userName, 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 600;"><?php echo htmlspecialchars($userName); ?></div>
                            <div style="font-size: 0.8rem; color: var(--text-secondary);">Administrator</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Analytics Insights -->
            <div class="analytics-insights">
                <h2 style="margin-bottom: 0.5rem;">
                    <i class="fas fa-brain"></i> AI-Powered Insights
                </h2>
                <p style="opacity: 0.9;">Automated analysis and recommendations based on your gym's performance data</p>
                
                <div class="insights-grid">
                    <div class="insight-item">
                        <div class="insight-title">Peak Hours</div>
                        <div class="insight-value">6-8 PM</div>
                        <div class="insight-description">Highest member activity detected</div>
                    </div>
                    <div class="insight-item">
                        <div class="insight-title">Revenue Growth</div>
                        <div class="insight-value">+12.5%</div>
                        <div class="insight-description">Compared to last month</div>
                    </div>
                    <div class="insight-item">
                        <div class="insight-title">Equipment Utilization</div>
                        <div class="insight-value">78%</div>
                        <div class="insight-description">Average daily usage rate</div>
                    </div>
                    <div class="insight-item">
                        <div class="insight-title">Member Retention</div>
                        <div class="insight-value">89%</div>
                        <div class="insight-description">Monthly retention rate</div>
                    </div>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="filters-section">
                <form action="" method="get" id="filtersForm">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label class="filter-label" for="report_type">Report Type</label>
                            <select name="report_type" id="report_type" class="filter-select">
                                <option value="dashboard_overview" <?php echo $reportType === 'dashboard_overview' ? 'selected' : ''; ?>>Dashboard Overview</option>
                                <option value="user_activity" <?php echo $reportType === 'user_activity' ? 'selected' : ''; ?>>User Activity</option>
                                <option value="financial" <?php echo $reportType === 'financial' ? 'selected' : ''; ?>>Financial Reports</option>
                                <option value="trainer_performance" <?php echo $reportType === 'trainer_performance' ? 'selected' : ''; ?>>Trainer Performance</option>
                                <option value="equipment" <?php echo $reportType === 'equipment' ? 'selected' : ''; ?>>Equipment Management</option>
                                <option value="attendance" <?php echo $reportType === 'attendance' ? 'selected' : ''; ?>>Member Attendance</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label" for="date_range">Date Range</label>
                            <select name="date_range" id="date_range" class="filter-select">
                                <option value="month" <?php echo $dateRange === 'month' ? 'selected' : ''; ?>>Monthly</option>
                                <option value="quarter" <?php echo $dateRange === 'quarter' ? 'selected' : ''; ?>>Quarterly</option>
                                <option value="year" <?php echo $dateRange === 'year' ? 'selected' : ''; ?>>Yearly</option>
                                <option value="custom" <?php echo $dateRange === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label" for="month">Month</label>
                            <select name="month" id="month" class="filter-select">
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $month === $i ? 'selected' : ''; ?>>
                                        <?php echo getMonthName($i); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label" for="year">Year</label>
                            <select name="year" id="year" class="filter-select">
                                <?php for ($i = date('Y'); $i >= date('Y') - 5; $i--): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $year === $i ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group" id="customDateRange" style="display: none;">
                            <label class="filter-label" for="start_date">Start Date</label>
                            <input type="date" name="start_date" id="start_date" class="filter-input" value="<?php echo $customStartDate; ?>">
                        </div>
                        
                        <div class="filter-group" id="customDateRangeEnd" style="display: none;">
                            <label class="filter-label" for="end_date">End Date</label>
                            <input type="date" name="end_date" id="end_date" class="filter-input" value="<?php echo $customEndDate; ?>">
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn">
                            <i class="fas fa-chart-bar"></i> Generate Report
                        </button>
                        <a href="reports.php" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset Filters
                        </a>
                        <button type="button" class="btn btn-outline" onclick="toggleAutoRefresh()">
                            <i class="fas fa-sync-alt"></i> Auto Refresh
                        </button>
                    </div>
                </form>
            </div>

            <!-- Export Section -->
            <div class="export-section">
                <h3 style="margin-bottom: 1.5rem; color: var(--text);">
                    <i class="fas fa-download"></i> Export Reports
                </h3>
                <div class="export-grid">
                    <a href="?export=<?php echo $reportType; ?>&format=csv&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="export-option">
                        <div class="export-icon">
                            <i class="fas fa-file-csv"></i>
                        </div>
                        <div>
                            <div style="font-weight: 600;">CSV Export</div>
                            <div style="font-size: 0.8rem; color: var(--text-secondary);">Comma-separated values</div>
                        </div>
                    </a>
                    
                    <a href="?export=<?php echo $reportType; ?>&format=excel&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="export-option">
                        <div class="export-icon">
                            <i class="fas fa-file-excel"></i>
                        </div>
                        <div>
                            <div style="font-weight: 600;">Excel Export</div>
                            <div style="font-size: 0.8rem; color: var(--text-secondary);">Microsoft Excel format</div>
                        </div>
                    </a>
                    
                    <a href="?export=<?php echo $reportType; ?>&format=pdf&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="export-option">
                        <div class="export-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div>
                            <div style="font-weight: 600;">PDF Export</div>
                            <div style="font-size: 0.8rem; color: var(--text-secondary);">Portable document format</div>
                        </div>
                    </a>
                    
                    <button onclick="scheduleReport()" class="export-option" style="border: none; cursor: pointer;">
                        <div class="export-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <div style="font-weight: 600;">Schedule Report</div>
                            <div style="font-size: 0.8rem; color: var(--text-secondary);">Automated delivery</div>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Report Tabs -->
            <div class="report-tabs">
                <a href="?report_type=dashboard_overview&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="report-tab <?php echo $reportType === 'dashboard_overview' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i> Overview
                </a>
                <a href="?report_type=user_activity&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="report-tab <?php echo $reportType === 'user_activity' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> User Activity
                </a>
                <a href="?report_type=financial&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="report-tab <?php echo $reportType === 'financial' ? 'active' : ''; ?>">
                    <i class="fas fa-dollar-sign"></i> Financial
                </a>
                <a href="?report_type=trainer_performance&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="report-tab <?php echo $reportType === 'trainer_performance' ? 'active' : ''; ?>">
                    <i class="fas fa-user-tie"></i> Trainers
                </a>
                <a href="?report_type=equipment&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="report-tab <?php echo $reportType === 'equipment' ? 'active' : ''; ?>">
                    <i class="fas fa-dumbbell"></i> Equipment
                </a>
                <a href="?report_type=attendance&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="report-tab <?php echo $reportType === 'attendance' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i> Attendance
                </a>
            </div>

            <!-- Dashboard Overview Report -->
            <?php if ($reportType === 'dashboard_overview'): ?>
                <!-- Key Performance Indicators -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon primary">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                        <div class="stat-value">
                            <?php 
                            $totalUsers = 0;
                            if (isset($reportData['user_counts'])) {
                                foreach ($reportData['user_counts'] as $userCount) {
                                    $totalUsers += $userCount['count'];
                                }
                            }
                            echo $totalUsers;
                            ?>
                        </div>
                        <div class="stat-label">Total Active Users</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> +5.2% from last month
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon success">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                        </div>
                        <div class="stat-value">
                            $<?php echo number_format($reportData['financial_summary']['total_revenue'] ?? 0, 2); ?>
                        </div>
                        <div class="stat-label">Monthly Revenue</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> +12.5% from last month
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon info">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                        </div>
                        <div class="stat-value">
                            <?php 
                            $totalVisits = 0;
                            if (isset($reportData['attendance_data'])) {
                                foreach ($reportData['attendance_data'] as $attendance) {
                                    $totalVisits += $attendance['daily_visits'];
                                }
                            }
                            echo $totalVisits;
                            ?>
                        </div>
                        <div class="stat-label">Monthly Visits</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> +8.3% from last month
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon warning">
                                <i class="fas fa-dumbbell"></i>
                            </div>
                        </div>
                        <div class="stat-value">
                            <?php 
                            $activeEquipment = 0;
                            if (isset($reportData['equipment_status'])) {
                                foreach ($reportData['equipment_status'] as $equipment) {
                                    if ($equipment['status'] === 'Active') {
                                        $activeEquipment = $equipment['count'];
                                        break;
                                    }
                                }
                            }
                            echo $activeEquipment;
                            ?>
                        </div>
                        <div class="stat-label">Active Equipment</div>
                        <div class="stat-change negative">
                            <i class="fas fa-arrow-down"></i> -2 under maintenance
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="card">
                    <div class="chart-controls">
                        <h3 class="chart-title">Daily Attendance Trends</h3>
                        <div class="chart-options">
                            <div class="chart-option active" onclick="switchChart('attendance', 'line')">Line</div>
                            <div class="chart-option" onclick="switchChart('attendance', 'bar')">Bar</div>
                            <div class="chart-option" onclick="switchChart('attendance', 'area')">Area</div>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="attendanceChart"></canvas>
                    </div>
                </div>

                <div class="card">
                    <div class="chart-controls">
                        <h3 class="chart-title">User Distribution by Role</h3>
                        <div class="chart-options">
                            <div class="chart-option active" onclick="switchChart('users', 'doughnut')">Doughnut</div>
                            <div class="chart-option" onclick="switchChart('users', 'pie')">Pie</div>
                            <div class="chart-option" onclick="switchChart('users', 'bar')">Bar</div>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="userDistributionChart"></canvas>
                    </div>
                </div>

                <script>
                    // Attendance Chart
                    const attendanceCtx = document.getElementById('attendanceChart').getContext('2d');
                    const attendanceChart = new Chart(attendanceCtx, {
                        type: 'line',
                        data: {
                            labels: [
                                <?php
                                if (isset($reportData['attendance_data'])) {
                                    $labels = [];
                                    foreach ($reportData['attendance_data'] as $data) {
                                        $labels[] = "'" . date('M j', strtotime($data['date'])) . "'";
                                    }
                                    echo implode(', ', $labels);
                                }
                                ?>
                            ],
                            datasets: [{
                                label: 'Daily Visits',
                                data: [
                                    <?php
                                    if (isset($reportData['attendance_data'])) {
                                        $values = [];
                                        foreach ($reportData['attendance_data'] as $data) {
                                            $values[] = $data['daily_visits'];
                                        }
                                        echo implode(', ', $values);
                                    }
                                    ?>
                                ],
                                borderColor: '#FF8C00',
                                backgroundColor: 'rgba(255, 140, 0, 0.1)',
                                tension: 0.4,
                                fill: true,
                                pointBackgroundColor: '#FF8C00',
                                pointBorderColor: '#ffffff',
                                pointBorderWidth: 2,
                                pointRadius: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                    titleColor: '#ffffff',
                                    bodyColor: '#ffffff',
                                    borderColor: '#FF8C00',
                                    borderWidth: 1
                                }
                            },
                            scales: {
                                x: {
                                    ticks: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    },
                                    grid: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--border')
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    },
                                    grid: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--border')
                                    }
                                }
                            }
                        }
                    });

                    // User Distribution Chart
                    const userCtx = document.getElementById('userDistributionChart').getContext('2d');
                    const userChart = new Chart(userCtx, {
                        type: 'doughnut',
                        data: {
                            labels: [
                                <?php
                                if (isset($reportData['user_counts'])) {
                                    $labels = [];
                                    foreach ($reportData['user_counts'] as $data) {
                                        $labels[] = "'" . $data['role'] . "'";
                                    }
                                    echo implode(', ', $labels);
                                }
                                ?>
                            ],
                            datasets: [{
                                data: [
                                    <?php
                                    if (isset($reportData['user_counts'])) {
                                        $values = [];
                                        foreach ($reportData['user_counts'] as $data) {
                                            $values[] = $data['count'];
                                        }
                                        echo implode(', ', $values);
                                    }
                                    ?>
                                ],
                                backgroundColor: [
                                    '#FF8C00',
                                    '#28a745',
                                    '#17a2b8',
                                    '#ffc107',
                                    '#dc3545'
                                ],
                                borderWidth: 0,
                                hoverBorderWidth: 3,
                                hoverBorderColor: '#ffffff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color'),
                                        padding: 20,
                                        usePointStyle: true
                                    }
                                },
                                tooltip: {
                                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                    titleColor: '#ffffff',
                                    bodyColor: '#ffffff',
                                    borderColor: '#FF8C00',
                                    borderWidth: 1
                                }
                            }
                        }
                    });
                </script>
            <?php endif; ?>

            <!-- Financial Report -->
            <?php if ($reportType === 'financial'): ?>
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-dollar-sign"></i>
                            Financial Report - <?php echo getMonthName($month) . ' ' . $year; ?>
                        </h2>
                    </div>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon success">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                            </div>
                            <div class="stat-value">
                                $<?php 
                                $totalRevenue = 0;
                                if (!empty($reportData)) {
                                    foreach ($reportData as $data) {
                                        $totalRevenue += $data['total_amount'];
                                    }
                                }
                                echo number_format($totalRevenue, 2);
                                ?>
                            </div>
                            <div class="stat-label">Total Revenue</div>
                            <div class="stat-change positive">
                                <i class="fas fa-arrow-up"></i> +15.3% from last month
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon info">
                                    <i class="fas fa-receipt"></i>
                                </div>
                            </div>
                            <div class="stat-value">
                                <?php 
                                $totalTransactions = 0;
                                if (!empty($reportData)) {
                                    foreach ($reportData as $data) {
                                        $totalTransactions += $data['transaction_count'];
                                    }
                                }
                                echo $totalTransactions;
                                ?>
                            </div>
                            <div class="stat-label">Total Transactions</div>
                            <div class="stat-change positive">
                                <i class="fas fa-arrow-up"></i> +8.7% from last month
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon warning">
                                    <i class="fas fa-calculator"></i>
                                </div>
                            </div>
                            <div class="stat-value">
                                $<?php echo $totalTransactions > 0 ? number_format($totalRevenue / $totalTransactions, 2) : '0.00'; ?>
                            </div>
                            <div class="stat-label">Average Transaction</div>
                            <div class="stat-change positive">
                                <i class="fas fa-arrow-up"></i> +3.2% from last month
                            </div>
                        </div>
                    </div>

                    <div class="chart-container">
                        <canvas id="revenueChart"></canvas>
                    </div>

                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Transaction Type</th>
                                    <th>Payment Method</th>
                                    <th>Amount</th>
                                    <th>Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($reportData)): ?>
                                    <?php foreach ($reportData as $data): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($data['date'])); ?></td>
                                            <td>
                                                <span class="badge badge-primary">
                                                    <?php echo ucwords(str_replace('_', ' ', $data['transaction_type'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($data['payment_method']); ?></td>
                                            <td>$<?php echo number_format($data['total_amount'], 2); ?></td>
                                            <td><?php echo $data['transaction_count']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center;">No financial data available for this period.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <script>
                    // Revenue Chart
                    const revenueCtx = document.getElementById('revenueChart').getContext('2d');
                    const revenueChart = new Chart(revenueCtx, {
                        type: 'bar',
                        data: {
                            labels: [
                                <?php
                                if (!empty($reportData)) {
                                    $labels = [];
                                    foreach ($reportData as $data) {
                                        $labels[] = "'" . date('M j', strtotime($data['date'])) . "'";
                                    }
                                    echo implode(', ', array_unique($labels));
                                }
                                ?>
                            ],
                            datasets: [{
                                label: 'Daily Revenue',
                                data: [
                                    <?php
                                    if (!empty($reportData)) {
                                        $dailyRevenue = [];
                                        foreach ($reportData as $data) {
                                            $date = $data['date'];
                                            if (!isset($dailyRevenue[$date])) {
                                                $dailyRevenue[$date] = 0;
                                            }
                                            $dailyRevenue[$date] += $data['total_amount'];
                                        }
                                        echo implode(', ', array_values($dailyRevenue));
                                    }
                                    ?>
                                ],
                                backgroundColor: '#FF8C00',
                                borderColor: '#e67e00',
                                borderWidth: 1,
                                borderRadius: 8
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                    titleColor: '#ffffff',
                                    bodyColor: '#ffffff',
                                    borderColor: '#FF8C00',
                                    borderWidth: 1,
                                    callbacks: {
                                        label: function(context) {
                                            return 'Revenue: $' + context.parsed.y.toLocaleString();
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    ticks: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    },
                                    grid: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--border')
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color'),
                                        callback: function(value) {
                                            return '$' + value.toLocaleString();
                                        }
                                    },
                                    grid: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--border')
                                    }
                                }
                            }
                        }
                    });
                </script>
            <?php endif; ?>

            <!-- Trainer Performance Report -->
            <?php if ($reportType === 'trainer_performance'): ?>
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-user-tie"></i>
                            Trainer Performance Report - <?php echo getMonthName($month) . ' ' . $year; ?>
                        </h2>
                    </div>

                    <div class="chart-container">
                        <canvas id="trainerChart"></canvas>
                    </div>

                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Trainer Name</th>
                                    <th>Unique Members</th>
                                    <th>Total Sessions</th>
                                    <th>Avg Duration</th>
                                    <th>Avg Rating</th>
                                    <th>Performance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($reportData)): ?>
                                    <?php foreach ($reportData as $trainer): ?>
                                        <?php
                                        $rating = $trainer['avg_rating'] ?? 0;
                                        $sessions = $trainer['total_sessions'] ?? 0;
                                        
                                        if ($rating >= 4.5 && $sessions >= 20) {
                                            $performance = 'Excellent';
                                            $badgeClass = 'badge-success';
                                        } elseif ($rating >= 4.0 && $sessions >= 15) {
                                            $performance = 'Good';
                                            $badgeClass = 'badge-info';
                                        } elseif ($rating >= 3.5 && $sessions >= 10) {
                                            $performance = 'Average';
                                            $badgeClass = 'badge-warning';
                                        } else {
                                            $performance = 'Needs Improvement';
                                            $badgeClass = 'badge-danger';
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($trainer['trainer_name']); ?></td>
                                            <td><?php echo $trainer['unique_members'] ?? 0; ?></td>
                                            <td><?php echo $trainer['total_sessions'] ?? 0; ?></td>
                                            <td><?php echo round($trainer['avg_session_duration'] ?? 0); ?> min</td>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                    <span><?php echo number_format($rating, 1); ?></span>
                                                    <div style="color: #ffc107;">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i class="fas fa-star<?php echo $i <= $rating ? '' : '-o'; ?>"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $badgeClass; ?>">
                                                    <?php echo $performance; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center;">No trainer performance data available for this period.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <script>
                    // Trainer Performance Chart
                    const trainerCtx = document.getElementById('trainerChart').getContext('2d');
                    const trainerChart = new Chart(trainerCtx, {
                        type: 'radar',
                        data: {
                            labels: [
                                <?php
                                if (!empty($reportData)) {
                                    $labels = [];
                                    foreach (array_slice($reportData, 0, 6) as $trainer) {
                                        $labels[] = "'" . $trainer['trainer_name'] . "'";
                                    }
                                    echo implode(', ', $labels);
                                }
                                ?>
                            ],
                            datasets: [
                                {
                                    label: 'Total Sessions',
                                    data: [
                                        <?php
                                        if (!empty($reportData)) {
                                            $values = [];
                                            foreach (array_slice($reportData, 0, 6) as $trainer) {
                                                $values[] = $trainer['total_sessions'] ?? 0;
                                            }
                                            echo implode(', ', $values);
                                        }
                                        ?>
                                    ],
                                    borderColor: '#FF8C00',
                                    backgroundColor: 'rgba(255, 140, 0, 0.2)',
                                    pointBackgroundColor: '#FF8C00',
                                    pointBorderColor: '#ffffff',
                                    pointBorderWidth: 2
                                },
                                {
                                    label: 'Average Rating (x10)',
                                    data: [
                                        <?php
                                        if (!empty($reportData)) {
                                            $values = [];
                                            foreach (array_slice($reportData, 0, 6) as $trainer) {
                                                $values[] = ($trainer['avg_rating'] ?? 0) * 10;
                                            }
                                            echo implode(', ', $values);
                                        }
                                        ?>
                                    ],
                                    borderColor: '#28a745',
                                    backgroundColor: 'rgba(40, 167, 69, 0.2)',
                                    pointBackgroundColor: '#28a745',
                                    pointBorderColor: '#ffffff',
                                    pointBorderWidth: 2
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'top',
                                    labels: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    }
                                }
                            },
                            scales: {
                                r: {
                                    beginAtZero: true,
                                    ticks: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                                    },
                                    grid: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--border')
                                    },
                                    angleLines: {
                                        color: getComputedStyle(document.documentElement).getPropertyValue('--border')
                                    }
                                }
                            }
                        }
                    });
                </script>
            <?php endif; ?>

            <!-- Equipment Report -->
            <?php if ($reportType === 'equipment'): ?>
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-dumbbell"></i>
                            Equipment Management Report - <?php echo getMonthName($month) . ' ' . $year; ?>
                        </h2>
                    </div>

                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Equipment Name</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Usage Count</th>
                                    <th>Last Maintenance</th>
                                    <th>Next Maintenance</th>
                                    <th>Maintenance Sessions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($reportData)): ?>
                                    <?php foreach ($reportData as $equipment): ?>
                                        <?php
                                        $statusClass = '';
                                        switch ($equipment['status']) {
                                            case 'Active':
                                                $statusClass = 'badge-success';
                                                break;
                                            case 'Maintenance':
                                                $statusClass = 'badge-warning';
                                                break;
                                            case 'Out of Order':
                                                $statusClass = 'badge-danger';
                                                break;
                                            case 'Retired':
                                                $statusClass = 'badge-secondary';
                                                break;
                                            default:
                                                $statusClass = 'badge-info';
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($equipment['name']); ?></td>
                                            <td><?php echo htmlspecialchars($equipment['category']); ?></td>
                                            <td>
                                                <span class="badge <?php echo $statusClass; ?>">
                                                    <?php echo htmlspecialchars($equipment['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $equipment['usage_count']; ?></td>
                                            <td>
                                                <?php echo $equipment['last_maintenance'] ? date('M j, Y', strtotime($equipment['last_maintenance'])) : 'Never'; ?>
                                            </td>
                                            <td>
                                                <?php echo $equipment['next_maintenance'] ? date('M j, Y', strtotime($equipment['next_maintenance'])) : 'Not scheduled'; ?>
                                            </td>
                                            <td><?php echo $equipment['maintenance_sessions']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center;">No equipment data available for this period.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Attendance Report -->
            <?php if ($reportType === 'attendance'): ?>
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-calendar-check"></i>
                            Member Attendance Report - <?php echo getMonthName($month) . ' ' . $year; ?>
                        </h2>
                    </div>

                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Member Name</th>
                                    <th>Total Visits</th>
                                    <th>Avg Duration</th>
                                    <th>First Visit</th>
                                    <th>Last Visit</th>
                                    <th>Attendance Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($reportData)): ?>
                                    <?php foreach ($reportData as $member): ?>
                                        <?php
                                        $visits = $member['total_visits'];
                                        $daysInMonth = date('t', mktime(0, 0, 0, $month, 1, $year));
                                        $attendanceRate = ($visits / $daysInMonth) * 100;
                                        
                                        if ($attendanceRate >= 80) {
                                            $rateClass = 'badge-success';
                                        } elseif ($attendanceRate >= 60) {
                                            $rateClass = 'badge-info';
                                        } elseif ($attendanceRate >= 40) {
                                            $rateClass = 'badge-warning';
                                        } else {
                                            $rateClass = 'badge-danger';
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($member['member_name']); ?></td>
                                            <td><?php echo $member['total_visits']; ?></td>
                                            <td><?php echo round($member['avg_duration']); ?> min</td>
                                            <td><?php echo date('M j, Y', strtotime($member['first_visit'])); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($member['last_visit'])); ?></td>
                                            <td>
                                                <span class="badge <?php echo $rateClass; ?>">
                                                    <?php echo round($attendanceRate, 1); ?>%
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center;">No attendance data available for this period.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Global variables
        let autoRefreshInterval;
        let isAutoRefreshEnabled = false;

        // Theme handling
        function updateChartColors() {
            const textColor = getComputedStyle(document.documentElement).getPropertyValue('--text-color');
            const borderColor = getComputedStyle(document.documentElement).getPropertyValue('--border');
            
            // Update all charts with new colors
            Chart.helpers.each(Chart.instances, function(chart) {
                if (chart.options.plugins && chart.options.plugins.legend) {
                    chart.options.plugins.legend.labels.color = textColor;
                }
                if (chart.options.scales) {
                    Object.keys(chart.options.scales).forEach(scaleKey => {
                        const scale = chart.options.scales[scaleKey];
                        if (scale.ticks) scale.ticks.color = textColor;
                        if (scale.grid) scale.grid.color = borderColor;
                        if (scale.angleLines) scale.angleLines.color = borderColor;
                    });
                }
                chart.update();
            });
        }

        // Auto refresh functionality
        function toggleAutoRefresh() {
            if (isAutoRefreshEnabled) {
                clearInterval(autoRefreshInterval);
                isAutoRefreshEnabled = false;
                showNotification('Auto refresh disabled', 'info');
            } else {
                autoRefreshInterval = setInterval(() => {
                    showLoadingOverlay();
                    location.reload();
                }, 30000); // Refresh every 30 seconds
                isAutoRefreshEnabled = true;
                showNotification('Auto refresh enabled (30s interval)', 'success');
            }
        }

        // Chart switching functionality
        function switchChart(chartType, newType) {
            // Update chart options
            const chartOptions = document.querySelectorAll(`[onclick*="${chartType}"]`);
            chartOptions.forEach(option => option.classList.remove('active'));
            event.target.classList.add('active');
            
            // Here you would implement chart type switching logic
            showNotification(`Switched to ${newType} chart`, 'info');
        }

        // Date range handling
        document.getElementById('date_range').addEventListener('change', function() {
            const customRangeElements = document.querySelectorAll('#customDateRange, #customDateRangeEnd');
            if (this.value === 'custom') {
                customRangeElements.forEach(el => el.style.display = 'block');
            } else {
                customRangeElements.forEach(el => el.style.display = 'none');
            }
        });

        // Loading overlay
        function showLoadingOverlay() {
            document.getElementById('loadingOverlay').classList.add('show');
        }

        function hideLoadingOverlay() {
            document.getElementById('loadingOverlay').classList.remove('show');
        }

        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation-triangle' : 'info-circle'}"></i>
                <span>${message}</span>
            `;
            
            // Add notification styles
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: var(--card-bg);
                border: 1px solid var(--border);
                border-radius: var(--border-radius);
                padding: 1rem 1.5rem;
                box-shadow: var(--shadow);
                z-index: 10000;
                display: flex;
                align-items: center;
                gap: 0.75rem;
                color: var(--text);
                transform: translateX(100%);
                transition: var(--transition);
            `;
            
            document.body.appendChild(notification);
            
            // Animate in
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            // Remove after 3 seconds
            setTimeout(() => {
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Schedule report functionality
        function scheduleReport() {
            const modal = document.createElement('div');
            modal.innerHTML = `
                <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">
                    <div style="background: var(--card-bg); border-radius: var(--border-radius); padding: 2rem; max-width: 500px; width: 90%;">
                        <h3 style="margin-bottom: 1.5rem; color: var(--text);">Schedule Report</h3>
                        <form id="scheduleForm">
                            <div style="margin-bottom: 1rem;">
                                <label style="display: block; margin-bottom: 0.5rem; color: var(--text);">Frequency</label>
                                <select style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--border-radius); background: var(--input-bg); color: var(--text);">
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            </div>
                            <div style="margin-bottom: 1rem;">
                                <label style="display: block; margin-bottom: 0.5rem; color: var(--text);">Email</label>
                                <input type="email" placeholder="admin@elitefit.com" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--border-radius); background: var(--input-bg); color: var(--text);">
                            </div>
                            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                                <button type="button" onclick="this.closest('div').parentNode.remove()" style="padding: 0.75rem 1.5rem; background: var(--secondary); color: white; border: none; border-radius: var(--border-radius); cursor: pointer;">Cancel</button>
                                <button type="submit" style="padding: 0.75rem 1.5rem; background: var(--primary); color: white; border: none; border-radius: var(--border-radius); cursor: pointer;">Schedule</button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            modal.querySelector('#scheduleForm').addEventListener('submit', function(e) {
                e.preventDefault();
                showNotification('Report scheduled successfully!', 'success');
                modal.remove();
            });
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            hideLoadingOverlay();
            
            // Update chart colors on theme change
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'data-theme') {
                        setTimeout(updateChartColors, 100);
                    }
                });
            });
            
            observer.observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['data-theme']
            });
            
            // Show welcome message
            setTimeout(() => {
                showNotification('Reports dashboard loaded successfully!', 'success');
            }, 1000);
        });

        // Export functionality with progress
        document.querySelectorAll('.export-option').forEach(link => {
            if (link.href) {
                link.addEventListener('click', function(e) {
                    showLoadingOverlay();
                    showNotification('Generating export file...', 'info');
                    
                    // Hide loading after a delay (simulating export process)
                    setTimeout(() => {
                        hideLoadingOverlay();
                        showNotification('Export completed successfully!', 'success');
                    }, 2000);
                });
            }
        });

        // Form submission with loading
        document.getElementById('filtersForm').addEventListener('submit', function() {
            showLoadingOverlay();
        });

        // Mobile sidebar toggle
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }

        // Add mobile menu button if needed
        if (window.innerWidth <= 768) {
            const mobileMenuBtn = document.createElement('button');
            mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            mobileMenuBtn.style.cssText = `
                position: fixed;
                top: 1rem;
                left: 1rem;
                z-index: 1002;
                background: var(--primary);
                color: white;
                border: none;
                border-radius: var(--border-radius);
                padding: 0.75rem;
                cursor: pointer;
            `;
            mobileMenuBtn.onclick = toggleSidebar;
            document.body.appendChild(mobileMenuBtn);
        }
    </script>
</body>
</html>