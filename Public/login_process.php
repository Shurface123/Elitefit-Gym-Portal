<?php
// Start session
session_start();

// Include database connection
require_once __DIR__ . '/db_connect.php';

// Function to sanitize input data
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Function to log login attempts
function logLoginAttempt($email, $success, $ip, $userAgent, $role = null) {
    $conn = connectDB();
    $stmt = $conn->prepare("INSERT INTO login_logs (email, success, ip_address, user_agent, role, timestamp) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$email, $success, $ip, $userAgent, $role]);
}

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $email = sanitizeInput($_POST["email"]);
    $password = $_POST["password"];
    $remember = isset($_POST["remember"]) ? true : false;
    
    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['login_error'] = "Invalid email format";
        header("Location: login.php");
        exit;
    }
    
    // Connect to database
    $conn = connectDB();
    
    // Prepare SQL statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->rowCount() === 1) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verify password
        if (password_verify($password, $user['password'])) {
            // Password is correct, set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            
            // Set remember me cookie if checked
            if ($remember) {
                // Generate a secure token
                $token = bin2hex(random_bytes(32));
                
                // Store token in database
                $tokenStmt = $conn->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
                $tokenStmt->execute([$user['id'], $token]);
                
                // Set cookie
                setcookie("remember_token", $token, time() + (86400 * 30), "/", "", true, true); // 30 days
            }
            
            // Log successful login
            logLoginAttempt($email, 1, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $user['role']);
            
            // Redirect based on role
            switch ($user['role']) {
                case 'Member':
                    header("Location: member/dashboard.php");
                    break;
                case 'Trainer':
                    header("Location: trainer/dashboard.php");
                    break;
                case 'Admin':
                    header("Location: admin/dashboard.php");
                    break;
                case 'EquipmentManager':
                    header("Location: equipment/dashboard.php");
                    break;
                default:
                    header("Location: index.php");
                    break;
            }
            exit;
        } else {
            // Log failed login
            logLoginAttempt($email, 0, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            
            // Password is incorrect
            $_SESSION['login_error'] = "Invalid email or password";
            header("Location: login.php");
            exit;
        }
    } else {
        // Log failed login
        logLoginAttempt($email, 0, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        
        // User not found
        $_SESSION['login_error'] = "Invalid email or password";
        header("Location: login.php");
        exit;
    }
} else {
    // If not a POST request, redirect to login page
    header("Location: login.php");
    exit;
}
?>

