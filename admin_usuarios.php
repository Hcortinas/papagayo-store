<?php
include('includes/verificar_sesion.php');
include('includes/conexion.php');

if ($_SESSION['usuario_rol'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$mensaje = "";

// Registrar nuevo usuario
if (isset($_POST['nuevo_nombre'], $_POST['nuevo_usuario'], $_POST['nuevo_contra'], $_POST['nuevo_rol'])) {
    $nombre  = trim($_POST['nuevo_nombre']);
    $usuario = trim($_POST['nuevo_usuario']);
    $contra  = trim($_POST['nuevo_contra']);
    $rol     = $_POST['nuevo_rol'];

    if (strlen($nombre) < 3 || strlen($usuario) < 3 || strlen($contra) < 4) {
        $mensaje = "❌ Todos los campos deben tener al menos 3 o 4 caracteres.";
    } else {
        $hash = password_hash($contra, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO usuarios (nombre, usuario, contrasena, rol) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $nombre, $usuario, $hash, $rol);
        if ($stmt->execute()) {
            $mensaje = "✅ Usuario registrado correctamente.";
        } else {
            $mensaje = "❌ Error al registrar usuario (posiblemente ya existe el usuario).";
        }
    }
}

// Cambiar contraseña de usuario
if (isset($_POST['cambiar_usuario_id'], $_POST['nueva_contra'])) {
    $id     = intval($_POST['cambiar_usuario_id']);
    $nueva  = trim($_POST['nueva_contra']);

    if (strlen($nueva) < 4) {
        $mensaje = "❌ La nueva contraseña debe tener al menos 4 caracteres.";
    } else {
        $hash = password_hash($nueva, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE usuarios SET contrasena = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $id);
        if ($stmt->execute()) {
            $mensaje = "✅ Contraseña actualizada correctamente.";
        } else {
            $mensaje = "❌ Error al actualizar la contraseña.";
        }
    }
}

// Obtener usuarios existentes
$usuarios = $conn->query("SELECT id, nombre FROM usuarios ORDER BY nombre ASC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>👤 Administrar Usuarios</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="estilos.css">
  <style>
    body {
      background: #f0f0f0;
      font-family: 'Segoe UI', sans-serif;
      padding: 40px 20px;
      text-align: center;
    }
    .contenedor {
      max-width: 600px;
      margin: auto;
      background: white;
      padding: 30px;
      border-radius: 12px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }
    h2 {
      margin-top: 0;
    }
    form {
      margin-bottom: 30px;
    }
    input, select {
      width: 100%;
      padding: 10px;
      margin-top: 10px;
      border-radius: 8px;
      border: 1px solid #ccc;
    }
    input[type="submit"] {
      background: #007BFF;
      color: white;
      font-weight: bold;
      cursor: pointer;
    }
    input[type="submit"]:hover {
      background: #0056b3;
    }
    .mensaje {
      font-weight: bold;
      margin: 10px 0;
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
  <div class="contenedor">
    <a href="dashboard.php" class="btn-volver">🔙 Volver al Dashboard</a>
    <h2>👥 Panel de Administración de Usuarios</h2>

    <?php if ($mensaje): ?>
      <div class="mensaje" style="color:<?= strpos($mensaje, '✅') !== false ? 'green' : 'red' ?>;">
        <?= $mensaje ?>
      </div>
    <?php endif; ?>

    <form method="POST">
      <h3>➕ Registrar Nuevo Usuario</h3>
      <input type="text" name="nuevo_nombre" placeholder="Nombre completo" required>
      <input type="text" name="nuevo_usuario" placeholder="Nombre de usuario" required>
      <input type="password" name="nuevo_contra" placeholder="Contraseña" required>
      <select name="nuevo_rol" required>
        <option value="" disabled selected>Selecciona Rol</option>
        <option value="admin">Administrador</option>
        <option value="usuario">Usuario</option>
        <option value="limitado">Limitado</option>
      </select>
      <input type="submit" value="Registrar Usuario">
    </form>

    <form method="POST">
      <h3>🔐 Cambiar Contraseña</h3>
      <select name="cambiar_usuario_id" required>
        <option value="" disabled selected>Selecciona Usuario</option>
        <?php while($u = $usuarios->fetch_assoc()): ?>
          <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?></option>
        <?php endwhile; ?>
      </select>
      <input type="password" name="nueva_contra" placeholder="Nueva contraseña" required>
      <input type="submit" value="Actualizar Contraseña">
    </form>
  </div>
</body>
</html>
