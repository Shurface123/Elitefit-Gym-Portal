<?php
session_start();
require_once __DIR__ . '/db_connect.php';

// Store referring page on first visit to logout page
if (!isset($_GET['confirm']) && !isset($_SESSION['logout_referrer']) && isset($_SERVER['HTTP_REFERER'])) {
    // Only save if the referrer is not the logout page itself
    if (strpos($_SERVER['HTTP_REFERER'], 'logout.php') === false) {
        $_SESSION['logout_referrer'] = $_SERVER['HTTP_REFERER'];
    }
}

// Check if the user has confirmed logout
if (!isset($_GET['confirm'])) {
    // Default return URL if nothing else works
    $return_url = 'index.php';
    
    // Use referring page from session if available
    if (isset($_SESSION['logout_referrer'])) {
        $return_url = $_SESSION['logout_referrer'];
    }
    
    // If still no valid return URL, check for common pages
    if ($return_url === 'logout.php' || empty($return_url)) {
        // Try to find a valid landing page
        $possible_pages = ['dashboard.php', 'home.php', 'main.php', 'user_dashboard.php', 'index.php'];
        foreach ($possible_pages as $page) {
            if (file_exists($page)) {
                $return_url = $page;
                break;
            }
        }
    }
    
    // If not confirmed, show confirmation page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Confirm Logout - EliteFit Gym</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
        <style>
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
            .logout-container {
                background-color: rgba(0, 0, 0, 0.7);
                border-radius: 12px;
                padding: 35px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
                text-align: center;
                max-width: 500px;
                width: 90%;
                min-height: 500px; /* Added minimum height */
                display: flex;
                flex-direction: column;
                justify-content: space-between; /* Distributes content evenly */
                backdrop-filter: blur(10px);
                border: 1px solid rgba(255, 255, 255, 0.05);
      }
            .logo {
                margin-bottom: 25px;
                font-size: 34px;
                font-weight: bold;
                color: #FF8C00;
                text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            }
            h2 {
                margin-top: 0;
                color: #FF8C00;
                font-size: 26px;
            }
            p {
                margin: 20px 0;
                font-size: 16px;
                line-height: 1.6;
            }
            .btn-container {
                display: flex;
                justify-content: center;
                gap: 18px;
                margin-top: 30px;
            }
            .btn {
                padding: 14px 28px;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-weight: 600;
                font-size: 16px;
                transition: all 0.3s ease;
                display: inline-block;
                text-align: center;
                text-decoration: none;
                position: relative;
                overflow: hidden;
            }
            .btn::after {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(255, 255, 255, 0.1);
                opacity: 0;
                transition: opacity 0.3s ease;
            }
            .btn:hover::after {
                opacity: 1;
            }
            .btn-cancel {
                background-color: #333333;
                color: white;
                border: 1px solid #444444;
            }
            .btn-logout {
                background-color: #FF8C00;
                color: white;
                box-shadow: 0 4px 8px rgba(255, 140, 0, 0.3);
            }
            .btn:hover {
                transform: translateY(-3px);
                box-shadow: 0 6px 15px rgba(0, 0, 0, 0.3);
            }
            .btn-cancel:hover {
                background-color: #444444;
            }
            .btn-logout:hover {
                background-color: #FF9F1A;
                box-shadow: 0 6px 15px rgba(255, 140, 0, 0.4);
            }
            .icon {
                font-size: 18px;
                margin-right: 10px;
            }
        </style>
    </head>
    <body>
        <div class="logout-container">
            <div class="logo">
                <i class="fas fa-dumbbell"></i> EliteFit Gym
            </div>
            <h2>Confirm Logout</h2>
            <p>Are you sure you want to end your current session and logout from EliteFit Gym portal?</p>
            <p>Any unsaved changes may be lost.</p>
            <div class="btn-container">
                <a href="<?php echo htmlspecialchars($return_url); ?>" class="btn btn-cancel">
                    <i class="fas fa-times icon"></i> Cancel
                </a>
                <a href="logout.php?confirm=1" class="btn btn-logout">
                    <i class="fas fa-sign-out-alt icon"></i> Logout
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// User has confirmed logout, so clean up

// Clear the referrer from session before destroying
if (isset($_SESSION['logout_referrer'])) {
    unset($_SESSION['logout_referrer']);
}

// Clear remember me cookie if exists
if (isset($_COOKIE['remember_token'])) {
    // Connect to database to delete token
    $conn = connectDB();
    
    if ($conn) {
        $token = $_COOKIE['remember_token'];
        $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE token = ?");
        $stmt->execute([$token]);
    }
    
    // Delete cookie
    setcookie("remember_token", "", time() - 3600, "/");
}

// Enhanced user identification - get all possible user identifiers before destroying session
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$fullName = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : '';
$firstName = isset($_SESSION['first_name']) ? $_SESSION['first_name'] : '';
$lastName = isset($_SESSION['last_name']) ? $_SESSION['last_name'] : '';
$email = isset($_SESSION['email']) ? $_SESSION['email'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '';

// Try to build the most specific name possible
if (!empty($fullName)) {
    $displayName = $fullName;
} elseif (!empty($firstName) && !empty($lastName)) {
    $displayName = $firstName . ' ' . $lastName;
} elseif (!empty($firstName)) {
    $displayName = $firstName;
} elseif (!empty($username)) {
    $displayName = $username;
} elseif (!empty($email)) {
    // Extract name from email if possible (before the @ symbol)
    $emailParts = explode('@', $email);
    $displayName = $emailParts[0];
} elseif (!empty($userId)) {
    // As a last resort, show the user ID
    $displayName = 'User #' . $userId;
} else {
    // If absolutely no user info is available
    $displayName = '';
}

// Destroy session
session_unset();
session_destroy();

// Show redirect page with countdown
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out - EliteFit Gym</title>
    <meta http-equiv="refresh" content="5;url=login.php">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
        .logout-container {
            background-color: rgba(0, 0, 0, 0.7);
            border-radius: 12px;
            padding: 35px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            text-align: center;
            max-width: 500px;
            width: 90%;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .logo {
            margin-bottom: 25px;
            font-size: 34px;
            font-weight: bold;
            color: #FF8C00;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }
        h2 {
            margin-top: 0;
            color: #FF8C00;
            font-size: 26px;
        }
        p {
            margin: 20px 0;
            font-size: 16px;
            line-height: 1.6;
        }
        .countdown {
            font-size: 48px;
            font-weight: bold;
            color: #FF8C00;
            margin: 25px 0;
            text-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
        }
        .loader {
            width: 85%;
            height: 10px;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 5px;
            margin: 25px auto;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.3);
        }
        .loader-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #FF8C00, #FFB347);
            border-radius: 5px;
            animation: progress 5s linear forwards;
            box-shadow: 0 0 8px rgba(255, 140, 0, 0.5);
        }
        .message {
            margin-top: 25px;
            font-style: italic;
            color: #ddd;
        }
        .btn {
            display: inline-block;
            margin-top: 25px;
            padding: 12px 25px;
            background-color: #FF8C00;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 8px rgba(255, 140, 0, 0.3);
            position: relative;
            overflow: hidden;
        }
        .btn::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.1);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .btn:hover::after {
            opacity: 1;
        }
        .btn:hover {
            background-color: #FF9F1A;
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(255, 140, 0, 0.4);
        }
        @keyframes progress {
            0% { width: 0%; }
            100% { width: 100%; }
        }
        .icon-container {
            font-size: 60px;
            color: #FF8C00;
            margin: 25px 0;
            animation: pulse 1.5s infinite;
            text-shadow: 0 2px 10px rgba(255, 140, 0, 0.3);
        }
        @keyframes pulse {
            0% { transform: scale(1); opacity: 0.8; }
            50% { transform: scale(1.1); opacity: 1; }
            100% { transform: scale(1); opacity: 0.8; }
        }
        .user-greeting {
            font-size: 20px;
            font-weight: 600;
            color: #FF8C00;
            margin-bottom: 10px;
            padding: 5px 15px;
            display: inline-block;
            border-radius: 30px;
            background-color: rgba(255, 140, 0, 0.1);
            border: 1px solid rgba(255, 140, 0, 0.2);
        }
        .no-display-name {
            display: none;
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <div class="logo">
            <i class="fas fa-dumbbell"></i> EliteFit Gym
        </div>
        <div class="icon-container">
            <i class="fas fa-sign-out-alt"></i>
        </div>
        <h2>Successfully Logged Out</h2>
        
        <?php if (!empty($displayName)): ?>
        <div class="user-greeting">
            Thank you, <?php echo htmlspecialchars($displayName); ?>!
        </div>
        <?php endif; ?>
        
        <p>You have safely logged out of your EliteFit Gym portal session.</p>
        <p>You will be redirected to the login page in:</p>
        <div class="countdown" id="countdown">5</div>
        <div class="loader">
            <div class="loader-bar"></div>
        </div>
        <p class="message">We look forward to seeing you again for your next training session!</p>
        <a href="login.php" class="btn">
            <i class="fas fa-sign-in-alt icon"></i> Login Now
        </a>
    </div>

    <script>
        // Countdown timer
        let seconds = 5;
        const countdownElement = document.getElementById('countdown');
        
        const interval = setInterval(() => {
            seconds--;
            countdownElement.textContent = seconds;
            
            if (seconds <= 0) {
                clearInterval(interval);
                window.location.href = 'login.php';
            }
        }, 1000);
    </script>
</body>
</html>
<?php
exit;
?>