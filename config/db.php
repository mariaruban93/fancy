<?php
// Database connection using PDO
// Update these variables according to your MySQL setup
$host = 'localhost';
$db   = 'multi_branch_pos_36';
$user = 'root';
$pass = '12345678';
$charset = 'utf8mb4';

// Data Source Name
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Establish a database connection
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // If connection fails, throw an exception with the error message
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

?>