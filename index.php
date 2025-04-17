<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TaskMaster - Smart Task Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="/task_manager/assets/css/index.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand" href="/task_manager/index.php">
                <i class="fas fa-check-circle me-2"></i>TaskMaster
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="/task_manager/login.php">Login</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/task_manager/register.php">Register</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="hero-section">
        <div class="container">
            <div class="row align-items-center min-vh-100">
                <div class="col-lg-6">
                    <h1 class="display-4 mb-4">Master Your Tasks, Boost Productivity</h1>
                    <p class="lead mb-4">Organize, track, and complete your tasks efficiently. The smart way to manage your projects and achieve your goals.</p>
                    <div class="d-flex gap-3">
                        <a href="/task_manager/register.php" class="btn btn-primary btn-lg">
                            Start Free
                            <i class="fas fa-rocket ms-2"></i>
                        </a>
                        <a href="#features" class="btn btn-outline-primary btn-lg">
                            Explore Features
                            <i class="fas fa-arrow-down ms-2"></i>
                        </a>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="hero-animation">
                        <div class="task-cards">
                            <div class="animated-task-card">
                                <i class="fas fa-tasks"></i>
                                <span>Project Planning</span>
                            </div>
                            <div class="animated-task-card">
                                <i class="fas fa-chart-line"></i>
                                <span>Progress Tracking</span>
                            </div>
                            <div class="animated-task-card">
                                <i class="fas fa-calendar-check"></i>
                                <span>Deadline Management</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <section id="features" class="py-5">
        <div class="container">
            <h2 class="text-center mb-5">Why Choose TaskMaster?</h2>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card text-center p-4">
                        <i class="fas fa-brain feature-icon mb-3"></i>
                        <h3>Smart Organization</h3>
                        <p>Intelligent task categorization and priority management to keep you focused on what matters.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card text-center p-4">
                        <i class="fas fa-chart-bar feature-icon mb-3"></i>
                        <h3>Visual Progress</h3>
                        <p>Track your progress with beautiful charts and get insights into your productivity patterns.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card text-center p-4">
                        <i class="fas fa-bell feature-icon mb-3"></i>
                        <h3>Smart Reminders</h3>
                        <p>Never miss a deadline with timely reminders and progress tracking.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5 bg-light">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2 class="mb-4">Transform Your Productivity Today</h2>
                    <p class="mb-4">Join thousands of productive professionals who use TaskMaster to achieve their goals faster.</p>
                    <a href="/task_manager/register.php" class="btn btn-primary btn-lg">
                        Get Started Free
                        <i class="fas fa-arrow-right ms-2"></i>
                    </a>
                </div>
                <div class="col-md-6">
                    <div class="stats-container p-4">
                        <div class="row text-center">
                            <div class="col-6 mb-4">
                                <h3 class="stat-number">50K+</h3>
                                <p class="stat-label">Tasks Completed</p>
                            </div>
                            <div class="col-6 mb-4">
                                <h3 class="stat-number">5K+</h3>
                                <p class="stat-label">Active Users</p>
                            </div>
                            <div class="col-6">
                                <h3 class="stat-number">98%</h3>
                                <p class="stat-label">Success Rate</p>
                            </div>
                            <div class="col-6">
                                <h3 class="stat-number">100%</h3>
                                <p class="stat-label">Satisfaction</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer class="py-4">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0">&copy; 2024 TaskMaster. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="#" class="text-decoration-none me-3">Privacy Policy</a>
                    <a href="#" class="text-decoration-none me-3">Terms of Service</a>
                    <a href="#" class="text-decoration-none">Contact</a>
                </div>
            </div>
        </div>
    </footer>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>