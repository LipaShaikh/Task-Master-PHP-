<?php
require_once 'config/database.php';
session_start();

// Redirect if already logged in
if(isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error_message = "Email address is required";
    } else {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            // Check if email exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->rowCount() > 0) {
                $user_id = $stmt->fetchColumn();
                
                // Generate token
                $token = bin2hex(random_bytes(32));
                
                // Store token in database - expires_at will use the default value
                $stmt = $db->prepare("INSERT INTO password_resets (email, token) VALUES (?, ?)");
                $stmt->execute([$email, $token]);
                
                // Log the action
                $stmt = $db->prepare("INSERT INTO user_activity (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $user_id,
                    'password_reset_request',
                    'Password reset requested',
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                
                // Send email with reset link
                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/task_manager/reset_password.php?token=" . $token;
                
                // In a real application, you would use a proper email library like PHPMailer
                // For this example, we'll just show the link on the page
                $success_message = "Password reset link has been sent to your email address. The link will expire in 1 hour.";
                
                // For demonstration purposes only - in a real app, this would be sent via email
                $demo_link = "<div class='mt-4 p-4 bg-gray-100 rounded-lg'><p class='mb-2'>For demonstration purposes, here is the reset link:</p><a href='$reset_link' class='text-indigo-600 break-all'>$reset_link</a></div>";
                $success_message .= $demo_link;
            } else {
                // Don't reveal if email exists or not for security
                $success_message = "If your email address exists in our database, you will receive a password recovery link at your email address.";
            }
        } catch(PDOException $e) {
            error_log($e->getMessage());
            $error_message = "An error occurred. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - TaskMaster</title>
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
            <?php if ($error_message): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded" role="alert">
                    <p><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded" role="alert">
                    <?php echo $success_message; ?>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="px-6 py-8 text-center border-b border-gray-200">
                        <h2 class="text-2xl font-bold text-gray-800 mb-2">Forgot Your Password?</h2>
                        <p class="text-gray-600">Enter your email address and we'll send you a link to reset your password.</p>
                    </div>
                    <div class="p-6">
                        <form method="POST" action="forgot_password.php" id="forgotPasswordForm">
                            <div class="mb-6">
                                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-envelope text-gray-400"></i>
                                    </div>
                                    <input type="email" id="email" name="email" required 
                                           class="pl-10 w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                           placeholder="Enter your email">
                                </div>
                            </div>
                            <div>
                                <button type="submit" class="w-full bg-indigo-600 text-white py-2 px-4 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition duration-200">
                                    <i class="fas fa-paper-plane mr-2"></i>Send Reset Link
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                        <div class="text-center text-sm">
                            <a href="login.php" class="text-indigo-600 hover:text-indigo-800">
                                <i class="fas fa-arrow-left mr-1"></i>Back to Login
                            </a>
                        </div>
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
            const form = document.getElementById('forgotPasswordForm');
            if (form) {
                form.addEventListener('submit', function() {
                    const button = this.querySelector('button[type="submit"]');
                    button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Sending...';
                    button.disabled = true;
                });
            }
        });
    </script>
</body>
</html>