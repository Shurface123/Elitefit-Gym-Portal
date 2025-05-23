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
        
        // Get user details before archiving for logging
        $stmt = $conn->prepare("SELECT name, email, role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("User not found");
        }
        
        // Check if user is an admin
        if ($user['role'] === 'Admin' && $userId === $_SESSION['user_id']) {
            throw new Exception("You cannot archive your own admin account");
        }
        
        // Check if archived_users table exists
        $checkTableStmt = $conn->prepare("
            SELECT COUNT(*) as table_exists 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = 'archived_users'
        ");
        $checkTableStmt->execute();
        $tableExists = $checkTableStmt->fetch(PDO::FETCH_ASSOC)['table_exists'];
        
        // Create archived_users table if it doesn't exist (matching your existing structure)
        if (!$tableExists) {
            $createTableStmt = $conn->prepare("
                CREATE TABLE archived_users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    original_id INT,
                    name VARCHAR(100) NOT NULL,
                    email VARCHAR(100) NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    role ENUM('Admin','Trainer','Member') NOT NULL,
                    created_at TIMESTAMP NULL,
                    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    archived_by INT NOT NULL,
                    archive_reason VARCHAR(255) DEFAULT 'User archived by admin',
                    restore_count INT DEFAULT 0,
                    last_restore_date TIMESTAMP NULL,
                    archive_notes TEXT
                )
            ");
            $createTableStmt->execute();
        }
        
        // Get all user data to copy to archived_users table
        $userDataStmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $userDataStmt->execute([$userId]);
        $userData = $userDataStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$userData) {
            throw new Exception("Unable to retrieve user data");
        }
        
        // Archive the user by copying data to archived_users table
        $archiveStmt = $conn->prepare("
            INSERT INTO archived_users 
            (original_id, name, email, password, role, created_at, archived_by, archive_reason, archive_notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $archiveReason = isset($_POST['archive_reason']) ? $_POST['archive_reason'] : 'User archived by admin';
        $archiveNotes = isset($_POST['archive_notes']) ? $_POST['archive_notes'] : null;
        $adminId = $_SESSION['user_id'];
        
        $archiveStmt->execute([
            $userId,
            $userData['name'],
            $userData['email'],
            $userData['password'],
            $userData['role'],
            $userData['created_at'],
            $adminId,
            $archiveReason,
            $archiveNotes
        ]);
        
        // Update user status to 'Archived' instead of deleting
        $updateStmt = $conn->prepare("UPDATE users SET status = 'Archived' WHERE id = ?");
        $updateStmt->execute([$userId]);
        
        // Log the action
        $adminId = $_SESSION['user_id'];
        
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
        $logMessage = "Archived user: " . $user['name'] . " (" . $user['email'] . ") with role " . $user['role'];
        
        // Build dynamic SQL query based on available columns
        $sql = "INSERT INTO admin_logs (";
        $params = [];
        $placeholders = [];
        
        // Always include admin_id and action
        $sql .= "admin_id, action";
        $params[] = $adminId;
        $params[] = "archive_user";
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
        $_SESSION['success_message'] = "User " . $user['name'] . " has been successfully archived.";
        
        // Redirect back to users page
        header("Location: users.php");
        exit;
        
    } catch (Exception $e) {
        // Check if a transaction is active before attempting to roll it back
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        
        // Set error message
        $_SESSION['error_message'] = "Error archiving user: " . $e->getMessage();
        
        // Redirect back to users page
        header("Location: users.php");
        exit;
    }
} else {
    // If not a POST request or no user_id, redirect to users page
    header("Location: users.php");
    exit;
}
?>s