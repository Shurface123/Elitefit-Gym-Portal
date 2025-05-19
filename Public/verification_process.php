<?php
// Start session
session_start();

// Include database connection
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';

// Function to sanitize input data
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Function to validate password strength
function isStrongPassword($password) {
    // Password must be at least 8 characters long and contain at least one uppercase letter, 
    // one lowercase letter, one number, and one special character
    $uppercase = preg_match('@[A-Z]@', $password);
    $lowercase = preg_match('@[a-z]@', $password);
    $number = preg_match('@[0-9]@', $password);
    $specialChars = preg_match('@[^\w]@', $password);
    
    return strlen($password) >= 8 && $uppercase && $lowercase && $number && $specialChars;
}

// Function to log registration activity
function logRegistration($email, $success, $role, $message) {
    $conn = connectDB();
    $stmt = $conn->prepare("INSERT INTO registration_logs (email, success, role, message, ip_address, timestamp) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$email, $success, $role, $message, $_SERVER['REMOTE_ADDR']]);
}

// Function to verify Google reCAPTCHA
function verifyCaptcha($recaptchaResponse) {
    $secretKey = "6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe"; // Replace with your actual secret key
    $url = 'https://www.google.com/recaptcha/api/siteverify';
    $data = [
        'secret' => $secretKey,
        'response' => $recaptchaResponse,
        'remoteip' => $_SERVER['REMOTE_ADDR']
    ];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    $resultJson = json_decode($result);
    
    return $resultJson->success;
}

// Function to generate verification token
function generateVerificationToken() {
    return bin2hex(random_bytes(32));
}

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Check if temporary user data exists in session
    if (!isset($_SESSION['temp_user_data'])) {
        $_SESSION['verification_error'] = "Session expired. Please start the registration process again.";
        header("Location: register.php");
        exit;
    }
    
    // Get temporary user data from session
    $userData = $_SESSION['temp_user_data'];
    
    // Get verification password
    $verificationPassword = $_POST['verification_password'];
    
    // Verify reCAPTCHA
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
    if (empty($recaptchaResponse)) {
        $_SESSION['verification_error'] = "Please complete the CAPTCHA verification.";
        header("Location: verification.php");
        exit;
    }
    
    if (!verifyCaptcha($recaptchaResponse)) {
        $_SESSION['verification_error'] = "CAPTCHA verification failed. Please try again.";
        header("Location: verification.php");
        exit;
    }
    
    // Check if verification password matches the original password
    if (!password_verify($verificationPassword, $userData['hashed_password'])) {
        $_SESSION['verification_error'] = "Password verification failed. Please try again.";
        header("Location: verification.php");
        exit;
    }
    
    // Check password strength again as an extra security measure
    if (!isStrongPassword($verificationPassword)) {
        $_SESSION['verification_error'] = "Password does not meet security requirements. Please use a stronger password.";
        header("Location: verification.php");
        exit;
    }
    
    try {
        // Connect to database
        $conn = connectDB();
        
        // Check if email already exists
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $checkStmt->execute([$userData['email']]);
        
        if ($checkStmt->rowCount() > 0) {
            $_SESSION['verification_error'] = "Email already exists. Please use a different email or login.";
            logRegistration($userData['email'], 0, $userData['role'], "Email already exists");
            header("Location: verification.php");
            exit;
        }
        
        // Generate verification token
        $verificationToken = generateVerificationToken();
        $tokenExpiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Determine account status based on role
        $status = 'Pending Email Verification';
        if ($userData['role'] === 'Trainer' || $userData['role'] === 'EquipmentManager') {
            $status = 'Pending Admin Approval';
        }
        
        // Prepare SQL statement
        $stmt = $conn->prepare("
            INSERT INTO users (
                name, email, password, role, experience_level, 
                fitness_goals, preferred_routines, verification_token, 
                token_expiry, status, registration_ip, registration_date
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )
        ");
        
        // Execute statement
        $stmt->execute([
            $userData['name'], 
            $userData['email'], 
            $userData['hashed_password'], 
            $userData['role'], 
            $userData['experience_level'], 
            $userData['fitness_goals'], 
            $userData['preferred_routines'],
            $verificationToken,
            $tokenExpiry,
            $status,
            $_SERVER['REMOTE_ADDR']
        ]);
        
        $userId = $conn->lastInsertId();
        
        // Log registration analytics
        $analyticsStmt = $conn->prepare("
            INSERT INTO registration_analytics (
                user_id, email, role, registration_date, 
                ip_address, user_agent, referrer, 
                completion_step, status
            ) VALUES (
                ?, ?, ?, NOW(), ?, ?, ?, 'Verification Completed', ?
            )
        ");
        
        $analyticsStmt->execute([
            $userId,
            $userData['email'],
            $userData['role'],
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['HTTP_REFERER'] ?? null,
            $status
        ]);
        
        // Send verification email
        $verificationLink = "https://" . $_SERVER['HTTP_HOST'] . "/verify_email.php?token=" . $verificationToken;
        $emailSubject = "EliteFit Gym - Verify Your Email";
        $emailBody = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #FF8C00; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background-color: #f9f9f9; }
                    .button { display: inline-block; background-color: #FF8C00; color: white; text-decoration: none; padding: 10px 20px; border-radius: 5px; }
                    .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #777; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Welcome to EliteFit Gym!</h1>
                    </div>
                    <div class='content'>
                        <p>Hello " . htmlspecialchars($userData['name']) . ",</p>
                        <p>Thank you for registering with EliteFit Gym. To complete your registration, please verify your email address by clicking the button below:</p>
                        <p style='text-align: center;'>
                            <a href='" . $verificationLink . "' class='button'>Verify Email Address</a>
                        </p>
                        <p>If the button doesn't work, you can copy and paste the following link into your browser:</p>
                        <p>" . $verificationLink . "</p>
                        <p>This link will expire in 24 hours.</p>
                        <p>If you did not create an account, please ignore this email.</p>
                    </div>
                    <div class='footer'>
                        <p>&copy; " . date('Y') . " EliteFit Gym. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        // Send email
        sendEmail($userData['email'], $emailSubject, $emailBody);
        
        // Notify admin if approval is required
        if ($status === 'Pending Admin Approval') {
            // Get admin emails
            $adminStmt = $conn->prepare("SELECT email FROM users WHERE role = 'Admin'");
            $adminStmt->execute();
            $adminEmails = $adminStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($adminEmails)) {
                $adminSubject = "EliteFit Gym - New User Approval Required";
                $adminBody = "
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .header { background-color: #FF8C00; color: white; padding: 20px; text-align: center; }
                            .content { padding: 20px; background-color: #f9f9f9; }
                            .button { display: inline-block; background-color: #FF8C00; color: white; text-decoration: none; padding: 10px 20px; border-radius: 5px; }
                            .user-info { background-color: #eee; padding: 15px; margin: 15px 0; border-radius: 5px; }
                            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #777; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h1>New User Approval Required</h1>
                            </div>
                            <div class='content'>
                                <p>A new user has registered and requires your approval:</p>
                                <div class='user-info'>
                                    <p><strong>Name:</strong> " . htmlspecialchars($userData['name']) . "</p>
                                    <p><strong>Email:</strong> " . htmlspecialchars($userData['email']) . "</p>
                                    <p><strong>Role:</strong> " . htmlspecialchars($userData['role']) . "</p>
                                    <p><strong>Registration Date:</strong> " . date('Y-m-d H:i:s') . "</p>
                                </div>
                                <p>Please log in to the admin dashboard to approve or reject this user.</p>
                                <p style='text-align: center;'>
                                    <a href='https://" . $_SERVER['HTTP_HOST'] . "/admin/dashboard.php' class='button'>Go to Admin Dashboard</a>
                                </p>
                            </div>
                            <div class='footer'>
                                <p>&copy; " . date('Y') . " EliteFit Gym. All rights reserved.</p>
                            </div>
                        </div>
                    </body>
                    </html>
                ";
                
                // Send email to all admins
                foreach ($adminEmails as $adminEmail) {
                    sendEmail($adminEmail, $adminSubject, $adminBody);
                }
            }
        }
        
        // Log successful registration
        logRegistration($userData['email'], 1, $userData['role'], "Registration successful, verification email sent");
        
        // Clear temporary user data
        unset($_SESSION['temp_user_data']);
        
        // Set success message
        $_SESSION['register_success'] = "Registration successful! Please check your email to verify your account.";
        
        // Redirect to login page
        header("Location: login.php");
        exit;
        
    } catch (PDOException $e) {
        // Log error
        logRegistration($userData['email'], 0, $userData['role'], "Database error: " . $e->getMessage());
        
        // Set error message
        $_SESSION['verification_error'] = "An error occurred during registration. Please try again.";
        
        // Redirect back to verification page
        header("Location: verification.php");
        exit;
    }
} else {
    // If not a POST request, redirect to registration page
    header("Location: register.php");
    exit;
}
?>
