<?php
class Database {
    private $host = "localhost:3307";
    private $db_name = "task_manager";
    private $username = "root";
    private $password = '';
    private $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            // Create database if it doesn't exist
            $this->conn = new PDO(
                "mysql:host=" . $this->host,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create database if it doesn't exist
            $this->conn->exec("CREATE DATABASE IF NOT EXISTS `" . $this->db_name . "`");
            
            // Connect with database and create tables
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8");
            
            // Create tables after successful connection
            $this->createTables();
            
            return $this->conn;
        } catch(PDOException $e) {
            echo "Connection Error: " . $e->getMessage();
            return null;
        }
    }

    public function createTables() {
        try {
            $queries = [
                // Users table
                "CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(100) UNIQUE NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    name VARCHAR(100),
                    role ENUM('user', 'admin') DEFAULT 'user',
                    status ENUM('active', 'inactive') DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    last_login TIMESTAMP NULL
                )",

                // Tasks table
                "CREATE TABLE IF NOT EXISTS tasks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    title VARCHAR(100) NOT NULL,
                    description TEXT,
                    deadline DATETIME NOT NULL,
                    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
                    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )",

                // Task updates for tracking changes and generating reports
                "CREATE TABLE IF NOT EXISTS task_updates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    task_id INT NOT NULL,
                    user_id INT NOT NULL,
                    status_from ENUM('pending', 'in_progress', 'completed', 'cancelled'),
                    status_to ENUM('pending', 'in_progress', 'completed', 'cancelled'),
                    update_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    notes TEXT,
                    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )",

                // Password reset tokens
                "CREATE TABLE IF NOT EXISTS password_resets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(100) NOT NULL,
                    token VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    expires_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    used BOOLEAN DEFAULT FALSE,
                    INDEX (email),
                    INDEX (token)
                )",

                // User activity logs
                "CREATE TABLE IF NOT EXISTS user_activity (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    action VARCHAR(100) NOT NULL,
                    details TEXT,
                    ip_address VARCHAR(45),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )"
            ];

            foreach ($queries as $query) {
                $this->conn->exec($query);
            }
            return true;
        } catch(PDOException $e) {
            error_log("Table Creation Error: " . $e->getMessage());
            return false;
        }
    }
}
?>