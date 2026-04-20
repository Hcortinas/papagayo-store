<?php
include('includes/conexion.php');
include('includes/verificar_sesion.php');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = $_POST['id'];
    $nombre = $_POST['nombre'];
    $categoria = $_POST['categoria'];
    $precio = $_POST['precio'];

    // Validación básica
    if (!$id || !$nombre || !is_numeric($precio)) {
        echo "Datos inválidos.";
        exit;
    }

    $stmt = $conn->prepare("UPDATE productos SET nombre = ?, categoria = ?, precio = ? WHERE id = ?");
    $stmt->bind_param("ssdi", $nombre, $categoria, $precio, $id);

    if ($stmt->execute()) {
        echo "✅ Producto actualizado correctamente.";
        echo "<br><a href='ver_productos.php'>Volver al listado</a>";
    } else {
        echo "❌ Error al actualizar el producto: " . $conn->error;
    }
} else {
    echo "Acceso no permitido.";
}
?>
