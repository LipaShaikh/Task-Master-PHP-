<?php
session_start();
require_once 'config/database.php';

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
    
    header("Location: admin_reports.php");
    exit();
}

// Get task statistics
try {
    // Tasks by status
    $stmt = $db->prepare("
        SELECT status, COUNT(*) as count 
        FROM tasks 
        WHERE user_id = ? 
        GROUP BY status
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $status_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Tasks by priority
    $stmt = $db->prepare("
        SELECT priority, COUNT(*) as count 
        FROM tasks 
        WHERE user_id = ? 
        GROUP BY priority
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $priority_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Completion rate last 30 days
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM tasks 
        WHERE user_id = ? 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $completion_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Upcoming deadlines
    $stmt = $db->prepare("
        SELECT * 
        FROM tasks 
        WHERE user_id = ? 
        AND status NOT IN ('completed', 'cancelled')
        AND deadline >= NOW()
        ORDER BY deadline ASC 
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $upcoming_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Task updates history
    $stmt = $db->prepare("
        SELECT tu.*, t.title
        FROM task_updates tu
        JOIN tasks t ON tu.task_id = t.id
        WHERE t.user_id = ?
        ORDER BY tu.update_date DESC
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Tasks created vs completed over time (last 30 days)
    $stmt = $db->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as created_count
        FROM tasks
        WHERE user_id = ?
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $tasks_created = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare("
        SELECT 
            DATE(updated_at) as date,
            COUNT(*) as completed_count
        FROM tasks
        WHERE user_id = ?
        AND status = 'completed'
        AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(updated_at)
        ORDER BY date
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $tasks_completed = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare data for trend chart
    $dates = [];
    $created_data = [];
    $completed_data = [];
    
    // Get all dates in the last 30 days
    $start_date = new DateTime(date('Y-m-d', strtotime('-30 days')));
    $end_date = new DateTime(date('Y-m-d'));
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($start_date, $interval, $end_date);
    
    foreach ($date_range as $date) {
        $date_str = $date->format('Y-m-d');
        $dates[] = $date->format('M j');
        
        // Find created count for this date
        $created_count = 0;
        foreach ($tasks_created as $task) {
            if ($task['date'] === $date_str) {
                $created_count = $task['created_count'];
                break;
            }
        }
        $created_data[] = $created_count;
        
        // Find completed count for this date
        $completed_count = 0;
        foreach ($tasks_completed as $task) {
            if ($task['date'] === $date_str) {
                $completed_count = $task['completed_count'];
                break;
            }
        }
        $completed_data[] = $completed_count;
    }

} catch(PDOException $e) {
    error_log($e->getMessage());
    $error_message = "Failed to generate reports";
}

// Calculate completion rate
$completion_rate = 0;
if ($completion_stats['total'] > 0) {
    $completion_rate = round(($completion_stats['completed'] / $completion_stats['total']) * 100);
}

// Prepare data for charts
$status_labels = [];
$status_data = [];
foreach ($status_stats as $stat) {
    $status_labels[] = ucfirst(str_replace('_', ' ', $stat['status']));
    $status_data[] = $stat['count'];
}

$priority_labels = [];
$priority_data = [];
foreach ($priority_stats as $stat) {
    $priority_labels[] = ucfirst($stat['priority']);
    $priority_data[] = $stat['count'];
}

// We'll just use the email from the session for now
// The name column will be added in a future database update
$user_name = null; // Initialize as null since we're not querying it

// Log reports page access
$stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
$stmt->execute([
    $_SESSION['user_id'],
    'reports_access',
    'User accessed reports page',
    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - TaskMaster</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <nav class="bg-white shadow-md sticky top-0 z-50">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <a href="dashboard.php" class="text-2xl font-bold text-indigo-600">
                    <i class="fas fa-check-circle mr-2"></i>TaskMaster
                </a>
                <div class="hidden md:flex space-x-6">
                    <a href="dashboard.php" class="text-gray-600 hover:text-indigo-600">
                        <i class="fas fa-home mr-1"></i>Dashboard
                    </a>
                    <a href="tasks.php" class="text-gray-600 hover:text-indigo-600">
                        <i class="fas fa-tasks mr-1"></i>Tasks
                    </a>
                    <a href="reports.php" class="text-indigo-600 font-medium">
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
                    <a href="dashboard.php" class="block py-2 px-4 text-gray-600 hover:text-indigo-600">
                        <i class="fas fa-home mr-1"></i>Dashboard
                    </a>
                    <a href="tasks.php" class="block py-2 px-4 text-gray-600 hover:text-indigo-600">
                        <i class="fas fa-tasks mr-1"></i>Tasks
                    </a>
                    <a href="reports.php" class="block py-2 px-4 text-indigo-600 font-medium">
                        <i class="fas fa-chart-bar mr-1"></i>Reports
                    </a>
                    <a href="logout.php" class="block py-2 px-4 text-gray-600 hover:text-indigo-600">
                        <i class="fas fa-sign-out-alt mr-1"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-8">
        <div class="grid grid-cols-1 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h5 class="font-semibold text-gray-700"><i class="fas fa-chart-pie mr-2 text-indigo-600"></i>30-Day Completion Rate</h5>
                </div>
                <div class="p-6">
                    <div class="flex flex-col items-center">
                        <div class="relative w-48 h-48">
                            <svg class="w-full h-full" viewBox="0 0 36 36">
                                <path class="stroke-current text-gray-200" stroke-width="3.8" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                <path class="stroke-current text-indigo-600" stroke-width="3.8" fill="none" stroke-linecap="round" stroke-dasharray="<?php echo $completion_rate; ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                <text x="18" y="20.5" font-size="10" font-weight="bold" text-anchor="middle" fill="#4f46e5"><?php echo $completion_rate; ?>%</text>
                            </svg>
                        </div>
                        <p class="text-gray-600 mt-4 flex items-center">
                            <i class="fas fa-check-circle text-green-500 mr-2"></i>
                            <?php echo $completion_stats['completed']; ?> of <?php echo $completion_stats['total']; ?> tasks completed
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h5 class="font-semibold text-gray-700"><i class="fas fa-chart-pie mr-2 text-indigo-600"></i>Tasks by Status</h5>
                </div>
                <div class="p-6">
                    <canvas id="statusChart" height="200"></canvas>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h5 class="font-semibold text-gray-700"><i class="fas fa-chart-column mr-2 text-indigo-600"></i>Tasks by Priority</h5>
                </div>
                <div class="p-6">
                    <canvas id="priorityChart" height="200"></canvas>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h5 class="font-semibold text-gray-700"><i class="fas fa-calendar-alt mr-2 text-indigo-600"></i>Upcoming Deadlines</h5>
                </div>
                <div class="p-6">
                    <?php if (empty($upcoming_tasks)): ?>
                        <p class="text-gray-500 text-center py-4">No upcoming deadlines</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($upcoming_tasks as $task): ?>
                                <div class="border border-gray-200 rounded-lg p-3 hover:bg-gray-50 transition duration-150">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <h6 class="font-medium text-gray-800"><?php echo htmlspecialchars($task['title']); ?></h6>
                                            <div class="flex items-center mt-1 space-x-2">
                                                <span class="px-2 py-1 text-xs rounded-full 
                                                    <?php 
                                                        $priority_class = '';
                                                        switch ($task['priority']) {
                                                            case 'high': $priority_class = 'bg-red-100 text-red-800'; break;
                                                            case 'medium': $priority_class = 'bg-yellow-100 text-yellow-800'; break;
                                                            case 'low': $priority_class = 'bg-blue-100 text-blue-800'; break;
                                                        }
                                                        echo $priority_class;
                                                    ?>">
                                                    <?php echo ucfirst($task['priority']); ?>
                                                </span>
                                                <span class="px-2 py-1 text-xs rounded-full 
                                                    <?php 
                                                        $status_class = '';
                                                        switch ($task['status']) {
                                                            case 'pending': $status_class = 'bg-yellow-100 text-yellow-800'; break;
                                                            case 'in_progress': $status_class = 'bg-blue-100 text-blue-800'; break;
                                                        }
                                                        echo $status_class;
                                                    ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span class="text-<?php 
                                                $days_until = (strtotime($task['deadline']) - time()) / (60 * 60 * 24);
                                                echo $days_until <= 1 ? 'red' : 
                                                    ($days_until <= 3 ? 'yellow' : 'green');
                                            ?>-600 font-medium">
                                                <?php echo date('M j, Y', strtotime($task['deadline'])); ?>
                                            </span>
                                            <div class="text-xs text-gray-500 mt-1">
                                                <?php echo date('g:i A', strtotime($task['deadline'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h5 class="font-semibold text-gray-700"><i class="fas fa-history mr-2 text-indigo-600"></i>Recent Activity</h5>
                </div>
                <div class="p-6">
                    <?php if (empty($recent_updates)): ?>
                        <p class="text-gray-500 text-center py-4">No recent activity</p>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($recent_updates as $update): ?>
                                <div class="flex">
                                    <div class="flex-shrink-0 mr-3">
                                        <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center">
                                            <i class="fas fa-exchange-alt text-indigo-600"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-800">
                                            <?php echo htmlspecialchars($update['title']); ?>
                                        </p>
                                        <p class="text-sm text-gray-600">
                                            Status changed from 
                                            <span class="font-medium">
                                                <?php echo ucfirst(str_replace('_', ' ', $update['status_from'])); ?>
                                            </span>
                                            to
                                            <span class="font-medium">
                                                <?php echo ucfirst(str_replace('_', ' ', $update['status_to'])); ?>
                                            </span>
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <?php echo date('M j, Y g:i A', strtotime($update['update_date'])); ?>
                                        </p>
                                        <?php if ($update['notes']): ?>
                                            <p class="text-xs text-gray-600 mt-1 italic">
                                                <?php echo htmlspecialchars($update['notes']); ?>
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
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuButton').addEventListener('click', function() {
            const mobileMenu = document.getElementById('mobileMenu');
            mobileMenu.classList.toggle('hidden');
        });
        
        // Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Status Chart
            const statusCtx = document.getElementById('statusChart').getContext('2d');
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($status_labels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($status_data); ?>,
                        backgroundColor: [
                            'rgba(245, 158, 11, 0.8)', // Pending - yellow
                            'rgba(59, 130, 246, 0.8)', // In Progress - blue
                            'rgba(34, 197, 94, 0.8)',  // Completed - green
                            'rgba(239, 68, 68, 0.8)'   // Cancelled - red
                        ],
                        borderWidth: 1,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                font: {
                                    size: 12,
                                    family: "'Inter', sans-serif"
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });

            // Priority Chart
            const priorityCtx = document.getElementById('priorityChart').getContext('2d');
            new Chart(priorityCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($priority_labels); ?>,
                    datasets: [{
                        label: 'Tasks',
                        data: <?php echo json_encode($priority_data); ?>,
                        backgroundColor: [
                            'rgba(59, 130, 246, 0.8)',  // Low - blue
                            'rgba(245, 158, 11, 0.8)',  // Medium - yellow
                            'rgba(239, 68, 68, 0.8)'    // High - red
                        ],
                        borderWidth: 1,
                        borderColor: '#fff',
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
            
            // Task Trend Chart initialization removed
        });
    </script>
</body>
</html>