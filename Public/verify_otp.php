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
    header("Location: register.php");
    exit;
}

$email = $_SESSION['verification_email'];
$name = $_SESSION['verification_name'];
$role = $_SESSION['verification_role'];

// Send OTP email function
function sendOTPEmail($email, $name, $otp, $role) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lovelacejohnkwakubaidoo@gmail.com';  // Replace with your email
        $mail->Password   = 'qdep zzus harq poqb';     // Replace with your app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

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
                body { font-family: 'Arial', sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #FF8C00, #FF6B35); padding: 30px; text-align: center; color: white; }
                .logo { font-size: 28px; font-weight: bold; margin-bottom: 10px; }
                .content { padding: 40px 30px; text-align: center; }
                .otp-code { background-color: #f8f9fa; border: 2px dashed #FF8C00; border-radius: 10px; padding: 20px; margin: 30px 0; font-size: 32px; font-weight: bold; color: #FF8C00; letter-spacing: 5px; }
                .footer { background-color: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='logo'>🏋️ ELITEFIT GYM</div>
                    <p>New Verification Code</p>
                </div>
                <div class='content'>
                    <h2>New Verification Code</h2>
                    <p>Hello <strong>" . htmlspecialchars($name) . "</strong>,</p>
                    <p>Here is your new verification code:</p>
                    
                    <div class='otp-code'>" . $otp . "</div>
                    
                    <p><strong>This code will expire in 15 minutes.</strong></p>
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
        return false;
    }
}

// Handle OTP verification
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submittedOTP = trim($_POST['otp']);
    
    if (empty($submittedOTP)) {
        $_SESSION['otp_error'] = "Please enter the verification code.";
    } else {
        try {
            $conn = connectDB();
            
            // Get pending registration
            $stmt = $conn->prepare("
                SELECT * FROM pending_registrations 
                WHERE email = ? AND expires_at > NOW() 
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$email]);
            $pendingReg = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$pendingReg) {
                $_SESSION['otp_error'] = "Verification code has expired. Please register again.";
            } else {
                // Increment verification attempts
                $updateAttemptsStmt = $conn->prepare("
                    UPDATE pending_registrations 
                    SET verification_attempts = verification_attempts + 1 
                    WHERE id = ?
                ");
                $updateAttemptsStmt->execute([$pendingReg['id']]);
                
                // Check if too many attempts
                if ($pendingReg['verification_attempts'] >= 5) {
                    // Delete expired/failed registration
                    $deleteStmt = $conn->prepare("DELETE FROM pending_registrations WHERE id = ?");
                    $deleteStmt->execute([$pendingReg['id']]);
                    
                    $_SESSION['otp_error'] = "Too many failed attempts. Please register again.";
                } else if ($submittedOTP === $pendingReg['otp_code']) {
                    // OTP is correct, create user account
                    $insertUserStmt = $conn->prepare("
                        INSERT INTO users (
                            name, email, password, role, experience_level, 
                            fitness_goals, preferred_routines, email_verified, 
                            created_at, last_login, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), 'active')
                    ");
                    
                    $insertUserStmt->execute([
                        $pendingReg['name'],
                        $pendingReg['email'],
                        $pendingReg['password_hash'],
                        $pendingReg['role'],
                        $pendingReg['experience_level'],
                        $pendingReg['fitness_goals'],
                        $pendingReg['preferred_routines']
                    ]);
                    
                    $userId = $conn->lastInsertId();
                    
                    // Update analytics
                    $analyticsStmt = $conn->prepare("
                        UPDATE registration_analytics 
                        SET completion_step = 'Registration Complete', 
                            status = 'Completed',
                            completed_at = NOW()
                        WHERE email = ? AND status = 'Pending Verification'
                    ");
                    $analyticsStmt->execute([$email]);
                    
                    // Create user profile based on role
                    if ($pendingReg['role'] === 'Member') {
                        $profileStmt = $conn->prepare("
                            INSERT INTO member_profiles (
                                user_id, experience_level, fitness_goals, 
                                preferred_routines, created_at
                            ) VALUES (?, ?, ?, ?, NOW())
                        ");
                        $profileStmt->execute([
                            $userId,
                            $pendingReg['experience_level'],
                            $pendingReg['fitness_goals'],
                            $pendingReg['preferred_routines']
                        ]);
                    } elseif ($pendingReg['role'] === 'Trainer') {
                        $profileStmt = $conn->prepare("
                            INSERT INTO trainer_profiles (
                                user_id, specialization, experience, 
                                training_approach, status, created_at
                            ) VALUES (?, ?, ?, ?, 'pending', NOW())
                        ");
                        $profileStmt->execute([
                            $userId,
                            $pendingReg['experience_level'],
                            $pendingReg['fitness_goals'],
                            $pendingReg['preferred_routines']
                        ]);
                    }
                    
                    // Delete pending registration
                    $deleteStmt = $conn->prepare("DELETE FROM pending_registrations WHERE id = ?");
                    $deleteStmt->execute([$pendingReg['id']]);
                    
                    // Clear verification session
                    unset($_SESSION['verification_email']);
                    unset($_SESSION['verification_name']);
                    unset($_SESSION['verification_role']);
                    
                    // Set success message
                    $_SESSION['register_success'] = "Registration completed successfully! You can now login to your account.";
                    
                    // Redirect to login page
                    header("Location: login.php");
                    exit;
                } else {
                    $remainingAttempts = 5 - ($pendingReg['verification_attempts'] + 1);
                    $_SESSION['otp_error'] = "Invalid verification code. $remainingAttempts attempts remaining.";
                }
            }
        } catch (PDOException $e) {
            $_SESSION['otp_error'] = "An error occurred during verification. Please try again.";
            error_log("OTP Verification Error: " . $e->getMessage());
        }
    }
}

// Handle resend OTP
if (isset($_GET['resend']) && $_GET['resend'] === '1') {
    try {
        $conn = connectDB();
        
        // Check if we can resend (limit to prevent spam)
        $checkStmt = $conn->prepare("
            SELECT created_at FROM pending_registrations 
            WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        ");
        $checkStmt->execute([$email]);
        
        if ($checkStmt->rowCount() > 0) {
            $_SESSION['otp_error'] = "Please wait 2 minutes before requesting a new code.";
        } else {
            // Generate new OTP
            $newOTP = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $newExpiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Update pending registration with new OTP
            $updateStmt = $conn->prepare("
                UPDATE pending_registrations 
                SET otp_code = ?, expires_at = ?, verification_attempts = 0, created_at = NOW()
                WHERE email = ?
            ");
            $updateStmt->execute([$newOTP, $newExpiry, $email]);
            
            // Send new OTP email
            if (sendOTPEmail($email, $name, $newOTP, $role)) {
                $_SESSION['otp_success'] = "A new verification code has been sent to your email.";
            } else {
                $_SESSION['otp_error'] = "Failed to send new verification code. Please try again.";
            }
        }
    } catch (PDOException $e) {
        $_SESSION['otp_error'] = "An error occurred. Please try again.";
        error_log("Resend OTP Error: " . $e->getMessage());
    }
    
    header("Location: verify_otp.php");
    exit;
}

// Get messages
$success = isset($_SESSION['otp_success']) ? $_SESSION['otp_success'] : '';
$error = isset($_SESSION['otp_error']) ? $_SESSION['otp_error'] : '';

// Clear messages
unset($_SESSION['otp_success']);
unset($_SESSION['otp_error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: #ff4d4d;
            --secondary: #333;
            --light: #f8f9fa;
            --dark: #212529;
            --success: #28a745;
            --border-radius: 8px;
            --orange: #FF8C00;
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
            justify-content: center;
            align-items: center;
            margin: 0;
            color: white;
            padding: 20px;
            position: relative;
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
        
        .container {
            width: 500px;
            max-width: 95%;
            background: #121212;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
            color: white;
        }
        
        .header {
            background: #1e1e1e;
            padding: 30px;
            text-align: center;
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
        }
        
        .logo-text {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--orange);
            letter-spacing: 2px;
        }
        
        .header h2 {
            color: white;
            font-size: 1.5rem;
            margin-top: 10px;
        }
        
        .content {
            padding: 40px 30px;
        }
        
        .verification-info {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .verification-info h3 {
            color: var(--orange);
            margin-bottom: 15px;
            font-size: 1.3rem;
        }
        
        .verification-info p {
            color: #e0e0e0;
            line-height: 1.6;
            margin-bottom: 10px;
        }
        
        .email-display {
            background: #1e1e1e;
            padding: 15px;
            border-radius: var(--border-radius);
            border: 1px solid #444;
            margin: 20px 0;
            text-align: center;
        }
        
        .email-display strong {
            color: var(--orange);
            font-size: 1.1rem;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.9rem;
            color: #e0e0e0;
            font-weight: 500;
        }
        
        .otp-input {
            width: 100%;
            padding: 15px;
            border: 2px solid #444;
            background-color: #1e1e1e;
            border-radius: var(--border-radius);
            font-size: 1.5rem;
            text-align: center;
            letter-spacing: 5px;
            transition: all 0.3s;
            color: white;
            font-weight: bold;
        }
        
        .otp-input:focus {
            border-color: var(--orange);
            outline: none;
            box-shadow: 0 0 0 3px rgba(255, 140, 0, 0.2);
        }
        
        .btn {
            width: 100%;
            padding: 15px;
            background-color: var(--orange);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 15px;
        }
        
        .btn:hover {
            background-color: #e67e00;
            transform: translateY(-2px);
        }
        
        .btn:disabled {
            background-color: #666;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-secondary {
            background-color: transparent;
            border: 1px solid #444;
            color: #e0e0e0;
        }
        
        .btn-secondary:hover {
            background-color: #2a2a2a;
            border-color: var(--orange);
        }
        
        .resend-section {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #333;
        }
        
        .resend-text {
            color: #aaa;
            font-size: 0.9rem;
            margin-bottom: 15px;
        }
        
        .timer {
            color: var(--orange);
            font-weight: bold;
            font-size: 1.1rem;
            margin: 10px 0;
        }
        
        .success-message {
            background-color: rgba(40, 167, 69, 0.2);
            color: #75e096;
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        
        .error-message {
            background-color: rgba(220, 53, 69, 0.2);
            color: #ff6b6b;
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        
        .back-link a {
            color: var(--orange);
            text-decoration: none;
            font-weight: 500;
        }
        
        .back-link a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .container {
                margin: 10px;
            }
            
            .content {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-container">
                <div class="logo-icon">
                    <i class="fas fa-dumbbell"></i>
                </div>
                <div class="logo-text">ELITEFIT</div>
            </div>
            <h2>EMAIL VERIFICATION</h2>
        </div>
        
        <div class="content">
            <?php if (!empty($success)): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <div class="verification-info">
                <h3><i class="fas fa-envelope"></i> Check Your Email</h3>
                <p>We've sent a 6-digit verification code to:</p>
                <div class="email-display">
                    <strong><?php echo htmlspecialchars($email); ?></strong>
                </div>
                <p>Enter the code below to complete your registration as a <strong><?php echo htmlspecialchars($role); ?></strong>.</p>
            </div>
            
            <form action="verify_otp.php" method="post" id="otpForm">
                <div class="form-group">
                    <label for="otp">Verification Code</label>
                    <input type="text" id="otp" name="otp" class="otp-input" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required autocomplete="off">
                </div>
                
                <button type="submit" class="btn" id="verifyBtn">
                    <i class="fas fa-check"></i> Verify Email
                </button>
            </form>
            
            <div class="resend-section">
                <p class="resend-text">Didn't receive the code?</p>
                <div class="timer" id="timer">You can request a new code in <span id="countdown">120</span> seconds</div>
                <a href="verify_otp.php?resend=1" class="btn btn-secondary" id="resendBtn" style="display: none;">
                    <i class="fas fa-redo"></i> Send New Code
                </a>
            </div>
            
            <div class="back-link">
                <a href="register.php"><i class="fas fa-arrow-left"></i> Back to Registration</a>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-format OTP input
        document.getElementById('otp').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 6) {
                value = value.substring(0, 6);
            }
            e.target.value = value;
            
            // Auto-submit when 6 digits are entered
            if (value.length === 6) {
                document.getElementById('otpForm').submit();
            }
        });
        
        // Countdown timer for resend
        let countdown = 120;
        const timerElement = document.getElementById('timer');
        const resendBtn = document.getElementById('resendBtn');
        const countdownSpan = document.getElementById('countdown');
        
        const timer = setInterval(function() {
            countdown--;
            countdownSpan.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(timer);
                timerElement.style.display = 'none';
                resendBtn.style.display = 'inline-block';
            }
        }, 1000);
        
        // Focus on OTP input when page loads
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('otp').focus();
        });
        
        // Prevent form submission with invalid OTP
        document.getElementById('otpForm').addEventListener('submit', function(e) {
            const otp = document.getElementById('otp').value;
            if (otp.length !== 6 || !/^\d{6}$/.test(otp)) {
                e.preventDefault();
                alert('Please enter a valid 6-digit verification code.');
            }
        });
    </script>
</body>
</html>