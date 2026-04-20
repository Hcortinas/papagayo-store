<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$fpdfPath = __DIR__ . '/vendor/fpdf/fpdf.php';
if (!file_exists($fpdfPath)) {
    die("❌ No se encontró FPDF en: $fpdfPath");
}
require $fpdfPath;
if (!class_exists('FPDF')) {
    die("❌ Clase FPDF no encontrada.");
}

include 'includes/verificar_sesion.php';
include 'includes/conexion.php';

$tipo = $_GET['tipo'] ?? 'productos';
$desde = $_GET['desde'] ?? null;
$hasta = $_GET['hasta'] ?? null;
$modo = $_GET['modo'] ?? 'pendientes';
date_default_timezone_set('America/Mexico_City');

$pdf = new FPDF('L','mm','A4');
$pdf->SetMargins(10, 10, 10);

switch ($tipo) {
    case 'deudas':
        $desde = $desde ?? date('Y-m-01');
        $hasta = $hasta ?? date('Y-m-d');
        $titulo = 'Listado de Deudas';
        $cols = ['ID','Empleado','Producto','Cantidad','Monto','Fecha','Registrado por'];
        $colWidth = ($pdf->GetPageWidth() - 20) / count($cols);

        // Base SQL
        $where = "DATE(d.fecha) BETWEEN '$desde' AND '$hasta'";
        $base = "
            SELECT d.id, d.empleado, p.nombre AS producto, d.cantidad, d.monto,
                   d.fecha, u.nombre AS registrado_por
            FROM deudas d
            JOIN productos p ON d.producto_id = p.id
            JOIN usuarios u ON d.registrado_por = u.id
            WHERE $where
        ";

        if ($modo === 'todas') {
            $res1 = $conn->query($base . " AND d.estado = 'pendiente' ORDER BY d.fecha DESC");
            $res2 = $conn->query($base . " AND d.estado = 'pagado' ORDER BY d.fecha DESC");

            foreach (['Pendientes' => $res1, 'Liquidadas' => $res2] as $estado => $res) {
                $pdf->AddPage();
                $pdf->SetFont('Arial','B',14);
                $pdf->Cell(0, 10, utf8_decode("Deudas $estado del $desde al $hasta"), 0, 1, 'C');
                $pdf->Ln(4);
                $pdf->SetFont('Arial','B',12);
                foreach ($cols as $col) {
                    $pdf->Cell($colWidth, 8, utf8_decode($col), 1, 0, 'C');
                }
                $pdf->Ln();
                $pdf->SetFont('Arial','',11);
                while ($row = $res->fetch_assoc()) {
                    $fila = [
                        $row['id'],
                        $row['empleado'],
                        $row['producto'],
                        $row['cantidad'],
                        '$' . number_format($row['monto'], 2),
                        date('d/m/Y', strtotime($row['fecha'])),
                        $row['registrado_por']
                    ];
                    foreach ($fila as $val) {
                        $pdf->Cell($colWidth, 7, utf8_decode($val), 1, 0, 'C');
                    }
                    $pdf->Ln();
                }
            }

        } else {
            $res = $conn->query($base . " AND d.estado = 'pendiente' ORDER BY d.fecha DESC");
            $pdf->AddPage();
            $pdf->SetFont('Arial','B',14);
            $pdf->Cell(0, 10, utf8_decode("Deudas Pendientes del $desde al $hasta"), 0, 1, 'C');
            $pdf->Ln(4);
            $pdf->SetFont('Arial','B',12);
            foreach ($cols as $col) {
                $pdf->Cell($colWidth, 8, utf8_decode($col), 1, 0, 'C');
            }
            $pdf->Ln();
            $pdf->SetFont('Arial','',11);
            while ($row = $res->fetch_assoc()) {
                $fila = [
                    $row['id'],
                    $row['empleado'],
                    $row['producto'],
                    $row['cantidad'],
                    '$' . number_format($row['monto'], 2),
                    date('d/m/Y', strtotime($row['fecha'])),
                    $row['registrado_por']
                ];
                foreach ($fila as $val) {
                    $pdf->Cell($colWidth, 7, utf8_decode($val), 1, 0, 'C');
                }
                $pdf->Ln();
            }
        }

        $pdf->Output('D', "Deudas_$modo.pdf");
        exit;

    // Resto de casos ya existentes
    case 'historial':
        $titulo = 'Historial de Ventas';
        $filtro_fecha = "";

        if ($desde && $hasta) {
            $filtro_fecha = "WHERE DATE(v.fecha) BETWEEN '$desde' AND '$hasta'";
            $titulo .= " ($desde a $hasta)";
        }

        $sql = "
          SELECT v.id, p.nombre AS producto, v.cantidad, p.precio, v.fecha, u.nombre AS registrado_por
          FROM ventas v
          INNER JOIN productos p ON v.producto_id = p.id
          LEFT JOIN usuarios u ON v.usuario_id = u.id
          $filtro_fecha
          ORDER BY v.fecha DESC
        ";
        $cols = ['ID Venta', 'Producto', 'Cantidad', 'Precio Unitario', 'Total', 'Fecha', 'Registrado por'];
        break;

    case 'stock_bajo':
        $titulo = 'Productos con Stock Bajo';
        $sql = "SELECT id, nombre, categoria, precio, cantidad FROM productos WHERE cantidad < 10 ORDER BY cantidad ASC";
        $cols = ['ID','Nombre','Categoría','Precio','Cantidad'];
        break;

    case 'ventas_hoy':
        $hoy = date('Y-m-d');
        $titulo = 'Ventas de Hoy ' . date('d/m/Y');
        $sql = "
          SELECT v.id, p.nombre AS producto, v.cantidad, p.precio, v.fecha, u.nombre AS registrado_por
          FROM ventas v
          INNER JOIN productos p ON v.producto_id = p.id
          LEFT JOIN usuarios u ON v.usuario_id = u.id
          WHERE DATE(v.fecha) = '$hoy'
          ORDER BY v.fecha DESC
        ";
        $cols = ['ID Venta', 'Producto', 'Cantidad', 'Precio Unitario', 'Total', 'Fecha', 'Registrado por'];
        break;

    case 'productos':
    default:
        $titulo = 'Listado de Productos';
        $sql = "SELECT id, nombre, categoria, precio, cantidad FROM productos ORDER BY nombre ASC";
        $cols = ['ID','Nombre','Categoría','Precio','Cantidad'];
        break;
}

if ($tipo !== 'deudas') {
    $result = $conn->query($sql);
    if ($result === false) {
        die("❌ Error en la consulta SQL: " . $conn->error);
    }

    $pdf->AddPage();
    $pdf->SetFont('Arial','B',16);
    $pdf->Cell(0, 10, utf8_decode($titulo), 0, 1, 'C');
    $pdf->Ln(4);

    $pdf->SetFont('Arial','B',12);
    $totalCols = count($cols);
    $pageWidth = $pdf->GetPageWidth() - 20;
    $colWidth  = $pageWidth / $totalCols;

    foreach ($cols as $col) {
        $pdf->Cell($colWidth, 8, utf8_decode($col), 1, 0, 'C');
    }
    $pdf->Ln();

    $pdf->SetFont('Arial','',11);
    $total = 0;

    while ($row = $result->fetch_assoc()) {
        if ($tipo === 'historial' || $tipo === 'ventas_hoy') {
            $totalFila = $row['cantidad'] * $row['precio'];
            $fila = [
                $row['id'],
                utf8_decode($row['producto']),
                $row['cantidad'],
                '$' . number_format($row['precio'], 2),
                '$' . number_format($totalFila, 2),
                date('d/m/Y H:i', strtotime($row['fecha'])),
                utf8_decode($row['registrado_por'])
            ];
            $total += $totalFila;
        } else {
            $fila = array_values($row);
            foreach ($cols as $i => $colName) {
                if (stripos($colName, 'Precio') !== false || stripos($colName, 'Monto') !== false) {
                    $fila[$i] = '$' . number_format($fila[$i], 2);
                }
                if (stripos($colName, 'Fecha') !== false && strtotime($fila[$i])) {
                    $fila[$i] = date('d/m/Y H:i', strtotime($fila[$i]));
                }
            }
        }

        foreach ($fila as $celda) {
            $pdf->Cell($colWidth, 7, utf8_decode($celda), 1, 0, 'C');
        }
        $pdf->Ln();
    }

    if ($tipo === 'historial' || $tipo === 'ventas_hoy') {
        $pdf->Ln(5);
        $pdf->SetFont('Arial','B',12);
        $pdf->Cell(0, 10, utf8_decode('Total del Período: $' . number_format($total, 2)), 0, 1, 'R');
    }

    $pdf->Output('D', "$titulo.pdf");
    exit;
}
