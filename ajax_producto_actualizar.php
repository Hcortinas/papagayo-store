<?php
include 'includes/verificar_sesion.php';
session_start();
include 'includes/conexion.php';

header('Content-Type: application/json; charset=utf-8');

// Solo admin/usuario pueden editar
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] === 'limitado') {
  http_response_code(403);
  echo json_encode(['ok'=>false, 'msg'=>'Sin permisos']); exit;
}

// Leer inputs
$id        = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$nombre    = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
$categoria = isset($_POST['categoria']) ? trim($_POST['categoria']) : '';
$precio    = isset($_POST['precio']) ? (float)$_POST['precio'] : null;
$cantidad  = isset($_POST['cantidad']) ? (int)$_POST['cantidad'] : null;

if ($id <= 0 || $nombre === '' || $precio === null || $precio < 0 || $cantidad === null || $cantidad < 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'msg'=>'Datos inválidos']); exit;
}

// Actualizar
$stmt = $conn->prepare("UPDATE productos SET nombre = ?, categoria = ?, precio = ?, cantidad = ? WHERE id = ?");
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'msg'=>'Error de preparación']); exit;
}
$stmt->bind_param('ssdii', $nombre, $categoria, $precio, $cantidad, $id);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'msg'=>'No se pudo actualizar']); exit;
}

echo json_encode([
  'ok' => true,
  'producto' => [
    'id' => $id,
    'nombre' => $nombre,
    'categoria' => $categoria,
    'precio' => $precio,
    'cantidad' => $cantidad
  ]
]);
