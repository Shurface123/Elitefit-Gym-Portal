<?php
// This script helps install PHPMailer using Composer

// Check if Composer is installed
$composerInstalled = false;
$output = [];
$returnVar = 0;
exec('composer --version', $output, $returnVar);
$composerInstalled = ($returnVar === 0);

// Check if PHPMailer is already installed
$phpmailerInstalled = file_exists('vendor/phpmailer/phpmailer/src/PHPMailer.php');

// Initialize variables
$message = '';
$status = '';

// Process installation if requested
if (isset($_POST['install']) && $composerInstalled && !$phpmailerInstalled) {
    // Create composer.json if it doesn't exist
    if (!file_exists('composer.json')) {
        file_put_contents('composer.json', json_encode([
            'require' => [
                'phpmailer/phpmailer' => '^6.8'
            ]
        ], JSON_PRETTY_PRINT));
    }
    
    // Run composer install
    $output = [];
    $returnVar = 0;
    exec('composer require phpmailer/phpmailer', $output, $returnVar);
    
    if ($returnVar === 0) {
        $message = 'PHPMailer has been successfully installed!';
        $status = 'success';
        $phpmailerInstalled = true;
    } else {
        $message = 'Failed to install PHPMailer. Error: ' . implode("\n", $output);
        $status = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install PHPMailer - EliteFit Gym</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #ff6600;
            --primary-light: #ff8533;
            --primary-dark: #e65c00;
            --secondary: #000000;
            --secondary-light: #333333;
            --light: #ffffff;
            --gray: #f5f5f5;
            --dark-gray: #333333;
            --text-color: #333333;
            --bg-color: #ffffff;
            --card-bg: #ffffff;
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Montserrat', sans-serif;
        }
        
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        /* Header Styles */
        header {
            background-color: var(--secondary);
            color: var(--light);
            padding: 15px 0;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.2);
        }
        
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            display: flex;
            align-items: center;
            font-size: 24px;
            font-weight: 800;
            color: var(--light);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .logo:hover {
            transform: scale(1.05);
        }
        
        .logo i {
            color: var(--primary);
            margin-right: 10px;
            font-size: 28px;
        }
        
        /* Main Content */
        main {
            flex: 1;
            padding: 60px 0;
        }
        
        .card {
            background-color: var(--card-bg);
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            padding: 40px;
            margin-bottom: 30px;
            border-top: 5px solid var(--primary);
        }
        
        .card-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .card-header h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 28px;
            margin-bottom: 10px;
            color: var(--secondary);
        }
        
        .card-header p {
            color: var(--dark-gray);
            font-size: 16px;
            line-height: 1.6;
        }
        
        .card-icon {
            font-size: 60px;
            color: var(--primary);
            margin-bottom: 20px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            border: 1px solid rgba(40, 167, 69, 0.2);
            color: #28a745;
        }
        
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border: 1px solid rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        
        .alert-warning {
            background-color: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .alert-info {
            background-color: rgba(23, 162, 184, 0.1);
            border: 1px solid rgba(23, 162, 184, 0.2);
            color: #17a2b8;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: var(--primary);
            color: var(--light);
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(255, 102, 0, 0.3);
        }
        
        .btn-secondary {
            background-color: var(--secondary);
        }
        
        .btn-secondary:hover {
            background-color: var(--secondary-light);
        }
        
        .btn-disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        
        .btn-disabled:hover {
            background-color: #ccc;
            transform: none;
            box-shadow: none;
        }
        
        .steps {
            margin: 30px 0;
        }
        
        .step {
            margin-bottom: 20px;
            padding: 20px;
            background-color: var(--gray);
            border-radius: 8px;
        }
        
        .step h3 {
            margin-bottom: 10px;
            color: var(--secondary);
        }
        
        .step p {
            margin-bottom: 15px;
            line-height: 1.6;
        }
        
        .step code {
            display: block;
            padding: 10px;
            background-color: #f1f1f1;
            border-radius: 4px;
            font-family: monospace;
            margin-bottom: 15px;
            overflow-x: auto;
        }
        
        .actions {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
        }
        
        /* Footer */
        footer {
            background-color: var(--secondary);
            color: var(--light);
            padding: 20px 0;
            text-align: center;
            font-size: 14px;
        }
        
        .footer-content p {
            margin: 5px 0;
        }
        
        .footer-content a {
            color: var(--primary);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .footer-content a:hover {
            color: var(--primary-light);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container header-container">
            <a href="index.php" class="logo">
                <i class="fas fa-dumbbell"></i>
                EliteFit
            </a>
        </div>
    </header>
    
    <!-- Main Content -->
    <main>
        <div class="container">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <h1>Install PHPMailer</h1>
                    <p>This utility will help you install PHPMailer to enable email functionality in your EliteFit Gym application.</p>
                </div>
                
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $status === 'success' ? 'success' : 'danger'; ?>">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!$composerInstalled): ?>
                    <div class="alert alert-warning">
                        <strong>Composer Not Found:</strong> Composer is required to install PHPMailer.
                    </div>
                    
                    <div class="steps">
                        <div class="step">
                            <h3>Step 1: Install Composer</h3>
                            <p>Download and install Composer from <a href="https://getcomposer.org/download/" target="_blank">getcomposer.org</a></p>
                        </div>
                        
                        <div class="step">
                            <h3>Step 2: Verify Installation</h3>
                            <p>After installing Composer, refresh this page to continue with PHPMailer installation.</p>
                        </div>
                    </div>
                <?php elseif ($phpmailerInstalled): ?>
                    <div class="alert alert-success">
                        <strong>PHPMailer is already installed!</strong> You can now use it to send emails from your application.
                    </div>
                    
                    <div class="steps">
                        <div class="step">
                            <h3>Configure PHPMailer</h3>
                            <p>Edit the <code>forgot-password.php</code> file to configure your SMTP settings:</p>
                            <code>
$mail->Host       = 'smtp.gmail.com'; // Change to your SMTP server<br>
$mail->Username   = 'lovelacejohnkwakubaidoo@gmail.com'; // Change to your email<br>
$mail->Password   = 'qdep zzus harq poqb'; // Change to your app password
                            </code>
                            <p>For Gmail, you'll need to create an App Password. <a href="https://support.google.com/accounts/answer/185833" target="_blank">Learn how</a></p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <strong>Ready to Install:</strong> Composer is installed and PHPMailer is ready to be installed.
                    </div>
                    
                    <div class="steps">
                        <div class="step">
                            <h3>Install PHPMailer</h3>
                            <p>Click the button below to install PHPMailer using Composer:</p>
                            <form method="post" action="">
                                <button type="submit" name="install" class="btn">Install PHPMailer</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="actions">
                    <a href="forgot_password.php" class="btn">Back to Forgot Password</a>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-content">
                <p>&copy; <?php echo date('Y'); ?> EliteFit Gym. All Rights Reserved.</p>
                <p>Need help? <a href="contact.php">Contact Support</a></p>
            </div>
        </div>
    </footer>
</body>
</html>
