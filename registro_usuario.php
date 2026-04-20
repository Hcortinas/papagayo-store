<?php
include('includes/conexion.php');
$mensaje = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre = $_POST["nombre"];
    $usuario = $_POST["usuario"];
    $contrasena = password_hash($_POST["contrasena"], PASSWORD_DEFAULT);
    $rol = $_POST["rol"];

    $stmt = $conn->prepare("INSERT INTO usuarios (nombre, usuario, contrasena, rol) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $nombre, $usuario, $contrasena, $rol);

    if ($stmt->execute()) {
        $mensaje = "✅ Usuario registrado exitosamente.";
    } else {
        $mensaje = "❌ Error: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Registrar Usuario</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body {
      background-color: #f0f0f0;
      font-family: 'Segoe UI', sans-serif;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
    }
    .form-box {
      background: white;
      padding: 30px;
      border-radius: 10px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
      width: 300px;
      text-align: center;
    }
    h2 {
      margin-bottom: 20px;
    }
    input, select {
      width: 100%;
      padding: 10px;
      margin: 8px 0;
      border-radius: 6px;
      border: 1px solid #ccc;
    }
    button {
      width: 100%;
      background-color: #28a745;
      color: white;
      padding: 10px;
      font-weight: bold;
      border: none;
      border-radius: 6px;
      margin-top: 12px;
      cursor: pointer;
    }
    button:hover {
      background-color: #218838;
    }
    .mensaje {
      margin-top: 10px;
      font-weight: bold;
    }
  </style>
</head>
<body>
  <div class="form-box">
    <h2>👤 Registrar Usuario</h2>
    <form method="POST" action="">
      <input type="text" name="nombre" placeholder="Nombre completo" required>
      <input type="text" name="usuario" placeholder="Nombre de usuario" required>
      <input type="password" name="contrasena" placeholder="Contraseña" required>

      <select name="rol" required>
        <option value="" disabled selected>Seleccionar rol</option>
        <option value="admin">Administrador</option>
        <option value="usuario">Usuario (Acceso completo)</option>
        <option value="limitado">Limitado (Ventas e inventario)</option>
      </select>

      <button type="submit">Crear Usuario</button>
    </form>
    <?php if ($mensaje): ?>
      <p class="mensaje"><?= $mensaje ?></p>
    <?php endif; ?>
  </div>
</body>
</html>
