<?php
// Initialize session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if email content exists in session
if (!isset($_SESSION['debug_email']) || !isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit;
}

$debug_email = $_SESSION['debug_email'];
$reset_email = $_SESSION['reset_email'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Preview - ELITEFIT GYM</title>
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
            width: 800px;
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
        
        .page-title {
            text-align: center;
            margin-bottom: 20px;
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .page-description {
            text-align: center;
            margin-bottom: 30px;
            color: #e0e0e0;
            font-size: 0.9rem;
            line-height: 1.6;
        }
        
        .email-preview {
            margin-top: 20px;
            padding: 15px;
            border: 1px dashed #444;
            border-radius: var(--border-radius);
            background-color: #1e1e1e;
            height: 500px;
            overflow-y: auto;
        }
        
        .email-preview h3 {
            margin-bottom: 10px;
            color: var(--orange);
            font-size: 1rem;
        }
        
        .email-info {
            margin-bottom: 15px;
            padding: 10px;
            background-color: #2a2a2a;
            border-radius: var(--border-radius);
        }
        
        .email-info p {
            margin: 5px 0;
            font-size: 0.9rem;
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
            text-align: center;
            display: block;
            text-decoration: none;
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
        
        .note {
            margin-top: 20px;
            padding: 15px;
            background-color: rgba(255, 140, 0, 0.1);
            border-left: 4px solid var(--orange);
            border-radius: 4px;
            font-size: 0.9rem;
            line-height: 1.6;
        }
        
        .note h4 {
            margin-bottom: 5px;
            color: var(--orange);
        }
        
        .note ul {
            margin-top: 10px;
            margin-left: 20px;
        }
        
        .note li {
            margin-bottom: 5px;
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
            
            .page-title {
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
        
        <h2 class="page-title">EMAIL PREVIEW</h2>
        <p class="page-description">This is a preview of the password reset email that would be sent in production.</p>
        
        <div class="email-info">
            <p><strong>To:</strong> <?php echo htmlspecialchars($reset_email); ?></p>
            <p><strong>Subject:</strong> EliteFit Gym - Password Reset Request</p>
            <p><strong>Date:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
        
        <div class="email-preview">
            <h3>Email Content:</h3>
            <iframe srcdoc="<?php echo htmlspecialchars($debug_email); ?>" style="width: 100%; height: 400px; border: none; background: white;"></iframe>
        </div>
        
        <div class="note">
            <h4>Development Mode Notice</h4>
            <p>This email preview is shown because the system is in development mode or PHPMailer is not properly configured. In production, this email would be sent directly to the user's inbox.</p>
            <p>To enable actual email sending:</p>
            <ul>
                <li>Install PHPMailer via Composer: <code>composer require phpmailer/phpmailer</code></li>
                <li>Configure your SMTP settings in the forgot_password.php file</li>
                <li>For Gmail, use an App Password instead of your regular password</li>
                <li>Make sure your hosting environment allows outgoing SMTP connections</li>
            </ul>
        </div>
        
        <a href="forgot_password.php" class="btn">BACK TO FORGOT PASSWORD</a>
        
        <div class="back-link">
            <a href="login.php"><i class="fas fa-arrow-left"></i> BACK TO LOGIN</a>
        </div>
    </div>
</body>
</html>