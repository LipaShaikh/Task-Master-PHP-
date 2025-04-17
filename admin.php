<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in and is an admin
if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Connect to database
$database = new Database();
$db = $database->getConnection();

// Check if user is an admin
$stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$user || $user['role'] !== 'admin') {
    // Log unauthorized access attempt
    $stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $_SESSION['user_id'],
        'unauthorized_access',
        'Attempted to access admin dashboard',
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // Redirect to dashboard
    header("Location: dashboard.php");
    exit();
}

// Get system stats
try {
    // Total users
    $stmt = $db->prepare("SELECT COUNT(*) as total_users FROM users");
    $stmt->execute();
    $total_users = $stmt->fetch(PDO::FETCH_ASSOC)['total_users'];
    
    // Active users (logged in within last 7 days)
    $stmt = $db->prepare("SELECT COUNT(*) as active_users FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stmt->execute();
    $active_users = $stmt->fetch(PDO::FETCH_ASSOC)['active_users'];
    
    // Total tasks
    $stmt = $db->prepare("SELECT COUNT(*) as total_tasks FROM tasks");
    $stmt->execute();
    $total_tasks = $stmt->fetch(PDO::FETCH_ASSOC)['total_tasks'];
    
    // Tasks completed in last 30 days
    $stmt = $db->prepare("SELECT COUNT(*) as completed_tasks FROM tasks WHERE status = 'completed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $completed_tasks = $stmt->fetch(PDO::FETCH_ASSOC)['completed_tasks'];
    
    // Recent user activity
    $stmt = $db->prepare("
        SELECT ua.*, u.email 
        FROM user_activity ua
        JOIN users u ON ua.user_id = u.id
        ORDER BY ua.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top performing users (most completed tasks in last 30 days)
    // Note: name column will be added in a future database update
    $stmt = $db->prepare("
        SELECT u.id, u.email, COUNT(t.id) as completed_count
        FROM users u
        JOIN tasks t ON u.id = t.user_id
        WHERE t.status = 'completed' AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY u.id
        ORDER BY completed_count DESC
        LIMIT 5
    ");
    $stmt->execute();
    $top_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log($e->getMessage());
    $error_message = "Failed to retrieve admin statistics";
}

// Log admin dashboard access
$stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
$stmt->execute([
    $_SESSION['user_id'],
    'admin_access',
    'Accessed admin dashboard',
    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - TaskMaster</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-md sticky top-0 z-50">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <a href="admin.php" class="text-2xl font-bold text-indigo-600">
                    <i class="fas fa-check-circle mr-2"></i>TaskMaster Admin
                </a>
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="text-gray-600 hover:text-indigo-600">
                        <i class="fas fa-home mr-1"></i>Main Site
                    </a>
                    <div class="flex items-center space-x-4">
                        <span class="text-gray-700">
                            <i class="fas fa-user mr-1"></i>
                            <?php echo htmlspecialchars($_SESSION['email']); ?>
                        </span>
                        <a href="logout.php" class="text-red-600 hover:text-red-800">
                            <i class="fas fa-sign-out-alt mr-1"></i>Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-wrap -mx-4">
            <div class="w-full lg:w-1/4 px-4">
                <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-700">Admin Menu</h3>
                    </div>
                    <div class="p-4">
                        <nav class="space-y-2">
                            <a href="admin.php" class="flex items-center px-4 py-3 text-indigo-600 bg-indigo-50 rounded-md">
                                <i class="fas fa-tachometer-alt mr-3"></i>
                                <span>Dashboard</span>
                            </a>
                            <a href="admin_users.php" class="flex items-center px-4 py-3 text-gray-600 hover:text-indigo-600 hover:bg-indigo-50 rounded-md">
                                <i class="fas fa-users mr-3"></i>
                                <span>User Management</span>
                            </a>
                            <a href="admin_reports.php" class="flex items-center px-4 py-3 text-gray-600 hover:text-indigo-600 hover:bg-indigo-50 rounded-md">
                                <i class="fas fa-chart-bar mr-3"></i>
                                <span>Reports</span>
                            </a>
                        </nav>
                    </div>
                </div>
            </div>

            <div class="w-full lg:w-3/4 px-4">
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">System Overview</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                        <div class="bg-gradient-to-r from-indigo-500 to-indigo-600 rounded-lg shadow-md p-6 text-white">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm opacity-80">Total Users</p>
                                    <h3 class="text-3xl font-bold"><?php echo $total_users; ?></h3>
                                </div>
                                <i class="fas fa-users text-4xl opacity-50"></i>
                            </div>
                        </div>
                        
                        <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-lg shadow-md p-6 text-white">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm opacity-80">Active Users (7d)</p>
                                    <h3 class="text-3xl font-bold"><?php echo $active_users; ?></h3>
                                </div>
                                <i class="fas fa-user-check text-4xl opacity-50"></i>
                            </div>
                        </div>
                        
                        <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg shadow-md p-6 text-white">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm opacity-80">Total Tasks</p>
                                    <h3 class="text-3xl font-bold"><?php echo $total_tasks; ?></h3>
                                </div>
                                <i class="fas fa-tasks text-4xl opacity-50"></i>
                            </div>
                        </div>
                        
                        <div class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg shadow-md p-6 text-white">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm opacity-80">Completed Tasks (30d)</p>
                                    <h3 class="text-3xl font-bold"><?php echo $completed_tasks; ?></h3>
                                </div>
                                <i class="fas fa-check-circle text-4xl opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="bg-white rounded-lg shadow border border-gray-200 p-6">
                            <h3 class="text-lg font-semibold text-gray-700 mb-4">
                                <i class="fas fa-trophy text-yellow-500 mr-2"></i>Top Performing Users
                            </h3>
                            <?php if (empty($top_users)): ?>
                                <p class="text-gray-500">No data available</p>
                            <?php else: ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full">
                                        <thead>
                                            <tr class="bg-gray-50">
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Completed Tasks</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            <?php foreach ($top_users as $user): ?>
                                                <tr>
                                                    <td class="px-4 py-3">
                                                        <div class="flex items-center">
                                                            <div class="flex-shrink-0 h-8 w-8 bg-indigo-100 rounded-full flex items-center justify-center">
                                                                <span class="text-indigo-800 font-medium text-sm">
                                                                    <?php echo substr($user['email'], 0, 1); ?>
                                                                </span>
                                                            </div>
                                                            <div class="ml-3">
                                                                <p class="text-sm font-medium text-gray-700">
                                                                    <?php echo htmlspecialchars('User'); ?>
                                                                </p>
                                                                <p class="text-xs text-gray-500">
                                                                    <?php echo htmlspecialchars($user['email']); ?>
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                            <?php echo $user['completed_count']; ?> tasks
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow border border-gray-200 p-6">
                            <h3 class="text-lg font-semibold text-gray-700 mb-4">
                                <i class="fas fa-history text-blue-500 mr-2"></i>Recent Activity
                            </h3>
                            <?php if (empty($recent_activity)): ?>
                                <p class="text-gray-500">No recent activity</p>
                            <?php else: ?>
                                <div class="space-y-4 max-h-80 overflow-y-auto">
                                    <?php foreach ($recent_activity as $activity): ?>
                                        <div class="flex items-start">
                                            <div class="flex-shrink-0 h-8 w-8 bg-blue-100 rounded-full flex items-center justify-center">
                                                <i class="fas fa-user-clock text-blue-600"></i>
                                            </div>
                                            <div class="ml-3">
                                                <p class="text-sm text-gray-700">
                                                    <span class="font-medium"><?php echo htmlspecialchars($activity['email']); ?></span>
                                                    <span class="text-gray-500">
                                                        <?php 
                                                            $action = str_replace('_', ' ', $activity['action']);
                                                            echo htmlspecialchars(ucwords($action)); 
                                                        ?>
                                                    </span>
                                                </p>
                                                <p class="text-xs text-gray-500">
                                                    <?php echo date('M j, Y g:i A', strtotime($activity['created_at'])); ?>
                                                </p>
                                                <?php if (!empty($activity['details'])): ?>
                                                    <p class="text-xs text-gray-600 mt-1">
                                                        <?php echo htmlspecialchars($activity['details']); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Quick Actions</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <a href="admin_users.php" class="bg-white border border-gray-200 rounded-lg p-4 flex flex-col items-center justify-center text-center hover:bg-indigo-50 hover:border-indigo-300 transition duration-300">
                            <i class="fas fa-user-cog text-3xl text-indigo-500 mb-2"></i>
                            <h3 class="text-lg font-semibold text-gray-700">Manage Users</h3>
                            <p class="text-sm text-gray-500 mt-1">Edit, deactivate or delete user accounts</p>
                        </a>
                        
                        <a href="admin_reports.php" class="bg-white border border-gray-200 rounded-lg p-4 flex flex-col items-center justify-center text-center hover:bg-indigo-50 hover:border-indigo-300 transition duration-300">
                            <i class="fas fa-file-export text-3xl text-indigo-500 mb-2"></i>
                            <h3 class="text-lg font-semibold text-gray-700">Generate Reports</h3>
                            <p class="text-sm text-gray-500 mt-1">Export CSV/PDF reports for all users</p>
                        </a>
                        
                        <a href="admin_reports.php?view=analytics" class="bg-white border border-gray-200 rounded-lg p-4 flex flex-col items-center justify-center text-center hover:bg-indigo-50 hover:border-indigo-300 transition duration-300">
                            <i class="fas fa-chart-line text-3xl text-indigo-500 mb-2"></i>
                            <h3 class="text-lg font-semibold text-gray-700">View Analytics</h3>
                            <p class="text-sm text-gray-500 mt-1">See task trends and user productivity</p>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Add any JavaScript functionality here
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize any components that need JavaScript
        });
    </script>
</body>
</html>