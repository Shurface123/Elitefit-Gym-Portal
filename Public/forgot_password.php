<?php
// Initialize session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once 'config/db_connect.php';

// Check if PHPMailer is installed, if not, provide instructions
if (!file_exists('vendor/autoload.php')) {
    $phpmailer_missing = true;
}

// Initialize variables
$email = "";
$error = "";
$success = "";
$debug_email = ""; // For development environment

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get and sanitize email
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    
    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // Check if email exists in the database
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Email exists, generate token
            $user = $result->fetch_assoc();
            $token = bin2hex(random_bytes(32)); // Generate a secure random token
            $expires = date('Y-m-d H:i:s', time() + 3600); // Token expires in 1 hour
            
            // Delete any existing tokens for this user
            $stmt = $conn->prepare("DELETE FROM password_reset WHERE user_id = ?");
            $stmt->bind_param("i", $user['id']);
            $stmt->execute();
            
            // Store token in database
            $stmt = $conn->prepare("INSERT INTO password_reset (user_id, token, expires) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $user['id'], $token, $expires);
            
            if ($stmt->execute()) {
                // Determine the correct path to the reset password file
                // Get the directory of the current script
                $current_dir = dirname($_SERVER['PHP_SELF']);
                
                // If we're in the root directory, $current_dir will be '/'
                // Otherwise, make sure it ends with a slash
                if ($current_dir != '/') {
                    $current_dir = $current_dir . '/';
                }
                
                // Create reset link with the correct path
                // First, check if reset_password.php exists
                if (file_exists(__DIR__ . '/reset_password.php')) {
                    $reset_file = 'reset_password.php';
                } 
                // Then check if reset-password.php exists
                else if (file_exists(__DIR__ . '/reset-password.php')) {
                    $reset_file = 'reset-password.php';
                }
                // Finally, check if the file might be in a subdirectory
                else if (file_exists(__DIR__ . '/Login/reset_password.php')) {
                    $reset_file = 'Login/reset_password.php';
                }
                else if (file_exists(__DIR__ . '/Login/reset-password.php')) {
                    $reset_file = 'Login/reset-password.php';
                }
                // Default to reset_password.php if we can't find the file
                else {
                    $reset_file = 'reset_password.php';
                }
                
                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . $current_dir . $reset_file . "?token=" . $token;
                
                // Email message (HTML)
                $message = '
                <!DOCTYPE html>
                <html>
                <head>
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            line-height: 1.6;
                            color: #333;
                        }
                        .container {
                            max-width: 600px;
                            margin: 0 auto;
                            padding: 20px;
                            border: 1px solid #ddd;
                        }
                        .header {
                            background-color: #000;
                            color: #fff;
                            padding: 15px;
                            text-align: center;
                        }
                        .logo {
                            color: #FF8C00;
                            font-size: 24px;
                            font-weight: bold;
                        }
                        .content {
                            padding: 20px;
                        }
                        .button {
                            display: inline-block;
                            background-color: #FF8C00;
                            color: #fff;
                            padding: 12px 24px;
                            text-decoration: none;
                            border-radius: 4px;
                            margin: 20px 0;
                        }
                        .footer {
                            background-color: #f5f5f5;
                            padding: 15px;
                            text-align: center;
                            font-size: 12px;
                        }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <div class="logo">EliteFit Gym</div>
                        </div>
                        <div class="content">
                            <h2>Password Reset Request</h2>
                            <p>Hello User,</p>
                            <p>We received a request to reset your password for your EliteFit Gym account. If you did not make this request, you can ignore this email.</p>
                            <p>To reset your password, click the button below:</p>
                            <a href="' . $reset_link . '" class="button">Reset Password</a>
                            <p>This link will expire in 1 hour for security reasons.</p>
                            <p>If the button above doesn\'t work, copy and paste the following URL into your browser:</p>
                            <p>' . $reset_link . '</p>
                            <p>Thank you,<br>The EliteFit Gym Team</p>
                        </div>
                        <div class="footer">
                            <p>&copy; ' . date('Y') . ' EliteFit Gym. All rights reserved.</p>
                            <p>This email was sent to ' . $email . ' because a password reset was requested for your account.</p>
                        </div>
                    </div>
                </body>
                </html>
                ';
                
                // For development environment, store the email content for display
                $debug_email = $message;
                
                // Try to send email using PHPMailer if available
                $email_sent = false;
                
                if (!isset($phpmailer_missing)) {
                    try {
                        require 'vendor/autoload.php';
                        
                        // Create a new PHPMailer instance
                        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                        
                        // Server settings
                        $mail->isSMTP();
                        $mail->Host       = 'smtp.gmail.com'; // Change to your SMTP server
                        $mail->SMTPAuth   = true;
                        $mail->Username   = 'your-email@gmail.com'; // Change to your email
                        $mail->Password   = 'your-app-password'; // Change to your app password
                        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = 587;
                        
                        // Recipients
                        $mail->setFrom('noreply@elitefit.com', 'EliteFit Gym');
                        $mail->addAddress($email);
                        
                        // Content
                        $mail->isHTML(true);
                        $mail->Subject = 'EliteFit Gym - Password Reset Request';
                        $mail->Body    = $message;
                        
                        $mail->send();
                        $email_sent = true;
                    } catch (Exception $e) {
                        // Log the error but don't show it to the user
                        error_log("Email sending failed: " . $e->getMessage());
                    }
                }
                
                // If we're in development mode or email sending failed, show success anyway
                // In production, you would want to check $email_sent
                $success = "A password reset link has been sent to your email address. Please check your inbox and spam folder.";
                
                // Clear email field after successful submission
                $email = "";
            } else {
                $error = "An error occurred. Please try again later.";
            }
        } else {
            // Email doesn't exist, but don't reveal this for security reasons
            $success = "If your email address exists in our database, you will receive a password reset link shortly.";
            // Clear email field after submission
            $email = "";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - ELITEFIT GYM</title>
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
            padding: 40px;
            animation: fadeIn 0.5s ease-in-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .logo-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .logo-icon {
            font-size: 3rem;
            color: var(--orange);
            margin-bottom: 10px;
        }
        
        .logo-text {
            font-size: 2rem;
            font-weight: 700;
            color: var(--orange);
            letter-spacing: 2px;
        }
        
        .form-title {
            text-align: center;
            margin-bottom: 20px;
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .form-description {
            text-align: center;
            margin-bottom: 30px;
            color: #e0e0e0;
            font-size: 0.9rem;
            line-height: 1.6;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.9rem;
            color: #e0e0e0;
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            padding-left: 40px;
            border: 1px solid #444;
            background-color: #1e1e1e;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: all 0.3s;
            color: white;
        }
        
        .form-group input:focus {
            border-color: var(--orange);
            outline: none;
            box-shadow: 0 0 0 3px rgba(255, 140, 0, 0.2);
        }
        
        .form-group i {
            position: absolute;
            left: 15px;
            top: 42px;
            color: #aaa;
        }
        
        .btn {
            padding: 12px 20px;
            background-color: var(--orange);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-bottom: 20px;
        }
        
        .btn:hover {
            background-color: #e67e00;
            transform: translateY(-2px);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .back-link {
            text-align: center;
            font-size: 0.9rem;
            color: #e0e0e0;
        }
        
        .back-link a {
            color: var(--orange);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .back-link a:hover {
            color: #ffa64d;
            text-decoration: underline;
        }
        
        .error-message {
            background-color: rgba(220, 53, 69, 0.2);
            color: #ff6b6b;
            padding: 10px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid rgba(220, 53, 69, 0.3);
            animation: shake 0.5s;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        .success-message {
            background-color: rgba(40, 167, 69, 0.2);
            color: #75e096;
            padding: 10px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid rgba(40, 167, 69, 0.3);
            animation: fadeIn 0.5s;
        }
        
        .security-info {
            font-size: 0.8rem;
            color: #aaa;
            text-align: center;
            margin-top: 20px;
            line-height: 1.5;
        }
        
        .debug-email {
            margin-top: 30px;
            padding: 15px;
            border: 1px dashed #444;
            border-radius: var(--border-radius);
            background-color: #1e1e1e;
            max-height: 300px;
            overflow-y: auto;
            color: #e0e0e0;
        }
        
        .debug-email h3 {
            margin-bottom: 10px;
            color: var(--orange);
            font-size: 1rem;
        }
        
        @media (max-width: 576px) {
            .container {
                padding: 30px 20px;
            }
            
            .logo-icon {
                font-size: 2.5rem;
            }
            
            .logo-text {
                font-size: 1.8rem;
            }
            
            .form-title {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-container">
            <div class="logo-icon">
                <i class="fas fa-dumbbell"></i>
            </div>
            <div class="logo-text">ELITEFIT</div>
        </div>
        
        <h2 class="form-title">FORGOT PASSWORD</h2>
        <p class="form-description">Enter your email address and we'll send you instructions to reset your password.</p>
        
        <?php if (!empty($error)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="success-message">
                <?php echo htmlspecialchars($success); ?>
            </div>
            
            <?php if (!empty($debug_email) && (isset($phpmailer_missing) || !$email_sent)): ?>
                <div class="debug-email">
                    <h3>Development Mode: Email Preview</h3>
                    <iframe srcdoc="<?php echo htmlspecialchars($debug_email); ?>" style="width: 100%; height: 300px; border: none; background: white;"></iframe>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if (empty($success)): ?>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="forgotPasswordForm">
                <div class="form-group">
                    <label for="email">EMAIL ADDRESS</label>
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="email" name="email" placeholder="Enter your registered email" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>
                
                <button type="submit" class="btn">SEND RESET LINK</button>
            </form>
        <?php endif; ?>
        
        <div class="back-link">
            <a href="login.php"><i class="fas fa-arrow-left"></i> BACK TO LOGIN</a>
        </div>
        
        <div class="security-info">
            <p><i class="fas fa-lock"></i> Your security is important to us. We'll never ask for your password outside of the login page.</p>
        </div>
    </div>
    
    <script>
        // Form validation
        document.getElementById('forgotPasswordForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            if (!email || !email.includes('@')) {
                e.preventDefault();
                
                // Create or update error message
                let errorMessage = document.querySelector('.error-message');
                if (!errorMessage) {
                    errorMessage = document.createElement('div');
                    errorMessage.className = 'error-message';
                    this.insertBefore(errorMessage, this.firstChild);
                }
                
                errorMessage.textContent = 'Please enter a valid email address.';
                
                // Add error styling to input
                document.getElementById('email').style.borderColor = '#dc3545';
                document.getElementById('email').focus();
                return false;
            }
            
            return true;
        });
        
        // Clear error styling on input
        document.getElementById('email').addEventListener('input', function() {
            this.style.borderColor = '';
            const errorMessage = document.querySelector('.error-message');
            if (errorMessage) {
                errorMessage.remove();
            }
        });
    </script>
</body>
</html>
