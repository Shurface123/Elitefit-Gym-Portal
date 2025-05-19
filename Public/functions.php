<?php
// Include PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Function to send email using PHPMailer
function sendEmail($to, $subject, $body) {
    // Load Composer's autoloader
    require 'vendor/autoload.php';
    
    // Create a new PHPMailer instance
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.example.com'; // Replace with your SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'your-email@example.com'; // Replace with your email
        $mail->Password   = 'your-password'; // Replace with your email password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Recipients
        $mail->setFrom('noreply@elitefit.com', 'EliteFit Gym');
        $mail->addAddress($to);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// Function to calculate password strength
function calculatePasswordStrength($password) {
    $score = 0;
    
    // Length
    if (strlen($password) >= 8) $score += 1;
    if (strlen($password) >= 12) $score += 1;
    if (strlen($password) >= 16) $score += 1;
    
    // Complexity
    if (preg_match('@[A-Z]@', $password)) $score += 1;
    if (preg_match('@[a-z]@', $password)) $score += 1;
    if (preg_match('@[0-9]@', $password)) $score += 1;
    if (preg_match('@[^\w]@', $password)) $score += 1;
    
    // Variety
    $chars = str_split($password);
    $uniqueChars = array_unique($chars);
    if (count($uniqueChars) >= 8) $score += 1;
    
    return $score;
}

// Function to get password strength description
function getPasswordStrengthDescription($score) {
    if ($score <= 2) return 'Weak';
    if ($score <= 4) return 'Medium';
    if ($score <= 6) return 'Strong';
    return 'Very Strong';
}

// Function to generate a random token
function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

// Function to check if a token is valid
function isValidToken($token, $expiry) {
    if (empty($token) || empty($expiry)) {
        return false;
    }
    
    $expiryTime = strtotime($expiry);
    $currentTime = time();
    
    return $expiryTime > $currentTime;
}

// Function to log user activity
function logUserActivity($userId, $action, $details = null) {
    $conn = connectDB();
    $stmt = $conn->prepare("
        INSERT INTO user_activity_logs (
            user_id, action, details, ip_address, user_agent, timestamp
        ) VALUES (
            ?, ?, ?, ?, ?, NOW()
        )
    ");
    $stmt->execute([
        $userId,
        $action,
        $details,
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT']
    ]);
}

// Function to check if user needs admin approval
function needsAdminApproval($role) {
    $rolesRequiringApproval = ['Trainer', 'EquipmentManager'];
    return in_array($role, $rolesRequiringApproval);
}
?>
