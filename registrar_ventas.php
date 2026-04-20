<?php
include('includes/verificar_sesion.php');
include('includes/conexion.php');

$mensaje = "";
$rol = $_SESSION['usuario_rol'];
$usuario_id = $_SESSION['usuario_id'];

// Procesar venta si se envió el formulario
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['producto_id'], $_POST['cantidad'])) {
    $producto_id = (int) $_POST['producto_id'];
    $cantidad = (int) $_POST['cantidad'];

    // Validaciones básicas
    if ($producto_id > 0 && $cantidad > 0) {
        $verificar = $conn->prepare("SELECT cantidad FROM productos WHERE id = ?");
        $verificar->bind_param("i", $producto_id);
        $verificar->execute();
        $resultado = $verificar->get_result();

        if ($resultado->num_rows === 1) {
            $producto = $resultado->fetch_assoc();

            if ((int)$producto['cantidad'] >= $cantidad) {
                $conn->begin_transaction();

                try {
                    $stmt = $conn->prepare("INSERT INTO ventas (producto_id, cantidad, fecha, usuario_id) VALUES (?, ?, NOW(), ?)");
                    $stmt->bind_param("iii", $producto_id, $cantidad, $usuario_id);
                    $stmt->execute();

                    $stmt2 = $conn->prepare("UPDATE productos SET cantidad = cantidad - ? WHERE id = ?");
                    $stmt2->bind_param("ii", $cantidad, $producto_id);
                    $stmt2->execute();

                    $conn->commit();
                    header("Location: registrar_ventas.php?mensaje=ok");
                    exit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $mensaje = "❌ Error al registrar la venta.";
                }
            } else {
                $mensaje = "❌ Stock insuficiente.";
            }
        } else {
            $mensaje = "❌ Producto no encontrado.";
        }
    } else {
        $mensaje = "❌ Datos inválidos.";
    }
}

// Si viene por GET después de una redirección exitosa
if (isset($_GET['mensaje']) && $_GET['mensaje'] === 'ok') {
    $mensaje = "✅ Venta registrada exitosamente.";
}

// Obtener productos para el autocompletado
$productos_rs = $conn->query("SELECT id, nombre, cantidad FROM productos ORDER BY nombre ASC");
$productos = [];
if ($productos_rs) {
    while ($row = $productos_rs->fetch_assoc()) {
        // Normalizamos datos mínimos necesarios para el front
        $productos[] = [
            'id'       => (int)$row['id'],
            'nombre'   => $row['nombre'],
            'cantidad' => (int)$row['cantidad']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Registrar Venta</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{
      --bg:#f0f0f0; --card:#fff; --primary:#007BFF; --primary-600:#0056b3;
      --muted:#6c757d; --border:#ddd; --text:#111; --text-muted:#666;
      --danger:#d90429; --success:#2a9d8f; --warning:#f4a261;
    }
    *{box-sizing:border-box}
    body {
      background-color: var(--bg);
      font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
      margin: 0;
      padding: 40px 20px;
      color: var(--text);
    }
    .formulario {
      max-width: 560px;
      margin: auto;
      background-color: var(--card);
      padding: 28px;
      border-radius: 14px;
      box-shadow: 0 10px 24px rgba(0,0,0,0.08);
    }
    h2 { margin: 0 0 18px 0; font-weight: 700; text-align: center; }
    .btn-volver {
      display: inline-block; margin-bottom: 20px; background-color: var(--muted);
      color: white; padding: 10px 18px; border-radius: 10px; text-decoration: none; font-weight: 600;
    }
    label { display:block; font-weight:600; margin:16px 0 8px; text-align: center; }
    input[type="number"], input[type="text"] {
      width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid var(--border); outline: none;
      transition: border-color .2s, box-shadow .2s; font-size: 15px;
    }
    input[type="number"]:focus, input[type="text"]:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,123,255,0.15) }
    input[type="submit"] {
      margin-top: 14px; width: 100%; background-color: var(--primary); color: white; border: none;
      padding: 12px 18px; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 16px;
    }
    input[type="submit"]:hover { background-color: var(--primary-600); }
    .mensaje {
      margin-bottom: 15px; font-weight: 700; color: var(--success);
      background: #e6f6f4; border:1px solid #c3ede7; padding:10px 12px; border-radius:10px;
    }
    .mensaje.error { color: var(--danger); background:#fdecec; border-color:#f8c4c4; }
    /* Autocomplete */
    .autocomplete-wrapper { position: relative; }
    .helper { font-size:12px; color:var(--text-muted); margin-top:6px }
    .ac-list {
      position: absolute; z-index: 20; top: calc(100% + 6px); left: 0; right: 0;
      background: #fff; border: 1px solid var(--border); border-radius: 10px;
      max-height: 260px; overflow: auto; box-shadow: 0 10px 20px rgba(0,0,0,0.08);
      padding: 6px;
    }
    .ac-item {
      padding: 10px 12px; border-radius: 8px; cursor: pointer; display:flex; justify-content:space-between; gap:12px;
      align-items: center; font-size: 14px;
    }
    .ac-item:hover, .ac-item.active { background: #f2f7ff; }
    .ac-name { font-weight: 600; }
    .ac-stock { font-size: 12px; color: var(--text-muted); }
    .ac-item.disabled { opacity: .55; cursor: not-allowed; }
    .pill { font-size:11px; padding:2px 6px; border-radius:999px; border:1px solid var(--border); color:var(--text-muted) }
    .pill.warn { border-color:#ffe1b3; color:#b26b00; background:#fff7e6 }
    .pill.ok { border-color:#c9efe9; color:#1b6e62; background:#e9fbf8 }
    .pill.zero { border-color:#f8c4c4; color:#8a0d0d; background:#fdecec }
  </style>
</head>
<body>
  <div class="formulario">
    <a href="dashboard.php" class="btn-volver">🔙 Volver al Panel de Control</a>
    <h2>💵 Registrar Venta</h2>

    <?php if ($mensaje): ?>
      <div class="<?= strpos($mensaje, '✅') !== false ? 'mensaje' : 'mensaje error' ?>">
        <?= htmlspecialchars($mensaje) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="registrar_ventas.php" id="form-venta" autocomplete="off">
      <label for="input-producto">Producto</label>
      <div class="autocomplete-wrapper">
        <input type="text" id="input-producto" placeholder="Escribe para buscar… (p. ej. 'co' → coca cola)">
        <div id="lista-sugerencias" class="ac-list" style="display:none"></div>
      </div>
      <div class="helper">Tip: usa ↑/↓ para moverte por la lista y Enter para seleccionar.</div>

      <!-- Campo real que se envía al backend -->
      <input type="hidden" name="producto_id" id="producto_id" required>

      <label for="cantidad">Cantidad</label>
      <input type="number" name="cantidad" id="cantidad" min="1" value="1" required>

      <input type="submit" value="Registrar Venta">
    </form>
  </div>

  <script>
    // --- Datos desde PHP ---
    const PRODUCTS = <?=
      json_encode($productos, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
    ?>;

    // --- Utilidades ---
    const normalize = (str) => (str || '')
      .toString()
      .normalize('NFD').replace(/[\u0300-\u036f]/g,'') // quita acentos
      .toLowerCase().trim();

    // --- Elementos DOM ---
    const input = document.getElementById('input-producto');
    const list  = document.getElementById('lista-sugerencias');
    const hiddenId = document.getElementById('producto_id');
    const cantidadEl = document.getElementById('cantidad');
    const form = document.getElementById('form-venta');

    let currentIndex = -1;       // índice seleccionado con teclado
    let currentItems = [];        // lista actual filtrada

    // Renders de la lista
    function renderList(items) {
      list.innerHTML = '';
      currentIndex = -1;
      currentItems = items;

      if (!items.length) {
        list.style.display = 'none';
        return;
      }

      items.forEach((p, idx) => {
        const div = document.createElement('div');
        div.className = 'ac-item' + (p.cantidad <= 0 ? ' disabled' : '');
        div.dataset.index = idx;

        const left = document.createElement('div');
        left.className = 'ac-name';
        left.textContent = p.nombre;

        const right = document.createElement('div');
        right.className = 'ac-stock';

        const pill = document.createElement('span');
        pill.className = 'pill ' + (p.cantidad <= 0 ? 'zero' : (p.cantidad <= 5 ? 'warn' : 'ok'));
        pill.textContent = (p.cantidad <= 0) ? 'Sin stock' :
                           (p.cantidad <= 5) ? `Stock bajo: ${p.cantidad}` :
                           `${p.cantidad} disp.`;

        right.appendChild(pill);
        div.appendChild(left);
        div.appendChild(right);

        div.addEventListener('mousedown', (e) => { // mousedown para evitar blur antes del click
          e.preventDefault();
          if (p.cantidad <= 0) return; // no seleccionable sin stock
          pickItem(idx);
        });

        list.appendChild(div);
      });

      list.style.display = 'block';
    }

    function pickItem(idx) {
      const p = currentItems[idx];
      if (!p) return;
      input.value = p.nombre;
      hiddenId.value = p.id;
      list.style.display = 'none';
      cantidadEl.focus();
      cantidadEl.select();
    }

    function moveActive(delta) {
      if (!currentItems.length) return;
      const items = Array.from(list.children);

      // buscar próximo índice válido (que no sea disabled)
      let next = currentIndex;
      do {
        next = (next + delta + items.length) % items.length;
      } while (items[next].classList.contains('disabled') && next !== currentIndex);

      if (items[next].classList.contains('disabled')) return; // todos están disabled

      if (currentIndex >= 0) items[currentIndex].classList.remove('active');
      currentIndex = next;
      items[currentIndex].classList.add('active');

      // scroll a la vista
      const el = items[currentIndex];
      const top = el.offsetTop;
      const bottom = top + el.offsetHeight;
      if (top < list.scrollTop) list.scrollTop = top;
      else if (bottom > list.scrollTop + list.clientHeight) list.scrollTop = bottom - list.clientHeight;
    }

    function filterProducts(query) {
      const q = normalize(query);
      if (!q) {
        // mostrar top N con stock primero
        const withStock = PRODUCTS.filter(p => p.cantidad > 0);
        const noStock   = PRODUCTS.filter(p => p.cantidad <= 0);
        return [...withStock, ...noStock];
      }
      const results = PRODUCTS.filter(p => normalize(p.nombre).includes(q));
      // ordenar: coincidencia más cercana + stock
      results.sort((a,b) => {
        const aName = normalize(a.nombre), bName = normalize(b.nombre);
        const aIdx = aName.indexOf(q), bIdx = bName.indexOf(q);
        if (aIdx !== bIdx) return aIdx - bIdx;
        if ((a.cantidad>0) !== (b.cantidad>0)) return (b.cantidad>0) - (a.cantidad>0);
        return aName.localeCompare(bName);
      });
      return results;
    }

    // Eventos
    input.addEventListener('input', () => {
      hiddenId.value = ''; // al escribir de nuevo, invalidamos la selección previa
      renderList(filterProducts(input.value));
    });

    input.addEventListener('focus', () => {
      renderList(filterProducts(input.value));
    });

    input.addEventListener('blur', () => {
      // ocultar lista después de un tick para permitir click
      setTimeout(() => { list.style.display = 'none'; }, 100);
    });

    input.addEventListener('keydown', (e) => {
      if (list.style.display === 'none') return;
      if (e.key === 'ArrowDown') { e.preventDefault(); moveActive(+1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); moveActive(-1); }
      else if (e.key === 'Enter') {
        if (currentIndex >= 0 && currentItems[currentIndex] && currentItems[currentIndex].cantidad > 0) {
          e.preventDefault();
          pickItem(currentIndex);
        }
      } else if (e.key === 'Escape') {
        list.style.display = 'none';
      }
    });

    // Validación antes de enviar: asegurar que el texto corresponde a un producto válido
    form.addEventListener('submit', (e) => {
      const id = parseInt(hiddenId.value, 10);
      if (!id || !PRODUCTS.some(p => p.id === id)) {
        e.preventDefault();
        alert('Por favor, selecciona un producto de la lista.');
        input.focus();
        input.select();
      }
    });
  </script>
</body>
</html>


