<?php
/**
 * Enhanced Theme Preference Helper for Trainer Dashboard
 */

function getThemePreference($conn, $userId) {
    $theme = 'dark'; // Default to dark theme

    try {
        // Check if trainer_settings table exists
        $tableExists = $conn->query("SHOW TABLES LIKE 'trainer_settings'")->rowCount() > 0;

        if (!$tableExists) {
            // Create trainer_settings table
            $conn->exec("
                CREATE TABLE trainer_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    theme_preference VARCHAR(20) DEFAULT 'dark',
                    notification_email TINYINT(1) DEFAULT 1,
                    notification_sms TINYINT(1) DEFAULT 0,
                    auto_confirm_appointments TINYINT(1) DEFAULT 0,
                    default_session_duration INT DEFAULT 60,
                    availability_monday VARCHAR(50) DEFAULT '09:00-17:00',
                    availability_tuesday VARCHAR(50) DEFAULT '09:00-17:00',
                    availability_wednesday VARCHAR(50) DEFAULT '09:00-17:00',
                    availability_thursday VARCHAR(50) DEFAULT '09:00-17:00',
                    availability_friday VARCHAR(50) DEFAULT '09:00-17:00',
                    availability_saturday VARCHAR(50) DEFAULT '09:00-13:00',
                    availability_sunday VARCHAR(50) DEFAULT '',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY (user_id)
                )
            ");
            
            // Insert default settings for this user
            $stmt = $conn->prepare("INSERT INTO trainer_settings (user_id) VALUES (?)");
            $stmt->execute([$userId]);
        }

        // Get theme preference
        $stmt = $conn->prepare("SELECT theme_preference FROM trainer_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && isset($result['theme_preference'])) {
            $theme = $result['theme_preference'];
        }
    } catch (PDOException $e) {
        error_log('Theme preference error: ' . $e->getMessage());
    }

    return $theme;
}

function saveThemePreference($conn, $userId, $theme) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO trainer_settings (user_id, theme_preference) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE theme_preference = ?
        ");
        $stmt->execute([$userId, $theme, $theme]);
        return true;
    } catch (PDOException $e) {
        error_log('Save theme error: ' . $e->getMessage());
        return false;
    }
}
?>
