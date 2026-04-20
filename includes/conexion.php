<?php
$host = "localhost";
$usuario = "museopap_usertienda";
$contrasena = "Papagayo2024!";
$bd = "museopap_tienda_papagayo";

// Crear conexión
$conn = new mysqli($host, $usuario, $contrasena, $bd);

// Verificar conexión
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}
?>
