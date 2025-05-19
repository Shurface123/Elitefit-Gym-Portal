<?php
/**
 * This script should be run as a cron job to clean up expired password reset tokens
 * Recommended to run daily: 0 0 * * * /usr/bin/php /path/to/cleanup_tokens.php
 */

// Include database connection
require_once dirname(__DIR__) . '/config/db_connect.php';

// Delete expired tokens
$stmt = $conn->prepare("DELETE FROM password_reset WHERE expires < NOW()");
$stmt->execute();

$deletedCount = $stmt->affected_rows;

// Log the cleanup operation
$logMessage = date('Y-m-d H:i:s') . " - Cleaned up $deletedCount expired password reset tokens\n";
file_put_contents(dirname(__DIR__) . '/logs/token_cleanup.log', $logMessage, FILE_APPEND);

echo "Cleanup completed. Removed $deletedCount expired tokens.\n";

// Close connection
$conn->close();
