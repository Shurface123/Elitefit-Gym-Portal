<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Admin role to access this page
requireRole('Admin');

// Check if user ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "User ID is required";
    header("Location: archived-users.php");
    exit;
}

$archivedUserId = (int)$_GET['id'];

// Connect to database
require_once __DIR__ . '/../db_connect.php';
$conn = connectDB();

try {
    // Start transaction
    $conn->beginTransaction();
    
    // Get archived user details with improved error handling
    $stmt = $conn->prepare("SELECT * FROM archived_users WHERE id = ?");
    $stmt->execute([$archivedUserId]);
    $archivedUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$archivedUser) {
        throw new Exception("Archived user not found. Please check the user ID and try again.");
    }
    
    // Parse the archived user data with improved error handling
    $userData = null;
    
    // Check if user_data exists and is not empty
    if (empty($archivedUser['user_data'])) {
        // Try to reconstruct user data from other fields if user_data is empty
        $userData = [
            'id' => $archivedUser['original_id'],
            'name' => $archivedUser['name'] ?? null,
            'email' => $archivedUser['email'] ?? null,
            'role' => $archivedUser['role'] ?? null,
            'status' => 'Active'
        ];
        
        // Log this reconstruction
        error_log("Reconstructed user data for archived user ID: $archivedUserId due to empty user_data");
    } else {
        // Try to decode the JSON data
        $userData = json_decode($archivedUser['user_data'], true);
        
        // If JSON decoding failed, try to fix common JSON issues
        if ($userData === null) {
            // Log the problematic JSON for debugging
            error_log("Invalid JSON in archived_users.user_data for ID: $archivedUserId. JSON: " . $archivedUser['user_data']);
            
            // Try to fix common JSON issues (like escaped quotes)
            $fixedJson = str_replace('\"', '"', $archivedUser['user_data']);
            $fixedJson = preg_replace('/([{,])\s*([^"}\s]+)\s*:/', '$1"$2":', $fixedJson);
            $userData = json_decode($fixedJson, true);
            
            // If still null, try to parse as serialized PHP data
            if ($userData === null && function_exists('unserialize')) {
                $userData = @unserialize($archivedUser['user_data']);
                
                // Convert to array if it's an object
                if (is_object($userData)) {
                    $userData = (array)$userData;
                }
            }
            
            // If still null, try to extract data from the string
            if ($userData === null) {
                // Try to extract data using regex patterns
                $extractedData = [];
                
                // Extract key-value pairs
                preg_match_all('/"?([^":\s]+)"?\s*:\s*"?([^",\}\s]+)"?/', $archivedUser['user_data'], $matches, PREG_SET_ORDER);
                
                foreach ($matches as $match) {
                    if (isset($match[1]) && isset($match[2])) {
                        $extractedData[trim($match[1], '"')] = trim($match[2], '"');
                    }
                }
                
                if (!empty($extractedData)) {
                    $userData = $extractedData;
                    error_log("Extracted user data using regex for archived user ID: $archivedUserId");
                }
            }
        }
    }
    
    // Final fallback if all parsing attempts failed
    if (!$userData || !is_array($userData)) {
        // Create minimal user data from archived_users table fields
        $userData = [
            'id' => $archivedUser['original_id'],
            'name' => $archivedUser['name'] ?? 'Restored User',
            'email' => $archivedUser['email'] ?? 'restored_' . time() . '@example.com',
            'role' => $archivedUser['role'] ?? 'Member',
            'status' => 'Active',
            'created_at' => $archivedUser['archived_date'] ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        error_log("Created fallback user data for archived user ID: $archivedUserId");
    }
    
    // Ensure required fields exist in userData
    $requiredFields = ['name', 'email', 'role'];
    foreach ($requiredFields as $field) {
        if (!isset($userData[$field]) || empty($userData[$field])) {
            $userData[$field] = $archivedUser[$field] ?? ($field === 'role' ? 'Member' : 
                                ($field === 'name' ? 'Restored User' : 
                                 'restored_' . time() . '@example.com'));
        }
    }
    
    // Check if the original user still exists and get current status
    $checkStmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $checkStmt->execute([$archivedUser['original_id']]);
    $originalUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    $restoredUserId = null;
    $userDataBefore = null;
    $userDataAfter = null;
    
    // Get all columns from users table for validation
    $columnsStmt = $conn->prepare("
        SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'users'
    ");
    $columnsStmt->execute();
    $columnsInfo = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create lookup arrays for column validation
    $availableColumns = [];
    $columnTypes = [];
    $columnLengths = [];
    $nullableColumns = [];
    
    foreach ($columnsInfo as $column) {
        $colName = $column['COLUMN_NAME'];
        $availableColumns[] = $colName;
        $columnTypes[$colName] = $column['DATA_TYPE'];
        $columnLengths[$colName] = $column['CHARACTER_MAXIMUM_LENGTH'];
        $nullableColumns[$colName] = ($column['IS_NULLABLE'] === 'YES');
    }
    
    // Validate and sanitize user data based on column definitions
    foreach ($userData as $field => $value) {
        if (in_array($field, $availableColumns)) {
            // Handle data type validation and conversion
            switch ($columnTypes[$field]) {
                case 'int':
                case 'bigint':
                case 'tinyint':
                case 'smallint':
                case 'mediumint':
                    $userData[$field] = is_numeric($value) ? (int)$value : null;
                    break;
                    
                case 'decimal':
                case 'float':
                case 'double':
                    $userData[$field] = is_numeric($value) ? (float)$value : null;
                    break;
                    
                case 'varchar':
                case 'char':
                case 'text':
                    // Truncate if exceeds maximum length
                    if ($columnLengths[$field] && strlen($value) > $columnLengths[$field]) {
                        $userData[$field] = substr($value, 0, $columnLengths[$field]);
                    }
                    break;
                    
                case 'date':
                case 'datetime':
                case 'timestamp':
                    // Validate date format
                    if (!empty($value)) {
                        $timestamp = strtotime($value);
                        if ($timestamp === false) {
                            $userData[$field] = null;
                        } else {
                            $userData[$field] = date('Y-m-d H:i:s', $timestamp);
                        }
                    } else {
                        $userData[$field] = null;
                    }
                    break;
                    
                case 'json':
                    // Ensure JSON data is valid
                    if (is_array($value)) {
                        $userData[$field] = json_encode($value);
                    } elseif (!is_string($value) || json_decode($value) === null) {
                        $userData[$field] = '{}';
                    }
                    break;
            }
            
            // Handle null values
            if ($userData[$field] === null && !$nullableColumns[$field]) {
                // Provide default values for non-nullable fields
                switch ($field) {
                    case 'name':
                        $userData[$field] = 'Restored User';
                        break;
                    case 'email':
                        $userData[$field] = 'restored_' . time() . '@example.com';
                        break;
                    case 'role':
                        $userData[$field] = 'Member';
                        break;
                    case 'status':
                        $userData[$field] = 'Active';
                        break;
                    case 'created_at':
                    case 'updated_at':
                        $userData[$field] = date('Y-m-d H:i:s');
                        break;
                    default:
                        // For other non-nullable fields, use empty string for strings, 0 for numbers
                        if (in_array($columnTypes[$field], ['varchar', 'char', 'text'])) {
                            $userData[$field] = '';
                        } elseif (in_array($columnTypes[$field], ['int', 'bigint', 'tinyint', 'smallint', 'mediumint', 'decimal', 'float', 'double'])) {
                            $userData[$field] = 0;
                        }
                }
            }
        }
    }
    
    // Ensure password field exists if required
    if (in_array('password', $availableColumns) && (!isset($userData['password']) || empty($userData['password']))) {
        // Generate a random password if needed
        $userData['password'] = password_hash('TemporaryPassword' . time(), PASSWORD_DEFAULT);
        $passwordReset = true;
    } else {
        $passwordReset = false;
    }
    
    // Always ensure status is Active
    $userData['status'] = 'Active';
    
    if ($originalUser) {
        // If the original user exists, restore their data and set status to Active
        $userDataBefore = json_encode($originalUser);
        
        // Prepare update fields from archived data (excluding id)
        $updateFields = [];
        $updateValues = [];
        
        // Build update query with archived data
        foreach ($userData as $field => $value) {
            if ($field !== 'id' && in_array($field, $availableColumns)) {
                $updateFields[] = "$field = ?";
                $updateValues[] = $value;
            }
        }
        
        // Add user ID for WHERE clause
        $updateValues[] = $archivedUser['original_id'];
        
        // Execute update
        $updateSql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute($updateValues);
        
        $restoredUserId = $archivedUser['original_id'];
        
        // Get updated user data
        $updatedStmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $updatedStmt->execute([$restoredUserId]);
        $userDataAfter = json_encode($updatedStmt->fetch(PDO::FETCH_ASSOC));
        
        // Log status change
        $statusStmt = $conn->prepare("
            INSERT INTO user_status_history 
            (user_id, old_status, new_status, changed_by, change_reason, ip_address) 
            VALUES (?, ?, 'Active', ?, 'User restored from archive', ?)
        ");
        $statusStmt->execute([
            $restoredUserId,
            $originalUser['status'],
            $_SESSION['user_id'],
            $_SERVER['REMOTE_ADDR']
        ]);
        
    } else {
        // If the original user doesn't exist, restore with the original user ID
        $userDataBefore = json_encode(['status' => 'not_exists']);
        
        // Prepare insert fields and values
        $fields = [];
        $placeholders = [];
        $values = [];
        
        // Include the original user ID
        if (in_array('id', $availableColumns)) {
            $fields[] = 'id';
            $placeholders[] = '?';
            $values[] = $archivedUser['original_id'];
        }
        
        // Add other fields from archived data
        foreach ($userData as $field => $value) {
            if ($field !== 'id' && in_array($field, $availableColumns)) {
                $fields[] = $field;
                $placeholders[] = '?';
                $values[] = $value;
            }
        }
        
        // Create SQL query
        $sql = "INSERT INTO users (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        // Execute query
        $insertStmt = $conn->prepare($sql);
        $insertStmt->execute($values);
        
        // Use the original user ID as the restored user ID
        $restoredUserId = $archivedUser['original_id'];
        
        // Get restored user data
        $restoredStmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $restoredStmt->execute([$restoredUserId]);
        $userDataAfter = json_encode($restoredStmt->fetch(PDO::FETCH_ASSOC));
        
        // Log status change for restored user
        $statusStmt = $conn->prepare("
            INSERT INTO user_status_history 
            (user_id, old_status, new_status, changed_by, change_reason, ip_address) 
            VALUES (?, 'Archived', 'Active', ?, 'User restored from archive (recreated)', ?)
        ");
        $statusStmt->execute([
            $restoredUserId,
            $_SESSION['user_id'],
            $_SERVER['REMOTE_ADDR']
        ]);
    }
    
    // Create restore_logs table if it doesn't exist
    $conn->exec("
        CREATE TABLE IF NOT EXISTS restore_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            archived_user_id INT NOT NULL,
            original_user_id INT,
            restored_user_id INT NOT NULL,
            admin_id INT NOT NULL,
            restore_reason TEXT,
            restore_status VARCHAR(20) NOT NULL,
            user_data_before TEXT,
            user_data_after TEXT,
            error_message TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Log the restoration in restore_logs table
    $restoreLogStmt = $conn->prepare("
        INSERT INTO restore_logs 
        (archived_user_id, original_user_id, restored_user_id, admin_id, restore_reason, 
         restore_status, user_data_before, user_data_after, ip_address, user_agent) 
        VALUES (?, ?, ?, ?, ?, 'success', ?, ?, ?, ?)
    ");
    
    $restoreReason = isset($_POST['restore_reason']) ? $_POST['restore_reason'] : 'User restoration requested by admin';
    
    $restoreLogStmt->execute([
        $archivedUserId,
        $archivedUser['original_id'],
        $restoredUserId,
        $_SESSION['user_id'],
        $restoreReason,
        $userDataBefore,
        $userDataAfter,
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    // Update archived_users table with restore information
    $updateArchivedStmt = $conn->prepare("
        UPDATE archived_users 
        SET restore_count = restore_count + 1, last_restore_date = NOW() 
        WHERE id = ?
    ");
    $updateArchivedStmt->execute([$archivedUserId]);
    
    // Create admin_logs table if it doesn't exist
    $conn->exec("
        CREATE TABLE IF NOT EXISTS admin_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            log_message TEXT,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45)
        )
    ");
    
    // Log the action in admin_logs
    $adminId = $_SESSION['user_id'];
    
    // Prepare log message
    $logMessage = "Restored archived user: " . ($userData['name'] ?? $archivedUser['name']) . 
                  " (" . ($userData['email'] ?? $archivedUser['email']) . ") " .
                  "with role " . ($userData['role'] ?? $archivedUser['role']) . 
                  " - User ID: " . $restoredUserId;
    
    // Insert admin log
    $logStmt = $conn->prepare("
        INSERT INTO admin_logs (admin_id, action, log_message, ip_address) 
        VALUES (?, ?, ?, ?)
    ");
    $logStmt->execute([
        $adminId,
        "restore_user",
        $logMessage,
        $_SERVER['REMOTE_ADDR']
    ]);
    
    // Commit transaction
    $conn->commit();
    
    // Set success message with user ID information
    $successMessage = "User " . ($userData['name'] ?? $archivedUser['name']) . 
                      " (ID: " . $restoredUserId . ") has been successfully restored and is now active.";
    
    // Add password reset notification if applicable
    if ($passwordReset) {
        $successMessage .= " A temporary password has been set. Please advise the user to reset their password.";
    }
    
    $_SESSION['success_message'] = $successMessage;
    
    // Redirect back to archived users page
    header("Location: archived-users.php");
    exit;
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Log detailed error for debugging
    error_log("User restore error for archived ID $archivedUserId: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    
    // Create restore_logs table if it doesn't exist
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS restore_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                archived_user_id INT NOT NULL,
                original_user_id INT,
                restored_user_id INT,
                admin_id INT NOT NULL,
                restore_reason TEXT,
                restore_status VARCHAR(20) NOT NULL,
                user_data_before TEXT,
                user_data_after TEXT,
                error_message TEXT,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Log the error in restore_logs table
        $errorLogStmt = $conn->prepare("
            INSERT INTO restore_logs 
            (archived_user_id, original_user_id, admin_id, restore_status, error_message, ip_address, user_agent) 
            VALUES (?, ?, ?, 'failed', ?, ?, ?)
        ");
        $errorLogStmt->execute([
            $archivedUserId,
            $archivedUser['original_id'] ?? 0,
            $_SESSION['user_id'],
            $e->getMessage(),
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $logError) {
        // If logging fails, continue with error handling
        error_log("Failed to log restore error: " . $logError->getMessage());
    }
    
    // Set user-friendly error message
    $_SESSION['error_message'] = "Error restoring user: " . $e->getMessage() . 
                                " Please contact the system administrator if this problem persists.";
    
    // Redirect back to archived users page
    header("Location: archived-users.php");
    exit;
}
?>