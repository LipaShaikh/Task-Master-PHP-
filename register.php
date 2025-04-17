<?php
require_once 'config/database.php';
session_start();

if(isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($email) || empty($password) || empty($confirm_password)) {
        $error_message = "All fields are required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address";
    } elseif (strlen($password) < 8) {
        $error_message = "Password must be at least 8 characters long";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match";
    } else {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            if (!$db) {
                throw new Exception("Database connection failed");
            }
            
            // Check if email already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->rowCount() > 0) {
                $error_message = "Email already registered";
            } else {
                // Check if name column exists
                $check_columns = $db->query("SHOW COLUMNS FROM users LIKE 'name'");
                $has_name_column = $check_columns->rowCount() > 0;
                
                // Create new user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                if ($has_name_column) {
                    $stmt = $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
                    $success = $stmt->execute([$name, $email, $hashed_password]);
                } else {
                    $stmt = $db->prepare("INSERT INTO users (email, password) VALUES (?, ?)");
                    $success = $stmt->execute([$email, $hashed_password]);
                }
                
                if ($success) {
                    // Get the new user ID for activity logging
                    $user_id = $db->lastInsertId();
                    
                    // Try to log the registration if user_activity table exists
                    try {
                        $check_table = $db->query("SHOW TABLES LIKE 'user_activity'");
                        if ($check_table->rowCount() > 0) {
                            $stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
                            $stmt->execute([
                                $user_id,
                                'registration',
                                'User registered',
                                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                            ]);
                        }
                    } catch (PDOException $e) {
                        // Ignore error if table doesn't exist
                        error_log("Activity logging failed: " . $e->getMessage());
                    }
                    
                    $success_message = "Registration successful! Please login.";
                } else {
                    $error_message = "Failed to create account";
                }
            }
        } catch(PDOException $e) {
            error_log($e->getMessage());
            $error_message = "Registration failed. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - TaskMaster</title>
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
                    <a href="login.php" class="text-gray-600 hover:text-indigo-600">
                        <i class="fas fa-sign-in-alt mr-1"></i>Login
                    </a>
                    <a href="register.php" class="text-indigo-600 font-medium">
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
                    <h2 class="text-2xl font-bold text-gray-800 mb-2">Create Account</h2>
                    <p class="text-gray-600">Join us to boost your productivity</p>
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
                        <p class="mt-1">To enable all new features, please run the <a href="setup_db_update.php" class="text-blue-700 underline font-medium">database update script</a> after registration.</p>
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

                    <?php if ($success_message): ?>
                        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded" role="alert">
                            <p><?php echo htmlspecialchars($success_message); ?></p>
                            <div class="mt-2">
                                <a href="login.php" class="text-green-700 font-medium hover:text-green-900">
                                    <i class="fas fa-sign-in-alt mr-1"></i>Go to Login
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="register.php" id="registerForm" class="<?php echo $success_message ? 'hidden' : ''; ?>">
                        <div class="mb-6">
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-user text-gray-400"></i>
                                </div>
                                <input type="text" id="name" name="name" 
                                       value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                                       placeholder="Enter your full name"
                                       class="pl-10 w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>
                        
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
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-lock text-gray-400"></i>
                                </div>
                                <input type="password" id="password" name="password" 
                                       required minlength="8" placeholder="Minimum 8 characters"
                                       autocomplete="new-password"
                                       class="pl-10 w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                    <i class="fas fa-eye text-gray-400 hover:text-gray-600"></i>
                                </button>
                            </div>
                            <div class="mt-2" id="passwordStrength"></div>
                        </div>
                        
                        <div class="mb-6">
                            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-lock text-gray-400"></i>
                                </div>
                                <input type="password" id="confirm_password" name="confirm_password" 
                                       required minlength="8" placeholder="Re-enter your password"
                                       autocomplete="new-password"
                                       class="pl-10 w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <button type="button" id="toggleConfirmPassword" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                    <i class="fas fa-eye text-gray-400 hover:text-gray-600"></i>
                                </button>
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full bg-indigo-600 text-white py-3 px-4 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition duration-200 mb-6">
                            <i class="fas fa-user-plus mr-2"></i>Create Account
                        </button>
                        
                        <div class="text-center">
                            <p class="text-gray-600">
                                Already have an account? 
                                <a href="login.php" class="text-indigo-600 hover:text-indigo-800 font-medium">
                                    Login here
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
        // Password visibility toggle functions
        function setupPasswordToggle(inputId, toggleId) {
            document.getElementById(toggleId).addEventListener('click', function() {
                const input = document.getElementById(inputId);
                const icon = this.querySelector('i');
                
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        }

        setupPasswordToggle('password', 'togglePassword');
        setupPasswordToggle('confirm_password', 'toggleConfirmPassword');

        // Password strength indicator with Tailwind UI
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthDiv = document.getElementById('passwordStrength');
            let strength = 0;
            let message = '';
            let progressClass = '';
            let textClass = '';

            if (password.length >= 8) strength++;
            if (password.match(/[A-Z]/)) strength++;
            if (password.match(/[0-9]/)) strength++;
            if (password.match(/[^A-Za-z0-9]/)) strength++;

            const progress = (strength / 4) * 100;

            switch(strength) {
                case 0:
                case 1:
                    message = 'Weak';
                    progressClass = 'bg-red-500';
                    textClass = 'text-red-600';
                    break;
                case 2:
                    message = 'Medium';
                    progressClass = 'bg-yellow-500';
                    textClass = 'text-yellow-600';
                    break;
                case 3:
                    message = 'Strong';
                    progressClass = 'bg-blue-500';
                    textClass = 'text-blue-600';
                    break;
                case 4:
                    message = 'Very Strong';
                    progressClass = 'bg-green-500';
                    textClass = 'text-green-600';
                    break;
            }

            strengthDiv.innerHTML = `
                <div class="flex items-center gap-2">
                    <div class="flex-grow h-1.5 bg-gray-200 rounded-full overflow-hidden">
                        <div class="${progressClass} h-full rounded-full" style="width: ${progress}%"></div>
                    </div>
                    <span class="text-xs font-medium ${textClass}">${message}</span>
                </div>
            `;
        });

        // Form validation and loading state
        document.getElementById('registerForm')?.addEventListener('submit', function(event) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                event.preventDefault();
                
                // Create alert element
                const alertDiv = document.createElement('div');
                alertDiv.className = 'bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded';
                alertDiv.innerHTML = 'Passwords do not match';
                
                // Insert at the top of the form
                const firstChild = this.firstChild;
                this.insertBefore(alertDiv, firstChild);
                
                // Scroll to the alert
                alertDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                const button = this.querySelector('button[type="submit"]');
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Creating Account...';
                button.disabled = true;
            }
        });
    </script>
</body>
</html>