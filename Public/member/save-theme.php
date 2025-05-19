<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Member role to access this page
requireRole('Member');

// Get user data
$userId = $_SESSION['user_id'];

// Connect to database
$conn = connectDB();

// Get theme from POST data
$theme = isset($_POST['theme']) ? $_POST['theme'] : 'dark';

// Validate theme
if (!in_array($theme, ['light', 'dark', 'orange'])) {
   $theme = 'dark';
}

try {
   // Check if member_settings table exists
   $tableExists = $conn->query("SHOW TABLES LIKE 'member_settings'")->rowCount() > 0;

   if (!$tableExists) {
       // Create member_settings table if it doesn't exist
       $conn->exec("
           CREATE TABLE member_settings (
               id INT AUTO_INCREMENT PRIMARY KEY,
               user_id INT NOT NULL,
               theme_preference VARCHAR(20) DEFAULT 'dark',
               notification_email TINYINT(1) DEFAULT 1,
               notification_sms TINYINT(1) DEFAULT 0,
               show_weight_on_profile TINYINT(1) DEFAULT 0,
               measurement_unit VARCHAR(10) DEFAULT 'metric',
               created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
               updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               UNIQUE KEY (user_id)
           )
       ");
       
       // Insert default settings with the new theme
       $stmt = $conn->prepare("INSERT INTO member_settings (user_id, theme_preference) VALUES (?, ?)");
       $stmt->execute([$userId, $theme]);
   } else {
       // Check if user has settings
       $stmt = $conn->prepare("SELECT id FROM member_settings WHERE user_id = ?");
       $stmt->execute([$userId]);
       
       if ($stmt->rowCount() > 0) {
           // Update existing settings
           $stmt = $conn->prepare("UPDATE member_settings SET theme_preference = ? WHERE user_id = ?");
           $stmt->execute([$theme, $userId]);
       } else {
           // Insert new settings
           $stmt = $conn->prepare("INSERT INTO member_settings (user_id, theme_preference) VALUES (?, ?)");
           $stmt->execute([$userId, $theme]);
       }
   }
   
   // Return success response
   header('Content-Type: application/json');
   echo json_encode(['success' => true]);
   
} catch (PDOException $e) {
   // Return error response
   header('Content-Type: application/json');
   echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
