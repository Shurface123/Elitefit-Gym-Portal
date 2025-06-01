<?php
// Start session
session_start();

// Include database connection to fetch workout plans
require_once __DIR__ . '/db_connect.php';

// Fetch workout plans for member registration
$workout_plans = [];
try {
    $conn = connectDB();
    $stmt = $conn->prepare("SELECT plan_id, plan_name, description, difficulty_level FROM workout_plans ORDER BY plan_name");
    $stmt->execute();
    $workout_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If there's an error, continue with empty array
    $workout_plans = [];
}

// Check for success or error messages
$success = isset($_SESSION['register_success']) ? $_SESSION['register_success'] : '';
$error = isset($_SESSION['register_error']) ? $_SESSION['register_error'] : '';

// Clear session messages
unset($_SESSION['register_success']);
unset($_SESSION['register_error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ELITEFIT GYM REGISTER</title>
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
            --error: #dc3545;
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
            width: 1000px;
            max-width: 95%;
            background: #121212;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
            color: white;
            display: flex;
            flex-direction: column;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .header {
            background: #1e1e1e;
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #333;
            position: sticky;
            top: 0;
            z-index: 100;
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
        
        .role-selection {
            padding: 30px;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
        }
        
        .role-card {
            background: #1e1e1e;
            border-radius: var(--border-radius);
            padding: 20px;
            width: 180px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .role-card:hover {
            transform: translateY(-5px);
            border-color: var(--orange);
        }
        
        .role-card.active {
            border-color: var(--orange);
            background: #2a2a2a;
        }
        
        .role-icon {
            font-size: 2.5rem;
            color: var(--orange);
            margin-bottom: 15px;
        }
        
        .role-title {
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .role-description {
            font-size: 0.8rem;
            color: #aaa;
        }
        
        .registration-form {
            padding: 30px;
            display: none;
        }
        
        .form-title {
            font-size: 1.5rem;
            margin-bottom: 20px;
            color: white;
            text-align: center;
        }
        
        .form-section {
            margin-bottom: 30px;
            padding: 20px;
            background: #1a1a1a;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--orange);
        }
        
        .form-section h2 {
            color: var(--orange);
            font-size: 1.2rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            flex: 1;
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
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #444;
            background-color: #1e1e1e;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: all 0.3s;
            color: white;
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--orange);
            outline: none;
            box-shadow: 0 0 0 3px rgba(255, 140, 0, 0.2);
        }
        
        .form-group input.error {
            border-color: var(--error);
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.2);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-group .icon {
            position: absolute;
            left: 15px;
            top: 42px;
            color: #aaa;
        }
        
        .form-group .icon + input {
            padding-left: 40px;
        }
        
        .error-message {
            color: var(--error);
            font-size: 0.8rem;
            margin-top: 5px;
            display: none;
        }
        
        .success-message-field {
            color: var(--success);
            font-size: 0.8rem;
            margin-top: 5px;
            display: none;
        }
        
        .age-display {
            color: var(--orange);
            font-size: 0.9rem;
            margin-top: 5px;
            font-weight: 500;
        }
        
        .contact-format {
            color: #aaa;
            font-size: 0.8rem;
            margin-top: 5px;
        }
        
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #2a2a2a;
            border-radius: 5px;
            transition: all 0.3s;
        }
        
        .checkbox-item:hover {
            background: #333;
        }
        
        .checkbox-item input[type="checkbox"] {
            width: auto;
            margin: 0;
        }
        
        .checkbox-item label {
            margin: 0;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .workout-limit-message {
            color: var(--orange);
            font-size: 0.8rem;
            margin-top: 10px;
            font-style: italic;
        }
        
        .btn-container {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            gap: 15px;
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
            flex: 1;
        }
        
        .btn:hover {
            background-color: #e67e00;
        }
        
        .btn:disabled {
            background-color: #666;
            cursor: not-allowed;
        }
        
        .btn-secondary {
            background-color: transparent;
            border: 1px solid #444;
        }
        
        .btn-secondary:hover {
            background-color: #2a2a2a;
        }
        
        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 0.9rem;
            color: #e0e0e0;
        }
        
        .login-link a {
            color: var(--orange);
            text-decoration: none;
            font-weight: 500;
        }
        
        .success-message {
            background-color: rgba(40, 167, 69, 0.2);
            color: #75e096;
            padding: 10px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        
        .error-message-global {
            background-color: rgba(220, 53, 69, 0.2);
            color: #ff6b6b;
            padding: 10px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .role-selection {
                flex-direction: column;
                align-items: center;
            }
            
            .role-card {
                width: 100%;
                max-width: 300px;
            }
            
            .checkbox-group {
                grid-template-columns: 1fr;
            }
            
            .btn-container {
                flex-direction: column;
            }
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
            <h2>JOIN OUR FITNESS COMMUNITY</h2>
        </div>
        
        <?php if (!empty($success)): ?>
            <div class="success-message">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="error-message-global">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <div class="role-selection" id="roleSelection">
            <div class="role-card" data-role="member">
                <div class="role-icon">
                    <i class="fas fa-user"></i>
                </div>
                <div class="role-title">MEMBER</div>
                <div class="role-description">Join as a gym member to access personalized workout plans</div>
            </div>
            
            <div class="role-card" data-role="trainer">
                <div class="role-icon">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="role-title">TRAINER</div>
                <div class="role-description">Register as a fitness trainer to guide members</div>
            </div>
            
            <div class="role-card" data-role="equipment">
                <div class="role-icon">
                    <i class="fas fa-tools"></i>
                </div>
                <div class="role-title">EQUIPMENT MANAGER</div>
                <div class="role-description">Oversee gym equipment maintenance and availability</div>
            </div>
        </div>
        
        <!-- Member Registration Form -->
        <div class="registration-form" id="memberForm">
            <h3 class="form-title">MEMBER REGISTRATION</h3>
            <form action="register_process.php" method="post" id="memberRegistrationForm">
                <input type="hidden" name="role" value="Member">
                
                <!-- Section 1: Personal Information -->
                <div class="form-section">
                    <h2><i class="fas fa-user"></i> Personal Information</h2>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="member_name">Full Name </label>
                            <i class="fas fa-user icon"></i>
                            <input type="text" id="member_name" name="name" placeholder="Enter your full name" required>
                            <div class="error-message" id="name-error"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="member_email">Email Address </label>
                            <i class="fas fa-envelope icon"></i>
                            <input type="email" id="member_email" name="email" placeholder="Enter your email" required>
                            <div class="error-message" id="email-error"></div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="member_contact">Contact Number </label>
                            <i class="fas fa-phone icon"></i>
                            <input type="tel" id="member_contact" name="contact_number" placeholder="Enter 10-digit phone number" maxlength="10" required>
                            <div class="contact-format">Format: 10 digits only (e.g., 1234567890)</div>
                            <div class="error-message" id="contact-error"></div>
                            <div class="success-message-field" id="contact-success"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="member_dob">Date of Birth </label>
                            <i class="fas fa-calendar icon"></i>
                            <input type="date" id="member_dob" name="date_of_birth" required>
                            <div class="age-display" id="age-display"></div>
                            <div class="error-message" id="dob-error"></div>
                            <div class="success-message-field" id="dob-success"></div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="member_password">Password </label>
                            <i class="fas fa-lock icon"></i>
                            <input type="password" id="member_password" name="password" placeholder="Create a password" required>
                            <div class="error-message" id="password-error"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="member_confirm_password">Confirm Password </label>
                            <i class="fas fa-lock icon"></i>
                            <input type="password" id="member_confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                            <div class="error-message" id="confirm-password-error"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Section 2: Fitness Information -->
                <div class="form-section">
                    <h2><i class="fas fa-dumbbell"></i> Fitness Information</h2>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="member_height">Height (cm)</label>
                            <input type="number" id="member_height" name="height" step="0.1" min="100" max="250" placeholder="e.g., 175.5">
                        </div>
                        
                        <div class="form-group">
                            <label for="member_weight">Weight (kg)</label>
                            <input type="number" id="member_weight" name="weight" step="0.1" min="30" max="300" placeholder="e.g., 70.5">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="member_body_type">Body Type</label>
                            <select id="member_body_type" name="body_type">
                                <option value="">Select your body type...</option>
                                <option value="ectomorph">Ectomorph (Lean, difficulty gaining weight)</option>
                                <option value="mesomorph">Mesomorph (Athletic, gains muscle easily)</option>
                                <option value="endomorph">Endomorph (Larger frame, gains weight easily)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="member_experience">Experience Level </label>
                            <select id="member_experience" name="experience_level" required>
                                <option value="">Select your experience level...</option>
                                <option value="beginner">Beginner (0-6 months)</option>
                                <option value="intermediate">Intermediate (6 months - 2 years)</option>
                                <option value="advanced">Advanced (2+ years)</option>
                            </select>
                        </div>
                    </div>
                    
                    <?php if (!empty($workout_plans)): ?>
                    <div class="form-group">
                        <label>Preferred Workout Plans (Select up to 3)</label>
                        <div class="checkbox-group" id="workout-preferences">
                            <?php foreach ($workout_plans as $plan): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="workout_preferences[]" value="<?= $plan['plan_id'] ?>" id="plan_<?= $plan['plan_id'] ?>">
                                    <label for="plan_<?= $plan['plan_id'] ?>">
                                        <strong><?= htmlspecialchars($plan['plan_name']) ?></strong>
                                        <br><small><?= htmlspecialchars($plan['description']) ?></small>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="workout-limit-message" id="workout-limit-message" style="display: none;">
                            You can select up to 3 workout plans. Please unselect some to choose others.
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="member_goals">Fitness Goals</label>
                        <textarea id="member_goals" name="fitness_goals" rows="3" placeholder="Describe your fitness goals (e.g., lose weight, build muscle, improve endurance)"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="member_health_conditions">Health Conditions (if any)</label>
                        <textarea id="member_health_conditions" name="health_conditions" rows="2" placeholder="List any health conditions, injuries, or medical considerations"></textarea>
                    </div>
                </div>
                
                <div class="btn-container">
                    <button type="button" class="btn btn-secondary" onclick="showRoleSelection()">Back</button>
                    <button type="submit" class="btn" id="submit-btn">Register as Member</button>
                </div>
            </form>
            
            <div class="login-link">
                Already have an account? <a href="login.php">LOGIN</a>
            </div>
        </div>
        
        <!-- Trainer Registration Form -->
        <div class="registration-form" id="trainerForm">
            <h3 class="form-title">TRAINER REGISTRATION</h3>
            <form action="register_process.php" method="post">
                <input type="hidden" name="role" value="Trainer">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="trainer_name">Full Name</label>
                        <i class="fas fa-user icon"></i>
                        <input type="text" id="trainer_name" name="name" placeholder="Enter your full name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="trainer_email">Email Address</label>
                        <i class="fas fa-envelope icon"></i>
                        <input type="email" id="trainer_email" name="email" placeholder="Enter your email" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="trainer_password">Password</label>
                        <i class="fas fa-lock icon"></i>
                        <input type="password" id="trainer_password" name="password" placeholder="Create a password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="trainer_confirm_password">Confirm Password</label>
                        <i class="fas fa-lock icon"></i>
                        <input type="password" id="trainer_confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="trainer_specialization">Specialization</label>
                    <select id="trainer_specialization" name="experience_level" required>
                        <option value="">Select your specialization</option>
                        <option value="Strength Training">Strength Training</option>
                        <option value="Cardio">Cardio</option>
                        <option value="Yoga">Yoga</option>
                        <option value="CrossFit">CrossFit</option>
                        <option value="Nutrition">Nutrition</option>
                        <option value="Weight Loss">Weight Loss</option>
                        <option value="Bodybuilding">Bodybuilding</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="trainer_experience">Professional Experience</label>
                    <textarea id="trainer_experience" name="fitness_goals" placeholder="Describe your professional experience and certifications"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="trainer_approach">Training Approach</label>
                    <textarea id="trainer_approach" name="preferred_routines" placeholder="Describe your training approach and methodology"></textarea>
                </div>
                
                <div class="btn-container">
                    <button type="button" class="btn btn-secondary" onclick="showRoleSelection()">Back</button>
                    <button type="submit" class="btn">Register as Trainer</button>
                </div>
            </form>
            
            <div class="login-link">
                Already have an account? <a href="login.php">LOGIN</a>
            </div>
        </div>
        
        <!-- Equipment Manager Registration Form -->
        <div class="registration-form" id="equipmentForm">
            <h3 class="form-title">EQUIPMENT MANAGER REGISTRATION</h3>
            <form action="register_process.php" method="post">
                <input type="hidden" name="role" value="EquipmentManager">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="equipment_name">Full Name</label>
                        <i class="fas fa-user icon"></i>
                        <input type="text" id="equipment_name" name="name" placeholder="Enter your full name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="equipment_email">Email Address</label>
                        <i class="fas fa-envelope icon"></i>
                        <input type="email" id="equipment_email" name="email" placeholder="Enter your email" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="equipment_password">Password</label>
                        <i class="fas fa-lock icon"></i>
                        <input type="password" id="equipment_password" name="password" placeholder="Create a password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="equipment_confirm_password">Confirm Password</label>
                        <i class="fas fa-lock icon"></i>
                        <input type="password" id="equipment_confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="equipment_expertise">Area of Expertise</label>
                    <select id="equipment_expertise" name="experience_level" required>
                        <option value="">Select your area of expertise</option>
                        <option value="Cardio Equipment">Cardio Equipment</option>
                        <option value="Strength Machines">Strength Machines</option>
                        <option value="Free Weights">Free Weights</option>
                        <option value="General Maintenance">General Maintenance</option>
                        <option value="Equipment Procurement">Equipment Procurement</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="equipment_experience">Technical Experience</label>
                    <textarea id="equipment_experience" name="fitness_goals" placeholder="Describe your technical experience with gym equipment"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="equipment_certifications">Certifications</label>
                    <textarea id="equipment_certifications" name="preferred_routines" placeholder="List any relevant certifications or qualifications"></textarea>
                </div>
                
                <div class="btn-container">
                    <button type="button" class="btn btn-secondary" onclick="showRoleSelection()">Back</button>
                    <button type="submit" class="btn">Register as Equipment Manager</button>
                </div>
            </form>
            
            <div class="login-link">
                Already have an account? <a href="login.php">LOGIN</a>
            </div>
        </div>
    </div>
    
    <script>
        // Global validation state
        let validationState = {
            name: false,
            email: false,
            contact: false,
            dob: false,
            password: false,
            confirmPassword: false,
            experienceLevel: false
        };
        
        // Show role selection and hide all forms
        function showRoleSelection() {
            document.getElementById('roleSelection').style.display = 'flex';
            document.getElementById('memberForm').style.display = 'none';
            document.getElementById('trainerForm').style.display = 'none';
            document.getElementById('equipmentForm').style.display = 'none';
            
            // Remove active class from all role cards
            const roleCards = document.querySelectorAll('.role-card');
            roleCards.forEach(card => {
                card.classList.remove('active');
            });
        }
        
        // Show specific form based on role
        function showForm(role) {
            document.getElementById('roleSelection').style.display = 'none';
            document.getElementById('memberForm').style.display = 'none';
            document.getElementById('trainerForm').style.display = 'none';
            document.getElementById('equipmentForm').style.display = 'none';
            
            document.getElementById(role + 'Form').style.display = 'block';
        }
        
        // Validate contact number (exactly 10 digits)
        function validateContact(input) {
            const value = input.value.replace(/\D/g, ''); // Remove non-digits
            const errorDiv = document.getElementById('contact-error');
            const successDiv = document.getElementById('contact-success');
            
            // Update input value to only digits
            input.value = value;
            
            if (value.length === 0) {
                errorDiv.style.display = 'none';
                successDiv.style.display = 'none';
                input.classList.remove('error');
                validationState.contact = false;
            } else if (value.length !== 10) {
                errorDiv.textContent = `Contact number must be exactly 10 digits (currently ${value.length} digits)`;
                errorDiv.style.display = 'block';
                successDiv.style.display = 'none';
                input.classList.add('error');
                validationState.contact = false;
            } else {
                errorDiv.style.display = 'none';
                successDiv.textContent = 'Valid contact number';
                successDiv.style.display = 'block';
                input.classList.remove('error');
                validationState.contact = true;
            }
            
            updateSubmitButton();
        }
        
        // Check if year is leap year
        function isLeapYear(year) {
            return (year % 4 === 0 && year % 100 !== 0) || (year % 400 === 0);
        }
        
        // Get days in month
        function getDaysInMonth(year, month) {
            const daysInMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            if (month === 2 && isLeapYear(year)) {
                return 29;
            }
            return daysInMonth[month - 1];
        }
        
        // Validate date of birth
        function validateDateOfBirth(input) {
            const value = input.value;
            const errorDiv = document.getElementById('dob-error');
            const successDiv = document.getElementById('dob-success');
            const ageDisplay = document.getElementById('age-display');
            
            if (!value) {
                errorDiv.style.display = 'none';
                successDiv.style.display = 'none';
                ageDisplay.textContent = '';
                input.classList.remove('error');
                validationState.dob = false;
                updateSubmitButton();
                return;
            }
            
            const birthDate = new Date(value);
            const today = new Date();
            
            // Check if date is valid
            const inputParts = value.split('-');
            if (inputParts.length !== 3) {
                errorDiv.textContent = 'Please enter date in YYYY-MM-DD format';
                errorDiv.style.display = 'block';
                successDiv.style.display = 'none';
                ageDisplay.textContent = '';
                input.classList.add('error');
                validationState.dob = false;
                updateSubmitButton();
                return;
            }
            
            const year = parseInt(inputParts[0]);
            const month = parseInt(inputParts[1]);
            const day = parseInt(inputParts[2]);
            
            // Validate year range
            if (year < 1900 || year > today.getFullYear()) {
                errorDiv.textContent = `Year must be between 1900 and ${today.getFullYear()}`;
                errorDiv.style.display = 'block';
                successDiv.style.display = 'none';
                ageDisplay.textContent = '';
                input.classList.add('error');
                validationState.dob = false;
                updateSubmitButton();
                return;
            }
            
            // Validate month
            if (month < 1 || month > 12) {
                errorDiv.textContent = 'Month must be between 01 and 12';
                errorDiv.style.display = 'block';
                successDiv.style.display = 'none';
                ageDisplay.textContent = '';
                input.classList.add('error');
                validationState.dob = false;
                updateSubmitButton();
                return;
            }
            
            // Validate day
            const maxDays = getDaysInMonth(year, month);
            if (day < 1 || day > maxDays) {
                let errorMessage = `Day must be between 01 and ${maxDays} for `;
                const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                                 'July', 'August', 'September', 'October', 'November', 'December'];
                errorMessage += monthNames[month - 1];
                if (month === 2) {
                    errorMessage += isLeapYear(year) ? ` ${year} (leap year)` : ` ${year} (non-leap year)`;
                }
                errorDiv.textContent = errorMessage;
                errorDiv.style.display = 'block';
                successDiv.style.display = 'none';
                ageDisplay.textContent = '';
                input.classList.add('error');
                validationState.dob = false;
                updateSubmitButton();
                return;
            }
            
            // Check if date is in the future
            if (birthDate > today) {
                errorDiv.textContent = 'Date of birth cannot be in the future';
                errorDiv.style.display = 'block';
                successDiv.style.display = 'none';
                ageDisplay.textContent = '';
                input.classList.add('error');
                validationState.dob = false;
                updateSubmitButton();
                return;
            }
            
            // Calculate age
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            
            // Check minimum age requirement
            if (age < 18) {
                errorDiv.textContent = `You must be at least 18 years old to register (current age: ${age})`;
                errorDiv.style.display = 'block';
                successDiv.style.display = 'none';
                ageDisplay.textContent = `Age: ${age} years`;
                input.classList.add('error');
                validationState.dob = false;
            } else {
                errorDiv.style.display = 'none';
                successDiv.textContent = 'Valid date of birth';
                successDiv.style.display = 'block';
                ageDisplay.textContent = `Age: ${age} years`;
                input.classList.remove('error');
                validationState.dob = true;
            }
            
            updateSubmitButton();
        }
        
        // Validate password strength
        function validatePassword(input) {
            const value = input.value;
            const errorDiv = document.getElementById('password-error');
            
            if (value.length === 0) {
                errorDiv.style.display = 'none';
                input.classList.remove('error');
                validationState.password = false;
            } else if (value.length < 8) {
                errorDiv.textContent = 'Password must be at least 8 characters long';
                errorDiv.style.display = 'block';
                input.classList.add('error');
                validationState.password = false;
            } else if (!/[A-Z]/.test(value)) {
                errorDiv.textContent = 'Password must contain at least one uppercase letter';
                errorDiv.style.display = 'block';
                input.classList.add('error');
                validationState.password = false;
            } else if (!/[a-z]/.test(value)) {
                errorDiv.textContent = 'Password must contain at least one lowercase letter';
                errorDiv.style.display = 'block';
                input.classList.add('error');
                validationState.password = false;
            } else if (!/[0-9]/.test(value)) {
                errorDiv.textContent = 'Password must contain at least one number';
                errorDiv.style.display = 'block';
                input.classList.add('error');
                validationState.password = false;
            } else if (!/[^\w]/.test(value)) {
                errorDiv.textContent = 'Password must contain at least one special character';
                errorDiv.style.display = 'block';
                input.classList.add('error');
                validationState.password = false;
            } else {
                errorDiv.style.display = 'none';
                input.classList.remove('error');
                validationState.password = true;
            }
            
            // Re-validate confirm password
            const confirmInput = document.getElementById('member_confirm_password');
            if (confirmInput.value) {
                validateConfirmPassword(confirmInput);
            }
            
            updateSubmitButton();
        }
        
        // Validate confirm password
        function validateConfirmPassword(input) {
            const value = input.value;
            const password = document.getElementById('member_password').value;
            const errorDiv = document.getElementById('confirm-password-error');
            
            if (value.length === 0) {
                errorDiv.style.display = 'none';
                input.classList.remove('error');
                validationState.confirmPassword = false;
            } else if (value !== password) {
                errorDiv.textContent = 'Passwords do not match';
                errorDiv.style.display = 'block';
                input.classList.add('error');
                validationState.confirmPassword = false;
            } else {
                errorDiv.style.display = 'none';
                input.classList.remove('error');
                validationState.confirmPassword = true;
            }
            
            updateSubmitButton();
        }
        
        // Validate required fields
        function validateRequired(input, fieldName) {
            const value = input.value.trim();
            
            if (value.length === 0) {
                validationState[fieldName] = false;
            } else {
                validationState[fieldName] = true;
            }
            
            updateSubmitButton();
        }
        
        // Update submit button state
        function updateSubmitButton() {
            const submitBtn = document.getElementById('submit-btn');
            const allValid = Object.values(validationState).every(valid => valid);
            
            submitBtn.disabled = !allValid;
        }
        
        // Limit workout plan selection to 3
        function limitWorkoutSelection() {
            const checkboxes = document.querySelectorAll('input[name="workout_preferences[]"]');
            const limitMessage = document.getElementById('workout-limit-message');
            const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            
            if (checkedCount >= 3) {
                // Disable unchecked checkboxes
                checkboxes.forEach(cb => {
                    if (!cb.checked) {
                        cb.disabled = true;
                    }
                });
                limitMessage.style.display = 'block';
            } else {
                // Enable all checkboxes
                checkboxes.forEach(cb => {
                    cb.disabled = false;
                });
                limitMessage.style.display = 'none';
            }
        }
        
        // Add event listeners when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            const roleCards = document.querySelectorAll('.role-card');
            
            roleCards.forEach(card => {
                card.addEventListener('click', function() {
                    const role = this.getAttribute('data-role');
                    
                    // Add active class to selected card
                    roleCards.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Show corresponding form after a short delay
                    setTimeout(() => {
                        showForm(role);
                    }, 300);
                });
            });
            
            // Member form validation
            const memberContact = document.getElementById('member_contact');
            const memberDob = document.getElementById('member_dob');
            const memberPassword = document.getElementById('member_password');
            const memberConfirmPassword = document.getElementById('member_confirm_password');
            const memberName = document.getElementById('member_name');
            const memberEmail = document.getElementById('member_email');
            const memberExperience = document.getElementById('member_experience');
            
            if (memberContact) {
                memberContact.addEventListener('input', function() {
                    validateContact(this);
                });
                
                // Prevent non-numeric input
                memberContact.addEventListener('keypress', function(e) {
                    if (!/[0-9]/.test(e.key) && !['Backspace', 'Delete', 'Tab', 'Enter'].includes(e.key)) {
                        e.preventDefault();
                    }
                });
            }
            
            if (memberDob) {
                memberDob.addEventListener('change', function() {
                    validateDateOfBirth(this);
                });
            }
            
            if (memberPassword) {
                memberPassword.addEventListener('input', function() {
                    validatePassword(this);
                });
            }
            
            if (memberConfirmPassword) {
                memberConfirmPassword.addEventListener('input', function() {
                    validateConfirmPassword(this);
                });
            }
            
            if (memberName) {
                memberName.addEventListener('input', function() {
                    validateRequired(this, 'name');
                });
            }
            
            if (memberEmail) {
                memberEmail.addEventListener('input', function() {
                    validateRequired(this, 'email');
                });
            }
            
            if (memberExperience) {
                memberExperience.addEventListener('change', function() {
                    validateRequired(this, 'experienceLevel');
                });
            }
            
            // Workout plan selection limit
            const workoutCheckboxes = document.querySelectorAll('input[name="workout_preferences[]"]');
            workoutCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', limitWorkoutSelection);
            });
            
            // Form submission validation
            const memberForm = document.getElementById('memberRegistrationForm');
            if (memberForm) {
                memberForm.addEventListener('submit', function(event) {
                    const password = document.getElementById('member_password').value;
                    const confirmPassword = document.getElementById('member_confirm_password').value;
                    
                    if (password !== confirmPassword) {
                        event.preventDefault();
                        alert('Passwords do not match. Please try again.');
                        return;
                    }
                    
                    // Check if all validations pass
                    const allValid = Object.values(validationState).every(valid => valid);
                    if (!allValid) {
                        event.preventDefault();
                        alert('Please fix all validation errors before submitting.');
                        return;
                    }
                });
            }
            
            // Initialize submit button state
            updateSubmitButton();
        });
    </script>
</body>
</html>