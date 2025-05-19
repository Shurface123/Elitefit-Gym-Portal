<?php
// Start session
session_start();

// Include database connection
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/functions.php';

// Get token from URL
$token = isset($_GET['token']) ? $_GET['token'] : '';
$message = '';
$messageType = '';

if (empty($token)) {
    $message = "Invalid verification link. Please request a new one.";
    $messageType = "error";
} else {
    try {
        // Connect to database
        $conn = connectDB();
        
        // Check if token exists and is valid
        $stmt = $conn->prepare("
            SELECT id, email, role, status, token_expiry 
            FROM users 
            WHERE verification_token = ? AND status = 'Pending Email Verification'
        ");
        $stmt->execute([$token]);
        
        if ($stmt->rowCount() === 1) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if token is expired
            if (!isValidToken($token, $user['token_expiry'])) {
                $message = "Verification link has expired. Please request a new one.";
                $messageType = "error";
            } else {
                // Determine new status based on role
                $newStatus = 'Active';
                if (needsAdminApproval($user['role'])) {
                    $newStatus = 'Pending Admin Approval';
                }
                
                // Update user status
                $updateStmt = $conn->prepare("
                    UPDATE users 
                    SET status = ?, email_verified_at = NOW(), verification_token = NULL 
                    WHERE id = ?
                ");
                $updateStmt->execute([$newStatus, $user['id']]);
                
                // Log user activity
                logUserActivity($user['id'], 'Email Verified', 'User verified email address');
                
                // Update registration analytics
                $analyticsStmt = $conn->prepare("
                    UPDATE registration_analytics 
                    SET completion_step = 'Email Verified', status = ? 
                    WHERE user_id = ? OR email = ?
                ");
                $analyticsStmt->execute([$newStatus, $user['id'], $user['email']]);
                
                // Set success message
                if ($newStatus === 'Active') {
                    $message = "Your email has been verified successfully! You can now login to your account.";
                } else {
                    $message = "Your email has been verified successfully! Your account is now pending admin approval.";
                }
                $messageType = "success";
                
                // Notify admin if approval is required
                if ($newStatus === 'Pending Admin Approval') {
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
                                        <p>A new user has verified their email and requires your approval:</p>
                                        <div class='user-info'>
                                            <p><strong>Email:</strong> " . htmlspecialchars($user['email']) . "</p>
                                            <p><strong>Role:</strong> " . htmlspecialchars($user['role']) . "</p>
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
            }
        } else {
            $message = "Invalid verification link or account already verified.";
            $messageType = "error";
        }
    } catch (PDOException $e) {
        $message = "An error occurred during verification. Please try again.";
        $messageType = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ELITEFIT GYM - Email Verification</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: #ff4d4d;
            --secondary: #333;
            --light: #f8f9fa;
            --dark: #212529;
            --success: #28a745;
            --danger: #dc3545;
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
            text-align: center;
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
        
        .content {
            padding: 30px;
        }
        
        .message {
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }
        
        .message-success {
            background-color: rgba(40, 167, 69, 0.2);
            color: #75e096;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        
        .message-error {
            background-color: rgba(220, 53, 69, 0.2);
            color: #ff6b6b;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        
        .icon-container {
            font-size: 4rem;
            margin: 20px 0;
        }
        
        .icon-success {
            color: var(--success);
        }
        
        .icon-error {
            color: var(--danger);
        }
        
        .btn {
            display: inline-block;
            padding: 12px 20px;
            background-color: var(--orange);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            margin-top: 20px;
        }
        
        .btn:hover {
            background-color: #e67e00;
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
            <div class="message message-<?php echo $messageType; ?>">
                <?php echo $message; ?>
            </div>
            
            <div class="icon-container">
                <?php if ($messageType === 'success'): ?>
                    <i class="fas fa-check-circle icon-success"></i>
                <?php else: ?>
                    <i class="fas fa-times-circle icon-error"></i>
                <?php endif; ?>
            </div>
            
            <?php if ($messageType === 'success'): ?>
                <p>Thank you for verifying your email address.</p>
                <?php if (strpos($message, 'pending admin approval') !== false): ?>
                    <p>Your account is now pending admin approval. You will receive an email once your account is approved.</p>
                <?php else: ?>
                    <p>You can now login to your account and start your fitness journey!</p>
                <?php endif; ?>
                <a href="login.php" class="btn">Go to Login</a>
            <?php else: ?>
                <p>There was a problem with your verification link.</p>
                <a href="login.php" class="btn">Go to Login</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
