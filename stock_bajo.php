<?php
include 'includes/verificar_sesion.php';
session_start();
include 'includes/conexion.php';

$result = $conn->query("
  SELECT id, nombre, categoria, precio, cantidad
  FROM productos
  WHERE cantidad < 10
  ORDER BY cantidad ASC
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Stock Bajo - Tienda Papagayo</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body {
      background:#f0f0f0; color:#222;
      font-family:'Segoe UI',sans-serif;
      margin:0; padding:40px 20px;
      text-align:center;
    }
    .contenedor { max-width:960px; margin:auto; }
    .btn-volver, .btn-pdf {
      display:inline-block; margin-bottom:15px;
      padding:10px 18px; border-radius:8px;
      text-decoration:none; font-weight:bold; color:#fff;
    }
    .btn-volver { background:#6c757d; margin-right:10px; }
    .btn-pdf    { background:#6f42c1; }
    .btn-volver:hover, .btn-pdf:hover { opacity:0.9; }
    table {
      width:100%; border-collapse:collapse;
      margin-top:20px;
    }
    th, td {
      border:1px solid #ccc; padding:10px;
      text-align:center;
    }
    th { background:#eee; }
  </style>
</head>
<body>
  <div class="contenedor">
    <a href="dashboard.php" class="btn-volver">🔙 Volver al Panel de Control</a>
    <a href="exportar_pdf.php?tipo=stock_bajo" class="btn-pdf">📄 Descargar PDF Stock Bajo</a>

    <h2>⚠️ Productos con Stock Bajo</h2>

    <?php if ($result->num_rows > 0): ?>
      <table>
        <tr><th>ID</th><th>Nombre</th><th>Categoría</th><th>Precio</th><th>Cantidad</th></tr>
        <?php while ($r = $result->fetch_assoc()): ?>
          <tr>
            <td><?= $r['id'] ?></td>
            <td><?= htmlspecialchars($r['nombre']) ?></td>
            <td><?= htmlspecialchars($r['categoria']) ?></td>
            <td>$<?= number_format($r['precio'],2) ?></td>
            <td><?= $r['cantidad'] ?></td>
          </tr>
        <?php endwhile; ?>
      </table>
    <?php else: ?>
      <p>No hay productos con stock bajo.</p>
    <?php endif; ?>
  </div>
</body>
</html>
