<?php
session_start();

// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Check if user is logged in and is an equipment manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'equipment_manager') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

// Get equipment data for edit modal
if (isset($_GET['action']) && $_GET['action'] == 'get_equipment' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    $query = "SELECT * FROM equipment WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        header('Content-Type: application/json');
        echo json_encode($row);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Equipment not found']);
    }
    exit();
}

// Get detailed equipment data for view modal
if (isset($_GET['action']) && $_GET['action'] == 'get_equipment_details' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // Get equipment data
    $query = "SELECT * FROM equipment WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $equipment = $result->fetch_assoc();
    
    if (!$equipment) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Equipment not found']);
        exit();
    }
    
    // Get maintenance history
    $maintenance_query = "SELECT * FROM maintenance WHERE equipment_id = ? ORDER BY maintenance_date DESC";
    $stmt = $conn->prepare($maintenance_query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $maintenance_result = $stmt->get_result();
    $maintenance_history = [];
    while ($row = $maintenance_result->fetch_assoc()) {
        $maintenance_history[] = $row;
    }
    
    // Get usage history
    $usage_query = "SELECT eu.*, u.username as user_name 
                   FROM equipment_usage eu 
                   LEFT JOIN users u ON eu.user_id = u.id 
                   WHERE eu.equipment_id = ? 
                   ORDER BY eu.usage_date DESC";
    $stmt = $conn->prepare($usage_query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $usage_result = $stmt->get_result();
    $usage_history = [];
    while ($row = $usage_result->fetch_assoc()) {
        $usage_history[] = $row;
    }
    
    // Combine all data
    $equipment['maintenance_history'] = $maintenance_history;
    $equipment['usage_history'] = $usage_history;
    
    header('Content-Type: application/json');
    echo json_encode($equipment);
    exit();
}

// Add new equipment
if (isset($_POST['action']) && $_POST['action'] == 'add_equipment') {
    // Validate required fields
    $required_fields = ['name', 'type', 'serial_number', 'purchase_date', 'purchase_cost', 'status', 'location'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Missing required field: ' . $field]);
            exit();
        }
    }
    
    // Get form data
    $name = $conn->real_escape_string($_POST['name']);
    $type = $conn->real_escape_string($_POST['type']);
    $serial_number = $conn->real_escape_string($_POST['serial_number']);
    $purchase_date = $conn->real_escape_string($_POST['purchase_date']);
    $purchase_cost = floatval($_POST['purchase_cost']);
    $status = $conn->real_escape_string($_POST['status']);
    $location = $conn->real_escape_string($_POST['location']);
    $notes = isset($_POST['notes']) ? $conn->real_escape_string($_POST['notes']) : '';
    
    // Check if serial number already exists
    $check_query = "SELECT id FROM equipment WHERE serial_number = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("s", $serial_number);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Serial number already exists']);
        exit();
    }
    
    // Insert new equipment
    $insert_query = "INSERT INTO equipment (name, type, serial_number, purchase_date, purchase_cost, status, location, notes, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("ssssdsss", $name, $type, $serial_number, $purchase_date, $purchase_cost, $status, $location, $notes);
    
    if ($stmt->execute()) {
        $equipment_id = $stmt->insert_id;
        
        // Log equipment creation in history
        $history_query = "INSERT INTO equipment_history (equipment_id, action, user_id, new_value, created_at) 
                         VALUES (?, 'created', ?, ?, NOW())";
        $new_value = json_encode([
            'name' => $name,
            'type' => $type,
            'serial_number' => $serial_number,
            'purchase_date' => $purchase_date,
            'purchase_cost' => $purchase_cost,
            'status' => $status,
            'location' => $location,
            'notes' => $notes
        ]);
        $stmt = $conn->prepare($history_query);
        $user_id = $_SESSION['user_id'];
        $stmt->bind_param("iis", $equipment_id, $user_id, $new_value);
        $stmt->execute();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'id' => $equipment_id]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to add equipment: ' . $conn->error]);
    }
    exit();
}

// Update equipment
if (isset($_POST['action']) && $_POST['action'] == 'update_equipment') {
    // Validate required fields
    $required_fields = ['id', 'name', 'type', 'serial_number', 'purchase_date', 'purchase_cost', 'status', 'location'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Missing required field: ' . $field]);
            exit();
        }
    }
    
    // Get form data
    $id = intval($_POST['id']);
    $name = $conn->real_escape_string($_POST['name']);
    $type = $conn->real_escape_string($_POST['type']);
    $serial_number = $conn->real_escape_string($_POST['serial_number']);
    $purchase_date = $conn->real_escape_string($_POST['purchase_date']);
    $purchase_cost = floatval($_POST['purchase_cost']);
    $status = $conn->real_escape_string($_POST['status']);
    $location = $conn->real_escape_string($_POST['location']);
    $notes = isset($_POST['notes']) ? $conn->real_escape_string($_POST['notes']) : '';
    
    // Check if serial number already exists for another equipment
    $check_query = "SELECT id FROM equipment WHERE serial_number = ? AND id != ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("si", $serial_number, $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Serial number already exists for another equipment']);
        exit();
    }
    
    // Get current equipment data for history
    $current_query = "SELECT * FROM equipment WHERE id = ?";
    $stmt = $conn->prepare($current_query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $current_data = $result->fetch_assoc();
    
    // Update equipment
    $update_query = "UPDATE equipment 
                    SET name = ?, type = ?, serial_number = ?, purchase_date = ?, 
                        purchase_cost = ?, status = ?, location = ?, notes = ?, updated_at = NOW() 
                    WHERE id = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("ssssdsssi", $name, $type, $serial_number, $purchase_date, $purchase_cost, $status, $location, $notes, $id);
    
    if ($stmt->execute()) {
        // Log equipment update in history
        $history_query = "INSERT INTO equipment_history (equipment_id, action, user_id, old_value, new_value, created_at) 
                         VALUES (?, 'updated', ?, ?, ?, NOW())";
        $old_value = json_encode($current_data);
        $new_value = json_encode([
            'name' => $name,
            'type' => $type,
            'serial_number' => $serial_number,
            'purchase_date' => $purchase_date,
            'purchase_cost' => $purchase_cost,
            'status' => $status,
            'location' => $location,
            'notes' => $notes
        ]);
        $stmt = $conn->prepare($history_query);
        $user_id = $_SESSION['user_id'];
        $stmt->bind_param("iiss", $id, $user_id, $old_value, $new_value);
        $stmt->execute();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to update equipment: ' . $conn->error]);
    }
    exit();
}

// Handle AJAX requests for maintenance management
if (isset($_GET['action']) && $_GET['action'] == 'get_maintenance' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    $query = "SELECT * FROM maintenance WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        header('Content-Type: application/json');
        echo json_encode($row);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Maintenance record not found']);
    }
    exit();
}

// Update maintenance status
if (isset($_POST['action']) && $_POST['action'] == 'update_maintenance_status') {
    $maintenance_id = intval($_POST['maintenance_id']);
    $status = $conn->real_escape_string($_POST['status']);
    $completion_date = null;
    $cost = null;
    
    if ($status == 'completed') {
        $completion_date = $conn->real_escape_string($_POST['completion_date']);
        $cost = floatval($_POST['cost']);
    }
    
    $update_query = "UPDATE maintenance SET 
                    status = ?, 
                    completion_date = ?, 
                    cost = ?, 
                    updated_at = NOW() 
                    WHERE id = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("ssdi", $status, $completion_date, $cost, $maintenance_id);
    
    if ($stmt->execute()) {
        // If maintenance is completed, update equipment status
        if ($status == 'completed') {
            // Get equipment ID
            $equipment_query = "SELECT equipment_id FROM maintenance WHERE id = ?";
            $stmt = $conn->prepare($equipment_query);
            $stmt->bind_param("i", $maintenance_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $equipment_id = $row['equipment_id'];
            
            // Update equipment status and last_maintenance_date
            $equipment_update = "UPDATE equipment SET 
                               status = 'available', 
                               last_maintenance_date = ?, 
                               updated_at = NOW() 
                               WHERE id = ?";
            $stmt = $conn->prepare($equipment_update);
            $stmt->bind_param("si", $completion_date, $equipment_id);
            $stmt->execute();
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to update maintenance status']);
    }
    exit();
}

// Get equipment types for autocomplete
if (isset($_GET['action']) && $_GET['action'] == 'get_equipment_types') {
    $query = "SELECT DISTINCT type FROM equipment ORDER BY type";
    $result = $conn->query($query);
    $types = [];
    while ($row = $result->fetch_assoc()) {
        $types[] = $row['type'];
    }
    
    header('Content-Type: application/json');
    echo json_encode($types);
    exit();
}

// Get equipment locations for autocomplete
if (isset($_GET['action']) && $_GET['action'] == 'get_equipment_locations') {
    $query = "SELECT DISTINCT location FROM equipment ORDER BY location";
    $result = $conn->query($query);
    $locations = [];
    while ($row = $result->fetch_assoc()) {
        $locations[] = $row['location'];
    }
    
    header('Content-Type: application/json');
    echo json_encode($locations);
    exit();
}

// Default response for invalid requests
header('Content-Type: application/json');
echo json_encode(['error' => 'Invalid request']);
exit();
?>
