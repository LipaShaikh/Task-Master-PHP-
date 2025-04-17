<?php
require_once 'config/database.php';
session_start();

// Only allow admins or when no users exist yet
$allow_access = false;
$first_time_setup = false;

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Check if any users exist
    $stmt = $db->query("SELECT COUNT(*) as count FROM users");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] == 0) {
        $allow_access = true;
        $first_time_setup = true;
    } elseif (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        $allow_access = true;
    }
} catch (PDOException $e) {
    // If there's an error, it might be because the tables don't exist yet
    $allow_access = true;
    $first_time_setup = true;
}

if (!$allow_access) {
    header("Location: login.php");
    exit();
}

$updates = [];
$errors = [];

// Function to execute SQL safely and log results
function executeSafely($db, $sql, $description) {
    global $updates, $errors;
    
    try {
        $db->exec($sql);
        $updates[] = "✅ " . $description;
        return true;
    } catch (PDOException $e) {
        $errors[] = "❌ " . $description . ": " . $e->getMessage();
        return false;
    }
}

// Function to check if a column exists in a table
function columnExists($db, $table, $column) {
    $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $stmt->rowCount() > 0;
}

// Function to check if a table exists
function tableExists($db, $table) {
    $stmt = $db->query("SHOW TABLES LIKE '$table'");
    return $stmt->rowCount() > 0;
}

// Start the update process
try {
    // 1. Update users table
    if (!columnExists($db, 'users', 'name')) {
        executeSafely($db, "ALTER TABLE users ADD COLUMN name VARCHAR(100) NULL AFTER id", 
            "Added 'name' column to users table");
    }
    
    if (!columnExists($db, 'users', 'role')) {
        executeSafely($db, "ALTER TABLE users ADD COLUMN role ENUM('user', 'admin') NOT NULL DEFAULT 'user' AFTER password", 
            "Added 'role' column to users table");
    }
    
    if (!columnExists($db, 'users', 'status')) {
        executeSafely($db, "ALTER TABLE users ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER role", 
            "Added 'status' column to users table");
    }
    
    if (!columnExists($db, 'users', 'last_login')) {
        executeSafely($db, "ALTER TABLE users ADD COLUMN last_login TIMESTAMP NULL AFTER status", 
            "Added 'last_login' column to users table");
    }
    
    // 2. Update tasks table
    if (!columnExists($db, 'tasks', 'updated_at')) {
        executeSafely($db, "ALTER TABLE tasks ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at", 
            "Added 'updated_at' column to tasks table");
    }
    
    // 3. Update task_updates table
    if (!columnExists($db, 'task_updates', 'user_id')) {
        executeSafely($db, "ALTER TABLE task_updates ADD COLUMN user_id INT AFTER task_id", 
            "Added 'user_id' column to task_updates table");
        
        executeSafely($db, "ALTER TABLE task_updates ADD CONSTRAINT fk_task_updates_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL", 
            "Added foreign key constraint for user_id in task_updates table");
    }
    
    // 4. Create password_resets table if it doesn't exist
    if (!tableExists($db, 'password_resets')) {
        $sql = "CREATE TABLE password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) NOT NULL,
            token VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            used BOOLEAN DEFAULT FALSE,
            INDEX (email),
            INDEX (token)
        )";
        
        executeSafely($db, $sql, "Created password_resets table");
    }
    
    // 5. Create user_activity table if it doesn't exist
    if (!tableExists($db, 'user_activity')) {
        $sql = "CREATE TABLE user_activity (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            action VARCHAR(50) NOT NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        
        executeSafely($db, $sql, "Created user_activity table");
    }
    
    // 6. Create first admin user if this is a first-time setup
    if ($first_time_setup) {
        $stmt = $db->query("SELECT COUNT(*) as count FROM users");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] == 0) {
            $admin_email = 'admin@taskmaster.com';
            $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
            
            // First check if admin user already exists
            $check_admin = $db->prepare("SELECT id FROM users WHERE email = ?");
            $check_admin->execute([$admin_email]);
            
            if ($check_admin->rowCount() == 0) {
                // Admin doesn't exist, create it
                $stmt = $db->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
                if ($stmt->execute(['Administrator', $admin_email, $admin_password, 'admin'])) {
                    $updates[] = "✅ Created default admin user (Email: admin@taskmaster.com, Password: admin123)";
                }
            } else {
                // Admin exists, make sure it has admin role
                $stmt = $db->prepare("UPDATE users SET role = 'admin' WHERE email = ?");
                $stmt->execute([$admin_email]);
                $updates[] = "✅ Verified admin user exists (Email: admin@taskmaster.com, Password: admin123)";
            }
        }
    }
    
} catch (PDOException $e) {
    $errors[] = "❌ Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Update - TaskMaster</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <a href="index.php" class="text-2xl font-bold text-indigo-600">
                    <i class="fas fa-check-circle mr-2"></i>TaskMaster
                </a>
                <div class="flex space-x-4">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="dashboard.php" class="text-gray-600 hover:text-indigo-600">
                            <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                        </a>
                        <a href="logout.php" class="text-red-600 hover:text-red-800">
                            <i class="fas fa-sign-out-alt mr-1"></i>Logout
                        </a>
                    <?php else: ?>
                        <a href="login.php" class="text-gray-600 hover:text-indigo-600">
                            <i class="fas fa-sign-in-alt mr-1"></i>Login
                        </a>
                        <a href="register.php" class="text-gray-600 hover:text-indigo-600">
                            <i class="fas fa-user-plus mr-1"></i>Register
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-8 flex-grow">
        <div class="max-w-4xl mx-auto">
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="px-6 py-8 border-b border-gray-200">
                    <h2 class="text-2xl font-bold text-gray-800 mb-2">Database Update</h2>
                    <p class="text-gray-600">This script updates your database schema to support all the new features.</p>
                </div>
                <div class="p-6">
                    <?php if ($first_time_setup && count($updates) > 0): ?>
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-indigo-600 mb-2">
                                <i class="fas fa-user-shield mr-2"></i>Admin Credentials
                            </h3>
                            <div class="bg-indigo-50 border border-indigo-200 rounded-md p-4">
                                <p class="text-indigo-800 font-medium">A default admin user has been created with the following credentials:</p>
                                <div class="mt-2 p-3 bg-white rounded border border-indigo-200">
                                    <p class="mb-1"><span class="font-semibold">Email:</span> admin@taskmaster.com</p>
                                    <p><span class="font-semibold">Password:</span> admin123</p>
                                </div>
                                <p class="mt-2 text-indigo-700 text-sm">Please login with these credentials and change the password immediately.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (count($updates) > 0): ?>
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-green-600 mb-2">
                                <i class="fas fa-check-circle mr-2"></i>Updates Applied
                            </h3>
                            <ul class="bg-green-50 border border-green-200 rounded-md p-4 space-y-2">
                                <?php foreach ($updates as $update): ?>
                                    <li class="text-green-800"><?php echo $update; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (count($errors) > 0): ?>
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-red-600 mb-2">
                                <i class="fas fa-exclamation-circle mr-2"></i>Errors
                            </h3>
                            <ul class="bg-red-50 border border-red-200 rounded-md p-4 space-y-2">
                                <?php foreach ($errors as $error): ?>
                                    <li class="text-red-800"><?php echo $error; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mt-6 flex justify-center">
                        <?php if (count($errors) === 0): ?>
                            <a href="<?php echo isset($_SESSION['role']) && $_SESSION['role'] === 'admin' ? 'admin.php' : 'dashboard.php'; ?>" 
                               class="bg-indigo-600 text-white py-2 px-6 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition duration-200">
                                <i class="fas fa-arrow-right mr-2"></i>Continue to Dashboard
                            </a>
                        <?php else: ?>
                            <a href="setup_db_update.php" 
                               class="bg-indigo-600 text-white py-2 px-6 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition duration-200">
                                <i class="fas fa-sync mr-2"></i>Try Again
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="bg-white border-t border-gray-200 py-6 mt-8">
        <div class="container mx-auto px-4">
            <div class="text-center text-gray-500 text-sm">
                &copy; <?php echo date('Y'); ?> TaskMaster. All rights reserved.
            </div>
        </div>
    </footer>
</body>
</html>