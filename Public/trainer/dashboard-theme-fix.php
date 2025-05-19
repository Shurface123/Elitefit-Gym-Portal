<?php
/**
 * Theme Preference Helper Function
 * Include this file at the top of all trainer dashboard files to handle theme preferences
 */

// Function to get the theme preference with proper error handling
function getThemePreference($conn, $userId) {
    // Set default theme
    $theme = 'dark';

    try {
        // First check if the trainer_settings table exists
        $tableExists = $conn->query("SHOW TABLES LIKE 'trainer_settings'")->rowCount() > 0;

        if (!$tableExists) {
            // Create trainer_settings table if it doesn't exist
            $conn->exec("
                CREATE TABLE trainer_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    theme_preference VARCHAR(20) DEFAULT 'dark',
                    notification_email TINYINT(1) DEFAULT 1,
                    notification_sms TINYINT(1) DEFAULT 0,
                    auto_confirm_appointments TINYINT(1) DEFAULT 0,
                    availability_monday VARCHAR(20) DEFAULT '09:00-17:00',
                    availability_tuesday VARCHAR(20) DEFAULT '09:00-17:00',
                    availability_wednesday VARCHAR(20) DEFAULT '09:00-17:00',
                    availability_thursday VARCHAR(20) DEFAULT '09:00-17:00',
                    availability_friday VARCHAR(20) DEFAULT '09:00-17:00',
                    availability_saturday VARCHAR(20) DEFAULT '09:00-13:00',
                    availability_sunday VARCHAR(20) DEFAULT '',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY (user_id)
                )
            ");
            
            // Insert default settings for this user
            $stmt = $conn->prepare("INSERT INTO trainer_settings (user_id) VALUES (?)");
            $stmt->execute([$userId]);
        } else {
            // Check if theme_preference column exists
            $columnExists = $conn->query("SHOW COLUMNS FROM trainer_settings LIKE 'theme_preference'")->rowCount() > 0;
            
            if (!$columnExists) {
                // Add theme_preference column if it doesn't exist
                $conn->exec("ALTER TABLE trainer_settings ADD COLUMN theme_preference VARCHAR(20) DEFAULT 'dark' AFTER user_id");
            }
        }

        // Now try to get the theme preference
        $stmt = $conn->prepare("SELECT theme_preference FROM trainer_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $themeResult = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($themeResult && isset($themeResult['theme_preference'])) {
            $theme = $themeResult['theme_preference'];
        }
    } catch (PDOException $e) {
        // If there's any error, just use the default theme
        // You might want to log this error for debugging
        // error_log('Theme preference error: ' . $e->getMessage());
    }

    return $theme;
}
