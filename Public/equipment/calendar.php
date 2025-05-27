<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require EquipmentManager role to access this page
requireRole('EquipmentManager');

// Include theme helper
require_once 'dashboard-theme-helper.php';

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'] ?? 'Equipment Manager';

// Get theme preference (default to dark)
$theme = getThemePreference($userId) ?: 'dark';
$themeClasses = getThemeClasses($theme);

// Connect to database
$conn = connectDB();

// Enhanced equipment list with additional details
$equipmentStmt = $conn->prepare("
    SELECT e.id, e.name, e.type, e.status, e.location, e.purchase_date,
           COUNT(m.id) as maintenance_count,
           MAX(m.scheduled_date) as last_maintenance,
           CASE 
               WHEN e.status = 'Active' THEN 'available'
               WHEN e.status = 'Maintenance' THEN 'maintenance'
               WHEN e.status = 'Out of Order' THEN 'unavailable'
               ELSE 'unknown'
           END as availability_status
    FROM equipment e
    LEFT JOIN maintenance_schedule m ON e.id = m.equipment_id
    GROUP BY e.id, e.name, e.type, e.status, e.location, e.purchase_date
    ORDER BY e.name
");
$equipmentStmt->execute();
$equipmentList = $equipmentStmt->fetchAll(PDO::FETCH_ASSOC);

// Enhanced maintenance types
$maintenanceTypes = [
    'Routine Inspection' => 'Regular scheduled inspection',
    'Preventive Maintenance' => 'Preventive care to avoid issues',
    'Corrective Maintenance' => 'Fix identified problems',
    'Emergency Repair' => 'Urgent repair needed',
    'Deep Cleaning' => 'Thorough cleaning and sanitization',
    'Calibration' => 'Equipment calibration and adjustment',
    'Parts Replacement' => 'Replace worn or damaged parts',
    'Software Update' => 'Update equipment software/firmware',
    'Safety Inspection' => 'Safety compliance check',
    'Performance Testing' => 'Test equipment performance'
];

// Get staff list with roles and specializations
$staffStmt = $conn->prepare("
    SELECT u.id, u.name, u.role, u.email,
           COALESCE(u.specialization, 'General') as specialization,
           'Basic' as certification
    FROM users u
    WHERE u.role IN ('EquipmentManager', 'Maintenance', 'Technician', 'Admin')
    ORDER BY u.name
");
$staffStmt->execute();
$staffList = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'create':
            try {
                $title = trim($_POST['title']);
                $start = $_POST['start'];
                $end = $_POST['end'] ?? null;
                $allDay = isset($_POST['allDay']) ? (int)$_POST['allDay'] : 0;
                $equipmentId = $_POST['equipment_id'] ?? null;
                $eventType = $_POST['event_type'];
                $description = trim($_POST['description'] ?? '');
                $priority = $_POST['priority'] ?? 'Medium';
                $assignedTo = $_POST['assigned_to'] ?? null;
                $color = $_POST['color'] ?? '#ff6b35';
                $location = trim($_POST['location'] ?? '');
                $estimatedDuration = $_POST['estimated_duration'] ?? null;
                $estimatedCost = $_POST['estimated_cost'] ?? null;
                $notes = trim($_POST['notes'] ?? '');
                $reminderTime = $_POST['reminder_time'] ?? null;
                $recurrence = $_POST['recurrence'] ?? 'none';
                $tags = $_POST['tags'] ?? '';
                
                if ($eventType === 'maintenance') {
                    // Create maintenance schedule with enhanced fields
                    $stmt = $conn->prepare("
                        INSERT INTO maintenance_schedule 
                        (equipment_id, scheduled_date, maintenance_type, description, priority, 
                         assigned_to, status, estimated_duration, estimated_cost, location, notes, 
                         reminder_time, recurrence_pattern, tags, created_by, created_at) 
                        VALUES (:equipment_id, :scheduled_date, :maintenance_type, :description, :priority,
                                :assigned_to, 'Scheduled', :estimated_duration, :estimated_cost, :location, :notes,
                                :reminder_time, :recurrence_pattern, :tags, :created_by, NOW())
                    ");
                    $stmt->execute([
                        ':equipment_id' => $equipmentId,
                        ':scheduled_date' => $start,
                        ':maintenance_type' => $title,
                        ':description' => $description,
                        ':priority' => $priority,
                        ':assigned_to' => $assignedTo,
                        ':estimated_duration' => $estimatedDuration,
                        ':estimated_cost' => $estimatedCost,
                        ':location' => $location,
                        ':notes' => $notes,
                        ':reminder_time' => $reminderTime,
                        ':recurrence_pattern' => $recurrence,
                        ':tags' => $tags,
                        ':created_by' => $userId
                    ]);
                    
                    $eventId = $conn->lastInsertId();
                    
                    // Update equipment status if maintenance is scheduled
                    if ($equipmentId) {
                        $updateStmt = $conn->prepare("
                            UPDATE equipment 
                            SET status = CASE 
                                WHEN :priority = 'High' THEN 'Maintenance'
                                ELSE status 
                            END,
                            next_maintenance_date = :scheduled_date
                            WHERE id = :equipment_id
                        ");
                        $updateStmt->execute([
                            ':priority' => $priority,
                            ':scheduled_date' => $start,
                            ':equipment_id' => $equipmentId
                        ]);
                    }
                    
                    // Send notification if assigned to someone
                    if ($assignedTo) {
                        $notificationStmt = $conn->prepare("
                            INSERT INTO notifications (user_id, title, message, type, related_id, priority, created_at)
                            VALUES (:user_id, :title, :message, 'maintenance', :related_id, :priority, NOW())
                        ");
                        $equipmentName = '';
                        if ($equipmentId) {
                            $nameStmt = $conn->prepare("SELECT name FROM equipment WHERE id = :id");
                            $nameStmt->execute([':id' => $equipmentId]);
                            $equipmentName = $nameStmt->fetchColumn();
                        }
                        
                        $notificationStmt->execute([
                            ':user_id' => $assignedTo,
                            ':title' => 'New Maintenance Assignment',
                            ':message' => "You have been assigned {$title} for {$equipmentName} on " . date('M d, Y', strtotime($start)),
                            ':related_id' => $eventId,
                            ':priority' => $priority
                        ]);
                    }
                    
                    // Create recurring events if specified
                    if ($recurrence !== 'none') {
                        $this->createRecurringEvents($eventId, $recurrence, $start, $end, $title, $equipmentId, $assignedTo);
                    }
                    
                } else {
                    // Create calendar event with enhanced fields
                    $stmt = $conn->prepare("
                        INSERT INTO calendar_events 
                        (title, start_date, end_date, all_day, equipment_id, event_type, description, 
                         priority, assigned_to, color, location, estimated_duration, estimated_cost, notes,
                         reminder_time, recurrence_pattern, tags, created_by, created_at) 
                        VALUES (:title, :start_date, :end_date, :all_day, :equipment_id, :event_type, :description,
                                :priority, :assigned_to, :color, :location, :estimated_duration, :estimated_cost, :notes,
                                :reminder_time, :recurrence_pattern, :tags, :created_by, NOW())
                    ");
                    $stmt->execute([
                        ':title' => $title,
                        ':start_date' => $start,
                        ':end_date' => $end,
                        ':all_day' => $allDay,
                        ':equipment_id' => $equipmentId,
                        ':event_type' => $eventType,
                        ':description' => $description,
                        ':priority' => $priority,
                        ':assigned_to' => $assignedTo,
                        ':color' => $color,
                        ':location' => $location,
                        ':estimated_duration' => $estimatedDuration,
                        ':estimated_cost' => $estimatedCost,
                        ':notes' => $notes,
                        ':reminder_time' => $reminderTime,
                        ':recurrence_pattern' => $recurrence,
                        ':tags' => $tags,
                        ':created_by' => $userId
                    ]);
                    
                    $eventId = $conn->lastInsertId();
                }
                
                // Log activity with detailed information
                $logStmt = $conn->prepare("
                    INSERT INTO activity_log (user_id, equipment_id, action, details, timestamp)
                    VALUES (:user_id, :equipment_id, :action, :details, NOW())
                ");
                $equipmentName = '';
                if ($equipmentId) {
                    $nameStmt = $conn->prepare("SELECT name FROM equipment WHERE id = :id");
                    $nameStmt->execute([':id' => $equipmentId]);
                    $equipmentName = $nameStmt->fetchColumn();
                }
                
                $action = "Created {$eventType} event: {$title}";
                $details = json_encode([
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'equipment_name' => $equipmentName,
                    'priority' => $priority,
                    'assigned_to' => $assignedTo,
                    'estimated_cost' => $estimatedCost
                ]);
                
                $logStmt->execute([
                    ':user_id' => $userId,
                    ':equipment_id' => $equipmentId,
                    ':action' => $action,
                    ':details' => $details
                ]);
                
                echo json_encode([
                    'success' => true,
                    'id' => $eventId,
                    'message' => 'Event created successfully',
                    'event_type' => $eventType
                ]);
            } catch (PDOException $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error creating event: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'update':
            try {
                $id = $_POST['id'];
                $title = trim($_POST['title']);
                $start = $_POST['start'];
                $end = $_POST['end'] ?? null;
                $allDay = isset($_POST['allDay']) ? (int)$_POST['allDay'] : 0;
                $eventType = $_POST['event_type'];
                $description = trim($_POST['description'] ?? '');
                $priority = $_POST['priority'] ?? 'Medium';
                $assignedTo = $_POST['assigned_to'] ?? null;
                $status = $_POST['status'] ?? 'Scheduled';
                $location = trim($_POST['location'] ?? '');
                $estimatedDuration = $_POST['estimated_duration'] ?? null;
                $estimatedCost = $_POST['estimated_cost'] ?? null;
                $actualDuration = $_POST['actual_duration'] ?? null;
                $actualCost = $_POST['actual_cost'] ?? null;
                $notes = trim($_POST['notes'] ?? '');
                $completionNotes = trim($_POST['completion_notes'] ?? '');
                
                if ($eventType === 'maintenance') {
                    $stmt = $conn->prepare("
                        UPDATE maintenance_schedule 
                        SET scheduled_date = :scheduled_date, maintenance_type = :maintenance_type,
                            description = :description, priority = :priority, assigned_to = :assigned_to,
                            status = :status, estimated_duration = :estimated_duration, 
                            estimated_cost = :estimated_cost, actual_duration = :actual_duration,
                            actual_cost = :actual_cost, location = :location, notes = :notes,
                            completion_notes = :completion_notes, updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':scheduled_date' => $start,
                        ':maintenance_type' => $title,
                        ':description' => $description,
                        ':priority' => $priority,
                        ':assigned_to' => $assignedTo,
                        ':status' => $status,
                        ':estimated_duration' => $estimatedDuration,
                        ':estimated_cost' => $estimatedCost,
                        ':actual_duration' => $actualDuration,
                        ':actual_cost' => $actualCost,
                        ':location' => $location,
                        ':notes' => $notes,
                        ':completion_notes' => $completionNotes,
                        ':id' => $id
                    ]);
                    
                    // Update equipment status based on maintenance status
                    if ($status === 'Completed') {
                        $equipmentStmt = $conn->prepare("
                            UPDATE equipment 
                            SET status = 'Active', 
                                last_maintenance_date = :completion_date,
                                maintenance_count = COALESCE(maintenance_count, 0) + 1
                            WHERE id = (SELECT equipment_id FROM maintenance_schedule WHERE id = :id)
                        ");
                        $equipmentStmt->execute([
                            ':completion_date' => date('Y-m-d'),
                            ':id' => $id
                        ]);
                    }
                } else {
                    $stmt = $conn->prepare("
                        UPDATE calendar_events 
                        SET title = :title, start_date = :start_date, end_date = :end_date,
                            all_day = :all_day, description = :description, priority = :priority,
                            assigned_to = :assigned_to, location = :location, 
                            estimated_duration = :estimated_duration, estimated_cost = :estimated_cost, 
                            notes = :notes, updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':title' => $title,
                        ':start_date' => $start,
                        ':end_date' => $end,
                        ':all_day' => $allDay,
                        ':description' => $description,
                        ':priority' => $priority,
                        ':assigned_to' => $assignedTo,
                        ':location' => $location,
                        ':estimated_duration' => $estimatedDuration,
                        ':estimated_cost' => $estimatedCost,
                        ':notes' => $notes,
                        ':id' => $id
                    ]);
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Event updated successfully'
                ]);
            } catch (PDOException $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error updating event: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'delete':
            try {
                $id = $_POST['id'];
                $eventType = $_POST['event_type'];
                
                if ($eventType === 'maintenance') {
                    // Get equipment info before deletion
                    $infoStmt = $conn->prepare("
                        SELECT m.equipment_id, e.name as equipment_name, m.maintenance_type
                        FROM maintenance_schedule m
                        LEFT JOIN equipment e ON m.equipment_id = e.id
                        WHERE m.id = :id
                    ");
                    $infoStmt->execute([':id' => $id]);
                    $info = $infoStmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Delete maintenance schedule
                    $stmt = $conn->prepare("DELETE FROM maintenance_schedule WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                    
                    // Update equipment status if needed
                    if ($info && $info['equipment_id']) {
                        $updateStmt = $conn->prepare("
                            UPDATE equipment 
                            SET status = CASE 
                                WHEN status = 'Maintenance' THEN 'Active'
                                ELSE status 
                            END
                            WHERE id = :equipment_id
                        ");
                        $updateStmt->execute([':equipment_id' => $info['equipment_id']]);
                    }
                    
                    // Log activity
                    if ($info) {
                        $logStmt = $conn->prepare("
                            INSERT INTO activity_log (user_id, equipment_id, action, details, timestamp)
                            VALUES (:user_id, :equipment_id, :action, :details, NOW())
                        ");
                        $action = "Deleted maintenance: " . $info['maintenance_type'];
                        $details = json_encode([
                            'equipment_name' => $info['equipment_name'],
                            'maintenance_type' => $info['maintenance_type']
                        ]);
                        $logStmt->execute([
                            ':user_id' => $userId,
                            ':equipment_id' => $info['equipment_id'],
                            ':action' => $action,
                            ':details' => $details
                        ]);
                    }
                } else {
                    // Delete calendar event
                    $stmt = $conn->prepare("DELETE FROM calendar_events WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Event deleted successfully'
                ]);
            } catch (PDOException $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error deleting event: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'get_events':
            try {
                $start = $_POST['start'];
                $end = $_POST['end'];
                $equipmentFilter = $_POST['equipment'] ?? [];
                $typeFilter = $_POST['event_type'] ?? [];
                $priorityFilter = $_POST['priority'] ?? [];
                $statusFilter = $_POST['status'] ?? [];
                $assignedFilter = $_POST['assigned'] ?? [];
                
                $events = [];
                
                // Get maintenance events with enhanced data
                $maintenanceQuery = "
                    SELECT m.id, m.scheduled_date, m.maintenance_type, m.description, m.priority, m.status,
                           m.estimated_duration, m.estimated_cost, m.actual_duration, m.actual_cost,
                           m.location, m.notes, m.tags,
                           e.name as equipment_name, e.id as equipment_id, e.type as equipment_type,
                           u.name as assigned_name, u.id as assigned_id,
                           creator.name as created_by_name,
                           CASE 
                               WHEN m.status != 'Completed' AND m.scheduled_date < CURDATE() THEN 'Overdue'
                               ELSE m.status
                           END as display_status,
                           DATEDIFF(CURDATE(), m.scheduled_date) as days_overdue
                    FROM maintenance_schedule m
                    LEFT JOIN equipment e ON m.equipment_id = e.id
                    LEFT JOIN users u ON m.assigned_to = u.id
                    LEFT JOIN users creator ON m.created_by = creator.id
                    WHERE m.scheduled_date BETWEEN :start AND :end
                ";
                
                $params = [':start' => $start, ':end' => $end];
                
                // Apply filters
                if (!empty($equipmentFilter) && !in_array('all', $equipmentFilter)) {
                    $placeholders = str_repeat('?,', count($equipmentFilter) - 1) . '?';
                    $maintenanceQuery .= " AND m.equipment_id IN ($placeholders)";
                    $params = array_merge($params, $equipmentFilter);
                }
                
                if (!empty($priorityFilter) && !in_array('all', $priorityFilter)) {
                    $placeholders = str_repeat('?,', count($priorityFilter) - 1) . '?';
                    $maintenanceQuery .= " AND m.priority IN ($placeholders)";
                    $params = array_merge($params, $priorityFilter);
                }
                
                if (!empty($statusFilter) && !in_array('all', $statusFilter)) {
                    if (in_array('Overdue', $statusFilter)) {
                        $maintenanceQuery .= " AND (m.status IN (" . str_repeat('?,', count($statusFilter) - 1) . "?) OR (m.status != 'Completed' AND m.scheduled_date < CURDATE()))";
                        $params = array_merge($params, array_filter($statusFilter, function($s) { return $s !== 'Overdue'; }));
                    } else {
                        $placeholders = str_repeat('?,', count($statusFilter) - 1) . '?';
                        $maintenanceQuery .= " AND m.status IN ($placeholders)";
                        $params = array_merge($params, $statusFilter);
                    }
                }
                
                if (!empty($assignedFilter) && !in_array('all', $assignedFilter)) {
                    $placeholders = str_repeat('?,', count($assignedFilter) - 1) . '?';
                    $maintenanceQuery .= " AND m.assigned_to IN ($placeholders)";
                    $params = array_merge($params, $assignedFilter);
                }
                
                $stmt = $conn->prepare($maintenanceQuery);
                $stmt->execute($params);
                $maintenanceEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($maintenanceEvents as $event) {
                    // Determine color based on priority and status
                    $color = '#dc3545'; // Default red for maintenance
                    if ($event['display_status'] === 'Overdue') {
                        $color = '#6f42c1'; // Purple for overdue
                    } elseif ($event['display_status'] === 'Completed') {
                        $color = '#28a745'; // Green for completed
                    } elseif ($event['display_status'] === 'In Progress') {
                        $color = '#fd7e14'; // Orange for in progress
                    } else {
                        switch ($event['priority']) {
                            case 'High':
                                $color = '#dc3545'; // Red
                                break;
                            case 'Medium':
                                $color = '#fd7e14'; // Orange
                                break;
                            case 'Low':
                                $color = '#ffc107'; // Yellow
                                break;
                        }
                    }
                    
                    $events[] = [
                        'id' => 'm_' . $event['id'],
                        'title' => $event['maintenance_type'] . ($event['equipment_name'] ? ' - ' . $event['equipment_name'] : ''),
                        'start' => $event['scheduled_date'],
                        'allDay' => true,
                        'backgroundColor' => $color,
                        'borderColor' => $color,
                        'classNames' => [
                            'event-maintenance', 
                            'priority-' . strtolower($event['priority']),
                            'status-' . strtolower(str_replace(' ', '-', $event['display_status']))
                        ],
                        'extendedProps' => [
                            'equipment_id' => $event['equipment_id'],
                            'equipment_name' => $event['equipment_name'],
                            'equipment_type' => $event['equipment_type'],
                            'description' => $event['description'],
                            'priority' => $event['priority'],
                            'status' => $event['status'],
                            'display_status' => $event['display_status'],
                            'assigned_name' => $event['assigned_name'],
                            'assigned_id' => $event['assigned_id'],
                            'created_by_name' => $event['created_by_name'],
                            'estimated_duration' => $event['estimated_duration'],
                            'estimated_cost' => $event['estimated_cost'],
                            'actual_duration' => $event['actual_duration'],
                            'actual_cost' => $event['actual_cost'],
                            'location' => $event['location'],
                            'notes' => $event['notes'],
                            'tags' => $event['tags'],
                            'days_overdue' => $event['days_overdue'],
                            'event_type' => 'maintenance'
                        ]
                    ];
                }
                
                // Get calendar events with enhanced data
                $eventsQuery = "
                    SELECT c.id, c.title, c.start_date, c.end_date, c.all_day, c.event_type, 
                           c.description, c.priority, c.color, c.location, c.notes, c.tags,
                           c.estimated_duration, c.estimated_cost,
                           e.name as equipment_name, e.id as equipment_id, e.type as equipment_type,
                           u.name as assigned_name, u.id as assigned_id,
                           creator.name as created_by_name
                    FROM calendar_events c
                    LEFT JOIN equipment e ON c.equipment_id = e.id
                    LEFT JOIN users u ON c.assigned_to = u.id
                    LEFT JOIN users creator ON c.created_by = creator.id
                    WHERE c.start_date BETWEEN :start AND :end
                ";
                
                $params = [':start' => $start, ':end' => $end];
                
                // Apply filters for calendar events
                if (!empty($equipmentFilter) && !in_array('all', $equipmentFilter)) {
                    $placeholders = str_repeat('?,', count($equipmentFilter) - 1) . '?';
                    $eventsQuery .= " AND c.equipment_id IN ($placeholders)";
                    $params = array_merge($params, $equipmentFilter);
                }
                
                if (!empty($typeFilter) && !in_array('all', $typeFilter)) {
                    $placeholders = str_repeat('?,', count($typeFilter) - 1) . '?';
                    $eventsQuery .= " AND c.event_type IN ($placeholders)";
                    $params = array_merge($params, $typeFilter);
                }
                
                $stmt = $conn->prepare($eventsQuery);
                $stmt->execute($params);
                $calendarEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($calendarEvents as $event) {
                    // Determine color based on event type
                    $color = $event['color'] ?: '#ff6b35';
                    switch ($event['event_type']) {
                        case 'booking':
                            $color = $event['color'] ?: '#ff6b35';
                            break;
                        case 'training':
                            $color = $event['color'] ?: '#28a745';
                            break;
                        case 'inspection':
                            $color = $event['color'] ?: '#17a2b8';
                            break;
                        case 'meeting':
                            $color = $event['color'] ?: '#6f42c1';
                            break;
                        default:
                            $color = $event['color'] ?: '#6c757d';
                    }
                    
                    $events[] = [
                        'id' => 'e_' . $event['id'],
                        'title' => $event['title'],
                        'start' => $event['start_date'],
                        'end' => $event['end_date'],
                        'allDay' => $event['all_day'] == 1,
                        'backgroundColor' => $color,
                        'borderColor' => $color,
                        'classNames' => ['event-' . $event['event_type']],
                        'extendedProps' => [
                            'equipment_id' => $event['equipment_id'],
                            'equipment_name' => $event['equipment_name'],
                            'equipment_type' => $event['equipment_type'],
                            'description' => $event['description'],
                            'priority' => $event['priority'],
                            'location' => $event['location'],
                            'notes' => $event['notes'],
                            'tags' => $event['tags'],
                            'assigned_name' => $event['assigned_name'],
                            'assigned_id' => $event['assigned_id'],
                            'created_by_name' => $event['created_by_name'],
                            'estimated_duration' => $event['estimated_duration'],
                            'estimated_cost' => $event['estimated_cost'],
                            'event_type' => $event['event_type']
                        ]
                    ];
                }
                
                echo json_encode(['events' => $events]);
            } catch (PDOException $e) {
                echo json_encode(['events' => [], 'error' => $e->getMessage()]);
            }
            break;
            
        case 'get_statistics':
            try {
                $statsStmt = $conn->prepare("
                    SELECT 
                        COUNT(CASE WHEN m.status = 'Scheduled' THEN 1 END) as scheduled_maintenance,
                        COUNT(CASE WHEN m.status = 'In Progress' THEN 1 END) as in_progress_maintenance,
                        COUNT(CASE WHEN m.status = 'Completed' AND m.scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as completed_maintenance,
                        COUNT(CASE WHEN m.status != 'Completed' AND m.scheduled_date < CURDATE() THEN 1 END) as overdue_maintenance,
                        COUNT(CASE WHEN m.priority = 'High' AND m.status != 'Completed' THEN 1 END) as high_priority_maintenance,
                        AVG(CASE WHEN m.status = 'Completed' AND m.actual_cost IS NOT NULL THEN m.actual_cost END) as avg_maintenance_cost,
                        SUM(CASE WHEN m.status = 'Completed' AND m.actual_cost IS NOT NULL THEN m.actual_cost END) as total_maintenance_cost,
                        (SELECT COUNT(*) FROM calendar_events WHERE start_date >= CURDATE() AND start_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)) as upcoming_events,
                        (SELECT COUNT(DISTINCT equipment_id) FROM maintenance_schedule WHERE scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as equipment_maintained
                    FROM maintenance_schedule m
                ");
                $statsStmt->execute();
                $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'stats' => $stats]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action'
            ]);
    }
    exit;
}

// Get calendar statistics for dashboard
$statsStmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN m.status = 'Scheduled' THEN 1 END) as scheduled_maintenance,
        COUNT(CASE WHEN m.status = 'In Progress' THEN 1 END) as in_progress_maintenance,
        COUNT(CASE WHEN m.status = 'Completed' AND m.scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as completed_maintenance,
        COUNT(CASE WHEN m.status != 'Completed' AND m.scheduled_date < CURDATE() THEN 1 END) as overdue_maintenance,
        COUNT(CASE WHEN m.priority = 'High' AND m.status != 'Completed' THEN 1 END) as high_priority_maintenance,
        (SELECT COUNT(*) FROM calendar_events WHERE start_date >= CURDATE() AND start_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)) as upcoming_events,
        (SELECT COUNT(DISTINCT equipment_id) FROM maintenance_schedule WHERE scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as equipment_maintained,
        (SELECT AVG(actual_cost) FROM maintenance_schedule WHERE status = 'Completed' AND actual_cost IS NOT NULL AND scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)) as avg_maintenance_cost
    FROM maintenance_schedule m
");
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Ensure calendar_events table exists with enhanced structure
try {
    $checkStmt = $conn->prepare("SHOW TABLES LIKE 'calendar_events'");
    $checkStmt->execute();
    if ($checkStmt->rowCount() == 0) {
        $createTableSQL = "
            CREATE TABLE calendar_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                start_date DATETIME NOT NULL,
                end_date DATETIME NULL,
                all_day TINYINT(1) DEFAULT 0,
                equipment_id INT NULL,
                event_type VARCHAR(50) NOT NULL DEFAULT 'other',
                description TEXT NULL,
                priority ENUM('Low', 'Medium', 'High') DEFAULT 'Medium',
                assigned_to INT NULL,
                color VARCHAR(20) DEFAULT '#ff6b35',
                location VARCHAR(255) NULL,
                estimated_duration DECIMAL(4,2) NULL,
                estimated_cost DECIMAL(10,2) NULL,
                notes TEXT NULL,
                reminder_time DATETIME NULL,
                recurrence_pattern VARCHAR(50) NULL,
                tags VARCHAR(500) NULL,
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE SET NULL,
                FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_start_date (start_date),
                INDEX idx_equipment_id (equipment_id),
                INDEX idx_event_type (event_type),
                INDEX idx_assigned_to (assigned_to)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        $conn->exec($createTableSQL);
    }
} catch (PDOException $e) {
    error_log("Error checking/creating calendar_events table: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipment Calendar - EliteFit Gym</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/main.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.min.css">
    <style>
        :root {
            /* Enhanced Black & Orange Theme */
            --primary: #ff6b35;
            --primary-dark: #e55a2b;
            --primary-light: #ff8c5a;
            --primary-very-light: #fff4f0;
            --accent: #ff9500;
            --accent-dark: #e6850e;
            
            /* Dark Theme Colors */
            --dark-bg: #0a0a0a;
            --dark-surface: #1a1a1a;
            --dark-surface-light: #2d2d2d;
            --dark-surface-hover: #3a3a3a;
            --dark-border: #404040;
            --dark-text: #ffffff;
            --dark-text-secondary: #b3b3b3;
            --dark-text-muted: #888888;
            
            /* Light Theme Colors */
            --light-bg: #fafafa;
            --light-surface: #ffffff;
            --light-surface-alt: #f5f5f5;
            --light-border: #e0e0e0;
            --light-text: #1a1a1a;
            --light-text-secondary: #666666;
            
            /* Status Colors */
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --purple: #8b5cf6;
            
            /* Priority Colors */
            --priority-high: #ef4444;
            --priority-medium: #f59e0b;
            --priority-low: #10b981;
            
            /* Spacing & Effects */
            --border-radius: 12px;
            --border-radius-lg: 16px;
            --border-radius-xl: 20px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-fast: all 0.15s ease-out;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--dark-bg);
            color: var(--dark-text);
            line-height: 1.6;
            transition: var(--transition);
            overflow-x: hidden;
        }
        
        body.light-theme {
            background-color: var(--light-bg);
            color: var(--light-text);
        }
        
        /* Enhanced Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 280px;
            background: linear-gradient(180deg, var(--dark-surface) 0%, var(--dark-surface-light) 100%);
            border-right: 1px solid var(--dark-border);
            z-index: 1000;
            transition: var(--transition);
            overflow-y: auto;
            backdrop-filter: blur(10px);
        }
        
        .light-theme .sidebar {
            background: linear-gradient(180deg, var(--light-surface) 0%, var(--light-surface-alt) 100%);
            border-right: 1px solid var(--light-border);
        }
        
        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid var(--dark-border);
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
        }
        
        .light-theme .sidebar-header {
            border-bottom: 1px solid var(--light-border);
        }
        
        .sidebar-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 1.5rem;
            right: 1.5rem;
            height: 2px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 1px;
        }
        
        .sidebar-logo {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
            box-shadow: var(--shadow);
        }
        
        .sidebar-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.025em;
        }
        
        .sidebar-subtitle {
            font-size: 0.875rem;
            color: var(--dark-text-secondary);
            font-weight: 500;
        }
        
        .light-theme .sidebar-subtitle {
            color: var(--light-text-secondary);
        }
        
        .sidebar-nav {
            padding: 1.5rem 0;
        }
        
        .nav-section {
            margin-bottom: 2.5rem;
        }
        
        .nav-section-title {
            padding: 0 1.5rem 1rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--dark-text-secondary);
            position: relative;
        }
        
        .light-theme .nav-section-title {
            color: var(--light-text-secondary);
        }
        
        .nav-section-title::after {
            content: '';
            position: absolute;
            bottom: 0.5rem;
            left: 1.5rem;
            width: 30px;
            height: 2px;
            background: var(--primary);
            border-radius: 1px;
            opacity: 0.3;
        }
        
        .nav-item {
            margin: 0.25rem 1rem;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.25rem;
            border-radius: var(--border-radius);
            color: var(--dark-text-secondary);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .light-theme .nav-link {
            color: var(--light-text-secondary);
        }
        
        .nav-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 107, 53, 0.1), transparent);
            transition: var(--transition);
        }
        
        .nav-link:hover {
            background-color: rgba(255, 107, 53, 0.1);
            color: var(--primary);
            transform: translateX(8px);
        }
        
        .nav-link:hover::before {
            left: 100%;
        }
        
        .nav-link.active {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            box-shadow: var(--shadow-lg);
            transform: translateX(4px);
        }
        
        .nav-link.active::after {
            content: '';
            position: absolute;
            left: -1rem;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: white;
            border-radius: 0 4px 4px 0;
        }
        
        .nav-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        
        /* Enhanced Main Content */
        .main-content {
            margin-left: 280px;
            min-height: 100vh;
            background-color: var(--dark-bg);
            transition: var(--transition);
        }
        
        .light-theme .main-content {
            background-color: var(--light-bg);
        }
        
        /* Enhanced Header */
        .header {
            background: rgba(26, 26, 26, 0.95);
            border-bottom: 1px solid var(--dark-border);
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            backdrop-filter: blur(20px);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow);
        }
        
        .light-theme .header {
            background: rgba(255, 255, 255, 0.95);
            border-bottom: 1px solid var(--light-border);
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .header-title {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark-text);
            letter-spacing: -0.025em;
        }
        
        .light-theme .header-title {
            color: var(--light-text);
        }
        
        .header-subtitle {
            font-size: 1rem;
            color: var(--dark-text-secondary);
            font-weight: 500;
            margin-top: 0.25rem;
        }
        
        .light-theme .header-subtitle {
            color: var(--light-text-secondary);
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        /* Enhanced Theme Toggle */
        .theme-toggle {
            position: relative;
            width: 64px;
            height: 32px;
            background: var(--dark-surface-light);
            border-radius: 16px;
            border: 1px solid var(--dark-border);
            cursor: pointer;
            transition: var(--transition);
            overflow: hidden;
        }
        
        .light-theme .theme-toggle {
            background: var(--light-surface-alt);
            border: 1px solid var(--light-border);
        }
        
        .theme-toggle.active {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-color: var(--primary);
            box-shadow: 0 0 20px rgba(255, 107, 53, 0.3);
        }
        
        .theme-toggle-slider {
            position: absolute;
            top: 2px;
            left: 2px;
            width: 28px;
            height: 28px;
            background: white;
            border-radius: 50%;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            color: var(--dark-text);
            box-shadow: var(--shadow);
        }
        
        .theme-toggle.active .theme-toggle-slider {
            transform: translateX(32px);
            color: var(--primary);
        }
        
        /* Enhanced User Menu */
        .user-menu {
            position: relative;
        }
        
        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: var(--border-radius);
            border: 3px solid var(--primary);
            cursor: pointer;
            transition: var(--transition);
            object-fit: cover;
        }
        
        .user-avatar:hover {
            transform: scale(1.05);
            box-shadow: 0 0 0 6px rgba(255, 107, 53, 0.2);
        }
        
        /* Enhanced Cards */
        .card {
            background: var(--dark-surface);
            border: 1px solid var(--dark-border);
            border-radius: var(--border-radius-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .light-theme .card {
            background: var(--light-surface);
            border: 1px solid var(--light-border);
        }
        
        .card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-xl);
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }
        
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--dark-border);
        }
        
        .light-theme .card-header {
            border-bottom: 1px solid var(--light-border);
        }
        
        .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-text);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .light-theme .card-title {
            color: var(--light-text);
        }
        
        .card-title i {
            color: var(--primary);
        }
        
        /* Enhanced Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--dark-surface);
            border: 1px solid var(--dark-border);
            border-radius: var(--border-radius-lg);
            padding: 2rem;
            text-align: center;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .light-theme .stat-card {
            background: var(--light-surface);
            border: 1px solid var(--light-border);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }
        
        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-xl);
        }
        
        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
        }
        
        .stat-icon {
            width: 64px;
            height: 64px;
            border-radius: var(--border-radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            color: white;
            margin: 0 auto 1.5rem;
            transition: var(--transition);
            position: relative;
        }
        
        .stat-icon::after {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: var(--border-radius-lg);
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            opacity: 0;
            transition: var(--transition);
        }
        
        .stat-card:hover .stat-icon::after {
            opacity: 1;
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 900;
            line-height: 1;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-label {
            font-size: 1rem;
            color: var(--dark-text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .light-theme .stat-label {
            color: var(--light-text-secondary);
        }
        
        /* Enhanced Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1.75rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.875rem;
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: var(--transition);
        }
        
        .btn:hover::before {
            left: 100%;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            box-shadow: var(--shadow);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-secondary {
            background: var(--dark-surface-light);
            color: var(--dark-text);
            border: 1px solid var(--dark-border);
        }
        
        .light-theme .btn-secondary {
            background: var(--light-surface-alt);
            color: var(--light-text);
            border: 1px solid var(--light-border);
        }
        
        .btn-secondary:hover {
            background: var(--dark-surface-hover);
            transform: translateY(-2px);
        }
        
        .light-theme .btn-secondary:hover {
            background: var(--light-border);
        }
        
        .btn-sm {
            padding: 0.625rem 1.25rem;
            font-size: 0.8rem;
        }
        
        /* Enhanced Form Controls */
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.75rem;
            font-weight: 600;
            color: var(--dark-text);
            font-size: 0.875rem;
        }
        
        .light-theme .form-label {
            color: var(--light-text);
        }
        
        .form-control {
            width: 100%;
            padding: 1rem 1.25rem;
            background: var(--dark-surface-light);
            border: 1px solid var(--dark-border);
            border-radius: var(--border-radius);
            color: var(--dark-text);
            font-size: 0.875rem;
            transition: var(--transition);
        }
        
        .light-theme .form-control {
            background: var(--light-surface);
            border: 1px solid var(--light-border);
            color: var(--light-text);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.1);
            transform: translateY(-1px);
        }
        
        /* Filter Panel */
        .filter-panel {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .filter-actions {
            display: flex;
            align-items: flex-end;
            gap: 1rem;
            grid-column: 1 / -1;
            justify-content: center;
        }
        
        /* Enhanced Calendar Styles */
        .calendar-container {
            height: calc(100vh - 500px);
            min-height: 700px;
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }
        
        .fc {
            height: 100%;
            font-family: 'Inter', sans-serif;
        }
        
        .fc-toolbar {
            padding: 1.5rem;
            background: var(--dark-surface-light);
            border-bottom: 1px solid var(--dark-border);
        }
        
        .light-theme .fc-toolbar {
            background: var(--light-surface-alt);
            border-bottom: 1px solid var(--light-border);
        }
        
        .fc-toolbar-title {
            font-size: 1.75rem !important;
            font-weight: 800 !important;
            color: var(--dark-text) !important;
            letter-spacing: -0.025em !important;
        }
        
        .light-theme .fc-toolbar-title {
            color: var(--light-text) !important;
        }
        
        .fc .fc-button {
            background: var(--dark-surface) !important;
            border: 1px solid var(--dark-border) !important;
            color: var(--dark-text) !important;
            font-weight: 600 !important;
            border-radius: var(--border-radius) !important;
            padding: 0.75rem 1.25rem !important;
            transition: var(--transition) !important;
        }
        
        .light-theme .fc .fc-button {
            background: var(--light-surface) !important;
            border: 1px solid var(--light-border) !important;
            color: var(--light-text) !important;
        }
        
        .fc .fc-button:hover {
            background: var(--primary) !important;
            border-color: var(--primary) !important;
            color: white !important;
            transform: translateY(-2px) !important;
            box-shadow: var(--shadow) !important;
        }
        
        .fc .fc-button-primary:not(:disabled).fc-button-active,
        .fc .fc-button-primary:not(:disabled):active {
            background: var(--primary) !important;
            border-color: var(--primary) !important;
            color: white !important;
            box-shadow: var(--shadow) !important;
        }
        
        .fc-day-today {
            background-color: rgba(255, 107, 53, 0.1) !important;
        }
        
        .fc-daygrid-day:hover {
            background-color: rgba(255, 107, 53, 0.05) !important;
        }
        
        .fc-event {
            cursor: pointer !important;
            border-radius: 8px !important;
            padding: 4px 8px !important;
            font-size: 0.8rem !important;
            font-weight: 600 !important;
            border: none !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
            transition: var(--transition-fast) !important;
        }
        
        .fc-event:hover {
            transform: translateY(-2px) scale(1.02) !important;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25) !important;
            z-index: 10 !important;
        }
        
        .fc-event-title {
            font-weight: 700 !important;
        }
        
        .fc-event-time {
            font-weight: 500 !important;
            opacity: 0.9 !important;
        }
        
        /* Dark theme calendar adjustments */
        .dark-theme .fc-theme-standard .fc-scrollgrid,
        .dark-theme .fc-theme-standard td,
        .dark-theme .fc-theme-standard th {
            border-color: var(--dark-border) !important;
        }
        
        .dark-theme .fc-col-header-cell-cushion,
        .dark-theme .fc-daygrid-day-number,
        .dark-theme .fc-timegrid-slot-label-cushion {
            color: var(--dark-text) !important;
        }
        
        .dark-theme .fc-scrollgrid-sync-table {
            background: var(--dark-surface) !important;
        }
        
        .dark-theme .fc-daygrid-body,
        .dark-theme .fc-timegrid-body {
            background: var(--dark-surface) !important;
        }
        
        /* Event Type Styles with Enhanced Gradients */
        .event-maintenance {
            background: linear-gradient(135deg, var(--danger), #c82333) !important;
        }
        
        .event-booking {
            background: linear-gradient(135deg, var(--primary), var(--accent)) !important;
        }
        
        .event-training {
            background: linear-gradient(135deg, var(--success), #218838) !important;
        }
        
        .event-inspection {
            background: linear-gradient(135deg, var(--info), #0056b3) !important;
        }
        
        .event-meeting {
            background: linear-gradient(135deg, var(--purple), #7c3aed) !important;
        }
        
        .event-other {
            background: linear-gradient(135deg, #6c757d, #545b62) !important;
        }
        
        /* Priority Indicators */
        .priority-high {
            border-left: 6px solid var(--priority-high) !important;
            box-shadow: 0 0 15px rgba(239, 68, 68, 0.3) !important;
        }
        
        .priority-medium {
            border-left: 6px solid var(--priority-medium) !important;
            box-shadow: 0 0 15px rgba(245, 158, 11, 0.3) !important;
        }
        
        .priority-low {
            border-left: 6px solid var(--priority-low) !important;
            box-shadow: 0 0 15px rgba(16, 185, 129, 0.3) !important;
        }
        
        /* Status Indicators */
        .status-overdue {
            animation: pulse-danger 2s infinite;
        }
        
        .status-in-progress {
            animation: pulse-warning 2s infinite;
        }
        
        .status-completed {
            opacity: 0.8;
            filter: grayscale(20%);
        }
        
        @keyframes pulse-danger {
            0%, 100% { box-shadow: 0 0 15px rgba(239, 68, 68, 0.3); }
            50% { box-shadow: 0 0 25px rgba(239, 68, 68, 0.6); }
        }
        
        @keyframes pulse-warning {
            0%, 100% { box-shadow: 0 0 15px rgba(245, 158, 11, 0.3); }
            50% { box-shadow: 0 0 25px rgba(245, 158, 11, 0.6); }
        }
        
        /* Event Legend */
        .event-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.875rem;
            font-weight: 600;
            padding: 0.75rem 1rem;
            background: var(--dark-surface-light);
            border-radius: var(--border-radius);
            transition: var(--transition);
        }
        
        .light-theme .legend-item {
            background: var(--light-surface-alt);
        }
        
        .legend-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        
        /* Color Picker */
        .color-picker {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1rem;
        }
        
        .color-option {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            border: 3px solid transparent;
            transition: var(--transition);
            position: relative;
            box-shadow: var(--shadow);
        }
        
        .color-option:hover {
            transform: scale(1.15);
            box-shadow: var(--shadow-lg);
        }
        
        .color-option.selected {
            border-color: var(--dark-text);
            transform: scale(1.2);
            box-shadow: var(--shadow-xl);
        }
        
        .light-theme .color-option.selected {
            border-color: var(--light-text);
        }
        
        .color-option.selected::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-weight: bold;
            font-size: 1rem;
            text-shadow: 0 0 4px rgba(0, 0, 0, 0.8);
        }
        
        /* Enhanced Select2 Styling */
        .select2-container--default .select2-selection--single,
        .select2-container--default .select2-selection--multiple {
            background-color: var(--dark-surface-light) !important;
            border: 1px solid var(--dark-border) !important;
            border-radius: var(--border-radius) !important;
            min-height: 48px !important;
            transition: var(--transition) !important;
        }
        
        .light-theme .select2-container--default .select2-selection--single,
        .light-theme .select2-container--default .select2-selection--multiple {
            background-color: var(--light-surface) !important;
            border: 1px solid var(--light-border) !important;
        }
        
        .select2-container--default .select2-selection--single:hover,
        .select2-container--default .select2-selection--multiple:hover {
            border-color: var(--primary) !important;
            transform: translateY(-1px) !important;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: var(--dark-text) !important;
            line-height: 46px !important;
            padding-left: 1rem !important;
        }
        
        .light-theme .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: var(--light-text) !important;
        }
        
        .select2-dropdown {
            background-color: var(--dark-surface) !important;
            border: 1px solid var(--dark-border) !important;
            border-radius: var(--border-radius) !important;
            box-shadow: var(--shadow-lg) !important;
        }
        
        .light-theme .select2-dropdown {
            background-color: var(--light-surface) !important;
            border: 1px solid var(--light-border) !important;
        }
        
        .select2-results__option {
            color: var(--dark-text) !important;
            padding: 1rem 1.25rem !important;
            transition: var(--transition-fast) !important;
        }
        
        .light-theme .select2-results__option {
            color: var(--light-text) !important;
        }
        
        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: var(--primary) !important;
            color: white !important;
        }
        
        /* Enhanced SweetAlert2 Styling */
        .swal2-popup {
            background-color: var(--dark-surface) !important;
            color: var(--dark-text) !important;
            border-radius: var(--border-radius-lg) !important;
            box-shadow: var(--shadow-xl) !important;
            border: 1px solid var(--dark-border) !important;
        }
        
        .light-theme .swal2-popup {
            background-color: var(--light-surface) !important;
            color: var(--light-text) !important;
            border: 1px solid var(--light-border) !important;
        }
        
        .swal2-title {
            color: var(--dark-text) !important;
            font-weight: 700 !important;
        }
        
        .light-theme .swal2-title {
            color: var(--light-text) !important;
        }
        
        .swal2-input,
        .swal2-textarea,
        .swal2-select {
            background-color: var(--dark-surface-light) !important;
            border: 1px solid var(--dark-border) !important;
            color: var(--dark-text) !important;
            border-radius: var(--border-radius) !important;
            padding: 0.75rem 1rem !important;
        }
        
        .light-theme .swal2-input,
        .light-theme .swal2-textarea,
        .swal2-select {
            background-color: var(--light-surface) !important;
            border: 1px solid var(--light-border) !important;
            color: var(--light-text) !important;
        }
        
        /* Enhanced Modal Styling */
        .event-modal .swal2-html-container {
            max-height: 70vh;
            overflow-y: auto;
            padding: 0 !important;
        }
        
        .event-form {
            display: grid;
            gap: 1.5rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .form-section {
            background: var(--dark-surface-light);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            border: 1px solid var(--dark-border);
        }
        
        .light-theme .form-section {
            background: var(--light-surface-alt);
            border: 1px solid var(--light-border);
        }
        
        .form-section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Responsive Design */
        @media (max-width: 1400px) {
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }
        
        @media (max-width: 1200px) {
            .filter-panel {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 100%;
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .header {
                padding: 1rem;
            }
            
            .header-title {
                font-size: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 1rem;
            }
            
            .filter-panel {
                grid-template-columns: 1fr;
            }
            
            .calendar-container {
                height: calc(100vh - 600px);
                min-height: 500px;
            }
            
            .fc-toolbar {
                flex-direction: column !important;
                gap: 1rem !important;
                padding: 1rem !important;
            }
            
            .fc-toolbar-chunk {
                display: flex !important;
                justify-content: center !important;
                width: 100% !important;
            }
            
            .event-legend {
                flex-direction: column;
                gap: 1rem;
            }
            
            .card {
                padding: 1.5rem;
            }
        }
        
        @media (max-width: 480px) {
            .header-left {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .header-right {
                gap: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .card {
                padding: 1rem;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        /* Loading States */
        .loading {
            opacity: 0.6;
            pointer-events: none;
            position: relative;
        }
        
        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 32px;
            height: 32px;
            border: 3px solid var(--dark-border);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: translate(-50%, -50%) rotate(360deg); }
        }
        
        /* Utility Classes */
        .text-primary { color: var(--primary) !important; }
        .text-success { color: var(--success) !important; }
        .text-warning { color: var(--warning) !important; }
        .text-danger { color: var(--danger) !important; }
        .text-info { color: var(--info) !important; }
        .text-purple { color: var(--purple) !important; }
        
        .bg-primary { background-color: var(--primary) !important; }
        .bg-success { background-color: var(--success) !important; }
        .bg-warning { background-color: var(--warning) !important; }
        .bg-danger { background-color: var(--danger) !important; }
        .bg-info { background-color: var(--info) !important; }
        .bg-purple { background-color: var(--purple) !important; }
        
        .d-none { display: none !important; }
        .d-flex { display: flex !important; }
        .d-grid { display: grid !important; }
        
        .text-center { text-align: center !important; }
        .text-right { text-align: right !important; }
        
        .mb-0 { margin-bottom: 0 !important; }
        .mb-1 { margin-bottom: 0.5rem !important; }
        .mb-2 { margin-bottom: 1rem !important; }
        .mb-3 { margin-bottom: 1.5rem !important; }
        
        .mt-0 { margin-top: 0 !important; }
        .mt-1 { margin-top: 0.5rem !important; }
        .mt-2 { margin-top: 1rem !important; }
        .mt-3 { margin-top: 1.5rem !important; }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--dark-surface);
        }
        
        .light-theme ::-webkit-scrollbar-track {
            background: var(--light-surface-alt);
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--dark-border);
            border-radius: 4px;
        }
        
        .light-theme ::-webkit-scrollbar-thumb {
            background: var(--light-border);
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary);
        }
    </style>
</head>
<body class="<?php echo $theme === 'dark' ? '' : 'light-theme'; ?>">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <i class="fas fa-dumbbell"></i>
            </div>
            <div>
                <div class="sidebar-title">EliteFit</div>
                <div class="sidebar-subtitle">Equipment Manager</div>
            </div>
        </div>
        
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-title">Main</div>
                <div class="nav-item">
                    <a href="dashboard.php" class="nav-link">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="equipment.php" class="nav-link">
                        <i class="nav-icon fas fa-dumbbell"></i>
                        <span>Equipment</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="maintenance.php" class="nav-link">
                        <i class="nav-icon fas fa-tools"></i>
                        <span>Maintenance</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="inventory.php" class="nav-link">
                        <i class="nav-icon fas fa-boxes"></i>
                        <span>Inventory</span>
                    </a>
                </div>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Analytics</div>
                <div class="nav-item">
                    <a href="reports.php" class="nav-link">
                        <i class="nav-icon fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="analytics.php" class="nav-link">
                        <i class="nav-icon fas fa-chart-line"></i>
                        <span>Analytics</span>
                    </a>
                </div>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Tools</div>
                <div class="nav-item">
                    <a href="calendar.php" class="nav-link active">
                        <i class="nav-icon fas fa-calendar-alt"></i>
                        <span>Calendar</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="settings.php" class="nav-link">
                        <i class="nav-icon fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                </div>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <button class="btn btn-secondary btn-sm d-md-none" id="sidebar-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h1 class="header-title">Equipment Calendar</h1>
                    <p class="header-subtitle">Advanced scheduling and maintenance management</p>
                </div>
            </div>
            
            <div class="header-right">
                <div class="theme-toggle <?php echo $theme === 'dark' ? 'active' : ''; ?>" id="theme-toggle">
                    <div class="theme-toggle-slider">
                        <i class="fas fa-<?php echo $theme === 'dark' ? 'moon' : 'sun'; ?>"></i>
                    </div>
                </div>
                
                <div class="user-menu">
                    <img src="https://randomuser.me/api/portraits/men/3.jpg" alt="User Avatar" class="user-avatar" id="user-avatar">
                </div>
            </div>
        </header>
        
        <!-- Statistics Dashboard -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon bg-warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $stats['scheduled_maintenance'] ?? 0; ?></div>
                <div class="stat-label">Scheduled Maintenance</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon bg-info">
                    <i class="fas fa-cog"></i>
                </div>
                <div class="stat-value"><?php echo $stats['in_progress_maintenance'] ?? 0; ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon bg-success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['completed_maintenance'] ?? 0; ?></div>
                <div class="stat-label">Completed (30 days)</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon bg-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['overdue_maintenance'] ?? 0; ?></div>
                <div class="stat-label">Overdue</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon bg-purple">
                    <i class="fas fa-star"></i>
                </div>
                <div class="stat-value"><?php echo $stats['high_priority_maintenance'] ?? 0; ?></div>
                <div class="stat-label">High Priority</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon bg-primary">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-value"><?php echo $stats['upcoming_events'] ?? 0; ?></div>
                <div class="stat-label">Upcoming Events</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--accent), var(--primary));">
                    <i class="fas fa-tools"></i>
                </div>
                <div class="stat-value"><?php echo $stats['equipment_maintained'] ?? 0; ?></div>
                <div class="stat-label">Equipment Maintained</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--success), #059669);">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stat-value">$<?php echo number_format($stats['avg_maintenance_cost'] ?? 0, 0); ?></div>
                <div class="stat-label">Avg Maintenance Cost</div>
            </div>
        </div>
        
        <!-- Event Legend -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-palette"></i>
                    Event Types & Legend
                </h3>
                <button id="add-event-btn" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Event
                </button>
            </div>
            <div class="event-legend">
                <div class="legend-item">
                    <div class="legend-color" style="background: linear-gradient(135deg, var(--danger), #c82333);"></div>
                    <span>Maintenance</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: linear-gradient(135deg, var(--primary), var(--accent));"></div>
                    <span>Equipment Booking</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: linear-gradient(135deg, var(--success), #218838);"></div>
                    <span>Training Session</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: linear-gradient(135deg, var(--info), #0056b3);"></div>
                    <span>Inspection</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: linear-gradient(135deg, var(--purple), #7c3aed);"></div>
                    <span>Meeting</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: linear-gradient(135deg, #6c757d, #545b62);"></div>
                    <span>Other Events</span>
                </div>
            </div>
        </div>
        
        <!-- Advanced Filters -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-filter"></i>
                    Advanced Filters
                </h3>
                <div class="filter-actions">
                    <button id="apply-filters" class="btn btn-primary btn-sm">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <button id="reset-filters" class="btn btn-secondary btn-sm">
                        <i class="fas fa-sync-alt"></i> Reset All
                    </button>
                    <button id="export-calendar" class="btn btn-secondary btn-sm">
                        <i class="fas fa-download"></i> Export
                    </button>
                </div>
            </div>
            <div class="filter-panel">
                <div class="form-group">
                    <label for="equipment-filter" class="form-label">Equipment</label>
                    <select id="equipment-filter" class="form-control select2" multiple>
                        <option value="all" selected>All Equipment</option>
                        <?php foreach ($equipmentList as $equipment): ?>
                            <option value="<?php echo $equipment['id']; ?>" 
                                    data-type="<?php echo htmlspecialchars($equipment['type']); ?>"
                                    data-status="<?php echo htmlspecialchars($equipment['availability_status']); ?>">
                                <?php echo htmlspecialchars($equipment['name']); ?> 
                                (<?php echo htmlspecialchars($equipment['type']); ?>)
                                <?php if ($equipment['location']): ?>
                                    - <?php echo htmlspecialchars($equipment['location']); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="event-type-filter" class="form-label">Event Type</label>
                    <select id="event-type-filter" class="form-control select2" multiple>
                        <option value="all" selected>All Events</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="booking">Equipment Booking</option>
                        <option value="training">Training Session</option>
                        <option value="inspection">Inspection</option>
                        <option value="meeting">Meeting</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="priority-filter" class="form-label">Priority</label>
                    <select id="priority-filter" class="form-control select2" multiple>
                        <option value="all" selected>All Priorities</option>
                        <option value="High">High Priority</option>
                        <option value="Medium">Medium Priority</option>
                        <option value="Low">Low Priority</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="status-filter" class="form-label">Status</label>
                    <select id="status-filter" class="form-control select2" multiple>
                        <option value="all" selected>All Status</option>
                        <option value="Scheduled">Scheduled</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="Overdue">Overdue</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="assigned-filter" class="form-label">Assigned To</label>
                    <select id="assigned-filter" class="form-control select2" multiple>
                        <option value="all" selected>All Staff</option>
                        <?php foreach ($staffList as $staff): ?>
                            <option value="<?php echo $staff['id']; ?>"
                                    data-role="<?php echo htmlspecialchars($staff['role']); ?>"
                                    data-specialization="<?php echo htmlspecialchars($staff['specialization']); ?>">
                                <?php echo htmlspecialchars($staff['name']); ?> 
                                (<?php echo htmlspecialchars($staff['role']); ?>)
                                <?php if ($staff['specialization'] !== 'General'): ?>
                                    - <?php echo htmlspecialchars($staff['specialization']); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="date-range" class="form-label">Date Range</label>
                    <input type="text" id="date-range" class="form-control date-range-picker" placeholder="Select date range">
                </div>
            </div>
        </div>
        
        <!-- Enhanced Calendar -->
        <div class="card">
            <div id="calendar" class="calendar-container"></div>
        </div>
    </main>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.all.min.js"></script>
    
    <script>
        // Enhanced Calendar Management System
        class AdvancedCalendarManager {
            constructor() {
                this.currentTheme = '<?php echo $theme; ?>';
                this.calendar = null;
                this.filters = {
                    equipment: [],
                    event_type: [],
                    priority: [],
                    status: [],
                    assigned: [],
                    date_range: null
                };
                this.equipmentList = <?php echo json_encode($equipmentList); ?>;
                this.staffList = <?php echo json_encode($staffList); ?>;
                this.maintenanceTypes = <?php echo json_encode($maintenanceTypes); ?>;
                
                this.init();
            }
            
            init() {
                this.setupEventListeners();
                this.initializeComponents();
                this.initializeCalendar();
                this.setupRealTimeUpdates();
                this.setupKeyboardShortcuts();
            }
            
            setupEventListeners() {
                // Theme toggle
                document.getElementById('theme-toggle').addEventListener('click', () => {
                    this.toggleTheme();
                });
                
                // Sidebar toggle for mobile
                const sidebarToggle = document.getElementById('sidebar-toggle');
                if (sidebarToggle) {
                    sidebarToggle.addEventListener('click', () => {
                        document.getElementById('sidebar').classList.toggle('open');
                    });
                }
                
                // Add event button
                document.getElementById('add-event-btn').addEventListener('click', () => {
                    this.showEventModal('create', {
                        start: new Date().toISOString(),
                        allDay: false
                    });
                });
                
                // Filter controls
                document.getElementById('apply-filters').addEventListener('click', () => {
                    this.applyFilters();
                });
                
                document.getElementById('reset-filters').addEventListener('click', () => {
                    this.resetFilters();
                });
                
                document.getElementById('export-calendar').addEventListener('click', () => {
                    this.exportCalendar();
                });
            }
            
            initializeComponents() {
                // Initialize Select2 with enhanced options
                $('.select2').select2({
                    theme: "classic",
                    width: '100%',
                    placeholder: function() {
                        return $(this).data('placeholder') || 'Select options...';
                    },
                    allowClear: true,
                    closeOnSelect: false
                });
                
                // Initialize Flatpickr for date range
                flatpickr("#date-range", {
                    mode: "range",
                    dateFormat: "Y-m-d",
                    altInput: true,
                    altFormat: "F j, Y",
                    allowInput: true,
                    showMonths: 2
                });
            }
            
            initializeCalendar() {
                const calendarEl = document.getElementById('calendar');
                
                this.calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay,listMonth'
                    },
                    themeSystem: 'bootstrap5',
                    navLinks: true,
                    editable: true,
                    selectable: true,
                    selectMirror: true,
                    dayMaxEvents: 4,
                    nowIndicator: true,
                    businessHours: {
                        daysOfWeek: [1, 2, 3, 4, 5, 6], // Monday - Saturday
                        startTime: '06:00',
                        endTime: '22:00',
                    },
                    height: 'auto',
                    aspectRatio: 1.8,
                    eventDisplay: 'block',
                    displayEventTime: true,
                    
                    events: (info, successCallback, failureCallback) => {
                        this.loadEvents(info, successCallback, failureCallback);
                    },
                    
                    select: (info) => {
                        this.showEventModal('create', {
                            start: info.startStr,
                            end: info.endStr,
                            allDay: info.allDay
                        });
                    },
                    
                    eventClick: (info) => {
                        this.showEventModal('edit', {
                            id: info.event.id,
                            title: info.event.title,
                            start: info.event.startStr,
                            end: info.event.endStr,
                            allDay: info.event.allDay,
                            ...info.event.extendedProps
                        });
                    },
                    
                    eventDrop: (info) => {
                        this.updateEventDates(info.event);
                    },
                    
                    eventResize: (info) => {
                        this.updateEventDates(info.event);
                    },
                    
                    eventDidMount: (info) => {
                        // Add enhanced tooltips to events
                        const tooltip = this.createEventTooltip(info.event);
                        info.el.setAttribute('title', tooltip);
                        
                        // Add priority and status classes
                        if (info.event.extendedProps.priority) {
                            info.el.classList.add('priority-' + info.event.extendedProps.priority.toLowerCase());
                        }
                        
                        if (info.event.extendedProps.display_status) {
                            info.el.classList.add('status-' + info.event.extendedProps.display_status.toLowerCase().replace(' ', '-'));
                        }
                        
                        // Add hover effects
                        info.el.addEventListener('mouseenter', () => {
                            this.showEventPreview(info.event, info.el);
                        });
                        
                        info.el.addEventListener('mouseleave', () => {
                            this.hideEventPreview();
                        });
                    },
                    
                    datesSet: (dateInfo) => {
                        this.updateStatistics();
                    }
                });
                
                this.calendar.render();
            }
            
            loadEvents(info, successCallback, failureCallback) {
                $.ajax({
                    url: 'calendar.php',
                    type: 'POST',
                    data: {
                        action: 'get_events',
                        start: info.startStr,
                        end: info.endStr,
                        equipment: this.filters.equipment,
                        event_type: this.filters.event_type,
                        priority: this.filters.priority,
                        status: this.filters.status,
                        assigned: this.filters.assigned
                    },
                    success: (response) => {
                        if (response.events) {
                            successCallback(response.events);
                        } else {
                            failureCallback(response.error || 'Failed to load events');
                        }
                    },
                    error: (xhr, status, error) => {
                        console.error('Error loading events:', error);
                        failureCallback(error);
                    }
                });
            }
            
            createEventTooltip(event) {
                let tooltip = `<strong>${event.title}</strong>`;
                
                if (event.extendedProps.equipment_name) {
                    tooltip += `\nEquipment: ${event.extendedProps.equipment_name}`;
                }
                
                if (event.extendedProps.priority) {
                    tooltip += `\nPriority: ${event.extendedProps.priority}`;
                }
                
                if (event.extendedProps.display_status || event.extendedProps.status) {
                    tooltip += `\nStatus: ${event.extendedProps.display_status || event.extendedProps.status}`;
                }
                
                if (event.extendedProps.assigned_name) {
                    tooltip += `\nAssigned to: ${event.extendedProps.assigned_name}`;
                }
                
                if (event.extendedProps.location) {
                    tooltip += `\nLocation: ${event.extendedProps.location}`;
                }
                
                if (event.extendedProps.estimated_duration) {
                    tooltip += `\nDuration: ${event.extendedProps.estimated_duration}h`;
                }
                
                if (event.extendedProps.estimated_cost) {
                    tooltip += `\nCost: $${event.extendedProps.estimated_cost}`;
                }
                
                if (event.extendedProps.description) {
                    const desc = event.extendedProps.description.length > 100 
                        ? event.extendedProps.description.substring(0, 100) + '...'
                        : event.extendedProps.description;
                    tooltip += `\nDescription: ${desc}`;
                }
                
                if (event.extendedProps.days_overdue && event.extendedProps.days_overdue > 0) {
                    tooltip += `\n⚠️ ${event.extendedProps.days_overdue} days overdue`;
                }
                
                return tooltip;
            }
            
            showEventPreview(event, element) {
                // Create floating preview card
                const preview = document.createElement('div');
                preview.id = 'event-preview';
                preview.className = 'event-preview-card';
                preview.innerHTML = this.generateEventPreviewHTML(event);
                
                document.body.appendChild(preview);
                
                // Position the preview
                const rect = element.getBoundingClientRect();
                preview.style.position = 'fixed';
                preview.style.top = (rect.bottom + 10) + 'px';
                preview.style.left = rect.left + 'px';
                preview.style.zIndex = '9999';
            }
            
            hideEventPreview() {
                const preview = document.getElementById('event-preview');
                if (preview) {
                    preview.remove();
                }
            }
            
            generateEventPreviewHTML(event) {
                const props = event.extendedProps;
                return `
                    <div class="preview-header">
                        <h4>${event.title}</h4>
                        ${props.priority ? `<span class="priority-badge priority-${props.priority.toLowerCase()}">${props.priority}</span>` : ''}
                    </div>
                    <div class="preview-body">
                        ${props.equipment_name ? `<p><i class="fas fa-dumbbell"></i> ${props.equipment_name}</p>` : ''}
                        ${props.assigned_name ? `<p><i class="fas fa-user"></i> ${props.assigned_name}</p>` : ''}
                        ${props.location ? `<p><i class="fas fa-map-marker-alt"></i> ${props.location}</p>` : ''}
                        ${props.estimated_duration ? `<p><i class="fas fa-clock"></i> ${props.estimated_duration}h</p>` : ''}
                        ${props.estimated_cost ? `<p><i class="fas fa-dollar-sign"></i> $${props.estimated_cost}</p>` : ''}
                    </div>
                `;
            }
            
            showEventModal(mode, eventData) {
                const title = mode === 'create' ? 'Create New Event' : 'Edit Event';
                const confirmButtonText = mode === 'create' ? 'Create Event' : 'Update Event';
                
                // Generate comprehensive form HTML
                const formHTML = this.generateEventFormHTML(eventData);
                
                Swal.fire({
                    title: title,
                    html: formHTML,
                    showCancelButton: true,
                    showDenyButton: mode === 'edit',
                    confirmButtonText: confirmButtonText,
                    denyButtonText: 'Delete Event',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#ff6b35',
                    denyButtonColor: '#ef4444',
                    cancelButtonColor: '#6c757d',
                    width: '900px',
                    customClass: {
                        container: document.body.classList.contains('dark-theme') ? 'dark-theme' : '',
                        popup: 'event-modal'
                    },
                    didOpen: () => {
                        this.setupModalEventListeners(eventData);
                    },
                    preConfirm: () => {
                        return this.validateAndCollectFormData();
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        this.saveEvent(mode, result.value, eventData);
                    } else if (result.isDenied) {
                        this.deleteEvent(eventData);
                    }
                });
            }
            
            generateEventFormHTML(eventData) {
                const eventTypeOptions = `
                    <option value="maintenance" ${eventData.event_type === 'maintenance' ? 'selected' : ''}>Maintenance</option>
                    <option value="booking" ${eventData.event_type === 'booking' ? 'selected' : ''}>Equipment Booking</option>
                    <option value="training" ${eventData.event_type === 'training' ? 'selected' : ''}>Training Session</option>
                    <option value="inspection" ${eventData.event_type === 'inspection' ? 'selected' : ''}>Inspection</option>
                    <option value="meeting" ${eventData.event_type === 'meeting' ? 'selected' : ''}>Meeting</option>
                    <option value="other" ${eventData.event_type === 'other' || !eventData.event_type ? 'selected' : ''}>Other</option>
                `;
                
                const priorityOptions = `
                    <option value="Low" ${eventData.priority === 'Low' ? 'selected' : ''}>Low Priority</option>
                    <option value="Medium" ${eventData.priority === 'Medium' || !eventData.priority ? 'selected' : ''}>Medium Priority</option>
                    <option value="High" ${eventData.priority === 'High' ? 'selected' : ''}>High Priority</option>
                `;
                
                const statusOptions = `
                    <option value="Scheduled" ${eventData.status === 'Scheduled' || !eventData.status ? 'selected' : ''}>Scheduled</option>
                    <option value="In Progress" ${eventData.status === 'In Progress' ? 'selected' : ''}>In Progress</option>
                    <option value="Completed" ${eventData.status === 'Completed' ? 'selected' : ''}>Completed</option>
                    <option value="Cancelled" ${eventData.status === 'Cancelled' ? 'selected' : ''}>Cancelled</option>
                `;
                
                let equipmentOptions = '<option value="">Select Equipment (Optional)</option>';
                this.equipmentList.forEach(equipment => {
                    const selected = eventData.equipment_id == equipment.id ? 'selected' : '';
                    equipmentOptions += `<option value="${equipment.id}" ${selected}>${equipment.name} (${equipment.type})</option>`;
                });
                
                let staffOptions = '<option value="">Assign to Staff (Optional)</option>';
                this.staffList.forEach(staff => {
                    const selected = eventData.assigned_to == staff.id ? 'selected' : '';
                    staffOptions += `<option value="${staff.id}" ${selected}>${staff.name} (${staff.role})</option>`;
                });
                
                let maintenanceTypeOptions = '';
                Object.entries(this.maintenanceTypes).forEach(([type, description]) => {
                    const selected = eventData.title === type ? 'selected' : '';
                    maintenanceTypeOptions += `<option value="${type}" ${selected} data-description="${description}">${type}</option>`;
                });
                
                const colorOptions = this.generateColorOptions();
                
                const startDate = eventData.start ? new Date(eventData.start) : new Date();
                const endDate = eventData.end ? new Date(eventData.end) : new Date(startDate.getTime() + 60 * 60 * 1000);
                
                const formattedStartDate = startDate.toISOString().slice(0, 16);
                const formattedEndDate = endDate.toISOString().slice(0, 16);
                
                return `
                    <form id="event-form" class="event-form">
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-info-circle"></i>
                                Basic Information
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="event-title" class="form-label">Event Title *</label>
                                    <input type="text" id="event-title" class="form-control" value="${eventData.title || ''}" required>
                                </div>
                                <div class="form-group">
                                    <label for="event-type" class="form-label">Event Type *</label>
                                    <select id="event-type" class="form-control">
                                        ${eventTypeOptions}
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row" id="maintenance-type-row" style="display: none;">
                                <div class="form-group">
                                    <label for="maintenance-type" class="form-label">Maintenance Type</label>
                                    <select id="maintenance-type" class="form-control">
                                        <option value="">Select maintenance type...</option>
                                        ${maintenanceTypeOptions}
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="maintenance-description" class="form-label">Type Description</label>
                                    <input type="text" id="maintenance-description" class="form-control" readonly>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-calendar-alt"></i>
                                Schedule & Duration
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="event-start" class="form-label">Start Date/Time *</label>
                                    <input type="datetime-local" id="event-start" class="form-control" value="${formattedStartDate}" required>
                                </div>
                                <div class="form-group">
                                    <label for="event-end" class="form-label">End Date/Time</label>
                                    <input type="datetime-local" id="event-end" class="form-control" value="${formattedEndDate}">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="event-duration" class="form-label">Estimated Duration (hours)</label>
                                    <input type="number" id="event-duration" class="form-control" value="${eventData.estimated_duration || ''}" min="0" step="0.5" placeholder="2.5">
                                </div>
                                <div class="form-group">
                                    <div class="form-check" style="margin-top: 2rem;">
                                        <input type="checkbox" id="event-all-day" class="form-check-input" ${eventData.allDay ? 'checked' : ''}>
                                        <label for="event-all-day" class="form-check-label">All Day Event</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-users"></i>
                                Assignment & Resources
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="event-equipment" class="form-label">Equipment</label>
                                    <select id="event-equipment" class="form-control">
                                        ${equipmentOptions}
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="event-assigned" class="form-label">Assigned To</label>
                                    <select id="event-assigned" class="form-control">
                                        ${staffOptions}
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="event-priority" class="form-label">Priority</label>
                                    <select id="event-priority" class="form-control">
                                        ${priorityOptions}
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="event-status" class="form-label">Status</label>
                                    <select id="event-status" class="form-control">
                                        ${statusOptions}
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-map-marker-alt"></i>
                                Location & Cost
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="event-location" class="form-label">Location</label>
                                    <input type="text" id="event-location" class="form-control" value="${eventData.location || ''}" placeholder="Equipment location or meeting room">
                                </div>
                                <div class="form-group">
                                    <label for="event-cost" class="form-label">Estimated Cost ($)</label>
                                    <input type="number" id="event-cost" class="form-control" value="${eventData.estimated_cost || ''}" min="0" step="0.01" placeholder="150.00">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-edit"></i>
                                Description & Notes
                            </div>
                            <div class="form-group">
                                <label for="event-description" class="form-label">Description</label>
                                <textarea id="event-description" class="form-control" rows="3" placeholder="Event description and details...">${eventData.description || ''}</textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="event-notes" class="form-label">Internal Notes</label>
                                <textarea id="event-notes" class="form-control" rows="2" placeholder="Internal notes and comments...">${eventData.notes || ''}</textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="event-tags" class="form-label">Tags</label>
                                <input type="text" id="event-tags" class="form-control" value="${eventData.tags || ''}" placeholder="urgent, safety, warranty (comma separated)">
                            </div>
                        </div>
                        
                        <div class="form-section" id="color-picker-container">
                            <div class="form-section-title">
                                <i class="fas fa-palette"></i>
                                Event Color
                            </div>
                            <div class="color-picker">
                                ${colorOptions}
                            </div>
                        </div>
                    </form>
                `;
            }
            
            generateColorOptions() {
                const colors = [
                    '#ff6b35', '#ef4444', '#10b981', '#3b82f6', 
                    '#8b5cf6', '#f59e0b', '#06b6d4', '#84cc16',
                    '#f97316', '#ec4899', '#6366f1', '#14b8a6'
                ];
                
                return colors.map(color => 
                    `<div class="color-option" style="background-color: ${color};" data-color="${color}"></div>`
                ).join('');
            }
            
            setupModalEventListeners(eventData) {
                // Color picker functionality
                const colorOptions = document.querySelectorAll('.color-picker .color-option');
                colorOptions.forEach(option => {
                    option.addEventListener('click', function() {
                        colorOptions.forEach(opt => opt.classList.remove('selected'));
                        this.classList.add('selected');
                    });
                    
                    // Select default or existing color
                    const defaultColor = eventData.color || '#ff6b35';
                    if (option.getAttribute('data-color') === defaultColor) {
                        option.classList.add('selected');
                    }
                });
                
                // Event type change handler
                const eventTypeSelect = document.getElementById('event-type');
                const maintenanceTypeRow = document.getElementById('maintenance-type-row');
                const maintenanceTypeSelect = document.getElementById('maintenance-type');
                const maintenanceDescInput = document.getElementById('maintenance-description');
                const eventTitleInput = document.getElementById('event-title');
                const colorPickerContainer = document.getElementById('color-picker-container');
                
                function handleEventTypeChange() {
                    const eventType = eventTypeSelect.value;
                    
                    if (eventType === 'maintenance') {
                        maintenanceTypeRow.style.display = 'block';
                        colorPickerContainer.style.display = 'none';
                        
                        // Auto-fill title from maintenance type
                        if (maintenanceTypeSelect.value && !eventTitleInput.value) {
                            eventTitleInput.value = maintenanceTypeSelect.value;
                        }
                    } else {
                        maintenanceTypeRow.style.display = 'none';
                        colorPickerContainer.style.display = 'block';
                    }
                }
                
                eventTypeSelect.addEventListener('change', handleEventTypeChange);
                
                // Maintenance type change handler
                maintenanceTypeSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption.value) {
                        eventTitleInput.value = selectedOption.value;
                        maintenanceDescInput.value = selectedOption.dataset.description || '';
                    }
                });
                
                // Initialize event type display
                handleEventTypeChange();
                
                // All day checkbox handler
                const allDayCheckbox = document.getElementById('event-all-day');
                const endDateInput = document.getElementById('event-end');
                
                function toggleEndDate() {
                    endDateInput.disabled = allDayCheckbox.checked;
                    endDateInput.style.opacity = allDayCheckbox.checked ? '0.5' : '1';
                }
                
                allDayCheckbox.addEventListener('change', toggleEndDate);
                toggleEndDate();
                
                // Equipment selection handler
                const equipmentSelect = document.getElementById('event-equipment');
                const locationInput = document.getElementById('event-location');
                
                equipmentSelect.addEventListener('change', function() {
                    const selectedEquipment = this.equipmentList.find(eq => eq.id == this.value);
                    if (selectedEquipment && selectedEquipment.location && !locationInput.value) {
                        locationInput.value = selectedEquipment.location;
                    }
                }.bind(this));
            }
            
            validateAndCollectFormData() {
                const title = document.getElementById('event-title').value.trim();
                const eventType = document.getElementById('event-type').value;
                const equipmentId = document.getElementById('event-equipment').value;
                const assignedTo = document.getElementById('event-assigned').value;
                const priority = document.getElementById('event-priority').value;
                const status = document.getElementById('event-status').value;
                const location = document.getElementById('event-location').value.trim();
                const start = document.getElementById('event-start').value;
                const end = document.getElementById('event-end').value;
                const allDay = document.getElementById('event-all-day').checked;
                const description = document.getElementById('event-description').value.trim();
                const notes = document.getElementById('event-notes').value.trim();
                const tags = document.getElementById('event-tags').value.trim();
                const estimatedDuration = document.getElementById('event-duration').value;
                const estimatedCost = document.getElementById('event-cost').value;
                
                let color = '#ff6b35';
                const selectedColor = document.querySelector('.color-picker .color-option.selected');
                if (selectedColor) {
                    color = selectedColor.getAttribute('data-color');
                }
                
                // Enhanced validation
                if (!title) {
                    Swal.showValidationMessage('Please enter an event title');
                    return false;
                }
                
                if (eventType === 'maintenance' && !equipmentId) {
                    Swal.showValidationMessage('Please select equipment for maintenance events');
                    return false;
                }
                
                if (!start) {
                    Swal.showValidationMessage('Please select a start date/time');
                    return false;
                }
                
                if (!allDay && end && new Date(end) <= new Date(start)) {
                    Swal.showValidationMessage('End date/time must be after start date/time');
                    return false;
                }
                
                if (estimatedDuration && (isNaN(estimatedDuration) || estimatedDuration < 0)) {
                    Swal.showValidationMessage('Please enter a valid duration');
                    return false;
                }
                
                if (estimatedCost && (isNaN(estimatedCost) || estimatedCost < 0)) {
                    Swal.showValidationMessage('Please enter a valid cost');
                    return false;
                }
                
                return {
                    title,
                    event_type: eventType,
                    equipment_id: equipmentId,
                    assigned_to: assignedTo,
                    priority,
                    status,
                    location,
                    start,
                    end,
                    allDay,
                    description,
                    notes,
                    tags,
                    estimated_duration: estimatedDuration,
                    estimated_cost: estimatedCost,
                    color
                };
            }
            
            saveEvent(mode, formData, eventData) {
                // Add action and ID if editing
                formData.action = mode;
                if (mode === 'edit') {
                    formData.id = eventData.id.replace(/^[me]_/, '');
                }
                
                // Show loading state
                Swal.showLoading();
                
                $.ajax({
                    url: 'calendar.php',
                    type: 'POST',
                    data: formData,
                    success: (response) => {
                        Swal.close();
                        if (response.success) {
                            this.showNotification('success', 
                                mode === 'create' ? 'Event Created' : 'Event Updated',
                                response.message
                            );
                            this.calendar.refetchEvents();
                            this.updateStatistics();
                        } else {
                            this.showNotification('error', 'Error', response.message);
                        }
                    },
                    error: () => {
                        Swal.close();
                        this.showNotification('error', 'Error', 'Failed to save event. Please try again.');
                    }
                });
            }
            
            deleteEvent(eventData) {
                Swal.fire({
                    title: 'Delete Event',
                    text: 'Are you sure you want to delete this event? This action cannot be undone.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, delete it',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#6c757d',
                    customClass: {
                        container: document.body.classList.contains('dark-theme') ? 'dark-theme' : ''
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.showLoading();
                        
                        $.ajax({
                            url: 'calendar.php',
                            type: 'POST',
                            data: {
                                action: 'delete',
                                id: eventData.id.replace(/^[me]_/, ''),
                                event_type: eventData.event_type
                            },
                            success: (response) => {
                                Swal.close();
                                if (response.success) {
                                    this.showNotification('success', 'Event Deleted', response.message);
                                    this.calendar.refetchEvents();
                                    this.updateStatistics();
                                } else {
                                    this.showNotification('error', 'Error', response.message);
                                }
                            },
                            error: () => {
                                Swal.close();
                                this.showNotification('error', 'Error', 'Failed to delete event. Please try again.');
                            }
                        });
                    }
                });
            }
            
            updateEventDates(event) {
                const eventId = event.id.replace(/^[me]_/, '');
                const eventType = event.extendedProps.event_type;
                
                $.ajax({
                    url: 'calendar.php',
                    type: 'POST',
                    data: {
                        action: 'update',
                        id: eventId,
                        event_type: eventType,
                        title: event.title,
                        start: event.startStr,
                        end: event.endStr,
                        allDay: event.allDay ? 1 : 0,
                        description: event.extendedProps.description || '',
                        priority: event.extendedProps.priority || 'Medium',
                        assigned_to: event.extendedProps.assigned_id || '',
                        status: event.extendedProps.status || 'Scheduled',
                        location: event.extendedProps.location || '',
                        estimated_duration: event.extendedProps.estimated_duration || '',
                        estimated_cost: event.extendedProps.estimated_cost || '',
                        notes: event.extendedProps.notes || ''
                    },
                    success: (response) => {
                        if (response.success) {
                            this.showNotification('success', 'Event Updated', 'Event dates updated successfully');
                        } else {
                            this.showNotification('error', 'Error', response.message);
                            this.calendar.refetchEvents();
                        }
                    },
                    error: () => {
                        this.showNotification('error', 'Error', 'Failed to update event dates');
                        this.calendar.refetchEvents();
                    }
                });
            }
            
            applyFilters() {
                this.filters.equipment = $('#equipment-filter').val() || [];
                this.filters.event_type = $('#event-type-filter').val() || [];
                this.filters.priority = $('#priority-filter').val() || [];
                this.filters.status = $('#status-filter').val() || [];
                this.filters.assigned = $('#assigned-filter').val() || [];
                this.filters.date_range = $('#date-range').val();
                
                // Add loading state to calendar
                document.querySelector('.calendar-container').classList.add('loading');
                
                this.calendar.refetchEvents();
                
                // Remove loading state after events are loaded
                setTimeout(() => {
                    document.querySelector('.calendar-container').classList.remove('loading');
                }, 1000);
                
                this.showNotification('info', 'Filters Applied', 'Calendar events have been filtered');
            }
            
            resetFilters() {
                $('#equipment-filter').val('all').trigger('change');
                $('#event-type-filter').val('all').trigger('change');
                $('#priority-filter').val('all').trigger('change');
                $('#status-filter').val('all').trigger('change');
                $('#assigned-filter').val('all').trigger('change');
                $('#date-range').val('');
                
                this.filters = {
                    equipment: [],
                    event_type: [],
                    priority: [],
                    status: [],
                    assigned: [],
                    date_range: null
                };
                
                this.calendar.refetchEvents();
                this.showNotification('info', 'Filters Reset', 'All filters have been cleared');
            }
            
            exportCalendar() {
                const currentView = this.calendar.view;
                const startDate = currentView.activeStart.toISOString().split('T')[0];
                const endDate = currentView.activeEnd.toISOString().split('T')[0];
                
                Swal.fire({
                    title: 'Export Calendar',
                    html: `
                        <div class="export-options">
                            <div class="form-group">
                                <label>Export Format:</label>
                                <select id="export-format" class="form-control">
                                    <option value="csv">CSV (Excel Compatible)</option>
                                    <option value="ical">iCal (Calendar Import)</option>
                                    <option value="pdf">PDF Report</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Date Range:</label>
                                <input type="date" id="export-start" class="form-control" value="${startDate}">
                                <input type="date" id="export-end" class="form-control" value="${endDate}" style="margin-top: 0.5rem;">
                            </div>
                            <div class="form-group">
                                <label>Include:</label>
                                <div class="form-check">
                                    <input type="checkbox" id="include-maintenance" class="form-check-input" checked>
                                    <label for="include-maintenance" class="form-check-label">Maintenance Events</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" id="include-bookings" class="form-check-input" checked>
                                    <label for="include-bookings" class="form-check-label">Equipment Bookings</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" id="include-other" class="form-check-input" checked>
                                    <label for="include-other" class="form-check-label">Other Events</label>
                                </div>
                            </div>
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Export',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#ff6b35',
                    customClass: {
                        container: document.body.classList.contains('dark-theme') ? 'dark-theme' : ''
                    },
                    preConfirm: () => {
                        const format = document.getElementById('export-format').value;
                        const startDate = document.getElementById('export-start').value;
                        const endDate = document.getElementById('export-end').value;
                        const includeMaintenance = document.getElementById('include-maintenance').checked;
                        const includeBookings = document.getElementById('include-bookings').checked;
                        const includeOther = document.getElementById('include-other').checked;
                        
                        if (!startDate || !endDate) {
                            Swal.showValidationMessage('Please select both start and end dates');
                            return false;
                        }
                        
                        if (new Date(endDate) <= new Date(startDate)) {
                            Swal.showValidationMessage('End date must be after start date');
                            return false;
                        }
                        
                        return {
                            format,
                            startDate,
                            endDate,
                            includeMaintenance,
                            includeBookings,
                            includeOther
                        };
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        this.performExport(result.value);
                    }
                });
            }
            
            performExport(options) {
                // Create export URL with parameters
                const params = new URLSearchParams({
                    action: 'export',
                    format: options.format,
                    start: options.startDate,
                    end: options.endDate,
                    include_maintenance: options.includeMaintenance ? '1' : '0',
                    include_bookings: options.includeBookings ? '1' : '0',
                    include_other: options.includeOther ? '1' : '0'
                });
                
                // Create temporary download link
                const downloadUrl = `export-calendar.php?${params.toString()}`;
                const link = document.createElement('a');
                link.href = downloadUrl;
                link.download = `calendar-export-${options.startDate}-to-${options.endDate}.${options.format}`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                this.showNotification('success', 'Export Started', 'Your calendar export is being downloaded');
            }
            
            updateStatistics() {
                $.ajax({
                    url: 'calendar.php',
                    type: 'POST',
                    data: { action: 'get_statistics' },
                    success: (response) => {
                        if (response.success && response.stats) {
                            this.updateStatisticsDisplay(response.stats);
                        }
                    },
                    error: (error) => {
                        console.error('Error updating statistics:', error);
                    }
                });
            }
            
            updateStatisticsDisplay(stats) {
                // Update stat cards with animation
                const statCards = document.querySelectorAll('.stat-card');
                statCards.forEach((card, index) => {
                    const valueElement = card.querySelector('.stat-value');
                    if (valueElement) {
                        const currentValue = parseInt(valueElement.textContent) || 0;
                        let newValue = 0;
                        
                        switch (index) {
                            case 0: newValue = stats.scheduled_maintenance || 0; break;
                            case 1: newValue = stats.in_progress_maintenance || 0; break;
                            case 2: newValue = stats.completed_maintenance || 0; break;
                            case 3: newValue = stats.overdue_maintenance || 0; break;
                            case 4: newValue = stats.high_priority_maintenance || 0; break;
                            case 5: newValue = stats.upcoming_events || 0; break;
                            case 6: newValue = stats.equipment_maintained || 0; break;
                            case 7: newValue = Math.round(stats.avg_maintenance_cost || 0); break;
                        }
                        
                        if (currentValue !== newValue) {
                            this.animateValue(valueElement, currentValue, newValue, 1000);
                        }
                    }
                });
            }
            
            animateValue(element, start, end, duration) {
                const startTime = performance.now();
                const isMonetary = element.textContent.includes('$');
                
                const animate = (currentTime) => {
                    const elapsed = currentTime - startTime;
                    const progress = Math.min(elapsed / duration, 1);
                    
                    const current = Math.round(start + (end - start) * progress);
                    element.textContent = isMonetary ? `$${current.toLocaleString()}` : current.toString();
                    
                    if (progress < 1) {
                        requestAnimationFrame(animate);
                    }
                };
                
                requestAnimationFrame(animate);
            }
            
            toggleTheme() {
                const newTheme = this.currentTheme === 'dark' ? 'light' : 'dark';
                
                // Update UI immediately
                document.body.classList.toggle('light-theme', newTheme === 'light');
                
                const toggle = document.getElementById('theme-toggle');
                toggle.classList.toggle('active', newTheme === 'dark');
                
                const slider = toggle.querySelector('.theme-toggle-slider i');
                slider.className = `fas fa-${newTheme === 'dark' ? 'moon' : 'sun'}`;
                
                // Save preference
                fetch('save-theme.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ theme: newTheme })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.currentTheme = newTheme;
                        this.showNotification('success', 'Theme Changed', `Switched to ${newTheme} theme`);
                    }
                })
                .catch(error => {
                    console.error('Error saving theme:', error);
                });
            }
            
            setupRealTimeUpdates() {
                // Refresh calendar every 5 minutes
                setInterval(() => {
                    this.calendar.refetchEvents();
                    this.updateStatistics();
                }, 300000);
                
                // Check for overdue events every minute
                setInterval(() => {
                    this.checkOverdueEvents();
                }, 60000);
                
                // Auto-save filter preferences
                setInterval(() => {
                    this.saveFilterPreferences();
                }, 30000);
            }
            
            checkOverdueEvents() {
                const events = this.calendar.getEvents();
                const now = new Date();
                let overdueCount = 0;
                
                events.forEach(event => {
                    if (event.extendedProps.event_type === 'maintenance' && 
                        event.extendedProps.status !== 'Completed' && 
                        new Date(event.start) < now) {
                        overdueCount++;
                    }
                });
                
                if (overdueCount > 0) {
                    this.showOverdueNotification(overdueCount);
                }
            }
            
            showOverdueNotification(count) {
                // Only show notification once per session for overdue events
                if (!this.overdueNotificationShown) {
                    this.showNotification('warning', 'Overdue Events', 
                        `You have ${count} overdue maintenance event${count > 1 ? 's' : ''}`);
                    this.overdueNotificationShown = true;
                    
                    // Reset flag after 1 hour
                    setTimeout(() => {
                        this.overdueNotificationShown = false;
                    }, 3600000);
                }
            }
            
            saveFilterPreferences() {
                const preferences = {
                    equipment: this.filters.equipment,
                    event_type: this.filters.event_type,
                    priority: this.filters.priority,
                    status: this.filters.status,
                    assigned: this.filters.assigned
                };
                
                localStorage.setItem('calendar_filter_preferences', JSON.stringify(preferences));
            }
            
            loadFilterPreferences() {
                const saved = localStorage.getItem('calendar_filter_preferences');
                if (saved) {
                    try {
                        const preferences = JSON.parse(saved);
                        
                        // Apply saved filters
                        if (preferences.equipment && preferences.equipment.length > 0) {
                            $('#equipment-filter').val(preferences.equipment).trigger('change');
                        }
                        if (preferences.event_type && preferences.event_type.length > 0) {
                            $('#event-type-filter').val(preferences.event_type).trigger('change');
                        }
                        if (preferences.priority && preferences.priority.length > 0) {
                            $('#priority-filter').val(preferences.priority).trigger('change');
                        }
                        if (preferences.status && preferences.status.length > 0) {
                            $('#status-filter').val(preferences.status).trigger('change');
                        }
                        if (preferences.assigned && preferences.assigned.length > 0) {
                            $('#assigned-filter').val(preferences.assigned).trigger('change');
                        }
                        
                        this.filters = preferences;
                    } catch (error) {
                        console.error('Error loading filter preferences:', error);
                    }
                }
            }
            
            setupKeyboardShortcuts() {
                document.addEventListener('keydown', (event) => {
                    // Only handle shortcuts when not in input fields
                    if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA' || event.target.tagName === 'SELECT') {
                        return;
                    }
                    
                    // Ctrl/Cmd + N: New event
                    if ((event.ctrlKey || event.metaKey) && event.key === 'n') {
                        event.preventDefault();
                        this.showEventModal('create', {
                            start: new Date().toISOString(),
                            allDay: false
                        });
                    }
                    
                    // Ctrl/Cmd + F: Focus on filters
                    if ((event.ctrlKey || event.metaKey) && event.key === 'f') {
                        event.preventDefault();
                        document.getElementById('equipment-filter').focus();
                    }
                    
                    // Ctrl/Cmd + R: Refresh calendar
                    if ((event.ctrlKey || event.metaKey) && event.key === 'r') {
                        event.preventDefault();
                        this.calendar.refetchEvents();
                        this.updateStatistics();
                        this.showNotification('info', 'Refreshed', 'Calendar data has been refreshed');
                    }
                    
                    // Ctrl/Cmd + T: Toggle theme
                    if ((event.ctrlKey || event.metaKey) && event.key === 't') {
                        event.preventDefault();
                        this.toggleTheme();
                    }
                    
                    // Arrow keys for calendar navigation
                    if (event.key === 'ArrowLeft' && event.altKey) {
                        event.preventDefault();
                        this.calendar.prev();
                    }
                    
                    if (event.key === 'ArrowRight' && event.altKey) {
                        event.preventDefault();
                        this.calendar.next();
                    }
                    
                    // Escape: Close any open modals
                    if (event.key === 'Escape') {
                        Swal.close();
                        this.hideEventPreview();
                    }
                });
            }
            
            showNotification(type, title, message) {
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 4000,
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.addEventListener('mouseenter', Swal.stopTimer);
                        toast.addEventListener('mouseleave', Swal.resumeTimer);
                    },
                    customClass: {
                        container: document.body.classList.contains('dark-theme') ? 'dark-theme' : ''
                    }
                });
                
                Toast.fire({
                    icon: type,
                    title: title,
                    text: message
                });
            }
        }
        
        // Initialize calendar manager when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            window.calendarManager = new AdvancedCalendarManager();
            
            // Load saved filter preferences
            window.calendarManager.loadFilterPreferences();
            
            // Setup mobile sidebar toggle
            const sidebarToggle = document.getElementById('sidebar-toggle');
            const sidebar = document.getElementById('sidebar');
            
            if (sidebarToggle && sidebar) {
                sidebarToggle.addEventListener('click', () => {
                    sidebar.classList.toggle('open');
                });
                
                // Close sidebar when clicking outside on mobile
                document.addEventListener('click', (event) => {
                    if (window.innerWidth <= 768 && 
                        !sidebar.contains(event.target) && 
                        !sidebarToggle.contains(event.target) &&
                        sidebar.classList.contains('open')) {
                        sidebar.classList.remove('open');
                    }
                });
            }
            
            // Setup user avatar dropdown (if needed)
            const userAvatar = document.getElementById('user-avatar');
            if (userAvatar) {
                userAvatar.addEventListener('click', () => {
                    // Add user menu functionality here if needed
                    console.log('User menu clicked');
                });
            }
            
            // Add loading animation to page
            document.body.style.opacity = '0';
            document.body.style.transition = 'opacity 0.3s ease-in-out';
            
            setTimeout(() => {
                document.body.style.opacity = '1';
            }, 100);
        });
        
        // Add CSS for event preview card
        const previewStyles = `
            <style>
                .event-preview-card {
                    background: var(--dark-surface);
                    border: 1px solid var(--dark-border);
                    border-radius: var(--border-radius-lg);
                    padding: 1.5rem;
                    box-shadow: var(--shadow-xl);
                    max-width: 300px;
                    z-index: 9999;
                    animation: fadeInUp 0.2s ease-out;
                }
                
                .light-theme .event-preview-card {
                    background: var(--light-surface);
                    border: 1px solid var(--light-border);
                }
                
                .preview-header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    margin-bottom: 1rem;
                    padding-bottom: 0.75rem;
                    border-bottom: 1px solid var(--dark-border);
                }
                
                .light-theme .preview-header {
                    border-bottom: 1px solid var(--light-border);
                }
                
                .preview-header h4 {
                    margin: 0;
                    font-size: 1rem;
                    font-weight: 600;
                    color: var(--dark-text);
                }
                
                .light-theme .preview-header h4 {
                    color: var(--light-text);
                }
                
                .priority-badge {
                    padding: 0.25rem 0.5rem;
                    border-radius: 4px;
                    font-size: 0.75rem;
                    font-weight: 600;
                    text-transform: uppercase;
                }
                
                .priority-badge.priority-high {
                    background: var(--priority-high);
                    color: white;
                }
                
                .priority-badge.priority-medium {
                    background: var(--priority-medium);
                    color: white;
                }
                
                .priority-badge.priority-low {
                    background: var(--priority-low);
                    color: white;
                }
                
                .preview-body p {
                    margin: 0.5rem 0;
                    font-size: 0.875rem;
                    color: var(--dark-text-secondary);
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                }
                
                .light-theme .preview-body p {
                    color: var(--light-text-secondary);
                }
                
                .preview-body i {
                    width: 16px;
                    color: var(--primary);
                }
                
                @keyframes fadeInUp {
                    from {
                        opacity: 0;
                        transform: translateY(10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
                
                .export-options {
                    text-align: left;
                }
                
                .export-options .form-group {
                    margin-bottom: 1.5rem;
                }
                
                .export-options label {
                    display: block;
                    margin-bottom: 0.5rem;
                    font-weight: 600;
                    color: var(--dark-text);
                }
                
                .light-theme .export-options label {
                    color: var(--light-text);
                }
                
                .export-options .form-check {
                    margin-bottom: 0.5rem;
                }
                
                .export-options .form-check-label {
                    margin-left: 0.5rem;
                    font-weight: normal;
                }
            </style>
        `;
        
        document.head.insertAdjacentHTML('beforeend', previewStyles);
    </script>
</body>
</html>