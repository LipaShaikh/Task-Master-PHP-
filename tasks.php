<?php
session_start();
require_once 'config/database.php';

// Redirect if not logged in
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

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($_POST['action']) {
        case 'add_task':
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $deadline = $_POST['deadline'] ?? '';
            $priority = $_POST['priority'] ?? 'medium';

            // Enhanced validation for title
            if (empty($title) || empty($deadline)) {
                $error_message = "Title and deadline are required";
            } elseif (strlen($title) < 3) {
                $error_message = "Title must be at least 3 characters long";
            } elseif (strlen($title) > 100) {
                $error_message = "Title must not exceed 100 characters";
            } else {
                try {
                    $stmt = $db->prepare("INSERT INTO tasks (user_id, title, description, deadline, priority) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $title, $description, $deadline, $priority]);
                    
                    // Log the action
                    $task_id = $db->lastInsertId();
                    $stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
                    $stmt->execute([
                        $_SESSION['user_id'],
                        'add_task',
                        "Added task #$task_id: $title",
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                    
                    $success_message = "Task added successfully";
                } catch(PDOException $e) {
                    $error_message = "Failed to add task";
                    error_log($e->getMessage());
                }
            }
            break;

        case 'update_status':
            $task_id = $_POST['task_id'] ?? '';
            $new_status = $_POST['status'] ?? '';

            if (!empty($task_id) && !empty($new_status)) {
                try {
                    $db->beginTransaction();

                    // Get current status
                    $stmt = $db->prepare("SELECT status, title FROM tasks WHERE id = ? AND user_id = ?");
                    $stmt->execute([$task_id, $_SESSION['user_id']]);
                    $task = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($task) {
                        // Update task status
                        $stmt = $db->prepare("UPDATE tasks SET status = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                        $stmt->execute([$new_status, $task_id, $_SESSION['user_id']]);

                        // Log the status change
                        $stmt = $db->prepare("INSERT INTO task_updates (task_id, user_id, status_from, status_to) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$task_id, $_SESSION['user_id'], $task['status'], $new_status]);
                        
                        // Log the action
                        $stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
                        $stmt->execute([
                            $_SESSION['user_id'],
                            'update_task_status',
                            "Updated task #$task_id ({$task['title']}) status from {$task['status']} to $new_status",
                            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                        ]);

                        $db->commit();
                        $success_message = "Task status updated";
                    }
                } catch(PDOException $e) {
                    $db->rollBack();
                    $error_message = "Failed to update status";
                    error_log($e->getMessage());
                }
            }
            break;

        case 'delete_task':
            $task_id = $_POST['task_id'] ?? '';

            if (!empty($task_id)) {
                try {
                    // Get task title for logging
                    $stmt = $db->prepare("SELECT title FROM tasks WHERE id = ? AND user_id = ?");
                    $stmt->execute([$task_id, $_SESSION['user_id']]);
                    $task_title = $stmt->fetchColumn();
                    
                    $stmt = $db->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
                    $stmt->execute([$task_id, $_SESSION['user_id']]);
                    
                    // Log the action
                    $stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
                    $stmt->execute([
                        $_SESSION['user_id'],
                        'delete_task',
                        "Deleted task #$task_id: $task_title",
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                    
                    $success_message = "Task deleted successfully";
                } catch(PDOException $e) {
                    $error_message = "Failed to delete task";
                    error_log($e->getMessage());
                }
            }
            break;
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$priority_filter = $_GET['priority'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search_query = $_GET['search'] ?? '';

// Fetch tasks with filters
$where_conditions = ["user_id = ?"];
$params = [$_SESSION['user_id']];

if ($status_filter !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

if ($priority_filter !== 'all') {
    $where_conditions[] = "priority = ?";
    $params[] = $priority_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "deadline >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "deadline <= ?";
    $params[] = $date_to . ' 23:59:59';
}

if (!empty($search_query)) {
    $where_conditions[] = "(title LIKE ? OR description LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$where_clause = implode(" AND ", $where_conditions);
$sql = "SELECT * FROM tasks WHERE $where_clause ORDER BY deadline ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// We'll just use the email from the session for now
// The name column will be added in a future database update
$user_name = null; // Initialize as null since we're not querying it

// Log tasks page access
$stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
$stmt->execute([
    $_SESSION['user_id'],
    'tasks_access',
    'User accessed tasks page',
    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tasks - TaskMaster</title>
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
                    <a href="dashboard.php" class="text-gray-600 hover:text-teal-600">
                        <i class="fas fa-home mr-1"></i>Dashboard
                    </a>
                    <a href="tasks.php" class="text-teal-600 font-medium">
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
                    <a href="dashboard.php" class="block py-2 px-4 text-gray-600 hover:text-teal-600">
                        <i class="fas fa-home mr-1"></i>Dashboard
                    </a>
                    <a href="tasks.php" class="block py-2 px-4 text-teal-600 font-medium">
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

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200 mb-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h5 class="font-semibold text-gray-700"><i class="fas fa-plus-circle mr-2 text-teal-600"></i>Add New Task</h5>
                    </div>
                    <div class="p-6">
                        <form method="POST" action="tasks.php" id="addTaskForm">
                            <input type="hidden" name="action" value="add_task">
                            
                            <div class="mb-4">
                                <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-heading text-gray-400"></i>
                                    </div>
                                    <input type="text" id="title" name="title" 
                                           required minlength="3" maxlength="100"
                                           class="pl-10 w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                           placeholder="Task title">
                                </div>
                                <p class="mt-1 text-xs text-gray-500">3-100 characters</p>
                            </div>
                            
                            <div class="mb-4">
                                <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                <div class="relative">
                                    <div class="absolute top-3 left-3 pointer-events-none">
                                        <i class="fas fa-align-left text-gray-400"></i>
                                    </div>
                                    <textarea id="description" name="description" rows="3"
                                              class="pl-10 w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                              placeholder="Task description (optional)"></textarea>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="deadline" class="block text-sm font-medium text-gray-700 mb-1">Deadline</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-calendar text-gray-400"></i>
                                    </div>
                                    <input type="datetime-local" id="deadline" name="deadline" required
                                           class="pl-10 w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="priority" class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-flag text-gray-400"></i>
                                    </div>
                                    <select id="priority" name="priority"
                                            class="pl-10 w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                    </select>
                                </div>
                            </div>
                            
                            <button type="submit" id="addTaskBtn" 
                                    class="w-full bg-teal-600  py-2 px-4 rounded-md border hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 transition duration-200">
                                <i class="fas fa-plus mr-2"></i>Add Task
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h5 class="font-semibold text-gray-700"><i class="fas fa-filter mr-2 text-teal-600"></i>Filter Tasks</h5>
                    </div>
                    <div class="p-6">
                        <form method="GET" action="tasks.php">
                            <div class="mb-4">
                                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search_query); ?>"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                       placeholder="Search tasks...">
                            </div>
                            
                            <div class="mb-4">
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select id="status" name="status"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            
                            <div class="mb-4">
                                <label for="priority" class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                                <select id="priority" name="priority"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                    <option value="all" <?php echo $priority_filter === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                                    <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                                    <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                </select>
                            </div>
                            
                            <div class="mb-4">
                                <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                                <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            </div>
                            
                            <div class="mb-4">
                                <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                                <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            </div>
                            
                            <div class="flex space-x-2">
                                <button type="submit" class="flex-1 bg-teal-600 text-white py-2 px-4 rounded-md hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 transition duration-200">
                                    <i class="fas fa-filter mr-2"></i>Filter
                                </button>
                                <a href="tasks.php" class="flex-1 bg-gray-200 text-gray-700 py-2 px-4 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition duration-200 text-center">
                                    <i class="fas fa-redo mr-2"></i>Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="lg:col-span-3">
                <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h5 class="font-semibold text-gray-700"><i class="fas fa-list-check mr-2 text-teal-600"></i>Your Tasks</h5>
                    </div>
                    <div class="p-6">
                        <?php if (empty($tasks)): ?>
                            <div class="text-center py-8">
                                <div class="text-gray-400 text-5xl mb-4">
                                    <i class="fas fa-tasks"></i>
                                </div>
                                <h3 class="text-xl font-medium text-gray-700 mb-2">No tasks found</h3>
                                <p class="text-gray-500 mb-6">Add your first task or adjust your filters</p>
                                <button type="button" class="bg-teal-600 text-white py-2 px-6 rounded-md hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 transition duration-200"
                                        onclick="document.getElementById('title').focus()">
                                    <i class="fas fa-plus mr-2"></i>Add a Task
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($tasks as $task): ?>
                                    <?php
                                        // Determine status color
                                        $status_color = '';
                                        switch ($task['status']) {
                                            case 'pending':
                                                $status_color = 'bg-amber-100 text-amber-800';
                                                $status_icon = 'fa-clock';
                                                break;
                                            case 'in_progress':
                                                $status_color = 'bg-blue-100 text-blue-800';
                                                $status_icon = 'fa-spinner';
                                                break;
                                            case 'completed':
                                                $status_color = 'bg-green-100 text-green-800';
                                                $status_icon = 'fa-check';
                                                break;
                                            case 'cancelled':
                                                $status_color = 'bg-red-100 text-red-800';
                                                $status_icon = 'fa-ban';
                                                break;
                                        }
                                        
                                        // Determine priority color
                                        $priority_color = '';
                                        switch ($task['priority']) {
                                            case 'low':
                                                $priority_color = 'bg-blue-100 text-blue-800';
                                                break;
                                            case 'medium':
                                                $priority_color = 'bg-amber-100 text-amber-800';
                                                break;
                                            case 'high':
                                                $priority_color = 'bg-red-100 text-red-800';
                                                break;
                                        }
                                        
                                        // Check if task is overdue
                                        $is_overdue = false;
                                        if ($task['status'] !== 'completed' && $task['status'] !== 'cancelled') {
                                            $deadline = new DateTime($task['deadline']);
                                            $now = new DateTime();
                                            $is_overdue = $deadline < $now;
                                        }
                                    ?>
                                    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden shadow-sm hover:shadow-md transition duration-200">
                                        <div class="p-4">
                                            <div class="flex flex-wrap justify-between items-start">
                                                <div class="flex-grow mr-4">
                                                    <h6 class="text-lg font-medium text-gray-800 mb-1">
                                                        <?php echo htmlspecialchars($task['title']); ?>
                                                        <?php if ($is_overdue): ?>
                                                            <span class="ml-2 text-xs font-semibold text-red-600">
                                                                <i class="fas fa-exclamation-circle"></i> OVERDUE
                                                            </span>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <?php if (!empty($task['description'])): ?>
                                                        <p class="text-gray-600 mb-3">
                                                            <?php echo htmlspecialchars($task['description']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                    <div class="flex flex-wrap items-center text-sm text-gray-500 gap-3">
                                                        <span>
                                                            <i class="fas fa-calendar-alt mr-1"></i>
                                                            <?php echo date('M j, Y g:i A', strtotime($task['deadline'])); ?>
                                                        </span>
                                                        <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $priority_color; ?>">
                                                            <i class="fas fa-flag mr-1"></i>
                                                            <?php echo ucfirst($task['priority']); ?>
                                                        </span>
                                                        <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $status_color; ?>">
                                                            <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                                                            <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="flex items-center space-x-2 mt-2 sm:mt-0">
                                                    <div class="flex flex-wrap gap-1">
                                                        <form method="POST" action="tasks.php" class="inline-block">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                            <input type="hidden" name="status" value="pending">
                                                            <button type="submit" class="px-2 py-1 text-xs border border-amber-300 rounded-md text-amber-600 bg-white hover:bg-amber-50">
                                                                <i class="fas fa-clock mr-1"></i>Pending
                                                            </button>
                                                        </form>
                                                        <form method="POST" action="tasks.php" class="inline-block">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                            <input type="hidden" name="status" value="in_progress">
                                                            <button type="submit" class="px-2 py-1 text-xs border border-blue-300 rounded-md text-blue-600 bg-white hover:bg-blue-50">
                                                                <i class="fas fa-spinner mr-1"></i>In Progress
                                                            </button>
                                                        </form>
                                                        <form method="POST" action="tasks.php" class="inline-block">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                            <input type="hidden" name="status" value="completed">
                                                            <button type="submit" class="px-2 py-1 text-xs border border-green-300 rounded-md text-green-600 bg-white hover:bg-green-50">
                                                                <i class="fas fa-check mr-1"></i>Completed
                                                            </button>
                                                        </form>
                                                        <form method="POST" action="tasks.php" class="inline-block">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                            <input type="hidden" name="status" value="cancelled">
                                                            <button type="submit" class="px-2 py-1 text-xs border border-red-300 rounded-md text-red-600 bg-white hover:bg-red-50">
                                                                <i class="fas fa-ban mr-1"></i>Cancelled
                                                            </button>
                                                        </form>
                                                    </div>
                                                    
                                                    <form method="POST" action="tasks.php" onsubmit="return confirm('Are you sure you want to delete this task?');">
                                                        <input type="hidden" name="action" value="delete_task">
                                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                        <button type="submit" class="p-2 text-red-600 hover:text-red-800 focus:outline-none">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
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
        
        // Set minimum date to today for deadline
        document.addEventListener('DOMContentLoaded', function() {
            const deadlineInput = document.getElementById('deadline');
            if (deadlineInput) {
                const today = new Date();
                const todayStr = today.toISOString().slice(0, 16);
                deadlineInput.min = todayStr;
                deadlineInput.value = todayStr;
            }
            
            // Title input validation
            const titleInput = document.getElementById('title');
            const addTaskBtn = document.getElementById('addTaskBtn');
            
            if (titleInput) {
                titleInput.addEventListener('input', function() {
                    const length = this.value.trim().length;
                    const isValid = length >= 3 && length <= 100;
                    
                    this.classList.toggle('border-red-500', !isValid && length > 0);
                    this.classList.toggle('border-green-500', isValid && length > 0);
                    
                    // Enable/disable submit button based on form validation
                    const form = titleInput.closest('form');
                    addTaskBtn.disabled = !form.checkValidity();
                });
            }
            
            // Status dropdown toggle
            const dropdownToggles = document.querySelectorAll('.status-dropdown-toggle');
            dropdownToggles.forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const dropdownId = this.getAttribute('data-dropdown-id');
                    const dropdown = document.getElementById(dropdownId);
                    
                    // Close all other dropdowns
                    document.querySelectorAll('[id^="status-dropdown-"]').forEach(el => {
                        if (el.id !== dropdownId) {
                            el.classList.add('hidden');
                        }
                    });
                    
                    // Toggle this dropdown
                    dropdown.classList.toggle('hidden');
                });
            });
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                document.querySelectorAll('[id^="status-dropdown-"]').forEach(dropdown => {
                    dropdown.classList.add('hidden');
                });
            });
            
            // Add loading state to forms
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function() {
                    const button = this.querySelector('button[type="submit"]');
                    if (button && !button.classList.contains('no-loading')) {
                        const originalContent = button.innerHTML;
                        button.disabled = true;
                        
                        if (this.querySelector('[name="action"]')?.value === 'add_task') {
                            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Adding Task...';
                        } else if (this.querySelector('[name="action"]')?.value === 'delete_task') {
                            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                        }
                        
                        // Reset button after 2 seconds if form hasn't redirected
                        setTimeout(() => {
                            button.disabled = false;
                            button.innerHTML = originalContent;
                        }, 2000);
                    }
                });
            });
        });
    </script>
</body>
</html>