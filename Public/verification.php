<?php
// Start session
session_start();

// Check if user data exists in session
if (!isset($_SESSION['temp_user_data'])) {
    // Redirect to registration page if no data
    header("Location: register.php");
    exit;
}

// Get temporary user data from session
$userData = $_SESSION['temp_user_data'];

// Check for error messages
$error = isset($_SESSION['verification_error']) ? $_SESSION['verification_error'] : '';
unset($_SESSION['verification_error']);

// Google reCAPTCHA site key
$recaptchaSiteKey = "6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI"; // Replace with your actual site key
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ELITEFIT GYM - Verify Password</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
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
            padding: 20px;
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
        
        .verification-form {
            padding: 30px;
        }
        
        .form-title {
            font-size: 1.5rem;
            margin-bottom: 20px;
            color: white;
            text-align: center;
        }
        
        .form-subtitle {
            font-size: 0.9rem;
            margin-bottom: 25px;
            color: #aaa;
            text-align: center;
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
        
        .password-strength {
            margin-top: 10px;
            font-size: 0.85rem;
        }
        
        .password-strength-meter {
            height: 5px;
            background-color: #444;
            border-radius: 5px;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .password-strength-meter div {
            height: 100%;
            border-radius: 5px;
            transition: width 0.3s;
        }
        
        .password-requirements {
            margin-top: 15px;
            font-size: 0.85rem;
            color: #aaa;
        }
        
        .password-requirements ul {
            list-style-type: none;
            padding-left: 0;
            margin-top: 5px;
        }
        
        .password-requirements li {
            margin-bottom: 5px;
            display: flex;
            align-items: center;
        }
        
        .password-requirements li i {
            margin-right: 8px;
            font-size: 0.8rem;
        }
        
        .requirement-met {
            color: var(--success);
        }
        
        .requirement-not-met {
            color: #aaa;
        }
        
        .btn-container {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
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
        }
        
        .btn:hover {
            background-color: #e67e00;
        }
        
        .btn-secondary {
            background-color: transparent;
            border: 1px solid #444;
        }
        
        .btn-secondary:hover {
            background-color: #2a2a2a;
        }
        
        .error-message {
            background-color: rgba(220, 53, 69, 0.2);
            color: #ff6b6b;
            padding: 10px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        
        .user-info {
            background-color: #1e1e1e;
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }
        
        .user-info p {
            margin: 5px 0;
            font-size: 0.9rem;
        }
        
        .user-info strong {
            color: var(--orange);
        }
        
        .g-recaptcha {
            margin: 20px 0;
            display: flex;
            justify-content: center;
        }
        
        /* Password Strength Meter */
        .password-strength-container {
            margin-top: 10px;
        }
        
        .password-strength-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            margin-bottom: 5px;
        }
        
        .strength-text {
            font-weight: 500;
        }
        
        .strength-weak {
            color: #dc3545;
        }
        
        .strength-medium {
            color: #ffc107;
        }
        
        .strength-strong {
            color: #28a745;
        }
        
        .strength-very-strong {
            color: #20c997;
        }
        
        .password-strength-bar {
            height: 6px;
            background-color: #444;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .password-strength-bar div {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        
        .strength-bar-weak {
            background-color: #dc3545;
        }
        
        .strength-bar-medium {
            background-color: #ffc107;
        }
        
        .strength-bar-strong {
            background-color: #28a745;
        }
        
        .strength-bar-very-strong {
            background-color: #20c997;
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
            <h2>VERIFY YOUR PASSWORD</h2>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <div class="verification-form">
            <h3 class="form-title">One Last Step!</h3>
            <p class="form-subtitle">Please verify your password to complete your registration</p>
            
            <div class="user-info">
                <p><strong>Name:</strong> <?php echo htmlspecialchars($userData['name']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($userData['email']); ?></p>
                <p><strong>Role:</strong> <?php echo htmlspecialchars($userData['role']); ?></p>
            </div>
            
            <form action="verification_process.php" method="post">
                <div class="form-group">
                    <label for="verification_password">Enter Your Password Again</label>
                    <i class="fas fa-lock"></i>
                    <input type="password" id="verification_password" name="verification_password" placeholder="Enter your password" required>
                    
                    <div class="password-strength-container">
                        <div class="password-strength-label">
                            <span>Password Strength:</span>
                            <span class="strength-text" id="strength-text">None</span>
                        </div>
                        <div class="password-strength-bar">
                            <div id="strength-bar" style="width: 0%;"></div>
                        </div>
                    </div>
                </div>
                
                <div class="password-requirements">
                    <p>Password must meet the following requirements:</p>
                    <ul>
                        <li id="length-requirement"><i class="fas fa-circle"></i> At least 8 characters long</li>
                        <li id="uppercase-requirement"><i class="fas fa-circle"></i> At least one uppercase letter</li>
                        <li id="lowercase-requirement"><i class="fas fa-circle"></i> At least one lowercase letter</li>
                        <li id="number-requirement"><i class="fas fa-circle"></i> At least one number</li>
                        <li id="special-requirement"><i class="fas fa-circle"></i> At least one special character</li>
                    </ul>
                </div>
                
                <!-- Google reCAPTCHA -->
                <div class="g-recaptcha" data-sitekey="<?php echo $recaptchaSiteKey; ?>"></div>
                
                <div class="btn-container">
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='register.php'">Cancel</button>
                    <button type="submit" class="btn">Complete Registration</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Password strength checker
        const passwordInput = document.getElementById('verification_password');
        const lengthRequirement = document.getElementById('length-requirement');
        const uppercaseRequirement = document.getElementById('uppercase-requirement');
        const lowercaseRequirement = document.getElementById('lowercase-requirement');
        const numberRequirement = document.getElementById('number-requirement');
        const specialRequirement = document.getElementById('special-requirement');
        const strengthText = document.getElementById('strength-text');
        const strengthBar = document.getElementById('strength-bar');
        
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            
            // Check requirements
            const hasLength = password.length >= 8;
            const hasUppercase = /[A-Z]/.test(password);
            const hasLowercase = /[a-z]/.test(password);
            const hasNumber = /[0-9]/.test(password);
            const hasSpecial = /[^A-Za-z0-9]/.test(password);
            
            // Update requirement indicators
            lengthRequirement.innerHTML = hasLength 
                ? '<i class="fas fa-check-circle requirement-met"></i> At least 8 characters long'
                : '<i class="fas fa-circle requirement-not-met"></i> At least 8 characters long';
                
            uppercaseRequirement.innerHTML = hasUppercase
                ? '<i class="fas fa-check-circle requirement-met"></i> At least one uppercase letter'
                : '<i class="fas fa-circle requirement-not-met"></i> At least one uppercase letter';
                
            lowercaseRequirement.innerHTML = hasLowercase
                ? '<i class="fas fa-check-circle requirement-met"></i> At least one lowercase letter'
                : '<i class="fas fa-circle requirement-not-met"></i> At least one lowercase letter';
                
            numberRequirement.innerHTML = hasNumber
                ? '<i class="fas fa-check-circle requirement-met"></i> At least one number'
                : '<i class="fas fa-circle requirement-not-met"></i> At least one number';
                
            specialRequirement.innerHTML = hasSpecial
                ? '<i class="fas fa-check-circle requirement-met"></i> At least one special character'
                : '<i class="fas fa-circle requirement-not-met"></i> At least one special character';
            
            // Calculate password strength
            let strength = 0;
            if (password.length > 0) strength += 1;
            if (password.length >= 8) strength += 1;
            if (password.length >= 12) strength += 1;
            if (hasUppercase) strength += 1;
            if (hasLowercase) strength += 1;
            if (hasNumber) strength += 1;
            if (hasSpecial) strength += 1;
            if (password.length >= 16) strength += 1;
            
            // Update strength meter
            let strengthClass = '';
            let strengthPercentage = 0;
            
            if (password.length === 0) {
                strengthText.textContent = 'None';
                strengthText.className = 'strength-text';
                strengthBar.className = '';
                strengthBar.style.width = '0%';
            } else if (strength < 3) {
                strengthText.textContent = 'Weak';
                strengthText.className = 'strength-text strength-weak';
                strengthBar.className = 'strength-bar-weak';
                strengthPercentage = 25;
            } else if (strength < 5) {
                strengthText.textContent = 'Medium';
                strengthText.className = 'strength-text strength-medium';
                strengthBar.className = 'strength-bar-medium';
                strengthPercentage = 50;
            } else if (strength < 7) {
                strengthText.textContent = 'Strong';
                strengthText.className = 'strength-text strength-strong';
                strengthBar.className = 'strength-bar-strong';
                strengthPercentage = 75;
            } else {
                strengthText.textContent = 'Very Strong';
                strengthText.className = 'strength-text strength-very-strong';
                strengthBar.className = 'strength-bar-very-strong';
                strengthPercentage = 100;
            }
            
            strengthBar.style.width = strengthPercentage + '%';
        });
    </script>
</body>
</html>
