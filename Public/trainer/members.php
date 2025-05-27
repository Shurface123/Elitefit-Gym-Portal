<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';
requireRole('Trainer');

$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

require_once __DIR__ . '/../db_connect.php';
$conn = connectDB();

require_once 'trainer-theme-helper.php';
$theme = getThemePreference($conn, $userId);

// Add the missing formatDate function
function formatDate($dateString) {
    if (empty($dateString)) {
        return 'N/A';
    }
    $date = new DateTime($dateString);
    return $date->format('M j, Y');
}

// Handle member assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'assign_member') {
        $memberId = $_POST['member_id'];
        $specializationFocus = $_POST['specialization_focus'] ?? '';
        $goals = $_POST['goals'] ?? '';
        
        try {
            $stmt = $conn->prepare("
                INSERT INTO trainer_members (trainer_id, member_id, assigned_date, specialization_focus, goals) 
                VALUES (?, ?, CURDATE(), ?, ?)
                ON DUPLICATE KEY UPDATE 
                specialization_focus = VALUES(specialization_focus),
                goals = VALUES(goals),
                status = 'active'
            ");
            $stmt->execute([$userId, $memberId, $specializationFocus, $goals]);
            
            // Log activity
            $stmt = $conn->prepare("
                INSERT INTO trainer_activity (trainer_id, member_id, activity_type, title, description) 
                VALUES (?, ?, 'member', 'Member Assigned', 'New member assigned to training program')
            ");
            $stmt->execute([$userId, $memberId]);
            
            $success = "Member assigned successfully!";
        } catch (PDOException $e) {
            $error = "Error assigning member: " . $e->getMessage();
        }
    }
}

// Get trainer's members
$stmt = $conn->prepare("
    SELECT u.*, 
           tm.created_at AS assigned_date,
           tm.specialization_focus,
           tm.goals,
           tm.status as member_status,
           (SELECT COUNT(*) FROM trainer_schedule WHERE member_id = u.id AND trainer_id = ? AND status = 'completed') as completed_sessions,
           (SELECT COUNT(*) FROM workout_plans WHERE member_id = u.id AND trainer_id = ?) as workout_plans,
           (SELECT COUNT(*) FROM nutrition_plans WHERE member_id = u.id AND trainer_id = ?) as nutrition_plans
    FROM trainer_members tm
    JOIN users u ON tm.member_id = u.id
    WHERE tm.trainer_id = ?
    ORDER BY tm.created_at DESC
");
$stmt->execute([$userId, $userId, $userId, $userId]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Get available members (not assigned to this trainer)
$stmt = $conn->prepare("
    SELECT u.* 
    FROM users u 
    WHERE u.role = 'Member' 
    AND u.id NOT IN (
        SELECT member_id FROM trainer_members WHERE trainer_id = ? AND status = 'active'
    )
    ORDER BY u.name ASC
");
$stmt->execute([$userId]);
$availableMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Members - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Include the same CSS variables and base styles from dashboard.php */
        :root {
            --primary: #ff6b35;
            --primary-dark: #e55a2b;
            --secondary: #2c3e50;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #3498db;
            --bg-dark: #0a0a0a;
            --card-dark: #1a1a1a;
            --text-dark: #ecf0f1;
            --border-dark: #333333;
            --bg-light: #f8f9fa;
            --card-light: #ffffff;
            --text-light: #2c3e50;
            --border-light: #dee2e6;
        }

        [data-theme="dark"] {
            --bg: var(--bg-dark);
            --card-bg: var(--card-dark);
            --text: var(--text-dark);
            --border: var(--border-dark);
        }

        [data-theme="light"] {
            --bg: var(--bg-light);
            --card-bg: var(--card-light);
            --text: var(--text-light);
            --border: var(--border-light);
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
            transition: all 0.3s ease;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 0.75rem;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .members-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .member-card {
            background: var(--card-bg);
            border-radius: 1.5rem;
            padding: 1.5rem;
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .member-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .member-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .member-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.5rem;
        }

        .member-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .member-info h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .member-email {
            color: var(--text);
            opacity: 0.7;
            font-size: 0.875rem;
        }

        .member-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1rem 0;
        }

        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: rgba(255, 107, 53, 0.05);
            border-radius: 0.5rem;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text);
            opacity: 0.7;
        }

        .member-details {
            margin: 1rem 0;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border);
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .member-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
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

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: var(--card-bg);
            border-radius: 1rem;
            padding: 2rem;
            width: 90%;
            max-width: 500px;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            background: var(--bg);
            color: var(--text);
        }

        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }

        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text);
            opacity: 0.7;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--primary);
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-badge.active {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .status-badge.inactive {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>My Members</h1>
                <p>Manage your assigned members and their training programs</p>
            </div>
            <button class="btn" onclick="openAssignModal()">
                <i class="fas fa-plus"></i> Assign Member
            </button>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="members-grid">
            <?php foreach ($members as $member): ?>
                <div class="member-card">
                    <div class="member-header">
                        <div class="member-avatar">
                            <?php if (!empty($member['profile_image'])): ?>
                                <img src="<?php echo htmlspecialchars($member['profile_image']); ?>" alt="Profile">
                            <?php else: ?>
                                <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="member-info">
                            <h3><?php echo htmlspecialchars($member['name']); ?></h3>
                            <div class="member-email"><?php echo htmlspecialchars($member['email']); ?></div>
                        </div>
                    </div>

                    <div class="member-stats">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $member['completed_sessions']; ?></div>
                            <div class="stat-label">Sessions</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $member['workout_plans']; ?></div>
                            <div class="stat-label">Workouts</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $member['nutrition_plans']; ?></div>
                            <div class="stat-label">Nutrition</div>
                        </div>
                    </div>

                    <div class="member-details">
                        <div class="detail-item">
                            <span>Assigned Date:</span>
                            <span><?php echo formatDate($member['assigned_date']); ?></span>
                        </div>
                        <?php if (!empty($member['specialization_focus'])): ?>
                            <div class="detail-item">
                                <span>Focus:</span>
                                <span><?php echo htmlspecialchars($member['specialization_focus']); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="detail-item">
                            <span>Status:</span>
                            <span class="status-badge <?php echo $member['member_status']; ?>">
                                <?php echo ucfirst($member['member_status']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="member-actions">
                        <a href="member-details.php?id=<?php echo $member['id']; ?>" class="btn btn-sm">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                        <a href="workouts.php?member=<?php echo $member['id']; ?>" class="btn btn-sm btn-outline">
                            <i class="fas fa-dumbbell"></i> Workouts
                        </a>
                        <a href="nutrition.php?member=<?php echo $member['id']; ?>" class="btn btn-sm btn-outline">
                            <i class="fas fa-apple-alt"></i> Nutrition
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($members)): ?>
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <h3>No members assigned yet</h3>
                <p>Start by assigning members to your training program</p>
                <button class="btn" onclick="openAssignModal()">
                    <i class="fas fa-plus"></i> Assign Your First Member
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Assign Member Modal -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <h2>Assign New Member</h2>
            <form method="POST">
                <input type="hidden" name="action" value="assign_member">
                
                <div class="form-group">
                    <label for="member_id">Select Member:</label>
                    <select name="member_id" id="member_id" required>
                        <option value="">Choose a member...</option>
                        <?php foreach ($availableMembers as $member): ?>
                            <option value="<?php echo $member['id']; ?>">
                                <?php echo htmlspecialchars($member['name']); ?> (<?php echo htmlspecialchars($member['email']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="specialization_focus">Specialization Focus:</label>
                    <input type="text" name="specialization_focus" id="specialization_focus" 
                           placeholder="e.g., Weight Loss, Muscle Building, Cardio">
                </div>

                <div class="form-group">
                    <label for="goals">Member Goals:</label>
                    <textarea name="goals" id="goals" rows="3" 
                              placeholder="Describe the member's fitness goals and objectives"></textarea>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn">
                        <i class="fas fa-plus"></i> Assign Member
                    </button>
                    <button type="button" class="btn btn-outline" onclick="closeAssignModal()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAssignModal() {
            document.getElementById('assignModal').style.display = 'block';
        }

        function closeAssignModal() {
            document.getElementById('assignModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('assignModal');
            if (event.target === modal) {
                closeAssignModal();
            }
        }
    </script>
</body>
</html>