<?php
/**
 * Rate limiter for various actions
 */

/**
 * Checks if a user is rate limited
 * @param string $ipAddress The user's IP address
 * @param string $actionType The type of action being limited (e.g., 'login', 'password_reset')
 * @param int $maxAttempts Maximum allowed attempts
 * @param int $timeWindow Time window in seconds
 * @return bool True if rate limited, false otherwise
 */
function isRateLimited($ipAddress, $actionType, $maxAttempts, $timeWindow) {
    try {
        $conn = connectDB();
        
        // Check if there's an existing record
        $stmt = $conn->prepare("
            SELECT attempts, expires_at 
            FROM rate_limits 
            WHERE ip_address = ? AND action_type = ?
        ");
        $stmt->execute([$ipAddress, $actionType]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            // Check if the time window has expired
            if (strtotime($result['expires_at']) < time()) {
                // Reset the counter
                $stmt = $conn->prepare("
                    DELETE FROM rate_limits 
                    WHERE ip_address = ? AND action_type = ?
                ");
                $stmt->execute([$ipAddress, $actionType]);
                return false;
            } else {
                // Check if attempts exceed max
                return $result['attempts'] >= $maxAttempts;
            }
        }
        
        return false;
    } catch (PDOException $e) {
        error_log("Rate limiter error: " . $e->getMessage());
        // Fail open - don't rate limit if there's a database error
        return false;
    }
}

/**
 * Increments the rate limiter counter
 * @param string $ipAddress The user's IP address
 * @param string $actionType The type of action being limited
 */
function incrementRateLimiter($ipAddress, $actionType) {
    try {
        $conn = connectDB();
        
        // Default values (5 attempts per hour)
        $maxAttempts = 5;
        $timeWindow = 3600;
        
        // Check if there's an existing record
        $stmt = $conn->prepare("
            SELECT attempts, expires_at 
            FROM rate_limits 
            WHERE ip_address = ? AND action_type = ?
        ");
        $stmt->execute([$ipAddress, $actionType]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $expiresAt = date('Y-m-d H:i:s', time() + $timeWindow);
        
        if ($result) {
            // Update existing record
            $stmt = $conn->prepare("
                UPDATE rate_limits 
                SET attempts = attempts + 1, 
                    expires_at = ?
                WHERE ip_address = ? AND action_type = ?
            ");
            $stmt->execute([$expiresAt, $ipAddress, $actionType]);
        } else {
            // Create new record
            $stmt = $conn->prepare("
                INSERT INTO rate_limits 
                (ip_address, action_type, attempts, expires_at) 
                VALUES (?, ?, 1, ?)
            ");
            $stmt->execute([$ipAddress, $actionType, $expiresAt]);
        }
    } catch (PDOException $e) {
        error_log("Failed to increment rate limiter: " . $e->getMessage());
    }
}
?>