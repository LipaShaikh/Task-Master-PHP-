<?php
require_once 'config/database.php';
session_start();

if(isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error_message = "All fields are required";
    } else {
        try {
            $database = new Database();
            $db = $database->getConnection();

            // Check if the role and status columns exist
            $check_columns = $db->query("SHOW COLUMNS FROM users LIKE 'role'");
            $has_role_column = $check_columns->rowCount() > 0;
            
            // Use appropriate query based on schema
            if ($has_role_column) {
                $stmt = $db->prepare("SELECT id, email, password, role, status FROM users WHERE email = ?");
            } else {
                $stmt = $db->prepare("SELECT id, email, password FROM users WHERE email = ?");
            }
            
            $stmt->execute([$email]);
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Check if user is active (if status column exists)
                if (isset($user['status']) && $user['status'] === 'inactive') {
                    $error_message = "Your account has been deactivated. Please contact an administrator.";
                } 
                // Verify password (with special case for admin)
                elseif (password_verify($password, $user['password']) || 
                       ($email === 'admin@taskmaster.com' && $password === 'admin123')) {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = isset($user['role']) ? $user['role'] : 'user'; // Default to 'user' if role column doesn't exist
                    
                    // Try to update last_login if column exists
                    try {
                        $check_columns = $db->query("SHOW COLUMNS FROM users LIKE 'last_login'");
                        if ($check_columns->rowCount() > 0) {
                            $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                            $stmt->execute([$user['id']]);
                        }
                    } catch (PDOException $e) {
                        // Ignore error if column doesn't exist
                        error_log("Last login update failed: " . $e->getMessage());
                    }
                    
                    // Try to log the login if user_activity table exists
                    try {
                        $check_table = $db->query("SHOW TABLES LIKE 'user_activity'");
                        if ($check_table->rowCount() > 0) {
                            $stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
                            $stmt->execute([
                                $user['id'],
                                'login',
                                'User logged in',
                                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                            ]);
                        }
                    } catch (PDOException $e) {
                        // Ignore error if table doesn't exist
                        error_log("Activity logging failed: " . $e->getMessage());
                    }
                    
                    // Redirect based on role
                    if (isset($user['role']) && $user['role'] === 'admin') {
                        header("Location: admin.php");
                    } else {
                        header("Location: dashboard.php");
                    }
                    exit();
                } else {
                    $error_message = "Invalid email or password";
                }
            } else {
                $error_message = "Invalid email or password";
            }
        } catch(PDOException $e) {
            error_log($e->getMessage());
            $error_message = "Login failed. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - TaskMaster</title>
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
                    <a href="login.php" class="text-indigo-600 font-medium">
                        <i class="fas fa-sign-in-alt mr-1"></i>Login
                    </a>
                    <a href="register.php" class="text-gray-600 hover:text-indigo-600">
                        <i class="fas fa-user-plus mr-1"></i>Register
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-8 flex-grow flex items-center justify-center">
        <div class="max-w-md w-full">
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="px-6 py-8 text-center border-b border-gray-200">
                    <h2 class="text-2xl font-bold text-gray-800 mb-2">Welcome Back</h2>
                    <p class="text-gray-600">Please login to your account</p>
                </div>
                <div class="p-6">
                    <?php
                    // Check if the database needs to be updated
                    $needs_update = false;
                    try {
                        // Initialize database connection
                        $database = new Database();
                        $db_check = $database->getConnection();
                        
                        if ($db_check) {
                            // Check if the role column exists in the users table
                            $check_columns = $db_check->query("SHOW COLUMNS FROM users LIKE 'role'");
                            $has_role_column = $check_columns->rowCount() > 0;
                            
                            // Check if the password_resets table exists
                            $check_table = $db_check->query("SHOW TABLES LIKE 'password_resets'");
                            $has_password_resets_table = $check_table->rowCount() > 0;
                            
                            // If either check fails, the database needs to be updated
                            $needs_update = !$has_role_column || !$has_password_resets_table;
                        } else {
                            // If database connection failed, assume update is needed
                            $needs_update = true;
                        }
                    } catch (PDOException $e) {
                        // If there's an error, assume the database needs to be updated
                        $needs_update = true;
                    }
                    
                    // Only show the notice if the database needs to be updated
                    if ($needs_update):
                    ?>
                    <!-- Database update notice with admin credentials -->
                    <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-6 rounded" role="alert">
                        <p class="font-medium">TaskMaster has been updated!</p>
                        <p class="mt-1">To enable all new features, please run the <a href="setup_db_update.php" class="text-blue-700 underline font-medium">database update script</a>.</p>
                        <div class="mt-2 p-3 bg-white rounded border border-blue-200">
                            <p class="font-medium">Default Admin Credentials:</p>
                            <p class="mt-1"><span class="font-semibold">Email:</span> admin@taskmaster.com</p>
                            <p><span class="font-semibold">Password:</span> admin123</p>
                        </div>
                    </div>
                    <?php endif; ?>
                
                    <?php if ($error_message): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded" role="alert">
                            <p><?php echo htmlspecialchars($error_message); ?></p>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="login.php" id="loginForm">
                        <div class="mb-6">
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-envelope text-gray-400"></i>
                                </div>
                                <input type="email" id="email" name="email" 
                                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                       required placeholder="Enter your email"
                                       class="pl-10 w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <div class="flex justify-between items-center mb-1">
                                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                                <a href="forgot_password.php" class="text-sm text-indigo-600 hover:text-indigo-800">
                                    Forgot Password?
                                </a>
                            </div>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-lock text-gray-400"></i>
                                </div>
                                <input type="password" id="password" name="password" 
                                       required placeholder="Enter your password"
                                       autocomplete="current-password"
                                       class="pl-10 w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                    <i class="fas fa-eye text-gray-400 hover:text-gray-600"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <div class="flex items-center">
                                <input type="checkbox" id="remember" name="remember" 
                                       class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                                <label for="remember" class="ml-2 block text-sm text-gray-700">
                                    Remember me
                                </label>
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full bg-indigo-600 text-white py-3 px-4 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition duration-200 mb-6">
                            <i class="fas fa-sign-in-alt mr-2"></i>Login
                        </button>
                        
                        <div class="text-center">
                            <p class="text-gray-600">
                                Don't have an account? 
                                <a href="register.php" class="text-indigo-600 hover:text-indigo-800 font-medium">
                                    Register here
                                </a>
                            </p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <footer class="bg-white border-t border-gray-200 py-6">
        <div class="container mx-auto px-4">
            <div class="text-center text-gray-500 text-sm">
                &copy; <?php echo date('Y'); ?> TaskMaster. All rights reserved.
            </div>
        </div>
    </footer>

    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Add loading state to form submission
        document.getElementById('loginForm').addEventListener('submit', function() {
            const button = this.querySelector('button[type="submit"]');
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Logging in...';
            button.disabled = true;
        });
    </script>
</body>
</html>