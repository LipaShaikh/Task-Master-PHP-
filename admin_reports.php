<?php
session_start();
require_once 'config/database.php';
require_once 'pdf_generator.php'; // Include the PDF generator class

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
        'Attempted to access admin reports',
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // Redirect to dashboard
    header("Location: dashboard.php");
    exit();
}

// Handle report generation
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    $report_type = $_POST['report_type'] ?? '';
    $date_from = $_POST['date_from'] ?? '';
    $date_to = $_POST['date_to'] ?? '';
    $format = 'pdf'; // Only PDF format is supported
    
    if (empty($report_type) || empty($date_from) || empty($date_to)) {
        $error_message = "All fields are required to generate a report";
    } else {
        try {
            // Generate PDF report based on type
            $filename = "taskmaster_" . $report_type . "_" . date('Y-m-d') . ".pdf";
            
            switch ($report_type) {
                case 'task_completion':
                    // Create PDF generator with title and filename
                    $pdf = new PDFGenerator('Task Completion Report', $filename);
                    
                    // Set table headers
                    $pdf->setHeaders(['User', 'Task', 'Priority', 'Created', 'Completed']);
                    
                    // Query for completed tasks in date range
                    $stmt = $db->prepare("
                        SELECT u.email, t.title, t.priority, t.created_at, t.updated_at as completed_date
                        FROM tasks t
                        JOIN users u ON t.user_id = u.id
                        WHERE t.status = 'completed'
                        AND t.updated_at BETWEEN ? AND ?
                        ORDER BY u.email, t.updated_at
                    ");
                    $stmt->execute([$date_from, $date_to . ' 23:59:59']);
                    
                    // Prepare data for PDF
                    $data = [];
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $data[] = [
                            $row['email'],
                            $row['title'],
                            ucfirst($row['priority']),
                            date('M j, Y', strtotime($row['created_at'])),
                            date('M j, Y', strtotime($row['completed_date']))
                        ];
                    }
                    
                    // Set data and output PDF
                    $pdf->setData($data);
                    $pdf->output();
                    break;
                    
                case 'overdue_tasks':
                    // Create PDF generator with title and filename
                    $pdf = new PDFGenerator('Overdue Tasks Report', $filename);
                    
                    // Set table headers
                    $pdf->setHeaders(['User', 'Task', 'Priority', 'Deadline', 'Days Overdue']);
                    
                    // Query for overdue tasks in date range
                    $stmt = $db->prepare("
                        SELECT u.email, t.title, t.priority, t.deadline, DATEDIFF(NOW(), t.deadline) as days_overdue
                        FROM tasks t
                        JOIN users u ON t.user_id = u.id
                        WHERE t.status NOT IN ('completed', 'cancelled')
                        AND t.deadline < NOW()
                        AND t.created_at BETWEEN ? AND ?
                        ORDER BY days_overdue DESC
                    ");
                    $stmt->execute([$date_from, $date_to . ' 23:59:59']);
                    
                    // Prepare data for PDF
                    $data = [];
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $data[] = [
                            $row['email'],
                            $row['title'],
                            ucfirst($row['priority']),
                            date('M j, Y', strtotime($row['deadline'])),
                            $row['days_overdue']
                        ];
                    }
                    
                    // Set data and output PDF
                    $pdf->setData($data);
                    $pdf->output();
                    break;
                    
                case 'user_activity':
                    // Create PDF generator with title and filename
                    $pdf = new PDFGenerator('User Activity Report', $filename);
                    
                    // Set table headers
                    $pdf->setHeaders(['User', 'Action', 'Details', 'IP Address', 'Timestamp']);
                    
                    // Query for user activity in date range
                    $stmt = $db->prepare("
                        SELECT u.email, ua.action, ua.details, ua.ip_address, ua.created_at
                        FROM user_activity ua
                        JOIN users u ON ua.user_id = u.id
                        WHERE ua.created_at BETWEEN ? AND ?
                        ORDER BY ua.created_at DESC
                    ");
                    $stmt->execute([$date_from, $date_to . ' 23:59:59']);
                    
                    // Prepare data for PDF
                    $data = [];
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $data[] = [
                            $row['email'],
                            $row['action'],
                            $row['details'],
                            $row['ip_address'],
                            date('M j, Y H:i', strtotime($row['created_at']))
                        ];
                    }
                    
                    // Set data and output PDF
                    $pdf->setData($data);
                    $pdf->output();
                    break;
            }
        } catch(PDOException $e) {
            error_log($e->getMessage());
            $error_message = "Failed to generate report";
        }
    }
}

// Get analytics data for the view
$view = $_GET['view'] ?? 'reports';

// Analytics data
$analytics_data = [];

if ($view === 'analytics') {
    try {
        // Tasks created vs completed over time (last 30 days)
        $stmt = $db->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as created_count
            FROM tasks
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date
        ");
        $stmt->execute();
        $tasks_created = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $db->prepare("
            SELECT 
                DATE(updated_at) as date,
                COUNT(*) as completed_count
            FROM tasks
            WHERE status = 'completed'
            AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(updated_at)
            ORDER BY date
        ");
        $stmt->execute();
        $tasks_completed = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Prepare data for chart
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
        
        $analytics_data['task_trend'] = [
            'dates' => $dates,
            'created' => $created_data,
            'completed' => $completed_data
        ];
        
        // Task distribution by status
        $stmt = $db->prepare("
            SELECT status, COUNT(*) as count
            FROM tasks
            GROUP BY status
        ");
        $stmt->execute();
        $analytics_data['status_distribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Task distribution by priority
        $stmt = $db->prepare("
            SELECT priority, COUNT(*) as count
            FROM tasks
            GROUP BY priority
        ");
        $stmt->execute();
        $analytics_data['priority_distribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top 5 users by task completion
        // Note: name column will be added in a future database update
        $stmt = $db->prepare("
            SELECT u.id, u.email, COUNT(t.id) as completed_count
            FROM users u
            JOIN tasks t ON u.id = t.user_id
            WHERE t.status = 'completed'
            GROUP BY u.id
            ORDER BY completed_count DESC
            LIMIT 5
        ");
        $stmt->execute();
        $analytics_data['top_users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $e) {
        error_log($e->getMessage());
        $error_message = "Failed to retrieve analytics data";
    }
}

// Log admin reports access
$stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
$stmt->execute([
    $_SESSION['user_id'],
    'admin_access',
    'Accessed admin reports and analytics',
    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - TaskMaster Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50">
    <nav class="bg-white shadow-md sticky top-0 z-50">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <a href="admin.php" class="text-2xl font-bold text-teal-600">
                    <i class="fas fa-check-circle mr-2"></i>TaskMaster Admin
                </a>
                <div class="flex items-center space-x-4">
                    <a href="dashboard.php" class="text-gray-600 hover:text-teal-600">
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
                            <a href="admin.php" class="flex items-center px-4 py-3 text-gray-600 hover:text-teal-600 hover:bg-teal-50 rounded-md">
                                <i class="fas fa-tachometer-alt mr-3"></i>
                                <span>Dashboard</span>
                            </a>
                            <a href="admin_users.php" class="flex items-center px-4 py-3 text-gray-600 hover:text-teal-600 hover:bg-teal-50 rounded-md">
                                <i class="fas fa-users mr-3"></i>
                                <span>User Management</span>
                            </a>
                            <a href="admin_reports.php" class="flex items-center px-4 py-3 text-teal-600 bg-teal-50 rounded-md">
                                <i class="fas fa-chart-bar mr-3"></i>
                                <span>Reports</span>
                            </a>
                        </nav>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-700">View Options</h3>
                    </div>
                    <div class="p-4">
                        <nav class="space-y-2">
                            <a href="admin_reports.php" class="flex items-center px-4 py-3 <?php echo $view === 'reports' ? 'text-teal-600 bg-teal-50' : 'text-gray-600 hover:text-teal-600 hover:bg-teal-50'; ?> rounded-md">
                                <i class="fas fa-file-export mr-3"></i>
                                <span>Generate Reports</span>
                            </a>
                            <a href="admin_reports.php?view=analytics" class="flex items-center px-4 py-3 <?php echo $view === 'analytics' ? 'text-teal-600 bg-teal-50' : 'text-gray-600 hover:text-teal-600 hover:bg-teal-50'; ?> rounded-md">
                                <i class="fas fa-chart-line mr-3"></i>
                                <span>Analytics</span>
                            </a>
                        </nav>
                    </div>
                </div>
            </div>

            <div class="w-full lg:w-3/4 px-4">
                <?php if ($success_message): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded" role="alert">
                        <p><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded" role="alert">
                        <p><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($view === 'reports'): ?>
                    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                        <h2 class="text-xl font-bold text-gray-800 mb-4">Generate PDF Reports</h2>
                        
                        <form action="admin_reports.php" method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="report_type" class="block text-sm font-medium text-gray-700 mb-1">Report Type</label>
                                    <select id="report_type" name="report_type" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                        <option value="">Select a report type</option>
                                        <option value="task_completion">Task Completion</option>
                                        <option value="overdue_tasks">Overdue Tasks</option>
                                        <option value="user_activity">User Activity</option>
                                    </select>
                                </div>
                                
                                <!-- Format selection removed - only PDF is supported -->
                                <input type="hidden" name="format" value="pdf">
                                
                                <div>
                                    <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                                    <input type="date" id="date_from" name="date_from" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                </div>
                                
                                <div>
                                    <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                                    <input type="date" id="date_to" name="date_to" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                </div>
                            </div>
                            
                            <div class="flex justify-center mt-8">
                                <button type="submit" name="generate_report" class="px-8 py-4 bg-red-600 text-white text-xl font-bold rounded-lg hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-300 shadow-xl transform transition-transform duration-200 hover:scale-105 flex items-center border-2 border-white">
                                    <i class="fas fa-download mr-3 text-2xl"></i>DOWNLOAD REPORT
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 class="text-xl font-bold text-gray-800 mb-4">Report Descriptions</h2>
                        
                        <div class="space-y-4">
                            <div class="p-4 border border-gray-200 rounded-lg">
                                <h3 class="text-lg font-semibold text-teal-600 mb-2">
                                    <i class="fas fa-check-circle mr-2"></i>Task Completion Report
                                </h3>
                                <p class="text-gray-600">
                                    This report shows all completed tasks within the selected date range, including user information, task details, and completion dates.
                                </p>
                            </div>
                            
                            <div class="p-4 border border-gray-200 rounded-lg">
                                <h3 class="text-lg font-semibold text-yellow-600 mb-2">
                                    <i class="fas fa-exclamation-circle mr-2"></i>Overdue Tasks Report
                                </h3>
                                <p class="text-gray-600">
                                    This report shows all tasks that are past their deadline and not yet completed, including user information, task details, and the number of days overdue.
                                </p>
                            </div>
                            
                            <div class="p-4 border border-gray-200 rounded-lg">
                                <h3 class="text-lg font-semibold text-blue-700 mb-2">
                                    <i class="fas fa-history mr-2"></i>User Activity Report
                                </h3>
                                <p class="text-gray-600">
                                    This report shows all user activity within the selected date range, including logins, task updates, and administrative actions.
                                </p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="bg-white rounded-lg shadow-md p-6">
                            <h2 class="text-lg font-bold text-gray-800 mb-4">Task Status Distribution</h2>
                            <div class="h-64">
                                <canvas id="statusChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow-md p-6">
                            <h2 class="text-lg font-bold text-gray-800 mb-4">Task Priority Distribution</h2>
                            <div class="h-64">
                                <canvas id="priorityChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 class="text-xl font-bold text-gray-800 mb-4">Top Performing Users</h2>
                        
                        <?php if (empty($analytics_data['top_users'])): ?>
                            <p class="text-gray-500">No data available</p>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full">
                                    <thead>
                                        <tr class="bg-gray-50">
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rank</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Completed Tasks</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Completion Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php foreach ($analytics_data['top_users'] as $index => $user): ?>
                                            <tr>
                                                <td class="px-4 py-3 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div class="flex-shrink-0 h-8 w-8 rounded-full bg-teal-100 flex items-center justify-center">
                                                            <span class="text-teal-800 font-bold"><?php echo $index + 1; ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap">
                                                    <div class="flex items-center">
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
                                                <td class="px-4 py-3 whitespace-nowrap">
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-teal-100 text-teal-800">
                                                        <?php echo $user['completed_count']; ?> tasks
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap">
                                                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                                                        <?php 
                                                            // Calculate percentage based on highest count
                                                            $max_count = $analytics_data['top_users'][0]['completed_count'];
                                                            $percentage = ($user['completed_count'] / $max_count) * 100;
                                                        ?>
                                                        <div class="bg-teal-600 h-2.5 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($view === 'analytics' && !empty($analytics_data)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Task Trend Chart initialization removed
            
            // Status Distribution Chart
            const statusCtx = document.getElementById('statusChart').getContext('2d');
            const statusLabels = [];
            const statusData = [];
            const statusColors = {
                'pending': 'rgba(245, 158, 11, 0.8)',
                'in_progress': 'rgba(30, 64, 175, 0.8)',
                'completed': 'rgba(13, 148, 136, 0.8)',
                'cancelled': 'rgba(239, 68, 68, 0.8)'
            };
            const statusBackgroundColors = [];
            
            <?php foreach ($analytics_data['status_distribution'] as $status): ?>
                statusLabels.push('<?php echo ucfirst(str_replace('_', ' ', $status['status'])); ?>');
                statusData.push(<?php echo $status['count']; ?>);
                statusBackgroundColors.push(statusColors['<?php echo $status['status']; ?>']);
            <?php endforeach; ?>
            
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        data: statusData,
                        backgroundColor: statusBackgroundColors,
                        borderWidth: 1,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                        }
                    }
                }
            });
            
            // Priority Distribution Chart
            const priorityCtx = document.getElementById('priorityChart').getContext('2d');
            const priorityLabels = [];
            const priorityData = [];
            const priorityColors = {
                'low': 'rgba(30, 64, 175, 0.8)',
                'medium': 'rgba(245, 158, 11, 0.8)',
                'high': 'rgba(239, 68, 68, 0.8)'
            };
            const priorityBackgroundColors = [];
            
            <?php foreach ($analytics_data['priority_distribution'] as $priority): ?>
                priorityLabels.push('<?php echo ucfirst($priority['priority']); ?>');
                priorityData.push(<?php echo $priority['count']; ?>);
                priorityBackgroundColors.push(priorityColors['<?php echo $priority['priority']; ?>']);
            <?php endforeach; ?>
            
            new Chart(priorityCtx, {
                type: 'pie',
                data: {
                    labels: priorityLabels,
                    datasets: [{
                        data: priorityData,
                        backgroundColor: priorityBackgroundColors,
                        borderWidth: 1,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                        }
                    }
                }
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>