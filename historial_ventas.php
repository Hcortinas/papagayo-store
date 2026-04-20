<?php
include('includes/verificar_sesion.php');
session_start();
include('includes/conexion.php');

date_default_timezone_set('America/Mexico_City');

$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');

$sql = "
  SELECT v.id, p.nombre AS producto, v.cantidad, p.precio, v.fecha, u.nombre AS registrado_por
  FROM ventas v
  JOIN productos p ON v.producto_id = p.id
  LEFT JOIN usuarios u ON v.usuario_id = u.id
  WHERE DATE(v.fecha) BETWEEN '$desde' AND '$hasta'
  ORDER BY v.fecha DESC
";
$resultado = $conn->query($sql);

$total_periodo = 0;
if ($resultado) {
    foreach ($resultado as $fila) {
        $total_periodo += $fila['cantidad'] * $fila['precio'];
    }
    $resultado->data_seek(0); // reiniciar puntero
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>📊 Historial de Ventas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="estilos.css">
  <style>
    body {
      background-color: #f0f0f0;
      font-family: 'Segoe UI', sans-serif;
      padding: 40px 20px;
      text-align: center;
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
    form {
      margin-bottom: 20px;
    }
    input[type="date"] {
      padding: 8px;
      margin: 0 8px;
      border-radius: 6px;
      border: 1px solid #ccc;
    }
    input[type="submit"] {
      padding: 10px 16px;
      border-radius: 8px;
      border: none;
      background-color: #007BFF;
      color: white;
      font-weight: bold;
      cursor: pointer;
    }
    table {
      width: 100%;
      max-width: 960px;
      margin: auto;
      border-collapse: collapse;
      background-color: white;
      box-shadow: 0 0 10px rgba(0,0,0,0.05);
    }
    th, td {
      border: 1px solid #ccc;
      padding: 10px;
      text-align: center;
    }
    th {
      background-color: #f9f9f9;
    }
    .total {
      margin-top: 20px;
      font-weight: bold;
      font-size: 18px;
    }
    .exportar {
      margin-top: 15px;
      display: inline-block;
      background-color: #17a2b8;
      color: white;
      padding: 10px 18px;
      border-radius: 8px;
      text-decoration: none;
      font-weight: bold;
    }
  </style>
</head>
<body>
  <a href="dashboard.php" class="btn-volver">🔙 Volver al Panel de Control</a>
  <h2>📊 Historial de Ventas</h2>

  <form method="GET">
    <label>Desde:</label>
    <input type="date" name="desde" value="<?= $desde ?>" required>
    <label>Hasta:</label>
    <input type="date" name="hasta" value="<?= $hasta ?>" required>
    <input type="submit" value="Filtrar">
  </form>

  <?php if ($resultado && $resultado->num_rows > 0): ?>
    <table>
      <tr>
        <th>ID Venta</th>
        <th>Producto</th>
        <th>Cantidad</th>
        <th>Precio Unitario</th>
        <th>Total</th>
        <th>Fecha</th>
        <th>Registrado por</th>
      </tr>
      <?php while($row = $resultado->fetch_assoc()): ?>
        <tr>
          <td><?= $row['id'] ?></td>
          <td><?= htmlspecialchars($row['producto']) ?></td>
          <td><?= $row['cantidad'] ?></td>
          <td>$<?= number_format($row['precio'], 2) ?></td>
          <td>$<?= number_format($row['precio'] * $row['cantidad'], 2) ?></td>
          <td><?= date("d/m/Y H:i", strtotime($row['fecha'])) ?></td>
          <td><?= htmlspecialchars($row['registrado_por']) ?></td>
        </tr>
      <?php endwhile; ?>
    </table>

    <div class="total">🧾 Total en el período: $<?= number_format($total_periodo, 2) ?></div>
    <a class="exportar" href="exportar_pdf.php?tipo=historial&desde=<?= $desde ?>&hasta=<?= $hasta ?>">
      📄 Exportar PDF
    </a>

  <?php else: ?>
    <p>No se encontraron ventas en este período.</p>
  <?php endif; ?>
</body>
</html>
