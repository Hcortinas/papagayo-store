<?php
// reparar_deudas.php
include 'includes/conexion.php';
date_default_timezone_set('America/Mexico_City');

$conn->begin_transaction();

try {
    // 1. Reiniciar todas las deudas
    $conn->query("UPDATE deudas SET saldo_detalle = monto, estado='pendiente', fecha_pago=NULL, liquidada_en=NULL");

    // 2. Obtener todos los clientes que tengan abonos
    $resClientes = $conn->query("SELECT cliente_id, observaciones FROM abonos ORDER BY cliente_id");
    $clientes = [];
    while ($r = $resClientes->fetch_assoc()) {
        $key = $r['cliente_id'] > 0 ? $r['cliente_id'] : 'anon_' . md5($r['observaciones']);
        $clientes[$key] = [
            'id' => $r['cliente_id'],
            'obs'=> $r['observaciones']
        ];
    }

    foreach ($clientes as $c) {
        $cid = (int)$c['id'];

        // Total abonado por cliente
        $sql = "SELECT SUM(monto) AS total_abonos FROM abonos WHERE ";
        if ($cid > 0) {
            $sql .= "cliente_id=$cid";
        } else {
            // Para los sin ficha, usamos observaciones como fallback
            $sql .= "cliente_id IS NULL AND observaciones='".$conn->real_escape_string($c['obs'])."'";
        }
        $row = $conn->query($sql)->fetch_assoc();
        $totalAbonos = (float)($row['total_abonos'] ?? 0);

        if ($totalAbonos <= 0) continue;

        // Traer deudas pendientes/parciales en orden FIFO
        if ($cid > 0) {
            $stmt = $conn->prepare("SELECT id, saldo_detalle, monto FROM deudas WHERE cliente_id=? AND (estado='pendiente' OR estado='parcial') ORDER BY fecha ASC, id ASC FOR UPDATE");
            $stmt->bind_param("i", $cid);
        } else {
            $stmt = $conn->prepare("SELECT id, saldo_detalle, monto FROM deudas WHERE cliente_id IS NULL AND empleado=? AND (estado='pendiente' OR estado='parcial') ORDER BY fecha ASC, id ASC FOR UPDATE");
            $stmt->bind_param("s", $c['obs']);
        }
        $stmt->execute();
        $resD = $stmt->get_result();

        $restante = $totalAbonos;
        while ($restante > 0 && ($d = $resD->fetch_assoc())) {
            $idDeuda = (int)$d['id'];
            $saldo   = (float)$d['saldo_detalle'];
            if ($saldo <= 0.00001) continue;

            $aplicar = min($restante, $saldo);
            $nuevo   = round($saldo - $aplicar, 2);

            $estado = 'pendiente';
            if ($nuevo <= 0.00001) {
                $nuevo = 0.00;
                $estado = 'pagado';
            } elseif ($nuevo < $d['monto']) {
                $estado = 'parcial';
            }

            $stmtU = $conn->prepare("UPDATE deudas SET saldo_detalle=?, estado=?, fecha_pago=CASE WHEN ?=0 THEN NOW() ELSE fecha_pago END, liquidada_en=CASE WHEN ?=0 THEN NOW() ELSE liquidada_en END WHERE id=?");
            $stmtU->bind_param("dsddi", $nuevo, $estado, $nuevo, $nuevo, $idDeuda);
            $stmtU->execute();

            $restante = round($restante - $aplicar, 2);
        }
    }

    $conn->commit();
    echo "<h2>✅ Reparación completada. Revisa ver_deudas.php para confirmar.</h2>";
} catch (Exception $e) {
    $conn->rollback();
    echo "❌ Error: ".$e->getMessage();
}
