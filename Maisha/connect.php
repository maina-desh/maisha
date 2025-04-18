<?php
// Database credentials
$servername = "localhost";
$username = "root";  // default username for MySQL
$password = "strong_password";      // default password for MySQL (empty by default)
$dbname = "Maisha_sacco";  // the name of the database you just created

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
