-- =====================================================
-- ELITEFIT GYM TRAINER DASHBOARD DATABASE SCHEMA
-- Complete database structure for all trainer features
-- =====================================================

-- Drop existing tables if they exist (in correct order to handle foreign keys)
DROP TABLE IF EXISTS trainer_activity;
DROP TABLE IF EXISTS nutrition_tracking;
DROP TABLE IF EXISTS meal_plan_items;
DROP TABLE IF EXISTS meal_plans;
DROP TABLE IF EXISTS meal_templates;
DROP TABLE IF EXISTS assessment_reports;
DROP TABLE IF EXISTS assessment_goals;
DROP TABLE IF EXISTS progress_photos;
DROP TABLE IF EXISTS fitness_tests;
DROP TABLE IF EXISTS body_measurements;
DROP TABLE IF EXISTS assessments;
DROP TABLE IF EXISTS assessment_types;
DROP TABLE IF EXISTS nutrition_logs;
DROP TABLE IF EXISTS workout_sessions;
DROP TABLE IF EXISTS workout_exercises;
DROP TABLE IF EXISTS workout_plans;
DROP TABLE IF EXISTS trainer_schedule;
DROP TABLE IF EXISTS trainer_members;
DROP TABLE IF EXISTS trainer_specializations;
DROP TABLE IF EXISTS trainer_certifications;
DROP TABLE IF EXISTS trainer_preferences;

-- =====================================================
-- TRAINER CORE TABLES
-- =====================================================

-- Trainer Preferences Table (for theme and settings)
CREATE TABLE trainer_preferences (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT NOT NULL,
    theme ENUM('light', 'dark') DEFAULT 'light',
    dashboard_layout JSON,
    notification_settings JSON,
    timezone VARCHAR(50) DEFAULT 'UTC',
    language VARCHAR(10) DEFAULT 'en',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_trainer_prefs (trainer_id)
);

-- Trainer Certifications Table
CREATE TABLE trainer_certifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT NOT NULL,
    certification_name VARCHAR(100) NOT NULL,
    issuing_organization VARCHAR(100) NOT NULL,
    issue_date DATE NOT NULL,
    expiry_date DATE,
    certification_number VARCHAR(50),
    status ENUM('active', 'expired', 'pending_renewal') DEFAULT 'active',
    file_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_trainer_status (trainer_id, status)
);

-- Trainer Specializations Table
CREATE TABLE trainer_specializations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT NOT NULL,
    specialization VARCHAR(100) NOT NULL,
    experience_years INT DEFAULT 0,
    description TEXT,
    is_primary BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_trainer_specialization (trainer_id, specialization)
);

-- Trainer Members Relationship Table
CREATE TABLE trainer_members (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT NOT NULL,
    member_id INT NOT NULL,
    assigned_date DATE NOT NULL,
    status ENUM('active', 'inactive', 'transferred', 'completed') DEFAULT 'active',
    specialization_focus VARCHAR(100),
    goals TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_trainer_member (trainer_id, member_id),
    INDEX idx_trainer_status (trainer_id, status),
    INDEX idx_member_status (member_id, status)
);

-- =====================================================
-- SCHEDULING TABLES
-- =====================================================

-- Trainer Schedule Table
CREATE TABLE trainer_schedule (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT NOT NULL,
    member_id INT,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    session_type ENUM('personal_training', 'group_class', 'consultation', 'assessment', 'follow_up', 'other') NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    location VARCHAR(100),
    status ENUM('scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show') DEFAULT 'scheduled',
    recurring_pattern ENUM('none', 'daily', 'weekly', 'monthly') DEFAULT 'none',
    recurring_end_date DATE,
    session_rate DECIMAL(8,2),
    notes TEXT,
    reminder_sent BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_trainer_date (trainer_id, start_time),
    INDEX idx_member_date (member_id, start_time),
    INDEX idx_status_date (status, start_time)
);

-- =====================================================
-- WORKOUT MANAGEMENT TABLES
-- =====================================================

-- Workout Plans Table
CREATE TABLE workout_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT NOT NULL,
    member_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    plan_type ENUM('strength', 'cardio', 'flexibility', 'sports_specific', 'rehabilitation', 'general_fitness') NOT NULL,
    difficulty_level ENUM('beginner', 'intermediate', 'advanced') NOT NULL,
    duration_weeks INT NOT NULL,
    sessions_per_week INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,
    status ENUM('draft', 'active', 'completed', 'paused', 'cancelled') DEFAULT 'draft',
    goals TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_trainer_member (trainer_id, member_id),
    INDEX idx_status_date (status, start_date)
);

-- Workout Exercises Table
CREATE TABLE workout_exercises (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workout_plan_id INT NOT NULL,
    day_number INT NOT NULL,
    exercise_order INT NOT NULL,
    exercise_name VARCHAR(200) NOT NULL,
    exercise_category ENUM('chest', 'back', 'shoulders', 'arms', 'legs', 'core', 'cardio', 'full_body') NOT NULL,
    sets INT,
    reps VARCHAR(50), -- Can be "12-15" or "AMRAP" etc.
    weight VARCHAR(50), -- Can be "bodyweight", "50kg", "50-60kg" etc.
    rest_time_seconds INT,
    tempo VARCHAR(20), -- e.g., "2-1-2-1"
    rpe_target INT, -- Rate of Perceived Exertion (1-10)
    instructions TEXT,
    video_url VARCHAR(500),
    image_url VARCHAR(500),
    equipment_needed VARCHAR(200),
    muscle_groups JSON,
    is_superset BOOLEAN DEFAULT FALSE,
    superset_group INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workout_plan_id) REFERENCES workout_plans(id) ON DELETE CASCADE,
    INDEX idx_workout_day (workout_plan_id, day_number),
    INDEX idx_exercise_category (exercise_category)
);

-- Workout Sessions Table (actual completed workouts)
CREATE TABLE workout_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workout_plan_id INT NOT NULL,
    member_id INT NOT NULL,
    trainer_id INT NOT NULL,
    session_date DATE NOT NULL,
    day_number INT NOT NULL,
    start_time TIME,
    end_time TIME,
    total_duration_minutes INT,
    calories_burned INT,
    average_heart_rate INT,
    max_heart_rate INT,
    rpe_overall INT, -- Overall session RPE
    notes TEXT,
    status ENUM('planned', 'in_progress', 'completed', 'skipped') DEFAULT 'planned',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (workout_plan_id) REFERENCES workout_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_member_date (member_id, session_date),
    INDEX idx_trainer_date (trainer_id, session_date)
);

-- =====================================================
-- NUTRITION MANAGEMENT TABLES
-- =====================================================

-- Nutrition Plans Table
CREATE TABLE nutrition_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT NOT NULL,
    member_id INT NOT NULL,
    plan_name VARCHAR(200) NOT NULL,
    goal ENUM('weight_loss', 'muscle_gain', 'maintenance', 'performance', 'health') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,
    daily_calories DECIMAL(6,2) NOT NULL,
    daily_protein DECIMAL(5,2) NOT NULL,
    daily_carbs DECIMAL(5,2) NOT NULL,
    daily_fats DECIMAL(5,2) NOT NULL,
    daily_fiber DECIMAL(5,2),
    daily_water_liters DECIMAL(3,1) DEFAULT 2.5,
    meal_timing JSON, -- Store meal times
    dietary_restrictions TEXT,
    allergies TEXT,
    preferences TEXT,
    status ENUM('draft', 'active', 'completed', 'paused', 'cancelled') DEFAULT 'draft',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_trainer_member (trainer_id, member_id),
    INDEX idx_status_date (status, start_date)
);

-- Meal Templates Table
CREATE TABLE meal_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT,
    name VARCHAR(200) NOT NULL,
    category ENUM('breakfast', 'lunch', 'dinner', 'snack', 'pre_workout', 'post_workout') NOT NULL,
    goal ENUM('weight_loss', 'muscle_gain', 'maintenance', 'performance') NOT NULL,
    difficulty ENUM('easy', 'medium', 'hard') DEFAULT 'medium',
    prep_time INT DEFAULT 15, -- minutes
    cook_time INT DEFAULT 0, -- minutes
    servings INT DEFAULT 1,
    ingredients TEXT NOT NULL,
    instructions TEXT NOT NULL,
    calories DECIMAL(6,2) NOT NULL,
    protein DECIMAL(5,2) NOT NULL,
    carbs DECIMAL(5,2) NOT NULL,
    fats DECIMAL(5,2) NOT NULL,
    fiber DECIMAL(5,2) DEFAULT 0,
    sugar DECIMAL(5,2) DEFAULT 0,
    sodium DECIMAL(6,2) DEFAULT 0,
    image_url VARCHAR(500),
    tags JSON, -- ["high-protein", "vegetarian", etc.]
    is_vegetarian BOOLEAN DEFAULT FALSE,
    is_vegan BOOLEAN DEFAULT FALSE,
    is_gluten_free BOOLEAN DEFAULT FALSE,
    is_dairy_free BOOLEAN DEFAULT FALSE,
    is_public BOOLEAN DEFAULT FALSE, -- Can other trainers use this template
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_trainer_category (trainer_id, category),
    INDEX idx_goal_category (goal, category),
    INDEX idx_public_category (is_public, category)
);

-- Meal Plans Table (specific meals assigned to nutrition plans)
CREATE TABLE meal_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nutrition_plan_id INT NOT NULL,
    meal_template_id INT,
    day_of_week ENUM('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday') NOT NULL,
    meal_type ENUM('breakfast', 'lunch', 'dinner', 'snack', 'pre_workout', 'post_workout') NOT NULL,
    meal_time TIME,
    custom_meal_name VARCHAR(200),
    custom_ingredients TEXT,
    custom_instructions TEXT,
    servings DECIMAL(3,1) DEFAULT 1,
    calories DECIMAL(6,2),
    protein DECIMAL(5,2),
    carbs DECIMAL(5,2),
    fats DECIMAL(5,2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (nutrition_plan_id) REFERENCES nutrition_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (meal_template_id) REFERENCES meal_templates(id) ON DELETE SET NULL,
    INDEX idx_plan_day (nutrition_plan_id, day_of_week),
    INDEX idx_meal_type (meal_type)
);

-- Nutrition Tracking Table (member's actual food intake)
CREATE TABLE nutrition_tracking (
    id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    nutrition_plan_id INT,
    tracking_date DATE NOT NULL,
    meal_type ENUM('breakfast', 'lunch', 'dinner', 'snack', 'pre_workout', 'post_workout') NOT NULL,
    meal_id INT, -- Reference to meal_plans if following a plan
    custom_meal_name VARCHAR(200),
    food_items JSON, -- Array of food items with quantities
    total_calories DECIMAL(6,2),
    total_protein DECIMAL(5,2),
    total_carbs DECIMAL(5,2),
    total_fats DECIMAL(5,2),
    total_fiber DECIMAL(5,2),
    water_consumed DECIMAL(4,2) DEFAULT 0, -- liters
    notes TEXT,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (nutrition_plan_id) REFERENCES nutrition_plans(id) ON DELETE SET NULL,
    FOREIGN KEY (meal_id) REFERENCES meal_plans(id) ON DELETE SET NULL,
    INDEX idx_member_date (member_id, tracking_date),
    INDEX idx_plan_date (nutrition_plan_id, tracking_date)
);

-- =====================================================
-- ASSESSMENT TABLES
-- =====================================================

-- Assessment Types Table
CREATE TABLE assessment_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    category ENUM('fitness', 'body_composition', 'cardiovascular', 'strength', 'flexibility', 'nutrition', 'lifestyle') NOT NULL,
    description TEXT,
    fields JSON NOT NULL, -- Store field definitions as JSON
    scoring_system JSON, -- How to score/interpret results
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_active (is_active)
);

-- Assessments Table
CREATE TABLE assessments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    trainer_id INT NOT NULL,
    assessment_type_id INT NOT NULL,
    assessment_date DATE NOT NULL,
    results JSON NOT NULL, -- Store assessment results as JSON
    score DECIMAL(5,2), -- Overall score if applicable
    performance_level ENUM('poor', 'below_average', 'average', 'above_average', 'excellent'),
    notes TEXT,
    recommendations TEXT,
    next_assessment_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assessment_type_id) REFERENCES assessment_types(id) ON DELETE CASCADE,
    INDEX idx_member_date (member_id, assessment_date),
    INDEX idx_trainer_date (trainer_id, assessment_date),
    INDEX idx_assessment_type (assessment_type_id)
);

-- Body Measurements Table
CREATE TABLE body_measurements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    trainer_id INT NOT NULL,
    measurement_date DATE NOT NULL,
    weight DECIMAL(5,2),
    height DECIMAL(5,2),
    body_fat_percentage DECIMAL(4,2),
    muscle_mass DECIMAL(5,2),
    bone_mass DECIMAL(4,2),
    water_percentage DECIMAL(4,2),
    visceral_fat_level INT,
    metabolic_age INT,
    bmi DECIMAL(4,2),
    waist_circumference DECIMAL(5,2),
    chest_circumference DECIMAL(5,2),
    arm_circumference DECIMAL(5,2),
    thigh_circumference DECIMAL(5,2),
    neck_circumference DECIMAL(5,2),
    hip_circumference DECIMAL(5,2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_member_date (member_id, measurement_date)
);

-- Fitness Tests Table
CREATE TABLE fitness_tests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    trainer_id INT NOT NULL,
    test_date DATE NOT NULL,
    test_type ENUM('cardio', 'strength', 'flexibility', 'endurance', 'balance', 'agility', 'power') NOT NULL,
    test_name VARCHAR(100) NOT NULL,
    result_value DECIMAL(10,2),
    result_unit VARCHAR(20),
    performance_level ENUM('poor', 'below_average', 'average', 'above_average', 'excellent') DEFAULT 'average',
    percentile DECIMAL(4,1), -- Percentile ranking
    improvement_from_last DECIMAL(10,2), -- Improvement since last test
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_member_test (member_id, test_type, test_date),
    INDEX idx_trainer_test (trainer_id, test_type, test_date)
);

-- Progress Photos Table
CREATE TABLE progress_photos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    trainer_id INT NOT NULL,
    photo_date DATE NOT NULL,
    photo_type ENUM('front', 'side', 'back', 'before', 'after', 'progress') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT,
    notes TEXT,
    is_visible_to_member BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_member_date (member_id, photo_date),
    INDEX idx_photo_type (photo_type)
);

-- Assessment Goals Table
CREATE TABLE assessment_goals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    trainer_id INT NOT NULL,
    assessment_type_id INT NOT NULL,
    goal_metric VARCHAR(50) NOT NULL,
    current_value DECIMAL(10,2),
    target_value DECIMAL(10,2),
    target_date DATE,
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    status ENUM('active', 'achieved', 'modified', 'cancelled') DEFAULT 'active',
    progress_percentage DECIMAL(5,2) DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assessment_type_id) REFERENCES assessment_types(id) ON DELETE CASCADE,
    INDEX idx_member_status (member_id, status),
    INDEX idx_target_date (target_date)
);

-- Assessment Reports Table
CREATE TABLE assessment_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    trainer_id INT NOT NULL,
    report_type ENUM('progress', 'comprehensive', 'goal_tracking', 'comparison', 'summary') NOT NULL,
    report_title VARCHAR(200) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    report_data JSON NOT NULL,
    summary TEXT,
    recommendations TEXT,
    file_path VARCHAR(255),
    is_shared_with_member BOOLEAN DEFAULT FALSE,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_member_date (member_id, generated_at),
    INDEX idx_trainer_type (trainer_id, report_type)
);

-- =====================================================
-- ACTIVITY TRACKING TABLE
-- =====================================================

-- Trainer Activity Table (for comprehensive activity logging)
CREATE TABLE trainer_activity (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT NOT NULL,
    member_id INT,
    activity_type ENUM('session', 'workout', 'nutrition', 'assessment', 'progress', 'member', 'communication', 'goal', 'report') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    metadata JSON, -- Store additional activity-specific data
    importance ENUM('low', 'medium', 'high') DEFAULT 'medium',
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_trainer_date (trainer_id, created_at),
    INDEX idx_member_date (member_id, created_at),
    INDEX idx_activity_type (activity_type),
    INDEX idx_status_date (status, created_at)
);

-- =====================================================
-- ADDITIONAL UTILITY TABLES
-- =====================================================

-- Exercise Library Table
CREATE TABLE exercise_library (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    category ENUM('chest', 'back', 'shoulders', 'arms', 'legs', 'core', 'cardio', 'full_body', 'stretching') NOT NULL,
    subcategory VARCHAR(100),
    equipment_needed VARCHAR(200),
    difficulty_level ENUM('beginner', 'intermediate', 'advanced') NOT NULL,
    muscle_groups JSON,
    instructions TEXT,
    tips TEXT,
    video_url VARCHAR(500),
    image_url VARCHAR(500),
    calories_per_minute DECIMAL(4,2),
    is_compound BOOLEAN DEFAULT FALSE,
    created_by INT, -- trainer who created it
    is_public BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_category (category),
    INDEX idx_difficulty (difficulty_level),
    INDEX idx_equipment (equipment_needed),
    INDEX idx_public (is_public)
);

-- Member Goals Table
CREATE TABLE member_goals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    trainer_id INT NOT NULL,
    goal_type ENUM('weight_loss', 'muscle_gain', 'strength', 'endurance', 'flexibility', 'health', 'performance', 'other') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    target_value DECIMAL(10,2),
    target_unit VARCHAR(20),
    current_value DECIMAL(10,2),
    start_date DATE NOT NULL,
    target_date DATE,
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    status ENUM('active', 'achieved', 'paused', 'cancelled') DEFAULT 'active',
    progress_percentage DECIMAL(5,2) DEFAULT 0,
    milestones JSON, -- Array of milestone objects
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_member_status (member_id, status),
    INDEX idx_trainer_status (trainer_id, status),
    INDEX idx_target_date (target_date)
);

-- Communication Log Table
CREATE TABLE communication_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT NOT NULL,
    member_id INT NOT NULL,
    communication_type ENUM('email', 'sms', 'call', 'in_person', 'app_message', 'note') NOT NULL,
    subject VARCHAR(200),
    message TEXT,
    direction ENUM('outgoing', 'incoming') NOT NULL,
    status ENUM('sent', 'delivered', 'read', 'replied') DEFAULT 'sent',
    scheduled_for DATETIME,
    sent_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_trainer_date (trainer_id, created_at),
    INDEX idx_member_date (member_id, created_at),
    INDEX idx_type_status (communication_type, status)
);

-- =====================================================
-- TRIGGERS FOR AUTOMATIC CALCULATIONS
-- =====================================================

-- Trigger to calculate BMI automatically
DELIMITER //
CREATE TRIGGER calculate_bmi_insert 
BEFORE INSERT ON body_measurements
FOR EACH ROW
BEGIN
    IF NEW.weight IS NOT NULL AND NEW.height IS NOT NULL THEN
        SET NEW.bmi = NEW.weight / POWER(NEW.height / 100, 2);
    END IF;
END//

CREATE TRIGGER calculate_bmi_update 
BEFORE UPDATE ON body_measurements
FOR EACH ROW
BEGIN
    IF NEW.weight IS NOT NULL AND NEW.height IS NOT NULL THEN
        SET NEW.bmi = NEW.weight / POWER(NEW.height / 100, 2);
    END IF;
END//

-- Trigger to update goal progress automatically
CREATE TRIGGER update_goal_progress
BEFORE UPDATE ON member_goals
FOR EACH ROW
BEGIN
    IF NEW.target_value IS NOT NULL AND NEW.current_value IS NOT NULL AND NEW.target_value != 0 THEN
        SET NEW.progress_percentage = LEAST(100, (NEW.current_value / NEW.target_value) * 100);
        
        -- Mark as achieved if 100% progress
        IF NEW.progress_percentage >= 100 AND NEW.status = 'active' THEN
            SET NEW.status = 'achieved';
        END IF;
    END IF;
END//

DELIMITER ;

-- =====================================================
-- INDEXES FOR PERFORMANCE OPTIMIZATION
-- =====================================================

-- Additional composite indexes for common queries
CREATE INDEX idx_trainer_members_active ON trainer_members(trainer_id, status, assigned_date);
CREATE INDEX idx_schedule_trainer_week ON trainer_schedule(trainer_id, start_time, status);
CREATE INDEX idx_workout_plans_active ON workout_plans(trainer_id, status, start_date);
CREATE INDEX idx_nutrition_plans_active ON nutrition_plans(trainer_id, status, start_date);
CREATE INDEX idx_assessments_recent ON assessments(trainer_id, assessment_date DESC);
CREATE INDEX idx_activity_recent ON trainer_activity(trainer_id, created_at DESC);

-- =====================================================
-- SAMPLE DATA INSERTION
-- =====================================================

-- Insert default assessment types
INSERT INTO assessment_types (name, category, description, fields) VALUES
('Initial Fitness Assessment', 'fitness', 'Comprehensive initial fitness evaluation', '[
    {"name": "resting_heart_rate", "label": "Resting Heart Rate (bpm)", "type": "number", "required": true, "min": 40, "max": 120},
    {"name": "blood_pressure_systolic", "label": "Blood Pressure Systolic (mmHg)", "type": "number", "required": true, "min": 80, "max": 200},
    {"name": "blood_pressure_diastolic", "label": "Blood Pressure Diastolic (mmHg)", "type": "number", "required": true, "min": 40, "max": 120},
    {"name": "flexibility_sit_reach", "label": "Sit and Reach (cm)", "type": "number", "required": false},
    {"name": "push_ups", "label": "Push-ups (count)", "type": "number", "required": false, "min": 0},
    {"name": "plank_time", "label": "Plank Hold (seconds)", "type": "number", "required": false, "min": 0},
    {"name": "fitness_goals", "label": "Primary Fitness Goals", "type": "select", "required": true, "options": [
        {"value": "weight_loss", "label": "Weight Loss"},
        {"value": "muscle_gain", "label": "Muscle Gain"},
        {"value": "endurance", "label": "Endurance"},
        {"value": "strength", "label": "Strength"},
        {"value": "general_fitness", "label": "General Fitness"}
    ]}
]'),

('Body Composition Analysis', 'body_composition', 'Detailed body composition measurements', '[
    {"name": "weight", "label": "Weight (kg)", "type": "number", "required": true, "min": 30, "max": 300, "step": 0.1},
    {"name": "height", "label": "Height (cm)", "type": "number", "required": true, "min": 100, "max": 250},
    {"name": "body_fat_percentage", "label": "Body Fat Percentage (%)", "type": "number", "required": false, "min": 3, "max": 50, "step": 0.1},
    {"name": "muscle_mass", "label": "Muscle Mass (kg)", "type": "number", "required": false, "min": 10, "max": 100, "step": 0.1},
    {"name": "waist_circumference", "label": "Waist Circumference (cm)", "type": "number", "required": false, "min": 50, "max": 200, "step": 0.1}
]'),

('Cardiovascular Assessment', 'cardiovascular', 'Heart health and endurance evaluation', '[
    {"name": "resting_heart_rate", "label": "Resting Heart Rate (bpm)", "type": "number", "required": true, "min": 40, "max": 120},
    {"name": "max_heart_rate", "label": "Maximum Heart Rate (bpm)", "type": "number", "required": false, "min": 120, "max": 220},
    {"name": "vo2_max", "label": "VO2 Max (ml/kg/min)", "type": "number", "required": false, "min": 10, "max": 80, "step": 0.1},
    {"name": "mile_run_time", "label": "1-Mile Run Time (minutes)", "type": "number", "required": false, "min": 4, "max": 30, "step": 0.1}
]');

-- Insert sample meal templates
INSERT INTO meal_templates (trainer_id, name, category, goal, difficulty, prep_time, cook_time, servings, ingredients, instructions, calories, protein, carbs, fats, fiber, tags, is_vegetarian) VALUES
(NULL, 'High-Protein Breakfast Bowl', 'breakfast', 'muscle_gain', 'easy', 10, 5, 1, 
'Greek yogurt (200g), Rolled oats (40g), Banana (1 medium), Almonds (20g), Honey (1 tbsp), Chia seeds (1 tbsp)',
'1. Add oats to bowl with hot water, let soak 5 minutes\n2. Add Greek yogurt on top\n3. Slice banana and add to bowl\n4. Sprinkle almonds and chia seeds\n5. Drizzle with honey',
450, 35, 25, 18, 8, '["high_protein", "vegetarian", "post_workout"]', TRUE),

(NULL, 'Lean Chicken Power Salad', 'lunch', 'weight_loss', 'medium', 15, 20, 1,
'Chicken breast (150g), Mixed greens (100g), Cherry tomatoes (100g), Cucumber (80g), Red onion (30g), Olive oil (1 tbsp), Lemon juice (2 tbsp), Feta cheese (30g)',
'1. Season and grill chicken breast 6-8 minutes each side\n2. Let chicken rest 5 minutes, then slice\n3. Combine vegetables in bowl\n4. Whisk olive oil and lemon juice\n5. Top with chicken and feta, drizzle dressing',
380, 42, 15, 12, 9, '["high_protein", "low_carb", "gluten_free"]', FALSE);

-- Insert sample exercises
INSERT INTO exercise_library (name, category, equipment_needed, difficulty_level, muscle_groups, instructions, is_compound) VALUES
('Push-ups', 'chest', 'None (bodyweight)', 'beginner', '["chest", "shoulders", "triceps", "core"]', 
'1. Start in plank position with hands shoulder-width apart\n2. Lower body until chest nearly touches floor\n3. Push back up to starting position\n4. Keep core tight throughout movement', TRUE),

('Squats', 'legs', 'None (bodyweight)', 'beginner', '["quadriceps", "glutes", "hamstrings", "core"]',
'1. Stand with feet shoulder-width apart\n2. Lower body by bending knees and hips\n3. Keep chest up and knees tracking over toes\n4. Lower until thighs parallel to floor\n5. Drive through heels to return to start', TRUE),

('Deadlifts', 'back', 'Barbell', 'intermediate', '["hamstrings", "glutes", "lower_back", "traps", "forearms"]',
'1. Stand with feet hip-width apart, bar over mid-foot\n2. Bend at hips and knees to grip bar\n3. Keep chest up, shoulders back\n4. Drive through heels to lift bar\n5. Stand tall, then reverse movement', TRUE);

-- =====================================================
-- VIEWS FOR COMMON QUERIES
-- =====================================================

-- View for trainer dashboard summary
CREATE VIEW trainer_dashboard_summary AS
SELECT 
    t.id as trainer_id,
    t.first_name,
    t.last_name,
    COUNT(DISTINCT tm.member_id) as total_members,
    COUNT(DISTINCT CASE WHEN ts.start_time >= CURDATE() AND ts.start_time < CURDATE() + INTERVAL 1 DAY THEN ts.id END) as todays_sessions,
    COUNT(DISTINCT CASE WHEN ts.start_time >= CURDATE() AND ts.start_time < CURDATE() + INTERVAL 7 DAY THEN ts.id END) as weekly_sessions,
    COUNT(DISTINCT CASE WHEN np.status = 'active' THEN np.id END) as active_nutrition_plans,
    COUNT(DISTINCT CASE WHEN wp.status = 'active' THEN wp.id END) as active_workout_plans
FROM users t
LEFT JOIN trainer_members tm ON t.id = tm.trainer_id AND tm.status = 'active'
LEFT JOIN trainer_schedule ts ON t.id = ts.trainer_id
LEFT JOIN nutrition_plans np ON t.id = np.trainer_id
LEFT JOIN workout_plans wp ON t.id = wp.trainer_id
WHERE t.role = 'trainer'
GROUP BY t.id, t.first_name, t.last_name;

-- View for member progress overview
CREATE VIEW member_progress_overview AS
SELECT 
    m.id as member_id,
    m.first_name,
    m.last_name,
    tm.trainer_id,
    COUNT(DISTINCT a.id) as total_assessments,
    MAX(a.assessment_date) as last_assessment_date,
    COUNT(DISTINCT ws.id) as completed_workouts,
    COUNT(DISTINCT nt.id) as nutrition_logs,
    AVG(CASE WHEN bm.weight IS NOT NULL THEN bm.weight END) as avg_weight,
    COUNT(DISTINCT mg.id) as active_goals
FROM users m
JOIN trainer_members tm ON m.id = tm.member_id AND tm.status = 'active'
LEFT JOIN assessments a ON m.id = a.member_id
LEFT JOIN workout_sessions ws ON m.id = ws.member_id AND ws.status = 'completed'
LEFT JOIN nutrition_tracking nt ON m.id = nt.member_id
LEFT JOIN body_measurements bm ON m.id = bm.member_id
LEFT JOIN member_goals mg ON m.id = mg.member_id AND mg.status = 'active'
WHERE m.role = 'member'
GROUP BY m.id, m.first_name, m.last_name, tm.trainer_id;

-- =====================================================
-- STORED PROCEDURES
-- =====================================================

-- Procedure to get trainer activity summary
DELIMITER //
CREATE PROCEDURE GetTrainerActivitySummary(IN trainer_id INT, IN days_back INT)
BEGIN
    SELECT 
        activity_type,
        COUNT(*) as activity_count,
        DATE(created_at) as activity_date
    FROM trainer_activity 
    WHERE trainer_id = trainer_id 
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL days_back DAY)
    GROUP BY activity_type, DATE(created_at)
    ORDER BY activity_date DESC, activity_type;
END//

-- Procedure to calculate member progress
CREATE PROCEDURE CalculateMemberProgress(IN member_id INT, IN trainer_id INT)
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE goal_id INT;
    DECLARE current_val, target_val DECIMAL(10,2);
    DECLARE progress_pct DECIMAL(5,2);
    
    DECLARE goal_cursor CURSOR FOR 
        SELECT id, current_value, target_value 
        FROM member_goals 
        WHERE member_id = member_id AND trainer_id = trainer_id AND status = 'active';
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN goal_cursor;
    
    goal_loop: LOOP
        FETCH goal_cursor INTO goal_id, current_val, target_val;
        IF done THEN
            LEAVE goal_loop;
        END IF;
        
        IF target_val > 0 THEN
            SET progress_pct = LEAST(100, (current_val / target_val) * 100);
            
            UPDATE member_goals 
            SET progress_percentage = progress_pct,
                status = CASE WHEN progress_pct >= 100 THEN 'achieved' ELSE status END
            WHERE id = goal_id;
        END IF;
    END LOOP;
    
    CLOSE goal_cursor;
END//

DELIMITER ;

-- =====================================================
-- FINAL NOTES
-- =====================================================

-- This schema provides:
-- 1. Complete trainer dashboard functionality
-- 2. Member management and tracking
-- 3. Workout plan creation and monitoring
-- 4. Nutrition plan management
-- 5. Comprehensive assessment system
-- 6. Activity logging and reporting
-- 7. Progress tracking and analytics
-- 8. Communication logging
-- 9. Goal setting and monitoring
-- 10. Flexible meal template system

-- Remember to:
-- 1. Run this script on your EliteFit database
-- 2. Ensure proper user permissions
-- 3. Set up regular backups
-- 4. Monitor performance and add indexes as needed
-- 5. Update the schema as new features are added

-- For production use, consider:
-- 1. Partitioning large tables by date
-- 2. Archiving old data
-- 3. Setting up replication for high availability
-- 4. Implementing proper security measures
-- 5. Regular maintenance and optimization
