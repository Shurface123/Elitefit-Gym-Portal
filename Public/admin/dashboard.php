<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Admin role to access this page
requireRole('Admin', '../login.php');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Include dashboard data provider
require_once __DIR__ . '/dashboard-data.php';

// Get all dashboard data
$dashboardData = getDashboardData();

// Extract data
$userStats = $dashboardData['userStats'];
$recentUsers = $dashboardData['recentUsers'];
$loginLogs = $dashboardData['loginLogs'];
$monthlyRegistrations = $dashboardData['monthlyRegistrations'];
$equipmentStatus = $dashboardData['equipmentStatus'];
$todaysSessions = $dashboardData['todaysSessions'];
$monthlyRevenue = $dashboardData['monthlyRevenue'];
$unreadNotifications = $dashboardData['unreadNotifications'];

// Format chart data
$chartData = formatChartData($monthlyRegistrations);
$chartLabels = $chartData['labels'];
$chartData = $chartData['data'];

// Calculate equipment operational percentage
$equipmentPercentage = isset($equipmentStatus['operational_percentage']) ? 
    $equipmentStatus['operational_percentage'] : 98;

// Get theme preference
$theme = isset($_COOKIE['admin_theme']) ? $_COOKIE['admin_theme'] : 'dark';
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #f97316; /* Indigo-500 */
            --primary-light: #fb923c; /* Indigo-400 */
            --primary-dark: #ea580c; /* Indigo-600 */
            --secondary: #212529; /* Slate-700 */
            --light: #f8f9fa;
            --dark: #212529; /* Slate-900 */
            --success: #10b981; /* Emerald-500 */
            --success-light: #34d399; /* Emerald-400 */
            --danger: #ef4444; /* Red-500 */
            --danger-light: #f87171; /* Red-400 */
            --warning: #f59e0b; /* Amber-500 */
            --warning-light: #fbbf24; /* Amber-400 */
            --info: #0ea5e9; /* Sky-500 */
            --info-light: #38bdf8; /* Sky-400 */
            --border-radius: 0.75rem; /* Rounded-lg */
            --font-family: 'Poppins', sans-serif;
            --transition-speed: 0.3s;
        }

        [data-theme="light"] {
            --bg-color: #f9fafb; /* Gray-50 */
            --text-color: #1e293b; /* Gray-800 */
            --text-muted: #64748b; /* Gray-500 */
            --card-bg: #ffffff;
            --card-hover: #f8fafc; /* Slate-50 */
            --border-color: #e5e7eb; /* Gray-200 */
            --sidebar-bg: #ffffff;
            --sidebar-text: #1e293b;
            --sidebar-hover: #f1f5f9; /* Slate-100 */
            --header-bg: #ffffff;
            --table-header-bg: #f1f5f9; /* Slate-100 */
            --table-hover: #f8fafc; /* Slate-50 */
            --shadow-color: #212529(0, 0, 0, 0.05);
            --shadow-color-hover: #212529(0, 0, 0, 0.1);
            --modal-overlay: #212529(0, 0, 0, 0.4);
        }

        [data-theme="dark"] {
            --bg-color: #0f172a; /* Slate-900 */
            --text-color: #e2e8f0; /* Slate-200 */
            --text-muted: #94a3b8; /* Slate-400 */
            --card-bg: #212529; /* Slate-800 */
            --card-hover: #212529; /* Slate-700 */
            --border-color: #212529; /* Slate-700 */
            --sidebar-bg: #212529; /* Slate-800 */
            --sidebar-text: #e2e8f0; /* Slate-200 */
            --sidebar-hover: #212529; /* Slate-700 */
            --header-bg: #212529; /* Slate-800 */
            --table-header-bg: #212529; /* Slate-700 */
            --table-hover: #1e293b; /* Slate-800 */
            --shadow-color: #212529(0, 0, 0, 0.2);
            --shadow-color-hover: #212529(0, 0, 0, 0.3);
            --modal-overlay: #212529(0, 0, 0, 0.6);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-family);
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color var(--transition-speed) ease, 
                        color var(--transition-speed) ease;
            line-height: 1.6;
            overflow-x: hidden;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background-color: var(--sidebar-bg);
            color: var(--sidebar-text);
            padding: 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all var(--transition-speed) ease;
            border-right: 1px solid var(--border-color);
            z-index: 1000;
            box-shadow: 3px 0 10px var(--shadow-color);
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            margin-left: 10px;
            color: var(--primary);
            font-weight: 700;
            letter-spacing: 0.5px;
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
            color: var(--sidebar-text);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: all var(--transition-speed) ease;
            position: relative;
            overflow: hidden;
        }

        .sidebar-menu a::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background-color: var(--primary);
            transform: scaleY(0);
            transition: transform 0.2s ease;
        }

        .sidebar-menu a:hover::before,
        .sidebar-menu a.active::before {
            transform: scaleY(1);
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background-color: var(--sidebar-hover);
            color: var(--primary);
            transform: translateX(5px);
        }

        .sidebar-menu a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
            transition: transform 0.3s ease;
        }

        .sidebar-menu a:hover i,
        .sidebar-menu a.active i {
            transform: scale(1.2);
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 20px;
            transition: margin-left var(--transition-speed) ease;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background-color: var(--header-bg);
            padding: 15px 20px;
            border-radius: var(--border-radius);
            box-shadow: 0 5px 15px var(--shadow-color);
            transition: box-shadow var(--transition-speed) ease;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--success), var(--warning), var(--danger));
        }

        .header:hover {
            box-shadow: 0 8px 25px var(--shadow-color-hover);
        }

        .header h1 {
            font-size: 1.8rem;
            color: var(--text-color);
            font-weight: 600;
        }

        .header p {
            color: var(--text-muted);
            margin-top: 5px;
        }

        .user-info {
            display: flex;
            align-items: center;
        }

        .user-info img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
            object-fit: cover;
            border: 2px solid var(--primary);
            transition: transform 0.3s ease, border-color 0.3s ease;
        }

        .user-info img:hover {
            transform: scale(1.1);
            border-color: var(--primary-light);
        }

        .user-info .dropdown {
            position: relative;
        }

        .user-info .dropdown-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
            color: var(--text-color);
            padding: 5px 10px;
            border-radius: var(--border-radius);
            transition: background-color 0.3s ease;
        }

        .user-info .dropdown-toggle:hover {
            background-color: var(--sidebar-hover);
        }

        .user-info .dropdown-toggle i {
            margin-left: 5px;
            transition: transform 0.3s ease;
        }

        .user-info .dropdown-toggle:hover i {
            transform: rotate(180deg);
        }

        .user-dropdown-menu {
            position: absolute;
            right: 0;
            top: 100%;
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: 0 5px 15px var(--shadow-color);
            padding: 10px 0;
            min-width: 180px;
            z-index: 1000;
            display: none;
            border: 1px solid var(--border-color);
            transform-origin: top right;
            transform: scale(0.95);
            opacity: 0;
            transition: transform 0.2s ease, opacity 0.2s ease;
        }

        .user-dropdown-menu.show {
            display: block;
            transform: scale(1);
            opacity: 1;
        }

        .user-dropdown-menu a {
            display: flex;
            align-items: center;
            padding: 8px 20px;
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.3s;
        }

        .user-dropdown-menu a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        .user-dropdown-menu a:hover {
            background-color: var(--sidebar-hover);
            padding-left: 25px;
        }

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px var(--shadow-color);
            padding: 20px;
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
        }

        .card::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary), transparent);
            opacity: 0.7;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px var(--shadow-color-hover);
            background-color: var(--card-hover);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .card-header h3 {
            font-size: 1.2rem;
            color: var(--text-color);
            font-weight: 600;
        }

        .card-body {
            color: var(--text-color);
        }

        .card-body h2 {
            font-size: 2rem;
            margin-bottom: 5px;
            font-weight: 700;
            color: var(--primary);
        }

        .card-body p {
            color: var(--text-muted);
            margin-bottom: 15px;
        }

        .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card:hover .card-icon {
            transform: scale(1.1) rotate(10deg);
            box-shadow: 0 5px 15px #212529(0, 0, 0, 0.2);
        }

        .card-icon.members {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .card-icon.trainers {
            background: linear-gradient(135deg, var(--success), var(--success-light));
        }

        .card-icon.equipment {
            background: linear-gradient(135deg, var(--warning), var(--warning-light));
        }

        .card-icon.total {
            background: linear-gradient(135deg, var(--info), var(--info-light));
        }

        .chart-container {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px var(--shadow-color);
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .chart-container:hover {
            box-shadow: 0 10px 20px var(--shadow-color-hover);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-header h3 {
            font-size: 1.2rem;
            color: var(--text-color);
            font-weight: 600;
        }

        .chart-body {
            position: relative;
            height: 300px;
        }

        .user-list,
        .log-list {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px var(--shadow-color);
            overflow: hidden;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .user-list:hover,
        .log-list:hover {
            box-shadow: 0 10px 20px var(--shadow-color-hover);
        }

        .user-list-header,
        .log-list-header {
            padding: 15px 20px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .user-list-header h3,
        .log-list-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .user-list-body,
        .log-list-body {
            padding: 0;
        }

        .table-container {
            overflow-x: auto;
            width: 100%;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            border-spacing: 0;
        }

        .table th,
        .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .table th {
            font-weight: 600;
            color: var(--text-color);
            background-color: var(--table-header-bg);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table tbody tr {
            transition: background-color 0.3s ease;
        }

        .table tbody tr:hover {
            background-color: var(--table-hover);
        }

        .table td {
            vertical-align: middle;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 500;
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 5px #212529(0, 0, 0, 0.1);
        }

        .badge-success {
            background: linear-gradient(135deg, var(--success), var(--success-light));
        }

        .badge-danger {
            background: linear-gradient(135deg, var(--danger), var(--danger-light));
        }

        .badge-warning {
            background: linear-gradient(135deg, var(--warning), var(--warning-light));
        }

        .badge-info {
            background: linear-gradient(135deg, var(--info), var(--info-light));
        }

        .badge-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .btn {
            padding: 8px 15px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
            box-shadow: 0 2px 5px #212529(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: #212529(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%);
            transform-origin: 50% 50%;
        }

        .btn:hover::after {
            animation: ripple 1s ease-out;
        }

        @keyframes ripple {
            0% {
                transform: scale(0, 0);
                opacity: 0.5;
            }
            100% {
                transform: scale(20, 20);
                opacity: 0;
            }
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 10px #212529(0, 0, 0, 0.2);
        }

        .btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 5px #212529(0, 0, 0, 0.1);
        }

        .btn i {
            margin-right: 5px;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.9rem;
        }

        .btn-info {
            background-color: var(--info);
        }

        .btn-info:hover {
            background-color: var(--info-light);
        }

        .btn-warning {
            background-color: var(--warning);
        }

        .btn-warning:hover {
            background-color: var(--warning-light);
        }

        .btn-danger {
            background-color: var(--danger);
        }

        .btn-danger:hover {
            background-color: var(--danger-light);
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .theme-switch-wrapper {
            display: flex;
            align-items: center;
            margin-left: 20px;
        }

        .theme-switch {
            display: inline-block;
            position: relative;
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
            background-color: var(--primary);
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        .slider-icons {
            display: flex;
            justify-content: space-between;
            padding: 0 10px;
            align-items: center;
            height: 100%;
            color: white;
        }

        /* Quick Stats Section */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 15px;
            display: flex;
            align-items: center;
            box-shadow: 0 2px 10px var(--shadow-color);
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px var(--shadow-color-hover);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: white;
            font-size: 1.2rem;
        }

        .stat-icon.purple {
            background: linear-gradient(135deg, #d97706, #d97706);
        }

        .stat-icon.blue {
            background: linear-gradient(135deg, #2563eb, #3b82f6);
        }

        .stat-icon.green {
            background: linear-gradient(135deg, #059669, #10b981); 
        }

        .stat-icon.orange {
            background: linear-gradient(135deg, #d97706, #f59e0b);
        }

        .stat-info h4 {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 5px;
        }

        .stat-info p {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: var(--modal-overlay);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal.show {
            opacity: 1;
        }

        .modal-content {
            background-color: var(--card-bg);
            margin: 15% auto;
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            width: 50%;
            max-width: 500px;
            box-shadow: 0 4px 20px #212529(0, 0, 0, 0.2);
            transform: translateY(-20px);
            opacity: 0;
            transition: all 0.3s ease;
        }

        .modal.show .modal-content {
            transform: translateY(0);
            opacity: 1;
        }

        .modal-header {
            margin-bottom: 20px;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }

        .modal-body {
            margin-bottom: 20px;
            color: var(--text-color);
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            border-top: 1px solid var(--border-color);
            padding-top: 15px;
        }

        /* Loader Animation */
        .loader {
            display: inline-block;
            width: 30px;
            height: 30px;
            border: 3px solid #212529(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Notification Badge */
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
            font-weight: bold;
        }

        /* Responsive Styles */
        @media (max-width: 992px) {
            .dashboard-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .modal-content {
                width: 70%;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                padding: 20px 10px;
                transform: translateX(0);
            }

            .sidebar.expanded {
                width: 250px;
                transform: translateX(0);
            }

            .sidebar-header h2,
            .sidebar-menu a span {
                display: none;
            }

            .sidebar.expanded .sidebar-header h2,
            .sidebar.expanded .sidebar-menu a span {
                display: inline;
            }

            .main-content {
                margin-left: 70px;
            }

            .dashboard-cards {
                grid-template-columns: 1fr;
            }

            .quick-stats {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
            }

            .user-info {
                margin-top: 15px;
                width: 100%;
                justify-content: space-between;
            }

            .theme-switch-wrapper {
                margin-left: 0;
                margin-top: 15px;
            }
            
            .modal-content {
                width: 90%;
                margin: 30% auto;
            }
        }

        @media (max-width: 576px) {
            .action-buttons {
                flex-direction: column;
                gap: 5px;
            }
            
            .table th, 
            .table td {
                padding: 8px 10px;
            }
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1100;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 5px;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 10px #212529(0, 0, 0, 0.1);
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding-top: 60px;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-dumbbell fa-2x" style="color: var(--primary);"></i>
                <h2>EliteFit Gym</h2>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php" class="active"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="users.php"><i class="fas fa-users"></i> <span>Users</span></a></li>
                <li><a href="trainers.php"><i class="fas fa-user-tie"></i> <span>Trainers</span></a></li>
                <li><a href="equipment-managers.php"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
                <li><a href="reports.php"><i class="fas fa-chart-line"></i> <span>Reports</span></a></li>
                <li><a href="admin_settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>Admin Dashboard</h1>
                    <p>Welcome back, <?php echo htmlspecialchars($userName); ?>!</p>
                </div>
                <div class="user-info">
                    <img src="https://randomuser.me/api/portraits/women/1.jpg" alt="User Avatar">
                    <div class="dropdown">
                        <div class="dropdown-toggle" onclick="toggleDropdown()">
                            <span><?php echo htmlspecialchars($userName); ?></span>
                            <i class="fas fa-chevron-down ml-2"></i>
                        </div>
                        <div class="user-dropdown-menu" id="userDropdown">
                            <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                            <a href="admin_settings.php"><i class="fas fa-cog"></i> Settings</a>
                            <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>

                    <div class="theme-switch-wrapper">
                        <label class="theme-switch" for="checkbox">
                            <input type="checkbox" id="checkbox" <?php echo $theme === 'dark' ? 'checked' : ''; ?>>
                            <div class="slider">
                                <div class="slider-icons">
                                    <i class="fas fa-sun"></i>
                                    <i class="fas fa-moon"></i>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="quick-stats">
                <div class="stat-card">
                    <div class="stat-icon purple">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Active Members</h4>
                        <p><?php echo $userStats['member_count']; ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Today's Sessions</h4>
                        <p><?php echo isset($todaysSessions['total_sessions']) ? $todaysSessions['total_sessions'] : 0; ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Revenue (Month)</h4>
                        <p>$<?php echo isset($monthlyRevenue['monthly_revenue']) ? number_format($monthlyRevenue['monthly_revenue'], 2) : '0.00'; ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange">
                        <i class="fas fa-dumbbell"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Equipment Status</h4>
                        <p><?php echo $equipmentPercentage; ?>% Operational</p>
                    </div>
                </div>
            </div>

            <!-- Dashboard Cards -->
            <div class="dashboard-cards">
                <div class="card">
                    <div class="card-header">
                        <h3>Members</h3>
                        <div class="card-icon members">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="card-body">
                        <h2><?php echo $userStats['member_count']; ?></h2>
                        <p>Total Members</p>
                        <a href="users.php?role=Member" class="btn btn-sm"><i class="fas fa-eye"></i> View All</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Trainers</h3>
                        <div class="card-icon trainers">
                            <i class="fas fa-user-tie"></i>
                        </div>
                    </div>
                    <div class="card-body">
                        <h2><?php echo $userStats['trainer_count']; ?></h2>
                        <p>Total Trainers</p>
                        <a href="trainers.php" class="btn btn-sm"><i class="fas fa-eye"></i> View All</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Equipment Managers</h3>
                        <div class="card-icon equipment">
                            <i class="fas fa-tools"></i>
                        </div>
                    </div>
                    <div class="card-body">
                        <h2><?php echo $userStats['equipment_manager_count']; ?></h2>
                        <p>Total Equipment Managers</p>
                        <a href="equipment-managers.php" class="btn btn-sm"><i class="fas fa-eye"></i> View All</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Total Users</h3>
                        <div class="card-icon total">
                            <i class="fas fa-user-friends"></i>
                        </div>
                    </div>
                    <div class="card-body">
                        <h2><?php echo $userStats['total_users']; ?></h2>
                        <p>All Users</p>
                        <a href="users.php" class="btn btn-sm"><i class="fas fa-eye"></i> View All</a>
                    </div>
                </div>
            </div>

            <!-- User Registration Chart -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3>User Registrations (Last 6 Months)</h3>
                    <div>
                        <button class="btn btn-sm" id="refreshChartBtn">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
                <div class="chart-body">
                    <canvas id="registrationChart"></canvas>
                </div>
            </div>

            <!-- Recent Users -->
            <div class="user-list">
                <div class="user-list-header">
                    <h3>Recent Users</h3>
                    <a href="users.php" class="btn btn-sm"><i class="fas fa-users"></i> View All Users</a>
                </div>
                <div class="user-list-body">
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php 
$hasRecentUsers = false;
if (isset($recentUsers) && is_array($recentUsers)) {
    foreach ($recentUsers as $user) {
        $hasRecentUsers = true;
        break; // We only need to check if there's at least one element
    }
}
?>

<?php if ($hasRecentUsers): ?>
    <?php foreach ($recentUsers as $user): ?>
        <tr>
            <td><?php echo htmlspecialchars($user['name']); ?></td>
            <td><?php echo htmlspecialchars($user['email']); ?></td>
            <td>
                <?php 
                    $badgeClass = '';
                    switch ($user['role']) {
                        case 'Admin':
                            $badgeClass = 'badge-danger';
                            break;
                        case 'Trainer':
                            $badgeClass = 'badge-success';
                            break;
                        case 'EquipmentManager':
                            $badgeClass = 'badge-warning';
                            break;
                        default:
                            $badgeClass = 'badge-info';
                    }
                ?>
                <span class="badge <?php echo $badgeClass; ?>">
                    <?php echo htmlspecialchars($user['role']); ?>
                </span>
            </td>
            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
            <td class="action-buttons">
                <a href="view-user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-info" title="View"><i class="fas fa-eye"></i></a>
                <a href="edit-user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                <?php if ($user['role'] !== 'Admin'): ?>
                    <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')" class="btn btn-sm btn-danger" title="Delete"><i class="fas fa-trash"></i></a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr>
        <td colspan="5">No users found.</td>
    </tr>
<?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Login Logs -->
            <div class="log-list">
                <div class="log-list-header">
                    <h3>Recent Login Activity</h3>
                    <a href="login-logs.php" class="btn btn-sm"><i class="fas fa-history"></i> View All Logs</a>
                </div>
                <div class="log-list-body">
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>IP Address</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php 
$hasLoginLogs = false;
if (isset($loginLogs) && is_array($loginLogs)) {
    foreach ($loginLogs as $log) {
        $hasLoginLogs = true;
        break; // We only need to check if there's at least one element
    }
}
?>

<?php if ($hasLoginLogs): ?>
    <?php foreach ($loginLogs as $log): ?>
        <tr>
            <td><?php echo htmlspecialchars($log['email']); ?></td>
            <td>
                <span class="badge <?php echo $log['success'] ? 'badge-success' : 'badge-danger'; ?>">
                    <?php echo $log['success'] ? 'Success' : 'Failed'; ?>
                </span>
            </td>
            <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
            <td><?php echo date('M d, Y H:i', strtotime($log['timestamp'])); ?></td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr>
        <td colspan="4">No login logs found.</td>
    </tr>
<?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Deletion</h3>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <span id="deleteUserName"></span>? This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button onclick="closeDeleteModal()" class="btn" style="background-color: var(--secondary);">Cancel</button>
                <form id="deleteForm" action="delete-user.php" method="post">
                    <input type="hidden" id="deleteUserId" name="user_id">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Toggle dropdown menu
        function toggleDropdown() {
            document.getElementById('userDropdown').classList.toggle('show');
        }

        // Close dropdown when clicking outside
        window.onclick = function(event) {
            if (!event.target.matches('.dropdown-toggle') && !event.target.matches('.dropdown-toggle *')) {
                var dropdowns = document.getElementsByClassName('user-dropdown-menu');
                for (var i = 0; i < dropdowns.length; i++) {
                    var openDropdown = dropdowns[i];
                    if (openDropdown.classList.contains('show')) {
                        openDropdown.classList.remove('show');
                    }
                }
            }
        }

        // Theme switcher
        const themeSwitch = document.getElementById('checkbox');
        themeSwitch.addEventListener('change', function() {
            if(this.checked) {
                document.documentElement.setAttribute('data-theme', 'dark');
                setCookie('admin_theme', 'dark', 30);
            } else {
                document.documentElement.setAttribute('data-theme', 'light');
                setCookie('admin_theme', 'light', 30);
            }
        });

        // Set cookie function
        function setCookie(name, value, days) {
            var expires = "";
            if (days) {
                var date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = "; expires=" + date.toUTCString();
            }
            document.cookie = name + "=" + (value || "") + expires + "; path=/";
        }

        // Delete user confirmation
        function confirmDelete(userId, userName) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserName').textContent = userName;
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('deleteModal').classList.add('show');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
            setTimeout(() => {
                document.getElementById('deleteModal').style.display = 'none';
            }, 300);
        }

        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });

        // Registration Chart
        const ctx = document.getElementById('registrationChart').getContext('2d');
        const registrationChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [{
                    label: 'New Users',
                    data: <?php echo json_encode($chartData); ?>,
                    backgroundColor: '#212529(99, 102, 241, 0.2)',
                    borderColor: '#ea580c(99, 102, 241, 1)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointBackgroundColor: '#ea580c(99, 102, 241, 1)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--card-bg'),
                        titleColor: getComputedStyle(document.documentElement).getPropertyValue('--text-color'),
                        bodyColor: getComputedStyle(document.documentElement).getPropertyValue('--text-color'),
                        borderColor: getComputedStyle(document.documentElement).getPropertyValue('--border-color'),
                        borderWidth: 1,
                        padding: 10,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return `New Users: ${context.raw}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                        },
                        ticks: {
                            color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                        }
                    },
                    x: {
                        grid: {
                            color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                        },
                        ticks: {
                            color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index',
                },
                animation: {
                    duration: 1000,
                    easing: 'easeOutQuart'
                }
            }
        });

        // Refresh chart button
        document.getElementById('refreshChartBtn').addEventListener('click', function() {
            this.innerHTML = '<span class="loader"></span>';
            setTimeout(() => {
                registrationChart.update();
                this.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh';
            }, 1000);
        });

        // Update chart colors when theme changes
        themeSwitch.addEventListener('change', function() {
            setTimeout(() => {
                registrationChart.options.plugins.tooltip.backgroundColor = getComputedStyle(document.documentElement).getPropertyValue('--card-bg');
                registrationChart.options.plugins.tooltip.titleColor = getComputedStyle(document.documentElement).getPropertyValue('--text-color');
                registrationChart.options.plugins.tooltip.bodyColor = getComputedStyle(document.documentElement).getPropertyValue('--text-color');
                registrationChart.options.plugins.tooltip.borderColor = getComputedStyle(document.documentElement).getPropertyValue('--border-color');
                registrationChart.options.plugins.legend.labels.color = getComputedStyle(document.documentElement).getPropertyValue('--text-color');
                registrationChart.options.scales.y.grid.color = getComputedStyle(document.documentElement).getPropertyValue('--border-color');
                registrationChart.options.scales.x.grid.color = getComputedStyle(document.documentElement).getPropertyValue('--border-color');
                registrationChart.options.scales.y.ticks.color = getComputedStyle(document.documentElement).getPropertyValue('--text-color');
                registrationChart.options.scales.x.ticks.color = getComputedStyle(document.documentElement).getPropertyValue('--text-color');
                registrationChart.update();
            }, 300);
        });
    </script>
</body>
</html>
