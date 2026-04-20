<?php
include('includes/conexion.php');
include('includes/verificar_sesion.php');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $producto_id = $_POST['producto_id'];
    $cantidad_agregada = $_POST['cantidad_agregada'];

    if (!is_numeric($producto_id) || !is_numeric($cantidad_agregada) || $cantidad_agregada <= 0) {
        header("Location: entrada_productos.php?mensaje=error");
        exit;
    }

    // Sumar al stock
    $stmt = $conn->prepare("UPDATE productos SET cantidad = cantidad + ? WHERE id = ?");
    $stmt->bind_param("ii", $cantidad_agregada, $producto_id);

    if ($stmt->execute()) {
        header("Location: entrada_productos.php?mensaje=ok");
    } else {
        header("Location: entrada_productos.php?mensaje=error");
    }
    exit;
} else {
    header("Location: entrada_productos.php");
    exit;
}
?>
