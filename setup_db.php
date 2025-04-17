<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // First connection without database
    $conn = new PDO(
        "mysql:host=localhost:3307",
        "root",
        ''
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to MySQL successfully!\n";
    
    // Create database
    $conn->exec("CREATE DATABASE IF NOT EXISTS task_manager");
    echo "Database 'task_manager' created or already exists\n";
    
    // Connect to the database
    $conn = new PDO(
        "mysql:host=localhost:3307;dbname=task_manager",
        "root",
        
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to task_manager database\n";
    
    // Create tables
    $tables = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(100) NOT NULL,
            description TEXT,
            deadline DATETIME NOT NULL,
            status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
            priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS task_updates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            status_from ENUM('pending', 'in_progress', 'completed', 'cancelled'),
            status_to ENUM('pending', 'in_progress', 'completed', 'cancelled'),
            update_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            notes TEXT,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        )"
    ];
    
    foreach ($tables as $sql) {
        $conn->exec($sql);
        echo "Table created successfully\n";
    }
    
    // Verify tables
    $stmt = $conn->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "\nExisting tables:\n";
    foreach ($tables as $table) {
        echo "- " . $table . "\n";
    }
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>