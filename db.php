<?php
$host = "localhost";
$user = "root";
$pass = "1234"; // Set your MySQL password
$db = "doctor";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>