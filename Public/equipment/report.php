<?php
// Prevent any output before headers
ob_start();
session_start();

// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log the current directory and included files for debugging
error_log("Current directory: " . __DIR__);

// Define the required functions directly in this file to avoid dependency issues
// function connectDB() {
//     try {
//         $host = 'localhost';
//         $dbname = 'elitefitgym';
//         $username = 'root';
//         $password = '';
        
//         $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
//         $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
//         return $conn;
//     } catch (Exception $e) {
//         error_log("Database connection error: " . $e->getMessage());
//         throw $e;
//     }
// }

function ensureDatabaseTablesExist($conn) {
    try {
        // Check if dashboard_settings table exists
        $tableExists = false;
        $stmt = $conn->query("SHOW TABLES LIKE 'dashboard_settings'");
        $tableExists = ($stmt && $stmt->rowCount() > 0);
        
        // Create dashboard_settings table if it doesn't exist
        if (!$tableExists) {
            $createTableSQL = "
                CREATE TABLE dashboard_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    theme_preference VARCHAR(20) DEFAULT 'dark',
                    sidebar_collapsed TINYINT(1) DEFAULT 0,
                    items_per_page INT DEFAULT 10,
                    default_view VARCHAR(20) DEFAULT 'list',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY (user_id)
                )
            ";
            $conn->exec($createTableSQL);
        }
    } catch (PDOException $e) {
        error_log("Error in ensureDatabaseTablesExist: " . $e->getMessage());
        // Continue execution even if there's an error
    }
}

function getUserThemePreference($conn, $userId) {
    try {
        // Ensure tables exist
        ensureDatabaseTablesExist($conn);
        
        // Get user theme preference
        $stmt = $conn->prepare("SELECT theme_preference FROM dashboard_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        if ($stmt && $stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row['theme_preference'];
        } else {
            // Create default settings for user
            $stmt = $conn->prepare("INSERT INTO dashboard_settings (user_id, theme_preference) VALUES (?, 'dark') ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)");
            $stmt->execute([$userId]);
            return 'dark';
        }
    } catch (Exception $e) {
        error_log("Error in getUserThemePreference: " . $e->getMessage());
        return 'dark'; // Default to dark theme if there's an error
    }
}

function generateInlineCSS($theme = 'dark') {
    $variables = [
        'dark' => [
            '--primary-bg' => '#121212',
            '--secondary-bg' => '#1e1e1e',
            '--card-bg' => '#252525',
            '--text-color' => '#ffffff',
            '--text-muted' => '#b0b0b0',
            '--accent-color' => '#ff8c00',
            '--accent-hover' => '#ff7000',
            '--border-color' => '#333333',
            '--success-color' => '#4caf50',
            '--warning-color' => '#ff9800',
            '--danger-color' => '#f44336',
            '--info-color' => '#2196f3',
        ],
        'light' => [
            '--primary-bg' => '#f8f9fa',
            '--secondary-bg' => '#ffffff',
            '--card-bg' => '#ffffff',
            '--text-color' => '#212529',
            '--text-muted' => '#6c757d',
            '--accent-color' => '#ff8c00',
            '--accent-hover' => '#ff7000',
            '--border-color' => '#dee2e6',
            '--success-color' => '#28a745',
            '--warning-color' => '#ffc107',
            '--danger-color' => '#dc3545',
            '--info-color' => '#17a2b8',
        ]
    ];
    
    $themeVars = $variables[$theme] ?? $variables['dark'];
    $css = ":root {\n";
    
    foreach ($themeVars as $name => $value) {
        $css .= "    $name: $value;\n";
    }
    
    $css .= "}";
    return $css;
}

function doesEquipmentTableExist($conn) {
    try {
        $stmt = $conn->query("SHOW TABLES LIKE 'equipment'");
        return ($stmt && $stmt->rowCount() > 0);
    } catch (PDOException $e) {
        error_log("Error checking equipment table: " . $e->getMessage());
        return false;
    }
}

function doesMaintenanceTableExist($conn) {
    try {
        $stmt = $conn->query("SHOW TABLES LIKE 'maintenance'");
        return ($stmt && $stmt->rowCount() > 0);
    } catch (PDOException $e) {
        error_log("Error checking maintenance table: " . $e->getMessage());
        return false;
    }
}

// Try to include other required files, but don't fail if they're not found
try {
    if (file_exists('../../config/database.php')) {
        require_once '../../config/database.php';
    }
    
    if (file_exists('../auth_middleware.php')) {
        require_once '../auth_middleware.php';
    }
} catch (Exception $e) {
    error_log("Error including optional files: " . $e->getMessage());
    // Continue execution even if these files aren't found
}

// Check if user is logged in and has appropriate permissions
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Check role with more flexibility (case-insensitive comparison)
$userRole = strtolower($_SESSION['role'] ?? '');
$allowedRoles = ['equipment_manager', 'equipmentmanager', 'equipment manager', 'admin', 'administrator'];
if (!in_array($userRole, $allowedRoles)) {
    // Log the issue for debugging
    error_log("Access denied to report.php. User role: " . ($_SESSION['role'] ?? 'undefined'));
    header('Location: dashboard.php');
    exit();
}

// Connect to database with error handling
try {
    // Try to use the Database class if it exists
    if (class_exists('Database')) {
        $db = new Database();
        $conn = $db->getConnection();
    } else {
        // Fall back to our own connection function
        $conn = connectDB();
    }
    
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    error_log("Database connection error in report.php: " . $e->getMessage());
    echo "<div style='color:red; padding:20px; background:#ffeeee; border:1px solid #ff0000; margin:20px;'>";
    echo "<h3>Database Connection Error</h3>";
    echo "<p>Could not connect to the database. Please check your configuration.</p>";
    echo "<p><a href='dashboard.php' class='btn btn-primary'>Return to Dashboard</a></p>";
    echo "</div>";
    exit();
}

// Get user theme preference
$userId = $_SESSION['user_id'];
try {
    $theme = getUserThemePreference($conn, $userId);
} catch (Exception $e) {
    error_log("Error getting theme preference: " . $e->getMessage());
    $theme = 'dark'; // Default to dark theme if there's an error
}
$userName = $_SESSION['username'] ?? 'Equipment Manager';

// Get report type and parameters
$reportType = isset($_GET['type']) ? $_GET['type'] : 'inventory';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$equipmentType = isset($_GET['equipment_type']) ? $_GET['equipment_type'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$exportFormat = isset($_GET['export']) ? $_GET['export'] : '';

// Handle export request
if (!empty($exportFormat)) {
    if (file_exists('export-handler.php')) {
        require_once 'export-handler.php';
        exportReport($conn, $reportType, $startDate, $endDate, $equipmentType, $status, $exportFormat);
        exit();
    } else {
        echo "<div style='color:red; padding:20px;'>Export handler file not found</div>";
    }
}

// Check if tables exist
$equipmentTableExists = doesEquipmentTableExist($conn);
$maintenanceTableExists = doesMaintenanceTableExist($conn);

// Get equipment types for filter
$equipmentTypes = [];
if ($equipmentTableExists) {
    try {
        $typeQuery = "SELECT DISTINCT type FROM equipment ORDER BY type";
        $stmt = $conn->query($typeQuery);
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $equipmentTypes[] = $row['type'];
            }
        }
    } catch (PDOException $e) {
        error_log("Error getting equipment types: " . $e->getMessage());
    }
}

// Get maintenance statuses for filter
$maintenanceStatuses = ['pending', 'in_progress', 'completed', 'cancelled'];

// Get equipment statuses for filter
$equipmentStatuses = ['available', 'in_use', 'maintenance', 'retired'];

// Get report data based on type
function getReportDataForDisplay($conn, $reportType, $startDate, $endDate, $equipmentType, $status) {
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
        error_log("Error in getReportDataForDisplay: " . $e->getMessage());
    }
    
    return $data;
}

// Get report data
$reportData = [];
if ($equipmentTableExists && ($reportType != 'maintenance' || $maintenanceTableExists)) {
    $reportData = getReportDataForDisplay($conn, $reportType, $startDate, $endDate, $equipmentType, $status);
}

// Get summary statistics
$totalEquipment = 0;
$availableEquipment = 0;
$inUseEquipment = 0;
$maintenanceEquipment = 0;
$retiredEquipment = 0;

if ($equipmentTableExists) {
    try {
        $statsQuery = "SELECT 
                      COUNT(*) as total,
                      SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
                      SUM(CASE WHEN status = 'in_use' THEN 1 ELSE 0 END) as in_use,
                      SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
                      SUM(CASE WHEN status = 'retired' THEN 1 ELSE 0 END) as retired
                      FROM equipment";
        
        $stmt = $conn->query($statsQuery);
        if ($stmt && $row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $totalEquipment = $row['total'];
            $availableEquipment = $row['available'];
            $inUseEquipment = $row['in_use'];
            $maintenanceEquipment = $row['maintenance'];
            $retiredEquipment = $row['retired'];
        }
    } catch (PDOException $e) {
        error_log("Error getting equipment statistics: " . $e->getMessage());
    }
}

// Get chart data
$chartData = [];

// Equipment by type chart
if ($equipmentTableExists) {
    try {
        $typeChartQuery = "SELECT type, COUNT(*) as count FROM equipment GROUP BY type ORDER BY count DESC";
        $stmt = $conn->query($typeChartQuery);
        if ($stmt) {
            $chartData['equipmentByType'] = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $chartData['equipmentByType'][] = $row;
            }
        }
    } catch (PDOException $e) {
        error_log("Error getting equipment type chart data: " . $e->getMessage());
    }
}

// Maintenance by month chart
if ($maintenanceTableExists) {
    try {
        $maintenanceChartQuery = "SELECT 
                                 DATE_FORMAT(maintenance_date, '%Y-%m') as month,
                                 COUNT(*) as count,
                                 SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                                 SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                                 SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                                 SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                                 FROM maintenance 
                                 WHERE maintenance_date BETWEEN DATE_SUB(:endDate, INTERVAL 6 MONTH) AND :endDate
                                 GROUP BY month
                                 ORDER BY month";
        
        $stmt = $conn->prepare($maintenanceChartQuery);
        $stmt->bindParam(':endDate', $endDate);
        $stmt->execute();
        
        if ($stmt) {
            $chartData['maintenanceByMonth'] = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $chartData['maintenanceByMonth'][] = $row;
            }
        }
    } catch (PDOException $e) {
        error_log("Error getting maintenance chart data: " . $e->getMessage());
    }
}

// Equipment usage by type chart
if ($equipmentTableExists) {
    try {
        $usageChartQuery = "SELECT e.type, COUNT(eu.id) as count, SUM(eu.duration) as total_duration
                           FROM equipment e
                           LEFT JOIN equipment_usage eu ON e.id = eu.equipment_id
                           WHERE eu.usage_date BETWEEN :startDate AND :endDate
                           GROUP BY e.type
                           ORDER BY count DESC";
        
        $stmt = $conn->prepare($usageChartQuery);
        $stmt->bindParam(':startDate', $startDate);
        $stmt->bindParam(':endDate', $endDate);
        $stmt->execute();
        
        if ($stmt) {
            $chartData['usageByType'] = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $chartData['usageByType'][] = $row;
            }
        }
    } catch (PDOException $e) {
        error_log("Error getting usage chart data: " . $e->getMessage());
    }
}

// Cost analysis chart
if ($equipmentTableExists && $maintenanceTableExists) {
    try {
        $costChartQuery = "SELECT e.type, 
                          SUM(e.purchase_cost) as purchase_cost,
                          (SELECT SUM(m.cost) FROM maintenance m WHERE m.equipment_id = e.id) as maintenance_cost
                          FROM equipment e
                          GROUP BY e.type
                          ORDER BY (SUM(e.purchase_cost) + IFNULL((SELECT SUM(m.cost) FROM maintenance m WHERE m.equipment_id = e.id), 0)) DESC";
        
        $stmt = $conn->query($costChartQuery);
        if ($stmt) {
            $chartData['costByType'] = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $chartData['costByType'][] = $row;
            }
        }
    } catch (PDOException $e) {
        error_log("Error getting cost chart data: " . $e->getMessage());
    }
}

// Get maintenance trends
$maintenanceTrends = [];
if ($maintenanceTableExists) {
    try {
        $trendsQuery = "SELECT 
                       DATE_FORMAT(maintenance_date, '%Y-%m') as month,
                       COUNT(*) as total,
                       SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                       SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                       SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                       SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                       AVG(cost) as avg_cost
                       FROM maintenance
                       WHERE maintenance_date BETWEEN DATE_SUB(:endDate, INTERVAL 6 MONTH) AND :endDate
                       GROUP BY month
                       ORDER BY month";
        
        $stmt = $conn->prepare($trendsQuery);
        $stmt->bindParam(':endDate', $endDate);
        $stmt->execute();
        
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $maintenanceTrends[] = $row;
            }
        }
    } catch (PDOException $e) {
        error_log("Error getting maintenance trends: " . $e->getMessage());
    }
}

// Get top used equipment
$topEquipment = [];
if ($equipmentTableExists) {
    try {
        $topQuery = "SELECT e.id, e.name, e.type, COUNT(eu.id) as usage_count, SUM(eu.duration) as total_duration
                    FROM equipment e
                    LEFT JOIN equipment_usage eu ON e.id = eu.equipment_id
                    WHERE eu.usage_date BETWEEN :startDate AND :endDate
                    GROUP BY e.id
                    ORDER BY usage_count DESC
                    LIMIT 5";
        
        $stmt = $conn->prepare($topQuery);
        $stmt->bindParam(':startDate', $startDate);
        $stmt->bindParam(':endDate', $endDate);
        $stmt->execute();
        
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $topEquipment[] = $row;
            }
        }
    } catch (PDOException $e) {
        error_log("Error getting top equipment: " . $e->getMessage());
    }
}

// Get maintenance cost summary
$maintenanceCostSummary = [];
if ($maintenanceTableExists) {
    try {
        $costSummaryQuery = "SELECT 
                            SUM(cost) as total_cost,
                            AVG(cost) as avg_cost,
                            MIN(cost) as min_cost,
                            MAX(cost) as max_cost,
                            COUNT(*) as count
                            FROM maintenance
                            WHERE maintenance_date BETWEEN :startDate AND :endDate";
        
        $stmt = $conn->prepare($costSummaryQuery);
        $stmt->bindParam(':startDate', $startDate);
        $stmt->bindParam(':endDate', $endDate);
        $stmt->execute();
        
        if ($stmt && $row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $maintenanceCostSummary = $row;
        }
    } catch (PDOException $e) {
        error_log("Error getting maintenance cost summary: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Equipment Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.css">
    <style>
        <?php echo generateInlineCSS($theme); ?>
        
        :root {
            --primary: #ff6600;
            --primary-hover: #e65c00;
            --secondary: #222222;
            --dark: #121212;
            --darker: #0a0a0a;
            --light: #f8f9fa;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }
        
        body {
            background-color: var(--primary-bg);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: background-color 0.3s, color 0.3s;
        }
        
        .navbar {
            background-color: var(--secondary-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 0.5rem 1rem;
        }
        
        .sidebar {
            background-color: var(--secondary-bg);
            min-height: calc(100vh - 56px);
            border-right: 1px solid var(--border-color);
            transition: all 0.3s;
            width: 250px;
            position: fixed;
            top: 56px;
            left: 0;
            bottom: 0;
            z-index: 100;
            overflow-y: auto;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar .nav-link {
            color: var(--text-color);
            border-radius: 5px;
            margin: 5px 10px;
            padding: 10px 15px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
        }
        
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background-color: var(--accent-color);
            color: white;
            transform: translateX(5px);
        }
        
        .sidebar .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                transform: translateX(0);
            }
            
            .sidebar.collapsed {
                transform: translateX(-100%);
            }
            
            .sidebar .nav-link span {
                display: none;
            }
            
            .sidebar .nav-link i {
                margin-right: 0;
                font-size: 1.2rem;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .main-content.expanded {
                margin-left: 0;
            }
        }
        
        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 20px;
            transition: transform 0.3s, box-shadow 0.3s;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }
        
        .card-header {
            background-color: var(--accent-color);
            color: white;
            border-radius: var(--border-radius) var(--border-radius) 0 0 !important;
            font-weight: bold;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .btn-primary {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
            transition: var(--transition);
        }
        
        .btn-primary:hover {
            background-color: var(--accent-hover);
            border-color: var(--accent-hover);
            transform: translateY(-2px);
        }
        
        .btn-outline-primary {
            color: var(--accent-color);
            border-color: var(--accent-color);
        }
        
        .btn-outline-primary:hover {
            background-color: var(--accent-color);
            color: white;
        }
        
        .theme-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        
        .theme-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--accent-color);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        .table {
            color: var(--text-color);
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            border-radius: var(--border-radius);
            overflow: hidden;
        }
        
        .table thead th {
            background-color: var(--secondary-bg);
            border-color: var(--border-color);
            padding: 12px 15px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }
        
        .table tbody td {
            border-color: var(--border-color);
            padding: 12px 15px;
            vertical-align: middle;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(255, 140, 0, 0.1);
        }
        
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .dark-theme .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(255, 255, 255, 0.02);
        }
        
        .setup-card {
            text-align: center;
            padding: 30px;
        }
        
        .setup-card i {
            font-size: 4rem;
            color: var(--accent-color);
            margin-bottom: 20px;
        }
        
        .no-data-message {
            text-align: center;
            padding: 20px;
            color: var(--text-muted);
        }
        
        .report-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .report-nav .nav-link {
            color: var(--text-color);
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 10px 15px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .report-nav .nav-link:hover, .report-nav .nav-link.active {
            background-color: var(--accent-color);
            color: white;
            border-color: var(--accent-color);
            transform: translateY(-2px);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            margin-bottom: 20px;
        }
        
        .form-control, .form-select {
            background-color: var(--secondary-bg);
            border-color: var(--border-color);
            color: var(--text-color);
            border-radius: var(--border-radius);
            padding: 10px 15px;
            transition: var(--transition);
        }
        
        .form-control:focus, .form-select:focus {
            background-color: var(--secondary-bg);
            color: var(--text-color);
            border-color: var(--accent-color);
            box-shadow: 0 0 0 0.25rem rgba(255, 140, 0, 0.25);
        }
        
        .form-label {
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--text-color);
        }
        
        .filter-section {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }
        
        .stat-card .icon {
            font-size: 2.5rem;
            color: var(--accent-color);
            margin-bottom: 10px;
        }
        
        .stat-card .value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
            color: var(--text-color);
        }
        
        .stat-card .label {
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 992px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .badge {
            padding: 5px 10px;
            border-radius: 50px;
            font-weight: 500;
            font-size: 0.75rem;
        }
        
        .badge-success {
            background-color: var(--success-color);
            color: white;
        }
        
        .badge-warning {
            background-color: var(--warning-color);
            color: #212529;
        }
        
        .badge-danger {
            background-color: var(--danger-color);
            color: white;
        }
        
        .badge-info {
            background-color: var(--info-color);
            color: white;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .export-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            border-radius: var(--border-radius);
            font-weight: 500;
            transition: var(--transition);
            text-decoration: none;
            color: white;
        }
        
        .export-btn:hover {
            transform: translateY(-2px);
            color: white;
        }
        
        .export-btn-excel {
            background-color: #1D6F42;
        }
        
        .export-btn-pdf {
            background-color: #F40F02;
        }
        
        .export-btn-csv {
            background-color: #217346;
        }
        
        .export-btn-print {
            background-color: #5C6BC0;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
        
        .pagination .page-item .page-link {
            color: var(--accent-color);
            background-color: var(--card-bg);
            border-color: var(--border-color);
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
            color: white;
        }
        
        .pagination .page-item .page-link:hover {
            background-color: var(--accent-color);
            color: white;
        }
        
        .custom-tooltip {
            position: relative;
            display: inline-block;
        }
        
        .custom-tooltip .tooltip-text {
            visibility: hidden;
            width: 120px;
            background-color: var(--secondary-bg);
            color: var(--text-color);
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -60px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.8rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border-color);
        }
        
        .custom-tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
        
        .summary-section {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }
        
        .trend-indicator {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.85rem;
        }
        
        .trend-up {
            color: var(--success-color);
        }
        
        .trend-down {
            color: var(--danger-color);
        }
        
        .trend-neutral {
            color: var(--text-muted);
        }
        
        .custom-scrollbar::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-track {
            background: var(--secondary-bg);
            border-radius: 10px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: var(--accent-color);
            border-radius: 10px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: var(--accent-hover);
        }
        
        @media print {
            .sidebar, .navbar, .filter-section, .export-buttons, .pagination, .no-print {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            
            .card {
                break-inside: avoid;
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            body {
                background-color: white !important;
                color: black !important;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid">
            <a class="navbar-brand" href="#" style="color: var(--accent-color);">
                <i class="fas fa-tools me-2"></i>
                Equipment Manager
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <div class="d-flex align-items-center">
                            <span class="me-2" style="color: var(--text-color);">
                                <?php echo $theme === 'dark' ? 'Dark' : 'Light'; ?> Mode
                            </span>
                            <label class="theme-switch">
                                <input type="checkbox" id="theme-toggle" <?php echo $theme === 'dark' ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" style="color: var(--text-color);">
                            <i class="fas fa-user-circle me-1"></i>
                            <?php echo htmlspecialchars($userName); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" style="background-color: var(--secondary-bg);">
                            <li><a class="dropdown-item" href="../profile.php" style="color: var(--text-color);">Profile</a></li>
                            <li><a class="dropdown-item" href="../settings.php" style="color: var(--text-color);">Settings</a></li>
                            <li><hr class="dropdown-divider" style="border-color: var(--border-color);"></li>
                            <li><a class="dropdown-item" href="../logout.php" style="color: var(--danger-color);">Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar p-3">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="inventory.php">
                            <i class="fas fa-boxes"></i> Inventory
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="maintenance.php">
                            <i class="fas fa-wrench"></i> Maintenance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="report.php">
                            <i class="fas fa-chart-bar"></i> Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 main-content p-4">
                <h2 class="mb-4">Advanced Reports</h2>
                
                <?php if (!$equipmentTableExists || !$maintenanceTableExists): ?>
                <!-- Database Setup Required -->
                <div class="card setup-card">
                    <div class="card-body">
                        <i class="fas fa-database"></i>
                        <h3 class="mb-3">Database Setup Required</h3>
                        <p class="mb-4">The required database tables for the Equipment Manager do not exist. Please run the database setup script to create the necessary tables.</p>
                        <a href="setup_database.php" class="btn btn-primary">Run Database Setup</a>
                    </div>
                </div>
                <?php else: ?>
                
                <!-- Stats Overview -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="icon"><i class="fas fa-boxes"></i></div>
                        <div class="value"><?php echo $totalEquipment; ?></div>
                        <div class="label">Total Equipment</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="icon" style="color: var(--success-color);"><i class="fas fa-check-circle"></i></div>
                        <div class="value"><?php echo $availableEquipment; ?></div>
                        <div class="label">Available</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="icon" style="color: var(--info-color);"><i class="fas fa-users"></i></div>
                        <div class="value"><?php echo $inUseEquipment; ?></div>
                        <div class="label">In Use</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="icon" style="color: var(--warning-color);"><i class="fas fa-tools"></i></div>
                        <div class="value"><?php echo $maintenanceEquipment; ?></div>
                        <div class="label">Under Maintenance</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="icon" style="color: var(--danger-color);"><i class="fas fa-archive"></i></div>
                        <div class="value"><?php echo $retiredEquipment; ?></div>
                        <div class="label">Retired</div>
                    </div>
                </div>
                
                <!-- Report Navigation -->
                <div class="report-nav">
                    <a href="?type=inventory" class="nav-link <?php echo $reportType === 'inventory' ? 'active' : ''; ?>">
                        <i class="fas fa-boxes"></i> Inventory
                    </a>
                    <a href="?type=maintenance" class="nav-link <?php echo $reportType === 'maintenance' ? 'active' : ''; ?>">
                        <i class="fas fa-wrench"></i> Maintenance
                    </a>
                    <a href="?type=usage" class="nav-link <?php echo $reportType === 'usage' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i> Usage
                    </a>
                    <a href="?type=cost" class="nav-link <?php echo $reportType === 'cost' ? 'active' : ''; ?>">
                        <i class="fas fa-dollar-sign"></i> Cost Analysis
                    </a>
                    <a href="?type=performance" class="nav-link <?php echo $reportType === 'performance' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i> Performance
                    </a>
                </div>
                
                <!-- Filters -->
                <div class="filter-section">
                    <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Report Filters</h5>
                    <form action="report.php" method="GET" class="filter-form">
                        <input type="hidden" name="type" value="<?php echo $reportType; ?>">
                        
                        <?php if ($reportType != 'inventory'): ?>
                        <div class="form-group">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $startDate; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $endDate; ?>">
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="equipment_type" class="form-label">Equipment Type</label>
                            <select class="form-select" id="equipment_type" name="equipment_type">
                                <option value="">All Types</option>
                                <?php foreach ($equipmentTypes as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $equipmentType === $type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($reportType === 'inventory' || $reportType === 'maintenance'): ?>
                        <div class="form-group">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All Statuses</option>
                                <?php 
                                $statuses = $reportType === 'inventory' ? $equipmentStatuses : $maintenanceStatuses;
                                foreach ($statuses as $statusOption): 
                                ?>
                                <option value="<?php echo $statusOption; ?>" <?php echo $status === $statusOption ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($statusOption); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-2"></i> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Charts -->
                <div class="charts-grid">
                    <?php if ($reportType === 'inventory'): ?>
                    <div class="card">
                        <div class="card-header">
                            <span><i class="fas fa-chart-pie me-2"></i>Equipment by Type</span>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="equipmentByTypeChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <span><i class="fas fa-chart-bar me-2"></i>Equipment Status Distribution</span>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="statusDistributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($reportType === 'maintenance'): ?>
                    <div class="card">
                        <div class="card-header">
                            <span><i class="fas fa-chart-line me-2"></i>Maintenance Trends (6 Months)</span>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="maintenanceTrendChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <span><i class="fas fa-chart-pie me-2"></i>Maintenance Status Distribution</span>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="maintenanceStatusChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($reportType === 'usage'): ?>
                    <div class="card">
                        <div class="card-header">
                            <span><i class="fas fa-chart-bar me-2"></i>Equipment Usage by Type</span>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="usageByTypeChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <span><i class="fas fa-chart-line me-2"></i>Usage Trends</span>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="usageTrendChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($reportType === 'cost'): ?>
                    <div class="card">
                        <div class="card-header">
                            <span><i class="fas fa-chart-pie me-2"></i>Cost Distribution by Type</span>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="costDistributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <span><i class="fas fa-chart-line me-2"></i>Maintenance Cost Trends</span>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="costTrendChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($reportType === 'performance'): ?>
                    <div class="card">
                        <div class="card-header">
                            <span><i class="fas fa-chart-bar me-2"></i>Equipment Performance</span>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="performanceChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <span><i class="fas fa-chart-line me-2"></i>Maintenance vs Usage</span>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="maintenanceVsUsageChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Report Data -->
                <div class="card">
                    <div class="card-header">
                        <span>
                            <i class="fas fa-table me-2"></i>
                            <?php 
                            switch ($reportType) {
                                case 'inventory':
                                    echo 'Equipment Inventory Report';
                                    break;
                                case 'maintenance':
                                    echo 'Maintenance History Report';
                                    break;
                                case 'usage':
                                    echo 'Equipment Usage Report';
                                    break;
                                case 'cost':
                                    echo 'Cost Analysis Report';
                                    break;
                                case 'performance':
                                    echo 'Equipment Performance Report';
                                    break;
                            }
                            ?>
                        </span>
                        <div class="export-buttons">
                            <a href="?type=<?php echo $reportType; ?>&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>&equipment_type=<?php echo $equipmentType; ?>&status=<?php echo $status; ?>&export=excel" class="export-btn export-btn-excel">
                                <i class="fas fa-file-excel"></i> Excel
                            </a>
                            <a href="?type=<?php echo $reportType; ?>&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>&equipment_type=<?php echo $equipmentType; ?>&status=<?php echo $status; ?>&export=pdf" class="export-btn export-btn-pdf">
                                <i class="fas fa-file-pdf"></i> PDF
                            </a>
                            <a href="?type=<?php echo $reportType; ?>&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>&equipment_type=<?php echo $equipmentType; ?>&status=<?php echo $status; ?>&export=csv" class="export-btn export-btn-csv">
                                <i class="fas fa-file-csv"></i> CSV
                            </a>
                            <button onclick="window.print()" class="export-btn export-btn-print">
                                <i class="fas fa-print"></i> Print
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive custom-scrollbar">
                            <?php if ($reportType === 'inventory'): ?>
                            <table class="table table-hover table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Purchase Date</th>
                                        <th>Purchase Cost</th>
                                        <th>Last Maintenance</th>
                                        <th>Pending Maintenance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($reportData)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center">No equipment records found</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($reportData as $item): ?>
                                        <tr>
                                            <td><?php echo $item['id']; ?></td>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['type']); ?></td>
                                            <td>
                                                <?php 
                                                $statusClass = '';
                                                switch (strtolower($item['status'])) {
                                                    case 'available':
                                                        $statusClass = 'badge-success';
                                                        break;
                                                    case 'in_use':
                                                        $statusClass = 'badge-info';
                                                        break;
                                                    case 'maintenance':
                                                        $statusClass = 'badge-warning';
                                                        break;
                                                    case 'retired':
                                                        $statusClass = 'badge-danger';
                                                        break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $statusClass; ?>"><?php echo ucfirst($item['status']); ?></span>
                                            </td>
                                            <td><?php echo !empty($item['purchase_date']) ? date('M d, Y', strtotime($item['purchase_date'])) : 'N/A'; ?></td>
                                            <td>$<?php echo !empty($item['purchase_cost']) ? number_format($item['purchase_cost'], 2) : '0.00'; ?></td>
                                            <td><?php echo !empty($item['last_maintenance']) ? date('M d, Y', strtotime($item['last_maintenance'])) : 'Never'; ?></td>
                                            <td>
                                                <?php if (!empty($item['pending_maintenance']) && $item['pending_maintenance'] > 0): ?>
                                                <span class="badge badge-warning"><?php echo $item['pending_maintenance']; ?> pending</span>
                                                <?php else: ?>
                                                <span class="badge badge-success">None</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            
                            <?php elseif ($reportType === 'maintenance'): ?>
                            <table class="table table-hover table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Equipment</th>
                                        <th>Type</th>
                                        <th>Date</th>
                                        <th>Description</th>
                                        <th>Technician</th>
                                        <th>Status</th>
                                        <th>Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($reportData)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center">No maintenance records found</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($reportData as $item): ?>
                                        <tr>
                                            <td><?php echo $item['id']; ?></td>
                                            <td><?php echo htmlspecialchars($item['equipment_name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['equipment_type']); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($item['maintenance_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                                            <td><?php echo htmlspecialchars($item['technician'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php 
                                                $statusClass = '';
                                                switch ($item['status']) {
                                                    case 'pending':
                                                        $statusClass = 'badge-warning';
                                                        break;
                                                    case 'in_progress':
                                                        $statusClass = 'badge-info';
                                                        break;
                                                    case 'completed':
                                                        $statusClass = 'badge-success';
                                                        break;
                                                    case 'cancelled':
                                                        $statusClass = 'badge-danger';
                                                        break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $statusClass; ?>"><?php echo ucfirst($item['status']); ?></span>
                                            </td>
                                            <td>$<?php echo !empty($item['cost']) ? number_format($item['cost'], 2) : '0.00'; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            
                            <?php elseif ($reportType === 'usage'): ?>
                            <table class="table table-hover table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Equipment</th>
                                        <th>Type</th>
                                        <th>User</th>
                                        <th>Date</th>
                                        <th>Duration (min)</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($reportData)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center">No usage records found</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($reportData as $item): ?>
                                        <tr>
                                            <td><?php echo $item['id']; ?></td>
                                            <td><?php echo htmlspecialchars($item['equipment_name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['equipment_type']); ?></td>
                                            <td><?php echo htmlspecialchars($item['user_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($item['usage_date'])); ?></td>
                                            <td><?php echo $item['duration'] ?? 'N/A'; ?></td>
                                            <td><?php echo htmlspecialchars($item['notes'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            
                            <?php elseif ($reportType === 'cost'): ?>
                            <table class="table table-hover table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Equipment</th>
                                        <th>Type</th>
                                        <th>Purchase Date</th>
                                        <th>Purchase Cost</th>
                                        <th>Maintenance Cost</th>
                                        <th>Maintenance Count</th>
                                        <th>Total Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($reportData)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center">No cost data found</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($reportData as $item): ?>
                                        <tr>
                                            <td><?php echo $item['id']; ?></td>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['type']); ?></td>
                                            <td><?php echo !empty($item['purchase_date']) ? date('M d, Y', strtotime($item['purchase_date'])) : 'N/A'; ?></td>
                                            <td>$<?php echo !empty($item['purchase_cost']) ? number_format($item['purchase_cost'], 2) : '0.00'; ?></td>
                                            <td>$<?php echo !empty($item['maintenance_cost']) ? number_format($item['maintenance_cost'], 2) : '0.00'; ?></td>
                                            <td><?php echo $item['maintenance_count'] ?? 0; ?></td>
                                            <td>$<?php echo number_format(($item['purchase_cost'] ?? 0) + ($item['maintenance_cost'] ?? 0), 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            
                            <?php elseif ($reportType === 'performance'): ?>
                            <table class="table table-hover table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Equipment</th>
                                        <th>Type</th>
                                        <th>Usage Count</th>
                                        <th>Total Duration (min)</th>
                                        <th>Maintenance Count</th>
                                        <th>Performance Score</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($reportData)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center">No performance data found</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($reportData as $item): ?>
                                        <?php 
                                        // Calculate performance score (example: usage count / (maintenance count + 1))
                                        $usageCount = $item['usage_count'] ?? 0;
                                        $maintenanceCount = $item['maintenance_count'] ?? 0;
                                        $performanceScore = $maintenanceCount > 0 ? round($usageCount / ($maintenanceCount + 1), 2) : $usageCount;
                                        
                                        // Determine performance class
                                        $performanceClass = '';
                                        if ($performanceScore >= 5) {
                                            $performanceClass = 'badge-success';
                                        } elseif ($performanceScore >= 2) {
                                            $performanceClass = 'badge-info';
                                        } elseif ($performanceScore >= 1) {
                                            $performanceClass = 'badge-warning';
                                        } else {
                                            $performanceClass = 'badge-danger';
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo $item['id']; ?></td>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['type']); ?></td>
                                            <td><?php echo $usageCount; ?></td>
                                            <td><?php echo $item['total_duration'] ?? 0; ?></td>
                                            <td><?php echo $maintenanceCount; ?></td>
                                            <td><span class="badge <?php echo $performanceClass; ?>"><?php echo $performanceScore; ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Summary Section -->
                <?php if ($reportType === 'maintenance' && !empty($maintenanceCostSummary)): ?>
                <div class="summary-section">
                    <h5 class="mb-3"><i class="fas fa-calculator me-2"></i>Maintenance Cost Summary</h5>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="card">
                                <div class="card-body text-center">
                                    <h6 class="text-muted">Total Cost</h6>
                                    <h3 class="mb-0">$<?php echo number_format($maintenanceCostSummary['total_cost'] ?? 0, 2); ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card">
                                <div class="card-body text-center">
                                    <h6 class="text-muted">Average Cost</h6>
                                    <h3 class="mb-0">$<?php echo number_format($maintenanceCostSummary['avg_cost'] ?? 0, 2); ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card">
                                <div class="card-body text-center">
                                    <h6 class="text-muted">Minimum Cost</h6>
                                    <h3 class="mb-0">$<?php echo number_format($maintenanceCostSummary['min_cost'] ?? 0, 2); ?></h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card">
                                <div class="card-body text-center">
                                    <h6 class="text-muted">Maximum Cost</h6>
                                    <h3 class="mb-0">$<?php echo number_format($maintenanceCostSummary['max_cost'] ?? 0, 2); ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Top Equipment Section -->
                <?php if ($reportType === 'usage' && !empty($topEquipment)): ?>
                <div class="card">
                    <div class="card-header">
                        <span><i class="fas fa-trophy me-2"></i>Top Used Equipment</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Equipment</th>
                                        <th>Type</th>
                                        <th>Usage Count</th>
                                        <th>Total Duration (min)</th>
                                        <th>Utilization</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topEquipment as $equipment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($equipment['name']); ?></td>
                                        <td><?php echo htmlspecialchars($equipment['type']); ?></td>
                                        <td><?php echo $equipment['usage_count']; ?></td>
                                        <td><?php echo $equipment['total_duration'] ?? 0; ?></td>
                                        <td>
                                            <div class="progress" style="height: 10px;">
                                                <?php 
                                                $percentage = min(100, ($equipment['usage_count'] / 10) * 100);
                                                ?>
                                                <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $percentage; ?>%" aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Maintenance Trends Section -->
                <?php if ($reportType === 'maintenance' && !empty($maintenanceTrends)): ?>
                <div class="card">
                    <div class="card-header">
                        <span><i class="fas fa-chart-line me-2"></i>Maintenance Trends (Last 6 Months)</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Total</th>
                                        <th>Completed</th>
                                        <th>Pending</th>
                                        <th>In Progress</th>
                                        <th>Cancelled</th>
                                        <th>Avg. Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($maintenanceTrends as $trend): ?>
                                    <tr>
                                        <td><?php echo date('M Y', strtotime($trend['month'] . '-01')); ?></td>
                                        <td><?php echo $trend['total']; ?></td>
                                        <td>
                                            <span class="badge badge-success"><?php echo $trend['completed']; ?></span>
                                        </td>
                                        <td>
                                            <span class="badge badge-warning"><?php echo $trend['pending']; ?></span>
                                        </td>
                                        <td>
                                            <span class="badge badge-info"><?php echo $trend['in_progress']; ?></span>
                                        </td>
                                        <td>
                                            <span class="badge badge-danger"><?php echo $trend['cancelled']; ?></span>
                                        </td>
                                        <td>$<?php echo number_format($trend['avg_cost'] ?? 0, 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
    <script>
        $(document).ready(function() {
            // Theme toggle functionality
            $('#theme-toggle').change(function() {
                const theme = $(this).is(':checked') ? 'dark' : 'light';
                
                // Save theme preference via AJAX
                $.ajax({
                    url: 'save-theme.php',
                    type: 'POST',
                    data: { theme: theme },
                    success: function(response) {
                        if (response.success) {
                            // Reload page to apply new theme
                            location.reload();
                        } else {
                            console.error('Failed to save theme preference:', response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error:', error);
                    }
                });
            });
            
            // Mobile sidebar toggle
            $('.navbar-toggler').click(function() {
                $('.sidebar').toggleClass('collapsed');
                $('.main-content').toggleClass('expanded');
            });
            
            // Initialize charts based on report type
            const reportType = '<?php echo $reportType; ?>';
            const isDarkTheme = <?php echo $theme === 'dark' ? 'true' : 'false'; ?>;
            
            // Set Chart.js defaults based on theme
            Chart.defaults.color = isDarkTheme ? '#f5f5f5' : '#333333';
            Chart.defaults.borderColor = isDarkTheme ? '#444444' : '#dddddd';
            
            // Common chart colors
            const chartColors = [
                '#ff6600', '#2196f3', '#4caf50', '#ffc107', '#e91e63',
                '#9c27b0', '#00bcd4', '#ff9800', '#795548', '#607d8b'
            ];
            
            if (reportType === 'inventory') {
                // Equipment by Type Chart
                const typeCtx = document.getElementById('equipmentByTypeChart').getContext('2d');
                new Chart(typeCtx, {
                    type: 'pie',
                    data: {
                        labels: [
                            <?php 
                            if (!empty($chartData['equipmentByType'])) {
                                foreach ($chartData['equipmentByType'] as $item) {
                                    echo "'" . addslashes($item['type']) . "', ";
                                }
                            } else {
                                echo "'No Data'";
                            }
                            ?>
                        ],
                        datasets: [{
                            data: [
                                <?php 
                                if (!empty($chartData['equipmentByType'])) {
                                    foreach ($chartData['equipmentByType'] as $item) {
                                        echo $item['count'] . ", ";
                                    }
                                } else {
                                    echo "1";
                                }
                                ?>
                            ],
                            backgroundColor: chartColors,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
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
                
                // Status Distribution Chart
                const statusCtx = document.getElementById('statusDistributionChart').getContext('2d');
                new Chart(statusCtx, {
                    type: 'bar',
                    data: {
                        labels: ['Available', 'In Use', 'Maintenance', 'Retired'],
                        datasets: [{
                            label: 'Equipment Count',
                            data: [
                                <?php echo $availableEquipment; ?>,
                                <?php echo $inUseEquipment; ?>,
                                <?php echo $maintenanceEquipment; ?>,
                                <?php echo $retiredEquipment; ?>
                            ],
                            backgroundColor: [
                                '#4caf50', // Available
                                '#2196f3', // In Use
                                '#ffc107', // Maintenance
                                '#f44336'  // Retired
                            ],
                            borderWidth: 1
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
            } else if (reportType === 'maintenance') {
                // Maintenance Trend Chart
                const trendCtx = document.getElementById('maintenanceTrendChart').getContext('2d');
                new Chart(trendCtx, {
                    type: 'line',
                    data: {
                        labels: [
                            <?php 
                            if (!empty($chartData['maintenanceByMonth'])) {
                                foreach ($chartData['maintenanceByMonth'] as $item) {
                                    echo "'" . date('M Y', strtotime($item['month'] . '-01')) . "', ";
                                }
                            } else {
                                // Generate last 6 months if no data
                                for ($i = 5; $i >= 0; $i--) {
                                    echo "'" . date('M Y', strtotime("-$i months")) . "', ";
                                }
                            }
                            ?>
                        ],
                        datasets: [{
                            label: 'Maintenance Count',
                            data: [
                                <?php 
                                if (!empty($chartData['maintenanceByMonth'])) {
                                    foreach ($chartData['maintenanceByMonth'] as $item) {
                                        echo $item['count'] . ", ";
                                    }
                                } else {
                                    // Zero values if no data
                                    echo "0, 0, 0, 0, 0, 0";
                                }
                                ?>
                            ],
                            borderColor: '#ff6600',
                            backgroundColor: 'rgba(255, 102, 0, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true
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
                
                // Maintenance Status Chart
                const statusCtx = document.getElementById('maintenanceStatusChart').getContext('2d');
                new Chart(statusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Pending', 'In Progress', 'Completed', 'Cancelled'],
                        datasets: [{
                            data: [
                                <?php 
                                $pendingCount = 0;
                                $inProgressCount = 0;
                                $completedCount = 0;
                                $cancelledCount = 0;
                                
                                if (!empty($reportData)) {
                                    foreach ($reportData as $item) {
                                        if ($item['status'] === 'pending') $pendingCount++;
                                        else if ($item['status'] === 'in_progress') $inProgressCount++;
                                        else if ($item['status'] === 'completed') $completedCount++;
                                        else if ($item['status'] === 'cancelled') $cancelledCount++;
                                    }
                                }
                                
                                echo "$pendingCount, $inProgressCount, $completedCount, $cancelledCount";
                                
                                // If all zeros, add 1 to each for visualization
                                if ($pendingCount === 0 && $inProgressCount === 0 && $completedCount === 0 && $cancelledCount === 0) {
                                    echo ", 1, 1, 1, 1";
                                }
                                ?>
                            ],
                            backgroundColor: [
                                '#ffc107', // Pending
                                '#2196f3', // In Progress
                                '#4caf50', // Completed
                                '#f44336'  // Cancelled
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right'
                            }
                        }
                    }
                });
            }
            
            // Initialize other chart types as needed
        });
    </script>
</body>
</html>
