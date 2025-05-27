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

// Get theme preference (default to dark)
$theme = getThemePreference($userId) ?: 'dark';
$themeClasses = getThemeClasses($theme);

// Connect to database
$conn = connectDB();

// Get equipment statistics with enhanced queries
$equipmentStmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'Available' THEN 1 END) as available_count,
        COUNT(CASE WHEN status = 'In Use' THEN 1 END) as in_use_count,
        COUNT(CASE WHEN status = 'Maintenance' THEN 1 END) as maintenance_count,
        COUNT(CASE WHEN status = 'Retired' THEN 1 END) as retired_count,
        COUNT(*) as total_equipment,
        AVG(CASE WHEN purchase_date IS NOT NULL THEN DATEDIFF(CURDATE(), purchase_date) END) as avg_age_days,
        COUNT(CASE WHEN purchase_date > DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as new_equipment_count
    FROM equipment
");
$equipmentStmt->execute();
$equipmentStats = $equipmentStmt->fetch(PDO::FETCH_ASSOC);

// Enhanced maintenance statistics
$maintenanceStmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'Scheduled' THEN 1 END) as scheduled_count,
        COUNT(CASE WHEN status = 'In Progress' THEN 1 END) as in_progress_count,
        COUNT(CASE WHEN status = 'Completed' AND scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as completed_count,
        COUNT(CASE WHEN status = 'Overdue' OR (status = 'Scheduled' AND scheduled_date < CURDATE()) THEN 1 END) as overdue_count,
        COUNT(*) as total_maintenance,
        AVG(CASE WHEN status = 'Completed' AND actual_duration IS NOT NULL THEN actual_duration END) as avg_completion_time,
        COUNT(CASE WHEN priority = 'High' AND status != 'Completed' THEN 1 END) as high_priority_pending
    FROM maintenance_schedule
");
$maintenanceStmt->execute();
$maintenanceStats = $maintenanceStmt->fetch(PDO::FETCH_ASSOC);

// Get real-time calendar events
$calendarEvents = [];
$calendarStmt = $conn->prepare("
    SELECT 
        m.id,
        m.scheduled_date as event_date,
        m.description as title,
        e.name as equipment_name,
        m.priority,
        m.status,
        'maintenance' as event_type
    FROM maintenance_schedule m
    JOIN equipment e ON m.equipment_id = e.id
    WHERE m.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    UNION ALL
    SELECT 
        NULL as id,
        delivery_date as event_date,
        CONCAT('Delivery: ', item_name) as title,
        supplier as equipment_name,
        'Medium' as priority,
        'Scheduled' as status,
        'delivery' as event_type
    FROM inventory 
    WHERE delivery_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY event_date
");
$calendarStmt->execute();
$calendarEvents = $calendarStmt->fetchAll(PDO::FETCH_ASSOC);

// Get enhanced equipment usage analytics
$usageAnalytics = [];
$usageStmt = $conn->prepare("
    SELECT 
        DATE(usage_date) as date,
        COUNT(*) as usage_count,
        COUNT(DISTINCT equipment_id) as unique_equipment,
        AVG(duration_minutes) as avg_duration
    FROM equipment_usage 
    WHERE usage_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(usage_date)
    ORDER BY date
");
$usageStmt->execute();
$usageAnalytics = $usageStmt->fetchAll(PDO::FETCH_ASSOC);

// Get equipment performance metrics
$performanceMetrics = [];
$performanceStmt = $conn->prepare("
    SELECT 
        e.id,
        e.name,
        e.type,
        COUNT(u.id) as usage_frequency,
        AVG(u.duration_minutes) as avg_usage_duration,
        COUNT(m.id) as maintenance_frequency,
        DATEDIFF(CURDATE(), e.purchase_date) as age_days,
        e.expected_lifetime_days,
        (DATEDIFF(CURDATE(), e.purchase_date) / NULLIF(e.expected_lifetime_days, 0)) * 100 as lifecycle_percentage
    FROM equipment e
    LEFT JOIN equipment_usage u ON e.id = u.equipment_id AND u.usage_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    LEFT JOIN maintenance_schedule m ON e.id = m.equipment_id
    WHERE e.status != 'Retired'
    GROUP BY e.id
    ORDER BY usage_frequency DESC
    LIMIT 10
");
$performanceStmt->execute();
$performanceMetrics = $performanceStmt->fetchAll(PDO::FETCH_ASSOC);

// Get current date and calendar data
$currentDate = new DateTime();
$currentMonth = $currentDate->format('n');
$currentYear = $currentDate->format('Y');
$currentDay = $currentDate->format('j');

// Generate calendar data for current month
function generateCalendarData($year, $month, $events = []) {
    $firstDay = new DateTime("$year-$month-01");
    $lastDay = new DateTime($firstDay->format('Y-m-t'));
    $startDate = clone $firstDay;
    $startDate->modify('last sunday');
    
    $endDate = clone $lastDay;
    $endDate->modify('next saturday');
    
    $calendar = [];
    $current = clone $startDate;
    
    while ($current <= $endDate) {
        $dayData = [
            'date' => $current->format('Y-m-d'),
            'day' => $current->format('j'),
            'is_current_month' => $current->format('n') == $month,
            'is_today' => $current->format('Y-m-d') === date('Y-m-d'),
            'events' => []
        ];
        
        // Add events for this day
        foreach ($events as $event) {
            if ($event['event_date'] === $current->format('Y-m-d')) {
                $dayData['events'][] = $event;
            }
        }
        
        $calendar[] = $dayData;
        $current->modify('+1 day');
    }
    
    return $calendar;
}

$calendarData = generateCalendarData($currentYear, $currentMonth, $calendarEvents);

// Get weather data (enhanced with more details)
$weatherData = [
    'current' => [
        'temp' => 72,
        'condition' => 'Sunny',
        'icon' => 'sun',
        'humidity' => 45,
        'wind_speed' => 8,
        'maintenance_suitable' => true
    ],
    'forecast' => [
        [
            'date' => date('Y-m-d', strtotime('+1 day')),
            'temp' => 68,
            'condition' => 'Partly Cloudy',
            'icon' => 'cloud-sun',
            'maintenance_suitable' => true
        ],
        [
            'date' => date('Y-m-d', strtotime('+2 days')),
            'temp' => 65,
            'condition' => 'Rain',
            'icon' => 'cloud-rain',
            'maintenance_suitable' => false
        ],
        [
            'date' => date('Y-m-d', strtotime('+3 days')),
            'temp' => 70,
            'condition' => 'Clear',
            'icon' => 'sun',
            'maintenance_suitable' => true
        ]
    ]
];

// Enhanced notification system
$notifications = [];
$notificationStmt = $conn->prepare("
    SELECT 
        'maintenance_due' as type,
        CONCAT('Maintenance due for ', e.name) as message,
        m.scheduled_date as date,
        m.priority,
        CONCAT('maintenance.php?id=', m.id) as link
    FROM maintenance_schedule m
    JOIN equipment e ON m.equipment_id = e.id
    WHERE m.scheduled_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
    AND m.status = 'Scheduled'
    
    UNION ALL
    
    SELECT 
        'inventory_low' as type,
        CONCAT(item_name, ' stock is low (', quantity, ' remaining)') as message,
        NOW() as date,
        CASE 
            WHEN quantity <= min_quantity * 0.3 THEN 'High'
            WHEN quantity <= min_quantity * 0.6 THEN 'Medium'
            ELSE 'Low'
        END as priority,
        CONCAT('inventory.php?id=', id) as link
    FROM inventory
    WHERE quantity <= min_quantity
    
    UNION ALL
    
    SELECT 
        'equipment_alert' as type,
        CONCAT(name, ' requires attention (', 
            ROUND((DATEDIFF(CURDATE(), purchase_date) / expected_lifetime_days) * 100), 
            '% lifecycle)') as message,
        NOW() as date,
        'Medium' as priority,
        CONCAT('equipment.php?id=', id) as link
    FROM equipment
    WHERE expected_lifetime_days > 0 
    AND (DATEDIFF(CURDATE(), purchase_date) / expected_lifetime_days) > 0.85
    AND status != 'Retired'
    
    ORDER BY 
        CASE priority 
            WHEN 'High' THEN 1 
            WHEN 'Medium' THEN 2 
            ELSE 3 
        END,
        date DESC
    LIMIT 15
");
$notificationStmt->execute();
$notifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipment Manager Dashboard - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/date-fns@2.29.3/index.min.js"></script>
    <style>
        :root {
            /* Enhanced Orange & Dark Theme */
            --primary: #ff6b35;
            --primary-dark: #e55a2b;
            --primary-light: #ff8c5a;
            --primary-very-light: #fff4f0;
            --accent: #ff9500;
            --accent-dark: #e6850e;
            
            /* Dark Theme Colors */
            --dark-bg: #0a0a0a;
            --dark-surface: #1a1a1a;
            --dark-surface-light: #2d2d2d;
            --dark-border: #404040;
            --dark-text: #ffffff;
            --dark-text-secondary: #b3b3b3;
            
            /* Light Theme Colors */
            --light-bg: #fafafa;
            --light-surface: #ffffff;
            --light-surface-alt: #f5f5f5;
            --light-border: #e0e0e0;
            --light-text: #1a1a1a;
            --light-text-secondary: #666666;
            
            /* Status Colors */
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            
            /* Spacing & Effects */
            --border-radius: 12px;
            --border-radius-lg: 16px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--dark-bg);
            color: var(--dark-text);
            line-height: 1.6;
            transition: var(--transition);
        }
        
        body.light-theme {
            background-color: var(--light-bg);
            color: var(--light-text);
        }
        
        /* Enhanced Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 280px;
            background: linear-gradient(180deg, var(--dark-surface) 0%, var(--dark-surface-light) 100%);
            border-right: 1px solid var(--dark-border);
            z-index: 1000;
            transition: var(--transition);
            overflow-y: auto;
        }
        
        .light-theme .sidebar {
            background: linear-gradient(180deg, var(--light-surface) 0%, var(--light-surface-alt) 100%);
            border-right: 1px solid var(--light-border);
        }
        
        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid var(--dark-border);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .light-theme .sidebar-header {
            border-bottom: 1px solid var(--light-border);
        }
        
        .sidebar-logo {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
            font-weight: 700;
        }
        
        .sidebar-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .nav-section {
            margin-bottom: 2rem;
        }
        
        .nav-section-title {
            padding: 0 1.5rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--dark-text-secondary);
        }
        
        .light-theme .nav-section-title {
            color: var(--light-text-secondary);
        }
        
        .nav-item {
            margin: 0.25rem 1rem;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius);
            color: var(--dark-text-secondary);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            position: relative;
        }
        
        .light-theme .nav-link {
            color: var(--light-text-secondary);
        }
        
        .nav-link:hover {
            background-color: rgba(255, 107, 53, 0.1);
            color: var(--primary);
            transform: translateX(4px);
        }
        
        .nav-link.active {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            box-shadow: var(--shadow);
        }
        
        .nav-link.active::before {
            content: '';
            position: absolute;
            left: -1rem;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 20px;
            background: var(--primary);
            border-radius: 0 4px 4px 0;
        }
        
        .nav-icon {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }
        
        /* Enhanced Main Content */
        .main-content {
            margin-left: 280px;
            min-height: 100vh;
            background-color: var(--dark-bg);
            transition: var(--transition);
        }
        
        .light-theme .main-content {
            background-color: var(--light-bg);
        }
        
        /* Enhanced Header */
        .header {
            background: var(--dark-surface);
            border-bottom: 1px solid var(--dark-border);
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            backdrop-filter: blur(10px);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .light-theme .header {
            background: var(--light-surface);
            border-bottom: 1px solid var(--light-border);
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .header-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-text);
        }
        
        .light-theme .header-title {
            color: var(--light-text);
        }
        
        .header-subtitle {
            font-size: 0.875rem;
            color: var(--dark-text-secondary);
            margin-top: 0.25rem;
        }
        
        .light-theme .header-subtitle {
            color: var(--light-text-secondary);
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        /* Enhanced Search */
        .search-container {
            position: relative;
        }
        
        .search-input {
            width: 300px;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            background: var(--dark-surface-light);
            border: 1px solid var(--dark-border);
            border-radius: var(--border-radius);
            color: var(--dark-text);
            font-size: 0.875rem;
            transition: var(--transition);
        }
        
        .light-theme .search-input {
            background: var(--light-surface-alt);
            border: 1px solid var(--light-border);
            color: var(--light-text);
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
            width: 350px;
        }
        
        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--dark-text-secondary);
            font-size: 0.875rem;
        }
        
        .light-theme .search-icon {
            color: var(--light-text-secondary);
        }
        
        /* Enhanced Theme Toggle */
        .theme-toggle {
            position: relative;
            width: 60px;
            height: 30px;
            background: var(--dark-surface-light);
            border-radius: 15px;
            border: 1px solid var(--dark-border);
            cursor: pointer;
            transition: var(--transition);
        }
        
        .light-theme .theme-toggle {
            background: var(--light-surface-alt);
            border: 1px solid var(--light-border);
        }
        
        .theme-toggle.active {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-color: var(--primary);
        }
        
        .theme-toggle-slider {
            position: absolute;
            top: 2px;
            left: 2px;
            width: 26px;
            height: 26px;
            background: white;
            border-radius: 50%;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            color: var(--dark-text);
        }
        
        .theme-toggle.active .theme-toggle-slider {
            transform: translateX(30px);
            color: var(--primary);
        }
        
        /* Enhanced Notifications */
        .notification-bell {
            position: relative;
            width: 40px;
            height: 40px;
            background: var(--dark-surface-light);
            border: 1px solid var(--dark-border);
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .light-theme .notification-bell {
            background: var(--light-surface-alt);
            border: 1px solid var(--light-border);
        }
        
        .notification-bell:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 20px;
            height: 20px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        /* Enhanced User Menu */
        .user-menu {
            position: relative;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: var(--border-radius);
            border: 2px solid var(--primary);
            cursor: pointer;
            transition: var(--transition);
        }
        
        .user-avatar:hover {
            transform: scale(1.05);
            box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.2);
        }
        
        /* Enhanced Dashboard Grid */
        .dashboard-container {
            padding: 2rem;
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
        }
        
        /* Enhanced Cards */
        .card {
            background: var(--dark-surface);
            border: 1px solid var(--dark-border);
            border-radius: var(--border-radius-lg);
            padding: 1.5rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .light-theme .card {
            background: var(--light-surface);
            border: 1px solid var(--light-border);
        }
        
        .card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }
        
        .stat-card {
            grid-column: span 3;
        }
        
        .stat-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        
        .stat-card-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: white;
        }
        
        .stat-card-value {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 0.5rem;
        }
        
        .stat-card-label {
            font-size: 0.875rem;
            color: var(--dark-text-secondary);
            font-weight: 500;
        }
        
        .light-theme .stat-card-label {
            color: var(--light-text-secondary);
        }
        
        .stat-card-change {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        
        .stat-card-change.positive {
            color: var(--success);
        }
        
        .stat-card-change.negative {
            color: var(--danger);
        }
        
        /* Enhanced Calendar */
        .calendar-widget {
            grid-column: span 6;
        }
        
        .calendar-header {
            display: flex;
            align-items: center;
            justify-content: between;
            margin-bottom: 1.5rem;
        }
        
        .calendar-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark-text);
        }
        
        .light-theme .calendar-title {
            color: var(--light-text);
        }
        
        .calendar-nav {
            display: flex;
            gap: 0.5rem;
            margin-left: auto;
        }
        
        .calendar-nav-btn {
            width: 32px;
            height: 32px;
            border: none;
            background: var(--dark-surface-light);
            color: var(--dark-text);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .light-theme .calendar-nav-btn {
            background: var(--light-surface-alt);
            color: var(--light-text);
        }
        
        .calendar-nav-btn:hover {
            background: var(--primary);
            color: white;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: var(--dark-border);
            border-radius: var(--border-radius);
            overflow: hidden;
        }
        
        .light-theme .calendar-grid {
            background: var(--light-border);
        }
        
        .calendar-day-header {
            background: var(--dark-surface-light);
            padding: 0.75rem 0.5rem;
            text-align: center;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--dark-text-secondary);
        }
        
        .light-theme .calendar-day-header {
            background: var(--light-surface-alt);
            color: var(--light-text-secondary);
        }
        
        .calendar-day {
            background: var(--dark-surface);
            padding: 0.75rem 0.5rem;
            min-height: 80px;
            position: relative;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .light-theme .calendar-day {
            background: var(--light-surface);
        }
        
        .calendar-day:hover {
            background: rgba(255, 107, 53, 0.1);
        }
        
        .calendar-day.today {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
        }
        
        .calendar-day.other-month {
            opacity: 0.3;
        }
        
        .calendar-day-number {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .calendar-event {
            background: rgba(255, 107, 53, 0.2);
            border-left: 3px solid var(--primary);
            padding: 0.25rem;
            margin-bottom: 0.25rem;
            border-radius: 4px;
            font-size: 0.7rem;
            line-height: 1.2;
        }
        
        .calendar-event.high-priority {
            border-left-color: var(--danger);
            background: rgba(239, 68, 68, 0.2);
        }
        
        /* Enhanced Charts */
        .chart-container {
            grid-column: span 6;
            position: relative;
        }
        
        .chart-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }
        
        .chart-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--dark-text);
        }
        
        .light-theme .chart-title {
            color: var(--light-text);
        }
        
        .chart-controls {
            display: flex;
            gap: 0.5rem;
        }
        
        .chart-btn {
            padding: 0.5rem 1rem;
            background: var(--dark-surface-light);
            border: 1px solid var(--dark-border);
            border-radius: var(--border-radius);
            color: var(--dark-text);
            font-size: 0.875rem;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .light-theme .chart-btn {
            background: var(--light-surface-alt);
            border: 1px solid var(--light-border);
            color: var(--light-text);
        }
        
        .chart-btn:hover,
        .chart-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        /* Enhanced Tables */
        .data-table {
            grid-column: span 12;
        }
        
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            border: 1px solid var(--dark-border);
        }
        
        .light-theme .table-container {
            border: 1px solid var(--light-border);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        
        .table th {
            background: var(--dark-surface-light);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark-text);
            border-bottom: 1px solid var(--dark-border);
        }
        
        .light-theme .table th {
            background: var(--light-surface-alt);
            color: var(--light-text);
            border-bottom: 1px solid var(--light-border);
        }
        
        .table td {
            padding: 1rem;
            border-bottom: 1px solid var(--dark-border);
            color: var(--dark-text);
        }
        
        .light-theme .table td {
            border-bottom: 1px solid var(--light-border);
            color: var(--light-text);
        }
        
        .table tbody tr:hover {
            background: rgba(255, 107, 53, 0.05);
        }
        
        /* Enhanced Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        
        .badge-success {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }
        
        .badge-warning {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }
        
        .badge-danger {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }
        
        .badge-info {
            background: rgba(59, 130, 246, 0.2);
            color: var(--info);
        }
        
        .badge-primary {
            background: rgba(255, 107, 53, 0.2);
            color: var(--primary);
        }
        
        /* Enhanced Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.875rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }
        
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }
        
        /* Enhanced Weather Widget */
        .weather-widget {
            grid-column: span 6;
        }
        
        .weather-current {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: var(--border-radius);
            color: white;
        }
        
        .weather-icon {
            font-size: 3rem;
        }
        
        .weather-details h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .weather-details p {
            opacity: 0.9;
            margin: 0;
        }
        
        .weather-forecast {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }
        
        .weather-day {
            text-align: center;
            padding: 1rem;
            background: var(--dark-surface-light);
            border-radius: var(--border-radius);
            transition: var(--transition);
        }
        
        .light-theme .weather-day {
            background: var(--light-surface-alt);
        }
        
        .weather-day:hover {
            transform: translateY(-2px);
        }
        
        .weather-day-icon {
            font-size: 1.5rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        
        .weather-day-temp {
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .weather-day-condition {
            font-size: 0.8rem;
            color: var(--dark-text-secondary);
        }
        
        .light-theme .weather-day-condition {
            color: var(--light-text-secondary);
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .stat-card {
                grid-column: span 6;
            }
            
            .calendar-widget,
            .chart-container,
            .weather-widget {
                grid-column: span 12;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .dashboard-container {
                padding: 1rem;
                grid-template-columns: 1fr;
            }
            
            .stat-card,
            .calendar-widget,
            .chart-container,
            .weather-widget,
            .data-table {
                grid-column: span 1;
            }
            
            .header {
                padding: 1rem;
            }
            
            .search-input {
                width: 200px;
            }
            
            .search-input:focus {
                width: 250px;
            }
        }
        
        /* Loading States */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid var(--dark-border);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Utility Classes */
        .text-primary { color: var(--primary) !important; }
        .text-success { color: var(--success) !important; }
        .text-warning { color: var(--warning) !important; }
        .text-danger { color: var(--danger) !important; }
        .text-info { color: var(--info) !important; }
        
        .bg-primary { background-color: var(--primary) !important; }
        .bg-success { background-color: var(--success) !important; }
        .bg-warning { background-color: var(--warning) !important; }
        .bg-danger { background-color: var(--danger) !important; }
        .bg-info { background-color: var(--info) !important; }
    </style>
</head>
<body class="<?php echo $theme === 'dark' ? '' : 'light-theme'; ?>">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <i class="fas fa-dumbbell"></i>
            </div>
            <div>
                <div class="sidebar-title">EliteFit</div>
                <div style="font-size: 0.8rem; color: var(--dark-text-secondary);">Equipment Manager</div>
            </div>
        </div>
        
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-title">Main</div>
                <div class="nav-item">
                    <a href="dashboard.php" class="nav-link active">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="equipment.php" class="nav-link">
                        <i class="nav-icon fas fa-dumbbell"></i>
                        <span>Equipment</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="maintenance.php" class="nav-link">
                        <i class="nav-icon fas fa-tools"></i>
                        <span>Maintenance</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="inventory.php" class="nav-link">
                        <i class="nav-icon fas fa-boxes"></i>
                        <span>Inventory</span>
                    </a>
                </div>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Analytics</div>
                <div class="nav-item">
                    <a href="report.php" class="nav-link">
                        <i class="nav-icon fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="analytics.php" class="nav-link">
                        <i class="nav-icon fas fa-chart-line"></i>
                        <span>Analytics</span>
                    </a>
                </div>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Tools</div>
                <div class="nav-item">
                    <a href="calendar.php" class="nav-link">
                        <i class="nav-icon fas fa-calendar-alt"></i>
                        <span>Calendar</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="settings.php" class="nav-link">
                        <i class="nav-icon fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="../logout.php" class="nav-link">
                        <i class="nav-icon fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </nav>
    </aside>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <button class="btn btn-outline btn-sm d-md-none" id="sidebar-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h1 class="header-title">Equipment Dashboard</h1>
                    <p class="header-subtitle">Welcome back, <?php echo htmlspecialchars($userName); ?></p>
                </div>
            </div>
            
            <div class="header-right">
                <div class="search-container">
                    <i class="search-icon fas fa-search"></i>
                    <input type="text" class="search-input" placeholder="Search equipment, maintenance..." id="global-search">
                </div>
                
                <div class="theme-toggle <?php echo $theme === 'dark' ? 'active' : ''; ?>" id="theme-toggle">
                    <div class="theme-toggle-slider">
                        <i class="fas fa-<?php echo $theme === 'dark' ? 'moon' : 'sun'; ?>"></i>
                    </div>
                </div>
                
                <div class="notification-bell" id="notification-bell">
                    <i class="fas fa-bell"></i>
                    <?php if (count($notifications) > 0): ?>
                    <span class="notification-badge"><?php echo count($notifications); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="user-menu">
                    <img src="https://randomuser.me/api/portraits/men/3.jpg" alt="User Avatar" class="user-avatar" id="user-avatar">
                </div>
            </div>
        </header>
        
        <!-- Dashboard Content -->
        <div class="dashboard-container">
            <!-- Stats Cards -->
            <div class="card stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-icon bg-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-card-value text-success"><?php echo $equipmentStats['available_count'] ?? 0; ?></div>
                <div class="stat-card-label">Available Equipment</div>
                <div class="stat-card-change positive">
                    <i class="fas fa-arrow-up"></i>
                    <span>+5.2% from last week</span>
                </div>
            </div>
            
            <div class="card stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-icon bg-info">
                        <i class="fas fa-user-clock"></i>
                    </div>
                </div>
                <div class="stat-card-value text-info"><?php echo $equipmentStats['in_use_count'] ?? 0; ?></div>
                <div class="stat-card-label">In Use</div>
                <div class="stat-card-change positive">
                    <i class="fas fa-arrow-up"></i>
                    <span>+12.8% from last week</span>
                </div>
            </div>
            
            <div class="card stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-icon bg-warning">
                        <i class="fas fa-tools"></i>
                    </div>
                </div>
                <div class="stat-card-value text-warning"><?php echo $maintenanceStats['scheduled_count'] ?? 0; ?></div>
                <div class="stat-card-label">Scheduled Maintenance</div>
                <div class="stat-card-change">
                    <i class="fas fa-minus"></i>
                    <span>No change</span>
                </div>
            </div>
            
            <div class="card stat-card">
                <div class="stat-card-header">
                    <div class="stat-card-icon bg-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
                <div class="stat-card-value text-danger"><?php echo $maintenanceStats['overdue_count'] ?? 0; ?></div>
                <div class="stat-card-label">Overdue Items</div>
                <div class="stat-card-change negative">
                    <i class="fas fa-arrow-down"></i>
                    <span>-2.1% from last week</span>
                </div>
            </div>
            
            <!-- Enhanced Calendar Widget -->
            <div class="card calendar-widget">
                <div class="calendar-header">
                    <h2 class="calendar-title"><?php echo $currentDate->format('F Y'); ?></h2>
                    <div class="calendar-nav">
                        <button class="calendar-nav-btn" id="prev-month">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button class="calendar-nav-btn" id="today-btn">Today</button>
                        <button class="calendar-nav-btn" id="next-month">
                            <i class="fas fa-chevron-right"></i>
                        </button>
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
                    
                    <?php foreach ($calendarData as $day): ?>
                    <div class="calendar-day <?php echo !$day['is_current_month'] ? 'other-month' : ''; ?> <?php echo $day['is_today'] ? 'today' : ''; ?>" 
                         data-date="<?php echo $day['date']; ?>">
                        <div class="calendar-day-number"><?php echo $day['day']; ?></div>
                        <?php foreach ($day['events'] as $event): ?>
                        <div class="calendar-event <?php echo $event['priority'] === 'High' ? 'high-priority' : ''; ?>" 
                             title="<?php echo htmlspecialchars($event['title']); ?>">
                            <?php echo htmlspecialchars(substr($event['title'], 0, 20)) . (strlen($event['title']) > 20 ? '...' : ''); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Enhanced Weather Widget -->
            <div class="card weather-widget">
                <h2 class="chart-title">Weather & Maintenance Planning</h2>
                
                <div class="weather-current">
                    <div class="weather-icon">
                        <i class="fas fa-<?php echo $weatherData['current']['icon']; ?>"></i>
                    </div>
                    <div class="weather-details">
                        <h3><?php echo $weatherData['current']['temp']; ?>°F</h3>
                        <p><?php echo $weatherData['current']['condition']; ?></p>
                        <p>Humidity: <?php echo $weatherData['current']['humidity']; ?>% | Wind: <?php echo $weatherData['current']['wind_speed']; ?> mph</p>
                        <p class="<?php echo $weatherData['current']['maintenance_suitable'] ? 'text-success' : 'text-warning'; ?>">
                            <i class="fas fa-<?php echo $weatherData['current']['maintenance_suitable'] ? 'check' : 'exclamation-triangle'; ?>"></i>
                            <?php echo $weatherData['current']['maintenance_suitable'] ? 'Good for outdoor maintenance' : 'Indoor maintenance recommended'; ?>
                        </p>
                    </div>
                </div>
                
                <div class="weather-forecast">
                    <?php foreach ($weatherData['forecast'] as $forecast): ?>
                    <div class="weather-day">
                        <div class="weather-day-icon">
                            <i class="fas fa-<?php echo $forecast['icon']; ?>"></i>
                        </div>
                        <div class="weather-day-temp"><?php echo $forecast['temp']; ?>°F</div>
                        <div class="weather-day-condition"><?php echo $forecast['condition']; ?></div>
                        <div class="mt-2">
                            <span class="badge <?php echo $forecast['maintenance_suitable'] ? 'badge-success' : 'badge-warning'; ?>">
                                <?php echo $forecast['maintenance_suitable'] ? 'Suitable' : 'Not Ideal'; ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Equipment Performance Chart -->
            <div class="card chart-container">
                <div class="chart-header">
                    <h2 class="chart-title">Equipment Usage Analytics</h2>
                    <div class="chart-controls">
                        <button class="chart-btn active" data-period="7">7 Days</button>
                        <button class="chart-btn" data-period="30">30 Days</button>
                        <button class="chart-btn" data-period="90">90 Days</button>
                    </div>
                </div>
                <canvas id="usageChart" height="300"></canvas>
            </div>
            
            <!-- Maintenance Trends Chart -->
            <div class="card chart-container">
                <div class="chart-header">
                    <h2 class="chart-title">Maintenance Trends</h2>
                    <div class="chart-controls">
                        <button class="chart-btn" id="export-chart">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                <canvas id="maintenanceChart" height="300"></canvas>
            </div>
            
            <!-- Recent Activity & Upcoming Maintenance -->
            <div class="card data-table">
                <div class="chart-header">
                    <h2 class="chart-title">Recent Activity & Upcoming Maintenance</h2>
                    <div class="chart-controls">
                        <button class="chart-btn" id="refresh-data">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                        <a href="maintenance.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Schedule Maintenance
                        </a>
                    </div>
                </div>
                
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Equipment</th>
                                <th>Type</th>
                                <th>Scheduled Date</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Assigned To</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($upcomingMaintenance)): ?>
                            <tr>
                                <td colspan="7" class="text-center" style="padding: 2rem;">
                                    <i class="fas fa-calendar-check fa-2x text-primary mb-3"></i>
                                    <p>No upcoming maintenance scheduled.</p>
                                    <a href="maintenance.php" class="btn btn-primary btn-sm">Schedule Maintenance</a>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($upcomingMaintenance as $maintenance): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-dumbbell text-primary me-2"></i>
                                            <span class="fw-bold"><?php echo htmlspecialchars($maintenance['equipment_name']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-info"><?php echo htmlspecialchars($maintenance['equipment_type']); ?></span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($maintenance['scheduled_date'])); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo strtolower($maintenance['priority']); ?>">
                                            <?php echo htmlspecialchars($maintenance['priority']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo getMaintenanceStatusBadgeClass($maintenance['status'], $maintenance['scheduled_date']); ?>">
                                            <?php echo htmlspecialchars($maintenance['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($maintenance['assigned_to'] ?? 'Unassigned'); ?></td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <a href="maintenance.php?action=view&id=<?php echo $maintenance['id']; ?>" 
                                               class="btn btn-outline btn-sm" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="maintenance.php?action=edit&id=<?php echo $maintenance['id']; ?>" 
                                               class="btn btn-primary btn-sm" title="Edit">
                                                <i class="fas fa-edit"></i>
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
    </main>
    
    <!-- Enhanced JavaScript -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        // Enhanced Dashboard JavaScript
        class EquipmentDashboard {
            constructor() {
                this.currentTheme = '<?php echo $theme; ?>';
                this.currentMonth = <?php echo $currentMonth; ?>;
                this.currentYear = <?php echo $currentYear; ?>;
                this.charts = {};
                
                this.init();
            }
            
            init() {
                this.setupEventListeners();
                this.initializeCharts();
                this.setupRealTimeUpdates();
                this.setupNotifications();
            }
            
            setupEventListeners() {
                // Theme toggle
                document.getElementById('theme-toggle').addEventListener('click', () => {
                    this.toggleTheme();
                });
                
                // Sidebar toggle for mobile
                const sidebarToggle = document.getElementById('sidebar-toggle');
                if (sidebarToggle) {
                    sidebarToggle.addEventListener('click', () => {
                        document.getElementById('sidebar').classList.toggle('open');
                    });
                }
                
                // Calendar navigation
                document.getElementById('prev-month').addEventListener('click', () => {
                    this.navigateCalendar(-1);
                });
                
                document.getElementById('next-month').addEventListener('click', () => {
                    this.navigateCalendar(1);
                });
                
                document.getElementById('today-btn').addEventListener('click', () => {
                    this.goToToday();
                });
                
                // Chart controls
                document.querySelectorAll('.chart-btn[data-period]').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        this.updateUsageChart(e.target.dataset.period);
                        document.querySelectorAll('.chart-btn[data-period]').forEach(b => b.classList.remove('active'));
                        e.target.classList.add('active');
                    });
                });
                
                // Search functionality
                document.getElementById('global-search').addEventListener('keyup', (e) => {
                    if (e.key === 'Enter') {
                        this.performSearch(e.target.value);
                    }
                });
                
                // Refresh data
                const refreshBtn = document.getElementById('refresh-data');
                if (refreshBtn) {
                    refreshBtn.addEventListener('click', () => {
                        this.refreshDashboardData();
                    });
                }
                
                // Calendar day clicks
                document.querySelectorAll('.calendar-day').forEach(day => {
                    day.addEventListener('click', (e) => {
                        this.showDayDetails(e.currentTarget.dataset.date);
                    });
                });
            }
            
            toggleTheme() {
                const newTheme = this.currentTheme === 'dark' ? 'light' : 'dark';
                
                // Update UI immediately
                document.body.classList.toggle('light-theme', newTheme === 'light');
                
                const toggle = document.getElementById('theme-toggle');
                toggle.classList.toggle('active', newTheme === 'dark');
                
                const slider = toggle.querySelector('.theme-toggle-slider i');
                slider.className = `fas fa-${newTheme === 'dark' ? 'moon' : 'sun'}`;
                
                // Save preference
                fetch('save-theme.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ theme: newTheme })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.currentTheme = newTheme;
                        this.updateChartsForTheme();
                    }
                })
                .catch(error => {
                    console.error('Error saving theme:', error);
                });
            }
            
            initializeCharts() {
                const isDark = this.currentTheme === 'dark';
                
                // Set Chart.js defaults for theme
                Chart.defaults.color = isDark ? '#b3b3b3' : '#666666';
                Chart.defaults.borderColor = isDark ? '#404040' : '#e0e0e0';
                Chart.defaults.backgroundColor = isDark ? '#1a1a1a' : '#ffffff';
                
                // Usage Chart
                this.initUsageChart();
                
                // Maintenance Chart
                this.initMaintenanceChart();
            }
            
            initUsageChart() {
                const ctx = document.getElementById('usageChart').getContext('2d');
                const isDark = this.currentTheme === 'dark';
                
                this.charts.usage = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode(array_column($usageAnalytics, 'date')); ?>,
                        datasets: [{
                            label: 'Daily Usage',
                            data: <?php echo json_encode(array_column($usageAnalytics, 'usage_count')); ?>,
                            borderColor: '#ff6b35',
                            backgroundColor: 'rgba(255, 107, 53, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#ff6b35',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 6,
                            pointHoverRadius: 8
                        }, {
                            label: 'Unique Equipment',
                            data: <?php echo json_encode(array_column($usageAnalytics, 'unique_equipment')); ?>,
                            borderColor: '#ff9500',
                            backgroundColor: 'rgba(255, 149, 0, 0.1)',
                            borderWidth: 2,
                            fill: false,
                            tension: 0.4,
                            pointBackgroundColor: '#ff9500',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            intersect: false,
                            mode: 'index'
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    usePointStyle: true,
                                    padding: 20,
                                    color: isDark ? '#b3b3b3' : '#666666'
                                }
                            },
                            tooltip: {
                                backgroundColor: isDark ? '#2d2d2d' : '#ffffff',
                                titleColor: isDark ? '#ffffff' : '#1a1a1a',
                                bodyColor: isDark ? '#b3b3b3' : '#666666',
                                borderColor: isDark ? '#404040' : '#e0e0e0',
                                borderWidth: 1,
                                cornerRadius: 8,
                                displayColors: true,
                                callbacks: {
                                    title: function(context) {
                                        return 'Date: ' + context[0].label;
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    color: isDark ? '#b3b3b3' : '#666666'
                                }
                            },
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: isDark ? '#404040' : '#e0e0e0'
                                },
                                ticks: {
                                    color: isDark ? '#b3b3b3' : '#666666',
                                    precision: 0
                                }
                            }
                        }
                    }
                });
            }
            
            initMaintenanceChart() {
                const ctx = document.getElementById('maintenanceChart').getContext('2d');
                const isDark = this.currentTheme === 'dark';
                
                // Generate sample data for maintenance trends
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
                const scheduledData = [12, 15, 8, 20, 18, 14];
                const completedData = [10, 13, 7, 18, 16, 12];
                const overdueData = [2, 2, 1, 2, 2, 2];
                
                this.charts.maintenance = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: months,
                        datasets: [{
                            label: 'Scheduled',
                            data: scheduledData,
                            backgroundColor: 'rgba(59, 130, 246, 0.8)',
                            borderColor: '#3b82f6',
                            borderWidth: 1,
                            borderRadius: 6,
                            borderSkipped: false
                        }, {
                            label: 'Completed',
                            data: completedData,
                            backgroundColor: 'rgba(16, 185, 129, 0.8)',
                            borderColor: '#10b981',
                            borderWidth: 1,
                            borderRadius: 6,
                            borderSkipped: false
                        }, {
                            label: 'Overdue',
                            data: overdueData,
                            backgroundColor: 'rgba(239, 68, 68, 0.8)',
                            borderColor: '#ef4444',
                            borderWidth: 1,
                            borderRadius: 6,
                            borderSkipped: false
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    usePointStyle: true,
                                    padding: 20,
                                    color: isDark ? '#b3b3b3' : '#666666'
                                }
                            },
                            tooltip: {
                                backgroundColor: isDark ? '#2d2d2d' : '#ffffff',
                                titleColor: isDark ? '#ffffff' : '#1a1a1a',
                                bodyColor: isDark ? '#b3b3b3' : '#666666',
                                borderColor: isDark ? '#404040' : '#e0e0e0',
                                borderWidth: 1,
                                cornerRadius: 8
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    color: isDark ? '#b3b3b3' : '#666666'
                                }
                            },
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: isDark ? '#404040' : '#e0e0e0'
                                },
                                ticks: {
                                    color: isDark ? '#b3b3b3' : '#666666',
                                    precision: 0
                                }
                            }
                        }
                    }
                });
            }
            
            updateChartsForTheme() {
                const isDark = this.currentTheme === 'dark';
                
                // Update Chart.js defaults
                Chart.defaults.color = isDark ? '#b3b3b3' : '#666666';
                Chart.defaults.borderColor = isDark ? '#404040' : '#e0e0e0';
                
                // Update existing charts
                Object.values(this.charts).forEach(chart => {
                    if (chart && chart.options) {
                        // Update legend colors
                        if (chart.options.plugins && chart.options.plugins.legend) {
                            chart.options.plugins.legend.labels.color = isDark ? '#b3b3b3' : '#666666';
                        }
                        
                        // Update tooltip colors
                        if (chart.options.plugins && chart.options.plugins.tooltip) {
                            chart.options.plugins.tooltip.backgroundColor = isDark ? '#2d2d2d' : '#ffffff';
                            chart.options.plugins.tooltip.titleColor = isDark ? '#ffffff' : '#1a1a1a';
                            chart.options.plugins.tooltip.bodyColor = isDark ? '#b3b3b3' : '#666666';
                            chart.options.plugins.tooltip.borderColor = isDark ? '#404040' : '#e0e0e0';
                        }
                        
                        // Update scale colors
                        if (chart.options.scales) {
                            Object.values(chart.options.scales).forEach(scale => {
                                if (scale.ticks) {
                                    scale.ticks.color = isDark ? '#b3b3b3' : '#666666';
                                }
                                if (scale.grid) {
                                    scale.grid.color = isDark ? '#404040' : '#e0e0e0';
                                }
                            });
                        }
                        
                        chart.update();
                    }
                });
            }
            
            navigateCalendar(direction) {
                this.currentMonth += direction;
                
                if (this.currentMonth > 12) {
                    this.currentMonth = 1;
                    this.currentYear++;
                } else if (this.currentMonth < 1) {
                    this.currentMonth = 12;
                    this.currentYear--;
                }
                
                this.updateCalendar();
            }
            
            goToToday() {
                const today = new Date();
                this.currentMonth = today.getMonth() + 1;
                this.currentYear = today.getFullYear();
                this.updateCalendar();
            }
            
            updateCalendar() {
                // Update calendar title
                const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                    'July', 'August', 'September', 'October', 'November', 'December'];
                document.querySelector('.calendar-title').textContent = 
                    `${monthNames[this.currentMonth - 1]} ${this.currentYear}`;
                
                // Fetch new calendar data via AJAX
                fetch(`get-calendar-data.php?month=${this.currentMonth}&year=${this.currentYear}`)
                    .then(response => response.json())
                    .then(data => {
                        this.renderCalendar(data);
                    })
                    .catch(error => {
                        console.error('Error fetching calendar data:', error);
                    });
            }
            
            renderCalendar(calendarData) {
                const calendarGrid = document.querySelector('.calendar-grid');
                const dayHeaders = calendarGrid.querySelectorAll('.calendar-day-header');
                
                // Remove existing day elements
                calendarGrid.querySelectorAll('.calendar-day').forEach(el => el.remove());
                
                // Add new day elements
                calendarData.forEach(day => {
                    const dayElement = document.createElement('div');
                    dayElement.className = `calendar-day ${!day.is_current_month ? 'other-month' : ''} ${day.is_today ? 'today' : ''}`;
                    dayElement.dataset.date = day.date;
                    
                    dayElement.innerHTML = `
                        <div class="calendar-day-number">${day.day}</div>
                        ${day.events.map(event => `
                            <div class="calendar-event ${event.priority === 'High' ? 'high-priority' : ''}" 
                                 title="${event.title}">
                                ${event.title.length > 20 ? event.title.substring(0, 20) + '...' : event.title}
                            </div>
                        `).join('')}
                    `;
                    
                    dayElement.addEventListener('click', () => {
                        this.showDayDetails(day.date);
                    });
                    
                    calendarGrid.appendChild(dayElement);
                });
            }
            
            showDayDetails(date) {
                // Show modal or sidebar with day details
                console.log('Show details for date:', date);
                // Implementation for showing day details would go here
            }
            
            updateUsageChart(period) {
                // Fetch new data for the selected period
                fetch(`get-usage-data.php?period=${period}`)
                    .then(response => response.json())
                    .then(data => {
                        this.charts.usage.data.labels = data.labels;
                        this.charts.usage.data.datasets[0].data = data.usage_count;
                        this.charts.usage.data.datasets[1].data = data.unique_equipment;
                        this.charts.usage.update();
                    })
                    .catch(error => {
                        console.error('Error fetching usage data:', error);
                    });
            }
            
            performSearch(query) {
                if (query.trim()) {
                    window.location.href = `search.php?q=${encodeURIComponent(query)}`;
                }
            }
            
            refreshDashboardData() {
                const refreshBtn = document.getElementById('refresh-data');
                const originalText = refreshBtn.innerHTML;
                
                refreshBtn.innerHTML = '<div class="spinner"></div> Refreshing...';
                refreshBtn.disabled = true;
                
                // Simulate data refresh
                setTimeout(() => {
                    location.reload();
                }, 1500);
            }
            
            setupRealTimeUpdates() {
                // Set up periodic updates for real-time data
                setInterval(() => {
                    this.updateNotificationCount();
                    this.updateQuickStats();
                }, 30000); // Update every 30 seconds
            }
            
            updateNotificationCount() {
                fetch('get-notification-count.php')
                    .then(response => response.json())
                    .then(data => {
                        const badge = document.querySelector('.notification-badge');
                        if (data.count > 0) {
                            if (!badge) {
                                const bell = document.getElementById('notification-bell');
                                const newBadge = document.createElement('span');
                                newBadge.className = 'notification-badge';
                                newBadge.textContent = data.count;
                                bell.appendChild(newBadge);
                            } else {
                                badge.textContent = data.count;
                            }
                        } else if (badge) {
                            badge.remove();
                        }
                    })
                    .catch(error => {
                        console.error('Error updating notification count:', error);
                    });
            }
            
            updateQuickStats() {
                fetch('get-quick-stats.php')
                    .then(response => response.json())
                    .then(data => {
                        // Update stat cards with new data
                        document.querySelectorAll('.stat-card-value').forEach((element, index) => {
                            if (data.stats && data.stats[index]) {
                                element.textContent = data.stats[index].value;
                            }
                        });
                    })
                    .catch(error => {
                        console.error('Error updating quick stats:', error);
                    });
            }
            
            setupNotifications() {
                // Set up notification dropdown functionality
                const notificationBell = document.getElementById('notification-bell');
                const notificationDropdown = document.getElementById('notification-dropdown');
                
                if (notificationBell && notificationDropdown) {
                    notificationBell.addEventListener('click', (e) => {
                        e.stopPropagation();
                        notificationDropdown.classList.toggle('show');
                    });
                    
                    // Close dropdown when clicking outside
                    document.addEventListener('click', (e) => {
                        if (!notificationBell.contains(e.target)) {
                            notificationDropdown.classList.remove('show');
                        }
                    });
                }
            }
        }
        
        // Initialize dashboard when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            window.dashboard = new EquipmentDashboard();
            
            // Add smooth scrolling for anchor links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });
            
            // Add loading states for buttons
            document.querySelectorAll('.btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    if (this.type === 'submit' || this.dataset.loading === 'true') {
                        const originalText = this.innerHTML;
                        this.innerHTML = '<div class="spinner"></div> Loading...';
                        this.disabled = true;
                        
                        // Re-enable after 3 seconds (adjust as needed)
                        setTimeout(() => {
                            this.innerHTML = originalText;
                            this.disabled = false;
                        }, 3000);
                    }
                });
            });
        });
        
        // Export functions for global access
        window.toggleTheme = () => window.dashboard.toggleTheme();
        window.refreshData = () => window.dashboard.refreshDashboardData();
        window.navigateCalendar = (direction) => window.dashboard.navigateCalendar(direction);
    </script>
</body>
</html>