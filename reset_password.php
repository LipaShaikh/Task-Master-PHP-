<?php
require_once 'config/database.php';
session_start();

// Redirect if already logged in
if(isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$token = $_GET['token'] ?? '';
$valid_token = false;
$token_expired = false;
$token_used = false;
$user_email = '';
$success_message = '';
$error_message = '';

if (empty($token)) {
    $error_message = "Invalid or missing reset token";
} else {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if token exists and is valid
        $stmt = $db->prepare("
            SELECT email, expires_at, used 
            FROM password_resets 
            WHERE token = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$token]);
        
        if ($stmt->rowCount() > 0) {
            $reset = $stmt->fetch(PDO::FETCH_ASSOC);
            $user_email = $reset['email'];
            
            // Check if token is expired (tokens are valid for 1 hour after creation)
            $created_time = strtotime($reset['created_at'] ?? $reset['expires_at']);
            if ($created_time + 3600 < time()) {
                $token_expired = true;
                $error_message = "This password reset link has expired. Please request a new one.";
            }
            // Check if token is already used
            elseif ($reset['used']) {
                $token_used = true;
                $error_message = "This password reset link has already been used. Please request a new one if needed.";
            } else {
                $valid_token = true;
            }
        } else {
            $error_message = "Invalid reset token";
        }
    } catch(PDOException $e) {
        error_log($e->getMessage());
        $error_message = "An error occurred. Please try again later.";
    }
}

// Process password reset form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($password) || empty($confirm_password)) {
        $error_message = "Both password fields are required";
    } elseif (strlen($password) < 8) {
        $error_message = "Password must be at least 8 characters long";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match";
    } else {
        try {
            $db->beginTransaction();
            
            // Get user ID for activity logging
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$user_email]);
            $user_id = $stmt->fetchColumn();
            
            // Update user password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE email = ?");
            $stmt->execute([$hashed_password, $user_email]);
            
            // Mark token as used
            $stmt = $db->prepare("UPDATE password_resets SET used = TRUE WHERE token = ?");
            $stmt->execute([$token]);
            
            // Log the action
            $stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $user_id,
                'password_reset',
                'Password reset completed',
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            $db->commit();
            $success_message = "Your password has been reset successfully. You can now login with your new password.";
            
        } catch(PDOException $e) {
            $db->rollBack();
            error_log($e->getMessage());
            $error_message = "Failed to reset password. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - TaskMaster</title>
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
                <div>
                    <a href="login.php" class="text-gray-600 hover:text-indigo-600 mr-4">
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
            <?php if ($success_message): ?>
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="px-6 py-8 text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-green-100 text-green-500 mb-6">
                            <i class="fas fa-check-circle text-3xl"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 mb-2">Password Reset Successful</h2>
                        <p class="text-gray-600 mb-6"><?php echo htmlspecialchars($success_message); ?></p>
                        <a href="login.php" class="inline-block bg-indigo-600 text-white py-2 px-6 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition duration-200">
                            <i class="fas fa-sign-in-alt mr-2"></i>Go to Login
                        </a>
                    </div>
                </div>
            <?php elseif ($error_message && (!$valid_token || $token_expired || $token_used)): ?>
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="px-6 py-8 text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-red-100 text-red-500 mb-6">
                            <i class="fas fa-exclamation-circle text-3xl"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 mb-2">Password Reset Failed</h2>
                        <p class="text-gray-600 mb-6"><?php echo htmlspecialchars($error_message); ?></p>
                        <a href="forgot_password.php" class="inline-block bg-indigo-600 text-white py-2 px-6 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition duration-200">
                            <i class="fas fa-redo mr-2"></i>Request New Reset Link
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="px-6 py-8 text-center border-b border-gray-200">
                        <h2 class="text-2xl font-bold text-gray-800 mb-2">Reset Your Password</h2>
                        <p class="text-gray-600">Please enter your new password below.</p>
                    </div>
                    <div class="p-6">
                        <?php if ($error_message): ?>
                            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded" role="alert">
                                <p><?php echo htmlspecialchars($error_message); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" id="resetPasswordForm">
                            <div class="mb-6">
                                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-lock text-gray-400"></i>
                                    </div>
                                    <input type="password" id="password" name="password" required minlength="8"
                                           class="pl-10 w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                           placeholder="Enter new password">
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Password must be at least 8 characters long</p>
                            </div>
                            
                            <div class="mb-6">
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-lock text-gray-400"></i>
                                    </div>
                                    <input type="password" id="confirm_password" name="confirm_password" required
                                           class="pl-10 w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                           placeholder="Confirm new password">
                                </div>
                            </div>
                            
                            <div>
                                <button type="submit" class="w-full bg-indigo-600 text-white py-2 px-4 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition duration-200">
                                    <i class="fas fa-save mr-2"></i>Reset Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
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
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('resetPasswordForm');
            if (form) {
                // Password match validation
                const password = document.getElementById('password');
                const confirmPassword = document.getElementById('confirm_password');
                
                function validatePasswordMatch() {
                    if (password.value !== confirmPassword.value) {
                        confirmPassword.setCustomValidity("Passwords don't match");
                    } else {
                        confirmPassword.setCustomValidity('');
                    }
                }
                
                password.addEventListener('change', validatePasswordMatch);
                confirmPassword.addEventListener('keyup', validatePasswordMatch);
                
                // Form submission
                form.addEventListener('submit', function() {
                    const button = this.querySelector('button[type="submit"]');
                    button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Resetting...';
                    button.disabled = true;
                });
            }
        });
    </script>
</body>
</html>