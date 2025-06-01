<?php
// Start session
session_start();

// Include database connection
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';

// Include PHPMailer at the top of the file
require_once __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Check if user has verification session
if (!isset($_SESSION['verification_email'])) {
    $_SESSION['register_error'] = "Verification session expired. Please register again.";
    header("Location: register.php");
    exit;
}

$email = $_SESSION['verification_email'];
$name = $_SESSION['verification_name'] ?? 'User';
$role = $_SESSION['verification_role'] ?? 'Member';

// Enhanced database table creation function
function ensureUserProfileTablesExist($conn) {
    try {
        // Create member_profiles table if it doesn't exist
        $memberProfilesTable = "
            CREATE TABLE IF NOT EXISTS member_profiles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                experience_level VARCHAR(100),
                fitness_goals TEXT,
                preferred_routines TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $conn->exec($memberProfilesTable);
        
        // Create trainer_profiles table if it doesn't exist
        $trainerProfilesTable = "
            CREATE TABLE IF NOT EXISTS trainer_profiles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                specialization VARCHAR(255),
                experience TEXT,
                training_approach TEXT,
                status VARCHAR(20) DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $conn->exec($trainerProfilesTable);
        
        return true;
    } catch (Exception $e) {
        error_log("Profile table creation failed: " . $e->getMessage());
        return false;
    }
}

// Database connection test function
function testDatabaseConnection() {
    try {
        $conn = connectDB();
        if (!$conn) {
            return ['success' => false, 'error' => 'connectDB() returned null/false'];
        }
        
        // Test basic query
        $stmt = $conn->query("SELECT 1 as test");
        if (!$stmt) {
            return ['success' => false, 'error' => 'Failed to execute test query'];
        }
        
        // Test pending_registrations table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'pending_registrations'");
        if (!$tableCheck || $tableCheck->rowCount() == 0) {
            return ['success' => false, 'error' => 'pending_registrations table does not exist'];
        }
        
        return ['success' => true, 'error' => null];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => 'PDO Error: ' . $e->getMessage()];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'General Error: ' . $e->getMessage()];
    }
}

// Send OTP email function
function sendOTPEmail($email, $name, $otp, $role) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lovelacejohnkwakubaidoo@gmail.com';
        $mail->Password   = 'qdep zzus harq poqb';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->Timeout    = 30;

        // Recipients
        $mail->setFrom('lovelacejohnkwakubaidoo@gmail.com', 'EliteFit Gym');
        $mail->addAddress($email, $name);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'EliteFit Gym - New Verification Code';
        
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: 'Arial', sans-serif; background-color: #121212; margin: 0; padding: 20px; color: white; }
                .container { max-width: 600px; margin: 0 auto; background-color: #1e1e1e; border-radius: 10px; overflow: hidden; box-shadow: 0 0 20px rgba(0,0,0,0.5); }
                .header { background: linear-gradient(135deg, #FF8C00, #e67e00); padding: 30px; text-align: center; color: white; }
                .logo { font-size: 28px; font-weight: bold; margin-bottom: 10px; }
                .content { padding: 40px 30px; text-align: center; background: #1e1e1e; }
                .otp-code { background-color: #2a2a2a; border: 2px solid #FF8C00; border-radius: 10px; padding: 20px; margin: 30px 0; font-size: 32px; font-weight: bold; color: #FF8C00; letter-spacing: 5px; }
                .footer { background-color: #121212; padding: 20px; text-align: center; color: #aaa; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='logo'>🏋️ ELITEFIT GYM</div>
                    <p>New Verification Code</p>
                </div>
                <div class='content'>
                    <h2 style='color: white; margin-bottom: 20px;'>New Verification Code</h2>
                    <p style='color: #ccc;'>Hello <strong style='color: #FF8C00;'>" . htmlspecialchars($name) . "</strong>,</p>
                    <p style='color: #ccc;'>Here is your new verification code:</p>
                    
                    <div class='otp-code'>" . $otp . "</div>
                    
                    <p style='color: #FF8C00; font-weight: bold;'>This code will expire in 15 minutes.</p>
                </div>
                <div class='footer'>
                    <p>© 2024 EliteFit Gym. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        error_log("Full resend email error: " . $e->getMessage());
        return false;
    }
}

// Rate limiting for resend OTP
function checkResendRateLimit($email) {
    $conn = connectDB();
    if (!$conn) return false;
    
    // Check if last resend was within 1 minute
    $stmt = $conn->prepare("
        SELECT created_at FROM pending_registrations 
        WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ORDER BY created_at DESC LIMIT 1
    ");
    
    if ($stmt->execute([$email])) {
        return $stmt->rowCount() > 0;
    }
    
    return false;
}

// Handle resend OTP request
if (isset($_POST['resend_otp'])) {
    try {
        // Check rate limiting
        if (checkResendRateLimit($email)) {
            $_SESSION['otp_error'] = "Please wait 1 minute before requesting a new code.";
        } else {
            $conn = connectDB();
            
            if (!$conn) {
                throw new Exception("Database connection failed");
            }
            
            // Generate new OTP
            $newOTP = sprintf('%06d', mt_rand(0, 999999));
            
            // Update existing pending registration with new OTP
            $updateStmt = $conn->prepare("
                UPDATE pending_registrations 
                SET otp_code = ?, 
                    expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                    verification_attempts = 0,
                    created_at = NOW()
                WHERE email = ?
            ");
            
            if ($updateStmt->execute([$newOTP, $email])) {
                // Send new OTP email
                if (sendOTPEmail($email, $name, $newOTP, $role)) {
                    $_SESSION['otp_success'] = "New verification code sent to your email.";
                    error_log("New OTP sent successfully to: " . $email);
                } else {
                    $_SESSION['otp_error'] = "Failed to send new verification code. Please try again.";
                    error_log("Failed to send new OTP to: " . $email);
                }
            } else {
                $_SESSION['otp_error'] = "Failed to generate new verification code. Please try again.";
                error_log("Failed to update OTP in database for: " . $email);
            }
        }
        
    } catch (PDOException $e) {
        $_SESSION['otp_error'] = "Database error occurred. Please try again.";
        error_log("Database error in resend OTP: " . $e->getMessage());
    } catch (Exception $e) {
        $_SESSION['otp_error'] = "An error occurred. Please try again.";
        error_log("General error in resend OTP: " . $e->getMessage());
    }
    
    // Redirect to prevent form resubmission
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Handle OTP verification
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['resend_otp'])) {
    $submittedOTP = trim($_POST['otp'] ?? '');
    
    if (empty($submittedOTP)) {
        $_SESSION['otp_error'] = "Please enter the verification code.";
    } else {
        try {
            // Enhanced database connection with error checking
            $conn = connectDB();
            
            if (!$conn) {
                throw new Exception("Database connection failed");
            }
            
            // Set connection charset to handle special characters
            $conn->exec("SET NAMES utf8mb4");
            
            // Get pending registration with better error handling
            $stmt = $conn->prepare("
                SELECT * FROM pending_registrations 
                WHERE email = ? AND expires_at > NOW() 
                ORDER BY created_at DESC LIMIT 1
            ");
            
            if (!$stmt) {
                throw new PDOException("Failed to prepare statement");
            }
            
            if (!$stmt->execute([$email])) {
                throw new PDOException("Failed to execute statement");
            }
            
            $pendingReg = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Enhanced debug information
            error_log("=== OTP Verification Debug Start ===");
            error_log("Email: " . $email);
            error_log("Submitted OTP: " . $submittedOTP);
            error_log("Pending registration found: " . ($pendingReg ? 'Yes' : 'No'));
            
            if ($pendingReg) {
                error_log("Stored OTP: " . $pendingReg['otp_code']);
                error_log("Expires at: " . $pendingReg['expires_at']);
                error_log("Current attempts: " . $pendingReg['verification_attempts']);
            }
            error_log("=== OTP Verification Debug End ===");
            
            if (!$pendingReg) {
                $_SESSION['otp_error'] = "Verification code has expired or not found. Please register again.";
                error_log("No valid pending registration found for email: " . $email);
                
                // Clear session data
                unset($_SESSION['verification_email']);
                unset($_SESSION['verification_name']);
                unset($_SESSION['verification_role']);
                
            } else {
                // Check if too many attempts before incrementing
                if ($pendingReg['verification_attempts'] >= 5) {
                    // Delete failed registration
                    $deleteStmt = $conn->prepare("DELETE FROM pending_registrations WHERE id = ?");
                    $deleteStmt->execute([$pendingReg['id']]);
                    
                    $_SESSION['otp_error'] = "Too many failed attempts. Please register again.";
                    error_log("Too many attempts for email: " . $email);
                    
                    // Clear session data
                    unset($_SESSION['verification_email']);
                    unset($_SESSION['verification_name']);
                    unset($_SESSION['verification_role']);
                    
                } else {
                    // Clean and compare OTPs
                    $submittedOTPClean = preg_replace('/\D/', '', trim($submittedOTP));
                    $storedOTPClean = preg_replace('/\D/', '', trim($pendingReg['otp_code']));
                    
                    error_log("=== OTP Comparison ===");
                    error_log("Submitted (clean): '" . $submittedOTPClean . "'");
                    error_log("Stored (clean): '" . $storedOTPClean . "'");
                    error_log("Match: " . ($submittedOTPClean === $storedOTPClean ? 'YES' : 'NO'));
                    
                    if ($submittedOTPClean === $storedOTPClean) {
                        // OTP is correct - REDIRECT TO VERIFICATION.PHP INSTEAD OF CREATING USER
                        error_log("OTP verification successful for: " . $email . " - Redirecting to password verification");
                        
                        // Store user data in session for verification step
                        $_SESSION['temp_user_data'] = [
                            'name' => $pendingReg['name'],
                            'email' => $pendingReg['email'],
                            'hashed_password' => $pendingReg['password_hash'],
                            'role' => $pendingReg['role'],
                            'experience_level' => $pendingReg['experience_level'] ?? '',
                            'fitness_goals' => $pendingReg['fitness_goals'] ?? '',
                            'preferred_routines' => $pendingReg['preferred_routines'] ?? '',
                            'contact_number' => $pendingReg['contact_number'] ?? null,
                            'date_of_birth' => $pendingReg['date_of_birth'] ?? null,
                            'height' => $pendingReg['height'] ?? null,
                            'weight' => $pendingReg['weight'] ?? null,
                            'body_type' => $pendingReg['body_type'] ?? null,
                            'workout_preferences' => $pendingReg['workout_preferences'] ?? null,
                            'health_conditions' => $pendingReg['health_conditions'] ?? null
                        ];
                        
                        // Keep pending registration for now (will be deleted in verification_process.php)
                        // This allows the verification step to access the original data if needed
                        
                        // Clear verification session
                        unset($_SESSION['verification_email']);
                        unset($_SESSION['verification_name']);
                        unset($_SESSION['verification_role']);
                        
                        error_log("User data stored in session, redirecting to verification.php");
                        
                        // Redirect to password verification page
                        header("Location: verification.php");
                        exit;
                        
                    } else {
                        // Increment verification attempts for wrong OTP
                        $updateAttemptsStmt = $conn->prepare("
                            UPDATE pending_registrations 
                            SET verification_attempts = verification_attempts + 1 
                            WHERE id = ?
                        ");
                        $updateAttemptsStmt->execute([$pendingReg['id']]);
                        
                        $_SESSION['otp_error'] = "Invalid verification code. Please check and try again.";
                        error_log("OTP mismatch for email: " . $email . " (Attempt: " . ($pendingReg['verification_attempts'] + 1) . ")");
                    }
                }
            }
            
        } catch (PDOException $e) {
            $_SESSION['otp_error'] = "Database error: " . $e->getMessage();
            error_log("Database error in OTP verification: " . $e->getMessage());
        } catch (Exception $e) {
            $_SESSION['otp_error'] = "Verification error: " . $e->getMessage();
            error_log("General error in OTP verification: " . $e->getMessage());
        }
    }
}

// Get current pending registration for expiry time
$expiryTime = null;
$debugInfo = null;
$timeRemaining = 0;

try {
    $conn = connectDB();
    
    if ($conn) {
        // Get expiry time
        $stmt = $conn->prepare("SELECT expires_at FROM pending_registrations WHERE email = ? ORDER BY created_at DESC LIMIT 1");
        if ($stmt->execute([$email])) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $expiryTime = $result['expires_at'];
                $timeRemaining = max(0, strtotime($expiryTime) - time());
            }
        }
        
        // Get debug info
        $debugStmt = $conn->prepare("SELECT otp_code, expires_at, verification_attempts, created_at FROM pending_registrations WHERE email = ? ORDER BY created_at DESC LIMIT 1");
        if ($debugStmt->execute([$email])) {
            $debugInfo = $debugStmt->fetch(PDO::FETCH_ASSOC);
        }
    }
} catch (Exception $e) {
    error_log("Error in expiry/debug section: " . $e->getMessage());
    $debugInfo = ['error' => $e->getMessage()];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #ff4d4d;
            --secondary: #333;
            --light: #f8f9fa;
            --dark: #212529;
            --success: #28a745;
            --border-radius: 8px;
            --orange: #FF8C00;
            --orange-dark: #e67e00;
            --orange-light: #ffaa33;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: url('https://images.squarespace-cdn.com/content/v1/5696733025981d28a35ef8ab/8a7c7340-9f83-4281-84e7-6e5773d8d97e/hotel+gym+design+ref+1.jpg') no-repeat center center/cover fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            color: white;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0.5) 100%);
            z-index: -1;
        }

        .verification-container {
            background: #121212;
            border-radius: var(--border-radius);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
            padding: 0;
            width: 100%;
            max-width: 500px;
            text-align: center;
            position: relative;
            overflow: hidden;
            color: white;
        }

        .header {
            background: #1e1e1e;
            padding: 30px;
            border-bottom: 1px solid #333;
        }

        .logo-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 20px;
        }

        .logo-icon {
            font-size: 2.5rem;
            color: var(--orange);
            margin-bottom: 10px;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .logo-text {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--orange);
            letter-spacing: 2px;
        }

        .tagline {
            color: #aaa;
            font-size: 14px;
            margin-top: 10px;
        }

        .verification-header {
            margin-bottom: 20px;
        }

        .verification-header h2 {
            color: white;
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .verification-header p {
            color: #aaa;
            font-size: 16px;
            margin-bottom: 20px;
        }

        .verification-form {
            padding: 30px;
        }

        .email-display {
            background: #1e1e1e;
            padding: 15px;
            border-radius: var(--border-radius);
            color: var(--orange);
            font-weight: bold;
            margin-bottom: 20px;
            border: 2px solid var(--orange);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .alert {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background-color: rgba(220, 53, 69, 0.2);
            color: #ff6b6b;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.2);
            color: #4caf50;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }

        .form-group {
            margin-bottom: 25px;
            text-align: left;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #e0e0e0;
            font-weight: 500;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .otp-input {
            width: 100%;
            padding: 15px;
            padding-left: 40px;
            border: 2px solid #444;
            border-radius: var(--border-radius);
            font-size: 18px;
            text-align: center;
            letter-spacing: 5px;
            font-weight: bold;
            transition: all 0.3s ease;
            background-color: #1e1e1e;
            color: white;
        }

        .otp-input:focus {
            outline: none;
            border-color: var(--orange);
            box-shadow: 0 0 0 3px rgba(255, 140, 0, 0.2);
            transform: scale(1.02);
        }

        .otp-input::placeholder {
            color: #666;
            letter-spacing: 3px;
        }

        .form-group i {
            position: absolute;
            left: 15px;
            top: 42px;
            color: #aaa;
        }

        .input-container {
            position: relative;
        }

        .input-hint {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            font-size: 12px;
            pointer-events: none;
        }

        .btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 15px;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn:active::before {
            width: 300px;
            height: 300px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--orange), var(--orange-dark));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 140, 0, 0.3);
        }

        .btn-primary:disabled {
            background: #444;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-secondary {
            background: transparent;
            color: var(--orange);
            border: 2px solid var(--orange);
        }

        .btn-secondary:hover {
            background: var(--orange);
            color: white;
        }

        .btn-secondary:disabled {
            background: transparent;
            color: #666;
            border-color: #444;
            cursor: not-allowed;
        }

        .resend-section {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #333;
        }

        .resend-text {
            color: #aaa;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .timer {
            font-weight: bold;
            color: var(--orange);
            font-size: 18px;
            margin-bottom: 15px;
            min-height: 24px;
        }

        .timer.expired {
            color: #dc3545;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #aaa;
            text-decoration: none;
            margin-top: 20px;
            font-size: 14px;
            transition: color 0.3s ease;
        }

        .back-link:hover {
            color: var(--orange);
        }

        .debug-info {
            background: rgba(40, 167, 69, 0.2);
            border: 1px solid rgba(40, 167, 69, 0.3);
            padding: 10px;
            margin: 10px 0;
            border-radius: var(--border-radius);
            font-size: 12px;
            text-align: left;
            color: #4caf50;
        }

        .debug-error {
            background: rgba(220, 53, 69, 0.2);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #ff6b6b;
        }

        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(18, 18, 18, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 3px solid #444;
            border-top: 3px solid var(--orange);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 480px) {
            .verification-container {
                margin: 10px;
            }
            
            .header {
                padding: 20px;
            }
            
            .logo-icon {
                font-size: 2rem;
            }
            
            .logo-text {
                font-size: 1.5rem;
            }
            
            .verification-header h2 {
                font-size: 1.3rem;
            }
            
            .otp-input {
                font-size: 16px;
                letter-spacing: 3px;
                padding: 12px;
                padding-left: 35px;
            }

            .verification-form {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="verification-container">
        <div class="loading-overlay" id="loadingOverlay">
            <div class="loading-spinner"></div>
        </div>

        <div class="header">
            <div class="logo-container">
                <div class="logo-icon">
                    <i class="fas fa-dumbbell"></i>
                </div>
                <div class="logo-text">ELITEFIT</div>
            </div>
            <div class="tagline">Transform Your Body, Transform Your Life</div>
        </div>
        
        <div class="verification-form">
            <div class="verification-header">
                <h2>Verify Your Email</h2>
                <p>We've sent a 6-digit verification code to:</p>
            </div>
            
            <div class="email-display">
                <i class="fas fa-envelope"></i> 
                <?php echo htmlspecialchars($email); ?>
            </div>

            <?php if (isset($_SESSION['otp_error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($_SESSION['otp_error']); ?>
                </div>
                <?php unset($_SESSION['otp_error']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['otp_success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['otp_success']); ?>
                </div>
                <?php unset($_SESSION['otp_success']); ?>
            <?php endif; ?>

            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="otpForm">
                <div class="form-group">
                    <label for="otp">
                        <i class="fas fa-key"></i> Enter Verification Code
                    </label>
                    <div class="input-container">
                        <i class="fas fa-lock"></i>
                        <input type="text" 
                               id="otp" 
                               name="otp" 
                               class="otp-input" 
                               placeholder="000000" 
                               maxlength="6" 
                               pattern="[0-9]{6}" 
                               required 
                               autocomplete="off">
                        <div class="input-hint">6 digits</div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" id="verifyBtn">
                    <i class="fas fa-check"></i>
                    Verify & Continue
                </button>
            </form>

            <div class="resend-section">
                <div class="timer" id="timer">
                    <?php if ($timeRemaining > 0): ?>
                        Code expires in: <span id="countdown"><?php echo gmdate("i:s", $timeRemaining); ?></span>
                    <?php else: ?>
                        <span class="expired">Code has expired</span>
                    <?php endif; ?>
                </div>
                
                <div class="resend-text">
                    Didn't receive the code or code expired?
                </div>
                
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                    <button type="submit" 
                            name="resend_otp" 
                            class="btn btn-secondary" 
                            id="resendBtn"
                            <?php echo ($timeRemaining > 60) ? 'disabled' : ''; ?>>
                        <i class="fas fa-paper-plane"></i>
                        Send New Code
                    </button>
                </form>
            </div>

            <a href="register.php" class="back-link">
                <i class="fas fa-arrow-left"></i>
                Back to Registration
            </a>

            <?php if ($debugInfo && (isset($_GET['debug']) || isset($_SESSION['debug_mode']))): ?>
                <div class="debug-info <?php echo isset($debugInfo['error']) ? 'debug-error' : ''; ?>">
                    <strong>Debug Information:</strong><br>
                    <?php if (isset($debugInfo['error'])): ?>
                        Error: <?php echo htmlspecialchars($debugInfo['error']); ?>
                    <?php else: ?>
                        OTP Code: <?php echo htmlspecialchars($debugInfo['otp_code'] ?? 'N/A'); ?><br>
                        Expires: <?php echo htmlspecialchars($debugInfo['expires_at'] ?? 'N/A'); ?><br>
                        Attempts: <?php echo htmlspecialchars($debugInfo['verification_attempts'] ?? 'N/A'); ?><br>
                        Created: <?php echo htmlspecialchars($debugInfo['created_at'] ?? 'N/A'); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-format OTP input
        document.getElementById('otp').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            e.target.value = value;
            
            // Auto-submit when 6 digits are entered
            if (value.length === 6) {
                setTimeout(() => {
                    showLoading();
                    e.target.form.submit();
                }, 500);
            }
        });

        // Show loading overlay
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        // Form submission handler
        document.getElementById('otpForm').addEventListener('submit', function() {
            showLoading();
        });

        // Countdown timer
        <?php if ($timeRemaining > 0): ?>
        let timeLeft = <?php echo $timeRemaining; ?>;
        const timerElement = document.getElementById('timer');
        const countdownElement = document.getElementById('countdown');
        const resendBtn = document.getElementById('resendBtn');

        function updateTimer() {
            if (timeLeft <= 0) {
                timerElement.innerHTML = '<span class="expired">Code has expired</span>';
                if (resendBtn) {
                    resendBtn.disabled = false;
                    resendBtn.style.opacity = '1';
                }
                return;
            }

            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            const timeString = minutes.toString().padStart(2, '0') + ':' + seconds.toString().padStart(2, '0');
            
            if (countdownElement) {
                countdownElement.textContent = timeString;
            }

            // Enable resend button when less than 1 minute left
            if (timeLeft <= 60 && resendBtn && resendBtn.disabled) {
                resendBtn.disabled = false;
                resendBtn.style.opacity = '1';
            }

            timeLeft--;
        }

        // Update timer every second
        updateTimer();
        const timerInterval = setInterval(updateTimer, 1000);

        // Clear timer when page unloads
        window.addEventListener('beforeunload', () => {
            clearInterval(timerInterval);
        });
        <?php endif; ?>

        // Focus on OTP input when page loads
        window.addEventListener('load', function() {
            document.getElementById('otp').focus();
        });

        // Add ripple effect to buttons
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.classList.add('ripple');
                
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

        // Add CSS for ripple effect
        const style = document.createElement('style');
        style.textContent = `
            .ripple {
                position: absolute;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.6);
                transform: scale(0);
                animation: ripple-animation 0.6s linear;
                pointer-events: none;
            }
            
            @keyframes ripple-animation {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Enter key to submit form
            if (e.key === 'Enter' && document.activeElement === document.getElementById('otp')) {
                e.preventDefault();
                document.getElementById('otpForm').submit();
            }
            
            // Escape key to go back
            if (e.key === 'Escape') {
                window.location.href = 'register.php';
            }
        });

        // Paste support for OTP
        document.getElementById('otp').addEventListener('paste', function(e) {
            e.preventDefault();
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            const otpMatch = paste.match(/\d{6}/);
            if (otpMatch) {
                this.value = otpMatch[0];
                setTimeout(() => {
                    showLoading();
                    document.getElementById('otpForm').submit();
                }, 500);
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-20px)';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>
</body>
</html>