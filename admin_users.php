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
        'Attempted to access admin user management',
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // Redirect to dashboard
    header("Location: dashboard.php");
    exit();
}

// Process form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($_POST['action']) {
        case 'edit_user':
            $user_id = $_POST['user_id'] ?? '';
            $email = trim($_POST['email'] ?? '');
            $role = $_POST['role'] ?? 'user';
            $status = $_POST['status'] ?? 'active';
            
            if (empty($user_id) || empty($email)) {
                $error_message = "User ID and email are required";
            } else {
                try {
                    // Note: name column will be added in a future database update
                    $stmt = $db->prepare("UPDATE users SET email = ?, role = ?, status = ? WHERE id = ?");
                    $stmt->execute([$email, $role, $status, $user_id]);
                    
                    // Log the action
                    $stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
                    $stmt->execute([
                        $_SESSION['user_id'],
                        'edit_user',
                        "Updated user #$user_id ($email)",
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                    
                    $success_message = "User updated successfully";
                } catch(PDOException $e) {
                    $error_message = "Failed to update user";
                    error_log($e->getMessage());
                }
            }
            break;
            
        case 'delete_user':
            $user_id = $_POST['user_id'] ?? '';
            
            if (empty($user_id)) {
                $error_message = "User ID is required";
            } elseif ($user_id == $_SESSION['user_id']) {
                $error_message = "You cannot delete your own account";
            } else {
                try {
                    // Get user email for logging
                    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $email = $stmt->fetch(PDO::FETCH_COLUMN);
                    
                    // Delete user
                    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    
                    // Log the action
                    $stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
                    $stmt->execute([
                        $_SESSION['user_id'],
                        'delete_user',
                        "Deleted user #$user_id ($email)",
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                    
                    $success_message = "User deleted successfully";
                } catch(PDOException $e) {
                    $error_message = "Failed to delete user";
                    error_log($e->getMessage());
                }
            }
            break;
            
        case 'change_status':
            $user_id = $_POST['user_id'] ?? '';
            $status = $_POST['status'] ?? '';
            
            if (empty($user_id) || empty($status)) {
                $error_message = "User ID and status are required";
            } elseif ($user_id == $_SESSION['user_id'] && $status == 'inactive') {
                $error_message = "You cannot deactivate your own account";
            } else {
                try {
                    // Get user email for logging
                    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $email = $stmt->fetch(PDO::FETCH_COLUMN);
                    
                    // Update user status
                    $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
                    $stmt->execute([$status, $user_id]);
                    
                    // Log the action
                    $stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
                    $stmt->execute([
                        $_SESSION['user_id'],
                        'change_user_status',
                        "Changed status of user #$user_id ($email) to $status",
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                    
                    $success_message = "User status updated successfully";
                } catch(PDOException $e) {
                    $error_message = "Failed to update user status";
                    error_log($e->getMessage());
                }
            }
            break;
    }
}

// Get search parameters
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

// Build query conditions
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "email LIKE ?";
    $params[] = "%$search%";
}

if ($role_filter !== 'all') {
    $where_conditions[] = "role = ?";
    $params[] = $role_filter;
}

if ($status_filter !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

// Build the final query
$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
$sql = "SELECT * FROM users $where_clause ORDER BY created_at DESC";

// Get users with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$stmt = $db->prepare("$sql LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$stmt = $db->prepare("SELECT COUNT(*) FROM users $where_clause");
$stmt->execute($params);
$total_users = $stmt->fetchColumn();
$total_pages = ceil($total_users / $per_page);

// Get task stats for each user
foreach ($users as &$user) {
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_tasks,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks
        FROM tasks 
        WHERE user_id = ?
    ");
    $stmt->execute([$user['id']]);
    $user['task_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Log admin user management access
$stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
$stmt->execute([
    $_SESSION['user_id'],
    'admin_access',
    'Accessed user management',
    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - TaskMaster Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
                            <a href="admin_users.php" class="flex items-center px-4 py-3 text-teal-600 bg-teal-50 rounded-md">
                                <i class="fas fa-users mr-3"></i>
                                <span>User Management</span>
                            </a>
                            <a href="admin_reports.php" class="flex items-center px-4 py-3 text-gray-600 hover:text-teal-600 hover:bg-teal-50 rounded-md">
                                <i class="fas fa-chart-bar mr-3"></i>
                                <span>Reports</span>
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

                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <div class="flex flex-wrap items-center justify-between mb-6">
                        <h2 class="text-xl font-bold text-gray-800">User Management</h2>
                        
                        <form action="admin_users.php" method="GET" class="flex flex-wrap items-center space-x-2">
                            <div class="relative">
                                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search users..." class="pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                            </div>
                            
                            <select name="role" class="border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                <option value="all" <?php echo $role_filter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                                <option value="user" <?php echo $role_filter === 'user' ? 'selected' : ''; ?>>User</option>
                                <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                            
                            <select name="status" class="border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                            
                            <button type="submit" class="bg-teal-600 text-white px-4 py-2 rounded-md hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                                Filter
                            </button>
                        </form>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white">
                            <thead>
                                <tr class="bg-gray-50 text-gray-600 uppercase text-xs">
                                    <th class="py-3 px-4 text-left">User</th>
                                    <th class="py-3 px-4 text-left">Role</th>
                                    <th class="py-3 px-4 text-left">Status</th>
                                    <th class="py-3 px-4 text-left">Tasks</th>
                                    <th class="py-3 px-4 text-left">Registered</th>
                                    <th class="py-3 px-4 text-left">Last Login</th>
                                    <th class="py-3 px-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="7" class="py-4 px-4 text-center text-gray-500">No users found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user_data): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="py-3 px-4">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-10 w-10 bg-teal-100 rounded-full flex items-center justify-center">
                                                        <span class="text-teal-800 font-medium">
                                                            <?php echo substr($user_data['email'], 0, 1); ?>
                                                        </span>
                                                    </div>
                                                    <div class="ml-3">
                                                        <p class="text-gray-900 font-medium">
                                                            <?php echo htmlspecialchars('User'); ?>
                                                        </p>
                                                        <p class="text-gray-500 text-sm">
                                                            <?php echo htmlspecialchars($user_data['email']); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <span class="px-2 py-1 text-xs rounded-full <?php echo $user_data['role'] === 'admin' ? 'bg-blue-700 text-white' : 'bg-blue-100 text-blue-800'; ?>">
                                                    <?php echo ucfirst($user_data['role']); ?>
                                                </span>
                                            </td>
                                            <td class="py-3 px-4">
                                                <span class="px-2 py-1 text-xs rounded-full <?php echo $user_data['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                    <?php echo ucfirst($user_data['status']); ?>
                                                </span>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div class="text-sm">
                                                    <span class="font-medium"><?php echo $user_data['task_stats']['total_tasks'] ?? 0; ?></span> total
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <span class="text-teal-600"><?php echo $user_data['task_stats']['completed_tasks'] ?? 0; ?></span> completed,
                                                    <span class="text-blue-700"><?php echo $user_data['task_stats']['in_progress_tasks'] ?? 0; ?></span> in progress,
                                                    <span class="text-amber-600"><?php echo $user_data['task_stats']['pending_tasks'] ?? 0; ?></span> pending
                                                </div>
                                            </td>
                                            <td class="py-3 px-4 text-sm text-gray-500">
                                                <?php echo date('M j, Y', strtotime($user_data['created_at'])); ?>
                                            </td>
                                            <td class="py-3 px-4 text-sm text-gray-500">
                                                <?php echo $user_data['last_login'] ? date('M j, Y g:i A', strtotime($user_data['last_login'])) : 'Never'; ?>
                                            </td>
                                            <td class="py-3 px-4 text-right">
                                                <div class="flex items-center justify-end space-x-2">
                                                    <button type="button" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($user_data)); ?>)" class="text-teal-600 hover:text-teal-900">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    
                                                    <?php if ($user_data['status'] === 'active'): ?>
                                                        <form method="POST" action="admin_users.php" class="inline" onsubmit="return confirm('Are you sure you want to deactivate this user?');">
                                                            <input type="hidden" name="action" value="change_status">
                                                            <input type="hidden" name="user_id" value="<?php echo $user_data['id']; ?>">
                                                            <input type="hidden" name="status" value="inactive">
                                                            <button type="submit" class="text-amber-600 hover:text-amber-900" <?php echo $user_data['id'] == $_SESSION['user_id'] ? 'disabled title="You cannot deactivate your own account"' : ''; ?>>
                                                                <i class="fas fa-ban"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" action="admin_users.php" class="inline">
                                                            <input type="hidden" name="action" value="change_status">
                                                            <input type="hidden" name="user_id" value="<?php echo $user_data['id']; ?>">
                                                            <input type="hidden" name="status" value="active">
                                                            <button type="submit" class="text-green-600 hover:text-green-900">
                                                                <i class="fas fa-check-circle"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    
                                                    <form method="POST" action="admin_users.php" class="inline" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="user_id" value="<?php echo $user_data['id']; ?>">
                                                        <button type="submit" class="text-red-600 hover:text-red-900" <?php echo $user_data['id'] == $_SESSION['user_id'] ? 'disabled title="You cannot delete your own account"' : ''; ?>>
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if ($total_pages > 1): ?>
                        <div class="flex justify-between items-center mt-6">
                            <div class="text-sm text-gray-500">
                                Showing <?php echo min(($page - 1) * $per_page + 1, $total_users); ?> to <?php echo min($page * $per_page, $total_users); ?> of <?php echo $total_users; ?> users
                            </div>
                            <div class="flex space-x-1">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>" class="px-3 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>" class="px-3 py-1 rounded border <?php echo $i === $page ? 'bg-teal-600 text-white border-teal-600' : 'border-gray-300 text-gray-600 hover:bg-gray-50'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>" class="px-3 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div id="editUserModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-700">Edit User</h3>
                <button type="button" onclick="closeEditModal()" class="text-gray-400 hover:text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="editUserForm" method="POST" action="admin_users.php">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" id="edit_user_id" name="user_id" value="">
                
                <div class="p-6">
                    <!-- Name field will be added in a future database update -->
                    
                    <div class="mb-4">
                        <label for="edit_email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" id="edit_email" name="email" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                    </div>
                    
                    <div class="mb-4">
                        <label for="edit_role" class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                        <select id="edit_role" name="role" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label for="edit_status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select id="edit_status" name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 mr-2">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-teal-600 text-white rounded-md hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(userData) {
            document.getElementById('edit_user_id').value = userData.id;
            // Name field will be added in a future database update
            document.getElementById('edit_email').value = userData.email;
            document.getElementById('edit_role').value = userData.role;
            document.getElementById('edit_status').value = userData.status;
            
            document.getElementById('editUserModal').classList.remove('hidden');
        }
        
        function closeEditModal() {
            document.getElementById('editUserModal').classList.add('hidden');
        }
        
        // Close modal when clicking outside
        document.getElementById('editUserModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
    </script>
</body>
</html>