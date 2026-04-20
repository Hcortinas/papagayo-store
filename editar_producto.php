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

$mensaje = "";
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo "ID de producto inválido.";
    exit();
}

// Obtener datos del producto
$producto = $conn->prepare("SELECT * FROM productos WHERE id = ?");
$producto->bind_param("i", $id);
$producto->execute();
$resultado = $producto->get_result();

if ($resultado->num_rows === 0) {
    echo "Producto no encontrado.";
    exit();
}

$datos = $resultado->fetch_assoc();

// Procesar edición
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre = $_POST["nombre"];
    $categoria = $_POST["categoria"];
    $precio = floatval($_POST["precio"]);
    $cantidad = intval($_POST["cantidad"]);

    $stmt = $conn->prepare("UPDATE productos SET nombre = ?, categoria = ?, precio = ?, cantidad = ? WHERE id = ?");
    $stmt->bind_param("ssdii", $nombre, $categoria, $precio, $cantidad, $id);

    if ($stmt->execute()) {
        header("Location: editar_producto.php?id=$id&mensaje=ok");
        exit();
    } else {
        $mensaje = "❌ Error al actualizar el producto.";
    }
}

if (isset($_GET['mensaje']) && $_GET['mensaje'] === 'ok') {
    $mensaje = "✅ Producto actualizado correctamente.";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Editar Producto</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body {
      background-color: #f0f0f0;
      font-family: 'Segoe UI', sans-serif;
      margin: 0;
      padding: 40px 20px;
      text-align: center;
    }
    .formulario {
      max-width: 500px;
      margin: auto;
      background-color: white;
      padding: 30px;
      border-radius: 12px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }
    h2 {
      margin-bottom: 20px;
    }
    input[type="text"], input[type="number"] {
      width: 100%;
      padding: 10px;
      margin: 10px 0;
      border-radius: 8px;
      border: 1px solid #ccc;
    }
    input[type="submit"] {
      background-color: #007BFF;
      color: white;
      border: none;
      padding: 12px 20px;
      border-radius: 8px;
      font-weight: bold;
      cursor: pointer;
    }
    input[type="submit"]:hover {
      background-color: #0056b3;
    }
    .mensaje {
      margin-bottom: 15px;
      font-weight: bold;
      color: green;
    }
    .error {
      color: red;
    }
    .btn-volver {
      display: inline-block;
      margin-bottom: 20px;
      background-color: #6c757d;
      color: white;
      padding: 10px 18px;
      border-radius: 8px;
      text-decoration: none;
      font-weight: bold;
    }
  </style>
</head>
<body>
  <div class="formulario">
    <a href="ver_productos.php" class="btn-volver">🔙 Volver a Productos</a>
    <h2>✏️ Editar Producto</h2>

    <?php if ($mensaje): ?>
      <div class="<?= strpos($mensaje, '✅') !== false ? 'mensaje' : 'mensaje error' ?>">
        <?= $mensaje ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="">
      <input type="text" name="nombre" value="<?= htmlspecialchars($datos['nombre']) ?>" required>
      <input type="text" name="categoria" value="<?= htmlspecialchars($datos['categoria']) ?>">
      <input type="number" step="0.01" name="precio" value="<?= $datos['precio'] ?>" required>
      <input type="number" name="cantidad" value="<?= $datos['cantidad'] ?>" required>

      <input type="submit" value="Guardar Cambios">
    </form>
  </div>
</body>
</html>
