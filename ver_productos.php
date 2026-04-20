<?php
include 'includes/verificar_sesion.php';
session_start();
include 'includes/conexion.php';

// Sólo admin/usuario pueden ver productos
$rol = $_SESSION['usuario_rol'];
if ($rol === 'limitado') {
    header("Location: dashboard.php");
    exit();
}

// Sin filtros de servidor: cargamos todo el inventario ordenado por nombre
$productos = $conn->query("SELECT * FROM productos ORDER BY nombre ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>📦 Inventario de Productos</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body {
      background:#f0f0f0; color:#222;
      font-family:'Segoe UI',sans-serif;
      margin:0; padding:40px 20px;
      text-align:center;
    }
    .contenedor { max-width:960px; margin:auto; }
    .btn-volver, .btn-pdf {
      display:inline-block; margin-bottom:15px;
      padding:10px 18px; border-radius:8px;
      text-decoration:none; font-weight:bold; color:#fff;
    }
    .btn-volver { background:#6c757d; margin-right:10px; }
    .btn-pdf    { background:#6f42c1; }
    .btn-volver:hover, .btn-pdf:hover { opacity:0.9; }

    table {
      width:100%; border-collapse:collapse;
      margin-top:20px;
    }
    th, td {
      border:1px solid #ccc; padding:10px;
      text-align:center;
    }
    th { background:#eee; }
    .acciones a, .acciones button {
      margin:0 6px; text-decoration:none;
      font-weight:bold; cursor:pointer; border:none; background:transparent;
      color:#007BFF;
    }
    .acciones a:hover, .acciones button:hover { text-decoration:underline; }

    /* 🔎 Buscador en vivo */
    #buscador-productos-live {
      width:100%; max-width:420px; margin:0 auto 14px;
      padding:10px; border-radius:10px; border:1px solid #ccc;
    }
    #tabla-productos .active-row { outline: 2px solid #4c9ffe; background:#f2f7ff; }
    mark._hit { background:#ffeb99; padding:0 2px; border-radius:3px; }

    /* 🧩 Modal edición inline */
    .modal-backdrop {
      position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:center; justify-content:center; z-index:999;
    }
    .modal {
      width:95%; max-width:520px; background:#fff; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.2);
      padding:18px;
      text-align:left;
    }
    .modal h3 { margin:0 0 10px 0; text-align:center; }
    .modal .grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:10px; }
    .modal label { font-weight:600; display:block; margin-bottom:6px; }
    .modal input[type="text"], .modal input[type="number"] {
      width:100%; padding:9px; border-radius:8px; border:1px solid #ccc;
    }
    .modal .acciones {
      display:flex; justify-content:flex-end; gap:10px; margin-top:14px;
    }
    .btn {
      padding:10px 14px; border-radius:8px; font-weight:700; cursor:pointer; border:none;
    }
    .btn-primario { background:#007BFF; color:#fff; }
    .btn-primario:disabled { opacity:.6; cursor:not-allowed; }
    .btn-secundario { background:#6c757d; color:#fff; }
    .msg-ok { color:#1b6e62; font-weight:700; text-align:center; margin-top:6px; }
    .msg-err { color:#b62626; font-weight:700; text-align:center; margin-top:6px; }
  </style>
</head>
<body>
  <div class="contenedor">
    <a href="/tienda_papagayo/dashboard.php" class="btn-volver">🔙 Volver al Dashboard</a>
    <a href="/tienda_papagayo/exportar_pdf.php?tipo=productos" class="btn-pdf">📄 Descargar PDF Productos</a>

    <h2>📦 Inventario de Productos</h2>

    <!-- 🔎 Buscador en vivo -->
    <input id="buscador-productos-live" type="text" placeholder="Buscar en la tabla (en vivo: nombre, categoría, etc.)">

    <?php if ($productos && $productos->num_rows > 0): ?>
      <table id="tabla-productos">
        <tr>
          <th>ID</th>
          <th>Nombre</th>
          <th>Categoría</th>
          <th>Precio</th>
          <th>Cantidad</th>
          <th>Acciones</th>
        </tr>
        <?php while ($row = $productos->fetch_assoc()): ?>
          <tr data-id="<?= (int)$row['id'] ?>">
            <td class="c-id"><?= (int)$row['id'] ?></td>
            <td class="c-nombre"><?= htmlspecialchars($row['nombre']) ?></td>
            <td class="c-categoria"><?= htmlspecialchars($row['categoria']) ?></td>
            <td class="c-precio">$<?= number_format($row['precio'],2) ?></td>
            <td class="c-cantidad"><?= (int)$row['cantidad'] ?></td>
            <td class="acciones">
              <!-- ⬇️ Botón de edición inline (no redirige) -->
              <button type="button" class="btn-editar"
                data-id="<?= (int)$row['id'] ?>"
                data-nombre="<?= htmlspecialchars($row['nombre']) ?>"
                data-categoria="<?= htmlspecialchars($row['categoria']) ?>"
                data-precio="<?= (float)$row['precio'] ?>"
                data-cantidad="<?= (int)$row['cantidad'] ?>"
              >✏️ Editar</button>

              <!-- Mantengo eliminar como lo tenías -->
              <a href="/tienda_papagayo/eliminar_producto.php?id=<?= (int)$row['id'] ?>"
                 onclick="return confirm('¿Estás seguro de eliminar este producto?')">🗑️ Eliminar</a>
            </td>
          </tr>
        <?php endwhile; ?>
      </table>
    <?php else: ?>
      <p>No hay productos registrados.</p>
    <?php endif; ?>
  </div>

  <!-- 🧩 Modal (oculto por defecto) -->
  <div class="modal-backdrop" id="modal-backdrop">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="m-title">
      <h3 id="m-title">Editar producto</h3>
      <input type="hidden" id="m-id">
      <div class="grid">
        <div>
          <label for="m-nombre">Nombre</label>
          <input type="text" id="m-nombre" autocomplete="off">
        </div>
        <div>
          <label for="m-categoria">Categoría</label>
          <input type="text" id="m-categoria" autocomplete="off">
        </div>
        <div>
          <label for="m-precio">Precio</label>
          <input type="number" id="m-precio" min="0" step="0.01">
        </div>
        <div>
          <label for="m-cantidad">Cantidad</label>
          <input type="number" id="m-cantidad" min="0" step="1">
        </div>
      </div>
      <div class="acciones">
        <button type="button" class="btn btn-secundario" id="m-cancelar">Cancelar</button>
        <button type="button" class="btn btn-primario" id="m-guardar">Guardar</button>
      </div>
      <div id="m-msg"></div>
    </div>
  </div>

  <!-- 🧠 JS: buscador en vivo + edición AJAX -->
  <script>
  (function(){
    // === Buscador en vivo (igual que dejamos) ===
    const input = document.getElementById('buscador-productos-live');
    const table = document.getElementById('tabla-productos');
    if (input && table) {
      const norm = s => (s||'').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
      function dataRows(){
        const all = Array.from(table.querySelectorAll('tr'));
        if (table.tBodies && table.tBodies[0]) return Array.from(table.tBodies[0].rows);
        return all.slice(1);
      }
      let currentIndex = -1;
      function clearMarks(el){
        el.querySelectorAll('mark._hit').forEach(m=>{ m.replaceWith(document.createTextNode(m.textContent)); });
      }
      function highlightCell(cell, q){
        clearMarks(cell);
        if (!q) return;
        const text = cell.textContent;
        const ntext = norm(text), nq = norm(q);
        const pos = ntext.indexOf(nq);
        if (pos === -1) return;
        const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT, null);
        let start = 0, foundNode = null, foundOffset = 0;
        while (walker.nextNode()) {
          const t = walker.currentNode, len = t.textContent.length;
          if (start + len >= pos + 1) { foundNode = t; foundOffset = pos - start; break; }
          start += len;
        }
        if (!foundNode) return;
        try {
          const range = document.createRange();
          range.setStart(foundNode, foundOffset);
          range.setEnd(foundNode, foundOffset + q.length);
          const mark = document.createElement('mark');
          mark.className = '_hit';
          range.surroundContents(mark);
        } catch(e){}
      }
      function applyFilter(){
        const q = input.value, nq = norm(q);
        currentIndex = -1;
        dataRows().forEach(tr=>{
          tr.querySelectorAll('td').forEach(td => clearMarks(td));
          const text = norm(tr.textContent);
          const visible = !nq || text.includes(nq);
          tr.style.display = visible ? '' : 'none';
          tr.classList.remove('active-row');
          if (visible && nq) {
            const firstCell = tr.querySelector('.c-nombre') || tr.cells[1] || tr.cells[0];
            if (firstCell) highlightCell(firstCell, q);
          }
        });
      }
      input.addEventListener('input', applyFilter);
      input.addEventListener('keydown', e=>{
        const visibles = dataRows().filter(tr=>tr.style.display!=='none');
        if (!visibles.length) return;
        if (e.key==='ArrowDown'){ e.preventDefault(); currentIndex = (currentIndex+1)%visibles.length; }
        else if (e.key==='ArrowUp'){ e.preventDefault(); currentIndex = (currentIndex-1+visibles.length)%visibles.length; }
        else if (e.key==='Enter' && currentIndex>=0){ e.preventDefault(); visibles[currentIndex].scrollIntoView({block:'center'}); }
        else return;
        visibles.forEach(tr=>tr.classList.remove('active-row'));
        visibles[currentIndex].classList.add('active-row');
      });
      applyFilter();
    }

    // === Editor inline (modal + AJAX) ===
    const modalBg = document.getElementById('modal-backdrop');
    const mId = document.getElementById('m-id');
    const mNombre = document.getElementById('m-nombre');
    const mCategoria = document.getElementById('m-categoria');
    const mPrecio = document.getElementById('m-precio');
    const mCantidad = document.getElementById('m-cantidad');
    const mGuardar = document.getElementById('m-guardar');
    const mCancelar = document.getElementById('m-cancelar');
    const mMsg = document.getElementById('m-msg');

    function openModal(data){
      mId.value = data.id;
      mNombre.value = data.nombre || '';
      mCategoria.value = data.categoria || '';
      mPrecio.value = (data.precio !== undefined && data.precio !== null) ? data.precio : '';
      mCantidad.value = (data.cantidad !== undefined && data.cantidad !== null) ? data.cantidad : '';
      mMsg.className = ''; mMsg.textContent = '';
      modalBg.style.display = 'flex';
      mNombre.focus();
    }
    function closeModal(){
      modalBg.style.display = 'none';
    }
    mCancelar.addEventListener('click', closeModal);
    modalBg.addEventListener('click', (e)=>{ if (e.target === modalBg) closeModal(); });
    document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && modalBg.style.display === 'flex') closeModal(); });

    // Abrir modal al pulsar "✏️ Editar"
    document.addEventListener('click', (e)=>{
      const btn = e.target.closest('.btn-editar');
      if (!btn) return;
      const data = {
        id: parseInt(btn.dataset.id,10),
        nombre: btn.dataset.nombre || '',
        categoria: btn.dataset.categoria || '',
        precio: parseFloat(btn.dataset.precio || '0'),
        cantidad: parseInt(btn.dataset.cantidad || '0', 10)
      };
      openModal(data);
    });

    // Guardar cambios (AJAX)
    mGuardar.addEventListener('click', async ()=>{
      const id = parseInt(mId.value,10);
      const nombre = mNombre.value.trim();
      const categoria = mCategoria.value.trim();
      const precio = parseFloat(mPrecio.value);
      const cantidad = parseInt(mCantidad.value,10);

      if (!id || !nombre || isNaN(precio) || precio < 0 || isNaN(cantidad) || cantidad < 0) {
        mMsg.className = 'msg-err';
        mMsg.textContent = 'Revisa nombre, precio y cantidad.';
        return;
      }

      mGuardar.disabled = true; mGuardar.textContent = 'Guardando…';
      mMsg.className = ''; mMsg.textContent = '';

      try {
        const body = new URLSearchParams();
        body.set('id', id);
        body.set('nombre', nombre);
        body.set('categoria', categoria);
        body.set('precio', precio.toFixed(2));
        body.set('cantidad', String(cantidad));

        const resp = await fetch('ajax_producto_actualizar.php', {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body
        });
        const json = await resp.json();

        if (!json || !json.ok) {
          throw new Error(json && json.msg ? json.msg : 'Error al guardar');
        }

        // Actualiza la fila en la tabla sin recargar
        const tr = document.querySelector(`tr[data-id="${id}"]`);
        if (tr) {
          tr.querySelector('.c-nombre').textContent = nombre;
          tr.querySelector('.c-categoria').textContent = categoria;
          tr.querySelector('.c-precio').textContent = '$' + Number(precio).toFixed(2);
          tr.querySelector('.c-cantidad').textContent = String(cantidad);

          // Actualiza los data-attrs del botón editar
          const btn = tr.querySelector('.btn-editar');
          if (btn) {
            btn.dataset.nombre = nombre;
            btn.dataset.categoria = categoria;
            btn.dataset.precio = precio;
            btn.dataset.cantidad = cantidad;
          }
        }

        mMsg.className = 'msg-ok';
        mMsg.textContent = 'Cambios guardados ✅';
        setTimeout(closeModal, 600);
      } catch (err) {
        mMsg.className = 'msg-err';
        mMsg.textContent = err.message || 'Error inesperado';
      } finally {
        mGuardar.disabled = false; mGuardar.textContent = 'Guardar';
      }
    });
  })();
  </script>
</body>
</html>
