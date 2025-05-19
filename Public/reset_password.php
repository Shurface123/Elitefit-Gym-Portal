<?php
// Initialize session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once 'config/db_connect.php';

// Initialize variables
$token = "";
$error = "";
$success = "";
$tokenValid = false;
$userId = null;

// Check if token is provided in URL
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];
    
    // Validate token
    $stmt = $conn->prepare("SELECT user_id, expires FROM password_reset WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $resetData = $result->fetch_assoc();
        $expiryTime = strtotime($resetData['expires']);
        
        // Check if token has expired
        if (time() > $expiryTime) {
            $error = "This password reset link has expired. Please request a new one.";
        } else {
            $tokenValid = true;
            $userId = $resetData['user_id'];
        }
    } else {
        $error = "Invalid password reset link. Please request a new one.";
    }
} else {
    $error = "No reset token provided. Please request a password reset from the forgot password page.";
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && $tokenValid) {
    $password = trim($_POST['password']);
    $confirmPassword = trim($_POST['confirm_password']);
    
    // Validate password
    if (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } else {
        // Hash the new password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Update user's password
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashedPassword, $userId);
        
        if ($stmt->execute()) {
            // Delete the used token
            $stmt = $conn->prepare("DELETE FROM password_reset WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            
            $success = "Your password has been successfully reset. You can now login with your new password.";
            $tokenValid = false; // Hide the form after successful reset
        } else {
            $error = "An error occurred while resetting your password. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - ELITEFIT GYM</title>
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
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 42px;
            color: #aaa;
            cursor: pointer;
            transition: color 0.2s;
        }
        
        .password-toggle:hover {
            color: var(--orange);
        }
        
        .password-strength {
            margin-top: 10px;
            font-size: 0.8rem;
        }
        
        .strength-meter {
            height: 5px;
            width: 100%;
            background-color: #444;
            border-radius: 3px;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .strength-meter-fill {
            height: 100%;
            width: 0;
            border-radius: 3px;
            transition: width 0.3s ease, background-color 0.3s ease;
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
        
        .btn:disabled {
            background-color: #555;
            cursor: not-allowed;
            transform: none;
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
        
        #password-match {
            font-size: 0.8rem;
            margin-top: 5px;
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
        
        <h2 class="form-title">RESET PASSWORD</h2>
        <p class="form-description">Create a new secure password for your ELITEFIT GYM account.</p>
        
        <?php if (!empty($error)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="success-message">
                <?php echo htmlspecialchars($success); ?>
                <p style="margin-top: 10px;">
                    <a href="login.php" style="color: #75e096; text-decoration: underline;">Click here to login</a> with your new password.
                </p>
            </div>
        <?php endif; ?>
        
        <?php if ($tokenValid): ?>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . '?token=' . $token); ?>" method="post" id="reset-password-form">
                <div class="form-group">
                    <label for="password">NEW PASSWORD</label>
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" placeholder="Enter your new password" required minlength="8">
                    <span class="password-toggle" id="password-toggle">
                        <i class="fas fa-eye"></i>
                    </span>
                    <div class="password-strength">
                        <span id="password-strength-text">Password strength</span>
                        <div class="strength-meter">
                            <div class="strength-meter-fill" id="strength-meter-fill"></div>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">CONFIRM PASSWORD</label>
                    <i class="fas fa-lock"></i>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your new password" required minlength="8">
                    <span class="password-toggle" id="confirm-password-toggle">
                        <i class="fas fa-eye"></i>
                    </span>
                    <div id="password-match"></div>
                </div>
                
                <button type="submit" class="btn" id="reset-btn">RESET PASSWORD</button>
            </form>
        <?php endif; ?>
        
        <?php if (!$tokenValid && empty($success)): ?>
            <div class="back-link">
                <a href="forgot_password.php"><i class="fas fa-arrow-left"></i> BACK TO FORGOT PASSWORD</a>
            </div>
        <?php else: ?>
            <div class="back-link">
                <a href="login.php"><i class="fas fa-arrow-left"></i> BACK TO LOGIN</a>
            </div>
        <?php endif; ?>
        
        <div class="security-info">
            <p><i class="fas fa-shield-alt"></i> Create a strong password that includes uppercase letters, numbers, and special characters.</p>
        </div>
    </div>
    
    <script>
        // Toggle password visibility
        document.getElementById('password-toggle').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        document.getElementById('confirm-password-toggle').addEventListener('click', function() {
            const confirmPasswordInput = document.getElementById('confirm_password');
            const icon = this.querySelector('i');
            
            if (confirmPasswordInput.type === 'password') {
                confirmPasswordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                confirmPasswordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Password strength meter
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthMeter = document.getElementById('strength-meter-fill');
            const strengthText = document.getElementById('password-strength-text');
            
            // Calculate password strength
            let strength = 0;
            
            // Length check
            if (password.length >= 8) {
                strength += 25;
            }
            
            // Contains lowercase letters
            if (/[a-z]/.test(password)) {
                strength += 25;
            }
            
            // Contains uppercase letters
            if (/[A-Z]/.test(password)) {
                strength += 25;
            }
            
            // Contains numbers or special characters
            if (/[0-9!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)) {
                strength += 25;
            }
            
            // Update strength meter
            strengthMeter.style.width = strength + '%';
            
            // Update color based on strength
            if (strength < 25) {
                strengthMeter.style.backgroundColor = '#dc3545'; // Red
                strengthText.textContent = 'Very Weak';
                strengthText.style.color = '#dc3545';
            } else if (strength < 50) {
                strengthMeter.style.backgroundColor = '#ffc107'; // Yellow
                strengthText.textContent = 'Weak';
                strengthText.style.color = '#ffc107';
            } else if (strength < 75) {
                strengthMeter.style.backgroundColor = '#fd7e14'; // Orange
                strengthText.textContent = 'Medium';
                strengthText.style.color = '#fd7e14';
            } else {
                strengthMeter.style.backgroundColor = '#28a745'; // Green
                strengthText.textContent = 'Strong';
                strengthText.style.color = '#28a745';
            }
            
            // Check password match
            checkPasswordMatch();
        });
        
        // Check if passwords match
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchText = document.getElementById('password-match');
            const resetBtn = document.getElementById('reset-btn');
            
            if (confirmPassword.length > 0) {
                if (password === confirmPassword) {
                    matchText.textContent = 'Passwords match';
                    matchText.style.color = '#28a745';
                    resetBtn.disabled = false;
                } else {
                    matchText.textContent = 'Passwords do not match';
                    matchText.style.color = '#dc3545';
                    resetBtn.disabled = true;
                }
            } else {
                matchText.textContent = '';
                resetBtn.disabled = false;
            }
        }
        
        document.getElementById('confirm_password').addEventListener('input', checkPasswordMatch);
        
        // Form validation
        document.getElementById('reset-password-form').addEventListener('submit', function(event) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password.length < 8) {
                event.preventDefault();
                
                // Create or update error message
                let errorMessage = document.querySelector('.error-message');
                if (!errorMessage) {
                    errorMessage = document.createElement('div');
                    errorMessage.className = 'error-message';
                    this.insertBefore(errorMessage, this.firstChild);
                }
                
                errorMessage.textContent = 'Password must be at least 8 characters long.';
                return;
            }
            
            if (password !== confirmPassword) {
                event.preventDefault();
                
                // Create or update error message
                let errorMessage = document.querySelector('.error-message');
                if (!errorMessage) {
                    errorMessage = document.createElement('div');
                    errorMessage.className = 'error-message';
                    this.insertBefore(errorMessage, this.firstChild);
                }
                
                errorMessage.textContent = 'Passwords do not match.';
                return;
            }
        });
    </script>
</body>
</html>
