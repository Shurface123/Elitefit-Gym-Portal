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

// Function to validate email format
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
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

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $name = sanitizeInput($_POST["name"]);
    $email = sanitizeInput($_POST["email"]);
    $password = $_POST["password"];
    $confirmPassword = $_POST["confirm_password"];
    $role = sanitizeInput($_POST["role"]);
    $experienceLevel = isset($_POST["experience_level"]) ? sanitizeInput($_POST["experience_level"]) : null;
    $fitnessGoals = isset($_POST["fitness_goals"]) ? sanitizeInput($_POST["fitness_goals"]) : null;
    $preferredRoutines = isset($_POST["preferred_routines"]) ? sanitizeInput($_POST["preferred_routines"]) : null;
    
    // Validate inputs
    $errors = [];
    
    // Check if name is empty
    if (empty($name)) {
        $errors[] = "Name is required";
    }
    
    // Check if email is valid
    if (!isValidEmail($email)) {
        $errors[] = "Invalid email format";
    }
    
    // Check if password and confirm password match
    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match";
    }
    
    // Check password strength
    if (!isStrongPassword($password)) {
        $errors[] = "Password must be at least 8 characters long and include uppercase, lowercase, number, and special character";
    }
    
    // Check if role is valid
    $validRoles = ['Member', 'Trainer', 'Admin', 'EquipmentManager'];
    $roleIsValid = false;

    foreach ($validRoles as $validRole) {
        if ($role === $validRole) {
            $roleIsValid = true;
            break;
        }
    }

    if (!$roleIsValid) {
        $errors[] = "Invalid role selected";
    }
    
    // If there are no errors, proceed with registration
    if (empty($errors)) {
        try {
            // Connect to database
            $conn = connectDB();
            
            // Check if email already exists
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->execute([$email]);
            
            if ($checkStmt->rowCount() > 0) {
                $_SESSION['register_error'] = "Email already exists. Please use a different email or login.";
                logRegistration($email, 0, $role, "Email already exists");
                header("Location: register.php");
                exit;
            }
            
            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Log registration attempt in analytics
            $analyticsStmt = $conn->prepare("
                INSERT INTO registration_analytics (
                    email, role, registration_date, ip_address, 
                    user_agent, referrer, completion_step, status
                ) VALUES (
                    ?, ?, NOW(), ?, ?, ?, 'Form Submitted', 'In Progress'
                )
            ");
            
            $analyticsStmt->execute([
                $email,
                $role,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT'],
                $_SERVER['HTTP_REFERER'] ?? null
            ]);
            
            // Store user data in session for verification
            $_SESSION['temp_user_data'] = [
                'name' => $name,
                'email' => $email,
                'hashed_password' => $hashedPassword,
                'role' => $role,
                'experience_level' => $experienceLevel,
                'fitness_goals' => $fitnessGoals,
                'preferred_routines' => $preferredRoutines
            ];
            
            // Redirect to verification page
            header("Location: verification.php");
            exit;
            
        } catch (PDOException $e) {
            // Log error
            logRegistration($email, 0, $role, "Database error: " . $e->getMessage());
            
            // Set error message
            $_SESSION['register_error'] = "An error occurred during registration. Please try again.";
            
            // Redirect back to registration page
            header("Location: register.php");
            exit;
        }
    } else {
        // If there are validation errors, set error message
        $_SESSION['register_error'] = implode("<br>", $errors);
        
        // Log failed registration
        logRegistration($email, 0, $role, implode(", ", $errors));
        
        // Redirect back to registration page
        header("Location: register.php");
        exit;
    }
} else {
    // If not a POST request, redirect to registration page
    header("Location: register.php");
    exit;
}
?>
