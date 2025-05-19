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
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    
    // Validate input
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Name is required']);
        exit;
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Valid email is required']);
        exit;
    }
    
    // Check if email is already in use by another user
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
    $checkStmt->bindParam(':email', $email);
    $checkStmt->bindParam(':id', $userId);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'Email is already in use by another user']);
        exit;
    }
    
    // Update user profile
    $stmt = $conn->prepare("
        UPDATE users 
        SET name = :name, email = :email, phone = :phone
        WHERE id = :id
    ");
    $stmt->bindParam(':name', $name);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':phone', $phone);
    $stmt->bindParam(':id', $userId);
    
    if ($stmt->execute()) {
        // Update session data
        $_SESSION['name'] = $name;
        $_SESSION['email'] = $email;
        
        // Log activity
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (user_id, action)
            VALUES (:user_id, :action)
        ");
        $action = "Updated profile information";
        $logStmt->bindParam(':user_id', $userId);
        $logStmt->bindParam(':action', $action);
        $logStmt->execute();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Profile updated successfully!',
            'user' => [
                'name' => $name,
                'email' => $email,
                'phone' => $phone
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating profile']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
