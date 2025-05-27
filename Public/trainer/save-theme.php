<?php
session_start();

// Check if user is logged in and is a trainer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Trainer') {
    http_response_code(403);
    exit('Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['theme'])) {
    $theme = $_POST['theme'];
    
    // Validate theme value
    if (!in_array($theme, ['light', 'dark'])) {
        http_response_code(400);
        exit('Invalid theme');
    }
    
    require_once __DIR__ . '/../config/database.php';
    $conn = connectDB();
    
    require_once 'trainer-theme-helper.php';
    
    if (saveThemePreference($conn, $_SESSION['user_id'], $theme)) {
        echo 'Theme saved successfully';
    } else {
        http_response_code(500);
        echo 'Failed to save theme';
    }
} else {
    http_response_code(400);
    echo 'Invalid request';
}
?>
