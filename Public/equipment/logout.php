<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once __DIR__ . '/../db_connect.php';

// Log the logout activity if user is logged in
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    
    try {
        $conn = connectDB();
        
        // Log the logout activity
        $stmt = $conn->prepare("
            INSERT INTO activity_log 
            (user_id, action, details, ip_address) 
            VALUES (?, 'Logout', 'User logged out', ?)
        ");
        
        $stmt->execute([$userId, $_SERVER['REMOTE_ADDR']]);
        
        // Update user's last logout time
        $stmt = $conn->prepare("
            UPDATE users 
            SET last_logout = NOW() 
            WHERE id = ?
        ");
        
        $stmt->execute([$userId]);
    } catch (Exception $e) {
        // Just log the error, don't stop the logout process
        error_log("Error logging logout: " . $e->getMessage());
    }
}

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: login.php");
exit;
?>