<?php
include('includes/verificar_sesion.php');
include('includes/conexion.php');
date_default_timezone_set('America/Mexico_City');

$mensaje = "";

/* ===== Helper: crear/reusar cliente por nombre (auto-alta) =====
   - Si existe columna `activo`, lo marca en 1 al crear.
   - Usa ON DUPLICATE KEY para reusar el id existente cuando nombre es UNIQUE.
*/
function upsertClientePorNombre(mysqli $conn, string $nombre): int {
  $nombre = trim($nombre);
  if ($nombre === '') return 0;

  // Detectar una vez si existe columna `activo`
  static $tieneActivo = null;
  if ($tieneActivo === null) {
    $chk = $conn->query("SHOW COLUMNS FROM clientes LIKE 'activo'");
    $tieneActivo = $chk && $chk->num_rows > 0;
  }

  if ($tieneActivo) {
    // Crear con activo=1; si ya existe, reusar id
    $st = $conn->prepare("
      INSERT INTO clientes (nombre, activo) VALUES (?, 1)
      ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
    ");
    $st->bind_param("s", $nombre);
  } else {
    // Sin columna activo
    $st = $conn->prepare("
      INSERT INTO clientes (nombre) VALUES (?)
      ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
    ");
    $st->bind_param("s", $nombre);
  }
  $st->execute();
  return (int)$conn->insert_id; // id nuevo o existente
}

// === Cargar productos (para el autocompletado)
$productos_rs = $conn->query("SELECT id, nombre, precio, cantidad FROM productos ORDER BY nombre ASC");
$PRODUCTS = [];
if ($productos_rs) {
  while ($p = $productos_rs->fetch_assoc()) {
    $PRODUCTS[] = [
      'id'       => (int)$p['id'],
      'nombre'   => $p['nombre'],
      'precio'   => (float)$p['precio'],
      'cantidad' => (int)$p['cantidad']
    ];
  }
}

// === Cargar clientes (lista unificada)
$clientes_rs = $conn->query("SELECT id, nombre FROM clientes WHERE activo = 1 ORDER BY nombre ASC");
$CLIENTES = [];
if ($clientes_rs) {
  while ($c = $clientes_rs->fetch_assoc()) {
    $CLIENTES[] = ['id' => (int)$c['id'], 'nombre' => $c['nombre']];
  }
}

// === Procesar formulario (con transacción y stock protegido)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $cliente_id = isset($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : 0;
    $empleado_nombre = isset($_POST['empleado']) ? trim($_POST['empleado']) : '';
    $registrado_por = (int)$_SESSION['usuario_id'];

    // ✅ Alta automática si no viene id pero sí nombre tecleado
    if ($cliente_id <= 0 && $empleado_nombre !== '') {
        $cliente_id = upsertClientePorNombre($conn, $empleado_nombre);
    }

    if ($cliente_id <= 0 && $empleado_nombre === '') {
        $mensaje = "❌ Debes seleccionar o escribir un nombre de cliente.";
    } else {
        $producto_ids = isset($_POST['producto_id']) ? $_POST['producto_id'] : [];
        $cantidades   = isset($_POST['cantidad']) ? $_POST['cantidad'] : [];

        $conn->begin_transaction();
        try {
            foreach ($producto_ids as $idx => $pidRaw) {
                $pid = (int)$pidRaw;
                $cantidad = isset($cantidades[$idx]) ? (int)$cantidades[$idx] : 0;
                if ($pid <= 0 || $cantidad <= 0) throw new Exception("Fila de producto inválida.");

                // 1) Lee precio/stock y bloquea fila
                $q = $conn->prepare("SELECT precio, cantidad FROM productos WHERE id = ? FOR UPDATE");
                $q->bind_param("i", $pid);
                $q->execute();
                $r = $q->get_result();
                if (!$r || $r->num_rows === 0) throw new Exception("Producto inexistente (#$pid)");
                $prod = $r->fetch_assoc();
                $precio = (float)$prod['precio'];

                // 2) Descuenta stock solo si alcanza
                $upd = $conn->prepare("UPDATE productos SET cantidad = cantidad - ? WHERE id = ? AND cantidad >= ?");
                $upd->bind_param("iii", $cantidad, $pid, $cantidad);
                $upd->execute();
                if ($upd->affected_rows !== 1) throw new Exception("Stock insuficiente para producto #$pid");

                // 3) Inserta la deuda con saldo_detalle inicial
                $monto = $precio * $cantidad;
                $ins = $conn->prepare("
                  INSERT INTO deudas (cliente_id, empleado, producto_id, cantidad, monto, estado, fecha, registrado_por, saldo_detalle)
                  VALUES (?, ?, ?, ?, ?, 'pendiente', NOW(), ?, ?)
                ");
                $saldo_inicial = $monto;
                $ins->bind_param("isiiidi", $cliente_id, $empleado_nombre, $pid, $cantidad, $monto, $registrado_por, $saldo_inicial);
                $ins->execute();
                if ($ins->affected_rows !== 1) throw new Exception("No se pudo registrar la deuda.");
            }

            $conn->commit();
            header('Location: ver_deudas.php?mensaje=deuda_ok');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $mensaje = "❌ Error al registrar la deuda: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>🧾 Registrar Deuda</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    /* ✅ Evita que los inputs se desborden de la celda */
    *, *::before, *::after { box-sizing: border-box; }

    body { background:#f0f0f0; font-family:'Segoe UI',sans-serif; padding:40px 20px; text-align:center; }
    .contenedor { max-width:760px; margin:auto; background:white; border-radius:12px; box-shadow:0 0 10px #bbb; padding:30px; }
    h2 { margin-bottom:20px; text-align:center; }
    .mensaje { font-weight:bold; color:green; margin-bottom:10px; }
    .error { color:red; }
    .btn-volver { display:inline-block; margin-bottom:20px; background:#6c757d; color:white; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:bold; }

    table { width:100%; border-collapse:collapse; margin-bottom:20px; background:#fff; table-layout: fixed; } /* ✅ columnas estables */
    th,td { border:1px solid #ccc; padding:10px; text-align:center; vertical-align: middle; }
    th { background:#eee; }
    tfoot td { font-weight:700; background:#fafafa; }
    .subtotal { white-space: nowrap; } /* ✅ evita saltos de línea en $0.00 */

    .btn-agregar, .btn-quitar { padding:5px 14px; border:none; border-radius:6px; font-weight:bold; cursor:pointer; margin:2px; } /* ✅ espacio entre botones */
    .btn-agregar { background:#28a745; color:white; }
    .btn-quitar  { background:#dc3545; color:white; }

    input[type="text"], input[type="number"] { padding:10px; border-radius:8px; border:1px solid #ccc; margin:3px 0; width:100%; }
    /* ✅ la cantidad no se “estira” de más */
    .cantidad { max-width:110px; margin-left:auto; margin-right:auto; display:block; }

    input[type="submit"] { background:#007BFF; color:white; border:none; padding:12px 24px; border-radius:8px; font-weight:bold; cursor:pointer; }
    input[type="submit"]:hover { background:#0056b3; }

    /* Autocomplete (clientes y productos) */
    .autocomplete-wrapper { position: relative; width: 100%; margin: 0 auto; }
    .ac-list {
      position:absolute; top:calc(100% + 6px); left:0; right:0; background:#fff; border:1px solid #ccc;
      border-radius:10px; max-height:300px; overflow:auto; z-index:20; text-align:left; box-shadow:0 10px 20px rgba(0,0,0,0.08);
      padding:6px;
    }
    .ac-item { padding:10px 12px; border-radius:8px; cursor:pointer; display:flex; justify-content:space-between; gap:12px; align-items:center; font-size:14px; }
    .ac-item:hover, .ac-item.active { background:#f2f7ff; }
    .ac-name { font-weight:600; overflow:hidden; text-overflow:ellipsis; }
    .ac-meta { font-size:12px; color:#666; white-space:nowrap; }
    .ac-item.disabled { opacity:.55; cursor:not-allowed; }
    .pill { font-size:11px; padding:2px 6px; border-radius:999px; border:1px solid #ddd; color:#666; }
    .pill.warn { border-color:#ffe1b3; color:#b26b00; background:#fff7e6 }
    .pill.ok   { border-color:#c9efe9; color:#1b6e62; background:#e9fbf8 }
    .pill.zero { border-color:#f8c4c4; color:#8a0d0d; background:#fdecec }
    .helper { font-size:12px; color:#666; margin-top:6px }
    .total-right { text-align:right; padding-right:16px; }
  </style>
</head>
<body>
  <div class="contenedor">
    <a href="dashboard.php" class="btn-volver">🔙 Volver al Dashboard</a>
    <h2>🧾 Registrar Deuda</h2>
    <?php if ($mensaje): ?>
      <div class="mensaje"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <form method="POST" id="form-deuda" autocomplete="off">
      <!-- Cliente -->
      <label>Empleado / Cliente:</label>
      <div class="autocomplete-wrapper" style="max-width: 520px;">
        <!-- Conservamos name="empleado" (compatibilidad) y usamos hidden para id -->
        <input type="text" name="empleado" id="input-cliente" placeholder="Escribe para buscar o crear cliente…" required>
        <div id="lista-clientes" class="ac-list" style="display:none"></div>
      </div>
      <input type="hidden" name="cliente_id" id="cliente_id">
      <div class="helper" style="max-width:520px;margin:6px auto 16px;">Tip: usa ↑/↓ para moverte y Enter para seleccionar.</div>

      <!-- Productos -->
      <table id="tabla-productos">
        <thead>
          <tr>
            <th style="width:50%;">Producto</th>
            <th style="width:15%;">Cantidad</th>
            <th style="width:20%;">Subtotal</th>
            <th style="width:15%;">Acción</th>
          </tr>
        </thead>
        <tbody>
          <tr class="row-item" data-subtotal="0">
            <td>
              <div class="autocomplete-wrapper">
                <input type="text" class="input-producto" placeholder="Escribe para buscar producto… (p. ej. 'co' → coca cola)">
                <div class="ac-list" style="display:none"></div>
              </div>
              <input type="hidden" name="producto_id[]" class="producto_id">
            </td>
            <td>
              <input type="number" name="cantidad[]" class="cantidad" min="1" value="1" required>
            </td>
            <td class="subtotal">$0.00</td>
            <td>
              <button type="button" class="btn-agregar" onclick="agregarFila()">＋</button>
              <button type="button" class="btn-quitar" onclick="quitarFila(this)">−</button>
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2" class="total-right">Total</td>
            <td id="grandTotal" style="font-weight:700;">$0.00</td>
            <td></td>
          </tr>
        </tfoot>
      </table>

      <input type="submit" value="Registrar Deuda(s)">
    </form>
    <div class="helper">Puedes añadir varias filas; cada buscador es independiente. El total es informativo.</div>
  </div>

<script>
  // === Datos desde PHP ===
  const PRODUCTS = <?= json_encode($PRODUCTS, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
  const CLIENTES = <?= json_encode($CLIENTES, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;

  // === Utilidades ===
  const normalize = (str) => (str || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
  const money = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 2 });
  const getProductById = (id) => PRODUCTS.find(p => p.id === id);

  // === Autocompletado de CLIENTES ===
  (function initClientes(){
    const input = document.getElementById('input-cliente');
    const list  = document.getElementById('lista-clientes');
    const hidden= document.getElementById('cliente_id');
    let currentIndex = -1, currentItems = [];

    function render(items){
      list.innerHTML = '';
      currentItems = items;
      currentIndex = -1;
      if (!items.length){ list.style.display='none'; return; }
      items.forEach((c,idx)=>{
        const div = document.createElement('div');
        div.className = 'ac-item';
        div.textContent = c.nombre;
        div.dataset.index = idx;
        div.addEventListener('mousedown', (e)=>{
          e.preventDefault();
          input.value = c.nombre;
          hidden.value = c.id;
          list.style.display='none';
        });
        list.appendChild(div);
      });
      list.style.display = 'block';
    }
    function filter(q){
      if (!q) return CLIENTES.slice(0, 200);
      const nq = normalize(q);
      return CLIENTES.filter(c => normalize(c.nombre).includes(nq));
    }

    input.addEventListener('input', ()=>{ hidden.value=''; render(filter(input.value)); });
    input.addEventListener('focus', ()=>{ render(filter(input.value)); });
    input.addEventListener('blur', ()=> setTimeout(()=> list.style.display='none', 120));
    input.addEventListener('keydown', (e)=>{
      if (list.style.display==='none') return;
      const items = Array.from(list.children);
      if (!items.length) return;
      if (e.key==='ArrowDown'){ e.preventDefault(); currentIndex=(currentIndex+1)%items.length; items.forEach(x=>x.classList.remove('active')); items[currentIndex].classList.add('active'); }
      else if (e.key==='ArrowUp'){ e.preventDefault(); currentIndex=(currentIndex-1+items.length)%items.length; items.forEach(x=>x.classList.remove('active')); items[currentIndex].classList.add('active'); }
      else if (e.key==='Enter' && currentIndex>=0){ e.preventDefault(); items[currentIndex].dispatchEvent(new Event('mousedown')); }
      else if (e.key==='Escape'){ list.style.display='none'; }
    });
  })();

  // === Cálculo de subtotales y total ===
  function updateRowSubtotal(row){
    const pid = parseInt(row.querySelector('.producto_id').value || '0', 10);
    const qty = parseInt(row.querySelector('.cantidad').value || '0', 10);
    const p = getProductById(pid);
    let subtotal = 0;
    if (p && qty > 0) subtotal = p.precio * qty;
    row.querySelector('.subtotal').textContent = money.format(subtotal);
    row.dataset.subtotal = subtotal.toFixed(2);
    updateGrandTotal();
  }

  function updateGrandTotal(){
    const rows = Array.from(document.querySelectorAll('.row-item'));
    let total = 0;
    for (const r of rows) total += parseFloat(r.dataset.subtotal || '0');
    document.getElementById('grandTotal').textContent = money.format(total);
  }

  // === Autocompletado de PRODUCTOS (por fila) ===
  function attachProductAutocomplete(row){
    const input = row.querySelector('.input-producto');
    const list  = row.querySelector('.ac-list');
    const hidden= row.querySelector('.producto_id');
    const qtyEl = row.querySelector('.cantidad');
    let currentIndex = -1, currentItems = [];

    function render(items){
      list.innerHTML = '';
      currentItems = items;
      currentIndex = -1;
      if (!items.length){ list.style.display='none'; return; }

      items.forEach((p,idx)=>{
        const div = document.createElement('div');
        div.className = 'ac-item' + (p.cantidad <= 0 ? ' disabled' : '');
        div.dataset.index = idx;

        const left = document.createElement('div');
        left.className = 'ac-name';
        left.textContent = p.nombre;

        const right = document.createElement('div');
        right.className = 'ac-meta';
        const pill = document.createElement('span');
        pill.className = 'pill ' + (p.cantidad <= 0 ? 'zero' : (p.cantidad <= 5 ? 'warn' : 'ok'));
        pill.textContent = (p.cantidad <= 0) ? 'Sin stock' :
                           (p.cantidad <= 5) ? `Stock bajo: ${p.cantidad}` :
                           `${p.cantidad} disp.`;
        right.textContent = ` ${money.format(p.precio)} `;
        right.appendChild(pill);

        div.appendChild(left);
        div.appendChild(right);

        div.addEventListener('mousedown', (e)=>{
          e.preventDefault();
          if (p.cantidad <= 0) return; // no seleccionable sin stock
          input.value = p.nombre;
          hidden.value = p.id;
          list.style.display='none';
          if (!qtyEl.value || parseInt(qtyEl.value,10) <= 0) qtyEl.value = 1;
          updateRowSubtotal(row);
          qtyEl.focus();
          qtyEl.select();
        });

        list.appendChild(div);
      });

      list.style.display = 'block';
    }

    function filterProducts(query){
      const q = normalize(query);
      let arr = PRODUCTS.slice();
      if (q) arr = arr.filter(p => normalize(p.nombre).includes(q));
      // ordenar: coincidencia + stock
      arr.sort((a,b)=>{
        const an = normalize(a.nombre), bn = normalize(b.nombre);
        const ai = an.indexOf(q), bi = bn.indexOf(q);
        if (q && ai !== bi) return ai - bi;
        if ((a.cantidad>0) !== (b.cantidad>0)) return (b.cantidad>0) - (a.cantidad>0);
        return an.localeCompare(bn);
      });
      return arr;
    }

    input.addEventListener('input', ()=>{
      hidden.value=''; // invalida selección previa
      render(filterProducts(input.value));
      if (!input.value.trim()) { row.dataset.subtotal = '0'; row.querySelector('.subtotal').textContent = money.format(0); updateGrandTotal(); }
    });
    input.addEventListener('focus', ()=>{ render(filterProducts(input.value)); });
    input.addEventListener('blur', ()=> setTimeout(()=> list.style.display='none', 120));
    input.addEventListener('keydown', (e)=>{
      if (list.style.display==='none') return;
      const items = Array.from(list.children);
      if (!items.length) return;
      if (e.key==='ArrowDown'){ e.preventDefault(); currentIndex=(currentIndex+1)%items.length; items.forEach(x=>x.classList.remove('active')); items[currentIndex].classList.add('active'); }
      else if (e.key==='ArrowUp'){ e.preventDefault(); currentIndex=(currentIndex-1+items.length)%items.length; items.forEach(x=>x.classList.remove('active')); items[currentIndex].classList.add('active'); }
      else if (e.key==='Enter' && currentIndex>=0){
        e.preventDefault();
        if (!items[currentIndex].classList.contains('disabled')) items[currentIndex].dispatchEvent(new Event('mousedown'));
      }
      else if (e.key==='Escape'){ list.style.display='none'; }
    });

    // Recalcular al cambiar cantidad
    qtyEl.addEventListener('input', ()=> updateRowSubtotal(row));
  }

  // Inicializar la primera fila
  document.addEventListener('DOMContentLoaded', ()=>{
    const firstRow = document.querySelector('.row-item');
    attachProductAutocomplete(firstRow);
    updateGrandTotal();
  });

  // Añadir/Quitar filas
  function agregarFila(){
    const tbody = document.querySelector('#tabla-productos tbody');
    const base  = tbody.querySelector('.row-item');
    const nueva = base.cloneNode(true);

    // limpiar valores
    nueva.dataset.subtotal = '0';
    nueva.querySelector('.subtotal').textContent = money.format(0);
    nueva.querySelectorAll('input').forEach(el=>{
      if (el.classList.contains('producto_id')) el.value = '';
      else if (el.classList.contains('input-producto')) el.value = '';
      else if (el.classList.contains('cantidad')) el.value = '1';
    });
    // limpiar lista de sugerencias
    const list = nueva.querySelector('.ac-list');
    if (list){ list.innerHTML=''; list.style.display='none'; }

    tbody.appendChild(nueva);
    attachProductAutocomplete(nueva);
    updateGrandTotal();
  }

  function quitarFila(btn){
    const tbody = document.querySelector('#tabla-productos tbody');
    if (tbody.querySelectorAll('.row-item').length > 1) {
      const row = btn.closest('.row-item');
      row.remove();
      updateGrandTotal();
    }
  }

  // Validación antes de enviar (cada fila debe tener producto_id válido)
  document.getElementById('form-deuda').addEventListener('submit', (e)=>{
    const rows = Array.from(document.querySelectorAll('.row-item'));
    for (const row of rows) {
      const pid = parseInt(row.querySelector('.producto_id').value || '0', 10);
      const qty = parseInt(row.querySelector('.cantidad').value || '0', 10);
      if (qty > 0 && (!pid || !PRODUCTS.some(p => p.id === pid))) {
        e.preventDefault();
        alert('En cada fila, selecciona un producto de la lista antes de continuar.');
        row.querySelector('.input-producto').focus();
        return false;
      }
    }
  });
</script>
</body>
</html>
