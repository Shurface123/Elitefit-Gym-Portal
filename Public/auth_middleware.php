<?php
/**
 * Role-Based Access Control Middleware
 * This file handles authentication and authorization for EliteFit Gym
 */

// Include database connection
require_once __DIR__ . '/db_connect.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is authenticated
 * @return bool True if authenticated, false otherwise
 */
function isAuthenticated() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if user has specific role
 * @param string|array $roles Role or array of roles to check
 * @return bool True if user has role, false otherwise
 */
function hasRole($roles) {
    if (!isAuthenticated()) {
        return false;
    }
    
    if (is_array($roles)) {
        return is_array($_SESSION['role'], $roles);
    } else {
        return $_SESSION['role'] === $roles;
    }
}

/**
 * Require authentication to access page
 * @param string $redirect URL to redirect to if not authenticated
 */
function requireAuth($redirect = '../login.php') {
    if (!isAuthenticated()) {
        $_SESSION['login_error'] = "Please log in to access this page";
        header("Location: $redirect");
        exit;
    }
}

/**
 * Require specific role to access page
 * @param string|array $roles Role or array of roles allowed to access
 * @param string $redirect URL to redirect to if not authorized
 */
function requireRole($roles, $redirect = '../login.php') {
    requireAuth($redirect);
    
    if (!hasRole($roles)) {
        $_SESSION['login_error'] = "You don't have permission to access this page";
        header("Location: $redirect");
        exit;
    }
}

/**
 * Check for remember me cookie and log user in if valid
 */
function checkRememberMe() {
    if (!isAuthenticated() && isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        
        // Connect to database
        $conn = connectDB();
        
        // Check if token exists and is valid
        $stmt = $conn->prepare("
            SELECT u.id, u.name, u.email, u.role 
            FROM remember_tokens rt
            JOIN users u ON rt.user_id = u.id
            WHERE rt.token = ? AND rt.expires_at > NOW()
        ");
        $stmt->execute([$token]);
        
        if ($stmt->rowCount() === 1) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            
            // Update token expiry
            $updateStmt = $conn->prepare("UPDATE remember_tokens SET expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE token = ?");
            $updateStmt->execute([$token]);
        }
    }
}

// Check for remember me cookie on every page load
checkRememberMe();
?>

