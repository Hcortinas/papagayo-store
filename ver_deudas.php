<?php
include('includes/verificar_sesion.php');
include('includes/conexion.php');
date_default_timezone_set('America/Mexico_City');

$usuario_id = $_SESSION['usuario_id'] ?? 0;

// ===== Utilidades =====
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function nombreCliente($row){
  return ($row['cliente_nombre'] !== null && $row['cliente_nombre'] !== '')
    ? $row['cliente_nombre']
    : $row['empleado_fallback'];
}
function clienteKey($row){
  return $row['cliente_id'] ? ('c'.$row['cliente_id']) : ('n'.md5($row['empleado_fallback']));
}

// ===== Acciones POST =====

/* 1) Liquidar deuda individual (por renglón) — la acción existe, pero quitamos el botón en UI */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'liquidar_deuda' && isset($_POST['deuda_id'])) {
  $id = (int)$_POST['deuda_id'];

  $deuda = $conn->prepare("
    SELECT id, producto_id, cantidad, registrado_por, monto, saldo_detalle, cliente_id, empleado
    FROM deudas
    WHERE id=? AND (estado='pendiente' OR estado='parcial')
    FOR UPDATE
  ");
  $deuda->bind_param("i", $id);
  $conn->begin_transaction();
  try {
    $deuda->execute();
    $res = $deuda->get_result();
    if (!$res || $res->num_rows !== 1) throw new Exception('Deuda no encontrada o ya pagada.');
    $d = $res->fetch_assoc();

    $saldoActual    = (float)$d['saldo_detalle'];
    $cli_id         = (int)$d['cliente_id'];
    $empleado_name  = $d['empleado'];

    if ($saldoActual <= 0.00001) $saldoActual = 0.00;

    // ABONO de liquidación por el saldo pendiente de ESTE renglón
    if ($cli_id > 0) {
      $stmtA = $conn->prepare("INSERT INTO abonos (cliente_id, monto, fecha, registrado_por, observaciones, es_liquidacion)
                               VALUES (?, ?, NOW(), ?, 'Liquidación (deuda individual)', 1)");
      $stmtA->bind_param("idi", $cli_id, $saldoActual, $usuario_id);
    } else {
      $obs = 'Liquidación: ' . $empleado_name . ' (deuda individual)';
      $stmtA = $conn->prepare("INSERT INTO abonos (cliente_id, monto, fecha, registrado_por, observaciones, es_liquidacion)
                               VALUES (NULL, ?, NOW(), ?, ?, 1)");
      $stmtA->bind_param("dis", $saldoActual, $usuario_id, $obs);
    }
    $stmtA->execute();
    $abonoId = $conn->insert_id;

    // Cerrar el renglón
    $stmtU = $conn->prepare("UPDATE deudas
      SET estado='pagado', saldo_detalle=0, liquidada_en=NOW(), fecha_pago=NOW()
      WHERE id=?");
    $stmtU->bind_param("i", $id);
    $stmtU->execute();

    // Bitácora de aplicación
    $stmtB = $conn->prepare("INSERT INTO abonos_aplicacion (abono_id, deuda_id, monto_aplicado, cerro_renglon)
                             VALUES (?, ?, ?, 1)");
    $stmtB->bind_param("iid", $abonoId, $id, $saldoActual);
    $stmtB->execute();

    // No generamos ventas por liquidación
    $conn->commit();
  } catch (Exception $e) {
    $conn->rollback();
  }

  header("Location: ver_deudas.php");
  exit;
}

/* 2) Abonar a un cliente (monto parcial) — aplica en cascada (FIFO) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'abonar_cliente') {
  $cliente_id     = isset($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : 0;
  $cliente_nombre = isset($_POST['cliente_nombre']) ? trim($_POST['cliente_nombre']) : '';
  $monto          = isset($_POST['monto']) ? round((float)$_POST['monto'], 2) : 0.0;

  if ($monto > 0) {
    $conn->begin_transaction();
    try {
      // 2.1) Crear un SOLO registro de abono (total ingresado)
      if ($cliente_id > 0) {
        $stmtA = $conn->prepare("INSERT INTO abonos (cliente_id, monto, fecha, registrado_por, observaciones, es_liquidacion)
                                 VALUES (?, ?, NOW(), ?, 'Abono desde ver_deudas', 0)");
        $stmtA->bind_param("idi", $cliente_id, $monto, $usuario_id);
      } else {
        $obs = 'Abono a: '.$cliente_nombre;
        $stmtA = $conn->prepare("INSERT INTO abonos (cliente_id, monto, fecha, registrado_por, observaciones, es_liquidacion)
                                 VALUES (NULL, ?, NOW(), ?, ?, 0)");
        $stmtA->bind_param("dis", $monto, $usuario_id, $obs);
      }
      $stmtA->execute();
      $abonoId = (int)$conn->insert_id;

      // 2.2) Traer deudas con saldo > 0, en FIFO (bloqueadas)
      if ($cliente_id > 0) {
        $stmtD = $conn->prepare("
          SELECT id, producto_id, cantidad, saldo_detalle, monto
          FROM deudas
          WHERE (estado='pendiente' OR estado='parcial') AND cliente_id=? AND saldo_detalle>0
          ORDER BY fecha ASC, id ASC
          FOR UPDATE
        ");
        $stmtD->bind_param("i", $cliente_id);
      } else {
        $stmtD = $conn->prepare("
          SELECT id, producto_id, cantidad, saldo_detalle, monto, empleado
          FROM deudas
          WHERE (estado='pendiente' OR estado='parcial') AND cliente_id IS NULL AND empleado=? AND saldo_detalle>0
          ORDER BY fecha ASC, id ASC
          FOR UPDATE
        ");
        $stmtD->bind_param("s", $cliente_nombre);
      }
      $stmtD->execute();
      $resD = $stmtD->get_result();

      // 2.3) Reparto en cascada
      $restante  = $monto;

      while ($restante > 0 && ($d = $resD->fetch_assoc())) {
        $deuda_id = (int)$d['id'];
        $saldo    = (float)$d['saldo_detalle'];
        if ($saldo <= 0.00001) continue;

        $aplicar = min($restante, $saldo);
        $nuevo   = round($saldo - $aplicar, 2);
        $cerro   = ($nuevo <= 0.00001) ? 1 : 0;
        if ($cerro) { $nuevo = 0.00; }

        // Actualizar renglón y estado
        $stmtU = $conn->prepare("
          UPDATE deudas
          SET saldo_detalle=?,
              estado = CASE
                         WHEN ? <= 0 THEN 'pagado'
                         WHEN ? < monto THEN 'parcial'
                         ELSE 'pendiente'
                       END,
              liquidada_en = CASE WHEN ? <= 0 THEN NOW() ELSE liquidada_en END,
              fecha_pago   = CASE WHEN ? <= 0 THEN NOW() ELSE fecha_pago   END
          WHERE id=?
        ");
        $stmtU->bind_param("dddddi", $nuevo, $nuevo, $nuevo, $nuevo, $nuevo, $deuda_id);
        $stmtU->execute();

        // Bitácora de aplicación
        $stmtB = $conn->prepare("INSERT INTO abonos_aplicacion (abono_id, deuda_id, monto_aplicado, cerro_renglon) VALUES (?,?,?,?)");
        $stmtB->bind_param("iidi", $abonoId, $deuda_id, $aplicar, $cerro);
        $stmtB->execute();

        // No generamos ventas por liquidación
        $restante = round($restante - $aplicar, 2);
      }

      // 2.4) ¿Este abono dejó SALDO GLOBAL = 0? -> marcar es_liquidacion=1
      if ($cliente_id > 0) {
        $qSaldo = $conn->prepare("SELECT COALESCE(SUM(saldo_detalle),0) AS saldo
                                  FROM deudas
                                  WHERE (estado='pendiente' OR estado='parcial') AND cliente_id=?");
        $qSaldo->bind_param("i", $cliente_id);
      } else {
        $qSaldo = $conn->prepare("SELECT COALESCE(SUM(saldo_detalle),0) AS saldo
                                  FROM deudas
                                  WHERE (estado='pendiente' OR estado='parcial') AND cliente_id IS NULL AND empleado=?");
        $qSaldo->bind_param("s", $cliente_nombre);
      }
      $qSaldo->execute();
      $saldo_total = (float)$qSaldo->get_result()->fetch_assoc()['saldo'];
      $es_liq = ($saldo_total <= 0.00001) ? 1 : 0;

      $up = $conn->prepare("UPDATE abonos SET es_liquidacion=? WHERE id=?");
      $up->bind_param("ii", $es_liq, $abonoId);
      $up->execute();

      // 2.5) Si sobró dinero, deja nota
      if ($restante > 0) {
        $left = number_format($restante, 2, '.', '');
        $stmtO = $conn->prepare("UPDATE abonos SET observaciones = CONCAT(IFNULL(observaciones,''),' | Cambio no aplicado: $', ?) WHERE id=?");
        $stmtO->bind_param("si", $left, $abonoId);
        $stmtO->execute();
      }

      $conn->commit();
    } catch (Exception $e) {
      $conn->rollback();
    }
  }

  header("Location: ver_deudas.php");
  exit;
}

/* 3) Liquidar TODO por cliente — usa suma de saldo_detalle */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'liquidar_cliente') {
  $cliente_id     = isset($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : 0;
  $cliente_nombre = isset($_POST['cliente_nombre']) ? trim($_POST['cliente_nombre']) : '';

  $conn->begin_transaction();
  try {
    // 3.1) Traer todas las deudas con saldo del cliente (bloquear)
    if ($cliente_id > 0) {
      $q = $conn->prepare("
        SELECT id, producto_id, cantidad, saldo_detalle
        FROM deudas
        WHERE (estado='pendiente' OR estado='parcial') AND cliente_id=? AND saldo_detalle>0
        ORDER BY fecha ASC, id ASC
        FOR UPDATE
      ");
      $q->bind_param("i", $cliente_id);
    } else {
      $q = $conn->prepare("
        SELECT id, producto_id, cantidad, saldo_detalle
        FROM deudas
        WHERE (estado='pendiente' OR estado='parcial') AND cliente_id IS NULL AND empleado=? AND saldo_detalle>0
        ORDER BY fecha ASC, id ASC
        FOR UPDATE
      ");
      $q->bind_param("s", $cliente_nombre);
    }
    $q->execute();
    $rs = $q->get_result();
    if (!$rs || $rs->num_rows === 0) {
      $conn->rollback();
      header("Location: ver_deudas.php");
      exit;
    }

    // 3.2) Calcular total a liquidar
    $total_liq = 0.0;
    $rows = [];
    while ($r = $rs->fetch_assoc()) { $rows[] = $r; $total_liq += (float)$r['saldo_detalle']; }

    // 3.3) Registrar ABONO de liquidación por el total_liq
    if ($cliente_id > 0) {
      $stmtA = $conn->prepare("INSERT INTO abonos (cliente_id, monto, fecha, registrado_por, observaciones, es_liquidacion)
                               VALUES (?, ?, NOW(), ?, 'Liquidación total', 1)");
      $stmtA->bind_param("idi", $cliente_id, $total_liq, $usuario_id);
    } else {
      $obs = 'Liquidación: ' . $cliente_nombre . ' (total)';
      $stmtA = $conn->prepare("INSERT INTO abonos (cliente_id, monto, fecha, registrado_por, observaciones, es_liquidacion)
                               VALUES (NULL, ?, NOW(), ?, ?, 1)");
      $stmtA->bind_param("dis", $total_liq, $usuario_id, $obs);
    }
    $stmtA->execute();
    $abonoId = $conn->insert_id;

    // 3.4) Cerrar cada renglón y bitácora
    foreach ($rows as $r) {
      $deuda_id = (int)$r['id'];
      $saldo    = (float)$r['saldo_detalle'];
      if ($saldo <= 0.00001) continue;

      $stmtU = $conn->prepare("UPDATE deudas
        SET estado='pagado', saldo_detalle=0, liquidada_en=NOW(), fecha_pago=NOW()
        WHERE id=?");
      $stmtU->bind_param("i", $deuda_id);
      $stmtU->execute();

      $stmtB = $conn->prepare("INSERT INTO abonos_aplicacion (abono_id, deuda_id, monto_aplicado, cerro_renglon)
                               VALUES (?, ?, ?, 1)");
      $stmtB->bind_param("iid", $abonoId, $deuda_id, $saldo);
      $stmtB->execute();
    }

    // No generamos ventas por liquidación
    $conn->commit();
  } catch (Exception $e) {
    $conn->rollback();
  }

  header("Location: ver_deudas.php");
  exit;
}

// ===== Datos para tablas =====

/*
 * PENDIENTES / PARCIALES — SIN FILTRO DE FECHA (GLOBAL)
 * (Solo renglones con saldo > 0)
 */
$sqlPend = "
  SELECT
    COALESCE(c.id, 0)                          AS cliente_id,
    COALESCE(c.nombre, NULL)                   AS cliente_nombre,
    d.empleado                                 AS empleado_fallback,
    SUM(d.monto)                               AS total_deuda,
    SUM(d.monto - d.saldo_detalle)             AS total_abonado,
    SUM(d.saldo_detalle)                       AS total_saldo,
    COUNT(*)                                   AS partidas,
    MIN(d.fecha)                               AS fecha_min,
    MAX(d.fecha)                               AS fecha_max
  FROM deudas d
  LEFT JOIN clientes c ON c.id = d.cliente_id
  WHERE (d.estado='pendiente' OR d.estado='parcial')
    AND d.saldo_detalle > 0
  GROUP BY cliente_id, cliente_nombre, empleado_fallback
  ORDER BY cliente_nombre IS NULL, cliente_nombre ASC, empleado_fallback ASC
";
$pendientes = $conn->query($sqlPend);

/* Detalle de pendientes — SIN FILTRO DE FECHA (GLOBAL) */
$sqlDetPend = "
  SELECT
    d.id, d.producto_id, p.nombre AS producto, d.cantidad, d.monto, d.saldo_detalle, d.estado, d.fecha,
    COALESCE(c.id, 0) AS cliente_id, COALESCE(c.nombre, NULL) AS cliente_nombre, d.empleado AS empleado_fallback
  FROM deudas d
  JOIN productos p ON p.id = d.producto_id
  LEFT JOIN clientes c ON c.id = d.cliente_id
  WHERE (d.estado='pendiente' OR d.estado='parcial')
    AND d.saldo_detalle > 0
  ORDER BY d.fecha DESC, d.id DESC
";
$detPend = $conn->query($sqlDetPend);
$detallePendientes = [];
if ($detPend) {
  while ($r = $detPend->fetch_assoc()) {
    $key = ($r['cliente_id'] > 0) ? ('c'.$r['cliente_id']) : ('n'.md5($r['empleado_fallback']));
    if (!isset($detallePendientes[$key])) $detallePendientes[$key] = [];
    $detallePendientes[$key][] = $r;
  }
}

/*
 * LIQUIDADAS — TODAS (sin filtro de fecha)
 */
$sqlPag = "
  SELECT
    COALESCE(c.id, 0)                        AS cliente_id,
    COALESCE(c.nombre, NULL)                 AS cliente_nombre,
    d.empleado                               AS empleado_fallback,
    SUM(d.monto)                             AS total_pagado,
    COUNT(*)                                 AS partidas,
    MIN(d.fecha)                             AS fecha_min,
    MAX(d.fecha)                             AS fecha_max
  FROM deudas d
  LEFT JOIN clientes c ON c.id = d.cliente_id
  WHERE d.estado='pagado'
  GROUP BY cliente_id, cliente_nombre, empleado_fallback
  ORDER BY cliente_nombre IS NULL, cliente_nombre ASC, empleado_fallback ASC
";
$pagadas = $conn->query($sqlPag);

/* Detalle liquidadas — TODAS */
$sqlDetPag = "
  SELECT
    d.id, d.producto_id, p.nombre AS producto, d.cantidad, d.monto, d.fecha,
    COALESCE(c.id, 0) AS cliente_id, COALESCE(c.nombre, NULL) AS cliente_nombre, d.empleado AS empleado_fallback
  FROM deudas d
  JOIN productos p ON p.id = d.producto_id
  LEFT JOIN clientes c ON c.id = d.cliente_id
  WHERE d.estado='pagado'
  ORDER BY d.fecha DESC, d.id DESC
";
$detPag = $conn->query($sqlDetPag);
$detallePagadas = [];
if ($detPag) {
  while ($r = $detPag->fetch_assoc()) {
    $key = ($r['cliente_id'] > 0) ? ('c'.$r['cliente_id']) : ('n'.md5($r['empleado_fallback']));
    if (!isset($detallePagadas[$key])) $detallePagadas[$key] = [];
    $detallePagadas[$key][] = $r;
  }
}

// Reset punteros
if ($pendientes) $pendientes->data_seek(0);
if ($pagadas)    $pagadas->data_seek(0);

// Total SALDO pendiente GLOBAL
$total_pendiente_global = 0.0;
if ($pendientes) while ($row = $pendientes->fetch_assoc()) $total_pendiente_global += (float)$row['total_saldo'];
if ($pendientes) $pendientes->data_seek(0);

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>📋 Ver Deudas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { background:#f0f0f0; font-family:'Segoe UI',sans-serif; padding:40px 20px; text-align:center; color:#222; }
    .btn-volver { display:inline-block; margin-bottom:20px; background:#6c757d; color:white; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:bold; }
    input[type="submit"], .btn { padding:10px 16px; border-radius:8px; border:none; background:#007BFF; color:#fff; font-weight:bold; cursor:pointer; margin-left:8px; text-decoration:none; }
    input[type="submit"]:hover, .btn:hover { background:#0056b3; }
    .wrap { max-width:1100px; margin: 0 auto; }
    table { width:100%; border-collapse:collapse; background:#fff; margin:14px auto; }
    th,td { padding:10px; border:1px solid #ccc; text-align:center; }
    th { background:#eee; }
    .buscar { width:100%; max-width:420px; margin: 0 auto 10px; padding:10px; border-radius:10px; border:1px solid #ccc; }
    .acciones { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; }
    .btn-sec { background:#6c757d; }
    .btn-ok { background:#28a745; }
    .btn-warn { background:#ffc107; color:#222; }
    .detalle { background:#fafafa; }
    .total { font-weight:700; font-size:17px; margin: 8px auto 16px; }
    .badge { display:inline-block; padding:6px 10px; border-radius:8px; }
  </style>
</head>
<body>
  <div class="wrap">
    <a href="dashboard.php" class="btn-volver">🔙 Volver al Panel de Control</a>
    <h2>📋 Deudas</h2>

    <!-- 🔎 Buscador en vivo para PENDIENTES -->
    <input id="buscador-deudor-pend" class="buscar" type="text" placeholder="Buscar deudor en pendientes…">

    <!-- ===== PENDIENTES AGRUPADAS ===== -->
    <h3>🕓 Deudas Pendientes (agrupadas por cliente) — <small>todas</small></h3>
    <?php if ($pendientes && $pendientes->num_rows > 0): ?>
      <table id="tabla-deudores-pend">
        <thead>
          <tr>
            <th>Cliente</th>
            <th>Partidas</th>
            <th>Total</th>
            <th>Abonado</th>
            <th>Saldo</th>
            <th>Rango</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $pendientes->fetch_assoc()):
            $key   = clienteKey($row);
            $name  = nombreCliente($row);
            $total = (float)$row['total_deuda'];
            $abon  = (float)$row['total_abonado'];
            $saldo = (float)$row['total_saldo'];
          ?>
          <tr data-key="<?= e($key) ?>">
            <td class="td-cliente"><?= e($name) ?></td>
            <td><?= (int)$row['partidas'] ?></td>
            <td>$<?= number_format($total, 2) ?></td>
            <td>$<?= number_format($abon, 2) ?></td>
            <td><strong>$<?= number_format($saldo, 2) ?></strong></td>
            <td><?= date("d/m/Y", strtotime($row['fecha_min'])) ?> - <?= date("d/m/Y", strtotime($row['fecha_max'])) ?></td>
            <td class="acciones">
              <button type="button" class="btn btn-sec" onclick="toggleDetalle('pend', '<?= e($key) ?>')">👁️ Ver detalle</button>
              <form method="POST" style="display:inline;" onsubmit="return confirm('¿Liquidar TODAS las deudas con saldo de este cliente?')">
                <input type="hidden" name="accion" value="liquidar_cliente">
                <input type="hidden" name="cliente_id" value="<?= (int)$row['cliente_id'] ?>">
                <input type="hidden" name="cliente_nombre" value="<?= e($row['empleado_fallback']) ?>">
                <button type="submit" class="btn btn-ok">💸 Liquidar todo</button>
              </form>
              <form method="POST" style="display:inline;" onsubmit="return validarAbono(this);">
                <input type="hidden" name="accion" value="abonar_cliente">
                <input type="hidden" name="cliente_id" value="<?= (int)$row['cliente_id'] ?>">
                <input type="hidden" name="cliente_nombre" value="<?= e($row['empleado_fallback']) ?>">
                <input type="number" name="monto" min="0.01" step="0.01" placeholder="$ monto" style="width:120px; padding:8px; border:1px solid #ccc; border-radius:8px;">
                <button type="submit" class="btn btn-warn">➕ Abonar</button>
              </form>
            </td>
          </tr>
          <tr id="detalle-pend-<?= e($key) ?>" class="detalle" style="display:none;">
            <td colspan="7" style="text-align:left;">
              <?php if (!empty($detallePendientes[$key])): ?>
                <table style="width:100%; background:#fff;">
                  <tr>
                    <th>ID</th>
                    <th>Producto</th>
                    <th>Cantidad</th>
                    <th>Subtotal</th>
                    <th>Abonado</th>
                    <th>Saldo</th>
                    <th>Estado</th>
                    <th>Fecha</th>
                    <!-- (sin Acción: quitamos Liquidar por producto) -->
                  </tr>
                  <?php foreach ($detallePendientes[$key] as $d): ?>
                    <?php
                      $abonado = max(0, (float)$d['monto'] - (float)$d['saldo_detalle']); // acumulado por deuda
                      $saldo   = (float)$d['saldo_detalle'];
                    ?>
                    <tr>
                      <td><?= (int)$d['id'] ?></td>
                      <td><?= e($d['producto']) ?></td>
                      <td><?= (int)$d['cantidad'] ?></td>
                      <td>$<?= number_format($d['monto'], 2) ?></td>
                      <td>$<?= number_format($abonado, 2) ?></td>
                      <td>$<?= number_format($saldo, 2) ?></td>
                      <td><?= e($d['estado']) ?></td>
                      <td><?= date("d/m/Y", strtotime($d['fecha'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </table>
              <?php else: ?>
                <em>Sin detalle disponible.</em>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
      <div class="total">💰 Total SALDO pendiente <strong>global</strong>: $<?= number_format($total_pendiente_global, 2) ?></div>
    <?php else: ?>
      <p>No hay deudas pendientes/parciales.</p>
    <?php endif; ?>

    <!-- 🔎 Buscador en vivo para LIQUIDADAS -->
    <input id="buscador-deudor-pag" class="buscar" type="text" placeholder="Buscar deudor en liquidadas…">

    <!-- ===== LIQUIDADAS AGRUPADAS ===== -->
    <h3>✅ Deudas Liquidadas (agrupadas por cliente) — <small>todas</small></h3>
    <?php if ($pagadas && $pagadas->num_rows > 0): ?>
      <table id="tabla-deudores-pag">
        <thead>
          <tr>
            <th>Cliente</th>
            <th>Partidas</th>
            <th>Total Pagado</th>
            <th>Rango</th>
            <th>Detalle</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $pagadas->fetch_assoc()):
            $key  = clienteKey($row);
            $name = nombreCliente($row);
          ?>
          <tr data-key="<?= e($key) ?>">
            <td class="td-cliente"><?= e($name) ?></td>
            <td><?= (int)$row['partidas'] ?></td>
            <td>$<?= number_format((float)$row['total_pagado'], 2) ?></td>
            <td><?= date("d/m/Y", strtotime($row['fecha_min'])) ?> - <?= date("d/m/Y", strtotime($row['fecha_max'])) ?></td>
            <td class="acciones">
              <button type="button" class="btn btn-sec" onclick="toggleDetalle('pag', '<?= e($key) ?>')">👁️ Ver detalle</button>
            </td>
          </tr>
          <tr id="detalle-pag-<?= e($key) ?>" class="detalle" style="display:none;">
            <td colspan="5" style="text-align:left;">
              <?php if (!empty($detallePagadas[$key])): ?>
                <table style="width:100%; background:#fff;">
                  <tr>
                    <th>ID</th>
                    <th>Producto</th>
                    <th>Cantidad</th>
                    <th>Monto</th>
                    <th>Fecha</th>
                  </tr>
                  <?php foreach ($detallePagadas[$key] as $d): ?>
                    <tr>
                      <td><?= (int)$d['id'] ?></td>
                      <td><?= e($d['producto']) ?></td>
                      <td><?= (int)$d['cantidad'] ?></td>
                      <td>$<?= number_format($d['monto'], 2) ?></td>
                      <td><?= date("d/m/Y", strtotime($d['fecha'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </table>
              <?php else: ?>
                <em>Sin detalle disponible.</em>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p>No hay deudas liquidadas.</p>
    <?php endif; ?>

    <!-- Exportar PDF (sin rango) -->
    <div style="margin-top: 20px;">
      <a class="btn" href="exportar_pdf.php?tipo=deudas&modo=pendientes">📄 Exportar Pendientes</a>
      <a class="btn" href="exportar_pdf.php?tipo=deudas&modo=todas">📄 Exportar Todas</a>
    </div>

  </div>

  <script>
    function toggleDetalle(tipo, key){
      const row = document.getElementById('detalle-'+tipo+'-'+key);
      if (!row) return;
      row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
    }
    function validarAbono(form){
      const inp = form.querySelector('input[name="monto"]');
      if (!inp) return true;
      const v = parseFloat(inp.value || '0');
      if (isNaN(v) || v <= 0) { alert('Ingresa un monto válido (> 0).'); inp.focus(); return false; }
      return confirm('¿Registrar este abono?');
    }
    function initLiveFilter(inputId, tableId){
      const input = document.getElementById(inputId);
      const table = document.getElementById(tableId);
      if (!input || !table || !table.tBodies[0]) return;
      const norm = s => (s||'').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
      function filtra(){
        const q = norm(input.value);
        const bodyRows = Array.from(table.tBodies[0].rows).filter(r => !r.classList.contains('detalle'));
        for (const r of bodyRows){
          const nombre = norm(r.querySelector('.td-cliente')?.textContent || '');
          const show = !q || nombre.includes(q);
          r.style.display = show ? '' : 'none';
          const key = r.getAttribute('data-key');
          const det = document.getElementById('detalle-'+(tableId.endsWith('pend')?'pend':'pag')+'-'+key);
          if (det && !show) det.style.display = 'none';
        }
      }
      input.addEventListener('input', filtra);
    }
    initLiveFilter('buscador-deudor-pend','tabla-deudores-pend');
    initLiveFilter('buscador-deudor-pag','tabla-deudores-pag');
  </script>
</body>
</html>
