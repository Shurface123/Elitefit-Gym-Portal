<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Admin role to access this page
requireRole('Admin');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Connect to database
$conn = connectDB();

// Get archived users with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$roleFilter = isset($_GET['role']) ? $_GET['role'] : '';

try {
    // Check if archived_users table exists
    $checkTableStmt = $conn->prepare("
        SELECT COUNT(*) as table_exists 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'archived_users'
    ");
    $checkTableStmt->execute();
    $tableExists = $checkTableStmt->fetch(PDO::FETCH_ASSOC)['table_exists'];
    
    if (!$tableExists) {
        $archivedUsers = [];
        $totalUsers = 0;
    } else {
        // Build WHERE clause for search and filter
        $whereClause = "WHERE 1=1";
        $params = [];
        
        if (!empty($search)) {
            $whereClause .= " AND (name LIKE ? OR email LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($roleFilter)) {
            $whereClause .= " AND role = ?";
            $params[] = $roleFilter;
        }
        
        // Get total count for pagination
        $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM archived_users $whereClause");
        $countStmt->execute($params);
        $totalUsers = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get archived users with pagination
        $stmt = $conn->prepare("
            SELECT au.*, u.name as archived_by_name 
            FROM archived_users au
            LEFT JOIN users u ON au.archived_by = u.id
            $whereClause
            ORDER BY au.archived_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $archivedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Calculate pagination
    $totalPages = ceil($totalUsers / $limit);
    
} catch (Exception $e) {
    $_SESSION['error_message'] = "Error retrieving archived users: " . $e->getMessage();
    $archivedUsers = [];
    $totalUsers = 0;
    $totalPages = 0;
}

// Get theme preference
$theme = isset($_COOKIE['admin_theme']) ? $_COOKIE['admin_theme'] : 'dark';
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Users - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: #f97316; /* Orange-500 */
            --primary-light: #fb923c; /* Orange-400 */
            --primary-dark: #ea580c; /* Orange-600 */
            --secondary: #1f2937; /* Gray-800 */
            --light: #f8f9fa;
            --dark: #111827; /* Gray-900 */
            --success: #10b981; /* Emerald-500 */
            --success-light: #34d399; /* Emerald-400 */
            --danger: #ef4444; /* Red-500 */
            --danger-light: #f87171; /* Red-400 */
            --warning: #f59e0b; /* Amber-500 */
            --warning-light: #fbbf24; /* Amber-400 */
            --info: #0ea5e9; /* Sky-500 */
            --info-light: #38bdf8; /* Sky-400 */
            --border-radius: 0.75rem;
            --font-family: 'Poppins', sans-serif;
            --transition-speed: 0.3s;
        }

        [data-theme="light"] {
            --bg-color: #f9fafb;
            --text-color: #1f2937;
            --text-muted: #6b7280;
            --card-bg: #ffffff;
            --card-hover: #f8fafc;
            --border-color: #e5e7eb;
            --sidebar-bg: #ffffff;
            --sidebar-text: #1f2937;
            --sidebar-hover: #f3f4f6;
            --header-bg: #ffffff;
            --table-header-bg: #f9fafb;
            --table-hover: #f8fafc;
            --shadow-color: rgba(0, 0, 0, 0.05);
            --shadow-color-hover: rgba(0, 0, 0, 0.1);
            --modal-overlay: rgba(0, 0, 0, 0.4);
            --input-bg: #ffffff;
            --input-border: #d1d5db;
        }

        [data-theme="dark"] {
            --bg-color: #0f172a;
            --text-color: #e2e8f0;
            --text-muted: #94a3b8;
            --card-bg: #1e293b;
            --card-hover: #334155;
            --border-color: #334155;
            --sidebar-bg: #1e293b;
            --sidebar-text: #e2e8f0;
            --sidebar-hover: #334155;
            --header-bg: #1e293b;
            --table-header-bg: #334155;
            --table-hover: #334155;
            --shadow-color: rgba(0, 0, 0, 0.2);
            --shadow-color-hover: rgba(0, 0, 0, 0.3);
            --modal-overlay: rgba(0, 0, 0, 0.6);
            --input-bg: #374151;
            --input-border: #4b5563;
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
            background: linear-gradient(90deg, var(--primary), var(--danger));
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

        /* Search and Filter Section */
        .search-filter-section {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px var(--shadow-color);
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .search-filter-section:hover {
            box-shadow: 0 5px 15px var(--shadow-color-hover);
        }

        .search-filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .search-filter-header h3 {
            color: var(--text-color);
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .search-filter-header h3 i {
            margin-right: 10px;
            color: var(--primary);
        }

        .search-filter-form {
            display: grid;
            grid-template-columns: 1fr auto auto auto;
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            color: var(--text-color);
            font-weight: 500;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }

        .form-control {
            padding: 10px 15px;
            border: 1px solid var(--input-border);
            border-radius: var(--border-radius);
            background-color: var(--input-bg);
            color: var(--text-color);
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
        }

        .btn {
            padding: 10px 20px;
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
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
            font-size: 0.9rem;
        }

        .btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
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
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.2);
        }

        .btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .btn i {
            margin-right: 8px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        .btn-secondary {
            background-color: var(--secondary);
        }

        .btn-secondary:hover {
            background-color: #374151;
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

        .btn-success {
            background-color: var(--success);
        }

        .btn-success:hover {
            background-color: var(--success-light);
        }

        /* Statistics Cards */
        .stats-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 20px;
            display: flex;
            align-items: center;
            box-shadow: 0 2px 10px var(--shadow-color);
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px var(--shadow-color-hover);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: white;
            font-size: 1.3rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .stat-info h4 {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 5px;
            font-weight: 500;
        }

        .stat-info p {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-color);
        }

        /* Table Styles */
        .user-list {
            background-color: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px var(--shadow-color);
            overflow: hidden;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .user-list:hover {
            box-shadow: 0 10px 20px var(--shadow-color-hover);
        }

        .user-list-header {
            padding: 20px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .user-list-header h3 {
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .user-list-header h3 i {
            margin-right: 10px;
        }

        .user-list-body {
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
            padding: 15px;
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
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table tbody tr {
            transition: background-color 0.3s ease;
        }

        .table tbody tr:hover {
            background-color: var(--table-hover);
        }

        .table td {
            vertical-align: middle;
            font-size: 0.9rem;
        }

        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
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

        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        /* Pagination */
        .pagination-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: var(--card-bg);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px var(--shadow-color);
            border: 1px solid var(--border-color);
            margin-bottom: 30px;
        }

        .pagination-info {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .pagination {
            display: flex;
            gap: 5px;
            align-items: center;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-color);
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .pagination a:hover {
            background-color: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .pagination .current {
            background-color: var(--primary);
            color: white;
            font-weight: 600;
        }

        .pagination .disabled {
            color: var(--text-muted);
            cursor: not-allowed;
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
            margin: 10% auto;
            padding: 0;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            transform: translateY(-20px);
            opacity: 0;
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .modal.show .modal-content {
            transform: translateY(0);
            opacity: 1;
        }

        .modal-header {
            padding: 20px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-header h3 {
            margin: 0;
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .modal-header h3 i {
            margin-right: 10px;
        }

        .modal-body {
            padding: 20px;
            color: var(--text-color);
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 20px;
            border-top: 1px solid var(--border-color);
            background-color: var(--table-header-bg);
        }

        /* Loading Animation */
        .loader {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--primary);
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: var(--text-color);
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 20px;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            animation: slideInDown 0.3s ease;
        }

        .alert i {
            margin-right: 10px;
            font-size: 1.2rem;
        }

        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-left-color: var(--success);
        }

        .alert-error {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border-left-color: var(--danger);
        }

        .alert-info {
            background-color: rgba(14, 165, 233, 0.1);
            color: var(--info);
            border-left-color: var(--info);
        }

        @keyframes slideInDown {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
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
            border-radius: 8px;
            width: 45px;
            height: 45px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
        }

        .mobile-menu-toggle:hover {
            background-color: var(--primary-dark);
            transform: scale(1.05);
        }

        /* Responsive Styles */
        @media (max-width: 1200px) {
            .search-filter-form {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .search-filter-form .btn {
                justify-self: start;
            }
        }

        @media (max-width: 992px) {
            .stats-section {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .pagination-section {
                flex-direction: column;
                gap: 15px;
            }
        }

        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: flex;
            }
            
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 15px;
                padding-top: 70px;
            }

            .stats-section {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .user-info {
                width: 100%;
                justify-content: space-between;
            }

            .theme-switch-wrapper {
                margin-left: 0;
            }
            
            .table th, 
            .table td {
                padding: 10px 8px;
                font-size: 0.8rem;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 5px;
            }
            
            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
        }

        @media (max-width: 576px) {
            .search-filter-section {
                padding: 15px;
            }
            
            .user-list-header {
                padding: 15px;
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            
            .table-container {
                font-size: 0.8rem;
            }
            
            .badge {
                padding: 4px 8px;
                font-size: 0.7rem;
            }
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--bg-color);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        /* Tooltip */
        .tooltip {
            position: relative;
            display: inline-block;
        }

        .tooltip .tooltiptext {
            visibility: hidden;
            width: 120px;
            background-color: var(--secondary);
            color: white;
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
        }

        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
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
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="users.php"><i class="fas fa-users"></i> <span>Users</span></a></li>
                <li><a href="trainers.php"><i class="fas fa-user-tie"></i> <span>Trainers</span></a></li>
                <li><a href="equipment-managers.php"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
                <li><a href="archived-users.php" class="active"><i class="fas fa-archive"></i> <span>Archived Users</span></a></li>
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
                    <h1><i class="fas fa-archive"></i> Archived Users</h1>
                    <p>View and manage archived user accounts</p>
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

            <!-- Alert Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Section -->
            <div class="stats-section">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-archive"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Total Archived</h4>
                        <p><?php echo $totalUsers; ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Current Page</h4>
                        <p><?php echo $page; ?> of <?php echo max(1, $totalPages); ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-filter"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Showing Results</h4>
                        <p><?php echo count($archivedUsers); ?> of <?php echo $totalUsers; ?></p>
                    </div>
                </div>
            </div>

            <!-- Search and Filter Section -->
            <div class="search-filter-section">
                <div class="search-filter-header">
                    <h3><i class="fas fa-search"></i> Search & Filter</h3>
                    <a href="dashboard.php?tab=archived-users" class="btn btn-secondary btn-sm">
                        <i class="fas fa-chart-bar"></i> View Statistics
                    </a>
                </div>
                <form method="GET" class="search-filter-form">
                    <div class="form-group">
                        <label for="search">Search Users</label>
                        <input type="text" id="search" name="search" class="form-control" 
                               placeholder="Search by name or email..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label for="role">Filter by Role</label>
                        <select id="role" name="role" class="form-control">
                            <option value="">All Roles</option>
                            <option value="Member" <?php echo $roleFilter === 'Member' ? 'selected' : ''; ?>>Member</option>
                            <option value="Trainer" <?php echo $roleFilter === 'Trainer' ? 'selected' : ''; ?>>Trainer</option>
                            <option value="EquipmentManager" <?php echo $roleFilter === 'EquipmentManager' ? 'selected' : ''; ?>>Equipment Manager</option>
                            <option value="Admin" <?php echo $roleFilter === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>
                    <button type="submit" class="btn">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="archived-users.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </form>
            </div>

            <!-- Archived Users List -->
            <?php if (!empty($archivedUsers)): ?>
                <div class="user-list">
                    <div class="user-list-header">
                        <h3><i class="fas fa-archive"></i> Archived Users</h3>
                        <div>
                            <button onclick="exportData()" class="btn btn-success btn-sm">
                                <i class="fas fa-download"></i> Export
                            </button>
                            <button onclick="bulkRestore()" class="btn btn-warning btn-sm" id="bulkRestoreBtn" style="display: none;">
                                <i class="fas fa-undo"></i> Restore Selected
                            </button>
                        </div>
                    </div>
                    <div class="user-list-body">
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>
                                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                        </th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Archived Date</th>
                                        <th>Archived By</th>
                                        <th>Reason</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($archivedUsers as $user): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="user-checkbox" value="<?php echo $user['id']; ?>" onchange="updateBulkActions()">
                                        </td>
                                        <td>
                                            <div class="user-info-cell">
                                                <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                            </div>
                                        </td>
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
                                        <td>
                                            <div class="tooltip">
                                                <?php echo date('M d, Y', strtotime($user['archived_at'])); ?>
                                                <span class="tooltiptext"><?php echo date('M d, Y H:i:s', strtotime($user['archived_at'])); ?></span>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['archived_by_name'] ?? 'Unknown'); ?></td>
                                        <td>
                                            <div class="tooltip">
                                                <?php echo htmlspecialchars(substr($user['archive_reason'], 0, 30)) . (strlen($user['archive_reason']) > 30 ? '...' : ''); ?>
                                                <span class="tooltiptext"><?php echo htmlspecialchars($user['archive_reason']); ?></span>
                                            </div>
                                        </td>
                                        <td class="action-buttons">
                                            <a href="view-archived-user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-info tooltip" title="View Details">
                                                <i class="fas fa-eye"></i>
                                                <span class="tooltiptext">View Details</span>
                                            </a>
                                            <button onclick="confirmRestore(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')" class="btn btn-sm btn-warning tooltip" title="Restore User">
                                                <i class="fas fa-undo"></i>
                                                <span class="tooltiptext">Restore User</span>
                                            </button>
                                            <button onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')" class="btn btn-sm btn-danger tooltip" title="Delete Permanently">
                                                <i class="fas fa-trash"></i>
                                                <span class="tooltiptext">Delete Permanently</span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination-section">
                        <div class="pagination-info">
                            Showing <?php echo (($page - 1) * $limit) + 1; ?> to <?php echo min($page * $limit, $totalUsers); ?> of <?php echo $totalUsers; ?> results
                        </div>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=1<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($roleFilter) ? '&role=' . urlencode($roleFilter) : ''; ?>">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($roleFilter) ? '&role=' . urlencode($roleFilter) : ''; ?>">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="disabled"><i class="fas fa-angle-double-left"></i></span>
                                <span class="disabled"><i class="fas fa-angle-left"></i></span>
                            <?php endif; ?>

                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);
                            
                            for ($i = $start; $i <= $end; $i++):
                            ?>
                                <?php if ($i == $page): ?>
                                    <span class="current"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($roleFilter) ? '&role=' . urlencode($roleFilter) : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($roleFilter) ? '&role=' . urlencode($roleFilter) : ''; ?>">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?page=<?php echo $totalPages; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($roleFilter) ? '&role=' . urlencode($roleFilter) : ''; ?>">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="disabled"><i class="fas fa-angle-right"></i></span>
                                <span class="disabled"><i class="fas fa-angle-double-right"></i></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- Empty State -->
                <div class="user-list">
                    <div class="user-list-body">
                        <div class="empty-state">
                            <i class="fas fa-archive"></i>
                            <h3>No Archived Users Found</h3>
                            <p>
                                <?php if (!empty($search) || !empty($roleFilter)): ?>
                                    No archived users match your search criteria.
                                <?php else: ?>
                                    There are currently no archived users in the system.
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($search) || !empty($roleFilter)): ?>
                                <a href="archived-users.php" class="btn">
                                    <i class="fas fa-times"></i> Clear Filters
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Restore Confirmation Modal -->
    <div id="restoreModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-undo"></i> Restore User</h3>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to restore <strong><span id="restoreUserName"></span></strong>? This will reactivate their account and they will be able to access the system again.</p>
            </div>
            <div class="modal-footer">
                <button onclick="closeRestoreModal()" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <form id="restoreForm" action="restore-user.php" method="get" style="display: inline;">
                    <input type="hidden" id="restoreUserId" name="id">
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-undo"></i> Restore User
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirm Permanent Deletion</h3>
            </div>
            <div class="modal-body">
                <p><strong>Warning:</strong> Are you sure you want to permanently delete <strong><span id="deleteUserName"></span></strong>? This action cannot be undone and all user data will be lost forever.</p>
            </div>
            <div class="modal-footer">
                <button onclick="closeDeleteModal()" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <form id="deleteForm" action="delete-archived-user.php" method="post" style="display: inline;">
                    <input type="hidden" id="deleteUserId" name="user_id">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Permanently
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: var(--card-bg); padding: 30px; border-radius: var(--border-radius); text-align: center;">
            <div class="loader" style="width: 40px; height: 40px; margin-bottom: 15px;"></div>
            <p style="color: var(--text-color); margin: 0;">Processing...</p>
        </div>
    </div>

    <script>
        // Global variables
        let selectedUsers = [];

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

        // Restore user confirmation
        function confirmRestore(userId, userName) {
            document.getElementById('restoreUserId').value = userId;
            document.getElementById('restoreUserName').textContent = userName;
            document.getElementById('restoreModal').style.display = 'block';
            document.getElementById('restoreModal').classList.add('show');
        }

        function closeRestoreModal() {
            document.getElementById('restoreModal').classList.remove('show');
            setTimeout(() => {
                document.getElementById('restoreModal').style.display = 'none';
            }, 300);
        }

        // Delete confirmation
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

        // Select all functionality
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.user-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            
            updateBulkActions();
        }

        // Update bulk actions visibility
        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            const bulkRestoreBtn = document.getElementById('bulkRestoreBtn');
            
            selectedUsers = Array.from(checkboxes).map(cb => cb.value);
            
            if (selectedUsers.length > 0) {
                bulkRestoreBtn.style.display = 'inline-flex';
                bulkRestoreBtn.innerHTML = `<i class="fas fa-undo"></i> Restore Selected (${selectedUsers.length})`;
            } else {
                bulkRestoreBtn.style.display = 'none';
            }
            
            // Update select all checkbox state
            const allCheckboxes = document.querySelectorAll('.user-checkbox');
            const selectAll = document.getElementById('selectAll');
            
            if (selectedUsers.length === 0) {
                selectAll.indeterminate = false;
                selectAll.checked = false;
            } else if (selectedUsers.length === allCheckboxes.length) {
                selectAll.indeterminate = false;
                selectAll.checked = true;
            } else {
                selectAll.indeterminate = true;
                selectAll.checked = false;
            }
        }

        // Bulk restore functionality
        function bulkRestore() {
            if (selectedUsers.length === 0) {
                alert('Please select users to restore.');
                return;
            }
            
            if (confirm(`Are you sure you want to restore ${selectedUsers.length} selected user(s)?`)) {
                showLoading();
                
                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'bulk-restore-users.php';
                
                selectedUsers.forEach(userId => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'user_ids[]';
                    input.value = userId;
                    form.appendChild(input);
                });
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Export functionality
        function exportData() {
            showLoading();
            
            // Get current filters
            const search = new URLSearchParams(window.location.search).get('search') || '';
            const role = new URLSearchParams(window.location.search).get('role') || '';
            
            // Create export URL
            let exportUrl = 'export-archived-users.php?format=csv';
            if (search) exportUrl += '&search=' + encodeURIComponent(search);
            if (role) exportUrl += '&role=' + encodeURIComponent(role);
            
            // Create temporary link and click it
            const link = document.createElement('a');
            link.href = exportUrl;
            link.download = 'archived_users_' + new Date().toISOString().split('T')[0] + '.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            hideLoading();
        }

        // Loading overlay functions
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.animation = 'slideOutUp 0.3s ease';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }, 5000);
            });
        });

        // Search form auto-submit on Enter
        document.getElementById('search').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                this.form.submit();
            }
        });

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const restoreModal = document.getElementById('restoreModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target === restoreModal) {
                closeRestoreModal();
            }
            
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape key to close modals
            if (e.key === 'Escape') {
                closeRestoreModal();
                closeDeleteModal();
            }
            
            // Ctrl/Cmd + A to select all
            if ((e.ctrlKey || e.metaKey) && e.key === 'a' && e.target.tagName !== 'INPUT') {
                e.preventDefault();
                document.getElementById('selectAll').checked = true;
                toggleSelectAll();
            }
        });

        // Smooth scroll to top when pagination changes
        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        // Add click event to pagination links
        document.querySelectorAll('.pagination a').forEach(link => {
            link.addEventListener('click', function() {
                showLoading();
                setTimeout(hideLoading, 1000);
            });
        });

        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="loader"></span> Processing...';
                    
                    // Re-enable after 5 seconds to prevent permanent disable
                    setTimeout(() => {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = submitBtn.getAttribute('data-original-text') || 'Submit';
                    }, 5000);
                }
            });
        });

        // Store original button text
        document.querySelectorAll('button[type="submit"]').forEach(btn => {
            btn.setAttribute('data-original-text', btn.innerHTML);
        });

        // Tooltip positioning for mobile
        function adjustTooltips() {
            if (window.innerWidth <= 768) {
                document.querySelectorAll('.tooltip .tooltiptext').forEach(tooltip => {
                    tooltip.style.bottom = 'auto';
                    tooltip.style.top = '125%';
                });
            }
        }

        // Call on load and resize
        window.addEventListener('load', adjustTooltips);
        window.addEventListener('resize', adjustTooltips);

        // Add loading state to action buttons
        document.querySelectorAll('.action-buttons .btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (this.tagName === 'BUTTON') {
                    const originalContent = this.innerHTML;
                    this.innerHTML = '<span class="loader"></span>';
                    this.disabled = true;
                    
                    setTimeout(() => {
                        this.innerHTML = originalContent;
                        this.disabled = false;
                    }, 2000);
                }
            });
        });

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Focus search input if it has value
            const searchInput = document.getElementById('search');
            if (searchInput && searchInput.value) {
                searchInput.focus();
                searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
            }
            
            // Initialize bulk actions
            updateBulkActions();
            
            // Add fade-in animation to cards
            const cards = document.querySelectorAll('.stat-card, .user-list, .search-filter-section');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });

        // Performance optimization: Debounce search
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const form = this.form;
            
            searchTimeout = setTimeout(() => {
                if (this.value.length >= 3 || this.value.length === 0) {
                    showLoading();
                    form.submit();
                }
            }, 500);
        });

        // Add ripple effect to buttons
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.classList.add('ripple-effect');
                
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

        // Add CSS for ripple effect
        const style = document.createElement('style');
        style.textContent = `
            .btn {
                position: relative;
                overflow: hidden;
            }
            
            .ripple-effect {
                position: absolute;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.6);
                transform: scale(0);
                animation: ripple-animation 0.6s linear;
                pointer-events: none;
            }
            
            @keyframes ripple-animation {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
            
            @keyframes slideOutUp {
                from {
                    transform: translateY(0);
                    opacity: 1;
                }
                to {
                    transform: translateY(-20px);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
s