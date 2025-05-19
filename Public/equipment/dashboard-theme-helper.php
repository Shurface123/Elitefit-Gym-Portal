<?php
/**
 * Theme Helper for Equipment Manager Dashboard
 * Handles theme preferences and switching
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Function to get theme preference for a user
function getThemePreference($userId) {
    try {
        $conn = connectDB();
        
        // First check if the dashboard_settings table exists
        $tableCheckStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = 'dashboard_settings'
        ");
        $tableCheckStmt->execute();
        $tableExists = $tableCheckStmt->fetchColumn();
        
        if (!$tableExists) {
            // Create the dashboard_settings table if it doesn't exist
            $createTableStmt = $conn->prepare("
                CREATE TABLE IF NOT EXISTS dashboard_settings (
                    id INT NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    theme VARCHAR(20) NOT NULL DEFAULT 'dark',
                    layout VARCHAR(20) NOT NULL DEFAULT 'default',
                    widgets JSON DEFAULT NULL COMMENT 'Stores user widget preferences',
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY user_id (user_id)
                )
            ");
            $createTableStmt->execute();
        }
        
        // Check if the theme_preference column exists
        $columnCheckStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM information_schema.columns 
            WHERE table_schema = DATABASE() 
            AND table_name = 'dashboard_settings' 
            AND column_name = 'theme_preference'
        ");
        $columnCheckStmt->execute();
        $columnExists = $columnCheckStmt->fetchColumn();
        
        if (!$columnExists) {
            // Add the theme_preference column if it doesn't exist
            $addColumnStmt = $conn->prepare("
                ALTER TABLE dashboard_settings 
                ADD COLUMN theme_preference VARCHAR(20) DEFAULT 'dark' AFTER user_id
            ");
            $addColumnStmt->execute();
        }
        
        // Check if user has a theme preference
        $stmt = $conn->prepare("
            SELECT theme FROM dashboard_settings WHERE user_id = :user_id
        ");
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        $theme = $stmt->fetchColumn();
        
        if (!$theme) {
            // If no preference exists, create default
            $insertStmt = $conn->prepare("
                INSERT INTO dashboard_settings (user_id, theme) 
                VALUES (:user_id, 'dark')
                ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
            ");
            $insertStmt->bindParam(':user_id', $userId);
            $insertStmt->execute();
            
            return 'dark';
        }
        
        return $theme;
    } catch (PDOException $e) {
        // Log error but return a default theme to prevent breaking the UI
        error_log("Theme preference error: " . $e->getMessage());
        return 'dark';
    }
}

// Function to get theme classes based on preference
function getThemeClasses($theme) {
    $classes = [];
    
    if ($theme === 'dark') {
        $classes['body'] = 'dark-theme';
        $classes['text'] = 'text-light';
        $classes['bg'] = 'bg-dark';
        $classes['card'] = 'bg-dark text-light';
        $classes['table'] = 'table-dark';
    } else {
        $classes['body'] = 'light-theme';
        $classes['text'] = 'text-dark';
        $classes['bg'] = 'bg-light';
        $classes['card'] = 'bg-white';
        $classes['table'] = '';
    }
    
    return $classes;
}

// Function to save theme preference
function saveThemePreference($userId, $theme) {
    try {
        $conn = connectDB();
        
        $stmt = $conn->prepare("
            INSERT INTO dashboard_settings (user_id, theme) 
            VALUES (:user_id, :theme)
            ON DUPLICATE KEY UPDATE theme = :theme, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':theme', $theme);
        $stmt->execute();
        
        return true;
    } catch (PDOException $e) {
        error_log("Save theme error: " . $e->getMessage());
        return false;
    }
}
?>
