<?php
include('includes/verificar_sesion.php');
$rol = $_SESSION['usuario_rol'];

if (!in_array($rol, ['admin', 'usuario'])) {
    echo "⛔ Acceso denegado. Esta función no está disponible para tu rol.";
    exit;
}

session_start();
include('includes/conexion.php');

$rol = $_SESSION['usuario_rol'];

// Bloquear acceso a roles limitados
if ($rol === 'limitado') {
    header("Location: dashboard.php");
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id > 0) {
    $stmt = $conn->prepare("DELETE FROM productos WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        header("Location: ver_productos.php?mensaje=eliminado");
        exit();
    } else {
        echo "❌ Error al eliminar el producto.";
    }
} else {
    echo "❌ ID de producto inválido.";
}
?>
