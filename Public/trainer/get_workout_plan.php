<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Trainer role to access this page
requireRole('Trainer');

// Get user data
$userId = $_SESSION['user_id'];

// Connect to database
$conn = connectDB();

// Get plan ID from URL parameter
$planId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Initialize response array
$response = [
    'success' => false,
    'plan' => null,
    'exercises' => []
];

try {
    // Get plan details
    $stmt = $conn->prepare("
        SELECT wp.*, u.name as member_name, u.profile_image
        FROM workout_plans wp
        LEFT JOIN users u ON wp.member_id = u.id
        WHERE wp.id = ? AND wp.trainer_id = ?
    ");
    $stmt->execute([$planId, $userId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($plan) {
        $response['success'] = true;
        $response['plan'] = $plan;
        
        // Get exercises
        $stmt = $conn->prepare("
            SELECT * FROM workout_exercises
            WHERE workout_plan_id = ?
            ORDER BY day_number, exercise_order
        ");
        $stmt->execute([$planId]);
        $response['exercises'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $response['error'] = 'Database error: ' . $e->getMessage();
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
