<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Member role to access this page
requireRole('Member');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];
$userEmail = $_SESSION['email'] ?? '';
$profileImage = $_SESSION['profile_image'] ?? '';

// Connect to database
$conn = connectDB();

// Get user settings with dark theme as default
$settings = [
    'theme' => 'dark', // Changed default to dark
    'measurement_unit' => 'metric'
];

try {
    // Get user settings
    $stmt = $conn->prepare("SELECT theme, measurement_unit FROM member_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userSettings) {
        $settings['theme'] = $userSettings['theme'];
        $settings['measurement_unit'] = $userSettings['measurement_unit'];
    } else {
        // Insert default dark theme setting for new users
        try {
            $stmt = $conn->prepare("INSERT INTO member_settings (user_id, theme, measurement_unit) VALUES (?, ?, ?)");
            $stmt->execute([$userId, 'dark', 'metric']);
        } catch (PDOException $e) {
            // Table might not exist, continue with defaults
        }
    }
} catch (PDOException $e) {
    // Handle error
    $errorMessage = "Error loading settings: " . $e->getMessage();
}

// Get user nutrition data
$nutritionData = [
    'daily_calories' => 2000,
    'protein_target' => 150,
    'carbs_target' => 200,
    'fat_target' => 65,
    'water_target' => 2500,
    'fiber_target' => 30,
    'sugar_target' => 50
];

try {
    // Check if nutrition_settings table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'nutrition_settings'")->rowCount() > 0;
    
    if (!$tableExists) {
        // Create nutrition_settings table
        $conn->exec("
            CREATE TABLE nutrition_settings (
                user_id INT PRIMARY KEY,
                daily_calories INT DEFAULT 2000,
                protein_target INT DEFAULT 150,
                carbs_target INT DEFAULT 200,
                fat_target INT DEFAULT 65,
                water_target INT DEFAULT 2500,
                fiber_target INT DEFAULT 30,
                sugar_target INT DEFAULT 50,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        
        // Insert default settings for the user
        $stmt = $conn->prepare("INSERT INTO nutrition_settings (user_id) VALUES (?)");
        $stmt->execute([$userId]);
    }
    
    // Get user nutrition settings
    $stmt = $conn->prepare("SELECT * FROM nutrition_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userNutrition = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userNutrition) {
        // Manual merging to replace array_merge
        foreach ($userNutrition as $key => $value) {
            if (isset($nutritionData[$key])) {
                $nutritionData[$key] = $value;
            }
        }
    }
} catch (PDOException $e) {
    // Handle error
    $errorMessage = "Error loading nutrition settings: " . $e->getMessage();
}

// Check if meals table exists
try {
    $tableExists = $conn->query("SHOW TABLES LIKE 'meals'")->rowCount() > 0;
    
    if (!$tableExists) {
        // Create meals table with expanded nutritional data
        $conn->exec("
            CREATE TABLE meals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                meal_date DATE NOT NULL,
                meal_type VARCHAR(50) NOT NULL,
                name VARCHAR(255) NOT NULL,
                calories INT NOT NULL,
                protein FLOAT NOT NULL,
                carbs FLOAT NOT NULL,
                fat FLOAT NOT NULL,
                fiber FLOAT DEFAULT 0,
                sugar FLOAT DEFAULT 0,
                sodium FLOAT DEFAULT 0,
                is_favorite BOOLEAN DEFAULT 0,
                meal_image VARCHAR(255),
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX (user_id, meal_date),
                INDEX (is_favorite)
            )
        ");
    } else {
        // Check if new columns exist, add them if they don't
        $result = $conn->query("SHOW COLUMNS FROM meals LIKE 'fiber'");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE meals ADD COLUMN fiber FLOAT DEFAULT 0");
        }
        
        $result = $conn->query("SHOW COLUMNS FROM meals LIKE 'sugar'");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE meals ADD COLUMN sugar FLOAT DEFAULT 0");
        }
        
        $result = $conn->query("SHOW COLUMNS FROM meals LIKE 'sodium'");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE meals ADD COLUMN sodium FLOAT DEFAULT 0");
        }
        
        $result = $conn->query("SHOW COLUMNS FROM meals LIKE 'is_favorite'");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE meals ADD COLUMN is_favorite BOOLEAN DEFAULT 0");
        }
        
        $result = $conn->query("SHOW COLUMNS FROM meals LIKE 'meal_image'");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE meals ADD COLUMN meal_image VARCHAR(255)");
        }
        
        $result = $conn->query("SHOW COLUMNS FROM meals LIKE 'notes'");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE meals ADD COLUMN notes TEXT");
        }
    }
} catch (PDOException $e) {
    // Handle error
    $errorMessage = "Error setting up meals table: " . $e->getMessage();
}

// Check if water_intake table exists
try {
    $tableExists = $conn->query("SHOW TABLES LIKE 'water_intake'")->rowCount() > 0;
    
    if (!$tableExists) {
        // Create water_intake table with timestamps
        $conn->exec("
            CREATE TABLE water_intake (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                intake_date DATE NOT NULL,
                amount INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY (user_id, intake_date)
            )
        ");
    }
} catch (PDOException $e) {
    // Handle error
    $errorMessage = "Error setting up water intake table: " . $e->getMessage();
}

// Check if meal_templates table exists
try {
    $tableExists = $conn->query("SHOW TABLES LIKE 'meal_templates'")->rowCount() > 0;
    
    if (!$tableExists) {
        // Create meal_templates table
        $conn->exec("
            CREATE TABLE meal_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                meal_type VARCHAR(50) NOT NULL,
                calories INT NOT NULL,
                protein FLOAT NOT NULL,
                carbs FLOAT NOT NULL,
                fat FLOAT NOT NULL,
                fiber FLOAT DEFAULT 0,
                sugar FLOAT DEFAULT 0,
                sodium FLOAT DEFAULT 0,
                is_public BOOLEAN DEFAULT 1,
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        
        // Insert some default meal templates
        $defaultMeals = [
            ['Oatmeal with Berries', 'Breakfast', 350, 10, 60, 7, 8, 12, 50, 1, NULL],
            ['Greek Yogurt with Honey', 'Breakfast', 220, 15, 25, 5, 0, 20, 70, 1, NULL],
            ['Chicken Salad', 'Lunch', 450, 35, 20, 25, 5, 3, 300, 1, NULL],
            ['Salmon with Vegetables', 'Dinner', 550, 40, 30, 25, 6, 5, 400, 1, NULL],
            ['Protein Shake', 'Snacks', 200, 25, 10, 5, 1, 5, 100, 1, NULL]
        ];
        
        $stmt = $conn->prepare("
            INSERT INTO meal_templates 
            (name, meal_type, calories, protein, carbs, fat, fiber, sugar, sodium, is_public, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($defaultMeals as $meal) {
            $stmt->execute($meal);
        }
    }
} catch (PDOException $e) {
    // Handle error
    $errorMessage = "Error setting up meal templates: " . $e->getMessage();
}

// Get current date
$currentDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$displayDate = date('F j, Y', strtotime($currentDate));
$previousDate = date('Y-m-d', strtotime($currentDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));

// Get meals for the current date
$meals = [];
$mealTypes = ['Breakfast', 'Lunch', 'Dinner', 'Snacks'];
$dailyTotals = [
    'calories' => 0,
    'protein' => 0,
    'carbs' => 0,
    'fat' => 0,
    'fiber' => 0,
    'sugar' => 0,
    'sodium' => 0
];

try {
    $stmt = $conn->prepare("
        SELECT * FROM meals 
        WHERE user_id = ? AND meal_date = ? 
        ORDER BY meal_type, created_at
    ");
    $stmt->execute([$userId, $currentDate]);
    $mealResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($mealResults as $meal) {
        $meals[$meal['meal_type']][] = $meal;
        $dailyTotals['calories'] += $meal['calories'];
        $dailyTotals['protein'] += $meal['protein'];
        $dailyTotals['carbs'] += $meal['carbs'];
        $dailyTotals['fat'] += $meal['fat'];
        $dailyTotals['fiber'] += $meal['fiber'] ?? 0;
        $dailyTotals['sugar'] += $meal['sugar'] ?? 0;
        $dailyTotals['sodium'] += $meal['sodium'] ?? 0;
    }
} catch (PDOException $e) {
    // Handle error
    $errorMessage = "Error loading meals: " . $e->getMessage();
}

// Get water intake for the current date
$waterIntake = 0;

try {
    $stmt = $conn->prepare("SELECT amount FROM water_intake WHERE user_id = ? AND intake_date = ?");
    $stmt->execute([$userId, $currentDate]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $waterIntake = $result['amount'];
    }
} catch (PDOException $e) {
    // Handle error
    $errorMessage = "Error loading water intake: " . $e->getMessage();
}

// Get favorite meals
$favoriteMeals = [];
try {
    $stmt = $conn->prepare("
        SELECT * FROM meals 
        WHERE user_id = ? AND is_favorite = 1
        ORDER BY meal_type, name
    ");
    $stmt->execute([$userId]);
    $favoriteMeals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
    $errorMessage = "Error loading favorite meals: " . $e->getMessage();
}

// Get meal templates
$mealTemplates = [];
try {
    $stmt = $conn->prepare("
        SELECT * FROM meal_templates 
        WHERE is_public = 1 OR created_by = ?
        ORDER BY meal_type, name
    ");
    $stmt->execute([$userId]);
    $mealTemplates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
    $errorMessage = "Error loading meal templates: " . $e->getMessage();
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_meal'])) {
        // Add meal
        try {
            $mealType = $_POST['meal_type'] ?? '';
            $mealName = $_POST['meal_name'] ?? '';
            $calories = !empty($_POST['calories']) ? (int)$_POST['calories'] : 0;
            $protein = !empty($_POST['protein']) ? (float)$_POST['protein'] : 0;
            $carbs = !empty($_POST['carbs']) ? (float)$_POST['carbs'] : 0;
            $fat = !empty($_POST['fat']) ? (float)$_POST['fat'] : 0;
            $fiber = !empty($_POST['fiber']) ? (float)$_POST['fiber'] : 0;
            $sugar = !empty($_POST['sugar']) ? (float)$_POST['sugar'] : 0;
            $sodium = !empty($_POST['sodium']) ? (float)$_POST['sodium'] : 0;
            $isFavorite = isset($_POST['is_favorite']) ? 1 : 0;
            $notes = $_POST['notes'] ?? '';
            
            $stmt = $conn->prepare("
                INSERT INTO meals (
                    user_id, meal_date, meal_type, name, calories, 
                    protein, carbs, fat, fiber, sugar, sodium, 
                    is_favorite, notes
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId, $currentDate, $mealType, $mealName, $calories,
                $protein, $carbs, $fat, $fiber, $sugar, $sodium,
                $isFavorite, $notes
            ]);
            
            $message = 'Meal added successfully!';
            $messageType = 'success';
            
            // Refresh page to show new meal
            header("Location: nutrition.php?date=$currentDate&success=1");
            exit;
        } catch (PDOException $e) {
            $message = 'Error adding meal: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['update_water'])) {
        // Update water intake
        try {
            $amount = !empty($_POST['water_amount']) ? (int)$_POST['water_amount'] : 0;
            
            // Check if entry exists for this date
            $stmt = $conn->prepare("SELECT id FROM water_intake WHERE user_id = ? AND intake_date = ?");
            $stmt->execute([$userId, $currentDate]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($exists) {
                // Update existing entry
                $stmt = $conn->prepare("UPDATE water_intake SET amount = ? WHERE user_id = ? AND intake_date = ?");
                $stmt->execute([$amount, $userId, $currentDate]);
            } else {
                // Insert new entry
                $stmt = $conn->prepare("INSERT INTO water_intake (user_id, intake_date, amount) VALUES (?, ?, ?)");
                $stmt->execute([$userId, $currentDate, $amount]);
            }
            
            $message = 'Water intake updated successfully!';
            $messageType = 'success';
            $waterIntake = $amount;
            
            // Refresh page
            header("Location: nutrition.php?date=$currentDate&water_updated=1");
            exit;
        } catch (PDOException $e) {
            $message = 'Error updating water intake: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Calculate percentages for progress bars
$caloriesPercentage = 0;
if ($nutritionData['daily_calories'] > 0) {
    $caloriesPercentage = round(($dailyTotals['calories'] / $nutritionData['daily_calories']) * 100);
    if ($caloriesPercentage > 100) {
        $caloriesPercentage = 100;
    }
}

$proteinPercentage = 0;
if ($nutritionData['protein_target'] > 0) {
    $proteinPercentage = round(($dailyTotals['protein'] / $nutritionData['protein_target']) * 100);
    if ($proteinPercentage > 100) {
        $proteinPercentage = 100;
    }
}

$carbsPercentage = 0;
if ($nutritionData['carbs_target'] > 0) {
    $carbsPercentage = round(($dailyTotals['carbs'] / $nutritionData['carbs_target']) * 100);
    if ($carbsPercentage > 100) {
        $carbsPercentage = 100;
    }
}

$fatPercentage = 0;
if ($nutritionData['fat_target'] > 0) {
    $fatPercentage = round(($dailyTotals['fat'] / $nutritionData['fat_target']) * 100);
    if ($fatPercentage > 100) {
        $fatPercentage = 100;
    }
}

$waterPercentage = 0;
if ($nutritionData['water_target'] > 0) {
    $waterPercentage = round(($waterIntake / $nutritionData['water_target']) * 100);
    if ($waterPercentage > 100) {
        $waterPercentage = 100;
    }
}

// Calculate macronutrient percentages for improved chart
$totalMacros = $dailyTotals['protein'] * 4 + $dailyTotals['carbs'] * 4 + $dailyTotals['fat'] * 9;
$proteinMacroPercentage = ($totalMacros > 0) ? round(($dailyTotals['protein'] * 4 / $totalMacros) * 100) : 0;
$carbsMacroPercentage = ($totalMacros > 0) ? round(($dailyTotals['carbs'] * 4 / $totalMacros) * 100) : 0;
$fatMacroPercentage = ($totalMacros > 0) ? round(($dailyTotals['fat'] * 9 / $totalMacros) * 100) : 0;

// Get current theme (default to dark)
$theme = $settings['theme'];
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nutrition Tracker - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.css">
    
    <style>
        :root {
            --primary: #ff6b35;
            --primary-dark: #e55a2b;
            --secondary: #2c3e50;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #3498db;
            --light: #ecf0f1;
            --dark: #2c3e50;
            
            /* Light Theme */
            --bg-light: #f8f9fa;
            --card-light: #ffffff;
            --text-light: #2c3e50;
            --text-secondary-light: #6c757d;
            --border-light: #dee2e6;
            --hover-light: #f1f3f5;
            
            /* Dark Theme */
            --bg-dark: #0f0f0f;
            --card-dark: #1a1a1a;
            --text-dark: #e0e0e0;
            --text-secondary-dark: #adb5bd;
            --border-dark: #2d2d2d;
            --hover-dark: #2a2a2a;
        }

        [data-theme="light"] {
            --bg: var(--bg-light);
            --card-bg: var(--card-light);
            --text: var(--text-light);
            --text-secondary: var(--text-secondary-light);
            --border: var(--border-light);
            --hover: var(--hover-light);
        }

        [data-theme="dark"] {
            --bg: var(--bg-dark);
            --card-bg: var(--card-dark);
            --text: var(--text-dark);
            --text-secondary: var(--text-secondary-dark);
            --border: var(--border-dark);
            --hover: var(--hover-dark);
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

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background: var(--card-bg);
            border-right: 1px solid var(--border);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .sidebar-header i {
            color: var(--primary);
            font-size: 2rem;
        }

        .sidebar-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .sidebar-user {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            overflow: hidden;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-info h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .user-status {
            font-size: 0.875rem;
            color: var(--primary);
            background: rgba(255, 107, 53, 0.1);
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-weight: 500;
        }

        .sidebar-menu {
            list-style: none;
            padding: 1rem 0;
        }

        .sidebar-menu li {
            margin-bottom: 0.5rem;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.875rem 1.5rem;
            color: var(--text);
            text-decoration: none;
            transition: all 0.3s ease;
            border-radius: 0 2rem 2rem 0;
            margin-right: 1rem;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            transform: translateX(0.5rem);
        }

        .sidebar-menu i {
            width: 20px;
            text-align: center;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            transition: all 0.3s ease;
        }

        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 1001;
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.75rem;
            border-radius: 0.5rem;
            cursor: pointer;
            font-size: 1.25rem;
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
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .header p {
            color: var(--text-secondary);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
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
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.875rem;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 107, 53, 0.3);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--border);
            color: var(--text);
        }

        .btn-outline:hover {
            background: var(--hover);
            border-color: var(--primary);
            color: var(--primary);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .date-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: var(--card-bg);
            border-radius: 1rem;
            border: 1px solid var(--border);
        }

        .date-nav-current {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .date-nav-actions {
            display: flex;
            gap: 0.5rem;
        }

        .nutrition-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .summary-item {
            background: var(--card-bg);
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .summary-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .summary-item:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
        }

        .summary-item-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .summary-item-icon {
            width: 50px;
            height: 50px;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.25rem;
        }

        .summary-item-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-item-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text);
        }

        .summary-item-subtitle {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        .calories-icon {
            background: rgba(255, 107, 53, 0.1);
            color: var(--primary);
        }

        .protein-icon {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
        }

        .carbs-icon {
            background: rgba(52, 152, 219, 0.1);
            color: var(--info);
        }

        .fat-icon {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .progress-container {
            margin-top: 1rem;
        }

        .progress-bar {
            height: 8px;
            background: var(--hover);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 0.5rem;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.8s ease;
            position: relative;
        }

        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .progress-fill-calories {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        }

        .progress-fill-protein {
            background: linear-gradient(135deg, var(--success), #1e8449);
        }

        .progress-fill-carbs {
            background: linear-gradient(135deg, var(--info), #2471a3);
        }

        .progress-fill-fat {
            background: linear-gradient(135deg, var(--warning), #d68910);
        }

        .progress-fill-water {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }

        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 0.875rem;
            color: var(--text-secondary);
            font-weight: 500;
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

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            background: rgba(255, 107, 53, 0.02);
        }

        .card-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-header i {
            color: var(--primary);
        }

        .card-content {
            padding: 1.5rem;
        }

        .macro-chart-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 2rem;
        }

        .macro-chart {
            width: 50px;
            height: 50px;
            margin-bottom: 1rem;
            position: relative;
        }

        .macro-legend {
            display: flex;
            gap: 2rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .macro-legend-item {
            display: flex;
            align-items: center;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .macro-legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
            margin-right: 0.75rem;
        }

        .water-tracker {
            background: var(--card-bg);
            border-radius: 1.5rem;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
        }

        .water-tracker-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .water-tracker-title {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .water-tracker-title i {
            color: #3498db;
            font-size: 1.5rem;
        }

        .water-glasses {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1.5rem;
            justify-content: center;
        }

        .water-glass {
            width: 40px;
            height: 50px;
            background: var(--hover);
            border-radius: 0 0 20px 20px;
            position: relative;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid var(--border);
        }

        .water-glass:hover {
            transform: scale(1.1);
        }

        .water-glass.filled {
            background: linear-gradient(135deg, #3498db, #2980b9);
            border-color: #3498db;
        }

        .water-glass::before {
            content: '';
            position: absolute;
            top: -8px;
            left: -2px;
            right: -2px;
            height: 8px;
            border-radius: 8px 8px 0 0;
            background: inherit;
            border: 2px solid;
            border-color: inherit;
            border-bottom: none;
        }

        .meal-section {
            margin-bottom: 2rem;
        }

        .meal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding: 1rem 1.5rem;
            background: var(--card-bg);
            border-radius: 1rem;
            border: 1px solid var(--border);
        }

        .meal-title {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .meal-title i {
            color: var(--primary);
            font-size: 1.5rem;
        }

        .meal-items {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .meal-item {
            background: var(--card-bg);
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            transition: all 0.3s ease;
            border: 1px solid var(--border);
        }

        .meal-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .meal-item-info {
            flex: 1;
        }

        .meal-item-name {
            font-weight: 600;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            font-size: 1.1rem;
        }

        .meal-item-name i.fa-star {
            color: #ffc107;
            margin-left: 0.5rem;
            font-size: 1rem;
        }

        .meal-item-macros {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        .meal-item-macro {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: var(--hover);
            border-radius: 0.5rem;
        }

        .meal-item-macro i {
            color: var(--primary);
            width: 16px;
        }

        .meal-item-actions {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-left: 1rem;
        }

        .btn-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: var(--hover);
            color: var(--text);
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-icon:hover {
            background: var(--primary);
            color: white;
            transform: scale(1.1);
        }

        .meal-empty {
            grid-column: 1 / -1;
            padding: 3rem;
            text-align: center;
            background: var(--card-bg);
            border-radius: 1rem;
            color: var(--text-secondary);
            border: 2px dashed var(--border);
        }

        .meal-empty i {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: 1.5rem;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            transform: translateY(-20px);
            transition: transform 0.3s ease;
            border: 1px solid var(--border);
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
        }

        .modal-header h4 {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.5rem;
            cursor: pointer;
            transition: color 0.3s ease;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-close:hover {
            color: var(--primary);
            background: var(--hover);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            padding: 1.5rem;
            border-top: 1px solid var(--border);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text);
        }

        .form-control {
            width: 100%;
            padding: 0.875rem 1rem;
            font-size: 0.875rem;
            border: 2px solid var(--border);
            border-radius: 0.75rem;
            background: var(--card-bg);
            color: var(--text);
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .form-check {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .form-check-input {
            margin-right: 0.75rem;
            width: 18px;
            height: 18px;
        }

        .alert {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .alert i {
            margin-right: 0.75rem;
            font-size: 1.25rem;
        }

        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
            border: 1px solid rgba(39, 174, 96, 0.2);
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border: 1px solid rgba(231, 76, 60, 0.2);
        }

        .alert .close {
            margin-left: auto;
            background: none;
            border: none;
            color: inherit;
            font-size: 1.25rem;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.3s ease;
        }

        .alert .close:hover {
            opacity: 1;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .mobile-menu-toggle {
                display: block;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .nutrition-summary {
                grid-template-columns: 1fr;
            }

            .meal-items {
                grid-template-columns: 1fr;
            }

            .date-nav {
                flex-direction: column;
                gap: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .macro-legend {
                flex-direction: column;
                gap: 1rem;
            }

            .water-glasses {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-dumbbell"></i>
                <h2>EliteFit Gym</h2>
            </div>
            <div class="sidebar-user">
                <div class="user-avatar">
                    <?php if (!empty($profileImage)): ?>
                        <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile">
                    <?php else: ?>
                        <?php echo strtoupper(substr($userName, 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <h3><?php echo htmlspecialchars($userName); ?></h3>
                    <span class="user-status">Member</span>
                </div>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="workouts.php"><i class="fas fa-dumbbell"></i> <span>My Workouts</span></a></li>
                <li><a href="progress.php"><i class="fas fa-chart-line"></i> <span>Progress</span></a></li>
                <li><a href="appointments.php"><i class="fas fa-calendar-alt"></i> <span>Appointments</span></a></li>
                <li><a href="nutrition.php" class="active"><i class="fas fa-apple-alt"></i> <span>Nutrition</span></a></li>
                <li><a href="trainers.php"><i class="fas fa-user-friends"></i> <span>Trainers</span></a></li>
                <li><a href="profile.php"><i class="fas fa-user"></i> <span>Profile</span></a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Mobile Menu Toggle -->
            <button class="mobile-menu-toggle" id="mobileMenuToggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <!-- Header -->
            <div class="header">
                <div>
                    <h1>Nutrition Tracker</h1>
                    <p>Track your meals and monitor your nutritional intake</p>
                </div>
                <div class="header-actions">
                    <button class="btn" id="addMealBtn">
                        <i class="fas fa-plus"></i> Add Meal
                    </button>
                    <button class="btn btn-outline" id="updateWaterBtn">
                        <i class="fas fa-tint"></i> Water
                    </button>
                </div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <div><?php echo $message; ?></div>
                    <button type="button" class="close">&times;</button>
                </div>
            <?php endif; ?>
            
            <!-- Date Navigation -->
            <div class="date-nav">
                <div class="date-nav-current">
                    <?php echo $displayDate; ?>
                </div>
                <div class="date-nav-actions">
                    <a href="nutrition.php?date=<?php echo $previousDate; ?>" class="btn btn-outline btn-sm">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                    <a href="nutrition.php" class="btn btn-outline btn-sm">
                        <i class="fas fa-calendar-day"></i> Today
                    </a>
                    <a href="nutrition.php?date=<?php echo $nextDate; ?>" class="btn btn-outline btn-sm">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
            
            <!-- Nutrition Summary -->
            <div class="nutrition-summary">
                <div class="summary-item">
                    <div class="summary-item-header">
                        <div class="summary-item-icon calories-icon">
                            <i class="fas fa-fire"></i>
                        </div>
                        <div class="summary-item-title">Calories</div>
                    </div>
                    <div class="summary-item-value"><?php echo $dailyTotals['calories']; ?></div>
                    <div class="summary-item-subtitle">of <?php echo $nutritionData['daily_calories']; ?> kcal</div>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill progress-fill-calories" style="width: <?php echo $caloriesPercentage; ?>%"></div>
                        </div>
                        <div class="progress-text">
                            <span><?php echo $caloriesPercentage; ?>%</span>
                            <span><?php echo $dailyTotals['calories']; ?> / <?php echo $nutritionData['daily_calories']; ?> kcal</span>
                        </div>
                    </div>
                </div>
                
                <div class="summary-item">
                    <div class="summary-item-header">
                        <div class="summary-item-icon protein-icon">
                            <i class="fas fa-drumstick-bite"></i>
                        </div>
                        <div class="summary-item-title">Protein</div>
                    </div>
                    <div class="summary-item-value"><?php echo $dailyTotals['protein']; ?>g</div>
                    <div class="summary-item-subtitle">of <?php echo $nutritionData['protein_target']; ?>g</div>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill progress-fill-protein" style="width: <?php echo $proteinPercentage; ?>%"></div>
                        </div>
                        <div class="progress-text">
                            <span><?php echo $proteinPercentage; ?>%</span>
                            <span><?php echo $dailyTotals['protein']; ?> / <?php echo $nutritionData['protein_target']; ?>g</span>
                        </div>
                    </div>
                </div>
                
                <div class="summary-item">
                    <div class="summary-item-header">
                        <div class="summary-item-icon carbs-icon">
                            <i class="fas fa-bread-slice"></i>
                        </div>
                        <div class="summary-item-title">Carbs</div>
                    </div>
                    <div class="summary-item-value"><?php echo $dailyTotals['carbs']; ?>g</div>
                    <div class="summary-item-subtitle">of <?php echo $nutritionData['carbs_target']; ?>g</div>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill progress-fill-carbs" style="width: <?php echo $carbsPercentage; ?>%"></div>
                        </div>
                        <div class="progress-text">
                            <span><?php echo $carbsPercentage; ?>%</span>
                            <span><?php echo $dailyTotals['carbs']; ?> / <?php echo $nutritionData['carbs_target']; ?>g</span>
                        </div>
                    </div>
                </div>
                
                <div class="summary-item">
                    <div class="summary-item-header">
                        <div class="summary-item-icon fat-icon">
                            <i class="fas fa-cheese"></i>
                        </div>
                        <div class="summary-item-title">Fat</div>
                    </div>
                    <div class="summary-item-value"><?php echo $dailyTotals['fat']; ?>g</div>
                    <div class="summary-item-subtitle">of <?php echo $nutritionData['fat_target']; ?>g</div>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill progress-fill-fat" style="width: <?php echo $fatPercentage; ?>%"></div>
                        </div>
                        <div class="progress-text">
                            <span><?php echo $fatPercentage; ?>%</span>
                            <span><?php echo $dailyTotals['fat']; ?> / <?php echo $nutritionData['fat_target']; ?>g</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Enhanced Macro Distribution Chart -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie"></i> Macronutrient Distribution</h3>
                </div>
                <div class="card-content">
                    <div class="macro-chart-container">
                        <canvas id="macroChart" class="macro-chart"></canvas>
                        <div class="macro-legend">
                            <div class="macro-legend-item">
                                <div class="macro-legend-color" style="background: linear-gradient(135deg, #27ae60, #1e8449);"></div>
                                <span>Protein: <?php echo $proteinMacroPercentage; ?>% (<?php echo $dailyTotals['protein']; ?>g)</span>
                            </div>
                            <div class="macro-legend-item">
                                <div class="macro-legend-color" style="background: linear-gradient(135deg, #3498db, #2471a3);"></div>
                                <span>Carbs: <?php echo $carbsMacroPercentage; ?>% (<?php echo $dailyTotals['carbs']; ?>g)</span>
                            </div>
                            <div class="macro-legend-item">
                                <div class="macro-legend-color" style="background: linear-gradient(135deg, #f39c12, #d68910);"></div>
                                <span>Fat: <?php echo $fatMacroPercentage; ?>% (<?php echo $dailyTotals['fat']; ?>g)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Enhanced Water Tracker -->
            <div class="water-tracker">
                <div class="water-tracker-header">
                    <div class="water-tracker-title">
                        <i class="fas fa-tint"></i> Water Intake
                    </div>
                    <button class="btn btn-outline btn-sm" id="updateWaterBtn2">
                        <i class="fas fa-sync-alt"></i> Update
                    </button>
                </div>
                <div class="progress-container">
                    <div class="progress-bar">
                        <div class="progress-fill progress-fill-water" style="width: <?php echo $waterPercentage; ?>%"></div>
                    </div>
                    <div class="progress-text">
                        <span><?php echo $waterPercentage; ?>%</span>
                        <span><?php echo $waterIntake; ?> / <?php echo $nutritionData['water_target']; ?> ml</span>
                    </div>
                </div>
                <div class="water-glasses">
                    <?php for ($i = 1; $i <= 8; $i++): ?>
                        <div class="water-glass <?php echo $i <= floor($waterIntake / 300) ? 'filled' : ''; ?>" data-glass="<?php echo $i; ?>"></div>
                    <?php endfor; ?>
                </div>
            </div>
            
            <!-- Meal Sections -->
            <?php foreach ($mealTypes as $mealType): ?>
                <div class="meal-section">
                    <div class="meal-header">
                        <div class="meal-title">
                            <?php if ($mealType === 'Breakfast'): ?>
                                <i class="fas fa-coffee"></i>
                            <?php elseif ($mealType === 'Lunch'): ?>
                                <i class="fas fa-utensils"></i>
                            <?php elseif ($mealType === 'Dinner'): ?>
                                <i class="fas fa-utensil-spoon"></i>
                            <?php else: ?>
                                <i class="fas fa-cookie"></i>
                            <?php endif; ?>
                            <?php echo $mealType; ?>
                        </div>
                        <button class="btn btn-sm add-meal-btn" data-meal-type="<?php echo $mealType; ?>">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                    <div class="meal-items">
                        <?php if (isset($meals[$mealType]) && !empty($meals[$mealType])): ?>
                            <?php foreach ($meals[$mealType] as $meal): ?>
                                <div class="meal-item">
                                    <div class="meal-item-info">
                                        <div class="meal-item-name">
                                            <?php echo htmlspecialchars($meal['name']); ?>
                                            <?php if ($meal['is_favorite']): ?>
                                                <i class="fas fa-star"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="meal-item-macros">
                                            <div class="meal-item-macro">
                                                <i class="fas fa-fire"></i> <?php echo $meal['calories']; ?> kcal
                                            </div>
                                            <div class="meal-item-macro">
                                                <i class="fas fa-drumstick-bite"></i> <?php echo $meal['protein']; ?>g
                                            </div>
                                            <div class="meal-item-macro">
                                                <i class="fas fa-bread-slice"></i> <?php echo $meal['carbs']; ?>g
                                            </div>
                                            <div class="meal-item-macro">
                                                <i class="fas fa-cheese"></i> <?php echo $meal['fat']; ?>g
                                            </div>
                                        </div>
                                        <?php if (!empty($meal['notes'])): ?>
                                            <div class="meal-item-notes"><?php echo htmlspecialchars($meal['notes']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="meal-item-actions">
                                        <form action="nutrition.php?date=<?php echo $currentDate; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="toggle_favorite" value="1">
                                            <input type="hidden" name="meal_id" value="<?php echo $meal['id']; ?>">
                                            <input type="hidden" name="is_favorite" value="<?php echo $meal['is_favorite']; ?>">
                                            <button type="submit" class="btn-icon" title="<?php echo $meal['is_favorite'] ? 'Remove from favorites' : 'Add to favorites'; ?>">
                                                <i class="<?php echo $meal['is_favorite'] ? 'fas' : 'far'; ?> fa-star"></i>
                                            </button>
                                        </form>
                                        <form action="nutrition.php?date=<?php echo $currentDate; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="delete_meal" value="1">
                                            <input type="hidden" name="meal_id" value="<?php echo $meal['id']; ?>">
                                            <button type="submit" class="btn-icon" title="Delete meal" onclick="return confirm('Are you sure you want to delete this meal?')">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="meal-empty">
                                <i class="fas fa-utensils"></i>
                                <p>No meals added for <?php echo $mealType; ?> yet.</p>
                                <button class="btn add-meal-btn" data-meal-type="<?php echo $mealType; ?>">
                                    <i class="fas fa-plus"></i> Add Meal
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Add Meal Modal -->
    <div class="modal" id="addMealModal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>Add Meal</h4>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form action="nutrition.php?date=<?php echo $currentDate; ?>" method="post" id="addMealForm">
                    <input type="hidden" name="add_meal" value="1">
                    
                    <div class="form-group">
                        <label for="meal_type">Meal Type</label>
                        <select id="meal_type" name="meal_type" class="form-control" required>
                            <?php foreach ($mealTypes as $type): ?>
                                <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="meal_name">Meal Name</label>
                        <input type="text" id="meal_name" name="meal_name" class="form-control" placeholder="e.g., Grilled Chicken Salad" required>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="calories">Calories (kcal)</label>
                            <input type="number" id="calories" name="calories" class="form-control" min="0" step="1" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="protein">Protein (g)</label>
                            <input type="number" id="protein" name="protein" class="form-control" min="0" step="0.1" required>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="carbs">Carbs (g)</label>
                            <input type="number" id="carbs" name="carbs" class="form-control" min="0" step="0.1" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="fat">Fat (g)</label>
                            <input type="number" id="fat" name="fat" class="form-control" min="0" step="0.1" required>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="fiber">Fiber (g)</label>
                            <input type="number" id="fiber" name="fiber" class="form-control" min="0" step="0.1" value="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="sugar">Sugar (g)</label>
                            <input type="number" id="sugar" name="sugar" class="form-control" min="0" step="0.1" value="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="sodium">Sodium (mg)</label>
                        <input type="number" id="sodium" name="sodium" class="form-control" min="0" step="1" value="0">
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes (optional)</label>
                        <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Add any notes about this meal..."></textarea>
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" id="is_favorite" name="is_favorite" class="form-check-input">
                        <label for="is_favorite" class="form-check-label">Add to favorites</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelAddMeal">Cancel</button>
                <button type="submit" form="addMealForm" class="btn">Add Meal</button>
            </div>
        </div>
    </div>
    
    <!-- Update Water Modal -->
    <div class="modal" id="updateWaterModal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>Update Water Intake</h4>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form action="nutrition.php?date=<?php echo $currentDate; ?>" method="post" id="updateWaterForm">
                    <input type="hidden" name="update_water" value="1">
                    
                    <div class="form-group">
                        <label for="water_amount">Water Amount (ml)</label>
                        <input type="number" id="water_amount" name="water_amount" class="form-control" 
                               min="0" step="50" value="<?php echo $waterIntake; ?>" required>
                        <small class="form-text text-muted">Target: <?php echo $nutritionData['water_target']; ?> ml per day</small>
                    </div>
                    
                    <div class="water-quick-buttons" style="margin-top: 1rem;">
                        <button type="button" class="btn btn-outline btn-sm" onclick="addWater(250)">+250ml</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="addWater(500)">+500ml</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="addWater(750)">+750ml</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="resetWater()">Reset</button>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelUpdateWater">Cancel</button>
                <button type="submit" form="updateWaterForm" class="btn">Update Water</button>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });
        
        // Modal functionality
        const modals = document.querySelectorAll('.modal');
        const modalTriggers = [
            { id: 'addMealBtn', modal: 'addMealModal' },
            { id: 'updateWaterBtn', modal: 'updateWaterModal' },
            { id: 'updateWaterBtn2', modal: 'updateWaterModal' }
        ];
        
        // Add meal buttons
        document.querySelectorAll('.add-meal-btn').forEach(button => {
            button.addEventListener('click', function() {
                const mealType = this.getAttribute('data-meal-type');
                if (mealType) {
                    document.getElementById('meal_type').value = mealType;
                }
                document.getElementById('addMealModal').classList.add('show');
            });
        });
        
        // Open modal
        modalTriggers.forEach(trigger => {
            const element = document.getElementById(trigger.id);
            if (element) {
                element.addEventListener('click', function() {
                    document.getElementById(trigger.modal).classList.add('show');
                });
            }
        });
        
        // Close modal
        document.querySelectorAll('.modal-close, #cancelAddMeal, #cancelUpdateWater').forEach(button => {
            button.addEventListener('click', function() {
                this.closest('.modal').classList.remove('show');
            });
        });
        
        // Close modal when clicking outside
        modals.forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('show');
                }
            });
        });
        
        // Water intake functions
        function addWater(amount) {
            const currentAmount = parseInt(document.getElementById('water_amount').value) || 0;
            document.getElementById('water_amount').value = currentAmount + amount;
        }
        
        function resetWater() {
            document.getElementById('water_amount').value = 0;
        }
        
        // Water glass click functionality
        document.querySelectorAll('.water-glass').forEach(glass => {
            glass.addEventListener('click', function() {
                const glassNumber = parseInt(this.getAttribute('data-glass'));
                const currentWater = <?php echo $waterIntake; ?>;
                const newAmount = glassNumber * 300; // 300ml per glass
                
                // Update water intake via AJAX or form submission
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'nutrition.php?date=<?php echo $currentDate; ?>';
                
                const updateWaterInput = document.createElement('input');
                updateWaterInput.type = 'hidden';
                updateWaterInput.name = 'update_water';
                updateWaterInput.value = '1';
                
                const amountInput = document.createElement('input');
                amountInput.type = 'hidden';
                amountInput.name = 'water_amount';
                amountInput.value = newAmount;
                
                form.appendChild(updateWaterInput);
                form.appendChild(amountInput);
                document.body.appendChild(form);
                form.submit();
            });
        });
        
        // Alert close button
        const alertCloseBtn = document.querySelector('.alert .close');
        if (alertCloseBtn) {
            alertCloseBtn.addEventListener('click', function() {
                this.closest('.alert').style.display = 'none';
            });
        }
        
        // Initialize macro chart with enhanced styling
        const macroChartEl = document.getElementById('macroChart');
        if (macroChartEl) {
            const ctx = macroChartEl.getContext('2d');
            
            const proteinCalories = <?php echo $dailyTotals['protein']; ?> * 4;
            const carbsCalories = <?php echo $dailyTotals['carbs']; ?> * 4;
            const fatCalories = <?php echo $dailyTotals['fat']; ?> * 9;
            const totalCalories = proteinCalories + carbsCalories + fatCalories;
            
            // Only show chart if there's data
            if (totalCalories > 0) {
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Protein', 'Carbs', 'Fat'],
                        datasets: [{
                            data: [proteinCalories, carbsCalories, fatCalories],
                            backgroundColor: [
                                '#27ae60',
                                '#3498db', 
                                '#f39c12'
                            ],
                            borderColor: [
                                '#1e8449',
                                '#2471a3',
                                '#d68910'
                            ],
                            borderWidth: 3,
                            hoverOffset: 10
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '60%',
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                borderColor: '#ff6b35',
                                borderWidth: 1,
                                cornerRadius: 8,
                                displayColors: true,
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed;
                                        const percentage = ((value / totalCalories) * 100).toFixed(1);
                                        const grams = label === 'Protein' ? <?php echo $dailyTotals['protein']; ?> :
                                                     label === 'Carbs' ? <?php echo $dailyTotals['carbs']; ?> :
                                                     <?php echo $dailyTotals['fat']; ?>;
                                        return `${label}: ${grams}g (${percentage}%)`;
                                    }
                                }
                            }
                        },
                        animation: {
                            animateRotate: true,
                            animateScale: true,
                            duration: 1000,
                            easing: 'easeOutQuart'
                        },
                        elements: {
                            arc: {
                                borderWidth: 3,
                                hoverBorderWidth: 4
                            }
                        }
                    }
                });
                
                // Add center text
                const centerText = {
                    id: 'centerText',
                    beforeDatasetsDraw(chart, args, options) {
                        const { ctx, data } = chart;
                        ctx.save();
                        
                        const centerX = chart.getDatasetMeta(0).data[0].x;
                        const centerY = chart.getDatasetMeta(0).data[0].y;
                        
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        
                        // Total calories
                        ctx.font = 'bold 24px Inter';
                        ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--text').trim();
                        ctx.fillText('<?php echo $dailyTotals['calories']; ?>', centerX, centerY - 10);
                        
                        // Label
                        ctx.font = '14px Inter';
                        ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--text-secondary').trim();
                        ctx.fillText('calories', centerX, centerY + 15);
                        
                        ctx.restore();
                    }
                };
                
                Chart.register(centerText);
            } else {
                // Show empty state
                macroChartEl.parentElement.innerHTML = `
                    <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                        <i class="fas fa-chart-pie" style="font-size: 3rem; margin-bottom: 1rem; color: var(--primary);"></i>
                        <p>Add meals to see your macro distribution</p>
                    </div>
                `;
            }
        }
        
        // Auto-hide success messages
        setTimeout(function() {
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                successAlert.style.opacity = '0';
                setTimeout(function() {
                    successAlert.style.display = 'none';
                }, 300);
            }
        }, 5000);
        
        // Form validation
        document.getElementById('addMealForm').addEventListener('submit', function(e) {
            const calories = parseInt(document.getElementById('calories').value);
            const protein = parseFloat(document.getElementById('protein').value);
            const carbs = parseFloat(document.getElementById('carbs').value);
            const fat = parseFloat(document.getElementById('fat').value);
            
            // Basic validation
            if (calories <= 0) {
                alert('Please enter a valid calorie amount');
                e.preventDefault();
                return;
            }
            
            if (protein < 0 || carbs < 0 || fat < 0) {
                alert('Macronutrient values cannot be negative');
                e.preventDefault();
                return;
            }
            
            // Calculate calories from macros
            const calculatedCalories = (protein * 4) + (carbs * 4) + (fat * 9);
            const difference = Math.abs(calories - calculatedCalories);
            
            // Warn if there's a significant difference
            if (difference > 50) {
                const confirm = window.confirm(
                    `The calories you entered (${calories}) don't match the calculated calories from macros (${Math.round(calculatedCalories)}). ` +
                    'Do you want to continue anyway?'
                );
                if (!confirm) {
                    e.preventDefault();
                    return;
                }
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + M to add meal
            if ((e.ctrlKey || e.metaKey) && e.key === 'm') {
                e.preventDefault();
                document.getElementById('addMealModal').classList.add('show');
            }
            
            // Ctrl/Cmd + W to add water
            if ((e.ctrlKey || e.metaKey) && e.key === 'w') {
                e.preventDefault();
                document.getElementById('updateWaterModal').classList.add('show');
            }
            
            // Escape to close modals
            if (e.key === 'Escape') {
                modals.forEach(modal => {
                    modal.classList.remove('show');
                });
            }
        });
        
        // Smooth scrolling for meal sections
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
        
        // Progressive enhancement for better UX
        if ('serviceWorker' in navigator) {
            // Register service worker for offline functionality (if available)
            navigator.serviceWorker.register('/sw.js').catch(() => {
                // Silently fail if service worker is not available
            });
        }
        
        // Add loading states to forms
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                }
            });
        });
        
        // Initialize tooltips for better accessibility
        document.querySelectorAll('[title]').forEach(element => {
            element.addEventListener('mouseenter', function() {
                // Add custom tooltip styling if needed
                this.style.position = 'relative';
            });
        });
        
        // Auto-save functionality for forms (optional enhancement)
        let autoSaveTimeout;
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('input', function() {
                clearTimeout(autoSaveTimeout);
                autoSaveTimeout = setTimeout(() => {
                    // Auto-save draft to localStorage
                    const formData = new FormData(this.closest('form'));
                    const data = Object.fromEntries(formData);
                    localStorage.setItem('nutrition_draft', JSON.stringify(data));
                }, 1000);
            });
        });
        
        // Load draft data on page load
        document.addEventListener('DOMContentLoaded', function() {
            const draft = localStorage.getItem('nutrition_draft');
            if (draft) {
                try {
                    const data = JSON.parse(draft);
                    // Populate form fields with draft data if modal is opened
                    document.getElementById('addMealBtn').addEventListener('click', function() {
                        setTimeout(() => {
                            Object.keys(data).forEach(key => {
                                const field = document.getElementById(key);
                                if (field && field.value === '') {
                                    field.value = data[key];
                                }
                            });
                        }, 100);
                    });
                } catch (e) {
                    // Invalid draft data, remove it
                    localStorage.removeItem('nutrition_draft');
                }
            }
        });
        
        // Clear draft when form is successfully submitted
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('success') === '1') {
                localStorage.removeItem('nutrition_draft');
            }
        });
    </script>
</body>
</html>
