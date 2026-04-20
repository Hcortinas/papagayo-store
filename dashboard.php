<?php
// Protección de sesión y conexión
include 'includes/verificar_sesion.php';
session_start();
include 'includes/conexion.php';

$nombre = $_SESSION['usuario_nombre'];
$rol    = $_SESSION['usuario_rol'];

date_default_timezone_set('America/Mexico_City');

// —————— Indicadores de conteo ——————
$total_productos = $conn->query("SELECT COUNT(*) AS total FROM productos")
                        ->fetch_assoc()['total'];
$total_ventas    = $conn->query("SELECT COUNT(*) AS total FROM ventas")
                        ->fetch_assoc()['total'];
$stock_bajo      = $conn->query("SELECT COUNT(*) AS total FROM productos WHERE cantidad < 10")
                        ->fetch_assoc()['total'];
// SOLO ventas normales del día
$ventas_hoy      = $conn->query("
    SELECT COUNT(*) AS total
    FROM ventas
    WHERE DATE(fecha)=CURDATE()
      AND origen='normal'
")->fetch_assoc()['total'];

// —————— Indicadores monetarios (solo admin/usuario) ——————
if ($rol !== 'limitado') {

  // Ventanas de tiempo (mismas que ventas_hoy.php)
  $hoy         = date('Y-m-d');
  $inicio_dia  = $hoy . ' 00:00:00';
  $fin_dia     = $hoy . ' 23:59:59';
  $ini_semana  = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
  $fin_semana  = $fin_dia;
  $ini_mes     = date('Y-m-01') . ' 00:00:00';
  $fin_mes     = $fin_dia;

  // Helpers seguros (evitan doble conteo de liquidaciones)
  $sumVentasNormal = function($desde, $hasta) use ($conn){
    $q = $conn->prepare("
      SELECT COALESCE(SUM(v.cantidad*p.precio),0) AS total
      FROM ventas v
      JOIN productos p ON p.id = v.producto_id
      WHERE v.origen = 'normal'
        AND v.fecha BETWEEN ? AND ?
    ");
    $q->bind_param('ss', $desde, $hasta);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    $q->close();
    return (float)($r['total'] ?? 0);
  };

  $sumAbonos = function($desde, $hasta) use ($conn){
    $q = $conn->prepare("
      SELECT COALESCE(SUM(monto),0) AS total
      FROM abonos
      WHERE fecha BETWEEN ? AND ?
    ");
    $q->bind_param('ss', $desde, $hasta);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    $q->close();
    return (float)($r['total'] ?? 0);
  };

  // Día
  $ventas_monto_dia = $sumVentasNormal($inicio_dia, $fin_dia);
  $abonos_dia       = $sumAbonos($inicio_dia, $fin_dia);
  $ingresos_dia     = $ventas_monto_dia + $abonos_dia;

  // Semana
  $ventas_monto_semana = $sumVentasNormal($ini_semana, $fin_semana);
  $abonos_semana       = $sumAbonos($ini_semana, $fin_semana);
  $ingresos_semana     = $ventas_monto_semana + $abonos_semana;

  // Mes
  $ventas_monto_mes = $sumVentasNormal($ini_mes, $fin_mes);
  $abonos_mes       = $sumAbonos($ini_mes, $fin_mes);
  $ingresos_mes     = $ventas_monto_mes + $abonos_mes;
}


/* =============== Indicadores por categoría (existentes, mantenidos) =============== */
if ($rol !== 'limitado') {

  // Helper para sumar ventas por rango con filtro de categorías
  if (!function_exists('sumar_ventas')) {
  function sumar_ventas($conn, $desde, $hasta, $onlyCats = null, $excludeCats = []) {
    $sql = "
      SELECT SUM(v.cantidad * p.precio) AS total
      FROM ventas v
      JOIN productos p ON p.id = v.producto_id
      WHERE v.fecha BETWEEN ? AND ?
        AND v.origen = 'normal'     /* 👈 EXCLUYE liquidaciones */
    ";
    $types = "ss";
    $params = [$desde, $hasta];

    // Incluir solo ciertas categorías
    if (is_array($onlyCats) && count($onlyCats) > 0) {
      $inMarks = implode(',', array_fill(0, count($onlyCats), '?'));
      $sql .= " AND p.categoria IN ($inMarks) ";
      $types .= str_repeat('s', count($onlyCats));
      foreach ($onlyCats as $c) $params[] = $c;
    }

    // Excluir ciertas categorías
    if (is_array($excludeCats) && count($excludeCats) > 0) {
      $inMarks = implode(',', array_fill(0, count($excludeCats), '?'));
      $sql .= " AND (p.categoria IS NULL OR p.categoria NOT IN ($inMarks)) ";
      $types .= str_repeat('s', count($excludeCats));
      foreach ($excludeCats as $c) $params[] = $c;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0.0;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row && $row['total'] !== null ? (float)$row['total'] : 0.0;
  }
}

  // Fechas de referencia (Hoy / Semana / Mes)
  $hoy           = date('Y-m-d');
  $fin_hoy       = $hoy . ' 23:59:59';
  $inicio_semana = date('Y-m-d', strtotime('monday this week')); // semana inicia lunes
  $fin_semana    = $fin_hoy;
  $inicio_mes    = date('Y-m-01');
  $fin_mes       = $fin_hoy;

  // Categorías de proveedor externo
  $CATS_PROVEEDOR = ['Cacep', 'Pozolazo', 'Charricos'];

  // 1) Neto Tienda (excluyendo proveedor)
  $neto_hoy    = sumar_ventas($conn, $hoy,           $fin_hoy,    null, $CATS_PROVEEDOR);
  $neto_semana = sumar_ventas($conn, $inicio_semana, $fin_semana, null, $CATS_PROVEEDOR);
  $neto_mes    = sumar_ventas($conn, $inicio_mes,    $fin_mes,    null, $CATS_PROVEEDOR);

  // 2) Cacep
  $cacep_hoy    = sumar_ventas($conn, $hoy,           $fin_hoy,    ['Cacep']);
  $cacep_semana = sumar_ventas($conn, $inicio_semana, $fin_semana, ['Cacep']);
  $cacep_mes    = sumar_ventas($conn, $inicio_mes,    $fin_mes,    ['Cacep']);

  // 3) Pozolazo
  $poz_hoy    = sumar_ventas($conn, $hoy,           $fin_hoy,    ['Pozolazo']);
  $poz_semana = sumar_ventas($conn, $inicio_semana, $fin_semana, ['Pozolazo']);
  $poz_mes    = sumar_ventas($conn, $inicio_mes,    $fin_mes,    ['Pozolazo']);

  // 4) Charricos
  $char_hoy    = sumar_ventas($conn, $hoy,           $fin_hoy,    ['Charricos']);
  $char_semana = sumar_ventas($conn, $inicio_semana, $fin_semana, ['Charricos']);
  $char_mes    = sumar_ventas($conn, $inicio_mes,    $fin_mes,    ['Charricos']);
}

// —————— Filtro rango de fechas ——————
$filter_total = null;
$start = $_GET['start_date']  ?? '';
$end   = $_GET['end_date']    ?? '';
if ($rol !== 'limitado' && $start && $end) {
    $stmt_f = $conn->prepare("
        SELECT SUM(v.cantidad*p.precio) AS total
        FROM ventas v
        JOIN productos p ON v.producto_id=p.id
        WHERE DATE(v.fecha) BETWEEN ? AND ?
    ");
    $stmt_f->bind_param("ss", $start, $end);
    $stmt_f->execute();
    $filter_total = $stmt_f->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt_f->close();
}

// —————— Inventario y ventas recientes ——————
$inventario = $conn->query("SELECT * FROM productos ORDER BY nombre ASC");
$historial  = $conn->query("
    SELECT v.id, p.nombre, v.cantidad, v.fecha
    FROM ventas v
    JOIN productos p ON v.producto_id=p.id
    ORDER BY v.fecha DESC
    LIMIT 10
");

// —————— Datos para gráficas ——————
$hoy = date('Y-m-d');
$grafica_dia = $conn->query("
    SELECT p.nombre, SUM(v.cantidad) AS total
    FROM ventas v
    JOIN productos p ON v.producto_id=p.id
    WHERE DATE(v.fecha)='$hoy'
    GROUP BY p.nombre
");
$labels_dia = $data_dia = [];
while ($r = $grafica_dia->fetch_assoc()) {
    $labels_dia[] = $r['nombre'];
    $data_dia[]   = $r['total'];
}

$grafica_top = $conn->query("
    SELECT p.nombre, SUM(v.cantidad) AS total
    FROM ventas v
    JOIN productos p ON v.producto_id=p.id
    GROUP BY p.nombre
    ORDER BY total DESC
    LIMIT 5
");
$labels_top = $data_top = [];
while ($r = $grafica_top->fetch_assoc()) {
    $labels_top[] = $r['nombre'];
    $data_top[]   = $r['total'];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>🪁 Panel de Control - Tienda Papagayo</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    /* — Estilos generales — */
    body {
      background:#f0f0f0;
      font-family:'Segoe UI',sans-serif;
      margin:0; padding:40px 20px;
      text-align:center; position:relative;
    }
    .logout {
      position:absolute; top:20px; right:20px;
      font-weight:bold; color:#dc3545; text-decoration:none;
    }
    .usuario-logueado {
      position:absolute; top:20px; left:20px;
      font-weight:bold; color:#333;
    }
    h1 { margin-bottom:30px; }
    /* — Indicadores de conteo — */
    .indicadores {
      display:flex; flex-wrap:wrap;
      justify-content:center; gap:20px;
      margin-bottom:30px;
    }
    .indicador {
      padding:20px; border-radius:12px;
      width:200px; font-size:16px; font-weight:bold;
      color:white; text-decoration:none;
    }
    .naranja { background:#fd7e14; }
    .azul    { background:#007BFF; }
    .rojo    { background:#dc3545; }
    .verde   { background:#28a745; }
    .morado  { background:#6f42c1; } 
    
    /* — Indicadores monetarios — */
    .indicadores-monto {
      display:flex; flex-wrap:wrap;
      justify-content:center; gap:20px;
      margin-bottom:20px;
    }
    .indicador-monto {
      padding:20px; border-radius:12px;
      width:200px; font-size:16px; font-weight:bold;
      color:white; background:#17a2b8;
    }
    .indicador-monto { 
      text-align: center; 
      line-height: 1.3;
    }
    .indicador-monto strong {
      display: block;        /* fuerza salto de línea */
      margin-top: 6px; 
      font-size: 18px;
    }
    /* — Filtro fechas — */
    .filtro {
      display:flex; flex-wrap:wrap;
      justify-content:center; gap:10px;
      margin-bottom:20px;
    }
    .filtro input {
      padding:8px; border-radius:6px; border:1px solid #ccc;
    }
    .filtro button {
      padding:9px 15px; border:none;
      border-radius:6px; background:#007BFF;
      color:#fff; cursor:pointer;
    }
    .filtro button:hover { background:#0056b3; }
    /* — Botones de panel — */
    .panel-container {
      display:flex; flex-wrap:wrap;
      justify-content:center; gap:20px;
      max-width:900px; margin:30px auto;
    }
    .boton-panel {
      background:#007BFF; color:#fff;
      text-decoration:none; padding:25px 20px;
      border-radius:12px; font-size:18px;
      width:250px; text-align:center;
      transition:background-color .3s ease;
      display:flex; flex-direction:column;
      align-items:center;
      box-shadow:0 4px 10px rgba(0,0,0,.1);
    }
    .boton-panel:hover { background:#0056b3; }
    .boton-panel .emoji { font-size:36px; margin-bottom:8px; }
    /* — Tablas — */
    table {
      width:100%; border-collapse:collapse;
      margin:30px auto; max-width:960px;
    }
    th,td {
      border:1px solid #ccc; padding:8px 12px;
      text-align:center;
    }
    th { background:#eee; }
    /* — Gráficas — */
    .graficas {
      display:flex; flex-wrap:wrap;
      justify-content:center; gap:30px;
      margin-top:50px;
    }
    .grafica-container {
      width:400px; max-width:90%;
    }
    @media(max-width:600px) {
      .boton-panel, .indicador {
        width:90%;
      }
    }

    /* === Tarjetas de categorías / neto === */
    .cards-grid {
      display:flex; flex-wrap:wrap; gap:12px; justify-content:center; margin: 14px 0 6px;
    }
    .card {
      background:#fff; border-radius:12px; padding:14px 16px;
      box-shadow:0 2px 10px rgba(0,0,0,.06);
      min-width:260px; max-width:320px; text-align:left;
    }
    .card h4 { margin:0 0 10px 0; font-size:16px; color:#555; }
    .card .v { display:flex; justify-content:space-between; padding:6px 0; border-top:1px dashed #eee; }
    .card .v:first-of-type { border-top:none; }
    .card .v span.label { color:#777; }
    .card .v span.val { font-weight:800; }
    .tag { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; margin-left:6px; background:#eef2ff; color:#334; }

    /* — Estilo alterno para ingresos — */
    .indicador-monto.ingresos { background:#20c997; } /* teal */
  </style>
</head>
<body>
  <a href="logout.php" class="logout">🚪 Cerrar sesión</a>
  <div class="usuario-logueado">👤 Bienvenida, <?= htmlspecialchars($nombre) ?></div>
  <h1>🪁 Punto de Venta - Cafetería y Tienda Papagayo</h1>

  <!-- Indicadores de conteo -->
  <div class="indicadores">
  <a class="indicador naranja" href="historial_ventas.php">🧾 Ventas Totales: <?= $total_ventas ?></a>

  <?php if ($rol !== 'limitado'): ?>
    <a class="indicador azul" href="ver_productos.php">📦 Productos Totales: <?= $total_productos ?></a>
    <a class="indicador rojo" href="stock_bajo.php">⚠️ Stock Bajo: <?= $stock_bajo ?></a>
  <?php endif; ?>

  <a class="indicador verde" href="ventas_hoy.php">📅 Movimientos del día: <?= $ventas_hoy ?></a>
</div>


  <?php if ($rol !== 'limitado'): ?>
    <!-- Indicadores monetarios (VENTAS puras) -->
    <div class="indicadores-monto">
  <div class="indicador-monto">💲 Ventas Día:<br><strong>$<?= number_format($ventas_monto_dia,2) ?></strong></div>
  <div class="indicador-monto">💲 Ventas Semana:<br><strong>$<?= number_format($ventas_monto_semana,2) ?></strong></div>
  <div class="indicador-monto">💲 Ventas Mes:<br><strong>$<?= number_format($ventas_monto_mes,2) ?></strong></div>
</div>


    <!-- NUEVO: Indicadores de INGRESOS (Ventas + Abonos + Liquidaciones) -->
    <div class="indicadores-monto">
  <div class="indicador-monto ingresos">💰 Ingresos Día:<br><strong>$<?= number_format($ingresos_dia,2) ?></strong></div>
  <div class="indicador-monto ingresos">💰 Ingresos Semana:<br><strong>$<?= number_format($ingresos_semana,2) ?></strong></div>
  <div class="indicador-monto ingresos">💰 Ingresos Mes:<br><strong>$<?= number_format($ingresos_mes,2) ?></strong></div>
</div>


    <!-- Indicadores Neto / Categorías proveedor -->
    <div class="cards-grid">
      <!-- Neto tienda (excluye Cacep/Pozolazo/Charricos) -->
      <div class="card">
        <h4>🏷️ Neto Tienda <span class="tag">Excluye proveedor</span></h4>
        <div class="v"><span class="label">Hoy</span>    <span class="val">$<?= number_format($neto_hoy, 2) ?></span></div>
        <div class="v"><span class="label">Semana</span> <span class="val">$<?= number_format($neto_semana, 2) ?></span></div>
        <div class="v"><span class="label">Mes</span>    <span class="val">$<?= number_format($neto_mes, 2) ?></span></div>
      </div>

      <!-- Cacep -->
      <div class="card">
        <h4>☕ Cacep</h4>
        <div class="v"><span class="label">Hoy</span>    <span class="val">$<?= number_format($cacep_hoy, 2) ?></span></div>
        <div class="v"><span class="label">Semana</span> <span class="val">$<?= number_format($cacep_semana, 2) ?></span></div>
        <div class="v"><span class="label">Mes</span>    <span class="val">$<?= number_format($cacep_mes, 2) ?></span></div>
      </div>

      <!-- Pozolazo -->
      <div class="card">
        <h4>🥤 Pozolazo</h4>
        <div class="v"><span class="label">Hoy</span>    <span class="val">$<?= number_format($poz_hoy, 2) ?></span></div>
        <div class="v"><span class="label">Semana</span> <span class="val">$<?= number_format($poz_semana, 2) ?></span></div>
        <div class="v"><span class="label">Mes</span>    <span class="val">$<?= number_format($poz_mes, 2) ?></span></div>
      </div>

      <!-- Charricos -->
      <div class="card">
        <h4>🍿 Charricos</h4>
        <div class="v"><span class="label">Hoy</span>    <span class="val">$<?= number_format($char_hoy, 2) ?></span></div>
        <div class="v"><span class="label">Semana</span> <span class="val">$<?= number_format($char_semana, 2) ?></span></div>
        <div class="v"><span class="label">Mes</span>    <span class="val">$<?= number_format($char_mes, 2) ?></span></div>
      </div>
    </div>

    <!-- Filtro rango de fechas -->
    <form method="GET" class="filtro" onsubmit="redirigirHistorial(event)">
  <input type="date" name="start_date" value="<?= htmlspecialchars($start) ?>" required>
  <input type="date" name="end_date"   value="<?= htmlspecialchars($end) ?>" required>
  <button type="submit">Filtrar</button>
</form>

<script>
  function redirigirHistorial(e) {
    e.preventDefault();
    const start = document.querySelector('input[name="start_date"]').value;
    const end   = document.querySelector('input[name="end_date"]').value;
    if (start && end) {
      window.location.href = `historial_ventas.php?desde=${start}&hasta=${end}`;
    }
  }
</script>


    <?php if ($filter_total !== null): ?>
      <p><strong>Total entre <?= htmlspecialchars($start) ?> y <?= htmlspecialchars($end) ?>:</strong>
      $<?= number_format($filter_total,2) ?></p>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Panel de accesos -->
<div class="panel-container">
  <?php if ($rol === 'limitado'): ?>
    <a class="boton-panel" href="registrar_ventas.php">
      <span class="emoji">💵</span>
      Registrar Venta
    </a>
    <a class="boton-panel" href="agregar_deuda.php">
      <span class="emoji">🧾</span>
      Registrar Deuda
    </a>
    <a class="boton-panel" href="ver_deudas.php">
      <span class="emoji">📋</span>
      Ver Deudas
    </a>

  <?php else: ?>
    <a class="boton-panel" href="ver_productos.php">
      <span class="emoji">📦</span>
      Ver Productos
    </a>
    <a class="boton-panel" href="agregar_producto.php">
      <span class="emoji">➕</span>
      Agregar Producto
    </a>
    <a class="boton-panel" href="registrar_ventas.php">
      <span class="emoji">💵</span>
      Registrar Venta
    </a>
    <a class="boton-panel" href="entrada_productos.php">
      <span class="emoji">📥</span>
      Entrada de Inventario
    </a>
    <a class="boton-panel" href="historial_ventas.php">
      <span class="emoji">📊</span>
      Historial de Ventas
    </a>
    <a class="boton-panel" href="agregar_deuda.php">
      <span class="emoji">🧾</span>
      Registrar Deuda
    </a>
    <a class="boton-panel" href="ver_deudas.php">
      <span class="emoji">📋</span>
      Ver Deudas
    </a>
    <?php if ($rol === 'admin'): ?>
      <a class="boton-panel" href="admin_usuarios.php">
        <span class="emoji">👤</span>
        Admin Usuarios
      </a>
      <!-- Botón respaldo manual solo admin -->
      <button class="boton-panel" style="background:#343a40;" onclick="respaldarManual()" type="button">
        <span class="emoji">🗄️</span>
        Generar Respaldo Manual
      </button>
      <script>
        function respaldarManual() {
          if(confirm('¿Deseas generar un respaldo manual de la base de datos?')) {
            fetch('respaldo_db.php').then(r=>r.text()).then(txt=>{
              // Busca el nombre del archivo generado en el texto devuelto
              const match = txt.match(/respaldo_[\w\d_:-]+\.sql/i);
              if(match) {
                const filename = match[0];
                // Descarga el archivo generado
                window.location.href = 'respaldos/' + filename;
                alert('Respaldo generado y descargado: ' + filename);
              } else {
                alert(txt.replace(/<br\s*\/?>/gi, "\n").replace(/(<([^>]+)>)/gi, ""));
              }
            }).catch(e=>{
              alert("Ocurrió un error al generar el respaldo.");
            });
          }
        }
      </script>
    <?php endif; ?>
  <?php endif; ?>
</div>


  <!-- Gráficas -->
  <?php if ($rol !== 'limitado'): ?>
  <div class="graficas">
    <div class="grafica-container">
      <h3>🟠 Ventas del Día</h3>
      <canvas id="graficaDia"></canvas>
    </div>
    <div class="grafica-container">
      <h3>🔵 Productos Más Vendidos</h3>
      <canvas id="graficaTop"></canvas>
    </div>
  </div>
<?php endif; ?>


  <!-- Inventario actual -->
  <h2>📦 Inventario Actual</h2>
  <table>
    <tr><th>ID</th><th>Nombre</th><th>Categoría</th><th>Precio</th><th>Cantidad</th></tr>
    <?php while ($row = $inventario->fetch_assoc()): ?>
      <tr>
        <td><?= $row['id'] ?></td>
        <td><?= htmlspecialchars($row['nombre']) ?></td>
        <td><?= htmlspecialchars($row['categoria']) ?></td>
        <td>$<?= number_format($row['precio'],2) ?></td>
        <td><?= $row['cantidad'] ?></td>
      </tr>
    <?php endwhile; ?>
  </table>

  <!-- Últimas ventas -->
  <h2>🧾 Últimas Ventas</h2>
  <table>
    <tr><th>ID Venta</th><th>Producto</th><th>Cantidad</th><th>Fecha</th></tr>
    <?php while ($row = $historial->fetch_assoc()): ?>
      <tr>
        <td><?= $row['id'] ?></td>
        <td><?= htmlspecialchars($row['nombre']) ?></td>
        <td><?= $row['cantidad'] ?></td>
        <td><?= date("d/m/Y H:i", strtotime($row['fecha'])) ?></td>
      </tr>
    <?php endwhile; ?>
  </table>

  <!-- Scripts de Chart.js -->
  <script>
    new Chart(
      document.getElementById('graficaDia'),
      {
        type: 'doughnut',
        data: {
          labels: <?= json_encode($labels_dia, JSON_UNESCAPED_UNICODE) ?>,
          datasets: [{
            data: <?= json_encode($data_dia) ?>,
            backgroundColor: ['#FF6384','#36A2EB','#FFCE56','#4CAF50','#FFA07A']
          }]
        },
        options: { responsive:true, plugins:{ legend:{ position:'bottom' } } }
      }
    );

    new Chart(
      document.getElementById('graficaTop'),
      {
        type: 'bar',
        data: {
          labels: <?= json_encode($labels_top, JSON_UNESCAPED_UNICODE) ?>,
          datasets: [{ data: <?= json_encode($data_top) ?>, backgroundColor:'#007BFF' }]
        },
        options: { responsive:true, scales:{ y:{ beginAtZero:true } }, plugins:{ legend:{ display:false } } }
      }
    );
  </script>
</body>
</html>
