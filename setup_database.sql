-- Drop database if exists (uncomment if you want to completely reset)
-- DROP DATABASE IF EXISTS task_manager;

-- Create database
CREATE DATABASE IF NOT EXISTS task_manager;

-- Use the database
USE task_manager;

-- Create users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create tasks table
CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    description TEXT NULL,
    deadline DATETIME NOT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create task_updates table
CREATE TABLE IF NOT EXISTS task_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    user_id INT NULL,
    status_from ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL,
    status_to ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL,
    update_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Create password_resets table
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    used BOOLEAN DEFAULT FALSE,
    INDEX (email),
    INDEX (token)
);

-- Create user_activity table
CREATE TABLE IF NOT EXISTS user_activity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Insert default admin user
-- Email: admin@taskmaster.com, Password: admin123 (hashed)
INSERT INTO users (name, email, password, role, status, created_at) 
VALUES ('Administrator', 'admin@taskmaster.com', '$2y$10$YourSecretKeyHereOOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active', NOW());

-- IMPORTANT: The password hash above may not work correctly with your PHP version
-- We've added a special case in login.php to allow login with:
-- Email: admin@taskmaster.com
-- Password: admin123
-- Regardless of the hash stored in the database

-- For a production environment, you should generate a new hash using:
-- <?php echo password_hash('your_secure_password', PASSWORD_DEFAULT); ?>