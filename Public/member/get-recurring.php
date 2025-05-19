<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Member role to access this page
requireRole('Member');

// Get user data
$userId = $_SESSION['user_id'];

// Connect to database
$conn = connectDB();

// Get recurring ID from URL
$recurringId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Initialize response array
$response = [
    'success' => false,
    'recurring' => null,
    'error' => null
];

try {
    // Get recurring appointment details
    $stmt = $conn->prepare("
        SELECT * FROM recurring_appointments
        WHERE id = ? AND member_id = ?
    ");
    $stmt->execute([$recurringId, $userId]);
    $recurring = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($recurring) {
        $response['success'] = true;
        $response['recurring'] = $recurring;
    } else {
        $response['error'] = 'Recurring appointment not found.';
    }
} catch (PDOException $e) {
    $response['error'] = 'Database error: ' . $e->getMessage();
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
