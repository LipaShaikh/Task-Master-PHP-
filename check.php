<?php
require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db) {
        echo "Database connection successful!\n";
        
        // List all tables
        $stmt = $db->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo "\nExisting tables:\n";
        foreach ($tables as $table) {
            echo "- " . $table . "\n";
        }
    } else {
        echo "Failed to connect to database!";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>