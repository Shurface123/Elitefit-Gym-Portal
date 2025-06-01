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
        
        // Check the structure of admin_logs table to determine available columns
        $checkColumnsStmt = $conn->prepare("
            SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'admin_logs'
        ");
        $checkColumnsStmt->execute();
        $columns = $checkColumnsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // If admin_logs table doesn't exist, create it with minimal required columns
        if (empty($columns)) {
            $createTableStmt = $conn->prepare("
                CREATE TABLE admin_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_id INT NOT NULL,
                    action VARCHAR(50) NOT NULL,
                    log_message TEXT,
                    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $createTableStmt->execute();
            $columns = ['id', 'admin_id', 'action', 'log_message', 'timestamp'];
        }
        
        // Prepare log message
        $logMessage = "Deleted user: " . $user['name'] . " (" . $user['email'] . ") with role " . $user['role'];
        
        // Build dynamic SQL query based on available columns
        $sql = "INSERT INTO admin_logs (";
        $params = [];
        $placeholders = [];
        
        // Always include admin_id and action
        $sql .= "admin_id, action";
        $params[] = $adminId;
        $params[] = "delete_user";
        $placeholders[] = "?";
        $placeholders[] = "?";
        
        // Check for message column (could be 'details' or 'log_message')
        if (in_array('details', $columns)) {
            $sql .= ", details";
            $params[] = $logMessage;
            $placeholders[] = "?";
        } elseif (in_array('log_message', $columns)) {
            $sql .= ", log_message";
            $params[] = $logMessage;
            $placeholders[] = "?";
        }
        
        // Check for ip_address column
        if (in_array('ip_address', $columns)) {
            $sql .= ", ip_address";
            $params[] = $_SERVER['REMOTE_ADDR'];
            $placeholders[] = "?";
        }
        
        // Add timestamp if it's not auto-generated
        if (in_array('timestamp', $columns) && !in_array('CURRENT_TIMESTAMP', $columns)) {
            $sql .= ", timestamp";
            $placeholders[] = "NOW()";
        }
        
        // Complete the SQL query
        $sql .= ") VALUES (" . implode(", ", $placeholders) . ")";
        
        // Execute the log query
        $logStmt = $conn->prepare($sql);
        $logStmt->execute($params);
        
        // Commit transaction
        $conn->commit();
        
        // Set success message
        $_SESSION['success_message'] = "User " . $user['name'] . " has been successfully deleted.";
        
        // Redirect back to users page
        header("Location: users.php");
        exit;
        
    } catch (Exception $e) {
        // Check if a transaction is active before attempting to roll it back
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        
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
