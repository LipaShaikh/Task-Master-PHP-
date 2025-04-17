<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Check if user has admin role and redirect if needed
if(isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    // Log the redirect
    $stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $_SESSION['user_id'],
        'admin_redirect',
        'Admin user redirected to admin dashboard',
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    header("Location: admin.php");
    exit();
}

// Get user's tasks count
$stmt = $db->prepare("SELECT 
    COUNT(*) as total_tasks,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks
FROM tasks WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$task_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get upcoming deadlines for display
$stmt = $db->prepare("
    SELECT id, title, deadline, priority
    FROM tasks 
    WHERE user_id = ? 
    AND status NOT IN ('completed', 'cancelled')
    AND deadline BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)
    ORDER BY deadline ASC
    LIMIT 5
");
$stmt->execute([$_SESSION['user_id']]);
$upcoming_deadlines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// We'll just use the email from the session for now
// The name column will be added in a future database update
$user_name = null; // Initialize as null since we're not querying it

// Log dashboard access
$stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
$stmt->execute([
    $_SESSION['user_id'],
    'dashboard_access',
    'User accessed dashboard',
    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - TaskMaster</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen">
    <nav class="bg-white shadow-md sticky top-0 z-50">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <a href="dashboard.php" class="text-2xl font-bold text-teal-600">
                    <i class="fas fa-check-circle mr-2"></i>TaskMaster
                </a>
                <div class="hidden md:flex space-x-6">
                    <a href="dashboard.php" class="text-teal-600 font-medium">
                        <i class="fas fa-home mr-1"></i>Dashboard
                    </a>
                    <a href="tasks.php" class="text-gray-600 hover:text-teal-600">
                        <i class="fas fa-tasks mr-1"></i>Tasks
                    </a>
                    <a href="reports.php" class="text-gray-600 hover:text-teal-600">
                        <i class="fas fa-chart-bar mr-1"></i>Reports
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-gray-700">
                        <i class="fas fa-user mr-1"></i>
                        <?php echo htmlspecialchars($_SESSION['email']); ?>
                    </span>
                    <a href="logout.php" class="text-red-600 hover:text-red-800">
                        <i class="fas fa-sign-out-alt mr-1"></i>Logout
                    </a>
                </div>
                <button class="md:hidden text-gray-600 focus:outline-none" id="mobileMenuButton">
                    <i class="fas fa-bars text-xl"></i>
                </button>
            </div>
            <!-- Mobile menu -->
            <div class="md:hidden hidden" id="mobileMenu">
                <div class="py-2 space-y-2">
                    <a href="dashboard.php" class="block py-2 px-4 text-teal-600 font-medium">
                        <i class="fas fa-home mr-1"></i>Dashboard
                    </a>
                    <a href="tasks.php" class="block py-2 px-4 text-gray-600 hover:text-teal-600">
                        <i class="fas fa-tasks mr-1"></i>Tasks
                    </a>
                    <a href="reports.php" class="block py-2 px-4 text-gray-600 hover:text-teal-600">
                        <i class="fas fa-chart-bar mr-1"></i>Reports
                    </a>
                    <a href="logout.php" class="block py-2 px-4 text-gray-600 hover:text-teal-600">
                        <i class="fas fa-sign-out-alt mr-1"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-8">
        <?php if (!empty($upcoming_deadlines)): ?>
            <div class="mb-6">
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-md">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <i class="fas fa-bell text-yellow-400 text-xl"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800">Upcoming Deadlines</h3>
                            <div class="mt-2 text-sm text-yellow-700 space-y-1">
                                <?php foreach ($upcoming_deadlines as $task): ?>
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <i class="fas fa-calendar-day mr-1"></i>
                                            <a href="tasks.php" class="font-medium hover:underline">
                                                <?php echo htmlspecialchars($task['title']); ?>
                                            </a>
                                        </div>
                                        <div class="flex items-center">
                                            <span class="px-2 py-1 text-xs rounded-full 
                                                <?php 
                                                    $priority_class = '';
                                                    switch ($task['priority']) {
                                                        case 'high': $priority_class = 'bg-red-100 text-red-800'; break;
                                                        case 'medium': $priority_class = 'bg-yellow-100 text-yellow-800'; break;
                                                        case 'low': $priority_class = 'bg-blue-100 text-blue-800'; break;
                                                    }
                                                    echo $priority_class;
                                                ?>
                                                mr-2">
                                                <?php echo ucfirst($task['priority']); ?>
                                            </span>
                                            <span class="text-sm">
                                                <?php 
                                                    $deadline = new DateTime($task['deadline']);
                                                    $now = new DateTime();
                                                    $interval = $now->diff($deadline);
                                                    
                                                    if ($interval->days == 0) {
                                                        echo '<span class="text-red-600 font-medium">Today</span>';
                                                    } elseif ($interval->days == 1) {
                                                        echo '<span class="text-orange-600 font-medium">Tomorrow</span>';
                                                    } else {
                                                        echo '<span class="text-yellow-600 font-medium">In ' . $interval->days . ' days</span>';
                                                    }
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-3xl font-bold text-teal-600"><?php echo $task_stats['total_tasks'] ?? 0; ?></h3>
                            <p class="text-gray-600 mt-1">Total Tasks</p>
                        </div>
                        <div class="bg-teal-100 p-3 rounded-full">
                            <i class="fas fa-tasks text-teal-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-3xl font-bold text-green-600"><?php echo $task_stats['completed_tasks'] ?? 0; ?></h3>
                            <p class="text-gray-600 mt-1">Completed Tasks</p>
                        </div>
                        <div class="bg-green-100 p-3 rounded-full">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-3xl font-bold text-blue-700"><?php echo $task_stats['in_progress_tasks'] ?? 0; ?></h3>
                            <p class="text-gray-600 mt-1">In Progress</p>
                        </div>
                        <div class="bg-blue-100 p-3 rounded-full">
                            <i class="fas fa-spinner text-blue-700 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-3xl font-bold text-amber-600"><?php echo $task_stats['pending_tasks'] ?? 0; ?></h3>
                            <p class="text-gray-600 mt-1">Pending Tasks</p>
                        </div>
                        <div class="bg-amber-100 p-3 rounded-full">
                            <i class="fas fa-clock text-amber-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h5 class="font-semibold text-gray-700"><i class="fas fa-chart-line mr-2 text-teal-600"></i>Task Progress</h5>
                    </div>
                    <div class="p-6">
                        <?php
                        $total = $task_stats['total_tasks'] ?? 0;
                        $completed_percent = $total > 0 ? round(($task_stats['completed_tasks'] / $total) * 100) : 0;
                        $in_progress_percent = $total > 0 ? round(($task_stats['in_progress_tasks'] / $total) * 100) : 0;
                        $pending_percent = $total > 0 ? round(($task_stats['pending_tasks'] / $total) * 100) : 0;
                        ?>
                        
                        <div class="h-6 bg-gray-200 rounded-full overflow-hidden mb-4">
                            <div class="flex h-full">
                                <div class="bg-green-500 h-full" style="width: <?php echo $completed_percent; ?>%" title="Completed: <?php echo $completed_percent; ?>%"></div>
                                <div class="bg-blue-500 h-full" style="width: <?php echo $in_progress_percent; ?>%" title="In Progress: <?php echo $in_progress_percent; ?>%"></div>
                                <div class="bg-amber-500 h-full" style="width: <?php echo $pending_percent; ?>%" title="Pending: <?php echo $pending_percent; ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="flex justify-between text-sm text-gray-600">
                            <div class="flex items-center">
                                <div class="w-3 h-3 bg-green-500 rounded-full mr-2"></div>
                                <span>Completed (<?php echo $completed_percent; ?>%)</span>
                            </div>
                            <div class="flex items-center">
                                <div class="w-3 h-3 bg-blue-500 rounded-full mr-2"></div>
                                <span>In Progress (<?php echo $in_progress_percent; ?>%)</span>
                            </div>
                            <div class="flex items-center">
                                <div class="w-3 h-3 bg-amber-500 rounded-full mr-2"></div>
                                <span>Pending (<?php echo $pending_percent; ?>%)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h5 class="font-semibold text-gray-700"><i class="fas fa-plus-circle mr-2 text-teal-600"></i>Quick Actions</h5>
                    </div>
                    <div class="p-6">
                        <a href="tasks.php" class="block w-full bg-teal-600 text-center py-3 px-4 rounded-md  border border-teal-600 hover:bg-teal-700 transition hover:bg-teal-50 duration-200 mb-4 font-medium">
                            <i class="fas fa-plus mr-2"></i>Add New Task
                        </a>
                        <a href="reports.php" class="block w-full bg-white text-teal-600 text-center py-3 px-4 rounded-md border border-teal-600 hover:bg-teal-50 transition duration-200">
                            <i class="fas fa-chart-bar mr-2"></i>View Reports
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuButton').addEventListener('click', function() {
            const mobileMenu = document.getElementById('mobileMenu');
            mobileMenu.classList.toggle('hidden');
        });
    </script>
</body>
</html>