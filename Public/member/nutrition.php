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

// Get user settings
$settings = [
    'theme' => 'light',
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
        // FIX 1: Replace array_merge with manual merging
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

// Check if nutrition_logs table exists for tracking history
try {
    $tableExists = $conn->query("SHOW TABLES LIKE 'nutrition_logs'")->rowCount() > 0;
    
    if (!$tableExists) {
        // Create nutrition_logs table
        $conn->exec("
            CREATE TABLE nutrition_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                log_date DATE NOT NULL,
                total_calories INT NOT NULL,
                total_protein FLOAT NOT NULL,
                total_carbs FLOAT NOT NULL,
                total_fat FLOAT NOT NULL,
                total_water INT NOT NULL,
                weight FLOAT,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY (user_id, log_date)
            )
        ");
    }
} catch (PDOException $e) {
    // Handle error
    $errorMessage = "Error setting up nutrition logs: " . $e->getMessage();
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

// Get nutrition logs for the past week
$nutritionHistory = [];
try {
    $stmt = $conn->prepare("
        SELECT * FROM nutrition_logs 
        WHERE user_id = ? 
        AND log_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND ?
        ORDER BY log_date
    ");
    $stmt->execute([$userId, $currentDate, $currentDate]);
    $nutritionHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
    $errorMessage = "Error loading nutrition history: " . $e->getMessage();
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
            
            // Handle image upload if present
            $mealImage = '';
            if (isset($_FILES['meal_image']) && $_FILES['meal_image']['error'] == 0) {
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                $maxSize = 5 * 1024 * 1024; // 5MB
                
                // FIX 2: Replace in_array with manual check
                $isAllowedType = false;
                foreach ($allowedTypes as $type) {
                    if ($_FILES['meal_image']['type'] === $type) {
                        $isAllowedType = true;
                        break;
                    }
                }
                
                if ($isAllowedType && $_FILES['meal_image']['size'] <= $maxSize) {
                    $uploadDir = '../uploads/meals/';
                    
                    // Create directory if it doesn't exist
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $fileName = uniqid('meal_') . '_' . basename($_FILES['meal_image']['name']);
                    $uploadPath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($_FILES['meal_image']['tmp_name'], $uploadPath)) {
                        $mealImage = '/uploads/meals/' . $fileName;
                    }
                }
            }
            
            $stmt = $conn->prepare("
                INSERT INTO meals (
                    user_id, meal_date, meal_type, name, calories, 
                    protein, carbs, fat, fiber, sugar, sodium, 
                    is_favorite, meal_image, notes
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId, $currentDate, $mealType, $mealName, $calories,
                $protein, $carbs, $fat, $fiber, $sugar, $sodium,
                $isFavorite, $mealImage, $notes
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
    } elseif (isset($_POST['delete_meal'])) {
        // Delete meal
        try {
            $mealId = $_POST['meal_id'] ?? 0;
            
            $stmt = $conn->prepare("DELETE FROM meals WHERE id = ? AND user_id = ?");
            $stmt->execute([$mealId, $userId]);
            
            $message = 'Meal deleted successfully!';
            $messageType = 'success';
            
            // Refresh page
            header("Location: nutrition.php?date=$currentDate&deleted=1");
            exit;
        } catch (PDOException $e) {
            $message = 'Error deleting meal: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['toggle_favorite'])) {
        // Toggle favorite status
        try {
            $mealId = $_POST['meal_id'] ?? 0;
            $isFavorite = $_POST['is_favorite'] ? 0 : 1; // Toggle current value
            
            $stmt = $conn->prepare("UPDATE meals SET is_favorite = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$isFavorite, $mealId, $userId]);
            
            $message = $isFavorite ? 'Added to favorites!' : 'Removed from favorites!';
            $messageType = 'success';
            
            // Refresh page
            header("Location: nutrition.php?date=$currentDate&favorite_updated=1");
            exit;
        } catch (PDOException $e) {
            $message = 'Error updating favorite status: ' . $e->getMessage();
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
    } elseif (isset($_POST['update_nutrition_settings'])) {
        // Update nutrition settings
        try {
            $dailyCalories = !empty($_POST['daily_calories']) ? (int)$_POST['daily_calories'] : 2000;
            $proteinTarget = !empty($_POST['protein_target']) ? (int)$_POST['protein_target'] : 150;
            $carbsTarget = !empty($_POST['carbs_target']) ? (int)$_POST['carbs_target'] : 200;
            $fatTarget = !empty($_POST['fat_target']) ? (int)$_POST['fat_target'] : 65;
            $waterTarget = !empty($_POST['water_target']) ? (int)$_POST['water_target'] : 2500;
            $fiberTarget = !empty($_POST['fiber_target']) ? (int)$_POST['fiber_target'] : 30;
            $sugarTarget = !empty($_POST['sugar_target']) ? (int)$_POST['sugar_target'] : 50;
            
            $stmt = $conn->prepare("
                UPDATE nutrition_settings 
                SET daily_calories = ?, protein_target = ?, carbs_target = ?, 
                    fat_target = ?, water_target = ?, fiber_target = ?, sugar_target = ?
                WHERE user_id = ?
            ");
            
            $stmt->execute([
                $dailyCalories, $proteinTarget, $carbsTarget, 
                $fatTarget, $waterTarget, $fiberTarget, $sugarTarget,
                $userId
            ]);
            
            $message = 'Nutrition settings updated successfully!';
            $messageType = 'success';
            
            // Update nutrition data in memory
            $nutritionData['daily_calories'] = $dailyCalories;
            $nutritionData['protein_target'] = $proteinTarget;
            $nutritionData['carbs_target'] = $carbsTarget;
            $nutritionData['fat_target'] = $fatTarget;
            $nutritionData['water_target'] = $waterTarget;
            $nutritionData['fiber_target'] = $fiberTarget;
            $nutritionData['sugar_target'] = $sugarTarget;
            
            // Refresh page
            header("Location: nutrition.php?date=$currentDate&settings_updated=1");
            exit;
        } catch (PDOException $e) {
            $message = 'Error updating nutrition settings: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['save_template'])) {
        // Save meal as template
        try {
            $mealId = $_POST['meal_id'] ?? 0;
            
            // Get meal data
            $stmt = $conn->prepare("SELECT * FROM meals WHERE id = ? AND user_id = ?");
            $stmt->execute([$mealId, $userId]);
            $meal = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($meal) {
                // Insert as template
                $stmt = $conn->prepare("
                    INSERT INTO meal_templates (
                        name, meal_type, calories, protein, carbs, fat, 
                        fiber, sugar, sodium, is_public, created_by
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $meal['name'], $meal['meal_type'], $meal['calories'], 
                    $meal['protein'], $meal['carbs'], $meal['fat'],
                    $meal['fiber'], $meal['sugar'], $meal['sodium'],
                    0, $userId
                ]);
                
                $message = 'Meal saved as template!';
                $messageType = 'success';
            }
            
            // Refresh page
            header("Location: nutrition.php?date=$currentDate&template_saved=1");
            exit;
        } catch (PDOException $e) {
            $message = 'Error saving template: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['use_template'])) {
        // Use template to add meal
        try {
            $templateId = $_POST['template_id'] ?? 0;
            
            // Get template data
            $stmt = $conn->prepare("SELECT * FROM meal_templates WHERE id = ?");
            $stmt->execute([$templateId]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($template) {
                // Insert as meal
                $stmt = $conn->prepare("
                    INSERT INTO meals (
                        user_id, meal_date, meal_type, name, calories, 
                        protein, carbs, fat, fiber, sugar, sodium
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $userId, $currentDate, $template['meal_type'], $template['name'], 
                    $template['calories'], $template['protein'], $template['carbs'], 
                    $template['fat'], $template['fiber'], $template['sugar'], $template['sodium']
                ]);
                
                $message = 'Meal added from template!';
                $messageType = 'success';
            }
            
            // Refresh page
            header("Location: nutrition.php?date=$currentDate&template_used=1");
            exit;
        } catch (PDOException $e) {
            $message = 'Error using template: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['save_log'])) {
        // Save nutrition log
        try {
            $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
            $notes = $_POST['log_notes'] ?? '';
            
            // Check if log exists for this date
            $stmt = $conn->prepare("SELECT id FROM nutrition_logs WHERE user_id = ? AND log_date = ?");
            $stmt->execute([$userId, $currentDate]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($exists) {
                // Update existing log
                $stmt = $conn->prepare("
                    UPDATE nutrition_logs 
                    SET total_calories = ?, total_protein = ?, total_carbs = ?, 
                        total_fat = ?, total_water = ?, weight = ?, notes = ?
                    WHERE user_id = ? AND log_date = ?
                ");
                
                $stmt->execute([
                    $dailyTotals['calories'], $dailyTotals['protein'], 
                    $dailyTotals['carbs'], $dailyTotals['fat'], 
                    $waterIntake, $weight, $notes, $userId, $currentDate
                ]);
            } else {
                // Insert new log
                $stmt = $conn->prepare("
                    INSERT INTO nutrition_logs (
                        user_id, log_date, total_calories, total_protein, 
                        total_carbs, total_fat, total_water, weight, notes
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $userId, $currentDate, $dailyTotals['calories'], 
                    $dailyTotals['protein'], $dailyTotals['carbs'], 
                    $dailyTotals['fat'], $waterIntake, $weight, $notes
                ]);
            }
            
            $message = 'Nutrition log saved!';
            $messageType = 'success';
            
            // Refresh page
            header("Location: nutrition.php?date=$currentDate&log_saved=1");
            exit;
        } catch (PDOException $e) {
            $message = 'Error saving log: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// FIX 3: Replace min function with conditional logic for calculating percentages
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

$fiberPercentage = 0;
if ($nutritionData['fiber_target'] > 0) {
    $fiberPercentage = round(($dailyTotals['fiber'] / $nutritionData['fiber_target']) * 100);
    if ($fiberPercentage > 100) {
        $fiberPercentage = 100;
    }
}

$sugarPercentage = 0;
if ($nutritionData['sugar_target'] > 0) {
    $sugarPercentage = round(($dailyTotals['sugar'] / $nutritionData['sugar_target']) * 100);
    if ($sugarPercentage > 100) {
        $sugarPercentage = 100;
    }
}

// Calculate macronutrient percentages
$totalMacros = $dailyTotals['protein'] * 4 + $dailyTotals['carbs'] * 4 + $dailyTotals['fat'] * 9;
$proteinMacroPercentage = ($totalMacros > 0) ? round(($dailyTotals['protein'] * 4 / $totalMacros) * 100) : 0;
$carbsMacroPercentage = ($totalMacros > 0) ? round(($dailyTotals['carbs'] * 4 / $totalMacros) * 100) : 0;
$fatMacroPercentage = ($totalMacros > 0) ? round(($dailyTotals['fat'] * 9 / $totalMacros) * 100) : 0;

// Get current theme
$theme = $settings['theme'];

// Helper function to format time ago
function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $difference = time() - $timestamp;
    
    if ($difference < 60) {
        return "Just now";
    } elseif ($difference < 3600) {
        $minutes = round($difference / 60);
        return $minutes . " minute" . ($minutes > 1 ? "s" : "") . " ago";
    } elseif ($difference < 86400) {
        $hours = round($difference / 3600);
        return $hours . " hour" . ($hours > 1 ? "s" : "") . " ago";
    } elseif ($difference < 604800) {
        $days = round($difference / 86400);
        return $days . " day" . ($days > 1 ? "s" : "") . " ago";
    } elseif ($difference < 2592000) {
        $weeks = round($difference / 604800);
        return $weeks . " week" . ($weeks > 1 ? "s" : "") . " ago";
    } else {
        return date("M j, Y", $timestamp);
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nutrition Tracker - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.css">
    
    <!-- Inline CSS Styles -->
    <style>
        /* Variables */
        :root {
            /* Light Theme */
            --bg-light: #f8f9fa;
            --card-bg-light: #ffffff;
            --text-light: #333333;
            --text-secondary-light: #6c757d;
            --border-light: #dee2e6;
            --hover-light: #f1f3f5;
            --primary-light: #ff6b00;
            --primary-hover-light: #e05e00;
            --success-light: #28a745;
            --danger-light: #dc3545;
            --warning-light: #ffc107;
            --info-light: #17a2b8;
            
            /* Dark Theme */
            --bg-dark: #121212;
            --card-bg-dark: #1e1e1e;
            --text-dark: #e0e0e0;
            --text-secondary-dark: #adb5bd;
            --border-dark: #333333;
            --hover-dark: #2a2a2a;
            --primary-dark: #ff6b00;
            --primary-hover-dark: #ff8c3f;
            --success-dark: #28a745;
            --danger-dark: #dc3545;
            --warning-dark: #ffc107;
            --info-dark: #17a2b8;
            
            /* Default Theme (Light) */
            --bg: var(--bg-light);
            --card-bg: var(--card-bg-light);
            --text: var(--text-light);
            --text-secondary: var(--text-secondary-light);
            --border: var(--border-light);
            --hover: var(--hover-light);
            --primary: var(--primary-light);
            --primary-hover: var(--primary-hover-light);
            --success: var(--success-light);
            --danger: var(--danger-light);
            --warning: var(--warning-light);
            --info: var(--info-light);
        }
        
        /* Dark Theme */
        html[data-theme="dark"] {
            --bg: var(--bg-dark);
            --card-bg: var(--card-bg-dark);
            --text: var(--text-dark);
            --text-secondary: var(--text-secondary-dark);
            --border: var(--border-dark);
            --hover: var(--hover-dark);
            --primary: var(--primary-dark);
            --primary-hover: var(--primary-hover-dark);
            --success: var(--success-dark);
            --danger: var(--danger-dark);
            --warning: var(--warning-dark);
            --info: var(--info-dark);
        }
        
        /* Orange Theme */
        html[data-theme="orange"] {
            --bg: #fff9f2;
            --card-bg: #ffffff;
            --text: #333333;
            --text-secondary: #6c757d;
            --border: #ffcb9a;
            --hover: #fff0e0;
            --primary: #ff6b00;
            --primary-hover: #e05e00;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
        }
        
        /* Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        a:hover {
            color: var(--primary-hover);
        }
        
        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 260px;
            background-color: var(--card-bg);
            border-right: 1px solid var(--border);
            padding: 1.5rem 0;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
            transition: transform 0.3s ease, background-color 0.3s ease, border-color 0.3s ease;
            z-index: 100;
        }
        
        .sidebar-header {
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            margin-bottom: 2rem;
        }
        
        .sidebar-header i {
            color: var(--primary);
            margin-right: 0.75rem;
        }
        
        .sidebar-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text);
        }
        
        .sidebar-user {
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            margin-bottom: 2rem;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 0.75rem;
            background-color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--primary);
            color: white;
            font-weight: 600;
        }
        
        .user-info h3 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .user-status {
            font-size: 0.75rem;
            color: var(--text-secondary);
            background-color: var(--hover);
            padding: 0.125rem 0.5rem;
            border-radius: 0.25rem;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 0.25rem;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: var(--text);
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        .sidebar-menu a:hover {
            background-color: var(--hover);
            color: var(--primary);
        }
        
        .sidebar-menu a.active {
            background-color: var(--hover);
            color: var(--primary);
            border-left: 3px solid var(--primary);
        }
        
        .sidebar-menu a i {
            margin-right: 0.75rem;
            width: 20px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 2rem;
            margin-left: 260px;
            transition: margin-left 0.3s ease;
        }
        
        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--text);
            font-size: 1.5rem;
            cursor: pointer;
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 101;
            transition: color 0.3s ease;
        }
        
        .mobile-menu-toggle:hover {
            color: var(--primary);
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .header h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            color: var(--text-secondary);
        }
        
        .header-actions {
            display: flex;
            gap: 0.75rem;
        }
        
        /* Cards */
        .card {
            background-color: var(--card-bg);
            border-radius: 0.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        
        .card-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
        }
        
        .card-content {
            padding: 1.5rem;
        }
        
        /* Date Navigation */
        .date-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }
        
        .date-nav-current {
            font-size: 1.25rem;
            font-weight: 600;
        }
        
        .date-nav-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        /* Nutrition Summary */
        .nutrition-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .summary-item {
            background-color: var(--card-bg);
            border-radius: 0.5rem;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .summary-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .summary-item-header {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        
        .summary-item-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
            font-size: 1.25rem;
        }
        
        .summary-item-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-secondary);
        }
        
        .summary-item-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .summary-item-subtitle {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        
        .calories-icon {
            background-color: rgba(255, 107, 0, 0.1);
            color: var(--primary);
        }
        
        .protein-icon {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success);
        }
        
        .carbs-icon {
            background-color: rgba(23, 162, 184, 0.1);
            color: var(--info);
        }
        
        .fat-icon {
            background-color: rgba(255, 193, 7, 0.1);
            color: var(--warning);
        }
        
        .fiber-icon {
            background-color: rgba(111, 66, 193, 0.1);
            color: #6f42c1;
        }
        
        .sugar-icon {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }
        
        /* Progress Bars */
        .progress-container {
            margin-top: 0.75rem;
        }
        
        .progress-bar {
            height: 6px;
            background-color: var(--hover);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 0.25rem;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        
        .progress-fill-calories {
            background-color: var(--primary);
        }
        
        .progress-fill-protein {
            background-color: var(--success);
        }
        
        .progress-fill-carbs {
            background-color: var(--info);
        }
        
        .progress-fill-fat {
            background-color: var(--warning);
        }
        
        .progress-fill-water {
            background-color: #3498db;
        }
        
        .progress-fill-fiber {
            background-color: #6f42c1;
        }
        
        .progress-fill-sugar {
            background-color: var(--danger);
        }
        
        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: var(--text-secondary);
        }
        
        /* Macro Distribution Chart */
        .macro-chart-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .macro-chart {
            width: 200px;
            height: 200px;
            margin-bottom: 1rem;
        }
        
        .macro-legend {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .macro-legend-item {
            display: flex;
            align-items: center;
            font-size: 0.85rem;
        }
        
        .macro-legend-color {
            width: 12px;
            height: 12px;
            border-radius: 2px;
            margin-right: 0.5rem;
        }
        
        /* Meal Sections */
        .meal-section {
            margin-bottom: 2rem;
        }
        
        .meal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .meal-title {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
        }
        
        .meal-title i {
            margin-right: 0.5rem;
            color: var(--primary);
        }
        
        .meal-items {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }
        
        .meal-item {
            background-color: var(--card-bg);
            border-radius: 0.5rem;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .meal-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .meal-item-info {
            flex: 1;
        }
        
        .meal-item-name {
            font-weight: 500;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
        }
        
        .meal-item-name i.fa-star {
            color: #ffc107;
            margin-left: 0.5rem;
            font-size: 0.85rem;
        }
        
        .meal-item-macros {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }
        
        .meal-item-macro {
            display: flex;
            align-items: center;
        }
        
        .meal-item-macro i {
            margin-right: 0.25rem;
            font-size: 0.75rem;
        }
        
        .meal-item-image {
            width: 60px;
            height: 60px;
            border-radius: 0.25rem;
            overflow: hidden;
            margin-right: 1rem;
        }
        
        .meal-item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .meal-item-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .meal-item-notes {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-style: italic;
            margin-top: 0.5rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .meal-empty {
            grid-column: 1 / -1;
            padding: 1.5rem;
            text-align: center;
            background-color: var(--card-bg);
            border-radius: 0.5rem;
            color: var(--text-secondary);
        }
        
        /* Water Tracker */
        .water-tracker {
            background-color: var(--card-bg);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .water-tracker-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .water-tracker-title {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
        }
        
        .water-tracker-title i {
            margin-right: 0.5rem;
            color: #3498db;
        }
        
        .water-tracker-content {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .water-progress {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .water-glasses {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .water-glass {
            width: 30px;
            height: 40px;
            background-color: var(--hover);
            border-radius: 0 0 15px 15px;
            position: relative;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        
        .water-glass.filled {
            background-color: #3498db;
        }
        
        .water-glass::before {
            content: '';
            position: absolute;
            top: -5px;
            left: 0;
            right: 0;
            height: 5px;
            border-radius: 5px 5px 0 0;
            background-color: inherit;
        }
        
        .water-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .water-amount {
            font-size: 1.25rem;
            font-weight: 600;
            color: #3498db;
        }
        
        /* Nutrition History Chart */
        .nutrition-history {
            margin-bottom: 2rem;
        }
        
        .chart-container {
            height: 300px;
            margin-top: 1rem;
        }
        
        .chart-legend {
            display: flex;
            justify-content: center;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .chart-legend-item {
            display: flex;
            align-items: center;
            font-size: 0.85rem;
        }
        
        .chart-legend-color {
            width: 12px;
            height: 12px;
            border-radius: 2px;
            margin-right: 0.5rem;
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.5rem;
        }
        
        .tab {
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
        }
        
        .tab.active {
            border-bottom-color: var(--primary);
            color: var(--primary);
            font-weight: 500;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Meal Templates */
        .templates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .template-card {
            background-color: var(--card-bg);
            border-radius: 0.5rem;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .template-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .template-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }
        
        .template-title {
            font-weight: 500;
        }
        
        .template-type {
            font-size: 0.75rem;
            color: var(--text-secondary);
            background-color: var(--hover);
            padding: 0.125rem 0.5rem;
            border-radius: 0.25rem;
        }
        
        .template-macros {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
        }
        
        .template-macro {
            display: flex;
            align-items: center;
        }
        
        .template-macro i {
            margin-right: 0.25rem;
            font-size: 0.75rem;
        }
        
        .template-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        
        /* Favorites */
        .favorites-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        /* Forms */
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 0.9rem;
            border: 1px solid var(--border);
            border-radius: 0.375rem;
            background-color: var(--card-bg);
            color: var(--text);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(255, 107, 0, 0.25);
        }
        
        .form-control::placeholder {
            color: var(--text-secondary);
        }
        
        .form-text {
            margin-top: 0.25rem;
            font-size: 0.75rem;
            color: var(--text-secondary);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }
        
        .form-check {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .form-check-input {
            margin-right: 0.5rem;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            font-weight: 500;
            border-radius: 0.375rem;
            border: none;
            cursor: pointer;
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        .btn i {
            margin-right: 0.5rem;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            color: white;
        }
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--border);
            color: var(--text);
        }
        
        .btn-outline:hover {
            background-color: var(--hover);
            color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-danger {
            background-color: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #bd2130;
            color: white;
        }
        
        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
        }
        
        .btn-lg {
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
        }
        
        .btn-icon {
            width: 2rem;
            height: 2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background-color: transparent;
            color: var(--text);
            border: none;
            cursor: pointer;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        .btn-icon:hover {
            background-color: var(--hover);
            color: var(--primary);
        }
        
        /* Modals */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .modal.show {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background-color: var(--card-bg);
            border-radius: 0.5rem;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            transform: translateY(-20px);
            transition: transform 0.3s ease;
        }
        
        .modal.show .modal-content {
            transform: translateY(0);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        
        .modal-header h4 {
            font-size: 1.25rem;
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
        }
        
        .modal-close:hover {
            color: var(--primary);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            padding: 1.25rem 1.5rem;
            border-top: 1px solid var(--border);
        }
        
        /* Alerts */
        .alert {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        
        .alert i {
            margin-right: 0.75rem;
            font-size: 1.25rem;
        }
        
        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success);
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .alert-error {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .alert .close {
            margin-left: auto;
            background: none;
            border: none;
            color: inherit;
            font-size: 1.25rem;
            cursor: pointer;
            opacity: 0.5;
            transition: opacity 0.3s ease;
        }
        
        .alert .close:hover {
            opacity: 1;
        }
        
        /* Tooltips */
        .tooltip {
            position: relative;
            display: inline-block;
        }
        
        .tooltip .tooltip-text {
            visibility: hidden;
            width: 120px;
            background-color: var(--text);
            color: var(--card-bg);
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -60px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.75rem;
        }
        
        .tooltip .tooltip-text::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: var(--text) transparent transparent transparent;
        }
        
        .tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
        
        /* Responsive Styles */
        @media (max-width: 992px) {
            .nutrition-summary {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .templates-grid, .favorites-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 1000;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1.5rem;
            }
            
            .mobile-menu-toggle {
                display: block;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .header-actions {
                margin-top: 1rem;
                width: 100%;
            }
            
            .nutrition-summary {
                grid-template-columns: 1fr;
            }
            
            .meal-items {
                grid-template-columns: 1fr;
            }
            
            .date-nav {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .date-nav-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .templates-grid, .favorites-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                max-width: 90%;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-dumbbell fa-2x"></i>
                <h2>EliteFit Gym</h2>
            </div>
            <div class="sidebar-user">
                <div class="user-avatar">
                    <?php if (!empty($profileImage)): ?>
                        <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="avatar-placeholder">
                            <?php echo strtoupper(substr($userName, 0, 1)); ?>
                        </div>
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
                    <button class="btn btn-primary" id="addMealBtn">
                        <i class="fas fa-plus"></i> Add Meal
                    </button>
                    <button class="btn btn-outline" id="nutritionSettingsBtn">
                        <i class="fas fa-sliders-h"></i> Settings
                    </button>
                    <button class="btn btn-outline" id="saveLogBtn">
                        <i class="fas fa-save"></i> Save Log
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
                        <i class="fas fa-chevron-left"></i> Previous Day
                    </a>
                    <a href="nutrition.php" class="btn btn-outline btn-sm">
                        <i class="fas fa-calendar-day"></i> Today
                    </a>
                    <a href="nutrition.php?date=<?php echo $nextDate; ?>" class="btn btn-outline btn-sm">
                        Next Day <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
            
            <!-- Tabs -->
            <div class="tabs">
                <div class="tab active" data-tab="daily">Daily Tracker</div>
                <div class="tab" data-tab="templates">Meal Templates</div>
                <div class="tab" data-tab="favorites">My Favorites</div>
                <div class="tab" data-tab="reports">Reports</div>
            </div>
            
            <!-- Daily Tracker Tab -->
            <div class="tab-content active" id="daily-tab">
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
                
                <!-- Macro Distribution Chart -->
                <div class="card">
                    <div class="card-header">
                        <h3>Macronutrient Distribution</h3>
                    </div>
                    <div class="card-content">
                        <div class="macro-chart-container">
                            <canvas id="macroChart" class="macro-chart"></canvas>
                            <div class="macro-legend">
                                <div class="macro-legend-item">
                                    <div class="macro-legend-color" style="background-color: #28a745;"></div>
                                    <span>Protein: <?php echo $proteinMacroPercentage; ?>%</span>
                                </div>
                                <div class="macro-legend-item">
                                    <div class="macro-legend-color" style="background-color: #17a2b8;"></div>
                                    <span>Carbs: <?php echo $carbsMacroPercentage; ?>%</span>
                                </div>
                                <div class="macro-legend-item">
                                    <div class="macro-legend-color" style="background-color: #ffc107;"></div>
                                    <span>Fat: <?php echo $fatMacroPercentage; ?>%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Water Tracker -->
                <div class="water-tracker">
                    <div class="water-tracker-header">
                        <div class="water-tracker-title">
                            <i class="fas fa-tint"></i> Water Intake
                        </div>
                        <button class="btn btn-outline btn-sm" id="updateWaterBtn">
                            <i class="fas fa-sync-alt"></i> Update
                        </button>
                    </div>
                    <div class="water-tracker-content">
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
                            <button class="btn btn-outline btn-sm add-meal-btn" data-meal-type="<?php echo $mealType; ?>">
                                <i class="fas fa-plus"></i> Add
                            </button>
                        </div>
                        <div class="meal-items">
                            <?php if (isset($meals[$mealType]) && !empty($meals[$mealType])): ?>
                                <?php foreach ($meals[$mealType] as $meal): ?>
                                    <div class="meal-item">
                                        <?php if (!empty($meal['meal_image'])): ?>
                                            <div class="meal-item-image">
                                                <img src="<?php echo htmlspecialchars($meal['meal_image']); ?>" alt="<?php echo htmlspecialchars($meal['name']); ?>">
                                            </div>
                                        <?php endif; ?>
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
                                                <input type="hidden" name="save_template" value="1">
                                                <input type="hidden" name="meal_id" value="<?php echo $meal['id']; ?>">
                                                <button type="submit" class="btn-icon" title="Save as template">
                                                    <i class="fas fa-save"></i>
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
                                    <button class="btn btn-outline btn-sm add-meal-btn" data-meal-type="<?php echo $mealType; ?>">
                                        <i class="fas fa-plus"></i> Add Meal
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Meal Templates Tab -->
            <div class="tab-content" id="templates-tab">
                <div class="card">
                    <div class="card-header">
                        <h3>Meal Templates</h3>
                        <button class="btn btn-outline btn-sm" id="createTemplateBtn">
                            <i class="fas fa-plus"></i> Create Template
                        </button>
                    </div>
                    <div class="card-content">
                        <div class="templates-grid">
                            <?php if (!empty($mealTemplates)): ?>
                                <?php foreach ($mealTemplates as $template): ?>
                                    <div class="template-card">
                                        <div class="template-header">
                                            <div class="template-title"><?php echo htmlspecialchars($template['name']); ?></div>
                                            <div class="template-type"><?php echo htmlspecialchars($template['meal_type']); ?></div>
                                        </div>
                                        <div class="template-macros">
                                            <div class="template-macro">
                                                <i class="fas fa-fire"></i> <?php echo $template['calories']; ?> kcal
                                            </div>
                                            <div class="template-macro">
                                                <i class="fas fa-drumstick-bite"></i> <?php echo $template['protein']; ?>g
                                            </div>
                                            <div class="template-macro">
                                                <i class="fas fa-bread-slice"></i> <?php echo $template['carbs']; ?>g
                                            </div>
                                            <div class="template-macro">
                                                <i class="fas fa-cheese"></i> <?php echo $template['fat']; ?>g
                                            </div>
                                        </div>
                                        <div class="template-actions">
                                            <form action="nutrition.php?date=<?php echo $currentDate; ?>" method="post">
                                                <input type="hidden" name="use_template" value="1">
                                                <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-plus"></i> Add to Today
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="meal-empty">
                                    <i class="fas fa-clipboard-list"></i>
                                    <p>No meal templates available yet.</p>
                                    <button class="btn btn-outline btn-sm" id="createTemplateEmptyBtn">
                                        <i class="fas fa-plus"></i> Create Template
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Favorites Tab -->
            <div class="tab-content" id="favorites-tab">
                <div class="card">
                    <div class="card-header">
                        <h3>My Favorite Meals</h3>
                    </div>
                    <div class="card-content">
                        <div class="favorites-grid">
                            <?php if (!empty($favoriteMeals)): ?>
                                <?php foreach ($favoriteMeals as $meal): ?>
                                    <div class="template-card">
                                        <div class="template-header">
                                            <div class="template-title"><?php echo htmlspecialchars($meal['name']); ?></div>
                                            <div class="template-type"><?php echo htmlspecialchars($meal['meal_type']); ?></div>
                                        </div>
                                        <div class="template-macros">
                                            <div class="template-macro">
                                                <i class="fas fa-fire"></i> <?php echo $meal['calories']; ?> kcal
                                            </div>
                                            <div class="template-macro">
                                                <i class="fas fa-drumstick-bite"></i> <?php echo $meal['protein']; ?>g
                                            </div>
                                            <div class="template-macro">
                                                <i class="fas fa-bread-slice"></i> <?php echo $meal['carbs']; ?>g
                                            </div>
                                            <div class="template-macro">
                                                <i class="fas fa-cheese"></i> <?php echo $meal['fat']; ?>g
                                            </div>
                                        </div>
                                        <div class="template-actions">
                                            <form action="nutrition.php?date=<?php echo $currentDate; ?>" method="post">
                                                <input type="hidden" name="save_template" value="1">
                                                <input type="  name="save_template" value="1">
                                                <input type="hidden" name="meal_id" value="<?php echo $meal['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline">
                                                    <i class="fas fa-save"></i> Save as Template
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="meal-empty">
                                    <i class="fas fa-star"></i>
                                    <p>You haven't added any favorite meals yet.</p>
                                    <p>Mark meals as favorites to see them here.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Reports Tab -->
            <div class="tab-content" id="reports-tab">
                <div class="card nutrition-history">
                    <div class="card-header">
                        <h3>Nutrition History</h3>
                    </div>
                    <div class="card-content">
                        <div class="chart-container">
                            <canvas id="nutritionHistoryChart"></canvas>
                        </div>
                        <div class="chart-legend">
                            <div class="chart-legend-item">
                                <div class="chart-legend-color" style="background-color: #ff6b00;"></div>
                                <span>Calories</span>
                            </div>
                            <div class="chart-legend-item">
                                <div class="chart-legend-color" style="background-color: #28a745;"></div>
                                <span>Protein</span>
                            </div>
                            <div class="chart-legend-item">
                                <div class="chart-legend-color" style="background-color: #17a2b8;"></div>
                                <span>Carbs</span>
                            </div>
                            <div class="chart-legend-item">
                                <div class="chart-legend-color" style="background-color: #ffc107;"></div>
                                <span>Fat</span>
                            </div>
                            <div class="chart-legend-item">
                                <div class="chart-legend-color" style="background-color: #3498db;"></div>
                                <span>Water</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3>Nutrition Insights</h3>
                    </div>
                    <div class="card-content">
                        <div id="nutritionInsights">
                            <p>Loading insights...</p>
                        </div>
                    </div>
                </div>
            </div>
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
                <form action="nutrition.php?date=<?php echo $currentDate; ?>" method="post" id="addMealForm" enctype="multipart/form-data">
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
                        <label for="meal_image">Meal Image (optional)</label>
                        <input type="file" id="meal_image" name="meal_image" class="form-control" accept="image/*">
                        <div class="form-text">Max file size: 5MB. Supported formats: JPEG, PNG, GIF</div>
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
                <button type="submit" form="addMealForm" class="btn btn-primary">Add Meal</button>
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
                        <input type="number" id="water_amount" name="water_amount" class="form-control" min="0" step="50" value="<?php echo $waterIntake; ?>" required>
                        <div class="form-text">1 glass ≈ 250ml</div>
                    </div>
                    
                    <div class="water-controls">
                        <button type="button" class="btn btn-outline btn-sm" id="decreaseWater">
                            <i class="fas fa-minus"></i>
                        </button>
                        <div class="water-amount" id="waterAmountDisplay"><?php echo $waterIntake; ?> ml</div>
                        <button type="button" class="btn btn-outline btn-sm" id="increaseWater">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelUpdateWater">Cancel</button>
                <button type="submit" form="updateWaterForm" class="btn btn-primary">Update</button>
            </div>
        </div>
    </div>
    
    <!-- Nutrition Settings Modal -->
    <div class="modal" id="nutritionSettingsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>Nutrition Settings</h4>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form action="nutrition.php?date=<?php echo $currentDate; ?>" method="post" id="nutritionSettingsForm">
                    <input type="hidden" name="update_nutrition_settings" value="1">
                    
                    <div class="form-group">
                        <label for="daily_calories">Daily Calorie Target (kcal)</label>
                        <input type="number" id="daily_calories" name="daily_calories" class="form-control" min="1000" step="50" value="<?php echo $nutritionData['daily_calories']; ?>" required>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="protein_target">Protein Target (g)</label>
                            <input type="number" id="protein_target" name="protein_target" class="form-control" min="0" step="5" value="<?php echo $nutritionData['protein_target']; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="carbs_target">Carbs Target (g)</label>
                            <input type="number" id="carbs_target" name="carbs_target" class="form-control" min="0" step="5" value="<?php echo $nutritionData['carbs_target']; ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="fat_target">Fat Target (g)</label>
                            <input type="number" id="fat_target" name="fat_target" class="form-control" min="0" step="5" value="<?php echo $nutritionData['fat_target']; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="water_target">Water Target (ml)</label>
                            <input type="number" id="water_target" name="water_target" class="form-control" min="0" step="100" value="<?php echo $nutritionData['water_target']; ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="fiber_target">Fiber Target (g)</label>
                            <input type="number" id="fiber_target" name="fiber_target" class="form-control" min="0" step="1" value="<?php echo $nutritionData['fiber_target']; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="sugar_target">Sugar Target (g)</label>
                            <input type="number" id="sugar_target" name="sugar_target" class="form-control" min="0" step="1" value="<?php echo $nutritionData['sugar_target']; ?>" required>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelNutritionSettings">Cancel</button>
                <button type="submit" form="nutritionSettingsForm" class="btn btn-primary">Save Settings</button>
            </div>
        </div>
    </div>
    
    <!-- Create Template Modal -->
    <div class="modal" id="createTemplateModal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>Create Meal Template</h4>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form action="nutrition.php" method="post" id="createTemplateForm">
                    <input type="hidden" name="create_template" value="1">
                    
                    <div class="form-group">
                        <label for="template_name">Template Name</label>
                        <input type="text" id="template_name" name="template_name" class="form-control" placeholder="e.g., Protein Breakfast" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="template_meal_type">Meal Type</label>
                        <select id="template_meal_type" name="template_meal_type" class="form-control" required>
                            <?php foreach ($mealTypes as $type): ?>
                                <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="template_calories">Calories (kcal)</label>
                            <input type="number" id="template_calories" name="template_calories" class="form-control" min="0" step="1" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="template_protein">Protein (g)</label>
                            <input type="number" id="template_protein" name="template_protein" class="form-control" min="0" step="0.1" required>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="template_carbs">Carbs (g)</label>
                            <input type="number" id="template_carbs" name="template_carbs" class="form-control" min="0" step="0.1" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="template_fat">Fat (g)</label>
                            <input type="number" id="template_fat" name="template_fat" class="form-control" min="0" step="0.1" required>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="template_fiber">Fiber (g)</label>
                            <input type="number" id="template_fiber" name="template_fiber" class="form-control" min="0" step="0.1" value="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="template_sugar">Sugar (g)</label>
                            <input type="number" id="template_sugar" name="template_sugar" class="form-control" min="0" step="0.1" value="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="template_sodium">Sodium (mg)</label>
                        <input type="number" id="template_sodium" name="template_sodium" class="form-control" min="0" step="1" value="0">
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" id="template_is_public" name="template_is_public" class="form-check-input" checked>
                        <label for="template_is_public" class="form-check-label">Make template public (visible to other members)</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelCreateTemplate">Cancel</button>
                <button type="submit" form="createTemplateForm" class="btn btn-primary">Create Template</button>
            </div>
        </div>
    </div>
    
    <!-- Save Log Modal -->
    <div class="modal" id="saveLogModal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>Save Nutrition Log</h4>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form action="nutrition.php?date=<?php echo $currentDate; ?>" method="post" id="saveLogForm">
                    <input type="hidden" name="save_log" value="1">
                    
                    <div class="form-group">
                        <label for="weight">Weight (optional)</label>
                        <input type="number" id="weight" name="weight" class="form-control" min="0" step="0.1" placeholder="Enter your current weight">
                        <div class="form-text">Recording your weight helps track progress over time</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="log_notes">Notes (optional)</label>
                        <textarea id="log_notes" name="log_notes" class="form-control" rows="3" placeholder="Add any notes about today's nutrition..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" id="cancelSaveLog">Cancel</button>
                <button type="submit" form="saveLogForm" class="btn btn-primary">Save Log</button>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });
        
        // Tab functionality
        const tabs = document.querySelectorAll('.tab');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                
                // Remove active class from all tabs and contents
                tabs.forEach(t => t.classList.remove('active'));
                tabContents.forEach(c => c.classList.remove('active'));
                
                // Add active class to current tab and content
                this.classList.add('active');
                document.getElementById(tabId + '-tab').classList.add('active');
                
                // Initialize charts if on reports tab
                if (tabId === 'reports') {
                    initNutritionHistoryChart();
                    loadNutritionInsights();
                }
            });
        });
        
        // Modal functionality
        const modals = document.querySelectorAll('.modal');
        const modalCloseButtons = document.querySelectorAll('.modal-close');
        
        // Open modals
        document.getElementById('addMealBtn').addEventListener('click', function() {
            document.getElementById('addMealModal').classList.add('show');
        });
        
        document.getElementById('updateWaterBtn').addEventListener('click', function() {
            document.getElementById('updateWaterModal').classList.add('show');
        });
        
        document.getElementById('nutritionSettingsBtn').addEventListener('click', function() {
            document.getElementById('nutritionSettingsModal').classList.add('show');
        });
        
        document.getElementById('createTemplateBtn').addEventListener('click', function() {
            document.getElementById('createTemplateModal').classList.add('show');
        });
        
        if (document.getElementById('createTemplateEmptyBtn')) {
            document.getElementById('createTemplateEmptyBtn').addEventListener('click', function() {
                document.getElementById('createTemplateModal').classList.add('show');
            });
        }
        
        document.getElementById('saveLogBtn').addEventListener('click', function() {
            document.getElementById('saveLogModal').classList.add('show');
        });
        
        // Add meal buttons for each meal type
        const addMealButtons = document.querySelectorAll('.add-meal-btn');
        addMealButtons.forEach(button => {
            button.addEventListener('click', function() {
                const mealType = this.getAttribute('data-meal-type');
                document.getElementById('meal_type').value = mealType;
                document.getElementById('addMealModal').classList.add('show');
            });
        });
        
        // Close modals
        modalCloseButtons.forEach(button => {
            button.addEventListener('click', function() {
                modals.forEach(modal => {
                    modal.classList.remove('show');
                });
            });
        });
        
        // Cancel buttons
        document.getElementById('cancelAddMeal').addEventListener('click', function() {
            document.getElementById('addMealModal').classList.remove('show');
        });
        
        document.getElementById('cancelUpdateWater').addEventListener('click', function() {
            document.getElementById('updateWaterModal').classList.remove('show');
        });
        
        document.getElementById('cancelNutritionSettings').addEventListener('click', function() {
            document.getElementById('nutritionSettingsModal').classList.remove('show');
        });
        
        document.getElementById('cancelCreateTemplate').addEventListener('click', function() {
            document.getElementById('createTemplateModal').classList.remove('show');
        });
        
        document.getElementById('cancelSaveLog').addEventListener('click', function() {
            document.getElementById('saveLogModal').classList.remove('show');
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('show');
                }
            });
        });
        
        // Water intake controls
        const waterAmount = document.getElementById('water_amount');
        const waterAmountDisplay = document.getElementById('waterAmountDisplay');
        const decreaseWaterBtn = document.getElementById('decreaseWater');
        const increaseWaterBtn = document.getElementById('increaseWater');
        
        decreaseWaterBtn.addEventListener('click', function() {
            let amount = parseInt(waterAmount.value);
            if (amount >= 250) {
                amount -= 250;
                waterAmount.value = amount;
                waterAmountDisplay.textContent = amount + ' ml';
            }
        });
        
        increaseWaterBtn.addEventListener('click', function() {
            let amount = parseInt(waterAmount.value);
            amount += 250;
            waterAmount.value = amount;
            waterAmountDisplay.textContent = amount + ' ml';
        });
        
        // Water glass click
        const waterGlasses = document.querySelectorAll('.water-glass');
        waterGlasses.forEach(glass => {
            glass.addEventListener('click', function() {
                const glassNumber = parseInt(this.getAttribute('data-glass'));
                const amount = glassNumber * 300;
                
                // Update form
                document.getElementById('water_amount').value = amount;
                
                // Submit form
                document.getElementById('updateWaterForm').submit();
            });
        });
        
        // Alert close button
        const alertCloseBtn = document.querySelector('.alert .close');
        if (alertCloseBtn) {
            alertCloseBtn.addEventListener('click', function() {
                this.closest('.alert').style.display = 'none';
            });
        }
        
        // Macro Distribution Chart
        const macroCtx = document.getElementById('macroChart').getContext('2d');
        const macroChart = new Chart(macroCtx, {
            type: 'doughnut',
            data: {
                labels: ['Protein', 'Carbs', 'Fat'],
                datasets: [{
                    data: [
                        <?php echo $proteinMacroPercentage; ?>,
                        <?php echo $carbsMacroPercentage; ?>,
                        <?php echo $fatMacroPercentage; ?>
                    ],
                    backgroundColor: [
                        '#28a745',
                        '#17a2b8',
                        '#ffc107'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.raw + '%';
                            }
                        }
                    }
                }
            }
        });
        
        // Nutrition History Chart
        function initNutritionHistoryChart() {
            const historyCtx = document.getElementById('nutritionHistoryChart').getContext('2d');
            
            // Sample data - replace with actual data from PHP
            const dates = [
                <?php 
                    $dates = [];
                    $calories = [];
                    $proteins = [];
                    $carbs = [];
                    $fats = [];
                    $waters = [];
                    
                    // Get last 7 days
                    for ($i = 6; $i >= 0; $i--) {
                        $date = date('Y-m-d', strtotime("-$i days"));
                        $dates[] = "'" . date('M j', strtotime($date)) . "'";
                        
                        // Find matching log
                        $found = false;
                        foreach ($nutritionHistory as $log) {
                            if ($log['log_date'] == $date) {
                                $calories[] = $log['total_calories'];
                                $proteins[] = $log['total_protein'];
                                $carbs[] = $log['total_carbs'];
                                $fats[] = $log['total_fat'];
                                $waters[] = $log['total_water'] / 1000; // Convert to liters
                                $found = true;
                                break;
                            }
                        }
                        
                        if (!$found) {
                            $calories[] = 0;
                            $proteins[] = 0;
                            $carbs[] = 0;
                            $fats[] = 0;
                            $waters[] = 0;
                        }
                    }
                    
                    echo implode(', ', $dates);
                ?>
            ];
            
            const caloriesData = [<?php echo implode(', ', $calories); ?>];
            const proteinData = [<?php echo implode(', ', $proteins); ?>];
            const carbsData = [<?php echo implode(', ', $carbs); ?>];
            const fatData = [<?php echo implode(', ', $fats); ?>];
            const waterData = [<?php echo implode(', ', $waters); ?>];
            
            const historyChart = new Chart(historyCtx, {
                type: 'line',
                data: {
                    labels: dates,
                    datasets: [
                        {
                            label: 'Calories',
                            data: caloriesData,
                            borderColor: '#ff6b00',
                            backgroundColor: 'rgba(255, 107, 0, 0.1)',
                            tension: 0.3,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Protein (g)',
                            data: proteinData,
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            tension: 0.3,
                            yAxisID: 'y1'
                        },
                        {
                            label: 'Carbs (g)',
                            data: carbsData,
                            borderColor: '#17a2b8',
                            backgroundColor: 'rgba(23, 162, 184, 0.1)',
                            tension: 0.3,
                            yAxisID: 'y1'
                        },
                        {
                            label: 'Fat (g)',
                            data: fatData,
                            borderColor: '#ffc107',
                            backgroundColor: 'rgba(255, 193, 7, 0.1)',
                            tension: 0.3,
                            yAxisID: 'y1'
                        },
                        {
                            label: 'Water (L)',
                            data: waterData,
                            borderColor: '#3498db',
                            backgroundColor: 'rgba(52, 152, 219, 0.1)',
                            tension: 0.3,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Calories'
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Grams / Liters'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }
        
        // Load nutrition insights
        function loadNutritionInsights() {
            const insightsContainer = document.getElementById('nutritionInsights');
            
            // Calculate averages
            const avgCalories = <?php 
                $sum = 0;
                $count = 0;
                foreach ($nutritionHistory as $log) {
                    if ($log['total_calories'] > 0) {
                        $sum += $log['total_calories'];
                        $count++;
                    }
                }
                echo $count > 0 ? round($sum / $count) : 0;
            ?>;
            
            const avgProtein = <?php 
                $sum = 0;
                $count = 0;
                foreach ($nutritionHistory as $log) {
                    if ($log['total_protein'] > 0) {
                        $sum += $log['total_protein'];
                        $count++;
                    }
                }
                echo $count > 0 ? round($sum / $count, 1) : 0;
            ?>;
            
            const avgCarbs = <?php 
                $sum = 0;
                $count = 0;
                foreach ($nutritionHistory as $log) {
                    if ($log['total_carbs'] > 0) {
                        $sum += $log['total_carbs'];
                        $count++;
                    }
                }
                echo $count > 0 ? round($sum / $count, 1) : 0;
            ?>;
            
            const avgFat = <?php 
                $sum = 0;
                $count = 0;
                foreach ($nutritionHistory as $log) {
                    if ($log['total_fat'] > 0) {
                        $sum += $log['total_fat'];
                        $count++;
                    }
                }
                echo $count > 0 ? round($sum / $count, 1) : 0;
            ?>;
            
            const avgWater = <?php 
                $sum = 0;
                $count = 0;
                foreach ($nutritionHistory as $log) {
                    if ($log['total_water'] > 0) {
                        $sum += $log['total_water'];
                        $count++;
                    }
                }
                echo $count > 0 ? round($sum / $count) : 0;
            ?>;
            
            // Generate insights
            let insights = '';
            
            if (avgCalories > 0) {
                insights += `<div class="card" style="margin-bottom: 1rem; padding: 1rem; border-radius: 0.5rem;">
                    <h4>Weekly Average</h4>
                    <p>Your average daily intake: <strong>${avgCalories} calories</strong>, ${avgProtein}g protein, ${avgCarbs}g carbs, ${avgFat}g fat</p>
                    <p>Average water consumption: <strong>${avgWater} ml</strong></p>
                </div>`;
            }
            
            // Compare with targets
            const caloriesDiff = <?php echo $dailyTotals['calories']; ?> - <?php echo $nutritionData['daily_calories']; ?>;
            const proteinDiff = <?php echo $dailyTotals['protein']; ?> - <?php echo $nutritionData['protein_target']; ?>;
            const carbsDiff = <?php echo $dailyTotals['carbs']; ?> - <?php echo $nutritionData['carbs_target']; ?>;
            const fatDiff = <?php echo $dailyTotals['fat']; ?> - <?php echo $nutritionData['fat_target']; ?>;
            const waterDiff = <?php echo $waterIntake; ?> - <?php echo $nutritionData['water_target']; ?>;
            
            insights += `<div class="card" style="margin-bottom: 1rem; padding: 1rem; border-radius: 0.5rem;">
                <h4>Today's Progress</h4>
                <ul style="list-style-type: none; padding-left: 0;">
                    <li style="margin-bottom: 0.5rem;">
                        <i class="fas fa-fire" style="color: var(--primary); margin-right: 0.5rem;"></i>
                        Calories: <strong>${caloriesDiff > 0 ? caloriesDiff + ' over' : Math.abs(caloriesDiff) + ' under'}</strong> your target
                    </li>
                    <li style="margin-bottom: 0.5rem;">
                        <i class="fas fa-drumstick-bite" style="color: var(--success); margin-right: 0.5rem;"></i>
                        Protein: <strong>${proteinDiff > 0 ? proteinDiff + 'g over' : Math.abs(proteinDiff) + 'g under'}</strong> your target
                    </li>
                    <li style="margin-bottom: 0.5rem;">
                        <i class="fas fa-bread-slice" style="color: var(--info); margin-right: 0.5rem;"></i>
                        Carbs: <strong>${carbsDiff > 0 ? carbsDiff + 'g over' : Math.abs(carbsDiff) + 'g under'}</strong> your target
                    </li>
                    <li style="margin-bottom: 0.5rem;">
                        <i class="fas fa-cheese" style="color: var(--warning); margin-right: 0.5rem;"></i>
                        Fat: <strong>${fatDiff > 0 ? fatDiff + 'g over' : Math.abs(fatDiff) + 'g under'}</strong> your target
                    </li>
                    <li>
                        <i class="fas fa-tint" style="color: #3498db; margin-right: 0.5rem;"></i>
                        Water: <strong>${waterDiff > 0 ? waterDiff + 'ml over' : Math.abs(waterDiff) + 'ml under'}</strong> your target
                    </li>
                </ul>
            </div>`;
            
            // Recommendations
            insights += `<div class="card" style="padding: 1rem; border-radius: 0.5rem;">
                <h4>Recommendations</h4>
                <ul style="list-style-type: none; padding-left: 0;">`;
                
            if (<?php echo $proteinPercentage; ?> < 80) {
                insights += `<li style="margin-bottom: 0.5rem;">
                    <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                    Try to increase your protein intake to meet your daily target
                </li>`;
            }
            
            if (<?php echo $waterPercentage; ?> < 80) {
                insights += `<li style="margin-bottom: 0.5rem;">
                    <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                    Drink more water to stay hydrated
                </li>`;
            }
            
            if (<?php echo $carbsPercentage; ?> > 120) {
                insights += `<li style="margin-bottom: 0.5rem;">
                    <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                    Consider reducing your carbohydrate intake
                </li>`;
            }
            
            if (<?php echo $fatPercentage; ?> > 120) {
                insights += `<li style="margin-bottom: 0.5rem;">
                    <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                    Try to reduce your fat consumption
                </li>`;
            }
            
            if (<?php echo $caloriesPercentage; ?> < 80) {
                insights += `<li>
                    <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                    You're under your calorie target - make sure you're eating enough
                </li>`;
            } else if (<?php echo $caloriesPercentage; ?> > 110) {
                insights += `<li>
                    <i class="fas fa-check-circle" style="color: var(--success); margin-right: 0.5rem;"></i>
                    You're over your calorie target - consider adjusting your intake
                </li>`;
            }
            
            insights += `</ul>
            </div>`;
            
            insightsContainer.innerHTML = insights;
        }
        
        // Initialize charts on page load if on reports tab
        if (document.querySelector('.tab[data-tab="reports"]').classList.contains('active')) {
            initNutritionHistoryChart();
            loadNutritionInsights();
        }
    </script>
</body>
</html>
