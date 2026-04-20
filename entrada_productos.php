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

// Procesar entrada
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $producto_id = intval($_POST["producto_id"]);
    $cantidad_nueva = intval($_POST["cantidad"]);

    if ($producto_id > 0 && $cantidad_nueva > 0) {
        $stmt = $conn->prepare("UPDATE productos SET cantidad = cantidad + ? WHERE id = ?");
        $stmt->bind_param("ii", $cantidad_nueva, $producto_id);

        if ($stmt->execute()) {
            header("Location: entrada_productos.php?mensaje=ok");
            exit();
        } else {
            $mensaje = "❌ Error al actualizar el stock.";
        }
    } else {
        $mensaje = "❌ Datos inválidos.";
    }
}

if (isset($_GET['mensaje']) && $_GET['mensaje'] === 'ok') {
    $mensaje = "✅ Stock actualizado exitosamente.";
}

// Obtener productos para el desplegable
$productos = $conn->query("SELECT id, nombre, cantidad FROM productos ORDER BY nombre ASC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Entrada de Productos</title>
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
    select, input[type="number"] {
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
    <h2>📥 Entrada de Inventario</h2>

    <?php if ($mensaje): ?>
      <div class="<?= strpos($mensaje, '✅') !== false ? 'mensaje' : 'mensaje error' ?>">
        <?= $mensaje ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="entrada_productos.php">
      <label>Producto:</label>
      <select name="producto_id" required>
        <option value="" disabled selected>Selecciona un producto</option>
        <?php while ($row = $productos->fetch_assoc()): ?>
          <option value="<?= $row['id'] ?>">
            <?= htmlspecialchars($row['nombre']) ?> (<?= $row['cantidad'] ?> actuales)
          </option>
        <?php endwhile; ?>
      </select>

      <label>Cantidad a ingresar:</label>
      <input type="number" name="cantidad" min="1" required>

      <input type="submit" value="Actualizar Inventario">
    </form>
  </div>
</body>
</html>
