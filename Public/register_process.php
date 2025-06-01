<?php
// Start session
session_start();

// Include database connection and PHPMailer
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/vendor/autoload.php'; // PHPMailer autoload

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Function to sanitize input data
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Function to validate email format
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Function to validate password strength
function isStrongPassword($password) {
    $uppercase = preg_match('@[A-Z]@', $password);
    $lowercase = preg_match('@[a-z]@', $password);
    $number = preg_match('@[0-9]@', $password);
    $specialChars = preg_match('@[^\w]@', $password);
    
    return strlen($password) >= 8 && $uppercase && $lowercase && $number && $specialChars;
}

// Function to generate OTP
function generateOTP($length = 6) {
    return str_pad(random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

// Function to send OTP email
function sendOTPEmail($email, $name, $otp, $role) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Replace with your SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lovelacejohnkwakubaidoo@gmail.com'; // Replace with your email
        $mail->Password   = 'qdep zzus harq poqb'; // Replace with your app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('lovelacejohnkwakubaidoo@gmail.com', 'EliteFit Gym');
        $mail->addAddress($email, $name);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'EliteFit Gym - Email Verification Code';
        
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: 'Arial', sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #FF8C00, #FF6B35); padding: 30px; text-align: center; color: white; }
                .logo { font-size: 28px; font-weight: bold; margin-bottom: 10px; }
                .content { padding: 40px 30px; text-align: center; }
                .otp-code { background-color: #f8f9fa; border: 2px dashed #FF8C00; border-radius: 10px; padding: 20px; margin: 30px 0; font-size: 32px; font-weight: bold; color: #FF8C00; letter-spacing: 5px; }
                .footer { background-color: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 14px; }
                .btn { display: inline-block; background-color: #FF8C00; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='logo'> ELITEFIT GYM</div>
                    <p>Welcome to the Elite Fitness Community!</p>
                </div>
                <div class='content'>
                    <h2>Email Verification Required</h2>
                    <p>Hello <strong>" . htmlspecialchars($name) . "</strong>,</p>
                    <p>Thank you for registering as a <strong>" . htmlspecialchars($role) . "</strong> with EliteFit Gym!</p>
                    <p>To complete your registration, please use the verification code below:</p>
                    
                    <div class='otp-code'>" . $otp . "</div>
                    
                    <p><strong>This code will expire in 15 minutes.</strong></p>
                    <p>If you didn't request this registration, please ignore this email.</p>
                    
                    <div style='margin-top: 30px; padding: 20px; background-color: #fff3cd; border-radius: 5px; border-left: 4px solid #FF8C00;'>
                        <strong>Security Tips:</strong>
                        <ul style='text-align: left; margin: 10px 0;'>
                            <li>Never share your verification code with anyone</li>
                            <li>EliteFit staff will never ask for your verification code</li>
                            <li>This code is only valid for 15 minutes</li>
                        </ul>
                    </div>
                </div>
                <div class='footer'>
                    <p>© 2024 EliteFit Gym. All rights reserved.</p>
                    <p>This is an automated message, please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->AltBody = "Your EliteFit Gym verification code is: $otp. This code will expire in 15 minutes.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: {$mail->ErrorInfo}");
        return false;
    }
}

// Function to log registration activity
function logRegistration($email, $success, $role, $message) {
    try {
        $conn = connectDB();
        $stmt = $conn->prepare("INSERT INTO registration_logs (email, success, role, message, ip_address, timestamp) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$email, $success, $role, $message, $_SERVER['REMOTE_ADDR']]);
    } catch (Exception $e) {
        error_log("Failed to log registration: " . $e->getMessage());
    }
}

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $name = sanitizeInput($_POST["name"]);
    $email = sanitizeInput($_POST["email"]);
    $password = $_POST["password"];
    $confirmPassword = $_POST["confirm_password"];
    $role = sanitizeInput($_POST["role"]);
    $experienceLevel = isset($_POST["experience_level"]) ? sanitizeInput($_POST["experience_level"]) : null;
    $fitnessGoals = isset($_POST["fitness_goals"]) ? sanitizeInput($_POST["fitness_goals"]) : null;
    $preferredRoutines = isset($_POST["preferred_routines"]) ? sanitizeInput($_POST["preferred_routines"]) : null;
    
    // Validate inputs
    $errors = [];
    
    // Check if name is empty
    if (empty($name)) {
        $errors[] = "Name is required";
    }
    
    // Check if email is valid
    if (!isValidEmail($email)) {
        $errors[] = "Invalid email format";
    }
    
    // Check if password and confirm password match
    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match";
    }
    
    // Check password strength
    if (!isStrongPassword($password)) {
        $errors[] = "Password must be at least 8 characters long and include uppercase, lowercase, number, and special character";
    }
    
    // Check if role is valid
    $validRoles = ['Member', 'Trainer', 'Admin', 'EquipmentManager'];
    $roleIsValid = false;

    foreach ($validRoles as $validRole) {
        if ($role === $validRole) {
            $roleIsValid = true;
            break;
        }
    }

    if (!$roleIsValid) {
        $errors[] = "Invalid role selected";
    }
    
    // If there are no errors, proceed with registration
    if (empty($errors)) {
        try {
            // Connect to database
            $conn = connectDB();
            
            // Check if email already exists in users table
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->execute([$email]);
            
            if ($checkStmt->rowCount() > 0) {
                $_SESSION['register_error'] = "Email already exists. Please use a different email or login.";
                logRegistration($email, 0, $role, "Email already exists");
                header("Location: register.php");
                exit;
            }
            
            // Generate OTP
            $otp = generateOTP(6);
            $otpExpiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Delete any existing pending registration for this email
            $deleteStmt = $conn->prepare("DELETE FROM pending_registrations WHERE email = ?");
            $deleteStmt->execute([$email]);
            
            // Store pending registration in database
            $pendingStmt = $conn->prepare("
                INSERT INTO pending_registrations (
                    name, email, password_hash, role, experience_level, 
                    fitness_goals, preferred_routines, otp_code, expires_at, 
                    created_at, ip_address, user_agent, verification_attempts
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 0)
            ");
            
            $pendingStmt->execute([
                $name,
                $email,
                $hashedPassword,
                $role,
                $experienceLevel,
                $fitnessGoals,
                $preferredRoutines,
                $otp,
                $otpExpiry,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
            // Send OTP email
            if (sendOTPEmail($email, $name, $otp, $role)) {
                try {
                    // Log registration attempt in analytics (optional - only if table exists)
                    $analyticsStmt = $conn->prepare("
                        INSERT INTO registration_analytics (
                            email, role, registration_date, ip_address, 
                            user_agent, referrer, completion_step, status
                        ) VALUES (
                            ?, ?, NOW(), ?, ?, ?, 'OTP Sent', 'Pending Verification'
                        )
                    ");
                    
                    $analyticsStmt->execute([
                        $email,
                        $role,
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        $_SERVER['HTTP_REFERER'] ?? null
                    ]);
                } catch (Exception $e) {
                    // Analytics table might not exist, continue anyway
                    error_log("Analytics logging failed: " . $e->getMessage());
                }
                
                // Store email in session for verification page
                $_SESSION['verification_email'] = $email;
                $_SESSION['verification_name'] = $name;
                $_SESSION['verification_role'] = $role;
                
                // Log successful OTP sending
                logRegistration($email, 1, $role, "OTP sent successfully");
                
                // Redirect to verification page
                header("Location: verify_otp.php");
                exit;
            } else {
                // Email sending failed
                $_SESSION['register_error'] = "Failed to send verification email. Please try again.";
                logRegistration($email, 0, $role, "Email sending failed");
                header("Location: register.php");
                exit;
            }
            
        } catch (PDOException $e) {
            // Log error
            error_log("Registration database error: " . $e->getMessage());
            logRegistration($email, 0, $role, "Database error: " . $e->getMessage());
            
            // Set error message
            $_SESSION['register_error'] = "An error occurred during registration. Please try again.";
            
            // Redirect back to registration page
            header("Location: register.php");
            exit;
        }
    } else {
        // If there are validation errors, set error message
        $_SESSION['register_error'] = implode("<br>", $errors);
        
        // Log failed registration
        logRegistration($email ?? 'unknown', 0, $role ?? 'unknown', implode(", ", $errors));
        
        // Redirect back to registration page
        header("Location: register.php");
        exit;
    }
} else {
    // If not a POST request, redirect to registration page
    header("Location: register.php");
    exit;
}
?>