<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require EquipmentManager role to access this page
requireRole('EquipmentManager');

// Connect to database
$conn = connectDB();

// Get user data
$userId = $_SESSION['user_id'];

// Set response header
header('Content-Type: application/json');

// Check if request is AJAX
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Validate input
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        exit;
    }
    
    // Check if new passwords match
    if ($newPassword !== $confirmPassword) {
        echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
        exit;
    }
    
    // Check password strength
    if (strlen($newPassword) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long']);
        exit;
    }
    
    // Get current password hash
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
    $stmt->bindParam(':id', $userId);
    $stmt->execute();
    $currentHash = $stmt->fetchColumn();
    
    // Verify current password
    if (!password_verify($currentPassword, $currentHash)) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
        exit;
    }
    
    // Hash new password
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // Update password
    $updateStmt = $conn->prepare("
        UPDATE users 
        SET password = :password
        WHERE id = :id
    ");
    $updateStmt->bindParam(':password', $newHash);
    $updateStmt->bindParam(':id', $userId);
    
    if ($updateStmt->execute()) {
        // Log activity
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action)
            VALUES (:user_id, :action)
        ");
        $action = "Changed account password";
        $logStmt->bindParam(':user_id', $userId);
        $logStmt->bindParam(':action', $action);
        $logStmt->execute();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Password changed successfully!'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error changing password']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
