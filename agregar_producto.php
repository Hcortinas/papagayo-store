<?php
include('includes/verificar_sesion.php');
$rol = $_SESSION['usuario_rol'];

if (!in_array($rol, ['admin', 'usuario'])) {
    echo "⛔ Acceso denegado. Esta función no está disponible para tu rol.";
    exit;
}

session_start();
include('includes/conexion.php');

$mensaje = "";
$rol = $_SESSION['usuario_rol'];

// Procesar formulario
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre = $_POST["nombre"];
    $categoria = $_POST["categoria"];
    $precio = floatval($_POST["precio"]);
    $cantidad = intval($_POST["cantidad"]);

    $stmt = $conn->prepare("INSERT INTO productos (nombre, categoria, precio, cantidad) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssdi", $nombre, $categoria, $precio, $cantidad);

    if ($stmt->execute()) {
        header("Location: agregar_producto.php?mensaje=ok");
        exit();
    } else {
        $mensaje = "❌ Error al agregar el producto.";
    }
}

if (isset($_GET['mensaje']) && $_GET['mensaje'] === 'ok') {
    $mensaje = "✅ Producto agregado exitosamente.";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Agregar Producto</title>
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
    <a href="dashboard.php" class="btn-volver">🔙 Volver al Panel de Control</a>
    <h2>➕ Agregar Nuevo Producto</h2>

    <?php if ($mensaje): ?>
      <div class="<?= strpos($mensaje, '✅') !== false ? 'mensaje' : 'mensaje error' ?>">
        <?= $mensaje ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="agregar_producto.php">
      <input type="text" name="nombre" placeholder="Nombre del producto" required>
      <input type="text" name="categoria" placeholder="Categoría">
      <input type="number" name="precio" step="0.01" placeholder="Precio" required>
      <input type="number" name="cantidad" placeholder="Cantidad inicial" required>

      <input type="submit" value="Agregar Producto">
    </form>
  </div>
</body>
</html>
