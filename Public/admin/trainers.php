<?php
// Include authentication middleware
require_once __DIR__ . '/../auth_middleware.php';

// Require Admin role to access this page
requireRole('Admin', '../login.php');

// Get user data
$userId = $_SESSION['user_id'];
$userName = $_SESSION['name'];

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$specialization = isset($_GET['specialization']) ? $_GET['specialization'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Connect to database
$conn = connectDB();

// Build query based on filters
$query = "SELECT id, name, email, experience_level AS specialization, fitness_goals AS experience, preferred_routines AS approach, created_at FROM users WHERE role = 'Trainer'";
$countQuery = "SELECT COUNT(*) as total FROM users WHERE role = 'Trainer'";
$params = [];

if (!empty($search)) {
  $query .= " AND (name LIKE ? OR email LIKE ?)";
  $countQuery .= " AND (name LIKE ? OR email LIKE ?)";
  $searchParam = "%$search%";
  $params[] = $searchParam;
  $params[] = $searchParam;
}

if (!empty($specialization)) {
  $query .= " AND experience_level = ?";
  $countQuery .= " AND experience_level = ?";
  $params[] = $specialization;
}

// Add pagination - FIX: Use integers directly in the query instead of parameters
$limitOffset = " ORDER BY created_at DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
$query .= $limitOffset;

// Execute queries
$stmt = $conn->prepare($query);
$stmt->execute($params);
$trainers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$countStmt = $conn->prepare($countQuery);
$countStmt->execute($params);
$totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalCount / $perPage);

// Get all specializations for filter dropdown
$specializationStmt = $conn->prepare("SELECT DISTINCT experience_level FROM users WHERE role = 'Trainer' AND experience_level IS NOT NULL ORDER BY experience_level");
$specializationStmt->execute();
$specializations = $specializationStmt->fetchAll(PDO::FETCH_COLUMN);

// Get theme preference
$theme = isset($_COOKIE['admin_theme']) ? $_COOKIE['admin_theme'] : 'dark';

// Check for success or error messages
$success = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$error = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Manage Trainers - EliteFit Gym</title>
   <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
   <style>
       :root {
           --primary: #ff4d4d;
           --secondary: #333;
           --light: #f8f9fa;
           --dark: #212529;
           --success: #28a745;
           --danger: #dc3545;
           --warning: #ffc107;
           --info: #17a2b8;
           --border-radius: 8px;
           --orange: #FF8C00;
           
           /* Light theme variables */
           --bg-light: #f5f7fa;
           --text-light: #333;
           --card-light: #ffffff;
           --border-light: #e0e0e0;
           --sidebar-light: #ffffff;
           --sidebar-text-light: #333;
           --sidebar-hover-light: #f0f0f0;
           
           /* Dark theme variables */
           --bg-dark: #121212;
           --text-dark: #e0e0e0;
           --card-dark: #1e1e1e;
           --border-dark: #333;
           --sidebar-dark: #1a1a1a;
           --sidebar-text-dark: #e0e0e0;
           --sidebar-hover-dark: #2a2a2a;
       }
       
       [data-theme="light"] {
           --bg-color: var(--bg-light);
           --text-color: var(--text-light);
           --card-bg: var(--card-light);
           --border-color: var(--border-light);
           --sidebar-bg: var(--sidebar-light);
           --sidebar-text: var(--sidebar-text-light);
           --sidebar-hover: var(--sidebar-hover-light);
           --header-bg: var(--card-light);
       }
       
       [data-theme="dark"] {
           --bg-color: var(--bg-dark);
           --text-color: var(--text-dark);
           --card-bg: var(--card-dark);
           --border-color: var(--border-dark);
           --sidebar-bg: var(--sidebar-dark);
           --sidebar-text: var(--sidebar-text-dark);
           --sidebar-hover: var(--sidebar-hover-dark);
           --header-bg: var(--card-dark);
       }
       
       * {
           margin: 0;
           padding: 0;
           box-sizing: border-box;
           font-family: 'Poppins', sans-serif;
       }
       
       body {
           background-color: var(--bg-color);
           color: var(--text-color);
           transition: all 0.3s ease;
       }
       
       .dashboard-container {
           display: flex;
           min-height: 100vh;
       }
       
       .sidebar {
           width: 250px;
           background-color: var(--sidebar-bg);
           color: var(--sidebar-text);
           padding: 20px;
           position: fixed;
           height: 100vh;
           overflow-y: auto;
           transition: all 0.3s ease;
           border-right: 1px solid var(--border-color);
           z-index: 1000;
       }
       
       .sidebar-header {
           display: flex;
           align-items: center;
           margin-bottom: 30px;
           padding-bottom: 20px;
           border-bottom: 1px solid var(--border-color);
       }
       
       .sidebar-header h2 {
           font-size: 1.5rem;
           margin-left: 10px;
           color: var(--orange);
       }
       
       .sidebar-menu {
           list-style: none;
       }
       
       .sidebar-menu li {
           margin-bottom: 5px;
       }
       
       .sidebar-menu a {
           display: flex;
           align-items: center;
           padding: 12px 15px;
           color: var(--sidebar-text);
           text-decoration: none;
           border-radius: var(--border-radius);
           transition: all 0.3s;
       }
       
       .sidebar-menu a:hover, .sidebar-menu a.active {
           background-color: var(--sidebar-hover);
       }
       
       .sidebar-menu a i {
           margin-right: 10px;
           width: 20px;
           text-align: center;
       }
       
       .main-content {
           flex: 1;
           margin-left: 250px;
           padding: 20px;
           transition: all 0.3s ease;
       }
       
       .header {
           display: flex;
           justify-content: space-between;
           align-items: center;
           margin-bottom: 30px;
           background-color: var(--header-bg);
           padding: 15px 20px;
           border-radius: var(--border-radius);
           box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
           transition: all 0.3s ease;
       }
       
       .header h1 {
           font-size: 1.8rem;
           color: var(--text-color);
       }
       
       .user-info {
           display: flex;
           align-items: center;
       }
       
       .user-info img {
           width: 40px;
           height: 40px;
           border-radius: 50%;
           margin-right: 10px;
           object-fit: cover;
           border: 2px solid var(--orange);
       }
       
       .user-info .dropdown {
           position: relative;
       }
       
       .user-info .dropdown-toggle {
           cursor: pointer;
           display: flex;
           align-items: center;
           color: var(--text-color);
       }
       
       .user-info .dropdown-menu {
           position: absolute;
           right: 0;
           top: 100%;
           background-color: var(--card-bg);
           border-radius: var(--border-radius);
           box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
           padding: 10px 0;
           min-width: 180px;
           z-index: 1000;
           display: none;
           border: 1px solid var(--border-color);
       }
       
       .user-info .dropdown-menu.show {
           display: block;
       }
       
       .user-info .dropdown-menu a {
           display: block;
           padding: 8px 20px;
           color: var(--text-color);
           text-decoration: none;
           transition: all 0.3s;
       }
       
       .user-info .dropdown-menu a:hover {
           background-color: var(--sidebar-hover);
       }
       
       .card {
           background-color: var(--card-bg);
           border-radius: var(--border-radius);
           box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
           padding: 20px;
           transition: all 0.3s ease;
           border: 1px solid var(--border-color);
           margin-bottom: 20px;
       }
       
       .filter-section {
           display: flex;
           flex-wrap: wrap;
           gap: 15px;
           margin-bottom: 20px;
           align-items: center;
       }
       
       .filter-group {
           display: flex;
           align-items: center;
           gap: 10px;
       }
       
       .filter-label {
           font-weight: 500;
           color: var(--text-color);
       }
       
       .filter-select, .filter-input {
           padding: 8px 12px;
           border-radius: var(--border-radius);
           border: 1px solid var(--border-color);
           background-color: var(--card-bg);
           color: var(--text-color);
       }
       
       .filter-select:focus, .filter-input:focus {
           outline: none;
           border-color: var(--orange);
       }
       
       .table-container {
           overflow-x: auto;
       }
       
       .table {
           width: 100%;
           border-collapse: collapse;
       }
       
       .table th, .table td {
           padding: 12px 15px;
           text-align: left;
           border-bottom: 1px solid var(--border-color);
       }
       
       .table th {
           font-weight: 600;
           color: var(--text-color);
           background-color: var(--sidebar-hover);
       }
       
       .badge {
           display: inline-block;
           padding: 3px 8px;
           border-radius: 50px;
           font-size: 0.75rem;
           font-weight: 500;
           color: white;
       }
       
       .badge-success {
           background-color: var(--success);
       }
       
       .badge-danger {
           background-color: var(--danger);
       }
       
       .badge-warning {
           background-color: var(--warning);
       }
       
       .badge-info {
           background-color: var(--info);
       }
       
       .badge-primary {
           background-color: var(--primary);
       }
       
       .btn {
           padding: 8px 15px;
           background-color: var(--orange);
           color: white;
           border: none;
           border-radius: var(--border-radius);
           cursor: pointer;
           transition: all 0.3s;
           text-decoration: none;
           display: inline-block;
       }
       
       .btn:hover {
           background-color: #e67e00;
       }
       
       .btn-sm {
           padding: 5px 10px;
           font-size: 0.9rem;
       }
       
       .btn-danger {
           background-color: var(--danger);
       }
       
       .btn-danger:hover {
           background-color: #c82333;
       }
       
       .btn-success {
           background-color: var(--success);
       }
       
       .btn-success:hover {
           background-color: #218838;
       }
       
       .btn-warning {
           background-color: var(--warning);
           color: #212529;
       }
       
       .btn-warning:hover {
           background-color: #e0a800;
       }
       
       .btn-info {
           background-color: var(--info);
       }
       
       .btn-info:hover {
           background-color: #138496;
       }
       
       .pagination {
           display: flex;
           justify-content: center;
           margin-top: 20px;
           gap: 5px;
       }
       
       .pagination a, .pagination span {
           display: inline-block;
           padding: 5px 10px;
           border-radius: var(--border-radius);
           text-decoration: none;
           border: 1px solid var(--border-color);
           color: var(--text-color);
           background-color: var(--card-bg);
           transition: all 0.3s;
       }
       
       .pagination a:hover {
           background-color: var(--sidebar-hover);
       }
       
       .pagination .active {
           background-color: var(--orange);
           color: white;
           border-color: var(--orange);
       }
       
       .pagination .disabled {
           opacity: 0.5;
           cursor: not-allowed;
       }
       
       .alert {
           padding: 10px 15px;
           border-radius: var(--border-radius);
           margin-bottom: 20px;
           border: 1px solid transparent;
       }
       
       .alert-success {
           background-color: rgba(40, 167, 69, 0.2);
           border-color: rgba(40, 167, 69, 0.3);
           color: #28a745;
       }
       
       .alert-danger {
           background-color: rgba(220, 53, 69, 0.2);
           border-color: rgba(220, 53, 69, 0.3);
           color: #dc3545;
       }
       
       .truncate {
           max-width: 200px;
           white-space: nowrap;
           overflow: hidden;
           text-overflow: ellipsis;
       }
       
       @media (max-width: 768px) {
           .sidebar {
               width: 70px;
               padding: 20px 10px;
           }
           
           .sidebar-header h2, .sidebar-menu a span {
               display: none;
           }
           
           .main-content {
               margin-left: 70px;
           }
           
           .header {
               flex-direction: column;
               align-items: flex-start;
           }
           
           .user-info {
               margin-top: 15px;
               width: 100%;
               justify-content: space-between;
           }
           
           .filter-section {
               flex-direction: column;
               align-items: flex-start;
           }
           
           .filter-group {
               width: 100%;
           }
       }
   </style>
</head>
<body>
   <div class="dashboard-container">
       <!-- Sidebar -->
       <div class="sidebar">
           <div class="sidebar-header">
               <i class="fas fa-dumbbell fa-2x" style="color: var(--orange);"></i>
               <h2>EliteFit Gym</h2>
           </div>
           <ul class="sidebar-menu">
               <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
               <li><a href="users.php"><i class="fas fa-users"></i> <span>Users</span></a></li>
               <li><a href="trainers.php" class="active"><i class="fas fa-user-tie"></i> <span>Trainers</span></a></li>
               <li><a href="equipment-managers.php"><i class="fas fa-dumbbell"></i> <span>Equipment</span></a></li>
               <li><a href="reports.php"><i class="fas fa-chart-line"></i> <span>Reports</span></a></li>
               <li><a href="admin_settings.php"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
               <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
           </ul>
       </div>
       
       <!-- Main Content -->
       <div class="main-content">
           <!-- Header -->
           <div class="header">
               <div>
                   <h1>Manage Trainers</h1>
                   <p>View and manage all trainers in the system</p>
               </div>
               <div class="user-info">
                   <img src="https://randomuser.me/api/portraits/women/1.jpg" alt="User Avatar">
                   <div class="dropdown">
                       <div class="dropdown-toggle" onclick="toggleDropdown()">
                           <span><?php echo htmlspecialchars($userName); ?></span>
                           <i class="fas fa-chevron-down ml-2"></i>
                       </div>
                       <div class="dropdown-menu" id="userDropdown">
                           <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                           <a href="admin_settings.php"><i class="fas fa-cog"></i> Settings</a>
                           <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                       </div>
                   </div>
               </div>
           </div>
           
           <?php if (!empty($success)): ?>
               <div class="alert alert-success">
                   <?php echo htmlspecialchars($success); ?>
               </div>
           <?php endif; ?>
           
           <?php if (!empty($error)): ?>
               <div class="alert alert-danger">
                   <?php echo htmlspecialchars($error); ?>
               </div>
           <?php endif; ?>
           
           <div class="card">
               <form action="" method="get">
                   <div class="filter-section">
                       <div class="filter-group">
                           <label class="filter-label" for="specialization">Specialization:</label>
                           <select name="specialization" id="specialization" class="filter-select">
                               <option value="">All Specializations</option>
                               <?php foreach ($specializations as $spec): ?>
                                   <option value="<?php echo htmlspecialchars($spec); ?>" <?php echo $specialization === $spec ? 'selected' : ''; ?>>
                                       <?php echo htmlspecialchars($spec); ?>
                                   </option>
                               <?php endforeach; ?>
                           </select>
                       </div>
                       
                       <div class="filter-group">
                           <label class="filter-label" for="search">Search:</label>
                           <input type="text" name="search" id="search" class="filter-input" placeholder="Search by name or email" value="<?php echo htmlspecialchars($search); ?>">
                       </div>
                       
                       <button type="submit" class="btn btn-sm">Apply Filters</button>
                       <a href="trainers.php" class="btn btn-sm" style="background-color: var(--secondary);">Reset</a>
                   </div>
               </form>
               
               <div class="table-container">
                   <table class="table">
                       <thead>
                           <tr>
                               <th>ID</th>
                               <th>Name</th>
                               <th>Email</th>
                               <th>Specialization</th>
                               <th>Experience</th>
                               <th>Joined</th>
                               <th>Actions</th>
                           </tr>
                       </thead>
                       <tbody>
                           <?php if (count($trainers) > 0): ?>
                               <?php foreach ($trainers as $trainer): ?>
                                   <tr>
                                       <td><?php echo $trainer['id']; ?></td>
                                       <td><?php echo htmlspecialchars($trainer['name']); ?></td>
                                       <td><?php echo htmlspecialchars($trainer['email']); ?></td>
                                       <td>
                                           <span class="badge badge-success">
                                               <?php echo htmlspecialchars($trainer['specialization'] ?? 'N/A'); ?>
                                           </span>
                                       </td>
                                       <td class="truncate" title="<?php echo htmlspecialchars($trainer['experience'] ?? ''); ?>">
                                           <?php echo htmlspecialchars($trainer['experience'] ?? 'N/A'); ?>
                                       </td>
                                       <td><?php echo date('M d, Y', strtotime($trainer['created_at'])); ?></td>
                                       <td>
                                           <a href="view-trainer.php?id=<?php echo $trainer['id']; ?>" class="btn btn-sm btn-info" title="View"><i class="fas fa-eye"></i></a>
                                           <a href="edit-user.php?id=<?php echo $trainer['id']; ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                                           <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $trainer['id']; ?>, '<?php echo htmlspecialchars($trainer['name']); ?>')" class="btn btn-sm btn-danger" title="Delete"><i class="fas fa-trash"></i></a>
                                       </td>
                                   </tr>
                               <?php endforeach; ?>
                           <?php else: ?>
                               <tr>
                                   <td colspan="7" style="text-align: center;">No trainers found.</td>
                               </tr>
                           <?php endif; ?>
                       </tbody>
                   </table>
               </div>
               
               <!-- Pagination -->
               <?php if ($totalPages > 1): ?>
                   <div class="pagination">
                       <?php if ($page > 1): ?>
                           <a href="?page=<?php echo $page - 1; ?>&specialization=<?php echo urlencode($specialization); ?>&search=<?php echo urlencode($search); ?>">Previous</a>
                       <?php else: ?>
                           <span class="disabled">Previous</span>
                       <?php endif; ?>
                       
                       <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                           <?php if ($i == $page): ?>
                               <span class="active"><?php echo $i; ?></span>
                           <?php else: ?>
                               <a href="?page=<?php echo $i; ?>&specialization=<?php echo urlencode($specialization); ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                           <?php endif; ?>
                       <?php endfor; ?>
                       
                       <?php if ($page < $totalPages): ?>
                           <a href="?page=<?php echo $page + 1; ?>&specialization=<?php echo urlencode($specialization); ?>&search=<?php echo urlencode($search); ?>">Next</a>
                       <?php else: ?>
                           <span class="disabled">Next</span>
                       <?php endif; ?>
                   </div>
               <?php endif; ?>
           </div>
       </div>
   </div>
   
   <!-- Delete Confirmation Modal -->
   <div id="deleteModal" style="display: none; position: fixed; z-index: 1050; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
       <div style="background-color: var(--card-bg); margin: 15% auto; padding: 20px; border: 1px solid var(--border-color); border-radius: var(--border-radius); width: 50%; max-width: 500px; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
           <h3 style="margin-bottom: 20px; color: var(--text-color);">Confirm Deletion</h3>
           <p style="margin-bottom: 20px; color: var(--text-color);">Are you sure you want to delete trainer <span id="deleteUserName"></span>? This action cannot be undone and will remove all associated workout plans.</p>
           <div style="display: flex; justify-content: flex-end; gap: 10px;">
               <button onclick="closeDeleteModal()" class="btn btn-sm" style="background-color: var(--secondary);">Cancel</button>
               <form id="deleteForm" action="delete-user.php" method="post">
                   <input type="hidden" id="deleteUserId" name="user_id">
                   <button type="submit" class="btn btn-sm btn-danger">Delete</button>
               </form>
           </div>
       </div>
   </div>
   
   <script>
       // Toggle dropdown menu
       function toggleDropdown() {
           document.getElementById('userDropdown').classList.toggle('show');
       }
       
       // Close dropdown when clicking outside
       window.onclick = function(event) {
           if (!event.target.matches('.dropdown-toggle') && !event.target.matches('.dropdown-toggle *')) {
               var dropdowns = document.getElementsByClassName('dropdown-menu');
               for (var i = 0; i < dropdowns.length; i++) {
                   var openDropdown = dropdowns[i];
                   if (openDropdown.classList.contains('show')) {
                       openDropdown.classList.remove('show');
                   }
               }
           }
           
           // Close modal when clicking outside
           if (event.target.id === 'deleteModal') {
               closeDeleteModal();
           }
       }
       
       // Delete user confirmation
       function confirmDelete(userId, userName) {
           document.getElementById('deleteUserId').value = userId;
           document.getElementById('deleteUserName').textContent = userName;
           document.getElementById('deleteModal').style.display = 'block';
       }
       
       function closeDeleteModal() {
           document.getElementById('deleteModal').style.display = 'none';
       }
   </script>
</body>
</html>
