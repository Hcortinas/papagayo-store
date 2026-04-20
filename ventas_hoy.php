<?php
include 'includes/verificar_sesion.php';
session_start();
include 'includes/conexion.php';
date_default_timezone_set('America/Mexico_City');

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$fecha  = $_GET['fecha'] ?? date('Y-m-d');
$inicio = $fecha . ' 00:00:00';
$fin    = $fecha . ' 23:59:59';

/* ======================== CONSULTAS ======================== */

/* 1) Ventas normales del día (detalle por producto) */
$sqlVentasDetalle = "
  SELECT p.nombre, v.cantidad, p.precio, v.fecha
  FROM ventas v
  JOIN productos p ON p.id = v.producto_id
  WHERE (v.origen IS NULL OR v.origen='normal')
    AND v.fecha BETWEEN ? AND ?
  ORDER BY v.fecha DESC
";
$stVD = $conn->prepare($sqlVentasDetalle);
$stVD->bind_param('ss', $inicio, $fin);
$stVD->execute();
$resVD = $stVD->get_result();

$ventas_detalle = [];
$ventas_total = 0;
while ($v = $resVD->fetch_assoc()){
  $subtotal = $v['cantidad'] * $v['precio'];
  $ventas_total += $subtotal;
  $ventas_detalle[] = [
    'producto' => $v['nombre'],
    'cantidad' => (int)$v['cantidad'],
    'subtotal' => $subtotal,
    'fecha'    => $v['fecha']
  ];
}
$stVD->close();

/* 2) Abonos del día (una sola línea por cliente) */
$sqlCobros = "
  SELECT 
    COALESCE(c.id, 0) AS cliente_id,
    TRIM(COALESCE(c.nombre, a.observaciones, 'Cliente sin ficha')) AS cliente,
    SUM(a.monto)          AS cobrado,
    MAX(a.fecha)          AS ultima_hora,
    MAX(a.es_liquidacion) AS liquida_hoy
  FROM abonos a
  LEFT JOIN clientes c ON c.id = a.cliente_id
  WHERE a.fecha BETWEEN ? AND ?
  GROUP BY cliente_id, cliente
  ORDER BY ultima_hora DESC
";
$stC = $conn->prepare($sqlCobros);
$stC->bind_param('ss', $inicio, $fin);
$stC->execute();
$resCobros = $stC->get_result();

$personas = [];
$cobros_total = 0.0;
while ($r = $resCobros->fetch_assoc()) {
  $label = $r['cliente'] . (($r['liquida_hoy'] ?? 0) ? ' (liquida)' : ' (abono)');
  $personas[] = [
    'cliente' => $label,
    'cobrado' => (float)$r['cobrado'],
    'ultima'  => $r['ultima_hora'],
  ];
  $cobros_total += (float)$r['cobrado'];
}
$stC->close();

/* Totales */
$ingresos_total = $ventas_total + $cobros_total;

/* Navegación fechas */
$prev = date('Y-m-d', strtotime($fecha.' -1 day'));
$next = date('Y-m-d', strtotime($fecha.' +1 day'));
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>📅 Ingresos del Día</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{ background:#f0f0f0; font-family:'Segoe UI',sans-serif; margin:0; padding:40px 20px; text-align:center; color:#222;}
    .wrap{ max-width:1000px; margin:0 auto; }
    .btn-volver{ display:inline-block; margin-bottom:16px; background:#6c757d; color:#fff; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:bold; }
    .selector-fecha{ display:flex; justify-content:center; align-items:center; gap:8px; margin:10px 0 20px; flex-wrap:wrap; }
    .selector-fecha a, .selector-fecha button{
      background:#007BFF; color:#fff; border:none; border-radius:8px; padding:8px 12px; text-decoration:none; font-weight:600; cursor:pointer;
    }
    .selector-fecha a:hover, .selector-fecha button:hover{ opacity:.9; }
    .selector-fecha input[type="date"]{ padding:8px; border-radius:6px; border:1px solid #ccc; }
    .cards{ display:flex; flex-wrap:wrap; gap:12px; justify-content:center; margin:10px 0 20px; }
    .card{ background:#fff; border-radius:12px; padding:14px 18px; box-shadow:0 2px 10px rgba(0,0,0,.06); min-width:220px; }
    .card h4{ margin:0 0 6px; font-size:16px; color:#555; }
    .card .monto{ font-size:22px; font-weight:800; }
    table{ width:100%; background:#fff; border-collapse:collapse; margin:16px auto; }
    th,td{ border:1px solid #ddd; padding:10px; text-align:center; }
    th{ background:#eee; }
    .total-final{ font-size:20px; font-weight:800; margin:12px 0 8px; }
    .muted{ color:#666; font-size:13px; }
  </style>
</head>
<body>
  <div class="wrap">
    <a href="dashboard.php" class="btn-volver">🔙 Volver al Panel de Control</a>
    <h2>📅 Ingresos del día: <?= e(date('d/m/Y', strtotime($fecha))) ?></h2>

    <!-- Selector de fecha -->
    <form class="selector-fecha" method="GET">
      <a href="?fecha=<?= e($prev) ?>">⬅️ Día anterior</a>
      <input type="date" name="fecha" value="<?= e($fecha) ?>" required>
      <button type="submit">Ir</button>
      <a href="?fecha=<?= e($next) ?>">Día siguiente ➡️</a>
      <a href="?fecha=<?= e(date('Y-m-d')) ?>" style="background:#20c997;">Hoy</a>
    </form>

    <!-- Tarjetas resumen -->
    <div class="cards">
      <div class="card">
        <h4>🛒 Ventas (normales)</h4>
        <div class="monto">$<?= number_format($ventas_total,2) ?></div>
      </div>
      <div class="card">
        <h4>💵 Cobros de deudas</h4>
        <div class="monto">$<?= number_format($cobros_total,2) ?></div>
      </div>
      <div class="card">
        <h4>💰 Ingreso total del día</h4>
        <div class="monto">$<?= number_format($ingresos_total,2) ?></div>
      </div>
    </div>

    <!-- Tabla: ventas detalladas + cobros -->
    <table>
      <tr>
        <th>Concepto / Cliente</th>
        <th>Monto</th>
        <th>Hora</th>
      </tr>

      <!-- Ventas normales (detalle) -->
      <?php if (!empty($ventas_detalle)): ?>
        <?php foreach ($ventas_detalle as $v): ?>
          <tr>
            <td><?= e($v['cantidad'].' x '.$v['producto']) ?></td>
            <td>$<?= number_format($v['subtotal'],2) ?></td>
            <td><?= e(date('H:i', strtotime($v['fecha']))) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="3"><em>Sin ventas normales en esta fecha.</em></td></tr>
      <?php endif; ?>

      <!-- Cobros (abonos/liquidaciones) -->
      <?php if (!empty($personas)): ?>
        <?php foreach ($personas as $p): ?>
          <tr>
            <td><?= e($p['cliente']) ?></td>
            <td>$<?= number_format($p['cobrado'],2) ?></td>
            <td><?= e(date('H:i', strtotime($p['ultima']))) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="3"><em>Sin cobros por deudas en esta fecha.</em></td></tr>
      <?php endif; ?>

      <!-- Total -->
      <tr>
        <td style="text-align:right;"><strong>Total ingresos del día</strong></td>
        <td><strong>$<?= number_format($ingresos_total,2) ?></strong></td>
        <td></td>
      </tr>
    </table>

    <div class="total-final">💰 Ingreso del día: $<?= number_format($ingresos_total,2) ?></div>
  </div>
</body>
</html>
