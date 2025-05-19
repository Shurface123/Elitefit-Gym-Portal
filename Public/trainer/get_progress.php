<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Trainer role to access this page
requireRole('Trainer');

// Get user data
$userId = $_SESSION['user_id'];

// Connect to database
$conn = connectDB();

// Get progress ID from URL parameter
$progressId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Initialize response array
$response = [
    'success' => false,
    'progress' => null
];

try {
    // Get progress details
    $stmt = $conn->prepare("
        SELECT * FROM progress_tracking
        WHERE id = ? AND trainer_id = ?
    ");
    $stmt->execute([$progressId, $userId]);
    $progress = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($progress) {
        $response['success'] = true;
        $response['progress'] = $progress;
    }
} catch (PDOException $e) {
    $response['error'] = 'Database error: ' . $e->getMessage();
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
