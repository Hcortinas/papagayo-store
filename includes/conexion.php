<?php
$host     = getenv('DB_HOST')     ?: 'localhost';
$usuario  = getenv('DB_USER')     ?: '';
$contrasena = getenv('DB_PASS')   ?: '';
$bd       = getenv('DB_NAME')     ?: '';

$conn = new mysqli($host, $usuario, $contrasena, $bd);

if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}
?>