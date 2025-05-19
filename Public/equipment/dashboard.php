<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require EquipmentManager role to access this page
requireRole('EquipmentManager');

// Include theme helper
require_once 'dashboard-theme-helper.php';

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'] ?? 'Equipment Manager';

// Get theme preference
$theme = getThemePreference($userId);
$themeClasses = getThemeClasses($theme);

// Connect to database
$conn = connectDB();

// Get equipment statistics
$equipmentStmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'Available' THEN 1 END) as available_count,
        COUNT(CASE WHEN status = 'In Use' THEN 1 END) as in_use_count,
        COUNT(CASE WHEN status = 'Maintenance' THEN 1 END) as maintenance_count,
        COUNT(CASE WHEN status = 'Retired' THEN 1 END) as retired_count,
        COUNT(*) as total_equipment
    FROM equipment
");
$equipmentStmt->execute();
$equipmentStats = $equipmentStmt->fetch(PDO::FETCH_ASSOC);

// Check if maintenance_schedule table exists
$tableCheckStmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM information_schema.tables 
    WHERE table_schema = DATABASE() 
    AND table_name = 'maintenance_schedule'
");
$tableCheckStmt->execute();
$maintenanceTableExists = $tableCheckStmt->fetchColumn();

// Get maintenance statistics
if ($maintenanceTableExists) {
    $maintenanceStmt = $conn->prepare("
        SELECT 
            COUNT(CASE WHEN status = 'Scheduled' THEN 1 END) as scheduled_count,
            COUNT(CASE WHEN status = 'In Progress' THEN 1 END) as in_progress_count,
            COUNT(CASE WHEN status = 'Completed' AND scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as completed_count,
            COUNT(CASE WHEN status = 'Overdue' OR (status = 'Scheduled' AND scheduled_date < CURDATE()) THEN 1 END) as overdue_count,
            COUNT(*) as total_maintenance
        FROM maintenance_schedule
    ");
    $maintenanceStmt->execute();
    $maintenanceStats = $maintenanceStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get maintenance completion rate
    $completionRateStmt = $conn->prepare("
        SELECT 
            COUNT(CASE WHEN status = 'Completed' THEN 1 END) * 100 / COUNT(*) as completion_rate
        FROM maintenance_schedule
        WHERE scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $completionRateStmt->execute();
    $completionRate = $completionRateStmt->fetchColumn();
    $completionRate = $completionRate ? round($completionRate) : 0;
} else {
    // Create default stats if table doesn't exist
    $maintenanceStats = [
        'scheduled_count' => 0,
        'in_progress_count' => 0,
        'completed_count' => 0,
        'overdue_count' => 0,
        'total_maintenance' => 0
    ];
    $completionRate = 0;
}

// Check if activity_log table exists and has the required columns
$activityLogCheckStmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM information_schema.tables 
    WHERE table_schema = DATABASE() 
    AND table_name = 'activity_log'
");
$activityLogCheckStmt->execute();
$activityLogExists = $activityLogCheckStmt->fetchColumn();

// Get recent equipment activity
if ($activityLogExists) {
    // Check if the required columns exist
    $columnCheckStmt = $conn->prepare("
        SELECT 
            COUNT(*) as column_count
        FROM information_schema.columns 
        WHERE table_schema = DATABASE() 
        AND table_name = 'activity_log' 
        AND column_name IN ('equipment_id', 'action', 'user_id', 'timestamp')
    ");
    $columnCheckStmt->execute();
    $columnCount = $columnCheckStmt->fetchColumn();
    
    if ($columnCount >= 4) {
        // All required columns exist
        $activityStmt = $conn->prepare("
    SELECT a.id, a.action, a.timestamp, e.name as equipment_name, u.name as user_name, 
           e.type as equipment_type
    FROM activity_log a
    LEFT JOIN equipment e ON a.equipment_id = e.id
    LEFT JOIN users u ON a.user_id = u.id
    WHERE a.equipment_id IS NOT NULL
    ORDER BY a.timestamp DESC
    LIMIT 8
");
        $activityStmt->execute();
        $recentActivity = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Missing columns, use a simpler query
        $activityStmt = $conn->prepare("
    SELECT id, action, timestamp, NULL as equipment_name, NULL as user_name, 
           NULL as equipment_type
    FROM activity_log
    ORDER BY timestamp DESC
    LIMIT 8
");
        $activityStmt->execute();
        $recentActivity = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $recentActivity = [];
}

// Check if upcoming maintenance can be queried
$upcomingMaintenance = [];
if ($maintenanceTableExists) {
    try {
        $upcomingStmt = $conn->prepare("
            SELECT m.id, e.name as equipment_name, e.type as equipment_type, 
                   m.scheduled_date, m.description, m.status, m.priority,
                   u.name as assigned_to, m.estimated_duration
            FROM maintenance_schedule m
            JOIN equipment e ON m.equipment_id = e.id
            LEFT JOIN users u ON m.assigned_to = u.id
            WHERE (m.status = 'Scheduled' OR m.status = 'In Progress' OR 
                  (m.status = 'Overdue' OR (m.status = 'Scheduled' AND m.scheduled_date < CURDATE())))
            ORDER BY 
                CASE 
                    WHEN m.status = 'Overdue' OR (m.status = 'Scheduled' AND m.scheduled_date < CURDATE()) THEN 1
                    WHEN m.priority = 'High' THEN 2
                    WHEN m.priority = 'Medium' THEN 3
                    ELSE 4
                END,
                m.scheduled_date
            LIMIT 8
        ");
        $upcomingStmt->execute();
        $upcomingMaintenance = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If there's an error, just leave the array empty
        error_log("Error fetching upcoming maintenance: " . $e->getMessage());
    }
}

// Check if equipment_usage table exists
$usageTableCheckStmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM information_schema.tables 
    WHERE table_schema = DATABASE() 
    AND table_name = 'equipment_usage'
");
$usageTableCheckStmt->execute();
$usageTableExists = $usageTableCheckStmt->fetchColumn();

// Get equipment usage data for chart
$usageDates = [];
$usageCounts = [];
if ($usageTableExists) {
    try {
        // Get the last 14 days regardless of whether there's data
        $dateRangeStmt = $conn->prepare("
            SELECT 
                DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL n DAY), '%Y-%m-%d') as date
            FROM (
                SELECT 0 as n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION
                SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION
                SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13
            ) numbers
            ORDER BY date
        ");
        $dateRangeStmt->execute();
        $dateRange = $dateRangeStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get actual usage data
        $usageStmt = $conn->prepare("
            SELECT 
                DATE_FORMAT(usage_date, '%Y-%m-%d') as date,
                COUNT(*) as count
            FROM equipment_usage
            WHERE usage_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
            GROUP BY DATE_FORMAT(usage_date, '%Y-%m-%d')
        ");
        $usageStmt->execute();
        $usageData = $usageStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Combine the date range with actual data
        foreach ($dateRange as $date) {
            $usageDates[] = $date;
            $usageCounts[] = isset($usageData[$date]) ? $usageData[$date] : 0;
        }
        
        // If no data at all, provide default values
        if (empty($usageDates)) {
            for ($i = 13; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $usageDates[] = $date;
                $usageCounts[] = 0;
            }
        }
    } catch (PDOException $e) {
        // If there's an error, create default data for the last 14 days
        error_log("Error fetching usage data: " . $e->getMessage());
        for ($i = 13; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $usageDates[] = $date;
            $usageCounts[] = 0;
        }
    }
} else {
    // If table doesn't exist, create default data for the last 14 days
    for ($i = 13; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $usageDates[] = $date;
        $usageCounts[] = 0;
    }
}

// Get equipment type distribution for chart
$typeLabels = [];
$typeCounts = [];
try {
    // First check if there's any equipment data
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM equipment");
    $countStmt->execute();
    $equipmentCount = $countStmt->fetchColumn();
    
    if ($equipmentCount > 0) {
        $typeStmt = $conn->prepare("
            SELECT 
                COALESCE(type, 'Unknown') as type, 
                COUNT(*) as count
            FROM equipment
            GROUP BY type
            ORDER BY count DESC
        ");
        $typeStmt->execute();
        $typeData = $typeStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format type data for chart
        foreach ($typeData as $data) {
            $typeLabels[] = $data['type'];
            $typeCounts[] = $data['count'];
        }
    } else {
        // Add default data if no equipment exists
        $typeLabels = ['No Equipment'];
        $typeCounts = [0];
    }
} catch (PDOException $e) {
    // If there's an error, add default data
    error_log("Error fetching equipment type data: " . $e->getMessage());
    $typeLabels = ['Error Loading Data'];
    $typeCounts = [0];
}

// Get maintenance trend data for chart
$maintenanceTrendLabels = [];
$scheduledData = [];
$completedData = [];
$overdueData = [];

// Generate last 6 months regardless of data
for ($i = 5; $i >= 0; $i--) {
    $date = new DateTime();
    $date->modify("-$i months");
    $maintenanceTrendLabels[] = $date->format('M Y');
    $scheduledData[] = 0;
    $completedData[] = 0;
    $overdueData[] = 0;
}

if ($maintenanceTableExists) {
    try {
        $trendStmt = $conn->prepare("
            SELECT 
                DATE_FORMAT(scheduled_date, '%Y-%m') as month,
                COUNT(CASE WHEN status = 'Scheduled' OR status = 'In Progress' THEN 1 END) as scheduled,
                COUNT(CASE WHEN status = 'Completed' THEN 1 END) as completed,
                COUNT(CASE WHEN status = 'Overdue' OR (status = 'Scheduled' AND scheduled_date < CURDATE()) THEN 1 END) as overdue
            FROM maintenance_schedule
            WHERE scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(scheduled_date, '%Y-%m')
        ");
        $trendStmt->execute();
        $trendData = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format trend data for chart
        if (!empty($trendData)) {
            // Reset arrays since we have actual data
            $maintenanceTrendLabels = [];
            $scheduledData = [];
            $completedData = [];
            $overdueData = [];
            
            foreach ($trendData as $data) {
                // Convert YYYY-MM to Month YYYY format
                $date = DateTime::createFromFormat('Y-m', $data['month']);
                $maintenanceTrendLabels[] = $date->format('M Y');
                $scheduledData[] = (int)$data['scheduled'];
                $completedData[] = (int)$data['completed'];
                $overdueData[] = (int)$data['overdue'];
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching maintenance trend data: " . $e->getMessage());
    }
}

// Check if inventory table exists
$inventoryTableCheckStmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM information_schema.tables 
    WHERE table_schema = DATABASE() 
    AND table_name = 'inventory'
");
$inventoryTableCheckStmt->execute();
$inventoryTableExists = $inventoryTableCheckStmt->fetchColumn();

// Get inventory alerts (low stock)
$inventoryAlerts = [];
if ($inventoryTableExists) {
    try {
        // Check if the inventory table has the expected columns
        $inventoryColumnsStmt = $conn->prepare("
            SELECT 
                COUNT(*) as column_count
            FROM information_schema.columns 
            WHERE table_schema = DATABASE() 
            AND table_name = 'inventory' 
            AND column_name IN ('item_name', 'quantity', 'min_quantity')
        ");
        $inventoryColumnsStmt->execute();
        $inventoryColumnsCount = $inventoryColumnsStmt->fetchColumn();
        
        if ($inventoryColumnsCount >= 3) {
            $inventoryStmt = $conn->prepare("
                SELECT id, item_name as name, quantity, min_quantity, 
                       category, last_ordered, supplier
                FROM inventory
                WHERE quantity <= min_quantity
                ORDER BY (quantity / min_quantity)
                LIMIT 8
            ");
            $inventoryStmt->execute();
            $inventoryAlerts = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // If there's an error, just leave the array empty
        error_log("Error fetching inventory alerts: " . $e->getMessage());
    }
}

// Get equipment health status
$equipmentHealthData = [];
try {
    $healthStmt = $conn->prepare("
        SELECT 
            e.id, e.name, e.type, e.status,
            DATEDIFF(CURDATE(), e.purchase_date) as age_days,
            e.expected_lifetime_days,
            CASE 
                WHEN e.expected_lifetime_days > 0 THEN 
                    ROUND((DATEDIFF(CURDATE(), e.purchase_date) / e.expected_lifetime_days) * 100)
                ELSE 0
            END as lifecycle_percentage,
            (SELECT COUNT(*) FROM maintenance_schedule WHERE equipment_id = e.id AND status = 'Completed') as maintenance_count,
            (SELECT MAX(scheduled_date) FROM maintenance_schedule WHERE equipment_id = e.id AND status = 'Completed') as last_maintenance
        FROM equipment e
        WHERE e.status != 'Retired'
        ORDER BY lifecycle_percentage DESC
        LIMIT 5
    ");
    $healthStmt->execute();
    $equipmentHealthData = $healthStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching equipment health data: " . $e->getMessage());
}

// Get top used equipment
$topUsedEquipment = [];
if ($usageTableExists) {
    try {
        $topUsedStmt = $conn->prepare("
            SELECT 
                e.id, e.name, e.type, e.status,
                COUNT(u.id) as usage_count
            FROM equipment e
            JOIN equipment_usage u ON e.id = u.equipment_id
            WHERE u.usage_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY e.id
            ORDER BY usage_count DESC
            LIMIT 5
        ");
        $topUsedStmt->execute();
        $topUsedEquipment = $topUsedStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching top used equipment: " . $e->getMessage());
    }
}

// Get notifications
$notifications = [];
try {
    // Overdue maintenance
    if ($maintenanceTableExists) {
        $overdueStmt = $conn->prepare("
            SELECT 
                'maintenance_overdue' as type,
                CONCAT('Maintenance for ', e.name, ' is overdue') as message,
                m.scheduled_date as date,
                CONCAT('maintenance.php?action=view&id=', m.id) as link
            FROM maintenance_schedule m
            JOIN equipment e ON m.equipment_id = e.id
            WHERE m.status = 'Overdue' OR (m.status = 'Scheduled' AND m.scheduled_date < CURDATE())
            ORDER BY m.scheduled_date
            LIMIT 5
        ");
        $overdueStmt->execute();
        $overdueNotifications = $overdueStmt->fetchAll(PDO::FETCH_ASSOC);
        $notifications = array_merge($notifications, $overdueNotifications);
    }
    
    // Low inventory
    if ($inventoryTableExists) {
        $lowInventoryStmt = $conn->prepare("
            SELECT 
                'inventory_low' as type,
                CONCAT(item_name, ' is low in stock (', quantity, ' remaining)') as message,
                NOW() as date,
                CONCAT('inventory.php?action=view&id=', id) as link
            FROM inventory
            WHERE quantity <= min_quantity
            ORDER BY (quantity / min_quantity)
            LIMIT 5
        ");
        $lowInventoryStmt->execute();
        $inventoryNotifications = $lowInventoryStmt->fetchAll(PDO::FETCH_ASSOC);
        $notifications = array_merge($notifications, $inventoryNotifications);
    }
    
    // Equipment nearing end of life
    $eolStmt = $conn->prepare("
        SELECT 
            'equipment_eol' as type,
            CONCAT(name, ' is nearing end of life (', 
                ROUND((DATEDIFF(CURDATE(), purchase_date) / expected_lifetime_days) * 100), 
                '% of lifecycle)') as message,
            purchase_date as date,
            CONCAT('equipment.php?action=view&id=', id) as link
        FROM equipment
        WHERE 
            expected_lifetime_days > 0 AND
            (DATEDIFF(CURDATE(), purchase_date) / expected_lifetime_days) > 0.8 AND
            (DATEDIFF(CURDATE(), purchase_date) / expected_lifetime_days) < 1 AND
            status != 'Retired'
        ORDER BY (DATEDIFF(CURDATE(), purchase_date) / expected_lifetime_days) DESC
        LIMIT 5
    ");
    $eolStmt->execute();
    $eolNotifications = $eolStmt->fetchAll(PDO::FETCH_ASSOC);
    $notifications = array_merge($notifications, $eolNotifications);
    
    // Sort notifications by date
    usort($notifications, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    // Limit to 10 most recent
    $notifications = array_slice($notifications, 0, 10);
    
} catch (PDOException $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
}

// Get quick stats
$quickStats = [
    'maintenance_completion_rate' => $completionRate,
    'equipment_utilization' => 0,
    'inventory_health' => 0
];

// Calculate equipment utilization
if ($usageTableExists) {
    try {
        $utilizationStmt = $conn->prepare("
            SELECT 
                COUNT(DISTINCT u.equipment_id) * 100 / NULLIF(COUNT(DISTINCT e.id), 0) as utilization_rate
            FROM equipment e
            LEFT JOIN equipment_usage u ON e.id = u.equipment_id AND u.usage_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            WHERE e.status != 'Retired' AND e.status != 'Maintenance'
        ");
        $utilizationStmt->execute();
        $utilizationRate = $utilizationStmt->fetchColumn();
        $quickStats['equipment_utilization'] = $utilizationRate !== null ? round($utilizationRate) : 0;
    } catch (PDOException $e) {
        error_log("Error calculating equipment utilization: " . $e->getMessage());
    }
}

// Calculate inventory health
if ($inventoryTableExists) {
    try {
        $inventoryHealthStmt = $conn->prepare("
            SELECT 
                COUNT(CASE WHEN quantity > min_quantity THEN 1 END) * 100 / NULLIF(COUNT(*), 0) as health_rate
            FROM inventory
        ");
        $inventoryHealthStmt->execute();
        $healthRate = $inventoryHealthStmt->fetchColumn();
        $quickStats['inventory_health'] = $healthRate !== null ? round($healthRate) : 0;
    } catch (PDOException $e) {
        error_log("Error calculating inventory health: " . $e->getMessage());
    }
}

// Get weather data for maintenance planning (mock data for now)
$weatherData = [
    'today' => ['temp' => 72, 'condition' => 'Sunny', 'icon' => 'sun'],
    'tomorrow' => ['temp' => 68, 'condition' => 'Partly Cloudy', 'icon' => 'cloud-sun'],
    'day_after' => ['temp' => 65, 'condition' => 'Rain', 'icon' => 'cloud-rain']
];

// Function to get appropriate icon for notification type
function getNotificationIcon($type) {
    switch ($type) {
        case 'maintenance_overdue':
            return 'fas fa-exclamation-triangle text-danger';
        case 'inventory_low':
            return 'fas fa-box-open text-warning';
        case 'equipment_eol':
            return 'fas fa-hourglass-end text-info';
        default:
            return 'fas fa-bell text-primary';
    }
}

// Function to get appropriate badge class for maintenance status
function getMaintenanceStatusBadgeClass($status, $scheduled_date = null) {
    if ($status == 'Scheduled' && $scheduled_date && strtotime($scheduled_date) < strtotime('today')) {
        return 'badge-danger'; // Overdue
    }
    
    switch ($status) {
        case 'Completed':
            return 'badge-success';
        case 'In Progress':
            return 'badge-info';
        case 'Scheduled':
            return 'badge-primary';
        case 'Overdue':
            return 'badge-danger';
        default:
            return 'badge-secondary';
    }
}

// Function to get appropriate badge class for priority
function getPriorityBadgeClass($priority) {
    switch ($priority) {
        case 'High':
            return 'badge-danger';
        case 'Medium':
            return 'badge-warning';
        case 'Low':
            return 'badge-info';
        default:
            return 'badge-secondary';
    }
}

// Function to format relative time
function getRelativeTime($timestamp) {
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipment Manager Dashboard - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #ff6600;
            --primary-dark: #e65c00;
            --primary-light: #ff8533;
            --primary-very-light: #fff0e6;
            --secondary: #1a1a1a;
            --secondary-dark: #000000;
            --secondary-light: #333333;
            --light: #f8f9fa;
            --dark: #121212;
            --darker: #0a0a0a;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --border-radius: 8px;
            --card-border-radius: 12px;
            --box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --transition-speed: 0.3s;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: var(--secondary);
            min-height: 100vh;
            transition: background-color var(--transition-speed), color var(--transition-speed);
        }
        
        body.dark-theme {
            background-color: var(--dark);
            color: #f5f5f5;
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 260px;
            background-color: var(--secondary);
            color: white;
            padding: 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: all var(--transition-speed);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }
        
        .dark-theme .sidebar {
            background-color: var(--secondary-dark);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
        }
        
        .sidebar-header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-header h2 {
            font-size: 1.5rem;
            margin-left: 10px;
            color: var(--primary);
            font-weight: 600;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 5px;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: all var(--transition-speed);
            font-weight: 500;
        }
        
        .sidebar-menu a:hover {
            background-color: rgba(255, 102, 0, 0.2);
            color: white;
        }
        
        .sidebar-menu a.active {
            background-color: var(--primary);
            color: white;
            box-shadow: 0 4px 8px rgba(255, 102, 0, 0.3);
        }
        
        .sidebar-menu a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }
        
        .sidebar-footer {
            position: absolute;
            bottom: 20px;
            width: calc(100% - 40px);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 20px;
        }
        
        .sidebar-footer a {
            display: flex;
            align-items: center;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            padding: 10px;
            border-radius: var(--border-radius);
            transition: all var(--transition-speed);
        }
        
        .sidebar-footer a:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .sidebar-footer a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 20px;
            transition: all var(--transition-speed);
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background-color: white;
            padding: 15px 20px;
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            position: sticky;
            top: 0;
            z-index: 900;
        }
        
        .dark-theme .header {
            background-color: var(--secondary-light);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .header h1 {
            font-size: 1.8rem;
            color: var(--secondary);
            margin: 0;
            font-weight: 600;
        }
        
        .dark-theme .header h1 {
            color: #f5f5f5;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .search-bar {
            position: relative;
            margin-right: 15px;
        }
        
        .search-bar input {
            padding: 8px 15px 8px 35px;
            border-radius: 50px;
            border: 1px solid #e0e0e0;
            background-color: #f5f5f5;
            width: 200px;
            transition: all var(--transition-speed);
        }
        
        .dark-theme .search-bar input {
            background-color: var(--secondary-dark);
            border-color: #444;
            color: white;
        }
        
        .search-bar input:focus {
            width: 250px;
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(255, 102, 0, 0.2);
        }
        
        .search-bar i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
        }
        
        .dark-theme .search-bar i {
            color: #aaa;
        }
        
        .notification-bell {
            position: relative;
            cursor: pointer;
            font-size: 1.2rem;
            color: #555;
            transition: all var(--transition-speed);
        }
        
        .dark-theme .notification-bell {
            color: #ddd;
        }
        
        .notification-bell:hover {
            color: var(--primary);
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 320px;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            padding: 0;
            z-index: 1000;
            display: none;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .dark-theme .notification-dropdown {
            background-color: var(--secondary-light);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }
        
        .notification-dropdown.show {
            display: block;
        }
        
        .notification-header {
            padding: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .dark-theme .notification-header {
            border-color: #444;
        }
        
        .notification-header h5 {
            margin: 0;
            font-size: 1rem;
        }
        
        .notification-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .notification-item {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            transition: background-color var(--transition-speed);
        }
        
        .dark-theme .notification-item {
            border-color: #444;
        }
        
        .notification-item:hover {
            background-color: #f9f9f9;
        }
        
        .dark-theme .notification-item:hover {
            background-color: var(--secondary-dark);
        }
        
        .notification-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: rgba(255, 102, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            flex-shrink: 0;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-message {
            margin: 0 0 5px;
            font-size: 0.9rem;
        }
        
        .notification-time {
            font-size: 0.75rem;
            color: #888;
        }
        
        .dark-theme .notification-time {
            color: #aaa;
        }
        
        .notification-footer {
            padding: 10px;
            text-align: center;
            border-top: 1px solid #eee;
        }
        
        .dark-theme .notification-footer {
            border-color: #444;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            position: relative;
        }
        
        .user-info img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
            object-fit: cover;
            border: 2px solid var(--primary);
        }
        
        .user-info .dropdown {
            position: relative;
        }
        
        .user-info .dropdown-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
            padding: 5px 10px;
            border-radius: var(--border-radius);
            transition: background-color var(--transition-speed);
        }
        
        .user-info .dropdown-toggle:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-theme .user-info .dropdown-toggle:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .user-info .dropdown-menu {
            position: absolute;
            right: 0;
            top: 100%;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            padding: 10px 0;
            min-width: 200px;
            z-index: 1000;
            display: none;
        }
        
        .dark-theme .user-info .dropdown-menu {
            background-color: var(--secondary-light);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }
        
        .user-info .dropdown-menu.show {
            display: block;
        }
        
        .user-info .dropdown-menu a {
            display: flex;
            align-items: center;
            padding: 8px 20px;
            color: var(--secondary);
            text-decoration: none;
            transition: all var(--transition-speed);
        }
        
        .dark-theme .user-info .dropdown-menu a {
            color: #f5f5f5;
        }
        
        .user-info .dropdown-menu a:hover {
            background-color: #f8f9fa;
        }
        
        .dark-theme .user-info .dropdown-menu a:hover {
            background-color: var(--secondary-dark);
        }
        
        .user-info .dropdown-menu a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .user-info .dropdown-divider {
            height: 1px;
            background-color: #eee;
            margin: 8px 0;
        }
        
        .dark-theme .user-info .dropdown-divider {
            background-color: #444;
        }
        
        .dashboard-section {
            margin-bottom: 30px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .section-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--secondary);
            margin: 0;
        }
        
        .dark-theme .section-header h2 {
            color: #f5f5f5;
        }
        
        .section-actions {
            display: flex;
            gap: 10px;
        }
        
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .card {
            background-color: white;
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            padding: 20px;
            transition: transform var(--transition-speed), box-shadow var(--transition-speed);
            border: none;
            height: 100%;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }
        
        .dark-theme .card {
            background-color: var(--secondary-light);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        
        .dark-theme .card:hover {
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.3);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding: 0;
            background: none;
            border: none;
        }
        
        .card-header h3 {
            font-size: 1.2rem;
            color: var(--secondary);
            margin: 0;
            font-weight: 600;
        }
        
        .dark-theme .card-header h3 {
            color: #f5f5f5;
        }
        
        .card-body {
            color: var(--secondary);
            padding: 0;
        }
        
        .dark-theme .card-body {
            color: #f5f5f5;
        }
        
        .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .card-icon.available {
            background-color: var(--success);
        }
        
        .card-icon.in-use {
            background-color: var(--info);
        }
        
        .card-icon.maintenance {
            background-color: var(--warning);
        }
        
        .card-icon.retired {
            background-color: var(--secondary);
        }
        
        .card-icon.total {
            background-color: var(--primary);
        }
        
        .card-icon.scheduled {
            background-color: var(--info);
        }
        
        .card-icon.in-progress {
            background-color: var(--warning);
        }
        
        .card-icon.completed {
            background-color: var(--success);
        }
        
        .card-icon.overdue {
            background-color: var(--danger);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin: 10px 0 5px;
            color: var(--primary);
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #777;
            margin: 0;
        }
        
        .dark-theme .stat-label {
            color: #aaa;
        }
        
        .stat-change {
            display: flex;
            align-items: center;
            font-size: 0.8rem;
            margin-top: 5px;
        }
        
        .stat-change.positive {
            color: var(--success);
        }
        
        .stat-change.negative {
            color: var(--danger);
        }
        
        .quick-stat-card {
            display: flex;
            align-items: center;
            padding: 15px;
            border-radius: var(--card-border-radius);
            background-color: white;
            box-shadow: var(--box-shadow);
            transition: transform var(--transition-speed);
        }
        
        .quick-stat-card:hover {
            transform: translateY(-3px);
        }
        
        .dark-theme .quick-stat-card {
            background-color: var(--secondary-light);
        }
        
        .quick-stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.5rem;
            color: white;
        }
        
        .quick-stat-content {
            flex: 1;
        }
        
        .quick-stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.2;
        }
        
        .quick-stat-label {
            font-size: 0.9rem;
            color: #777;
            margin: 0;
        }
        
        .dark-theme .quick-stat-label {
            color: #aaa;
        }
        
        .chart-container {
            background-color: white;
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            padding: 20px;
            margin-bottom: 30px;
            height: 100%;
        }
        
        .dark-theme .chart-container {
            background-color: var(--secondary-light);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .chart-header h3 {
            font-size: 1.2rem;
            color: var(--secondary);
            margin: 0;
            font-weight: 600;
        }
        
        .dark-theme .chart-header h3 {
            color: #f5f5f5;
        }
        
        .chart-actions {
            display: flex;
            gap: 10px;
        }
        
        .chart-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 10px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            font-size: 0.85rem;
        }
        
        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            margin-right: 5px;
        }
        
        .activity-list, .maintenance-list, .inventory-alerts {
            background-color: white;
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            margin-bottom: 30px;
            height: 100%;
        }
        
        .dark-theme .activity-list, 
        .dark-theme .maintenance-list, 
        .dark-theme .inventory-alerts {
            background-color: var(--secondary-light);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        
        .activity-list-header, 
        .maintenance-list-header, 
        .inventory-alerts-header {
            padding: 15px 20px;
            background-color: var(--secondary);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .activity-list-header h3, 
        .maintenance-list-header h3, 
        .inventory-alerts-header h3 {
            font-size: 1.2rem;
            margin: 0;
            font-weight: 600;
        }
        
        .activity-list-body, 
        .maintenance-list-body, 
        .inventory-alerts-body {
            padding: 0;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }
        
        .table th, .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }
        
        .dark-theme .table th, 
        .dark-theme .table td {
            border-bottom: 1px solid #444;
        }
        
        .table th {
            font-weight: 600;
            color: var(--secondary);
            background-color: #f8f9fa;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .dark-theme .table th {
            color: #f5f5f5;
            background-color: var(--secondary-dark);
        }
        
        .table tbody tr {
            transition: background-color var(--transition-speed);
        }
        
        .table tbody tr:hover {
            background-color: rgba(255, 102, 0, 0.05);
        }
        
        .dark-theme .table tbody tr:hover {
            background-color: rgba(255, 102, 0, 0.1);
        }
        
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 500;
            color: white;
        }
        
        .badge-success {
            background-color: var(--success);
        }
        
        .badge-warning {
            background-color: var(--warning);
        }
        
        .badge-info {
            background-color: var(--info);
        }
        
        .badge-danger {
            background-color: var(--danger);
        }
        
        .badge-primary {
            background-color: var(--primary);
        }
        
        .badge-secondary {
            background-color: var(--secondary);
        }
        
        .btn {
            padding: 8px 15px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all var(--transition-speed);
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 102, 0, 0.3);
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 0.9rem;
        }
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-outline:hover {
            background-color: var(--primary);
            color: white;
        }
        
        .dark-theme .btn-outline {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .dark-theme .btn-outline:hover {
            background-color: var(--primary);
            color: white;
        }
        
        .btn-secondary {
            background-color: var(--secondary);
        }
        
        .btn-secondary:hover {
            background-color: var(--secondary-light);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }
        
        .btn-success {
            background-color: var(--success);
        }
        
        .btn-success:hover {
            background-color: #218838;
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }
        
        .btn-danger {
            background-color: var(--danger);
        }
        
        .btn-danger:hover {
            background-color: #c82333;
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .theme-switch {
            display: flex;
            align-items: center;
            margin-right: 20px;
        }
        
        .theme-switch label {
            margin: 0 10px 0 0;
            cursor: pointer;
            color: #888;
        }
        
        .dark-theme .theme-switch label {
            color: #ddd;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .switch input {
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
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--primary);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        .progress-bar {
            height: 8px;
            border-radius: 4px;
            background-color: #e9ecef;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .dark-theme .progress-bar {
            background-color: #444;
        }
        
        .progress-bar-fill {
            height: 100%;
            border-radius: 4px;
            background-color: var(--danger);
            transition: width 0.5s ease;
        }
        
        .progress-bar-fill.low {
            background-color: var(--danger);
        }
        
        .progress-bar-fill.medium {
            background-color: var(--warning);
        }
        
        .progress-bar-fill.high {
            background-color: var(--success);
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: #777;
            margin-top: 5px;
        }
        
        .dark-theme .progress-label {
            color: #aaa;
        }
        
        .weather-widget {
            display: flex;
            justify-content: space-between;
            background-color: white;
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .dark-theme .weather-widget {
            background-color: var(--secondary-light);
        }
        
        .weather-day {
            text-align: center;
            padding: 10px;
        }
        
        .weather-icon {
            font-size: 2rem;
            margin: 10px 0;
            color: var(--primary);
        }
        
        .weather-temp {
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .weather-condition {
            font-size: 0.9rem;
            color: #777;
        }
        
        .dark-theme .weather-condition {
            color: #aaa;
        }
        
        .equipment-health-card {
            display: flex;
            align-items: center;
            padding: 15px;
            border-radius: var(--card-border-radius);
            background-color: white;
            box-shadow: var(--box-shadow);
            margin-bottom: 15px;
            transition: transform var(--transition-speed);
        }
        
        .equipment-health-card:hover {
            transform: translateY(-3px);
        }
        
        .dark-theme .equipment-health-card {
            background-color: var(--secondary-light);
        }
        
        .equipment-health-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.2rem;
            color: white;
            flex-shrink: 0;
        }
        
        .equipment-health-content {
            flex: 1;
        }
        
        .equipment-health-name {
            font-weight: 600;
            margin: 0 0 5px;
        }
        
        .equipment-health-type {
            font-size: 0.85rem;
            color: #777;
            margin: 0 0 8px;
        }
        
        .dark-theme .equipment-health-type {
            color: #aaa;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .action-btn {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            background-color: var(--primary);
            border: none;
            cursor: pointer;
            transition: all var(--transition-speed);
            font-size: 0.8rem;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }
        
        .action-btn.view {
            background-color: var(--info);
        }
        
        .action-btn.edit {
            background-color: var(--warning);
        }
        
        .action-btn.complete {
            background-color: var(--success);
        }
        
        .action-btn.delete {
            background-color: var(--danger);
        }
        
        .activity-item {
            display: flex;
            padding: 15px;
            border-bottom: 1px solid #eee;
            transition: background-color var(--transition-speed);
        }
        
        .dark-theme .activity-item {
            border-color: #444;
        }
        
        .activity-item:hover {
            background-color: rgba(255, 102, 0, 0.05);
        }
        
        .dark-theme .activity-item:hover {
            background-color: rgba(255, 102, 0, 0.1);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
            color: white;
            font-size: 1rem;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 500;
            margin: 0 0 5px;
        }
        
        .activity-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: #777;
        }
        
        .dark-theme .activity-meta {
            color: #aaa;
        }
        
        .activity-time {
            font-style: italic;
        }
        
        .calendar-widget {
            background-color: white;
            border-radius: var(--card-border-radius);
            box-shadow: var(--box-shadow);
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .dark-theme .calendar-widget {
            background-color: var(--secondary-light);
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .calendar-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }
        
        .calendar-nav {
            display: flex;
            gap: 10px;
        }
        
        .calendar-nav-btn {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #f5f5f5;
            border: none;
            cursor: pointer;
            transition: all var(--transition-speed);
        }
        
        .dark-theme .calendar-nav-btn {
            background-color: var(--secondary-dark);
            color: #ddd;
        }
        
        .calendar-nav-btn:hover {
            background-color: var(--primary-light);
            color: white;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }
        
        .calendar-day-header {
            text-align: center;
            font-weight: 600;
            padding: 5px;
            font-size: 0.9rem;
        }
        
        .calendar-day {
            aspect-ratio: 1;
            border-radius: 8px;
            padding: 5px;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: all var(--transition-speed);
            cursor: pointer;
            position: relative;
        }
        
        .calendar-day:hover {
            background-color: var(--primary-very-light);
        }
        
        .dark-theme .calendar-day:hover {
            background-color: rgba(255, 102, 0, 0.1);
        }
        
        .calendar-day.today {
            background-color: var(--primary-very-light);
            border: 1px solid var(--primary);
        }
        
        .dark-theme .calendar-day.today {
            background-color: rgba(255, 102, 0, 0.2);
        }
        
        .calendar-day.has-events::after {
            content: '';
            position: absolute;
            bottom: 5px;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: var(--primary);
        }
        
        .calendar-day-number {
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .calendar-day.other-month .calendar-day-number {
            color: #aaa;
        }
        
        .dark-theme .calendar-day.other-month .calendar-day-number {
            color: #666;
        }
        
        .calendar-events {
            margin-top: 15px;
        }
        
        .calendar-event {
            padding: 8px 12px;
            border-radius: 6px;
            background-color: var(--primary-very-light);
            border-left: 3px solid var(--primary);
            margin-bottom: 8px;
            font-size: 0.85rem;
        }
        
        .dark-theme .calendar-event {
            background-color: rgba(255, 102, 0, 0.1);
        }
        
        .calendar-event-time {
            font-weight: 600;
            margin-bottom: 3px;
        }
        
        .calendar-event-title {
            color: #555;
        }
        
        .dark-theme .calendar-event-title {
            color: #ddd;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 20px;
        }
        
        .grid-col-4 {
            grid-column: span 4;
        }
        
        .grid-col-6 {
            grid-column: span 6;
        }
        
        .grid-col-8 {
            grid-column: span 8;
        }
        
        .grid-col-12 {
            grid-column: span 12;
        }
        
        .circular-progress {
            position: relative;
            width: 80px;
            height: 80px;
            margin: 0 auto;
        }
        
        .circular-progress svg {
            transform: rotate(-90deg);
        }
        
        .circular-progress circle {
            fill: none;
            stroke-width: 8;
            stroke-linecap: round;
        }
        
        .circular-progress .bg {
            stroke: #eee;
        }
        
        .dark-theme .circular-progress .bg {
            stroke: #444;
        }
        
        .circular-progress .progress {
            stroke: var(--primary);
            transition: stroke-dashoffset 0.5s ease;
        }
        
        .circular-progress .text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: repeat(6, 1fr);
            }
            
            .grid-col-4, .grid-col-6 {
                grid-column: span 3;
            }
            
            .grid-col-8, .grid-col-12 {
                grid-column: span 6;
            }
        }
        
        @media (max-width: 992px) {
            .dashboard-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .grid-col-4 {
                grid-column: span 2;
            }
            
            .grid-col-6, .grid-col-8, .grid-col-12 {
                grid-column: span 4;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                padding: 20px 10px;
                transform: translateX(0);
            }
            
            .sidebar.expanded {
                width: 260px;
            }
            
            .sidebar-header h2, .sidebar-menu a span {
                display: none;
            }
            
            .sidebar.expanded .sidebar-header h2, 
            .sidebar.expanded .sidebar-menu a span {
                display: block;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .dashboard-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .grid-col-4, .grid-col-6, .grid-col-8, .grid-col-12 {
                grid-column: span 2;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .header-actions {
                margin-top: 15px;
                width: 100%;
                justify-content: space-between;
            }
            
            .search-bar {
                width: 100%;
                margin-right: 0;
            }
            
            .search-bar input {
                width: 100%;
            }
            
            .user-info {
                margin-top: 10px;
                align-self: flex-end;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .grid-col-4, .grid-col-6, .grid-col-8, .grid-col-12 {
                grid-column: span 1;
            }
            
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
            
            .weather-widget {
                flex-direction: column;
            }
            
            .weather-day {
                margin-bottom: 15px;
            }
        }
        
        /* Animations */
        @keyframes pulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
            100% {
                transform: scale(1);
            }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .dark-theme ::-webkit-scrollbar-track {
            background: #333;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        .dark-theme ::-webkit-scrollbar-thumb {
            background: #666;
        }
        
        .dark-theme ::-webkit-scrollbar-thumb:hover {
            background: #888;
        }
    </style>
</head>
<body class="<?php echo $theme === 'dark' ? 'dark-theme' : ''; ?>">
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-dumbbell fa-2x" style="color: var(--primary);"></i>
                <h2>EliteFit Gym</h2>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
                <li><a href="equipment.php"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
                <li><a href="maintenance.php"><i class="fas fa-tools"></i> <span>Maintenance</span></a></li>
                <li><a href="inventory.php"><i class="fas fa-boxes"></i> <span>Inventory</span></a></li>
                <li class="nav-item">
    <a class="nav-link" href="report.php">
        <i class="fas fa-chart-bar"></i> <span>Reports</span>
    </a>
</li>
                <li><a href="calendar.php"><i class="fas fa-calendar-alt"></i> <span>Calendar</span></a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
            </ul>
            <div class="sidebar-footer">
                <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div class="d-flex align-items-center">
                    <button id="sidebar-toggle" class="btn-icon btn-secondary me-3 d-md-none">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1>Equipment Manager Dashboard</h1>
                </div>
                <div class="header-actions">
                    <div class="search-bar">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search..." id="global-search">
                    </div>
                    <div class="notification-bell" id="notification-bell">
                        <i class="fas fa-bell"></i>
                        <?php if (count($notifications) > 0): ?>
                        <span class="notification-badge"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                        <div class="notification-dropdown" id="notification-dropdown">
                            <div class="notification-header">
                                <h5>Notifications</h5>
                                <a href="#" class="text-primary">Mark all as read</a>
                            </div>
                            <ul class="notification-list">
                                <?php if (empty($notifications)): ?>
                                <li class="notification-item">
                                    <div class="notification-content">
                                        <p class="notification-message">No new notifications</p>
                                    </div>
                                </li>
                                <?php else: ?>
                                    <?php foreach ($notifications as $notification): ?>
                                    <li class="notification-item">
                                        <div class="notification-icon">
                                            <i class="<?php echo getNotificationIcon($notification['type']); ?>"></i>
                                        </div>
                                        <div class="notification-content">
                                            <p class="notification-message"><?php echo htmlspecialchars($notification['message']); ?></p>
                                            <span class="notification-time"><?php echo getRelativeTime($notification['date']); ?></span>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                            <div class="notification-footer">
                                <a href="notifications.php" class="text-primary">View all notifications</a>
                            </div>
                        </div>
                    </div>
                    <div class="theme-switch">
                        <label for="theme-toggle">
                            <i class="fas fa-moon" style="color: <?php echo $theme === 'dark' ? 'var(--primary)' : '#aaa'; ?>"></i>
                        </label>
                        <label class="switch">
                            <input type="checkbox" id="theme-toggle" <?php echo $theme === 'dark' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="user-info">
                        <img src="https://randomuser.me/api/portraits/men/3.jpg" alt="User Avatar">
                        <div class="dropdown">
                            <div class="dropdown-toggle" id="user-dropdown-toggle">
                                <span><?php echo htmlspecialchars($userName); ?></span>
                                <i class="fas fa-chevron-down ml-2"></i>
                            </div>
                            <div class="dropdown-menu" id="user-dropdown">
                                <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                                <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
                                <div class="dropdown-divider"></div>
                                <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="dashboard-section">
                <div class="row">
                    <div class="col-md-4 mb-4">
                        <div class="quick-stat-card">
                            <div class="quick-stat-icon" style="background-color: var(--success);">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="quick-stat-content">
                                <h2 class="quick-stat-value"><?php echo $quickStats['maintenance_completion_rate']; ?>%</h2>
                                <p class="quick-stat-label">Maintenance Completion Rate</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="quick-stat-card">
                            <div class="quick-stat-icon" style="background-color: var(--primary);">
                                <i class="fas fa-dumbbell"></i>
                            </div>
                            <div class="quick-stat-content">
                                <h2 class="quick-stat-value"><?php echo $quickStats['equipment_utilization']; ?>%</h2>
                                <p class="quick-stat-label">Equipment Utilization</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="quick-stat-card">
                            <div class="quick-stat-icon" style="background-color: var(--info);">
                                <i class="fas fa-boxes"></i>
                            </div>
                            <div class="quick-stat-content">
                                <h2 class="quick-stat-value"><?php echo $quickStats['inventory_health']; ?>%</h2>
                                <p class="quick-stat-label">Inventory Health</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Equipment Status -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2>Equipment Status</h2>
                    <div class="section-actions">
                        <a href="equipment.php" class="btn btn-sm btn-outline">View All Equipment</a>
                    </div>
                </div>
                <div class="dashboard-cards">
                    <div class="card">
                        <div class="card-header">
                            <h3>Available</h3>
                            <div class="card-icon available">
                                <i class="fas fa-check"></i>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="stat-value"><?php echo $equipmentStats['available_count'] ?? 0; ?></div>
                            <p class="stat-label">Equipment Available</p>
                            <div class="stat-change positive">
                                <i class="fas fa-arrow-up me-1"></i> 5% from last week
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h3>In Use</h3>
                            <div class="card-icon in-use">
                                <i class="fas fa-user"></i>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="stat-value"><?php echo $equipmentStats['in_use_count'] ?? 0; ?></div>
                            <p class="stat-label">Equipment In Use</p>
                            <div class="stat-change positive">
                                <i class="fas fa-arrow-up me-1"></i> 12% from last week
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h3>Maintenance</h3>
                            <div class="card-icon maintenance">
                                <i class="fas fa-tools"></i>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="stat-value"><?php echo $equipmentStats['maintenance_count'] ?? 0; ?></div>
                            <p class="stat-label">Under Maintenance</p>
                            <div class="stat-change negative">
                                <i class="fas fa-arrow-down me-1"></i> 3% from last week
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h3>Retired</h3>
                            <div class="card-icon retired">
                                <i class="fas fa-archive"></i>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="stat-value"><?php echo $equipmentStats['retired_count'] ?? 0; ?></div>
                            <p class="stat-label">Retired Equipment</p>
                            <div class="stat-change">
                                <i class="fas fa-minus me-1"></i> No change
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Maintenance Status -->
            <div class="dashboard-section">
                <div class="section-header">
                    <h2>Maintenance Status</h2>
                    <div class="section-actions">
                        <a href="maintenance.php" class="btn btn-sm btn-outline">View All Maintenance</a>
                    </div>
                </div>
                <div class="dashboard-cards">
                    <div class="card">
                        <div class="card-header">
                            <h3>Scheduled</h3>
                            <div class="card-icon scheduled">
                                <i class="fas fa-calendar"></i>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="stat-value"><?php echo $maintenanceStats['scheduled_count'] ?? 0; ?></div>
                            <p class="stat-label">Scheduled Maintenance</p>
                            <div class="progress-bar">
                                <div class="progress-bar-fill medium" style="width: 65%"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h3>In Progress</h3>
                            <div class="card-icon in-progress">
                                <i class="fas fa-spinner"></i>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="stat-value"><?php echo $maintenanceStats['in_progress_count'] ?? 0; ?></div>
                            <p class="stat-label">In Progress</p>
                            <div class="progress-bar">
                                <div class="progress-bar-fill high" style="width: 80%"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h3>Completed</h3>
                            <div class="card-icon completed">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="stat-value"><?php echo $maintenanceStats['completed_count'] ?? 0; ?></div>
                            <p class="stat-label">Completed This Month</p>
                            <div class="progress-bar">
                                <div class="progress-bar-fill high" style="width: 90%"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h3>Overdue</h3>
                            <div class="card-icon overdue">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="stat-value"><?php echo $maintenanceStats['overdue_count'] ?? 0; ?></div>
                            <p class="stat-label">Overdue Maintenance</p>
                            <div class="progress-bar">
                                <div class="progress-bar-fill low" style="width: 30%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Grid
            <div class="dashboard-grid">
                Equipment Usage Chart 
                <div class="grid-col-6">
                    <div class="chart-container">
                        <div class="chart-header">
                            <h3>Equipment Usage (Last 14 Days)</h3>
                            <div class="chart-actions">
                                <button class="btn btn-sm btn-outline" id="refresh-usage-chart">
                                    <i class="fas fa-sync-alt"></i> Refresh
                                </button>
                            </div>
                        </div>
                        <canvas id="usageChart" height="15"></canvas>
                    </div>
                </div> -->
                
                <!-- Equipment Type Chart
                <div class="grid-col-6">
                    <div class="chart-container">
                        <div class="chart-header">
                            <h3>Equipment by Type</h3>
                            <div class="chart-actions">
                                <button class="btn btn-sm btn-outline" id="toggle-chart-type">
                                    <i class="fas fa-chart-pie"></i> Toggle Chart
                                </button>
                            </div>
                        </div>
                        <canvas id="typeChart" height="15"></canvas>
                    </div>
                </div> -->
                
                <!-- Maintenance Trend Chart -->
                <div class="grid-col-8">
                    <div class="chart-container">
                        <div class="chart-header">
                            <h3>Maintenance Trends (6 Months)</h3>
                            <div class="chart-actions">
                                <select class="form-select form-select-sm" id="maintenance-chart-period">
                                    <option value="6">Last 6 Months</option>
                                    <option value="3">Last 3 Months</option>
                                    <option value="12">Last 12 Months</option>
                                </select>
                            </div>
                        </div>
                        <canvas id="maintenanceTrendChart" height="250"></canvas>
                        <div class="chart-legend">
                            <div class="legend-item">
                                <div class="legend-color" style="background-color: rgba(23, 162, 184, 0.8);"></div>
                                <span>Scheduled</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color" style="background-color: rgba(40, 167, 69, 0.8);"></div>
                                <span>Completed</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color" style="background-color: rgba(220, 53, 69, 0.8);"></div>
                                <span>Overdue</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Equipment Health -->
                <div class="grid-col-4">
                    <div class="card">
                        <div class="card-header">
                            <h3>Equipment Health</h3>
                            <a href="equipment.php?filter=health" class="btn btn-sm btn-outline">View All</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($equipmentHealthData)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-info-circle fa-2x mb-3" style="color: var(--primary);"></i>
                                <p>No equipment health data available.</p>
                            </div>
                            <?php else: ?>
                                <?php foreach ($equipmentHealthData as $equipment): ?>
                                <div class="equipment-health-card">
                                    <?php 
                                        $healthClass = '';
                                        $healthIcon = '';
                                        if ($equipment['lifecycle_percentage'] >= 80) {
                                            $healthClass = 'bg-danger';
                                            $healthIcon = 'fa-exclamation-circle';
                                        } elseif ($equipment['lifecycle_percentage'] >= 50) {
                                            $healthClass = 'bg-warning';
                                            $healthIcon = 'fa-exclamation-triangle';
                                        } else {
                                            $healthClass = 'bg-success';
                                            $healthIcon = 'fa-check-circle';
                                        }
                                    ?>
                                    <div class="equipment-health-icon <?php echo $healthClass; ?>">
                                        <i class="fas <?php echo $healthIcon; ?>"></i>
                                    </div>
                                    <div class="equipment-health-content">
                                        <h5 class="equipment-health-name"><?php echo htmlspecialchars($equipment['name']); ?></h5>
                                        <p class="equipment-health-type"><?php echo htmlspecialchars($equipment['type']); ?></p>
                                        <div class="progress-bar">
                                            <div class="progress-bar-fill <?php echo $equipment['lifecycle_percentage'] >= 80 ? 'low' : ($equipment['lifecycle_percentage'] >= 50 ? 'medium' : 'high'); ?>" style="width: <?php echo min(100, $equipment['lifecycle_percentage']); ?>%"></div>
                                        </div>
                                        <div class="progress-label">
                                            <span><?php echo $equipment['lifecycle_percentage']; ?>% of lifecycle</span>
                                            <span><?php echo $equipment['maintenance_count']; ?> services</span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <div class="grid-col-6">
                    <div class="activity-list">
                        <div class="activity-list-header">
                            <h3>Recent Activity</h3>
                            <a href="activity.php" class="btn btn-sm">View All</a>
                        </div>
                        <div class="activity-list-body">
                            <?php if (empty($recentActivity)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-history fa-2x mb-3" style="color: var(--primary);"></i>
                                <p>No recent activity found.</p>
                            </div>
                            <?php else: ?>
                                <?php foreach ($recentActivity as $activity): ?>
                                <div class="activity-item">
                                    <?php 
                                        $activityClass = '';
                                        switch (strtolower($activity['action'])) {
                                            case 'added':
                                                $activityClass = 'bg-success';
                                                $activityIcon = 'fa-plus';
                                                break;
                                            case 'updated':
                                                $activityClass = 'bg-info';
                                                $activityIcon = 'fa-edit';
                                                break;
                                            case 'removed':
                                                $activityClass = 'bg-danger';
                                                $activityIcon = 'fa-trash';
                                                break;
                                            case 'maintenance':
                                                $activityClass = 'bg-warning';
                                                $activityIcon = 'fa-tools';
                                                break;
                                            default:
                                                $activityClass = 'bg-primary';
                                                $activityIcon = 'fa-clipboard-list';
                                        }
                                    ?>
                                    <div class="activity-icon <?php echo $activityClass; ?>">
                                        <i class="fas <?php echo $activityIcon; ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <h5 class="activity-title">
                                            <?php echo htmlspecialchars($activity['action']); ?> 
                                            <?php echo htmlspecialchars($activity['equipment_name'] ?? 'Equipment'); ?>
                                        </h5>
                                        <div class="activity-meta">
                                            <span><?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?></span>
                                            <span class="activity-time"><?php echo getRelativeTime($activity['timestamp']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Upcoming Maintenance -->
                <div class="grid-col-6">
                    <div class="maintenance-list">
                        <div class="maintenance-list-header">
                            <h3>Upcoming Maintenance</h3>
                            <a href="maintenance.php" class="btn btn-sm">View All</a>
                        </div>
                        <div class="maintenance-list-body">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Equipment</th>
                                        <th>Date</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($upcomingMaintenance)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center">No upcoming maintenance scheduled.</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($upcomingMaintenance as $maintenance): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <span class="me-2"><?php echo htmlspecialchars($maintenance['equipment_name']); ?></span>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($maintenance['equipment_type']); ?></span>
                                                </div>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($maintenance['scheduled_date'])); ?></td>
                                            <td>
                                                <span class="badge <?php echo getPriorityBadgeClass($maintenance['priority']); ?>"><?php echo htmlspecialchars($maintenance['priority']); ?></span>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo getMaintenanceStatusBadgeClass($maintenance['status'], $maintenance['scheduled_date']); ?>">
                                                    <?php echo htmlspecialchars($maintenance['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="maintenance.php?action=view&id=<?php echo $maintenance['id']; ?>" class="action-btn view" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="maintenance.php?action=edit&id=<?php echo $maintenance['id']; ?>" class="action-btn edit" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="maintenance.php?action=complete&id=<?php echo $maintenance['id']; ?>" class="action-btn complete" title="Mark Complete">
                                                        <i class="fas fa-check"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Inventory Alerts -->
                <div class="grid-col-6">
                    <div class="inventory-alerts">
                        <div class="inventory-alerts-header">
                            <h3>Inventory Alerts</h3>
                            <a href="inventory.php" class="btn btn-sm">View All</a>
                        </div>
                        <div class="inventory-alerts-body">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Current Stock</th>
                                        <th>Min. Required</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($inventoryAlerts)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center">No inventory alerts at this time.</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($inventoryAlerts as $alert): ?>
                                        <?php 
                                            $ratio = $alert['quantity'] / $alert['min_quantity'];
                                            $statusClass = $ratio <= 0.3 ? 'low' : ($ratio <= 0.7 ? 'medium' : 'high');
                                            $percentage = round($ratio * 100);
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <span class="me-2"><?php echo htmlspecialchars($alert['name']); ?></span>
                                                    <?php if (isset($alert['category'])): ?>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($alert['category']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><?php echo $alert['quantity']; ?></td>
                                            <td><?php echo $alert['min_quantity']; ?></td>
                                            <td>
                                                <div class="progress-bar">
                                                    <div class="progress-bar-fill <?php echo $statusClass; ?>" style="width: <?php echo min(100, $percentage); ?>%"></div>
                                                </div>
                                                <div class="progress-label">
                                                    <span><?php echo $percentage; ?>% of minimum</span>
                                                    <a href="inventory.php?action=reorder&id=<?php echo $alert['id']; ?>" class="text-primary">Reorder</a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Weather Widget for Maintenance Planning -->
                <div class="grid-col-6">
                    <div class="card">
                        <div class="card-header">
                            <h3>Maintenance Planning</h3>
                        </div>
                        <div class="card-body">
                            <div class="weather-widget">
                                <div class="weather-day">
                                    <h5>Today</h5>
                                    <div class="weather-icon">
                                        <i class="fas fa-<?php echo $weatherData['today']['icon']; ?>"></i>
                                    </div>
                                    <div class="weather-temp"><?php echo $weatherData['today']['temp']; ?>°F</div>
                                    <div class="weather-condition"><?php echo $weatherData['today']['condition']; ?></div>
                                </div>
                                <div class="weather-day">
                                    <h5>Tomorrow</h5>
                                    <div class="weather-icon">
                                        <i class="fas fa-<?php echo $weatherData['tomorrow']['icon']; ?>"></i>
                                    </div>
                                    <div class="weather-temp"><?php echo $weatherData['tomorrow']['temp']; ?>°F</div>
                                    <div class="weather-condition"><?php echo $weatherData['tomorrow']['condition']; ?></div>
                                </div>
                                <div class="weather-day">
                                    <h5>Day After</h5>
                                    <div class="weather-icon">
                                        <i class="fas fa-<?php echo $weatherData['day_after']['icon']; ?>"></i>
                                    </div>
                                    <div class="weather-temp"><?php echo $weatherData['day_after']['temp']; ?>°F</div>
                                    <div class="weather-condition"><?php echo $weatherData['day_after']['condition']; ?></div>
                                </div>
                            </div>
                            
                            <!-- Mini Calendar -->
                            <div class="calendar-widget mt-4">
                                <div class="calendar-header">
                                    <h5 class="calendar-title">May 2025</h5>
                                    <div class="calendar-nav">
                                        <button class="calendar-nav-btn"><i class="fas fa-chevron-left"></i></button>
                                        <button class="calendar-nav-btn"><i class="fas fa-chevron-right"></i></button>
                                    </div>
                                </div>
                                <div class="calendar-grid">
                                    <div class="calendar-day-header">Sun</div>
                                    <div class="calendar-day-header">Mon</div>
                                    <div class="calendar-day-header">Tue</div>
                                    <div class="calendar-day-header">Wed</div>
                                    <div class="calendar-day-header">Thu</div>
                                    <div class="calendar-day-header">Fri</div>
                                    <div class="calendar-day-header">Sat</div>
                                    
                                    <!-- Calendar days would be dynamically generated -->
                                    <div class="calendar-day other-month"><div class="calendar-day-number">31</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">1</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">2</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">3</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">4</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">5</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">6</div></div>
                                    
                                    <div class="calendar-day"><div class="calendar-day-number">7</div></div>
                                    <div class="calendar-day has-events"><div class="calendar-day-number">8</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">9</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">10</div></div>
                                    <div class="calendar-day has-events"><div class="calendar-day-number">11</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">12</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">13</div></div>
                                    
                                    <div class="calendar-day"><div class="calendar-day-number">14</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">15</div></div>
                                    <div class="calendar-day has-events"><div class="calendar-day-number">16</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">17</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">18</div></div>
                                    <div class="calendar-day has-events"><div class="calendar-day-number">19</div></div>
                                    <div class="calendar-day today"><div class="calendar-day-number">20</div></div>
                                    
                                    <div class="calendar-day"><div class="calendar-day-number">21</div></div>
                                    <div class="calendar-day has-events"><div class="calendar-day-number">22</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">23</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">24</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">25</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">26</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">27</div></div>
                                    
                                    <div class="calendar-day"><div class="calendar-day-number">28</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">29</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">30</div></div>
                                    <div class="calendar-day"><div class="calendar-day-number">31</div></div>
                                    <div class="calendar-day other-month"><div class="calendar-day-number">1</div></div>
                                    <div class="calendar-day other-month"><div class="calendar-day-number">2</div></div>
                                    <div class="calendar-day other-month"><div class="calendar-day-number">3</div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Toggle user dropdown
        document.getElementById('user-dropdown-toggle').addEventListener('click', function() {
            document.getElementById('user-dropdown').classList.toggle('show');
        });
        
        // Toggle notification dropdown
        document.getElementById('notification-bell').addEventListener('click', function(e) {
            e.stopPropagation();
            document.getElementById('notification-dropdown').classList.toggle('show');
            
            // Close user dropdown if open
            document.getElementById('user-dropdown').classList.remove('show');
        });
        
        // Toggle sidebar on mobile
        document.getElementById('sidebar-toggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('expanded');
        });
        
        // Close dropdowns when clicking outside
        window.addEventListener('click', function(event) {
            if (!event.target.matches('.dropdown-toggle') && !event.target.matches('.dropdown-toggle *') &&
                !event.target.matches('.notification-bell') && !event.target.matches('.notification-bell *')) {
                
                var dropdowns = document.getElementsByClassName('dropdown-menu');
                for (var i = 0; i < dropdowns.length; i++) {
                    var openDropdown = dropdowns[i];
                    if (openDropdown.classList.contains('show')) {
                        openDropdown.classList.remove('show');
                    }
                }
                
                var notificationDropdown = document.getElementById('notification-dropdown');
                if (notificationDropdown.classList.contains('show')) {
                    notificationDropdown.classList.remove('show');
                }
            }
        });
        
        // Theme toggle functionality
        document.getElementById('theme-toggle').addEventListener('change', function() {
            const theme = this.checked ? 'dark' : 'light';
            
            // Save theme preference via AJAX
            $.ajax({
                url: 'save-theme.php',
                type: 'POST',
                data: { theme: theme },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Toggle body class
                        document.body.classList.toggle('dark-theme', theme === 'dark');
                        
                        // Update charts for the new theme
                        updateChartsForTheme(theme);
                    }
                }
            });
        });
        
        // Function to update chart colors based on theme
        function updateChartsForTheme(theme) {
            const isDark = theme === 'dark';
            
            // Update text color for all charts
            Chart.defaults.color = isDark ? '#f5f5f5' : '#666';
            Chart.defaults.borderColor = isDark ? '#444' : '#eee';
            
            // Redraw charts
            if (window.usageChart) window.usageChart.update();
            if (window.typeChart) window.typeChart.update();
            if (window.maintenanceTrendChart) window.maintenanceTrendChart.update();
        }
        
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            const isDarkTheme = document.body.classList.contains('dark-theme');
            
            // Set default chart colors based on theme
            Chart.defaults.color = isDarkTheme ? '#f5f5f5' : '#666';
            Chart.defaults.borderColor = isDarkTheme ? '#444' : '#eee';
            
            // Equipment Usage Chart
            const usageCtx = document.getElementById('usageChart').getContext('2d');
            window.usageChart = new Chart(usageCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($usageDates); ?>,
                    datasets: [{
                        label: 'Equipment Usage',
                        data: <?php echo json_encode($usageCounts); ?>,
                        backgroundColor: 'rgba(255, 102, 0, 0.2)',
                        borderColor: 'rgba(255, 102, 0, 1)',
                        borderWidth: 2,
                        tension: 0.3,
                        pointBackgroundColor: 'rgba(255, 102, 0, 1)',
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
                            },
                            grid: {
                                display: true,
                                color: isDarkTheme ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: isDarkTheme ? '#333' : '#fff',
                            titleColor: isDarkTheme ? '#fff' : '#333',
                            bodyColor: isDarkTheme ? '#fff' : '#333',
                            borderColor: isDarkTheme ? '#555' : '#ddd',
                            borderWidth: 1,
                            displayColors: false,
                            callbacks: {
                                title: function(tooltipItems) {
                                    return 'Date: ' + tooltipItems[0].label;
                                },
                                label: function(context) {
                                    return 'Usage Count: ' + context.raw;
                                }
                            }
                        }
                    }
                }
            });
            
            // Equipment Type Chart
            const typeCtx = document.getElementById('typeChart').getContext('2d');
            window.typeChart = new Chart(typeCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($typeLabels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($typeCounts); ?>,
                        backgroundColor: [
                            'rgba(255, 102, 0, 0.8)',
                            'rgba(54, 162, 235, 0.8)',
                            'rgba(255, 206, 86, 0.8)',
                            'rgba(75, 192, 192, 0.8)',
                            'rgba(153, 102, 255, 0.8)',
                            'rgba(255, 159, 64, 0.8)'
                        ],
                        borderWidth: 1,
                        borderColor: isDarkTheme ? '#2d2d2d' : '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                padding: 20,
                                boxWidth: 12,
                                color: isDarkTheme ? '#f5f5f5' : '#666'
                            }
                        },
                        tooltip: {
                            backgroundColor: isDarkTheme ? '#333' : '#fff',
                            titleColor: isDarkTheme ? '#fff' : '#333',
                            bodyColor: isDarkTheme ? '#fff' : '#333',
                            borderColor: isDarkTheme ? '#555' : '#ddd',
                            borderWidth: 1
                        }
                    },
                    cutout: '60%'
                }
            });
            
            // Toggle chart type
            document.getElementById('toggle-chart-type').addEventListener('click', function() {
                const currentType = window.typeChart.config.type;
                const newType = currentType === 'doughnut' ? 'bar' : 'doughnut';
                
                window.typeChart.destroy();
                
                window.typeChart = new Chart(typeCtx, {
                    type: newType,
                    data: {
                        labels: <?php echo json_encode($typeLabels); ?>,
                        datasets: [{
                            data: <?php echo json_encode($typeCounts); ?>,
                            backgroundColor: [
                                'rgba(255, 102, 0, 0.8)',
                                'rgba(54, 162, 235, 0.8)',
                                'rgba(255, 206, 86, 0.8)',
                                'rgba(75, 192, 192, 0.8)',
                                'rgba(153, 102, 255, 0.8)',
                                'rgba(255, 159, 64, 0.8)'
                            ],
                            borderWidth: 1,
                            borderColor: isDarkTheme ? '#2d2d2d' : '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: newType === 'doughnut' ? 'right' : 'top',
                                display: newType === 'doughnut',
                                labels: {
                                    padding: 20,
                                    boxWidth: 12,
                                    color: isDarkTheme ? '#f5f5f5' : '#666'
                                }
                            },
                            tooltip: {
                                backgroundColor: isDarkTheme ? '#333' : '#fff',
                                titleColor: isDarkTheme ? '#fff' : '#333',
                                bodyColor: isDarkTheme ? '#fff' : '#333',
                                borderColor: isDarkTheme ? '#555' : '#ddd',
                                borderWidth: 1
                            }
                        },
                        cutout: newType === 'doughnut' ? '60%' : 0,
                        scales: {
                            y: {
                                display: newType === 'bar',
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                },
                                grid: {
                                    display: true,
                                    color: isDarkTheme ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)'
                                }
                            },
                            x: {
                                display: newType === 'bar',
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            });
            
            // Maintenance Trend Chart
            const maintenanceTrendCtx = document.getElementById('maintenanceTrendChart').getContext('2d');
            window.maintenanceTrendChart = new Chart(maintenanceTrendCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($maintenanceTrendLabels); ?>,
                    datasets: [
                        {
                            label: 'Scheduled',
                            data: <?php echo json_encode($scheduledData); ?>,
                            backgroundColor: 'rgba(23, 162, 184, 0.8)',
                            borderColor: 'rgba(23, 162, 184, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Completed',
                            data: <?php echo json_encode($completedData); ?>,
                            backgroundColor: 'rgba(40, 167, 69, 0.8)',
                            borderColor: 'rgba(40, 167, 69, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Overdue',
                            data: <?php echo json_encode($overdueData); ?>,
                            backgroundColor: 'rgba(220, 53, 69, 0.8)',
                            borderColor: 'rgba(220, 53, 69, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            stacked: false,
                            ticks: {
                                precision: 0
                            },
                            grid: {
                                display: true,
                                color: isDarkTheme ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: isDarkTheme ? '#333' : '#fff',
                            titleColor: isDarkTheme ? '#fff' : '#333',
                            bodyColor: isDarkTheme ? '#fff' : '#333',
                            borderColor: isDarkTheme ? '#555' : '#ddd',
                            borderWidth: 1
                        }
                    }
                }
            });
            
            // Refresh usage chart
            document.getElementById('refresh-usage-chart').addEventListener('click', function() {
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                
                // Simulate AJAX request
                setTimeout(() => {
                    // Random data for demo
                    const newData = Array.from({length: window.usageChart.data.labels.length}, () => 
                        Math.floor(Math.random() * 50) + 10
                    );
                    
                    window.usageChart.data.datasets[0].data = newData;
                    window.usageChart.update();
                    
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh';
                }, 1000);
            });
            
            // Change maintenance chart period
            document.getElementById('maintenance-chart-period').addEventListener('change', function() {
                const period = parseInt(this.value);
                
                // Simulate AJAX request for different periods
                // In a real app, you would fetch new data from the server
                setTimeout(() => {
                    // Random data for demo
                    const labels = [];
                    const scheduled = [];
                    const completed = [];
                    const overdue = [];
                    
                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    const currentDate = new Date();
                    
                    for (let i = period - 1; i >= 0; i--) {
                        const d = new Date(currentDate);
                        d.setMonth(d.getMonth() - i);
                        labels.push(months[d.getMonth()] + ' ' + d.getFullYear());
                        
                        scheduled.push(Math.floor(Math.random() * 20) + 5);
                        completed.push(Math.floor(Math.random() * 15) + 3);
                        overdue.push(Math.floor(Math.random() * 8));
                    }
                    
                    window.maintenanceTrendChart.data.labels = labels;
                    window.maintenanceTrendChart.data.datasets[0].data = scheduled;
                    window.maintenanceTrendChart.data.datasets[1].data = completed;
                    window.maintenanceTrendChart.data.datasets[2].data = overdue;
                    window.maintenanceTrendChart.update();
                }, 300);
            });
            
            // Global search functionality
            document.getElementById('global-search').addEventListener('keyup', function(e) {
                if (e.key === 'Enter') {
                    const searchTerm = this.value.trim();
                    if (searchTerm) {
                        // Redirect to search results page
                        window.location.href = `search.php?q=${encodeURIComponent(searchTerm)}`;
                    }
                }
            });
            
            // Initialize circular progress animations
            const circularProgressElements = document.querySelectorAll('.circular-progress');
            circularProgressElements.forEach(element => {
                const value = parseInt(element.getAttribute('data-value'));
                const circle = element.querySelector('.progress');
                const radius = circle.r.baseVal.value;
                const circumference = 2 * Math.PI * radius;
                
                circle.style.strokeDasharray = `${circumference} ${circumference}`;
                circle.style.strokeDashoffset = circumference;
                
                const offset = circumference - (value / 100) * circumference;
                circle.style.strokeDashoffset = offset;
            });
        });
    </script>
</body>
</html>
