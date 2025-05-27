<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Trainer role to access this page
requireRole('Trainer');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Connect to database
require_once __DIR__ . '/../config/database.php';
$conn = connectDB();

// Include theme preference helper
require_once 'trainer-theme-helper.php';
$theme = getThemePreference($conn, $userId);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'get_template') {
        $templateId = intval($_POST['template_id']);
        $template = getTemplateById($conn, $templateId);
        echo json_encode($template);
        exit;
    }
    
    if ($_POST['action'] === 'create_custom_template') {
        $result = createCustomTemplate($conn, $userId, $_POST);
        echo json_encode($result);
        exit;
    }
    
    if ($_POST['action'] === 'add_to_plan') {
        $result = addTemplateToNutritionPlan($conn, $_POST);
        echo json_encode($result);
        exit;
    }
}

// Get meal templates with advanced filtering
$category = $_GET['category'] ?? '';
$goal = $_GET['goal'] ?? '';
$dietary = $_GET['dietary'] ?? '';
$difficulty = $_GET['difficulty'] ?? '';
$search = $_GET['search'] ?? '';

$templates = getMealTemplates($conn, $category, $goal, $dietary, $difficulty, $search);
$categories = ['breakfast', 'lunch', 'dinner', 'snack'];
$goals = ['weight_loss', 'muscle_gain', 'maintenance', 'performance'];
$difficulties = ['easy', 'medium', 'hard'];

// Get nutrition plans for adding templates
$nutritionPlans = getTrainerNutritionPlans($conn, $userId);

function getMealTemplates($conn, $category, $goal, $dietary, $difficulty, $search) {
    try {
        $whereConditions = [];
        $params = [];
        
        if (!empty($category)) {
            $whereConditions[] = "category = ?";
            $params[] = $category;
        }
        
        if (!empty($goal)) {
            $whereConditions[] = "goal = ?";
            $params[] = $goal;
        }
        
        if (!empty($dietary)) {
            switch ($dietary) {
                case 'vegetarian':
                    $whereConditions[] = "is_vegetarian = 1";
                    break;
                case 'vegan':
                    $whereConditions[] = "is_vegan = 1";
                    break;
                case 'gluten_free':
                    $whereConditions[] = "is_gluten_free = 1";
                    break;
                case 'dairy_free':
                    $whereConditions[] = "is_dairy_free = 1";
                    break;
            }
        }
        
        if (!empty($difficulty)) {
            $whereConditions[] = "difficulty = ?";
            $params[] = $difficulty;
        }
        
        if (!empty($search)) {
            $whereConditions[] = "(name LIKE ? OR ingredients LIKE ? OR tags LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        $stmt = $conn->prepare("
            SELECT * FROM meal_templates 
            {$whereClause}
            ORDER BY category, name
        ");
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting meal templates: " . $e->getMessage());
        return [];
    }
}

function getTemplateById($conn, $templateId) {
    try {
        $stmt = $conn->prepare("SELECT * FROM meal_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting template: " . $e->getMessage());
        return null;
    }
}

function getTrainerNutritionPlans($conn, $trainerId) {
    try {
        $stmt = $conn->prepare("
            SELECT np.id, np.plan_name, u.name as member_name
            FROM nutrition_plans np
            JOIN users u ON np.member_id = u.id
            WHERE np.trainer_id = ? AND np.status = 'active'
            ORDER BY np.plan_name
        ");
        $stmt->execute([$trainerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting nutrition plans: " . $e->getMessage());
        return [];
    }
}

function createCustomTemplate($conn, $userId, $data) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO meal_templates 
            (name, category, goal, difficulty, prep_time, cook_time, servings, ingredients, instructions, 
             calories, protein, carbs, fats, fiber, sugar, sodium, tags, is_vegetarian, is_vegan, is_gluten_free, is_dairy_free, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $tags = !empty($data['tags']) ? json_encode(explode(',', $data['tags'])) : '[]';
        
        $stmt->execute([
            $data['name'],
            $data['category'],
            $data['goal'],
            $data['difficulty'],
            $data['prep_time'],
            $data['cook_time'],
            $data['servings'],
            $data['ingredients'],
            $data['instructions'],
            $data['calories'],
            $data['protein'],
            $data['carbs'],
            $data['fats'],
            $data['fiber'] ?? 0,
            $data['sugar'] ?? 0,
            $data['sodium'] ?? 0,
            $tags,
            isset($data['is_vegetarian']) ? 1 : 0,
            isset($data['is_vegan']) ? 1 : 0,
            isset($data['is_gluten_free']) ? 1 : 0,
            isset($data['is_dairy_free']) ? 1 : 0,
            $userId
        ]);
        
        return ['success' => true, 'message' => 'Custom template created successfully!'];
    } catch (PDOException $e) {
        error_log("Error creating custom template: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error creating template. Please try again.'];
    }
}

function addTemplateToNutritionPlan($conn, $data) {
    try {
        // Get template details
        $template = getTemplateById($conn, $data['template_id']);
        if (!$template) {
            return ['success' => false, 'message' => 'Template not found.'];
        }
        
        // Add to meal plans
        $stmt = $conn->prepare("
            INSERT INTO meal_plans 
            (nutrition_plan_id, meal_type, day_of_week, meal_name, ingredients, instructions, calories, protein, carbs, fats)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['nutrition_plan_id'],
            $template['category'],
            $data['day_of_week'],
            $template['name'],
            $template['ingredients'],
            $template['instructions'],
            $template['calories'],
            $template['protein'],
            $template['carbs'],
            $template['fats']
        ]);
        
        return ['success' => true, 'message' => 'Template added to nutrition plan successfully!'];
    } catch (PDOException $e) {
        error_log("Error adding template to plan: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error adding template to plan. Please try again.'];
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meal Templates - EliteFit Gym</title>
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

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .filter-bar {
            background: var(--card-bg);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border);
            display: grid;
            grid-template-columns: 2fr repeat(4, 1fr) auto;
            gap: 1rem;
            align-items: end;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 0.75rem 0.75rem 2.5rem;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            background: var(--bg);
            color: var(--text);
            font-size: 0.875rem;
        }

        .search-box i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text);
            opacity: 0.5;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            background: var(--bg);
            color: var(--text);
            font-size: 0.875rem;
        }

        .templates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .template-card {
            background: var(--card-bg);
            border-radius: 1rem;
            overflow: hidden;
            border: 1px solid var(--border);
            transition: all 0.3s ease;
            position: relative;
        }

        .template-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .template-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
        }

        .template-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .template-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.8rem;
            color: var(--text);
            opacity: 0.7;
        }

        .template-tags {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .tag {
            padding: 0.25rem 0.5rem;
            background: var(--primary);
            color: white;
            border-radius: 0.25rem;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .dietary-tags {
            display: flex;
            gap: 0.25rem;
            margin-top: 0.5rem;
        }

        .dietary-tag {
            padding: 0.125rem 0.375rem;
            background: var(--success);
            color: white;
            border-radius: 0.25rem;
            font-size: 0.65rem;
        }

        .template-nutrition {
            padding: 1rem 1.5rem;
            background: var(--bg);
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }

        .nutrition-item {
            text-align: center;
        }

        .nutrition-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
        }

        .nutrition-label {
            font-size: 0.7rem;
            opacity: 0.7;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .template-actions {
            padding: 1rem 1.5rem;
            display: flex;
            gap: 0.5rem;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: 1rem;
            padding: 2rem;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text);
            opacity: 0.7;
        }

        .close-btn:hover {
            opacity: 1;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.875rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            background: var(--bg);
            color: var(--text);
            font-size: 0.875rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-item input[type="checkbox"] {
            width: 1rem;
            height: 1rem;
        }

        .difficulty-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .difficulty-easy {
            background: var(--success);
            color: white;
        }

        .difficulty-medium {
            background: var(--warning);
            color: white;
        }

        .difficulty-hard {
            background: var(--danger);
            color: white;
        }

        .category-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: capitalize;
            background: var(--info);
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text);
            opacity: 0.7;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .filter-bar {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .templates-grid {
                grid-template-columns: 1fr;
            }
            
            .template-nutrition {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .header-actions {
                width: 100%;
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-utensils"></i> Meal Templates</h1>
                <p>Create and manage nutrition meal templates for your clients</p>
            </div>
            <div class="header-actions">
                <button class="btn" onclick="openCreateTemplateModal()">
                    <i class="fas fa-plus"></i> Create Template
                </button>
                <a href="dashboard.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search templates..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <select id="categoryFilter" class="form-control">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat; ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                        <?php echo ucfirst($cat); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <select id="goalFilter" class="form-control">
                <option value="">All Goals</option>
                <?php foreach ($goals as $g): ?>
                    <option value="<?php echo $g; ?>" <?php echo $goal === $g ? 'selected' : ''; ?>>
                        <?php echo ucwords(str_replace('_', ' ', $g)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <select id="dietaryFilter" class="form-control">
                <option value="">All Dietary</option>
                <option value="vegetarian" <?php echo $dietary === 'vegetarian' ? 'selected' : ''; ?>>Vegetarian</option>
                <option value="vegan" <?php echo $dietary === 'vegan' ? 'selected' : ''; ?>>Vegan</option>
                <option value="gluten_free" <?php echo $dietary === 'gluten_free' ? 'selected' : ''; ?>>Gluten Free</option>
                <option value="dairy_free" <?php echo $dietary === 'dairy_free' ? 'selected' : ''; ?>>Dairy Free</option>
            </select>
            
            <select id="difficultyFilter" class="form-control">
                <option value="">All Difficulties</option>
                <?php foreach ($difficulties as $diff): ?>
                    <option value="<?php echo $diff; ?>" <?php echo $difficulty === $diff ? 'selected' : ''; ?>>
                        <?php echo ucfirst($diff); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <button class="btn btn-outline" onclick="clearFilters()">
                <i class="fas fa-times"></i> Clear
            </button>
        </div>

        <!-- Templates Grid -->
        <?php if (empty($templates)): ?>
            <div class="empty-state">
                <i class="fas fa-utensils"></i>
                <h3>No meal templates found</h3>
                <p>Try adjusting your filters or create a new template</p>
            </div>
        <?php else: ?>
            <div class="templates-grid">
                <?php foreach ($templates as $template): ?>
                    <div class="template-card">
                        <div class="template-header">
                            <div class="template-title"><?php echo htmlspecialchars($template['name']); ?></div>
                            <div class="template-meta">
                                <div class="meta-item">
                                    <i class="fas fa-clock"></i>
                                    <?php echo $template['prep_time'] + $template['cook_time']; ?> min
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-users"></i>
                                    <?php echo $template['servings']; ?> servings
                                </div>
                                <span class="category-badge"><?php echo $template['category']; ?></span>
                                <span class="difficulty-badge difficulty-<?php echo $template['difficulty']; ?>">
                                    <?php echo $template['difficulty']; ?>
                                </span>
                            </div>
                            
                            <?php 
                            $dietaryTags = [];
                            if ($template['is_vegetarian']) $dietaryTags[] = 'Vegetarian';
                            if ($template['is_vegan']) $dietaryTags[] = 'Vegan';
                            if ($template['is_gluten_free']) $dietaryTags[] = 'Gluten Free';
                            if ($template['is_dairy_free']) $dietaryTags[] = 'Dairy Free';
                            ?>
                            
                            <?php if (!empty($dietaryTags)): ?>
                                <div class="dietary-tags">
                                    <?php foreach ($dietaryTags as $tag): ?>
                                        <span class="dietary-tag"><?php echo $tag; ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="template-nutrition">
                            <div class="nutrition-item">
                                <div class="nutrition-value"><?php echo $template['calories']; ?></div>
                                <div class="nutrition-label">Calories</div>
                            </div>
                            <div class="nutrition-item">
                                <div class="nutrition-value"><?php echo $template['protein']; ?>g</div>
                                <div class="nutrition-label">Protein</div>
                            </div>
                            <div class="nutrition-item">
                                <div class="nutrition-value"><?php echo $template['carbs']; ?>g</div>
                                <div class="nutrition-label">Carbs</div>
                            </div>
                            <div class="nutrition-item">
                                <div class="nutrition-value"><?php echo $template['fats']; ?>g</div>
                                <div class="nutrition-label">Fats</div>
                            </div>
                        </div>
                        
                        <div class="template-actions">
                            <button class="btn btn-sm" onclick="viewTemplate(<?php echo $template['id']; ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="btn btn-sm btn-outline" onclick="addToPlan(<?php echo $template['id']; ?>)">
                                <i class="fas fa-plus"></i> Add to Plan
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Create Template Modal -->
    <div id="createTemplateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Create Custom Template</h2>
                <button class="close-btn" onclick="closeCreateTemplateModal()">&times;</button>
            </div>
            
            <form id="createTemplateForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Template Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-control" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat; ?>"><?php echo ucfirst($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Goal</label>
                        <select name="goal" class="form-control" required>
                            <option value="">Select Goal</option>
                            <?php foreach ($goals as $g): ?>
                                <option value="<?php echo $g; ?>"><?php echo ucwords(str_replace('_', ' ', $g)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Difficulty</label>
                        <select name="difficulty" class="form-control" required>
                            <option value="">Select Difficulty</option>
                            <?php foreach ($difficulties as $diff): ?>
                                <option value="<?php echo $diff; ?>"><?php echo ucfirst($diff); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prep Time (minutes)</label>
                        <input type="number" name="prep_time" class="form-control" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Cook Time (minutes)</label>
                        <input type="number" name="cook_time" class="form-control" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Servings</label>
                        <input type="number" name="servings" class="form-control" min="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Calories</label>
                        <input type="number" name="calories" class="form-control" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Protein (g)</label>
                        <input type="number" name="protein" class="form-control" min="0" step="0.1" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Carbs (g)</label>
                        <input type="number" name="carbs" class="form-control" min="0" step="0.1" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Fats (g)</label>
                        <input type="number" name="fats" class="form-control" min="0" step="0.1" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Fiber (g)</label>
                        <input type="number" name="fiber" class="form-control" min="0" step="0.1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Sugar (g)</label>
                        <input type="number" name="sugar" class="form-control" min="0" step="0.1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Sodium (mg)</label>
                        <input type="number" name="sodium" class="form-control" min="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Ingredients</label>
                    <textarea name="ingredients" class="form-control" placeholder="List ingredients, one per line..." required></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Instructions</label>
                    <textarea name="instructions" class="form-control" placeholder="Step-by-step cooking instructions..." required></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Tags (comma-separated)</label>
                    <input type="text" name="tags" class="form-control" placeholder="healthy, quick, protein-rich">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Dietary Restrictions</label>
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" name="is_vegetarian" id="is_vegetarian">
                            <label for="is_vegetarian">Vegetarian</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="is_vegan" id="is_vegan">
                            <label for="is_vegan">Vegan</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="is_gluten_free" id="is_gluten_free">
                            <label for="is_gluten_free">Gluten Free</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="is_dairy_free" id="is_dairy_free">
                            <label for="is_dairy_free">Dairy Free</label>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                    <button type="button" class="btn btn-outline" onclick="closeCreateTemplateModal()">Cancel</button>
                    <button type="submit" class="btn">Create Template</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Template Modal -->
    <div id="viewTemplateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="viewTemplateTitle">Template Details</h2>
                <button class="close-btn" onclick="closeViewTemplateModal()">&times;</button>
            </div>
            <div id="viewTemplateContent">
                <!-- Template details will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Add to Plan Modal -->
    <div id="addToPlanModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Add to Nutrition Plan</h2>
                <button class="close-btn" onclick="closeAddToPlanModal()">&times;</button>
            </div>
            
            <form id="addToPlanForm">
                <input type="hidden" id="selectedTemplateId" name="template_id">
                
                <div class="form-group">
                    <label class="form-label">Select Nutrition Plan</label>
                    <select name="nutrition_plan_id" class="form-control" required>
                        <option value="">Choose a plan...</option>
                        <?php foreach ($nutritionPlans as $plan): ?>
                            <option value="<?php echo $plan['id']; ?>">
                                <?php echo htmlspecialchars($plan['plan_name'] . ' - ' . $plan['member_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Day of Week</label>
                    <select name="day_of_week" class="form-control" required>
                        <option value="">Select day...</option>
                        <option value="monday">Monday</option>
                        <option value="tuesday">Tuesday</option>
                        <option value="wednesday">Wednesday</option>
                        <option value="thursday">Thursday</option>
                        <option value="friday">Friday</option>
                        <option value="saturday">Saturday</option>
                        <option value="sunday">Sunday</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                    <button type="button" class="btn btn-outline" onclick="closeAddToPlanModal()">Cancel</button>
                    <button type="submit" class="btn">Add to Plan</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Filter functionality
        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const category = document.getElementById('categoryFilter').value;
            const goal = document.getElementById('goalFilter').value;
            const dietary = document.getElementById('dietaryFilter').value;
            const difficulty = document.getElementById('difficultyFilter').value;
            
            const params = new URLSearchParams();
            if (search) params.append('search', search);
            if (category) params.append('category', category);
            if (goal) params.append('goal', goal);
            if (dietary) params.append('dietary', dietary);
            if (difficulty) params.append('difficulty', difficulty);
            
            window.location.href = 'meal-templates.php?' + params.toString();
        }

        function clearFilters() {
            window.location.href = 'meal-templates.php';
        }

        // Event listeners for filters
        document.getElementById('searchInput').addEventListener('input', debounce(applyFilters, 500));
        document.getElementById('categoryFilter').addEventListener('change', applyFilters);
        document.getElementById('goalFilter').addEventListener('change', applyFilters);
        document.getElementById('dietaryFilter').addEventListener('change', applyFilters);
        document.getElementById('difficultyFilter').addEventListener('change', applyFilters);

        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Modal functions
        function openCreateTemplateModal() {
            document.getElementById('createTemplateModal').classList.add('active');
        }

        function closeCreateTemplateModal() {
            document.getElementById('createTemplateModal').classList.remove('active');
            document.getElementById('createTemplateForm').reset();
        }

        function closeViewTemplateModal() {
            document.getElementById('viewTemplateModal').classList.remove('active');
        }

        function closeAddToPlanModal() {
            document.getElementById('addToPlanModal').classList.remove('active');
        }

        // Template functions
        function viewTemplate(templateId) {
            fetch('meal-templates.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax=1&action=get_template&template_id=${templateId}`
            })
            .then(response => response.json())
            .then(template => {
                if (template) {
                    document.getElementById('viewTemplateTitle').textContent = template.name;
                    
                    const dietaryTags = [];
                    if (template.is_vegetarian == 1) dietaryTags.push('Vegetarian');
                    if (template.is_vegan == 1) dietaryTags.push('Vegan');
                    if (template.is_gluten_free == 1) dietaryTags.push('Gluten Free');
                    if (template.is_dairy_free == 1) dietaryTags.push('Dairy Free');
                    
                    document.getElementById('viewTemplateContent').innerHTML = `
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                            <div>
                                <strong>Category:</strong> ${template.category}
                            </div>
                            <div>
                                <strong>Goal:</strong> ${template.goal.replace('_', ' ')}
                            </div>
                            <div>
                                <strong>Difficulty:</strong> ${template.difficulty}
                            </div>
                            <div>
                                <strong>Prep Time:</strong> ${template.prep_time} min
                            </div>
                            <div>
                                <strong>Cook Time:</strong> ${template.cook_time} min
                            </div>
                            <div>
                                <strong>Servings:</strong> ${template.servings}
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; padding: 1rem; background: var(--bg); border-radius: 0.5rem;">
                            <div style="text-align: center;">
                                <div style="font-size: 1.2rem; font-weight: 600; color: var(--primary);">${template.calories}</div>
                                <div style="font-size: 0.8rem; opacity: 0.7;">Calories</div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-size: 1.2rem; font-weight: 600; color: var(--primary);">${template.protein}g</div>
                                <div style="font-size: 0.8rem; opacity: 0.7;">Protein</div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-size: 1.2rem; font-weight: 600; color: var(--primary);">${template.carbs}g</div>
                                <div style="font-size: 0.8rem; opacity: 0.7;">Carbs</div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-size: 1.2rem; font-weight: 600; color: var(--primary);">${template.fats}g</div>
                                <div style="font-size: 0.8rem; opacity: 0.7;">Fats</div>
                            </div>
                            ${template.fiber ? `<div style="text-align: center;">
                                <div style="font-size: 1.2rem; font-weight: 600; color: var(--primary);">${template.fiber}g</div>
                                <div style="font-size: 0.8rem; opacity: 0.7;">Fiber</div>
                            </div>` : ''}
                        </div>
                        
                        ${dietaryTags.length > 0 ? `
                        <div style="margin-bottom: 1.5rem;">
                            <strong>Dietary:</strong>
                            ${dietaryTags.map(tag => `<span style="display: inline-block; padding: 0.25rem 0.5rem; background: var(--success); color: white; border-radius: 0.25rem; font-size: 0.8rem; margin-left: 0.5rem;">${tag}</span>`).join('')}
                        </div>
                        ` : ''}
                        
                        <div style="margin-bottom: 1.5rem;">
                            <h4 style="margin-bottom: 0.5rem;">Ingredients:</h4>
                            <div style="white-space: pre-line; background: var(--bg); padding: 1rem; border-radius: 0.5rem;">${template.ingredients}</div>
                        </div>
                        
                        <div>
                            <h4 style="margin-bottom: 0.5rem;">Instructions:</h4>
                            <div style="white-space: pre-line; background: var(--bg); padding: 1rem; border-radius: 0.5rem;">${template.instructions}</div>
                        </div>
                    `;
                    
                    document.getElementById('viewTemplateModal').classList.add('active');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading template details');
            });
        }

        function addToPlan(templateId) {
            document.getElementById('selectedTemplateId').value = templateId;
            document.getElementById('addToPlanModal').classList.add('active');
        }

        // Form submissions
        document.getElementById('createTemplateForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax', '1');
            formData.append('action', 'create_custom_template');
            
            fetch('meal-templates.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert(result.message);
                    closeCreateTemplateModal();
                    location.reload();
                } else {
                    alert(result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error creating template');
            });
        });

        document.getElementById('addToPlanForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax', '1');
            formData.append('action', 'add_to_plan');
            
            fetch('meal-templates.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert(result.message);
                    closeAddToPlanModal();
                } else {
                    alert(result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error adding template to plan');
            });
        });

        // Close modals when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
            }
        });
    </script>
</body>
</html>
