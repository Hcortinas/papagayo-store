<?php
include('includes/verificar_sesion.php');
include('includes/conexion.php');

// Solo admin puede acceder
if ($_SESSION['usuario_rol'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$mensaje = "";

// Procesar formulario
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['usuario_id'], $_POST['nueva_contrasena'])) {
    $usuario_id = intval($_POST['usuario_id']);
    $nueva_contrasena = trim($_POST['nueva_contrasena']);

    if (strlen($nueva_contrasena) < 4) {
        $mensaje = "❌ La contraseña debe tener al menos 4 caracteres.";
    } else {
        $hash = password_hash($nueva_contrasena, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE usuarios SET contrasena = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $usuario_id);
        if ($stmt->execute()) {
            $mensaje = "✅ Contraseña actualizada correctamente.";
        } else {
            $mensaje = "❌ Error al actualizar la contraseña.";
        }
    }
}

// Obtener lista de usuarios
$usuarios = $conn->query("SELECT id, nombre FROM usuarios ORDER BY nombre ASC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Cambiar Contraseña</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="estilos.css">
  <style>
    body {
      background-color: #f0f0f0;
      font-family: 'Segoe UI', sans-serif;
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
    select, input[type="password"] {
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
      font-weight: bold;
      margin-bottom: 15px;
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
    <a href="dashboard.php" class="btn-volver">🔙 Volver al Dashboard</a>
    <h2>🔐 Cambiar Contraseña de Usuario</h2>

    <?php if ($mensaje): ?>
      <div class="mensaje" style="color: <?= strpos($mensaje, '✅') !== false ? 'green' : 'red' ?>;">
        <?= $mensaje ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="">
      <label>Selecciona usuario:</label>
      <select name="usuario_id" required>
        <option value="" disabled selected>-- Seleccionar --</option>
        <?php while($u = $usuarios->fetch_assoc()): ?>
          <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?></option>
        <?php endwhile; ?>
      </select>

      <label>Nueva contraseña:</label>
      <input type="password" name="nueva_contrasena" required>

      <input type="submit" value="Actualizar Contraseña">
    </form>
  </div>
</body>
</html>
