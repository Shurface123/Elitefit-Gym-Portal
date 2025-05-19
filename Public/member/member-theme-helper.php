<?php
/**
* Theme Preference Helper Function for Members
* Include this file at the top of all member dashboard files to handle theme preferences
*/

// Function to get the theme preference with proper error handling
function getThemePreference($conn, $userId) {
  // Set default theme
  $theme = 'dark';

  try {
      // First check if the member_settings table exists
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
          
          // Insert default settings for this user
          $stmt = $conn->prepare("INSERT INTO member_settings (user_id) VALUES (?)");
          $stmt->execute([$userId]);
      } else {
          // Check if theme_preference column exists
          $columnExists = $conn->query("SHOW COLUMNS FROM member_settings LIKE 'theme_preference'")->rowCount() > 0;
          
          if (!$columnExists) {
              // Add theme_preference column if it doesn't exist
              $conn->exec("ALTER TABLE member_settings ADD COLUMN theme_preference VARCHAR(20) DEFAULT 'dark' AFTER user_id");
          }
      }

      // Now try to get the theme preference
      $stmt = $conn->prepare("SELECT theme_preference FROM member_settings WHERE user_id = ?");
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

// Helper function to format time ago
function timeAgo($datetime) {
  $now = new DateTime();
  $ago = new DateTime($datetime);
  $diff = $now->diff($ago);
  
  if ($diff->y > 0) {
      return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
  } elseif ($diff->m > 0) {
      return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
  } elseif ($diff->d > 0) {
      return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
  } elseif ($diff->h > 0) {
      return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
  } elseif ($diff->i > 0) {
      return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
  } else {
      return 'Just now';
  }
}

// Format date for display
function formatDate($date) {
  if (empty($date)) return 'N/A';
  return date('M j, Y', strtotime($date));
}

// Format time for display
function formatTime($time) {
  if (empty($time)) return 'N/A';
  return date('g:i A', strtotime($time));
}
