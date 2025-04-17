<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup - AI Study Planner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="card">
            <div class="card-header">
                <h2 class="text-center">Setting up AI Study Planner Database</h2>
            </div>
            <div class="card-body">
                <div class="setup-log">
                    <?php
                    try {
                        echo "<div class='mb-4'>";
                        echo "<h5>Environment Information:</h5>";
                        echo "<p class='text-muted mb-1'><strong>PHP Version:</strong> " . phpversion() . "</p>";
                        echo "<p class='text-muted mb-1'><strong>Document Root:</strong> " . $_SERVER['DOCUMENT_ROOT'] . "</p>";
                        echo "<p class='text-muted mb-1'><strong>Current Path:</strong> " . getcwd() . "</p>";
                        echo "</div>";

                        echo "<p class='text-muted'><i class='bi bi-arrow-right-circle'></i> Initializing database connection...</p>";
                        require_once 'config/database.php';
                        
                        echo "<p class='text-muted'><i class='bi bi-arrow-right-circle'></i> Creating database and tables...</p>";
                        $database = new Database();
                        $db = $database->getConnection();
                        
                        if ($db) {
                            echo "<p class='text-success'><i class='bi bi-check-circle'></i> Database connection successful.</p>";
                            
                            if ($database->createTables()) {
                                echo "<div class='alert alert-success mt-4'>";
                                echo "<h4><i class='bi bi-check-circle-fill'></i> Setup Complete!</h4>";
                                echo "<p>All database tables have been created successfully.</p>";
                                echo "</div>";
                                echo "<div class='text-center mt-4'>";
                                echo "<a href='index.php' class='btn btn-primary btn-lg'>";
                                echo "<i class='bi bi-house-door'></i> Go to Homepage";
                                echo "</a>";
                                echo "</div>";
                            } else {
                                echo "<div class='alert alert-danger mt-4'>";
                                echo "<h4><i class='bi bi-x-circle-fill'></i> Setup Failed</h4>";
                                echo "<p>Error creating database tables.</p>";
                                echo "</div>";
                            }
                        } else {
                            echo "<div class='alert alert-danger mt-4'>";
                            echo "<h4><i class='bi bi-x-circle-fill'></i> Connection Failed</h4>";
                            echo "<p>Failed to connect to database.</p>";
                            echo "</div>";
                        }
                    } catch(Exception $e) {
                        echo "<div class='alert alert-danger mt-4'>";
                        echo "<h4><i class='bi bi-x-circle-fill'></i> Error Occurred</h4>";
                        echo "<p><strong>Error:</strong> " . $e->getMessage() . "</p>";
                        echo "<p><strong>File:</strong> " . $e->getFile() . "</p>";
                        echo "<p><strong>Line:</strong> " . $e->getLine() . "</p>";
                        echo "</div>";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>