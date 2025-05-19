<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Include database connection
$conn = connectDB();

// Set content type to JSON
header('Content-Type: application/json');

// Get parameters
$start = $_GET['start'] ?? date('Y-m-d');
$end = $_GET['end'] ?? date('Y-m-d', strtotime('+30 days'));
$equipment = $_GET['equipment'] ?? 'all';
$eventType = $_GET['event_type'] ?? 'all';
$dateRange = $_GET['date_range'] ?? '';

try {
    // Parse date range if provided
    if (!empty($dateRange)) {
        $dates = explode(' to ', $dateRange);
        if (count($dates) == 2) {
            $start = $dates[0];
            $end = $dates[1];
        }
    }
    
    // Build equipment filter
    $equipmentFilter = '';
    if ($equipment !== 'all' && !empty($equipment)) {
        if (is_array($equipment)) {
            $equipmentIds = implode(',', array_map('intval', $equipment));
            $equipmentFilter = " AND (equipment_id IN ($equipmentIds) OR equipment_id IS NULL)";
        } else {
            $equipmentId = intval($equipment);
            $equipmentFilter = " AND (equipment_id = $equipmentId OR equipment_id IS NULL)";
        }
    }
    
    // Build event type filter
    $eventTypeFilter = '';
    if ($eventType !== 'all' && !empty($eventType)) {
        if (is_array($eventType)) {
            $eventTypes = implode("','", array_map(function($item) use ($conn) {
                return $conn->quote($item);
            }, $eventType));
            $eventTypeFilter = " AND event_type IN ('$eventTypes')";
        } else {
            $eventTypeFilter = " AND event_type = " . $conn->quote($eventType);
        }
    }
    
    // Get maintenance events
    $maintenanceQuery = "
        SELECT 
            m.id,
            m.equipment_id,
            e.name AS equipment_name,
            m.scheduled_date,
            m.maintenance_type,
            m.description,
            m.status
        FROM 
            maintenance_schedule m
        JOIN 
            equipment e ON m.equipment_id = e.id
        WHERE 
            m.scheduled_date BETWEEN :start AND :end
            $equipmentFilter
        ORDER BY 
            m.scheduled_date
    ";
    
    $maintenanceStmt = $conn->prepare($maintenanceQuery);
    $maintenanceStmt->bindParam(':start', $start);
    $maintenanceStmt->bindParam(':end', $end);
    $maintenanceStmt->execute();
    $maintenanceEvents = $maintenanceStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get calendar events
    $eventsQuery = "
        SELECT 
            c.id,
            c.title,
            c.start_date,
            c.end_date,
            c.all_day,
            c.equipment_id,
            e.name AS equipment_name,
            c.event_type,
            c.description,
            c.color
        FROM 
            calendar_events c
        LEFT JOIN 
            equipment e ON c.equipment_id = e.id
        WHERE 
            c.start_date BETWEEN :start AND :end
            $equipmentFilter
            $eventTypeFilter
        ORDER BY 
            c.start_date
    ";
    
    $eventsStmt = $conn->prepare($eventsQuery);
    $eventsStmt->bindParam(':start', $start);
    $eventsStmt->bindParam(':end', $end);
    $eventsStmt->execute();
    $calendarEvents = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return combined results
    echo json_encode([
        'success' => true,
        'maintenance' => $maintenanceEvents,
        'events' => $calendarEvents
    ]);
    
} catch (PDOException $e) {
    // Return error
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching calendar events: ' . $e->getMessage()
    ]);
}
?>
