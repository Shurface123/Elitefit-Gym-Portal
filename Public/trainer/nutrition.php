<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Trainer role to access this page
requireRole('Trainer');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];
$specialization = $_SESSION['specialization'] ?? 'General Fitness';

// Connect to database
require_once __DIR__ . '/../db_connect.php';
$conn = connectDB();

// Include theme preference helper
require_once 'trainer-theme-helper.php';
$theme = getThemePreference($conn, $userId);

// Create nutrition tables if they don't exist
createNutritionTables($conn);

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_plan':
                $result = createNutritionPlan($conn, $userId, $_POST);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
            case 'update_plan':
                $result = updateNutritionPlan($conn, $_POST);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
            case 'delete_plan':
                $result = deleteNutritionPlan($conn, $_POST['plan_id']);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
            case 'add_meal':
                $result = addMealToPlan($conn, $_POST);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
            case 'update_meal':
                $result = updateMeal($conn, $_POST);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
            case 'delete_meal':
                $result = deleteMeal($conn, $_POST['meal_id']);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
            case 'copy_template':
                $result = copyMealTemplate($conn, $userId, $_POST);
                $message = $result['message'];
                $messageType = $result['type'];
                break;
        }
    }
}

// Get current action
$action = $_GET['action'] ?? 'list';
$planId = $_GET['plan_id'] ?? null;
$memberId = $_GET['member_id'] ?? null;

// Get data based on action
$nutritionPlans = [];
$currentPlan = null;
$members = [];
$mealTemplates = [];
$nutritionStats = [];

if ($action === 'list' || $action === 'create' || $action === 'edit') {
    $nutritionPlans = getNutritionPlans($conn, $userId);
    $members = getTrainerMembers($conn, $userId);
    $nutritionStats = getNutritionStats($conn, $userId);
}

if ($action === 'edit' && $planId) {
    $currentPlan = getNutritionPlan($conn, $planId);
    $planMeals = getPlanMeals($conn, $planId);
}

if ($action === 'templates') {
    $mealTemplates = getMealTemplates($conn);
}

// Helper functions
function createNutritionTables($conn) {
    try {
        // Meal templates table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS meal_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                category ENUM('breakfast', 'lunch', 'dinner', 'snack') NOT NULL,
                goal ENUM('weight_loss', 'muscle_gain', 'maintenance', 'performance') NOT NULL,
                difficulty ENUM('easy', 'medium', 'hard') DEFAULT 'easy',
                prep_time INT DEFAULT 15,
                cook_time INT DEFAULT 0,
                servings INT DEFAULT 1,
                ingredients TEXT NOT NULL,
                instructions TEXT NOT NULL,
                calories INT NOT NULL,
                protein DECIMAL(5,2) NOT NULL,
                carbs DECIMAL(5,2) NOT NULL,
                fats DECIMAL(5,2) NOT NULL,
                fiber DECIMAL(4,2) DEFAULT 0,
                sugar DECIMAL(4,2) DEFAULT 0,
                sodium DECIMAL(6,2) DEFAULT 0,
                image_url VARCHAR(500),
                tags JSON,
                is_vegetarian BOOLEAN DEFAULT FALSE,
                is_vegan BOOLEAN DEFAULT FALSE,
                is_gluten_free BOOLEAN DEFAULT FALSE,
                is_dairy_free BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        // Nutrition tracking table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS nutrition_tracking (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                nutrition_plan_id INT NOT NULL,
                tracking_date DATE NOT NULL,
                meal_type ENUM('breakfast', 'lunch', 'dinner', 'snack') NOT NULL,
                meal_id INT,
                custom_meal_name VARCHAR(255),
                calories_consumed INT,
                protein_consumed DECIMAL(5,2),
                carbs_consumed DECIMAL(5,2),
                fats_consumed DECIMAL(5,2),
                water_consumed DECIMAL(4,2) DEFAULT 0,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_member_date (member_id, tracking_date),
                FOREIGN KEY (nutrition_plan_id) REFERENCES nutrition_plans(id) ON DELETE CASCADE
            )
        ");

        // Nutrition goals table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS nutrition_goals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                goal_type ENUM('weight_loss', 'muscle_gain', 'maintenance', 'performance') NOT NULL,
                target_weight DECIMAL(5,2),
                target_body_fat DECIMAL(4,2),
                target_date DATE,
                weekly_weight_change DECIMAL(3,2),
                activity_level ENUM('sedentary', 'light', 'moderate', 'active', 'very_active') DEFAULT 'moderate',
                dietary_restrictions TEXT,
                allergies TEXT,
                preferences TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_member_goal (member_id)
            )
        ");

        // Insert default meal templates
        insertDefaultMealTemplates($conn);

    } catch (PDOException $e) {
        error_log("Error creating nutrition tables: " . $e->getMessage());
    }
}

function insertDefaultMealTemplates($conn) {
    $templates = [
        // Breakfast Templates
        [
            'name' => 'Protein Power Oatmeal',
            'category' => 'breakfast',
            'goal' => 'muscle_gain',
            'difficulty' => 'easy',
            'prep_time' => 5,
            'cook_time' => 5,
            'servings' => 1,
            'ingredients' => '1/2 cup rolled oats, 1 scoop whey protein powder, 1 banana, 1 tbsp almond butter, 1 cup almond milk, 1 tsp honey, 1/4 cup blueberries',
            'instructions' => '1. Cook oats with almond milk. 2. Stir in protein powder. 3. Top with sliced banana, almond butter, honey, and blueberries.',
            'calories' => 485,
            'protein' => 32.5,
            'carbs' => 58.2,
            'fats' => 12.8,
            'fiber' => 8.5,
            'tags' => '["high-protein", "post-workout", "filling"]',
            'is_vegetarian' => true
        ],
        [
            'name' => 'Avocado Toast with Eggs',
            'category' => 'breakfast',
            'goal' => 'maintenance',
            'difficulty' => 'easy',
            'prep_time' => 10,
            'cook_time' => 5,
            'servings' => 1,
            'ingredients' => '2 slices whole grain bread, 1 ripe avocado, 2 eggs, 1 tsp olive oil, salt, pepper, red pepper flakes, lime juice',
            'instructions' => '1. Toast bread. 2. Mash avocado with lime juice, salt, and pepper. 3. Fry eggs. 4. Spread avocado on toast, top with eggs and seasonings.',
            'calories' => 420,
            'protein' => 18.5,
            'carbs' => 32.0,
            'fats' => 26.5,
            'fiber' => 12.0,
            'tags' => '["healthy-fats", "balanced", "satisfying"]',
            'is_vegetarian' => true
        ],
        // Lunch Templates
        [
            'name' => 'Grilled Chicken Quinoa Bowl',
            'category' => 'lunch',
            'goal' => 'muscle_gain',
            'difficulty' => 'medium',
            'prep_time' => 15,
            'cook_time' => 20,
            'servings' => 1,
            'ingredients' => '150g chicken breast, 1/2 cup quinoa, 1 cup mixed vegetables, 2 tbsp olive oil, 1/4 avocado, lemon juice, herbs',
            'instructions' => '1. Cook quinoa. 2. Grill seasoned chicken. 3. Steam vegetables. 4. Combine in bowl with avocado and dressing.',
            'calories' => 520,
            'protein' => 42.0,
            'carbs' => 35.5,
            'fats' => 22.8,
            'fiber' => 8.2,
            'tags' => '["high-protein", "complete-meal", "balanced"]'
        ],
        [
            'name' => 'Mediterranean Salad',
            'category' => 'lunch',
            'goal' => 'weight_loss',
            'difficulty' => 'easy',
            'prep_time' => 10,
            'cook_time' => 0,
            'servings' => 1,
            'ingredients' => '2 cups mixed greens, 1/2 cucumber, 1/4 cup cherry tomatoes, 2 tbsp feta cheese, 10 olives, 1 tbsp olive oil, lemon juice',
            'instructions' => '1. Chop vegetables. 2. Combine all ingredients. 3. Dress with olive oil and lemon juice.',
            'calories' => 285,
            'protein' => 8.5,
            'carbs' => 12.0,
            'fats' => 24.5,
            'fiber' => 6.8,
            'tags' => '["low-calorie", "fresh", "mediterranean"]',
            'is_vegetarian' => true
        ],
        // Dinner Templates
        [
            'name' => 'Salmon with Sweet Potato',
            'category' => 'dinner',
            'goal' => 'performance',
            'difficulty' => 'medium',
            'prep_time' => 10,
            'cook_time' => 25,
            'servings' => 1,
            'ingredients' => '150g salmon fillet, 1 medium sweet potato, 1 cup broccoli, 1 tbsp olive oil, herbs, lemon',
            'instructions' => '1. Roast sweet potato. 2. Pan-sear salmon. 3. Steam broccoli. 4. Serve with lemon and herbs.',
            'calories' => 465,
            'protein' => 35.2,
            'carbs' => 28.5,
            'fats' => 22.8,
            'fiber' => 6.5,
            'tags' => '["omega-3", "anti-inflammatory", "nutrient-dense"]'
        ],
        // Snack Templates
        [
            'name' => 'Greek Yogurt Berry Bowl',
            'category' => 'snack',
            'goal' => 'maintenance',
            'difficulty' => 'easy',
            'prep_time' => 3,
            'cook_time' => 0,
            'servings' => 1,
            'ingredients' => '1 cup Greek yogurt, 1/2 cup mixed berries, 1 tbsp honey, 2 tbsp granola',
            'instructions' => '1. Place yogurt in bowl. 2. Top with berries, honey, and granola.',
            'calories' => 245,
            'protein' => 18.5,
            'carbs' => 32.0,
            'fats' => 5.2,
            'fiber' => 4.8,
            'tags' => '["high-protein", "probiotics", "antioxidants"]',
            'is_vegetarian' => true
        ]
    ];

    foreach ($templates as $template) {
        try {
            $stmt = $conn->prepare("
                INSERT IGNORE INTO meal_templates 
                (name, category, goal, difficulty, prep_time, cook_time, servings, ingredients, instructions, 
                 calories, protein, carbs, fats, fiber, tags, is_vegetarian, is_vegan, is_gluten_free, is_dairy_free)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $template['name'], $template['category'], $template['goal'], $template['difficulty'],
                $template['prep_time'], $template['cook_time'], $template['servings'],
                $template['ingredients'], $template['instructions'],
                $template['calories'], $template['protein'], $template['carbs'], $template['fats'],
                $template['fiber'] ?? 0, $template['tags'] ?? '[]',
                $template['is_vegetarian'] ?? false, $template['is_vegan'] ?? false,
                $template['is_gluten_free'] ?? false, $template['is_dairy_free'] ?? false
            ]);
        } catch (PDOException $e) {
            // Template might already exist, continue
        }
    }
}

function getNutritionPlans($conn, $trainerId) {
    try {
        $stmt = $conn->prepare("
            SELECT np.*, 
                   u.name as member_name,
                   u.profile_image,
                   u.email as member_email,
                   (SELECT COUNT(*) FROM meal_plans WHERE nutrition_plan_id = np.id) as meal_count,
                   (SELECT COUNT(*) FROM nutrition_tracking WHERE nutrition_plan_id = np.id AND tracking_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as recent_logs
            FROM nutrition_plans np
            JOIN users u ON np.member_id = u.id
            WHERE np.trainer_id = ?
            ORDER BY np.updated_at DESC
        ");
        $stmt->execute([$trainerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting nutrition plans: " . $e->getMessage());
        return [];
    }
}

function getNutritionStats($conn, $trainerId) {
    try {
        $stats = [];
        
        // Total active plans
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM nutrition_plans WHERE trainer_id = ? AND status = 'active'");
        $stmt->execute([$trainerId]);
        $stats['active_plans'] = $stmt->fetch()['count'];
        
        // Members with nutrition plans
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT member_id) as count FROM nutrition_plans WHERE trainer_id = ? AND status = 'active'");
        $stmt->execute([$trainerId]);
        $stats['members_with_plans'] = $stmt->fetch()['count'];
        
        // Recent meal logs
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM nutrition_tracking nt
            JOIN nutrition_plans np ON nt.nutrition_plan_id = np.id
            WHERE np.trainer_id = ? AND nt.tracking_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute([$trainerId]);
        $stats['recent_logs'] = $stmt->fetch()['count'];
        
        // Average plan adherence
        $stmt = $conn->prepare("
            SELECT AVG(adherence_score) as avg_adherence
            FROM (
                SELECT 
                    np.id,
                    (COUNT(nt.id) / (DATEDIFF(NOW(), np.created_at) * 4)) * 100 as adherence_score
                FROM nutrition_plans np
                LEFT JOIN nutrition_tracking nt ON np.id = nt.nutrition_plan_id
                WHERE np.trainer_id = ? AND np.status = 'active'
                GROUP BY np.id
            ) as adherence_data
        ");
        $stmt->execute([$trainerId]);
        $result = $stmt->fetch();
        $stats['avg_adherence'] = round($result['avg_adherence'] ?? 0, 1);
        
        return $stats;
    } catch (PDOException $e) {
        error_log("Error getting nutrition stats: " . $e->getMessage());
        return ['active_plans' => 0, 'members_with_plans' => 0, 'recent_logs' => 0, 'avg_adherence' => 0];
    }
}

function getTrainerMembers($conn, $trainerId) {
    try {
        $stmt = $conn->prepare("
            SELECT u.id, u.name, u.email, u.profile_image,
                   tm.specialization_focus,
                   ng.goal_type, ng.target_weight, ng.activity_level,
                   (SELECT COUNT(*) FROM nutrition_plans WHERE member_id = u.id AND trainer_id = ? AND status = 'active') as has_nutrition_plan
            FROM trainer_members tm
            JOIN users u ON tm.member_id = u.id
            LEFT JOIN nutrition_goals ng ON u.id = ng.member_id
            WHERE tm.trainer_id = ? AND tm.status = 'active'
            ORDER BY u.name ASC
        ");
        $stmt->execute([$trainerId, $trainerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting trainer members: " . $e->getMessage());
        return [];
    }
}

function getMealTemplates($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM meal_templates 
            ORDER BY category, goal, name
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting meal templates: " . $e->getMessage());
        return [];
    }
}

function createNutritionPlan($conn, $trainerId, $data) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO nutrition_plans 
            (trainer_id, member_id, plan_name, goal, daily_calories, daily_protein, daily_carbs, daily_fats, daily_water_liters, meal_timing, restrictions, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $mealTiming = json_encode([
            'breakfast' => $data['breakfast_time'] ?? '08:00',
            'lunch' => $data['lunch_time'] ?? '12:00',
            'dinner' => $data['dinner_time'] ?? '18:00',
            'snack' => $data['snack_time'] ?? '15:00'
        ]);
        
        $stmt->execute([
            $trainerId,
            $data['member_id'],
            $data['plan_name'],
            $data['goal'],
            $data['daily_calories'],
            $data['daily_protein'],
            $data['daily_carbs'],
            $data['daily_fats'],
            $data['daily_water_liters'] ?? 2.5,
            $mealTiming,
            $data['restrictions'] ?? '',
            $data['notes'] ?? ''
        ]);
        
        // Log activity
        logTrainerActivity($conn, $trainerId, $data['member_id'], 'nutrition', 'Created nutrition plan', "Created nutrition plan: {$data['plan_name']}");
        
        return ['type' => 'success', 'message' => 'Nutrition plan created successfully!'];
    } catch (PDOException $e) {
        error_log("Error creating nutrition plan: " . $e->getMessage());
        return ['type' => 'error', 'message' => 'Error creating nutrition plan. Please try again.'];
    }
}

function logTrainerActivity($conn, $trainerId, $memberId, $type, $title, $description) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO trainer_activity (trainer_id, member_id, activity_type, title, description)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$trainerId, $memberId, $type, $title, $description]);
    } catch (PDOException $e) {
        error_log("Error logging activity: " . $e->getMessage());
    }
}

// Format helper functions
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

function formatTime($time) {
    return date('g:i A', strtotime($time));
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nutrition Management - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #ff6b35;
            --primary-dark: #e55a2b;
            --secondary: #2c3e50;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #3498db;
            --bg-dark: #0a0a0a;
            --card-dark: #1a1a1a;
            --text-dark: #ecf0f1;
            --border-dark: #333333;
            --bg-light: #f8f9fa;
            --card-light: #ffffff;
            --text-light: #2c3e50;
            --border-light: #dee2e6;
        }

        [data-theme="dark"] {
            --bg: var(--bg-dark);
            --card-bg: var(--card-dark);
            --text: var(--text-dark);
            --border: var(--border-dark);
        }

        [data-theme="light"] {
            --bg: var(--bg-light);
            --card-bg: var(--card-light);
            --text: var(--text-light);
            --border: var(--border-light);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            transition: all 0.3s ease;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 0.75rem;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.875rem;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .btn-success {
            background: var(--success);
        }

        .btn-success:hover {
            background: #219a52;
        }

        .btn-danger {
            background: var(--danger);
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
            background: rgba(255, 107, 53, 0.1);
            color: var(--primary);
        }

        .stat-content h3 {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text);
            opacity: 0.8;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 0.5rem;
        }

        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--border);
        }

        .tab {
            padding: 1rem 1.5rem;
            background: transparent;
            border: none;
            color: var(--text);
            cursor: pointer;
            border-radius: 0.5rem 0.5rem 0 0;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab.active {
            background: var(--primary);
            color: white;
        }

        .tab:hover:not(.active) {
            background: rgba(255, 107, 53, 0.1);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .card {
            background: var(--card-bg);
            border-radius: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid var(--border);
            margin-bottom: 2rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            background: rgba(255, 107, 53, 0.02);
        }

        .card-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-content {
            padding: 1.5rem;
        }

        .nutrition-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .nutrition-plan-card {
            background: var(--card-bg);
            border-radius: 1rem;
            padding: 1.5rem;
            border: 1px solid var(--border);
            transition: all 0.3s ease;
            position: relative;
        }

        .nutrition-plan-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .plan-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .plan-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .plan-goal {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .goal-weight_loss {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        .goal-muscle_gain {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .goal-maintenance {
            background: rgba(52, 152, 219, 0.1);
            color: var(--info);
        }

        .goal-performance {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .member-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

        .member-avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
        }

        .nutrition-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .nutrition-stat {
            text-align: center;
            padding: 0.75rem;
            background: rgba(255, 107, 53, 0.05);
            border-radius: 0.5rem;
        }

        .nutrition-stat-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--primary);
        }

        .nutrition-stat-label {
            font-size: 0.75rem;
            color: var(--text);
            opacity: 0.7;
            text-transform: uppercase;
        }

        .plan-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            background: var(--card-bg);
            color: var(--text);
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }

        .meal-template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .meal-template-card {
            background: var(--card-bg);
            border-radius: 1rem;
            overflow: hidden;
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .meal-template-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .meal-image {
            height: 200px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
        }

        .meal-content {
            padding: 1.5rem;
        }

        .meal-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .meal-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--text);
            opacity: 0.7;
        }

        .meal-nutrition {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .meal-nutrition-item {
            text-align: center;
            padding: 0.5rem;
            background: rgba(255, 107, 53, 0.05);
            border-radius: 0.5rem;
        }

        .meal-nutrition-value {
            font-weight: 600;
            color: var(--primary);
        }

        .meal-nutrition-label {
            font-size: 0.75rem;
            opacity: 0.7;
        }

        .meal-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .meal-tag {
            padding: 0.25rem 0.5rem;
            background: rgba(255, 107, 53, 0.1);
            color: var(--primary);
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .dietary-badges {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .dietary-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-vegetarian {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .badge-vegan {
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
        }

        .badge-gluten-free {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .badge-dairy-free {
            background: rgba(52, 152, 219, 0.1);
            color: var(--info);
        }

        .message {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .message.success {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
            border: 1px solid rgba(39, 174, 96, 0.2);
        }

        .message.error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border: 1px solid rgba(231, 76, 60, 0.2);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text);
            opacity: 0.7;
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .filter-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 250px;
        }

        .search-box input {
            padding-left: 2.5rem;
        }

        .search-box i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text);
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .nutrition-grid,
            .meal-template-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                flex-wrap: wrap;
            }

            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div>
                <h1><i class="fas fa-apple-alt"></i> Nutrition Management</h1>
                <p>Create and manage comprehensive nutrition plans for your members</p>
            </div>
            <div class="header-actions">
                <a href="dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <a href="?action=create" class="btn">
                    <i class="fas fa-plus"></i> Create Plan
                </a>
            </div>
        </div>

        <!-- Message Display -->
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType; ?>">
                <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <?php if ($action === 'list'): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Active Plans</h3>
                        <div class="stat-value"><?php echo $nutritionStats['active_plans']; ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Members with Plans</h3>
                        <div class="stat-value"><?php echo $nutritionStats['members_with_plans']; ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Recent Logs</h3>
                        <div class="stat-value"><?php echo $nutritionStats['recent_logs']; ?></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-percentage"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Avg Adherence</h3>
                        <div class="stat-value"><?php echo $nutritionStats['avg_adherence']; ?>%</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Navigation Tabs -->
        <div class="tabs">
            <a href="?action=list" class="tab <?php echo $action === 'list' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> My Plans
            </a>
            <a href="?action=create" class="tab <?php echo $action === 'create' ? 'active' : ''; ?>">
                <i class="fas fa-plus"></i> Create Plan
            </a>
            <a href="?action=templates" class="tab <?php echo $action === 'templates' ? 'active' : ''; ?>">
                <i class="fas fa-utensils"></i> Meal Templates
            </a>
        </div>

        <!-- Tab Content -->
        
        <!-- Nutrition Plans List -->
        <?php if ($action === 'list'): ?>
            <div class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-clipboard-list"></i> Nutrition Plans</h2>
                        <div class="filter-bar">
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" class="form-control" placeholder="Search plans..." id="searchPlans">
                            </div>
                            <select class="form-control" id="filterGoal" style="width: auto;">
                                <option value="">All Goals</option>
                                <option value="weight_loss">Weight Loss</option>
                                <option value="muscle_gain">Muscle Gain</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="performance">Performance</option>
                            </select>
                        </div>
                    </div>
                    <div class="card-content">
                        <?php if (empty($nutritionPlans)): ?>
                            <div class="empty-state">
                                <i class="fas fa-apple-alt"></i>
                                <h3>No nutrition plans yet</h3>
                                <p>Create your first nutrition plan to get started</p>
                                <a href="?action=create" class="btn">
                                    <i class="fas fa-plus"></i> Create Plan
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="nutrition-grid" id="plansGrid">
                                <?php foreach ($nutritionPlans as $plan): ?>
                                    <div class="nutrition-plan-card" data-goal="<?php echo $plan['goal']; ?>" data-name="<?php echo strtolower($plan['plan_name'] . ' ' . $plan['member_name']); ?>">
                                        <div class="plan-header">
                                            <div>
                                                <h3 class="plan-title"><?php echo htmlspecialchars($plan['plan_name']); ?></h3>
                                                <span class="plan-goal goal-<?php echo $plan['goal']; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $plan['goal'])); ?>
                                                </span>
                                            </div>
                                            <div class="plan-actions">
                                                <a href="?action=edit&plan_id=<?php echo $plan['id']; ?>" class="btn btn-outline" style="padding: 0.5rem;">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            </div>
                                        </div>
                                        
                                        <div class="member-info">
                                            <?php if (!empty($plan['profile_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($plan['profile_image']); ?>" alt="Profile" class="member-avatar">
                                            <?php else: ?>
                                                <div class="member-avatar-placeholder">
                                                    <?php echo strtoupper(substr($plan['member_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <h4><?php echo htmlspecialchars($plan['member_name']); ?></h4>
                                                <p style="font-size: 0.875rem; opacity: 0.7;"><?php echo htmlspecialchars($plan['member_email']); ?></p>
                                            </div>
                                        </div>
                                        
                                        <div class="nutrition-stats">
                                            <div class="nutrition-stat">
                                                <div class="nutrition-stat-value"><?php echo $plan['daily_calories']; ?></div>
                                                <div class="nutrition-stat-label">Calories</div>
                                            </div>
                                            <div class="nutrition-stat">
                                                <div class="nutrition-stat-value"><?php echo $plan['daily_protein']; ?>g</div>
                                                <div class="nutrition-stat-label">Protein</div>
                                            </div>
                                            <div class="nutrition-stat">
                                                <div class="nutrition-stat-value"><?php echo $plan['meal_count']; ?></div>
                                                <div class="nutrition-stat-label">Meals</div>
                                            </div>
                                            <div class="nutrition-stat">
                                                <div class="nutrition-stat-value"><?php echo $plan['recent_logs']; ?></div>
                                                <div class="nutrition-stat-label">Recent Logs</div>
                                            </div>
                                        </div>
                                        
                                        <div class="plan-actions">
                                            <a href="?action=edit&plan_id=<?php echo $plan['id']; ?>" class="btn btn-outline">
                                                <i class="fas fa-edit"></i> Edit Plan
                                            </a>
                                            <a href="nutrition-tracking.php?plan_id=<?php echo $plan['id']; ?>" class="btn">
                                                <i class="fas fa-chart-line"></i> View Progress
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Create/Edit Plan -->
        <?php if ($action === 'create' || $action === 'edit'): ?>
            <div class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i class="fas <?php echo $action === 'create' ? 'fa-plus' : 'fa-edit'; ?>"></i>
                            <?php echo $action === 'create' ? 'Create' : 'Edit'; ?> Nutrition Plan
                        </h2>
                    </div>
                    <div class="card-content">
                        <form method="POST" id="nutritionPlanForm">
                            <input type="hidden" name="action" value="<?php echo $action === 'create' ? 'create_plan' : 'update_plan'; ?>">
                            <?php if ($action === 'edit'): ?>
                                <input type="hidden" name="plan_id" value="<?php echo $planId; ?>">
                            <?php endif; ?>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="plan_name">Plan Name *</label>
                                    <input type="text" id="plan_name" name="plan_name" class="form-control" required
                                           value="<?php echo $currentPlan ? htmlspecialchars($currentPlan['plan_name']) : ''; ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="member_id">Member *</label>
                                    <select id="member_id" name="member_id" class="form-control" required <?php echo $action === 'edit' ? 'disabled' : ''; ?>>
                                        <option value="">Select Member</option>
                                        <?php foreach ($members as $member): ?>
                                            <option value="<?php echo $member['id']; ?>" 
                                                    <?php echo ($currentPlan && $currentPlan['member_id'] == $member['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($member['name']); ?>
                                                <?php if ($member['has_nutrition_plan'] > 0 && $action === 'create'): ?>
                                                    (Has active plan)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="goal">Goal *</label>
                                    <select id="goal" name="goal" class="form-control" required>
                                        <option value="">Select Goal</option>
                                        <option value="weight_loss" <?php echo ($currentPlan && $currentPlan['goal'] === 'weight_loss') ? 'selected' : ''; ?>>Weight Loss</option>
                                        <option value="muscle_gain" <?php echo ($currentPlan && $currentPlan['goal'] === 'muscle_gain') ? 'selected' : ''; ?>>Muscle Gain</option>
                                        <option value="maintenance" <?php echo ($currentPlan && $currentPlan['goal'] === 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                                        <option value="performance" <?php echo ($currentPlan && $currentPlan['goal'] === 'performance') ? 'selected' : ''; ?>>Performance</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="daily_calories">Daily Calories *</label>
                                    <input type="number" id="daily_calories" name="daily_calories" class="form-control" required min="1000" max="5000"
                                           value="<?php echo $currentPlan ? $currentPlan['daily_calories'] : ''; ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="daily_protein">Daily Protein (g) *</label>
                                    <input type="number" id="daily_protein" name="daily_protein" class="form-control" required min="50" max="300" step="0.1"
                                           value="<?php echo $currentPlan ? $currentPlan['daily_protein'] : ''; ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="daily_carbs">Daily Carbs (g) *</label>
                                    <input type="number" id="daily_carbs" name="daily_carbs" class="form-control" required min="50" max="500" step="0.1"
                                           value="<?php echo $currentPlan ? $currentPlan['daily_carbs'] : ''; ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="daily_fats">Daily Fats (g) *</label>
                                    <input type="number" id="daily_fats" name="daily_fats" class="form-control" required min="20" max="200" step="0.1"
                                           value="<?php echo $currentPlan ? $currentPlan['daily_fats'] : ''; ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="daily_water_liters">Daily Water (L)</label>
                                    <input type="number" id="daily_water_liters" name="daily_water_liters" class="form-control" min="1" max="5" step="0.1"
                                           value="<?php echo $currentPlan ? $currentPlan['daily_water_liters'] : '2.5'; ?>">
                                </div>
                            </div>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="breakfast_time">Breakfast Time</label>
                                    <input type="time" id="breakfast_time" name="breakfast_time" class="form-control" value="08:00">
                                </div>
                                
                                <div class="form-group">
                                    <label for="lunch_time">Lunch Time</label>
                                    <input type="time" id="lunch_time" name="lunch_time" class="form-control" value="12:00">
                                </div>
                                
                                <div class="form-group">
                                    <label for="dinner_time">Dinner Time</label>
                                    <input type="time" id="dinner_time" name="dinner_time" class="form-control" value="18:00">
                                </div>
                                
                                <div class="form-group">
                                    <label for="snack_time">Snack Time</label>
                                    <input type="time" id="snack_time" name="snack_time" class="form-control" value="15:00">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="restrictions">Dietary Restrictions</label>
                                <textarea id="restrictions" name="restrictions" class="form-control" rows="3" 
                                          placeholder="List any dietary restrictions, allergies, or food preferences..."><?php echo $currentPlan ? htmlspecialchars($currentPlan['restrictions']) : ''; ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea id="notes" name="notes" class="form-control" rows="3" 
                                          placeholder="Additional notes or instructions for the member..."><?php echo $currentPlan ? htmlspecialchars($currentPlan['notes']) : ''; ?></textarea>
                            </div>
                            
                            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                                <a href="?action=list" class="btn btn-outline">Cancel</a>
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save"></i> 
                                    <?php echo $action === 'create' ? 'Create Plan' : 'Update Plan'; ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Meal Templates -->
        <?php if ($action === 'templates'): ?>
            <div class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-utensils"></i> Meal Templates</h2>
                        <div class="filter-bar">
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" class="form-control" placeholder="Search templates..." id="searchTemplates">
                            </div>
                            <select class="form-control" id="filterCategory" style="width: auto;">
                                <option value="">All Categories</option>
                                <option value="breakfast">Breakfast</option>
                                <option value="lunch">Lunch</option>
                                <option value="dinner">Dinner</option>
                                <option value="snack">Snack</option>
                            </select>
                            <select class="form-control" id="filterTemplateGoal" style="width: auto;">
                                <option value="">All Goals</option>
                                <option value="weight_loss">Weight Loss</option>
                                <option value="muscle_gain">Muscle Gain</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="performance">Performance</option>
                            </select>
                        </div>
                    </div>
                    <div class="card-content">
                        <div class="meal-template-grid" id="templatesGrid">
                            <?php foreach ($mealTemplates as $template): ?>
                                <div class="meal-template-card" 
                                     data-category="<?php echo $template['category']; ?>" 
                                     data-goal="<?php echo $template['goal']; ?>"
                                     data-name="<?php echo strtolower($template['name']); ?>">
                                    <div class="meal-image">
                                        <i class="fas <?php 
                                            echo $template['category'] === 'breakfast' ? 'fa-coffee' : 
                                                ($template['category'] === 'lunch' ? 'fa-hamburger' : 
                                                ($template['category'] === 'dinner' ? 'fa-utensils' : 'fa-cookie-bite')); 
                                        ?>"></i>
                                    </div>
                                    
                                    <div class="meal-content">
                                        <h3 class="meal-title"><?php echo htmlspecialchars($template['name']); ?></h3>
                                        
                                        <div class="meal-meta">
                                            <span><i class="fas fa-clock"></i> <?php echo $template['prep_time'] + $template['cook_time']; ?> min</span>
                                            <span><i class="fas fa-users"></i> <?php echo $template['servings']; ?> serving<?php echo $template['servings'] > 1 ? 's' : ''; ?></span>
                                            <span><i class="fas fa-signal"></i> <?php echo ucfirst($template['difficulty']); ?></span>
                                        </div>
                                        
                                        <div class="meal-nutrition">
                                            <div class="meal-nutrition-item">
                                                <div class="meal-nutrition-value"><?php echo $template['calories']; ?></div>
                                                <div class="meal-nutrition-label">Cal</div>
                                            </div>
                                            <div class="meal-nutrition-item">
                                                <div class="meal-nutrition-value"><?php echo $template['protein']; ?>g</div>
                                                <div class="meal-nutrition-label">Protein</div>
                                            </div>
                                            <div class="meal-nutrition-item">
                                                <div class="meal-nutrition-value"><?php echo $template['carbs']; ?>g</div>
                                                <div class="meal-nutrition-label">Carbs</div>
                                            </div>
                                            <div class="meal-nutrition-item">
                                                <div class="meal-nutrition-value"><?php echo $template['fats']; ?>g</div>
                                                <div class="meal-nutrition-label">Fats</div>
                                            </div>
                                        </div>
                                        
                                        <div class="dietary-badges">
                                            <?php if ($template['is_vegetarian']): ?>
                                                <span class="dietary-badge badge-vegetarian">Vegetarian</span>
                                            <?php endif; ?>
                                            <?php if ($template['is_vegan']): ?>
                                                <span class="dietary-badge badge-vegan">Vegan</span>
                                            <?php endif; ?>
                                            <?php if ($template['is_gluten_free']): ?>
                                                <span class="dietary-badge badge-gluten-free">Gluten-Free</span>
                                            <?php endif; ?>
                                            <?php if ($template['is_dairy_free']): ?>
                                                <span class="dietary-badge badge-dairy-free">Dairy-Free</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if (!empty($template['tags'])): ?>
                                            <div class="meal-tags">
                                                <?php 
                                                $tags = json_decode($template['tags'], true);
                                                if ($tags) {
                                                    foreach ($tags as $tag): ?>
                                                        <span class="meal-tag"><?php echo htmlspecialchars($tag); ?></span>
                                                    <?php endforeach;
                                                }
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="plan-actions">
                                            <button class="btn btn-outline" onclick="viewTemplate(<?php echo $template['id']; ?>)">
                                                <i class="fas fa-eye"></i> View Recipe
                                            </button>
                                            <button class="btn" onclick="copyTemplate(<?php echo $template['id']; ?>)">
                                                <i class="fas fa-copy"></i> Use Template
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Template Modal -->
    <div id="templateModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle"></h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Template details will be loaded here -->
            </div>
        </div>
    </div>

    <script>
        // Search and filter functionality
        document.getElementById('searchPlans')?.addEventListener('input', function() {
            filterPlans();
        });
        
        document.getElementById('filterGoal')?.addEventListener('change', function() {
            filterPlans();
        });
        
        document.getElementById('searchTemplates')?.addEventListener('input', function() {
            filterTemplates();
        });
        
        document.getElementById('filterCategory')?.addEventListener('change', function() {
            filterTemplates();
        });
        
        document.getElementById('filterTemplateGoal')?.addEventListener('change', function() {
            filterTemplates();
        });
        
        function filterPlans() {
            const searchTerm = document.getElementById('searchPlans').value.toLowerCase();
            const goalFilter = document.getElementById('filterGoal').value;
            const plans = document.querySelectorAll('#plansGrid .nutrition-plan-card');
            
            plans.forEach(plan => {
                const name = plan.dataset.name;
                const goal = plan.dataset.goal;
                
                const matchesSearch = name.includes(searchTerm);
                const matchesGoal = !goalFilter || goal === goalFilter;
                
                plan.style.display = matchesSearch && matchesGoal ? 'block' : 'none';
            });
        }
        
        function filterTemplates() {
            const searchTerm = document.getElementById('searchTemplates').value.toLowerCase();
            const categoryFilter = document.getElementById('filterCategory').value;
            const goalFilter = document.getElementById('filterTemplateGoal').value;
            const templates = document.querySelectorAll('#templatesGrid .meal-template-card');
            
            templates.forEach(template => {
                const name = template.dataset.name;
                const category = template.dataset.category;
                const goal = template.dataset.goal;
                
                const matchesSearch = name.includes(searchTerm);
                const matchesCategory = !categoryFilter || category === categoryFilter;
                const matchesGoal = !goalFilter || goal === goalFilter;
                
                template.style.display = matchesSearch && matchesCategory && matchesGoal ? 'block' : 'none';
            });
        }
        
        // Template functions
        function viewTemplate(templateId) {
            // Fetch template details and show in modal
            fetch(`get-template.php?id=${templateId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('modalTitle').textContent = data.name;
                    document.getElementById('modalBody').innerHTML = `
                        <div class="template-details">
                            <div class="template-meta">
                                <span><strong>Category:</strong> ${data.category}</span>
                                <span><strong>Goal:</strong> ${data.goal.replace('_', ' ')}</span>
                                <span><strong>Prep Time:</strong> ${data.prep_time} min</span>
                                <span><strong>Cook Time:</strong> ${data.cook_time} min</span>
                                <span><strong>Servings:</strong> ${data.servings}</span>
                            </div>
                            
                            <div class="template-nutrition">
                                <h4>Nutrition (per serving)</h4>
                                <div class="nutrition-grid">
                                    <div>Calories: ${data.calories}</div>
                                    <div>Protein: ${data.protein}g</div>
                                    <div>Carbs: ${data.carbs}g</div>
                                    <div>Fats: ${data.fats}g</div>
                                </div>
                            </div>
                            
                            <div class="template-ingredients">
                                <h4>Ingredients</h4>
                                <p>${data.ingredients}</p>
                            </div>
                            
                            <div class="template-instructions">
                                <h4>Instructions</h4>
                                <p>${data.instructions}</p>
                            </div>
                        </div>
                    `;
                    document.getElementById('templateModal').style.display = 'flex';
                });
        }
        
        function copyTemplate(templateId) {
            // Show form to copy template to a nutrition plan
            const planId = prompt('Enter nutrition plan ID to add this meal to:');
            if (planId) {
                const formData = new FormData();
                formData.append('action', 'copy_template');
                formData.append('template_id', templateId);
                formData.append('plan_id', planId);
                
                fetch('nutrition.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(data => {
                    alert('Template copied successfully!');
                    location.reload();
                });
            }
        }
        
        function closeModal() {
            document.getElementById('templateModal').style.display = 'none';
        }
        
        // Auto-calculate macros based on goal
        document.getElementById('goal')?.addEventListener('change', function() {
            const goal = this.value;
            const caloriesInput = document.getElementById('daily_calories');
            const proteinInput = document.getElementById('daily_protein');
            const carbsInput = document.getElementById('daily_carbs');
            const fatsInput = document.getElementById('daily_fats');
            
            if (goal && caloriesInput.value) {
                const calories = parseInt(caloriesInput.value);
                let protein, carbs, fats;
                
                switch (goal) {
                    case 'weight_loss':
                        protein = Math.round(calories * 0.3 / 4);
                        carbs = Math.round(calories * 0.35 / 4);
                        fats = Math.round(calories * 0.35 / 9);
                        break;
                    case 'muscle_gain':
                        protein = Math.round(calories * 0.25 / 4);
                        carbs = Math.round(calories * 0.45 / 4);
                        fats = Math.round(calories * 0.3 / 9);
                        break;
                    case 'maintenance':
                        protein = Math.round(calories * 0.2 / 4);
                        carbs = Math.round(calories * 0.5 / 4);
                        fats = Math.round(calories * 0.3 / 9);
                        break;
                    case 'performance':
                        protein = Math.round(calories * 0.2 / 4);
                        carbs = Math.round(calories * 0.55 / 4);
                        fats = Math.round(calories * 0.25 / 9);
                        break;
                }
                
                proteinInput.value = protein;
                carbsInput.value = carbs;
                fatsInput.value = fats;
            }
        });
    </script>

    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: var(--card-bg);
            border-radius: 1rem;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            border: 1px solid var(--border);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .template-details h4 {
            margin: 1.5rem 0 0.5rem 0;
            color: var(--primary);
        }
        
        .template-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 1rem;
            background: rgba(255, 107, 53, 0.05);
            border-radius: 0.5rem;
        }
        
        .nutrition-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
    </style>
</body>
</html>
