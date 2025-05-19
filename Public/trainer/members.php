<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Trainer role to access this page
requireRole('Trainer');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Connect to database
$conn = connectDB();

// Include theme preference helper
require_once 'dashboard-theme-fix.php';
$theme = getThemePreference($conn, $userId);

// Get all members assigned to this trainer
$members = [];
try {
    // Check if trainer_members table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'trainer_members'")->rowCount() > 0;

    if ($tableExists) {
        // Check if status column exists in trainer_members
        $statusColumnExists = $conn->query("SHOW COLUMNS FROM trainer_members LIKE 'status'")->rowCount() > 0;
        
        $memberQuery = "
            SELECT u.id, u.name, u.email, u.phone, u.profile_image, tm.joined_date
            FROM trainer_members tm
            JOIN users u ON tm.member_id = u.id
            WHERE tm.trainer_id = ?
        ";
        
        if ($statusColumnExists) {
            $memberQuery .= " AND tm.status = 'active'";
        }
        
        $memberQuery .= " ORDER BY u.name ASC";
        
        $memberStmt = $conn->prepare($memberQuery);
        $memberStmt->execute([$userId]);
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Handle error - empty members array already set
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_member'])) {
        // Add new member
        try {
            $memberEmail = $_POST['email'];
            
            // Check if user exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND role = 'Member'");
            $stmt->execute([$memberEmail]);
            $memberUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($memberUser) {
                $memberId = $memberUser['id'];
                
                // Check if already assigned
                $stmt = $conn->prepare("SELECT id FROM trainer_members WHERE trainer_id = ? AND member_id = ?");
                $stmt->execute([$userId, $memberId]);
                
                if ($stmt->rowCount() === 0) {
                    // Create trainer_members table if it doesn't exist
                    if (!$tableExists) {
                        $conn->exec("
                            CREATE TABLE trainer_members (
                                id INT AUTO_INCREMENT PRIMARY KEY,
                                trainer_id INT NOT NULL,
                                member_id INT NOT NULL,
                                status VARCHAR(20) DEFAULT 'active',
                                joined_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                notes TEXT,
                                UNIQUE KEY (trainer_id, member_id)
                            )
                        ");
                    }
                    
                    // Add member to trainer
                    $stmt = $conn->prepare("
                        INSERT INTO trainer_members (trainer_id, member_id, notes) 
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$userId, $memberId, $_POST['notes'] ?? '']);
                    
                    $message = 'Member added successfully!';
                    $messageType = 'success';
                    
                    // Redirect to prevent form resubmission
                    header("Location: members.php?added=1");
                    exit;
                } else {
                    $message = 'This member is already assigned to you.';
                    $messageType = 'warning';
                }
            } else {
                $message = 'No member found with that email address.';
                $messageType = 'error';
            }
        } catch (PDOException $e) {
            $message = 'Error adding member: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['remove_member'])) {
        // Remove member
        try {
            $memberId = $_POST['member_id'];
            
            $stmt = $conn->prepare("DELETE FROM trainer_members WHERE trainer_id = ? AND member_id = ?");
            $stmt->execute([$userId, $memberId]);
            
            $message = 'Member removed successfully!';
            $messageType = 'success';
            
            // Redirect to prevent form resubmission
            header("Location: members.php?removed=1");
            exit;
        } catch (PDOException $e) {
            $message = 'Error removing member: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Check for URL parameters
if (isset($_GET['added']) && $_GET['added'] == '1') {
    $message = 'Member added successfully!';
    $messageType = 'success';
}

if (isset($_GET['removed']) && $_GET['removed'] == '1') {
    $message = 'Member removed successfully!';
    $messageType = 'success';
}

// Format date for display
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/trainer-dashboard.css">
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
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Main</div>
                <ul class="sidebar-menu">
                    <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                    <li><a href="my-profile.php"><i class="fas fa-user"></i> <span>My Profile</span></a></li>
                    <li><a href="members.php" class="active"><i class="fas fa-users"></i> <span>Members</span></a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Training</div>
                <ul class="sidebar-menu">
                    <li><a href="workout-plans.php"><i class="fas fa-dumbbell"></i> <span>Workout Plans</span></a></li>
                    <li><a href="schedule.php"><i class="fas fa-calendar-alt"></i> <span>Schedule</span></a></li>
                    <li><a href="progress-tracking.php"><i class="fas fa-chart-line"></i> <span>Progress Tracking</span></a></li>
                </ul>
            </div>
            
            <div class="sidebar-section">
                <div class="sidebar-section-title">Account</div>
                <ul class="sidebar-menu">
                    <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                    <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>Members</h1>
                    <p>Manage your assigned members</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" data-modal="addMemberModal">
                        <i class="fas fa-plus"></i> Add Member
                    </button>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <div><?php echo $message; ?></div>
                    <button type="button" class="close">&times;</button>
                </div>
            <?php endif; ?>
            
            <!-- Members List -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-users"></i> Your Members</h2>
                    <div class="card-actions">
                        <div class="search-box">
                            <input type="text" placeholder="Search members..." id="memberSearch">
                            <i class="fas fa-search"></i>
                        </div>
                    </div>
                </div>
                <div class="card-content">
                    <?php if (empty($members)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <p>No members assigned yet</p>
                            <button class="btn btn-primary" data-modal="addMemberModal">Add Your First Member</button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table" data-sortable id="membersTable">
                                <thead>
                                    <tr>
                                        <th data-sortable>Name</th>
                                        <th data-sortable>Email</th>
                                        <th data-sortable>Phone</th>
                                        <th data-sortable>Joined Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($members as $member): ?>
                                        <tr>
                                            <td>
                                                <div class="member-info">
                                                    <?php if (!empty($member['profile_image'])): ?>
                                                        <img src="<?php echo htmlspecialchars($member['profile_image']); ?>" alt="Profile" class="member-avatar">
                                                    <?php else: ?>
                                                        <div class="member-avatar-placeholder">
                                                            <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <span><?php echo htmlspecialchars($member['name']); ?></span>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($member['email']); ?></td>
                                            <td><?php echo !empty($member['phone']) ? htmlspecialchars($member['phone']) : '-'; ?></td>
                                            <td><?php echo isset($member['joined_date']) ? formatDate($member['joined_date']) : '-'; ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="member-details.php?id=<?php echo $member['id']; ?>" class="btn btn-sm btn-outline" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="workout-plans.php?member_id=<?php echo $member['id']; ?>" class="btn btn-sm btn-outline" title="Workout Plans">
                                                        <i class="fas fa-dumbbell"></i>
                                                    </a>
                                                    <a href="progress-tracking.php?member_id=<?php echo $member['id']; ?>" class="btn btn-sm btn-outline" title="Progress Tracking">
                                                        <i class="fas fa-chart-line"></i>
                                                    </a>
                                                    <a href="schedule.php?member_id=<?php echo $member['id']; ?>" class="btn btn-sm btn-outline" title="Schedule">
                                                        <i class="fas fa-calendar-alt"></i>
                                                    </a>
                                                    <button class="btn btn-sm btn-danger" onclick="confirmRemoveMember(<?php echo $member['id']; ?>, '<?php echo htmlspecialchars($member['name']); ?>')" title="Remove Member">
                                                        <i class="fas fa-user-minus"></i>
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
        </div>
    </div>
    
    <!-- Add Member Modal -->
    <div class="modal" id="addMemberModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Member</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="members.php" method="post" data-validate>
                    <input type="hidden" name="add_member" value="1">
                    
                    <div class="form-group">
                        <label for="email">Member Email</label>
                        <input type="email" id="email" name="email" class="form-control" required>
                        <div class="form-text">Enter the email of an existing gym member to assign them to you.</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes (Optional)</label>
                        <textarea id="notes" name="notes" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Remove Member Confirmation Modal -->
    <div class="modal" id="removeMemberModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Remove Member</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to remove <span id="memberNameToRemove"></span> from your members list?</p>
                <p>This will not delete the member's account, but they will no longer be assigned to you.</p>
                
                <form action="members.php" method="post">
                    <input type="hidden" name="remove_member" value="1">
                    <input type="hidden" id="memberIdToRemove" name="member_id" value="">
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline close-modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Remove Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../assets/js/trainer-dashboard.js"></script>
    <script>
        // Search functionality
        document.getElementById('memberSearch').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const table = document.getElementById('membersTable');
            
            if (table) {
                const rows = table.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchValue) ? '' : 'none';
                });
            }
        });
        
        // Confirm remove member
        function confirmRemoveMember(memberId, memberName) {
            document.getElementById('memberIdToRemove').value = memberId;
            document.getElementById('memberNameToRemove').textContent = memberName;
            
            const modal = document.getElementById('removeMemberModal');
            if (modal) {
                openModal(modal);
            }
        }
    </script>
</body>
</html>
