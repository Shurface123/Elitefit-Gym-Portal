<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Admin role to access this page
requireRole('Admin');

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['user_id'])) {
    $userId = (int)$_POST['user_id'];
    
    // Connect to database
    $conn = connectDB();
    
    try {
        // Start transaction
        $conn->beginTransaction();
        
        // Get user details before deletion for logging
        $stmt = $conn->prepare("SELECT name, email, role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("User not found");
        }
        
        // Check if user is an admin
        if ($user['role'] === 'Admin' && $userId === $_SESSION['user_id']) {
            throw new Exception("You cannot delete your own admin account");
        }
        
        // Delete related records first (foreign key constraints)
        // Delete remember tokens
        $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // If user is a trainer, delete trainer-member relationships
        if ($user['role'] === 'Trainer') {
            $stmt = $conn->prepare("DELETE FROM trainer_members WHERE trainer_id = ?");
            $stmt->execute([$userId]);
            
            $stmt = $conn->prepare("DELETE FROM workouts WHERE trainer_id = ?");
            $stmt->execute([$userId]);
        }
        
        // If user is a member, delete trainer-member relationships and workouts
        if ($user['role'] === 'Member') {
            $stmt = $conn->prepare("DELETE FROM trainer_members WHERE member_id = ?");
            $stmt->execute([$userId]);
            
            $stmt = $conn->prepare("DELETE FROM workouts WHERE member_id = ?");
            $stmt->execute([$userId]);
        }
        
        // Delete the user
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        
        // Log the action
        $adminId = $_SESSION['user_id'];
        $adminName = $_SESSION['name'];
        
        // Check if admin_logs table exists
        $checkTableStmt = $conn->prepare("
            SELECT COUNT(*) as table_exists 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = 'admin_logs'
        ");
        $checkTableStmt->execute();
        $tableExists = $checkTableStmt->fetch(PDO::FETCH_ASSOC)['table_exists'];
        
        // Create admin_logs table if it doesn't exist
        if (!$tableExists) {
            $createTableStmt = $conn->prepare("
                CREATE TABLE admin_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_id INT NOT NULL,
                    action VARCHAR(50) NOT NULL,
                    details TEXT,
                    ip_address VARCHAR(45),
                    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $createTableStmt->execute();
        }
        
        // Log the action
        $logStmt = $conn->prepare("
            INSERT INTO admin_logs (admin_id, action, details, ip_address, timestamp)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $details = "Deleted user: " . $user['name'] . " (" . $user['email'] . ") with role " . $user['role'];
        $logStmt->execute([$adminId, "delete_user", $details, $_SERVER['REMOTE_ADDR']]);
        
        // Commit transaction
        $conn->commit();
        
        // Set success message
        $_SESSION['success_message'] = "User " . $user['name'] . " has been successfully deleted.";
        
        // Redirect back to users page
        header("Location: users.php");
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        
        // Set error message
        $_SESSION['error_message'] = "Error deleting user: " . $e->getMessage();
        
        // Redirect back to users page
        header("Location: users.php");
        exit;
    }
} else {
    // If not a POST request or no user_id, redirect to users page
    header("Location: users.php");
    exit;
}
?>
