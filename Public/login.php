<?php
// Start session
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
   // Redirect based on role
   switch ($_SESSION['role']) {
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
   }
   exit;
}

// Check for error messages
$error = isset($_SESSION['login_error']) ? $_SESSION['login_error'] : '';
unset($_SESSION['login_error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>ELITEFIT GYM LOGIN</title>
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
           display: flex;
           width: 900px;
           max-width: 95%;
           background: #121212;
           border-radius: var(--border-radius);
           overflow: hidden;
           box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
           color: white;
       }
       
       .login-image {
           flex: 1;
           background-image: url('https://images.unsplash.com/photo-1534438327276-14e5300c3a48?ixlib=rb-1.2.1&auto=format&fit=crop&w=1350&q=80');
           background-size: cover;
           background-position: center;
           position: relative;
           display: flex;
           flex-direction: column;
           justify-content: flex-end;
           padding: 40px;
           color: white;
       }
       
       .login-image::before {
           content: '';
           position: absolute;
           top: 0;
           left: 0;
           right: 0;
           bottom: 0;
           background: linear-gradient(to top, rgba(0, 0, 0, 0.8), transparent);
       }
       
       .login-image h1, .login-image p {
           position: relative;
           z-index: 1;
       }
       
       .login-image h1 {
           font-size: 2.5rem;
           font-weight: 700;
           margin-bottom: 10px;
       }
       
       .login-image p {
           font-size: 1rem;
           opacity: 0.9;
       }
       
       .login-form {
           flex: 1;
           padding: 40px;
           display: flex;
           flex-direction: column;
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
       
       .form-check {
           display: flex;
           align-items: center;
           margin-bottom: 20px;
       }
       
       .form-check input {
           margin-right: 10px;
           accent-color: var(--orange);
       }
       
       .form-check label {
           font-size: 0.9rem;
           color: #e0e0e0;
       }
       
       .forgot-password {
           text-align: right;
           margin-bottom: 20px;
       }
       
       .forgot-password a {
           color: var(--orange);
           text-decoration: none;
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
       }
       
       .btn:hover {
           background-color: #e67e00;
       }
       
       .register-link {
           text-align: center;
           margin-top: 20px;
           font-size: 0.9rem;
           color: #e0e0e0;
       }
       
       .register-link a {
           color: var(--orange);
           text-decoration: none;
           font-weight: 500;
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
       
       .social-login {
           margin-top: 30px;
           border-top: 1px solid #444;
           padding-top: 20px;
       }
       
       .social-login-title {
           text-align: center;
           margin-bottom: 15px;
           position: relative;
       }
       
       .social-login-title span {
           background-color: #121212;
           padding: 0 10px;
           position: relative;
           top: -10px;
           font-size: 0.9rem;
           color: #aaa;
       }
       
       .social-buttons {
           display: flex;
           justify-content: center;
           gap: 15px;
       }
       
       .social-button {
           display: flex;
           align-items: center;
           justify-content: center;
           width: 50px;
           height: 50px;
           border-radius: 50%;
           background-color: #1e1e1e;
           color: white;
           text-decoration: none;
           transition: all 0.3s;
           border: 1px solid #444;
       }
       
       .social-button:hover {
           transform: translateY(-3px);
       }
       
       .social-button.google {
           color: #ea4335;
       }
       
       .social-button.facebook {
           color: #1877f2;
       }
       
       .social-button.apple {
           color: #ffffff;
       }
       
       @media (max-width: 768px) {
           .container {
               flex-direction: column;
           }
           
           .login-image {
               min-height: 200px;
           }
       }
   </style>
</head>
<body>
   <div class="container">
       <div class="login-image">
           <h1>ELITEFIT GYM</h1>
           <p>Transform Your Body, Transform Your Life.</p>
       </div>
       <div class="login-form">
           <div class="logo-container">
               <div class="logo-icon">
                   <i class="fas fa-dumbbell"></i>
               </div>
               <div class="logo-text">ELITEFIT</div>
           </div>
           
           <?php if (!empty($error)): ?>
               <div class="error-message">
                   <?php echo htmlspecialchars($error); ?>
               </div>
           <?php endif; ?>
           
           <form action="login_process.php" method="post">
               <div class="form-group">
                   <label for="email">EMAIL ADDRESS</label>
                   <i class="fas fa-envelope"></i>
                   <input type="email" id="email" name="email" placeholder="Enter your email" required>
               </div>
               
               <div class="form-group">
                   <label for="password">PASSWORD</label>
                   <i class="fas fa-lock"></i>
                   <input type="password" id="password" name="password" placeholder="Enter your password" required>
               </div>
               
               <div class="form-check">
                   <input type="checkbox" id="remember" name="remember">
                   <label for="remember">REMEMBER ME</label>
               </div>
               
               <div class="forgot-password">
                   <a href="forgot_password.php">FORGOT PASSWORD?</a>
               </div>
               
               <button type="submit" class="btn">LOGIN</button>
               
               <div class="register-link">
                   Don't have an account? <a href="register.php">SIGN UP</a>
               </div>
               
               <div class="social-login">
                   <div class="social-login-title">
                       <span>LOGIN WITH</span>
                   </div>
                   <div class="social-buttons">
                       <a href="https://mail.google.com" target="_blank" class="social-button google">
                           <i class="fab fa-google"></i>
                       </a>
                       <a href="https://www.facebook.com/yourprofile" _target="_blank" class="social-button facebook">
                           <i class="fab fa-facebook-f"></i>
                       </a>
                       <a href="https://www.icloud.com/" target="_blank" class="social-button apple">
                           <i class="fab fa-apple"></i>
                       </a>
                   </div>
               </div>
           </form>
       </div>
   </div>
</body>
</html>

