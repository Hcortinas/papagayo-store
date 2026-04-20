<?php
date_default_timezone_set('America/Mexico_City');

// Configuración
$usuario = 'museopap_usertienda';
$contrasena = 'Papagayo2024!';
$base_datos = 'museopap_tienda_papagayo';
$host = 'localhost';
$limite_respaldo = 52;

$fecha = date('Y-m-d_H-i');
$nombre_archivo = "respaldo_{$base_datos}_{$fecha}.sql";
$directorio = __DIR__ . "/respaldos/";
$path_archivo = $directorio . $nombre_archivo;

// Crear carpeta si no existe
if (!is_dir($directorio)) {
    mkdir($directorio, 0755, true);
}

// Conexión a la base de datos
$conn = new mysqli($host, $usuario, $contrasena, $base_datos);
if ($conn->connect_error) {
    die('❌ Error al conectar: ' . $conn->connect_error);
}
$conn->set_charset("utf8");

// Obtener todas las tablas
$tablas = [];
$res = $conn->query("SHOW TABLES");
while ($row = $res->fetch_array()) {
    $tablas[] = $row[0];
}

// Inicia el contenido del respaldo
$salida = "-- Respaldo de la base de datos: $base_datos\n";
$salida .= "-- Fecha de respaldo: " . date('Y-m-d H:i:s') . "\n\n";
$salida .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tablas as $tabla) {
    // Estructura de la tabla
    $res = $conn->query("SHOW CREATE TABLE `$tabla`");
    $row = $res->fetch_row();
    $salida .= "-- ----------------------------\n";
    $salida .= "-- Estructura de la tabla `$tabla`\n";
    $salida .= "-- ----------------------------\n";
    $salida .= "DROP TABLE IF EXISTS `$tabla`;\n";
    $salida .= $row[1] . ";\n\n";
    
    // Datos de la tabla
    $res = $conn->query("SELECT * FROM `$tabla`");
    if ($res->num_rows > 0) {
        $salida .= "-- ----------------------------\n";
        $salida .= "-- Datos de la tabla `$tabla`\n";
        $salida .= "-- ----------------------------\n";
        while ($fila = $res->fetch_assoc()) {
            $valores = array_map(function($v) use ($conn) {
                if (is_null($v)) return 'NULL';
                return "'" . $conn->real_escape_string($v) . "'";
            }, array_values($fila));
            $salida .= "INSERT INTO `$tabla` VALUES (" . implode(',', $valores) . ");\n";
        }
        $salida .= "\n";
    }
}

$salida .= "SET FOREIGN_KEY_CHECKS=1;\n";

// Escribir a archivo
if (file_put_contents($path_archivo, $salida) !== false) {
    echo "✅ Respaldo creado: $nombre_archivo<br>";
} else {
    echo "❌ Error al guardar el archivo de respaldo.<br>";
    exit;
}

// Limitar a los últimos 52 respaldos
$archivos = glob($directorio . "respaldo_{$base_datos}_*.sql");
usort($archivos, function($a, $b) {
    return filemtime($a) - filemtime($b);
});
if (count($archivos) > $limite_respaldo) {
    $a_eliminar = array_slice($archivos, 0, count($archivos) - $limite_respaldo);
    foreach ($a_eliminar as $archivo) {
        unlink($archivo);
        echo "🗑️ Respaldo eliminado: " . basename($archivo) . "<br>";
    }
}
?>

