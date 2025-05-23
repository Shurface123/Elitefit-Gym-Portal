<?php
// Function to get archived users statistics
function getArchivedUsersData() {
    $conn = connectDB();
    
    try {
        // Check if archived_users table exists
        $checkTableStmt = $conn->prepare("
            SELECT COUNT(*) as table_exists 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = 'archived_users'
        ");
        $checkTableStmt->execute();
        $tableExists = $checkTableStmt->fetch(PDO::FETCH_ASSOC)['table_exists'];
        
        if (!$tableExists) {
            return [
                'total_archived' => 0,
                'archived_by_role' => [
                    'Member' => 0,
                    'Trainer' => 0,
                    'Admin' => 0,
                    'EquipmentManager' => 0
                ],
                'recent_archived' => [],
                'monthly_archived' => []
            ];
        }
        
        // Get total archived users
        $totalStmt = $conn->prepare("SELECT COUNT(*) as total FROM archived_users");
        $totalStmt->execute();
        $totalArchived = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get archived users by role
        $roleStmt = $conn->prepare("
            SELECT role, COUNT(*) as count 
            FROM archived_users 
            GROUP BY role
        ");
        $roleStmt->execute();
        $roleResults = $roleStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $archivedByRole = [
            'Member' => 0,
            'Trainer' => 0,
            'Admin' => 0,
            'EquipmentManager' => 0
        ];
        
        foreach ($roleResults as $result) {
            $archivedByRole[$result['role']] = (int)$result['count'];
        }
        
        // Get recent archived users
        $recentStmt = $conn->prepare("
            SELECT id, original_id, name, email, role, archived_at, archived_by, archive_reason
            FROM archived_users
            ORDER BY archived_at DESC
            LIMIT 5
        ");
        $recentStmt->execute();
        $recentArchived = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get monthly archived users for the last 6 months
        $monthlyStmt = $conn->prepare("
            SELECT 
                DATE_FORMAT(archived_at, '%Y-%m') as month,
                COUNT(*) as count
            FROM archived_users
            WHERE archived_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(archived_at, '%Y-%m')
            ORDER BY month ASC
        ");
        $monthlyStmt->execute();
        $monthlyArchived = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format monthly data for chart
        $formattedMonthly = [];
        foreach ($monthlyArchived as $month) {
            $date = new DateTime($month['month'] . '-01');
            $formattedMonthly[] = [
                'month' => $date->format('M Y'),
                'count' => (int)$month['count']
            ];
        }
        
        return [
            'total_archived' => $totalArchived,
            'archived_by_role' => $archivedByRole,
            'recent_archived' => $recentArchived,
            'monthly_archived' => $formattedMonthly
        ];
        
    } catch (Exception $e) {
        // Return empty data on error
        return [
            'total_archived' => 0,
            'archived_by_role' => [
                'Member' => 0,
                'Trainer' => 0,
                'Admin' => 0,
                'EquipmentManager' => 0
            ],
            'recent_archived' => [],
            'monthly_archived' => [],
            'error' => $e->getMessage()
        ];
    }
}

// Function to format archived users chart data
function formatArchivedChartData($monthlyData) {
    $labels = [];
    $data = [];
    
    foreach ($monthlyData as $item) {
        $labels[] = $item['month'];
        $data[] = $item['count'];
    }
    
    return [
        'labels' => $labels,
        'data' => $data
    ];
}
?>
