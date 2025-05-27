<?php
session_start();
require_once __DIR__ . '/../auth_middleware.php';

requireRole('Trainer');

$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// require_once __DIR__ . '/../db_connect.php';
// $conn = connectDB();

require_once 'trainer-theme-helper.php';
// $theme = getThemePreference($conn, $userId);

// // Check if user is logged in and is a trainer
// if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'trainer') {
//     header('Location: ../Public/login.php');
//     exit();
// }

// $userId = $_SESSION['user_id'];
// $userName = $_SESSION['name'];

require_once __DIR__ . '/../db_connect.php';
$conn = connectDB();

require_once 'trainer-theme-helper.php';
$theme = getThemePreference($conn, $userId);

// Get all members assigned to this trainer with enhanced query
$members_query = "SELECT DISTINCT u.id, u.name, u.email, u.phone, u.profile_image, u.created_at,
                         COUNT(a.id) as assessment_count,
                         MAX(a.assessment_date) as last_assessment_date
                  FROM users u
                  JOIN trainer_members tm ON tm.member_id = u.id
                  LEFT JOIN assessments a ON a.member_id = u.id
                  WHERE tm.trainer_id = ? AND u.role = 'Member' AND tm.status = 'active'
                  GROUP BY u.id, u.name, u.email, u.phone, u.profile_image, u.created_at
                  ORDER BY u.name";

$members_stmt = $conn->prepare($members_query);
$members_stmt->execute([$userId]);
$members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get assessment types with enhanced categorization
$assessment_types_query = "SELECT at.*, COUNT(a.id) as usage_count 
                          FROM assessment_types at
                          LEFT JOIN assessments a ON a.assessment_type_id = at.id
                          GROUP BY at.id, at.name, at.category, at.fields, at.description
                          ORDER BY at.category, at.name";
$stmt = $conn->query($assessment_types_query);
$assessment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent assessments with enhanced data
$recent_assessments_query = "SELECT a.*, u.name AS member_name, u.profile_image, 
                                   at.name AS assessment_name, at.category,
                                   DATEDIFF(CURDATE(), a.assessment_date) as days_ago
                            FROM assessments a
                            JOIN users u ON a.member_id = u.id
                            JOIN trainer_members tm ON tm.member_id = u.id
                            JOIN assessment_types at ON a.assessment_type_id = at.id
                            WHERE tm.trainer_id = ?
                            ORDER BY a.assessment_date DESC
                            LIMIT 15";

$recent_stmt = $conn->prepare($recent_assessments_query);
$recent_stmt->execute([$userId]);
$recent_assessments = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get assessment statistics
$stats_query = "SELECT 
                    COUNT(DISTINCT a.member_id) as total_members_assessed,
                    COUNT(a.id) as total_assessments,
                    COUNT(CASE WHEN a.assessment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as assessments_this_month,
                    COUNT(CASE WHEN a.assessment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as assessments_this_week
                FROM assessments a
                JOIN trainer_members tm ON tm.member_id = a.member_id
                WHERE tm.trainer_id = ?";

$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->execute([$userId]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'create_assessment':
            try {
                $member_id = $_POST['member_id'];
                $assessment_type_id = $_POST['assessment_type_id'];
                $assessment_date = $_POST['assessment_date'];
                $notes = $_POST['notes'] ?? '';
                $results = json_encode($_POST['results'] ?? []);
                
                // Validate member belongs to trainer
                $validate_query = "SELECT COUNT(*) FROM trainer_members WHERE trainer_id = ? AND member_id = ? AND status = 'active'";
                $validate_stmt = $conn->prepare($validate_query);
                $validate_stmt->execute([$userId, $member_id]);
                
                if ($validate_stmt->fetchColumn() == 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid member selection']);
                    exit();
                }
                
                $insert_query = "INSERT INTO assessments (member_id, assessment_type_id, trainer_id, assessment_date, results, notes, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, NOW())";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->execute([$member_id, $assessment_type_id, $userId, $assessment_date, $results, $notes]);
                
                if ($insert_stmt->rowCount() > 0) {
                    echo json_encode(['success' => true, 'message' => 'Assessment created successfully', 'assessment_id' => $conn->lastInsertId()]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to create assessment']);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit();
            
        case 'get_member_assessments':
            try {
                $member_id = $_POST['member_id'];
                
                // Validate member belongs to trainer
                $validate_query = "SELECT COUNT(*) FROM trainer_members WHERE trainer_id = ? AND member_id = ? AND status = 'active'";
                $validate_stmt = $conn->prepare($validate_query);
                $validate_stmt->execute([$userId, $member_id]);
                
                if ($validate_stmt->fetchColumn() == 0) {
                    echo json_encode(['error' => 'Access denied']);
                    exit();
                }
                
                $query = "SELECT a.*, at.name as assessment_name, at.category, at.fields,
                                DATEDIFF(CURDATE(), a.assessment_date) as days_ago
                         FROM assessments a
                         JOIN assessment_types at ON a.assessment_type_id = at.id
                         WHERE a.member_id = ?
                         ORDER BY a.assessment_date DESC";
                $stmt = $conn->prepare($query);
                $stmt->execute([$member_id]);
                $assessments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($assessments);
            } catch (Exception $e) {
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit();
            
        case 'get_progress_data':
            try {
                $member_id = $_POST['member_id'];
                $assessment_type_id = $_POST['assessment_type_id'];
                
                // Validate member belongs to trainer
                $validate_query = "SELECT COUNT(*) FROM trainer_members WHERE trainer_id = ? AND member_id = ? AND status = 'active'";
                $validate_stmt = $conn->prepare($validate_query);
                $validate_stmt->execute([$userId, $member_id]);
                
                if ($validate_stmt->fetchColumn() == 0) {
                    echo json_encode(['error' => 'Access denied']);
                    exit();
                }
                
                $query = "SELECT assessment_date, results, notes FROM assessments 
                         WHERE member_id = ? AND assessment_type_id = ?
                         ORDER BY assessment_date ASC";
                $stmt = $conn->prepare($query);
                $stmt->execute([$member_id, $assessment_type_id]);
                $progress = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($progress);
            } catch (Exception $e) {
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit();
            
        case 'generate_report':
            try {
                $member_id = $_POST['member_id'];
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];
                
                // Validate member belongs to trainer
                $validate_query = "SELECT COUNT(*) FROM trainer_members WHERE trainer_id = ? AND member_id = ? AND status = 'active'";
                $validate_stmt = $conn->prepare($validate_query);
                $validate_stmt->execute([$userId, $member_id]);
                
                if ($validate_stmt->fetchColumn() == 0) {
                    echo json_encode(['error' => 'Access denied']);
                    exit();
                }
                
                $report_data = generateAssessmentReport($conn, $member_id, $start_date, $end_date);
                echo json_encode($report_data);
            } catch (Exception $e) {
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit();
            
        case 'get_member_stats':
            try {
                $member_id = $_POST['member_id'];
                
                // Validate member belongs to trainer
                $validate_query = "SELECT COUNT(*) FROM trainer_members WHERE trainer_id = ? AND member_id = ? AND status = 'active'";
                $validate_stmt = $conn->prepare($validate_query);
                $validate_stmt->execute([$userId, $member_id]);
                
                if ($validate_stmt->fetchColumn() == 0) {
                    echo json_encode(['error' => 'Access denied']);
                    exit();
                }
                
                $stats_query = "SELECT 
                                    COUNT(a.id) as total_assessments,
                                    COUNT(DISTINCT at.category) as categories_assessed,
                                    MIN(a.assessment_date) as first_assessment,
                                    MAX(a.assessment_date) as last_assessment,
                                    AVG(DATEDIFF(CURDATE(), a.assessment_date)) as avg_days_since_assessment
                                FROM assessments a
                                JOIN assessment_types at ON a.assessment_type_id = at.id
                                WHERE a.member_id = ?";
                
                $stats_stmt = $conn->prepare($stats_query);
                $stats_stmt->execute([$member_id]);
                $member_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode($member_stats);
            } catch (Exception $e) {
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit();
    }
}

function generateAssessmentReport($conn, $member_id, $start_date, $end_date) {
    // Get member info
    $member_query = "SELECT u.*, tm.assigned_date 
                     FROM users u 
                     JOIN trainer_members tm ON tm.member_id = u.id
                     WHERE u.id = ?";
    $member_stmt = $conn->prepare($member_query);
    $member_stmt->execute([$member_id]);
    $member = $member_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get assessments in date range
    $assessments_query = "SELECT a.*, at.name as assessment_name, at.category, at.fields
                         FROM assessments a
                         JOIN assessment_types at ON a.assessment_type_id = at.id
                         WHERE a.member_id = ? AND a.assessment_date BETWEEN ? AND ?
                         ORDER BY a.assessment_date ASC";
    $assessments_stmt = $conn->prepare($assessments_query);
    $assessments_stmt->execute([$member_id, $start_date, $end_date]);
    $assessments = $assessments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'member' => $member,
        'assessments' => $assessments,
        'period' => ['start' => $start_date, 'end' => $end_date],
        'summary' => calculateProgressSummary($assessments),
        'trends' => calculateTrends($assessments)
    ];
}

function calculateProgressSummary($assessments) {
    $summary = [];
    $categories = [];
    
    foreach ($assessments as $assessment) {
        $category = $assessment['category'];
        if (!isset($categories[$category])) {
            $categories[$category] = [];
        }
        $categories[$category][] = $assessment;
    }
    
    foreach ($categories as $category => $cat_assessments) {
        $summary[$category] = [
            'count' => count($cat_assessments),
            'first_date' => $cat_assessments[0]['assessment_date'],
            'last_date' => end($cat_assessments)['assessment_date'],
            'improvements' => calculateImprovements($cat_assessments)
        ];
    }
    
    return $summary;
}

function calculateImprovements($assessments) {
    if (count($assessments) < 2) return [];
    
    $first = json_decode($assessments[0]['results'], true);
    $last = json_decode(end($assessments)['results'], true);
    $improvements = [];
    
    if (!$first || !$last) return [];
    
    foreach ($first as $key => $value) {
        if (isset($last[$key]) && is_numeric($value) && is_numeric($last[$key])) {
            $change = $last[$key] - $value;
            $percentage = $value != 0 ? ($change / $value) * 100 : 0;
            $improvements[$key] = [
                'change' => $change,
                'percentage' => round($percentage, 2),
                'direction' => $change > 0 ? 'increase' : 'decrease',
                'initial' => $value,
                'current' => $last[$key]
            ];
        }
    }
    
    return $improvements;
}

function calculateTrends($assessments) {
    $trends = [];
    $metrics = [];
    
    // Extract all metrics from assessments
    foreach ($assessments as $assessment) {
        $results = json_decode($assessment['results'], true);
        if ($results) {
            foreach ($results as $key => $value) {
                if (is_numeric($value)) {
                    if (!isset($metrics[$key])) {
                        $metrics[$key] = [];
                    }
                    $metrics[$key][] = [
                        'date' => $assessment['assessment_date'],
                        'value' => floatval($value)
                    ];
                }
            }
        }
    }
    
    // Calculate trends for each metric
    foreach ($metrics as $metric => $data) {
        if (count($data) >= 2) {
            $values = array_column($data, 'value');
            $trend = 'stable';
            
            $first_half = array_slice($values, 0, ceil(count($values) / 2));
            $second_half = array_slice($values, floor(count($values) / 2));
            
            $first_avg = array_sum($first_half) / count($first_half);
            $second_avg = array_sum($second_half) / count($second_half);
            
            $change_percent = $first_avg != 0 ? (($second_avg - $first_avg) / $first_avg) * 100 : 0;
            
            if (abs($change_percent) > 5) {
                $trend = $change_percent > 0 ? 'improving' : 'declining';
            }
            
            $trends[$metric] = [
                'trend' => $trend,
                'change_percent' => round($change_percent, 2),
                'data_points' => count($data)
            ];
        }
    }
    
    return $trends;
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Assessments - EliteFit Trainer</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/date-fns@2.29.3/index.min.js"></script>
    <style>
        :root {
            --primary-orange: #ff6b35;
            --primary-black: #1a1a1a;
            --secondary-orange: #ff8c42;
            --accent-orange: #ffab73;
            --light-orange: #ffe5d9;
            --dark-gray: #2d2d2d;
            --medium-gray: #404040;
            --light-gray: #f5f5f5;
            --white: #ffffff;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
        }

        [data-theme="dark"] {
            --bg-primary: var(--primary-black);
            --bg-secondary: var(--dark-gray);
            --bg-tertiary: var(--medium-gray);
            --text-primary: var(--white);
            --text-secondary: #b0b0b0;
            --text-muted: #888888;
            --border-color: var(--medium-gray);
            --accent-color: var(--primary-orange);
            --accent-hover: var(--secondary-orange);
            --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }

        [data-theme="light"] {
            --bg-primary: var(--white);
            --bg-secondary: var(--light-gray);
            --bg-tertiary: #e5e7eb;
            --text-primary: var(--primary-black);
            --text-secondary: #4a5568;
            --text-muted: #6b7280;
            --border-color: #d1d5db;
            --accent-color: var(--primary-orange);
            --accent-hover: var(--secondary-orange);
            --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            transition: all 0.3s ease;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        /* Navigation */
        .navbar {
            background-color: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--card-shadow);
        }

        .navbar-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--text-primary);
            font-weight: 700;
            font-size: 1.5rem;
        }

        .navbar-brand i {
            color: var(--accent-color);
            font-size: 2rem;
        }

        .navbar-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .theme-toggle {
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 1.25rem;
            padding: 0.5rem;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .theme-toggle:hover {
            background-color: var(--bg-tertiary);
            color: var(--accent-color);
        }

        /* Cards */
        .card {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .card-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .card-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title i {
            color: var(--accent-color);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.75rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.875rem;
        }

        .btn-primary {
            background-color: var(--accent-color);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--accent-hover);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background-color: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background-color: var(--bg-primary);
        }

        .btn-success {
            background-color: var(--success);
            color: var(--white);
        }

        .btn-warning {
            background-color: var(--warning);
            color: var(--white);
        }

        .btn-danger {
            background-color: var(--danger);
            color: var(--white);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }

        .btn-lg {
            padding: 1rem 2rem;
            font-size: 1rem;
        }

        /* Grid System */
        .grid {
            display: grid;
            gap: 1.5rem;
        }

        .grid-cols-1 { grid-template-columns: repeat(1, 1fr); }
        .grid-cols-2 { grid-template-columns: repeat(2, 1fr); }
        .grid-cols-3 { grid-template-columns: repeat(3, 1fr); }
        .grid-cols-4 { grid-template-columns: repeat(4, 1fr); }

        @media (max-width: 768px) {
            .grid-cols-2, .grid-cols-3, .grid-cols-4 {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 769px) and (max-width: 1024px) {
            .grid-cols-3, .grid-cols-4 {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Stats Cards */
        .stat-card {
            background: linear-gradient(135deg, var(--accent-color), var(--secondary-orange));
            color: var(--white);
            border-radius: 1rem;
            padding: 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30px, -30px);
        }

        .stat-card i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }

        .stat-card h3 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-card p {
            font-size: 1rem;
            opacity: 0.9;
        }

        /* Assessment Types */
        .assessment-type-card {
            background-color: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            padding: 1rem;
            transition: all 0.3s ease;
        }

        .assessment-type-card:hover {
            border-color: var(--accent-color);
            transform: translateY(-2px);
        }

        .assessment-type-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .assessment-type-title {
            font-weight: 600;
            color: var(--text-primary);
        }

        .usage-badge {
            background-color: var(--accent-color);
            color: var(--white);
            padding: 0.25rem 0.5rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
            border-radius: 0.75rem;
            border: 1px solid var(--border-color);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .table th {
            background-color: var(--bg-tertiary);
            font-weight: 600;
            color: var(--text-primary);
        }

        .table tr:hover {
            background-color: var(--bg-tertiary);
        }

        /* Member Avatar */
        .member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent-color);
        }

        .member-avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--accent-color);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.25rem;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-primary {
            background-color: var(--accent-color);
            color: var(--white);
        }

        .badge-success {
            background-color: var(--success);
            color: var(--white);
        }

        .badge-warning {
            background-color: var(--warning);
            color: var(--white);
        }

        .badge-danger {
            background-color: var(--danger);
            color: var(--white);
        }

        .badge-secondary {
            background-color: var(--bg-tertiary);
            color: var(--text-secondary);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            backdrop-filter: blur(4px);
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-content {
            background-color: var(--bg-secondary);
            border-radius: 1rem;
            padding: 2rem;
            max-width: 90vw;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 25px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            color: var(--accent-color);
            background-color: var(--bg-tertiary);
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        /* Progress Charts */
        .progress-chart {
            background-color: var(--bg-tertiary);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .chart-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-primary);
            text-transform: capitalize;
        }

        /* Loading Spinner */
        .loading-spinner {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 2rem;
            color: var(--text-secondary);
        }

        .loading-spinner i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--text-muted);
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .empty-state p {
            margin-bottom: 2rem;
        }

        /* Notifications */
        .notification {
            position: fixed;
            top: 2rem;
            right: 2rem;
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            color: var(--white);
            font-weight: 500;
            z-index: 1100;
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.2);
            animation: slideIn 0.3s ease;
        }

        .notification.success {
            background-color: var(--success);
        }

        .notification.error {
            background-color: var(--danger);
        }

        .notification.warning {
            background-color: var(--warning);
        }

        .notification.info {
            background-color: var(--info);
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Responsive Design */
        @media (max-width: 640px) {
            .container {
                padding: 0 0.5rem;
            }

            .navbar-brand {
                font-size: 1.25rem;
            }

            .navbar-brand i {
                font-size: 1.5rem;
            }

            .card {
                padding: 1rem;
            }

            .stat-card {
                padding: 1.5rem;
            }

            .stat-card h3 {
                font-size: 2rem;
            }

            .modal-content {
                padding: 1.5rem;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                justify-content: center;
            }
        }

        /* Dark mode specific adjustments */
        [data-theme="dark"] .table th {
            background-color: var(--medium-gray);
        }

        [data-theme="dark"] .form-control {
            background-color: var(--dark-gray);
        }

        [data-theme="dark"] .assessment-type-card {
            background-color: var(--medium-gray);
        }

        /* Light mode specific adjustments */
        [data-theme="light"] .navbar {
            border-bottom-color: #e5e7eb;
        }

        [data-theme="light"] .stat-card {
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }

        /* Utility Classes */
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-bold { font-weight: 700; }
        .font-semibold { font-weight: 600; }
        .font-medium { font-weight: 500; }
        .text-sm { font-size: 0.875rem; }
        .text-lg { font-size: 1.125rem; }
        .text-xl { font-size: 1.25rem; }
        .text-2xl { font-size: 1.5rem; }
        .text-3xl { font-size: 1.875rem; }
        .mb-2 { margin-bottom: 0.5rem; }
        .mb-4 { margin-bottom: 1rem; }
        .mb-6 { margin-bottom: 1.5rem; }
        .mb-8 { margin-bottom: 2rem; }
        .mt-4 { margin-top: 1rem; }
        .mt-6 { margin-top: 1.5rem; }
        .p-4 { padding: 1rem; }
        .p-6 { padding: 1.5rem; }
        .px-4 { padding-left: 1rem; padding-right: 1rem; }
        .py-2 { padding-top: 0.5rem; padding-bottom: 0.5rem; }
        .py-4 { padding-top: 1rem; padding-bottom: 1rem; }
        .rounded { border-radius: 0.5rem; }
        .rounded-lg { border-radius: 0.75rem; }
        .shadow { box-shadow: var(--card-shadow); }
        .hidden { display: none; }
        .flex { display: flex; }
        .items-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .gap-2 { gap: 0.5rem; }
        .gap-4 { gap: 1rem; }
        .w-full { width: 100%; }
        .h-full { height: 100%; }
        .cursor-pointer { cursor: pointer; }
        .transition { transition: all 0.3s ease; }
        .hover\:scale-105:hover { transform: scale(1.05); }
        .opacity-90 { opacity: 0.9; }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="navbar-content">
                <a href="dashboard.php" class="navbar-brand">
                    <i class="fas fa-dumbbell"></i>
                    <span>EliteFit Trainer</span>
                </a>
                <div class="navbar-actions">
                    <button onclick="toggleTheme()" class="theme-toggle" title="Toggle Theme">
                        <i class="fas fa-moon" id="theme-icon"></i>
                    </button>
                    <a href="../Public/logout.php" class="btn btn-danger btn-sm">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container" style="padding-top: 2rem; padding-bottom: 2rem;">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold mb-2">Member Assessments</h1>
            <p class="text-lg" style="color: var(--text-secondary);">
                Comprehensive fitness and health assessments for your members
            </p>
        </div>

        <!-- Statistics Overview -->
        <div class="grid grid-cols-4 mb-8">
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <h3><?php echo $stats['total_members_assessed'] ?? 0; ?></h3>
                <p>Members Assessed</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-clipboard-list"></i>
                <h3><?php echo $stats['total_assessments'] ?? 0; ?></h3>
                <p>Total Assessments</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-calendar-month"></i>
                <h3><?php echo $stats['assessments_this_month'] ?? 0; ?></h3>
                <p>This Month</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-calendar-week"></i>
                <h3><?php echo $stats['assessments_this_week'] ?? 0; ?></h3>
                <p>This Week</p>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-4 mb-8">
            <button onclick="openNewAssessmentModal()" class="stat-card cursor-pointer hover:scale-105 transition">
                <i class="fas fa-plus"></i>
                <h3 class="text-xl">New</h3>
                <p>Create Assessment</p>
            </button>
            
            <button onclick="openProgressModal()" class="stat-card cursor-pointer hover:scale-105 transition">
                <i class="fas fa-chart-line"></i>
                <h3 class="text-xl">Progress</h3>
                <p>Track Member Progress</p>
            </button>
            
            <button onclick="openReportModal()" class="stat-card cursor-pointer hover:scale-105 transition">
                <i class="fas fa-file-alt"></i>
                <h3 class="text-xl">Reports</h3>
                <p>Generate Reports</p>
            </button>
            
            <button onclick="openBulkAssessmentModal()" class="stat-card cursor-pointer hover:scale-105 transition">
                <i class="fas fa-layer-group"></i>
                <h3 class="text-xl">Bulk</h3>
                <p>Multiple Assessments</p>
            </button>
        </div>

        <!-- Assessment Types Overview -->
        <div class="card mb-8">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-list-check"></i>
                    Assessment Types
                </h2>
                <button class="btn btn-primary btn-sm" onclick="openNewAssessmentModal()">
                    <i class="fas fa-plus"></i>
                    Quick Assessment
                </button>
            </div>
            
            <?php if (empty($assessment_types)): ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>No Assessment Types Available</h3>
                    <p>Contact your administrator to set up assessment types.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-3">
                    <?php
                    $categories = [];
                    foreach ($assessment_types as $type) {
                        $categories[$type['category']][] = $type;
                    }
                    
                    foreach ($categories as $category => $types): ?>
                        <div class="assessment-type-card">
                            <div class="assessment-type-header">
                                <h3 class="assessment-type-title"><?php echo ucfirst($category); ?></h3>
                                <span class="usage-badge"><?php echo count($types); ?> types</span>
                            </div>
                            <div class="space-y-2">
                                <?php foreach ($types as $type): ?>
                                    <div class="flex items-center justify-between p-2 rounded" style="background-color: var(--bg-primary);">
                                        <span class="text-sm font-medium"><?php echo htmlspecialchars($type['name']); ?></span>
                                        <div class="flex items-center gap-2">
                                            <?php if ($type['usage_count'] > 0): ?>
                                                <span class="badge badge-secondary"><?php echo $type['usage_count']; ?> used</span>
                                            <?php endif; ?>
                                            <button onclick="quickAssessment(<?php echo $type['id']; ?>)" 
                                                    class="btn btn-primary btn-sm">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Assessments -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-clock"></i>
                    Recent Assessments
                </h2>
                <div class="flex items-center gap-2">
                    <button onclick="refreshAssessments()" class="btn btn-secondary btn-sm">
                        <i class="fas fa-refresh"></i>
                        Refresh
                    </button>
                    <button onclick="openNewAssessmentModal()" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i>
                        New Assessment
                    </button>
                </div>
            </div>
            
            <?php if (empty($recent_assessments)): ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>No Assessments Yet</h3>
                    <p>Start by creating your first member assessment.</p>
                    <button onclick="openNewAssessmentModal()" class="btn btn-primary btn-lg">
                        <i class="fas fa-plus"></i>
                        Create First Assessment
                    </button>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Assessment</th>
                                <th>Category</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_assessments as $assessment): ?>
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <?php if (!empty($assessment['profile_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($assessment['profile_image']); ?>" 
                                                     alt="Profile" class="member-avatar">
                                            <?php else: ?>
                                                <div class="member-avatar-placeholder">
                                                    <?php echo strtoupper(substr($assessment['member_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="font-semibold"><?php echo htmlspecialchars($assessment['member_name']); ?></div>
                                                <div class="text-sm" style="color: var(--text-secondary);">
                                                    <?php 
                                                    if ($assessment['days_ago'] == 0) {
                                                        echo 'Today';
                                                    } elseif ($assessment['days_ago'] == 1) {
                                                        echo 'Yesterday';
                                                    } else {
                                                        echo $assessment['days_ago'] . ' days ago';
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="font-medium"><?php echo htmlspecialchars($assessment['assessment_name']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge badge-primary">
                                            <?php echo ucfirst($assessment['category']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="font-medium"><?php echo date('M j, Y', strtotime($assessment['assessment_date'])); ?></div>
                                        <div class="text-sm" style="color: var(--text-secondary);">
                                            <?php echo date('g:i A', strtotime($assessment['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-success">Completed</span>
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <button onclick="viewAssessment(<?php echo $assessment['id']; ?>)" 
                                                    class="btn btn-secondary btn-sm" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button onclick="editAssessment(<?php echo $assessment['id']; ?>)" 
                                                    class="btn btn-warning btn-sm" title="Edit Assessment">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="deleteAssessment(<?php echo $assessment['id']; ?>)" 
                                                    class="btn btn-danger btn-sm" title="Delete Assessment">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- New Assessment Modal -->
    <div id="newAssessmentModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h2 class="modal-title">Create New Assessment</h2>
                <button onclick="closeModal('newAssessmentModal')" class="modal-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="newAssessmentForm">
                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div class="form-group">
                        <label class="form-label">Select Member</label>
                        <select id="assessmentMember" name="member_id" required class="form-control">
                            <option value="">Choose a member...</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?php echo $member['id']; ?>" 
                                        data-email="<?php echo htmlspecialchars($member['email']); ?>"
                                        data-phone="<?php echo htmlspecialchars($member['phone'] ?? ''); ?>"
                                        data-assessments="<?php echo $member['assessment_count']; ?>">
                                    <?php echo htmlspecialchars($member['name']); ?>
                                    (<?php echo $member['assessment_count']; ?> assessments)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="memberInfo" class="mt-2 text-sm" style="color: var(--text-secondary); display: none;">
                            <!-- Member info will be displayed here -->
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Assessment Type</label>
                        <select id="assessmentType" name="assessment_type_id" required class="form-control">
                            <option value="">Select assessment type...</option>
                            <?php foreach ($assessment_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>" 
                                        data-fields='<?php echo htmlspecialchars($type['fields']); ?>'
                                        data-category="<?php echo $type['category']; ?>">
                                    <?php echo htmlspecialchars($type['name']); ?> 
                                    (<?php echo ucfirst($type['category']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group mb-6">
                    <label class="form-label">Assessment Date</label>
                    <input type="date" id="assessmentDate" name="assessment_date" required 
                           value="<?php echo date('Y-m-d'); ?>" class="form-control">
                </div>
                
                <div id="assessmentFields" class="mb-6">
                    <!-- Dynamic fields will be populated here -->
                </div>
                
                <div class="form-group mb-6">
                    <label class="form-label">Additional Notes</label>
                    <textarea id="assessmentNotes" name="notes" rows="4" class="form-control"
                              placeholder="Add any additional observations, recommendations, or notes about this assessment..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" onclick="closeModal('newAssessmentModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        Save Assessment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Progress Tracking Modal -->
    <div id="progressModal" class="modal">
        <div class="modal-content" style="max-width: 1200px;">
            <div class="modal-header">
                <h2 class="modal-title">Member Progress Tracking</h2>
                <button onclick="closeModal('progressModal')" class="modal-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div class="form-group">
                    <label class="form-label">Select Member</label>
                    <select id="progressMember" class="form-control">
                        <option value="">Choose a member...</option>
                        <?php foreach ($members as $member): ?>
                            <option value="<?php echo $member['id']; ?>">
                                <?php echo htmlspecialchars($member['name']); ?>
                                (<?php echo $member['assessment_count']; ?> assessments)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Assessment Type</label>
                    <select id="progressAssessmentType" class="form-control">
                        <option value="">Select assessment type...</option>
                        <?php foreach ($assessment_types as $type): ?>
                            <option value="<?php echo $type['id']; ?>">
                                <?php echo htmlspecialchars($type['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div id="memberStatsContainer" class="mb-6" style="display: none;">
                <!-- Member statistics will be displayed here -->
            </div>
            
            <div id="progressCharts">
                <div class="empty-state">
                    <i class="fas fa-chart-line"></i>
                    <h3>Select Member and Assessment Type</h3>
                    <p>Choose a member and assessment type to view progress charts and trends.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Report Generation Modal -->
    <div id="reportModal" class="modal">
        <div class="modal-content" style="max-width: 1000px;">
            <div class="modal-header">
                <h2 class="modal-title">Generate Assessment Report</h2>
                <button onclick="closeModal('reportModal')" class="modal-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="reportForm">
                <div class="grid grid-cols-3 gap-4 mb-6">
                    <div class="form-group">
                        <label class="form-label">Select Member</label>
                        <select id="reportMember" name="member_id" required class="form-control">
                            <option value="">Choose a member...</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?php echo $member['id']; ?>">
                                    <?php echo htmlspecialchars($member['name']); ?>
                                    (<?php echo $member['assessment_count']; ?> assessments)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" id="reportStartDate" name="start_date" required class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" id="reportEndDate" name="end_date" required 
                               value="<?php echo date('Y-m-d'); ?>" class="form-control">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" onclick="closeModal('reportModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-chart-bar"></i>
                        Generate Report
                    </button>
                </div>
            </form>
            
            <div id="reportContent" class="mt-6 hidden">
                <!-- Generated report will be displayed here -->
            </div>
        </div>
    </div>

    <script>
        // Theme management
        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            document.documentElement.setAttribute('data-theme', newTheme);
            updateThemeIcon(newTheme);
            
            // Save theme preference
            fetch('save-theme.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'theme=' + newTheme
            }).catch(error => console.error('Error saving theme:', error));
        }

        function updateThemeIcon(theme) {
            const icon = document.getElementById('theme-icon');
            if (theme === 'dark') {
                icon.className = 'fas fa-sun';
            } else {
                icon.className = 'fas fa-moon';
            }
        }

        // Initialize theme icon
        document.addEventListener('DOMContentLoaded', function() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            updateThemeIcon(currentTheme);
        });

        // Member selection handling
        document.getElementById('assessmentMember').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const memberInfo = document.getElementById('memberInfo');
            
            if (selectedOption.value) {
                const email = selectedOption.getAttribute('data-email');
                const phone = selectedOption.getAttribute('data-phone');
                const assessments = selectedOption.getAttribute('data-assessments');
                
                memberInfo.innerHTML = `
                    <div class="flex items-center gap-4">
                        <div><strong>Email:</strong> ${email}</div>
                        ${phone ? `<div><strong>Phone:</strong> ${phone}</div>` : ''}
                        <div><strong>Previous Assessments:</strong> ${assessments}</div>
                    </div>
                `;
                memberInfo.style.display = 'block';
            } else {
                memberInfo.style.display = 'none';
            }
        });

        // Assessment type handling
        document.getElementById('assessmentType').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const fields = selectedOption.getAttribute('data-fields');
            
            if (fields && fields !== 'null') {
                try {
                    generateAssessmentFields(JSON.parse(fields));
                } catch (e) {
                    console.error('Error parsing assessment fields:', e);
                    document.getElementById('assessmentFields').innerHTML = '';
                }
            } else {
                document.getElementById('assessmentFields').innerHTML = '';
            }
        });

        function generateAssessmentFields(fields) {
            const container = document.getElementById('assessmentFields');
            container.innerHTML = '';
            
            if (!fields || fields.length === 0) return;
            
            const fieldsetElement = document.createElement('fieldset');
            fieldsetElement.style.border = '1px solid var(--border-color)';
            fieldsetElement.style.borderRadius = '0.75rem';
            fieldsetElement.style.padding = '1.5rem';
            fieldsetElement.style.marginBottom = '1rem';
            
            const legend = document.createElement('legend');
            legend.textContent = 'Assessment Measurements';
            legend.style.fontWeight = '600';
            legend.style.color = 'var(--text-primary)';
            legend.style.padding = '0 0.5rem';
            fieldsetElement.appendChild(legend);
            
            const grid = document.createElement('div');
            grid.className = 'grid grid-cols-2 gap-4';
            
            fields.forEach((field, index) => {
                const fieldDiv = document.createElement('div');
                fieldDiv.className = 'form-group';
                fieldDiv.innerHTML = `
                    <label class="form-label">${field.label}</label>
                    ${generateFieldInput(field)}
                    ${field.description ? `<div class="text-sm mt-1" style="color: var(--text-secondary);">${field.description}</div>` : ''}
                `;
                grid.appendChild(fieldDiv);
            });
            
            fieldsetElement.appendChild(grid);
            container.appendChild(fieldsetElement);
        }

        function generateFieldInput(field) {
            const baseClasses = 'form-control';
            
            switch (field.type) {
                case 'number':
                    return `<input type="number" name="results[${field.name}]" 
                            ${field.required ? 'required' : ''} 
                            ${field.min !== undefined ? `min="${field.min}"` : ''} 
                            ${field.max !== undefined ? `max="${field.max}"` : ''} 
                            step="${field.step || 'any'}"
                            placeholder="${field.placeholder || ''}"
                            class="${baseClasses}">`;
                            
                case 'select':
                    const options = field.options ? field.options.map(opt => 
                        `<option value="${opt.value}">${opt.label}</option>`
                    ).join('') : '';
                    return `<select name="results[${field.name}]" 
                            ${field.required ? 'required' : ''} 
                            class="${baseClasses}">
                            <option value="">Select ${field.label}</option>
                            ${options}
                            </select>`;
                            
                case 'textarea':
                    return `<textarea name="results[${field.name}]" 
                            ${field.required ? 'required' : ''} 
                            rows="${field.rows || 3}"
                            placeholder="${field.placeholder || ''}"
                            class="${baseClasses}"></textarea>`;
                            
                case 'range':
                    return `<div class="range-input">
                            <input type="range" name="results[${field.name}]" 
                                   ${field.required ? 'required' : ''} 
                                   min="${field.min || 0}" 
                                   max="${field.max || 100}" 
                                   step="${field.step || 1}"
                                   value="${field.default || field.min || 0}"
                                   class="${baseClasses}"
                                   oninput="this.nextElementSibling.textContent = this.value">
                            <span class="range-value">${field.default || field.min || 0}</span>
                            </div>`;
                            
                default:
                    return `<input type="text" name="results[${field.name}]" 
                            ${field.required ? 'required' : ''} 
                            placeholder="${field.placeholder || ''}"
                            class="${baseClasses}">`;
            }
        }

        // Form submission handling
        document.getElementById('newAssessmentForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'create_assessment');
            
            // Collect results data
            const results = {};
            const resultInputs = this.querySelectorAll('[name^="results["]');
            resultInputs.forEach(input => {
                const name = input.name.match(/results\[(.+)\]/)[1];
                results[name] = input.value;
            });
            
            formData.delete('results');
            for (const [key, value] of Object.entries(results)) {
                formData.append(`results[${key}]`, value);
            }
            
            try {
                showLoadingState('newAssessmentForm', true);
                
                const response = await fetch('assessments.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Assessment created successfully!', 'success');
                    closeModal('newAssessmentModal');
                    refreshAssessments();
                    this.reset();
                    document.getElementById('memberInfo').style.display = 'none';
                    document.getElementById('assessmentFields').innerHTML = '';
                } else {
                    showNotification(result.message || 'Failed to create assessment', 'error');
                }
            } catch (error) {
                console.error('Error creating assessment:', error);
                showNotification('Error creating assessment. Please try again.', 'error');
            } finally {
                showLoadingState('newAssessmentForm', false);
            }
        });

        // Progress tracking
        document.getElementById('progressMember').addEventListener('change', function() {
            if (this.value) {
                loadMemberStats(this.value);
            } else {
                document.getElementById('memberStatsContainer').style.display = 'none';
            }
            loadMemberProgress();
        });

        document.getElementById('progressAssessmentType').addEventListener('change', loadMemberProgress);

        async function loadMemberStats(memberId) {
            try {
                const formData = new FormData();
                formData.append('action', 'get_member_stats');
                formData.append('member_id', memberId);
                
                const response = await fetch('assessments.php', {
                    method: 'POST',
                    body: formData
                });
                
                const stats = await response.json();
                
                if (stats.error) {
                    showNotification(stats.error, 'error');
                    return;
                }
                
                displayMemberStats(stats);
                
            } catch (error) {
                console.error('Error loading member stats:', error);
                showNotification('Error loading member statistics', 'error');
            }
        }

        function displayMemberStats(stats) {
            const container = document.getElementById('memberStatsContainer');
            
            container.innerHTML = `
                <div class="card" style="background-color: var(--bg-tertiary); margin-bottom: 1rem;">
                    <h3 class="text-lg font-semibold mb-4">Member Statistics</h3>
                    <div class="grid grid-cols-4 gap-4">
                        <div class="text-center">
                            <div class="text-2xl font-bold" style="color: var(--accent-color);">${stats.total_assessments || 0}</div>
                            <div class="text-sm" style="color: var(--text-secondary);">Total Assessments</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold" style="color: var(--accent-color);">${stats.categories_assessed || 0}</div>
                            <div class="text-sm" style="color: var(--text-secondary);">Categories Assessed</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold" style="color: var(--accent-color);">
                                ${stats.first_assessment ? new Date(stats.first_assessment).toLocaleDateString() : 'N/A'}
                            </div>
                            <div class="text-sm" style="color: var(--text-secondary);">First Assessment</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold" style="color: var(--accent-color);">
                                ${stats.last_assessment ? new Date(stats.last_assessment).toLocaleDateString() : 'N/A'}
                            </div>
                            <div class="text-sm" style="color: var(--text-secondary);">Last Assessment</div>
                        </div>
                    </div>
                </div>
            `;
            
            container.style.display = 'block';
        }

        async function loadMemberProgress() {
            const memberId = document.getElementById('progressMember').value;
            const assessmentTypeId = document.getElementById('progressAssessmentType').value;
            
            if (!memberId || !assessmentTypeId) {
                document.getElementById('progressCharts').innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-chart-line"></i>
                        <h3>Select Member and Assessment Type</h3>
                        <p>Choose a member and assessment type to view progress charts and trends.</p>
                    </div>
                `;
                return;
            }
            
            try {
                document.getElementById('progressCharts').innerHTML = `
                    <div class="loading-spinner">
                        <i class="fas fa-spinner fa-spin"></i>
                        <span>Loading progress data...</span>
                    </div>
                `;
                
                const formData = new FormData();
                formData.append('action', 'get_progress_data');
                formData.append('member_id', memberId);
                formData.append('assessment_type_id', assessmentTypeId);
                
                const response = await fetch('assessments.php', {
                    method: 'POST',
                    body: formData
                });
                
                const progressData = await response.json();
                
                if (progressData.error) {
                    showNotification(progressData.error, 'error');
                    return;
                }
                
                displayProgressCharts(progressData);
                
            } catch (error) {
                console.error('Error loading progress data:', error);
                showNotification('Error loading progress data', 'error');
                document.getElementById('progressCharts').innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error Loading Data</h3>
                        <p>There was an error loading the progress data. Please try again.</p>
                    </div>
                `;
            }
        }

        function displayProgressCharts(data) {
            const container = document.getElementById('progressCharts');
            container.innerHTML = '';
            
            if (data.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-chart-line"></i>
                        <h3>No Progress Data Available</h3>
                        <p>No assessment data found for this member and assessment type combination.</p>
                    </div>
                `;
                return;
            }
            
            // Process data for charts
            const dates = data.map(d => d.assessment_date);
            const results = data.map(d => {
                try {
                    return JSON.parse(d.results);
                } catch (e) {
                    return {};
                }
            });
            
            // Get all unique metrics
            const metrics = new Set();
            results.forEach(result => {
                Object.keys(result).forEach(key => {
                    if (typeof result[key] === 'number' || !isNaN(parseFloat(result[key]))) {
                        metrics.add(key);
                    }
                });
            });
            
            if (metrics.size === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-chart-line"></i>
                        <h3>No Numeric Data Available</h3>
                        <p>No numeric measurements found in the assessment data to create charts.</p>
                    </div>
                `;
                return;
            }
            
            // Create chart for each metric
            metrics.forEach(metric => {
                const values = results.map(result => parseFloat(result[metric]) || 0);
                createProgressChart(metric, dates, values, data);
            });
        }

        function createProgressChart(metric, dates, values, rawData) {
            const container = document.getElementById('progressCharts');
            
            const chartDiv = document.createElement('div');
            chartDiv.className = 'progress-chart';
            
            // Calculate trend
            const trend = calculateTrend(values);
            const trendIcon = trend > 5 ? 'fa-arrow-up' : trend < -5 ? 'fa-arrow-down' : 'fa-minus';
            const trendColor = trend > 5 ? 'var(--success)' : trend < -5 ? 'var(--danger)' : 'var(--warning)';
            
            chartDiv.innerHTML = `
                <div class="flex items-center justify-between mb-4">
                    <h3 class="chart-title">${metric.replace(/_/g, ' ')}</h3>
                    <div class="flex items-center gap-2">
                        <i class="fas ${trendIcon}" style="color: ${trendColor};"></i>
                        <span style="color: ${trendColor}; font-weight: 600;">
                            ${Math.abs(trend).toFixed(1)}% ${trend > 0 ? 'increase' : trend < 0 ? 'decrease' : 'stable'}
                        </span>
                    </div>
                </div>
                <canvas id="chart-${metric}" width="400" height="200"></canvas>
                <div class="mt-4 grid grid-cols-3 gap-4 text-center">
                    <div>
                        <div class="text-lg font-semibold" style="color: var(--accent-color);">${values[0]}</div>
                        <div class="text-sm" style="color: var(--text-secondary);">Initial</div>
                    </div>
                    <div>
                        <div class="text-lg font-semibold" style="color: var(--accent-color);">${values[values.length - 1]}</div>
                        <div class="text-sm" style="color: var(--text-secondary);">Current</div>
                    </div>
                    <div>
                        <div class="text-lg font-semibold" style="color: var(--accent-color);">${(values.reduce((a, b) => a + b, 0) / values.length).toFixed(1)}</div>
                        <div class="text-sm" style="color: var(--text-secondary);">Average</div>
                    </div>
                </div>
            `;
            
            container.appendChild(chartDiv);
            
            // Create chart
            const ctx = document.getElementById(`chart-${metric}`).getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: dates.map(date => new Date(date).toLocaleDateString()),
                    datasets: [{
                        label: metric.replace(/_/g, ' '),
                        data: values,
                        borderColor: 'var(--accent-color)',
                        backgroundColor: 'rgba(255, 107, 53, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: 'var(--accent-color)',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 6,
                        pointHoverRadius: 8
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
                            backgroundColor: 'var(--bg-secondary)',
                            titleColor: 'var(--text-primary)',
                            bodyColor: 'var(--text-primary)',
                            borderColor: 'var(--border-color)',
                            borderWidth: 1,
                            callbacks: {
                                afterBody: function(context) {
                                    const index = context[0].dataIndex;
                                    const notes = rawData[index].notes;
                                    return notes ? `Notes: ${notes}` : '';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: 'var(--border-color)'
                            },
                            ticks: {
                                color: 'var(--text-secondary)'
                            }
                        },
                        y: {
                            beginAtZero: false,
                            grid: {
                                color: 'var(--border-color)'
                            },
                            ticks: {
                                color: 'var(--text-secondary)'
                            }
                        }
                    }
                }
            });
        }

        function calculateTrend(values) {
            if (values.length < 2) return 0;
            
            const firstHalf = values.slice(0, Math.ceil(values.length / 2));
            const secondHalf = values.slice(Math.floor(values.length / 2));
            
            const firstAvg = firstHalf.reduce((a, b) => a + b, 0) / firstHalf.length;
            const secondAvg = secondHalf.reduce((a, b) => a + b, 0) / secondHalf.length;
            
            return firstAvg !== 0 ? ((secondAvg - firstAvg) / firstAvg) * 100 : 0;
        }

        // Report generation
        document.getElementById('reportForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'generate_report');
            
            try {
                showLoadingState('reportForm', true);
                
                const response = await fetch('assessments.php', {
                    method: 'POST',
                    body: formData
                });
                
                const reportData = await response.json();
                
                if (reportData.error) {
                    showNotification(reportData.error, 'error');
                    return;
                }
                
                displayReport(reportData);
                
            } catch (error) {
                console.error('Error generating report:', error);
                showNotification('Error generating report', 'error');
            } finally {
                showLoadingState('reportForm', false);
            }
        });

        function displayReport(reportData) {
            const container = document.getElementById('reportContent');
            
            let html = `
                <div style="border-top: 1px solid var(--border-color); padding-top: 2rem;">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-2xl font-bold">Assessment Report</h3>
                        <div class="flex items-center gap-2">
                            <button onclick="printReport()" class="btn btn-primary btn-sm">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <button onclick="exportReport()" class="btn btn-success btn-sm">
                                <i class="fas fa-download"></i> Export PDF
                            </button>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-6 mb-8">
                        <div class="card">
                            <h4 class="text-lg font-semibold mb-4">Member Information</h4>
                            <div class="space-y-2">
                                <div><strong>Name:</strong> ${reportData.member.name}</div>
                                <div><strong>Email:</strong> ${reportData.member.email}</div>
                                <div><strong>Phone:</strong> ${reportData.member.phone || 'N/A'}</div>
                                <div><strong>Member Since:</strong> ${new Date(reportData.member.created_at).toLocaleDateString()}</div>
                                ${reportData.member.assigned_date ? `<div><strong>Assigned Date:</strong> ${new Date(reportData.member.assigned_date).toLocaleDateString()}</div>` : ''}
                            </div>
                        </div>
                        
                        <div class="card">
                            <h4 class="text-lg font-semibold mb-4">Report Summary</h4>
                            <div class="space-y-2">
                                <div><strong>Report Period:</strong> ${new Date(reportData.period.start).toLocaleDateString()} - ${new Date(reportData.period.end).toLocaleDateString()}</div>
                                <div><strong>Total Assessments:</strong> ${reportData.assessments.length}</div>
                                <div><strong>Categories Covered:</strong> ${Object.keys(reportData.summary).length}</div>
                                <div><strong>Generated:</strong> ${new Date().toLocaleDateString()}</div>
                            </div>
                        </div>
                    </div>
            `;
            
            // Progress Summary
            if (Object.keys(reportData.summary).length > 0) {
                html += `
                    <div class="card mb-8">
                        <h4 class="text-lg font-semibold mb-4">Progress Summary by Category</h4>
                        <div class="grid grid-cols-3 gap-4">
                `;
                
                Object.entries(reportData.summary).forEach(([category, summary]) => {
                    html += `
                        <div class="assessment-type-card">
                            <h5 class="font-semibold capitalize mb-2">${category}</h5>
                            <div class="space-y-1 text-sm">
                                <div>Assessments: ${summary.count}</div>
                                <div>First: ${new Date(summary.first_date).toLocaleDateString()}</div>
                                <div>Latest: ${new Date(summary.last_date).toLocaleDateString()}</div>
                            </div>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            }
            
            // Trends Analysis
            if (reportData.trends && Object.keys(reportData.trends).length > 0) {
                html += `
                    <div class="card mb-8">
                        <h4 class="text-lg font-semibold mb-4">Trends Analysis</h4>
                        <div class="grid grid-cols-2 gap-4">
                `;
                
                Object.entries(reportData.trends).forEach(([metric, trend]) => {
                    const trendIcon = trend.trend === 'improving' ? 'fa-arrow-up' : trend.trend === 'declining' ? 'fa-arrow-down' : 'fa-minus';
                    const trendColor = trend.trend === 'improving' ? 'var(--success)' : trend.trend === 'declining' ? 'var(--danger)' : 'var(--warning)';
                    
                    html += `
                        <div class="flex items-center justify-between p-3 rounded" style="background-color: var(--bg-tertiary);">
                            <div>
                                <div class="font-medium">${metric.replace(/_/g, ' ')}</div>
                                <div class="text-sm" style="color: var(--text-secondary);">${trend.data_points} data points</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <i class="fas ${trendIcon}" style="color: ${trendColor};"></i>
                                <span style="color: ${trendColor}; font-weight: 600;">
                                    ${Math.abs(trend.change_percent).toFixed(1)}%
                                </span>
                            </div>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            }
            
            // Assessment History
            html += `
                <div class="card">
                    <h4 class="text-lg font-semibold mb-4">Assessment History</h4>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Assessment</th>
                                    <th>Category</th>
                                    <th>Key Measurements</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            reportData.assessments.forEach(assessment => {
                const results = JSON.parse(assessment.results || '{}');
                const keyMeasurements = Object.entries(results)
                    .filter(([key, value]) => !isNaN(parseFloat(value)))
                    .slice(0, 3)
                    .map(([key, value]) => `${key}: ${value}`)
                    .join(', ');
                
                html += `
                    <tr>
                        <td>${new Date(assessment.assessment_date).toLocaleDateString()}</td>
                        <td>${assessment.assessment_name}</td>
                        <td><span class="badge badge-primary">${assessment.category}</span></td>
                        <td class="text-sm">${keyMeasurements || 'No numeric data'}</td>
                        <td class="text-sm">${assessment.notes || 'No notes'}</td>
                    </tr>
                `;
            });
            
            html += `
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
            
            html += `</div>`;
            
            container.innerHTML = html;
            container.classList.remove('hidden');
        }

        // Utility functions
        function openNewAssessmentModal() {
            document.getElementById('newAssessmentModal').classList.add('active');
        }

        function openProgressModal() {
            document.getElementById('progressModal').classList.add('active');
        }

        function openReportModal() {
            document.getElementById('reportModal').classList.add('active');
            // Set default start date to 3 months ago
            const startDate = new Date();
            startDate.setMonth(startDate.getMonth() - 3);
            document.getElementById('reportStartDate').value = startDate.toISOString().split('T')[0];
        }

        function openBulkAssessmentModal() {
            showNotification('Bulk assessment feature coming soon!', 'info');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        function quickAssessment(assessmentTypeId) {
            document.getElementById('assessmentType').value = assessmentTypeId;
            document.getElementById('assessmentType').dispatchEvent(new Event('change'));
            openNewAssessmentModal();
        }

        async function refreshAssessments() {
            showNotification('Refreshing assessments...', 'info');
            setTimeout(() => {
                location.reload();
            }, 1000);
        }

        function viewAssessment(assessmentId) {
            showNotification('View assessment feature coming soon!', 'info');
        }

        function editAssessment(assessmentId) {
            showNotification('Edit assessment feature coming soon!', 'info');
        }

        function deleteAssessment(assessmentId) {
            if (confirm('Are you sure you want to delete this assessment? This action cannot be undone.')) {
                showNotification('Delete assessment feature coming soon!', 'info');
            }
        }

        function printReport() {
            window.print();
        }

        function exportReport() {
            showNotification('PDF export feature coming soon!', 'info');
        }

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <div class="flex items-center gap-2">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 5000);
        }

        function showLoadingState(formId, isLoading) {
            const form = document.getElementById(formId);
            const submitBtn = form.querySelector('button[type="submit"]');
            
            if (isLoading) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            } else {
                submitBtn.disabled = false;
                submitBtn.innerHTML = submitBtn.getAttribute('data-original-text') || 'Submit';
            }
        }

        // Close modals when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const activeModal = document.querySelector('.modal.active');
                if (activeModal) {
                    activeModal.classList.remove('active');
                }
            }
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Store original button text for loading states
            document.querySelectorAll('button[type="submit"]').forEach(btn => {
                btn.setAttribute('data-original-text', btn.innerHTML);
            });
        });
    </script>
</body>
</html>
