(function () {
  var btn = document.getElementById('btn-tema');
  if (!btn) return;

  var ICONOS = { claro: '☀️', oscuro: '🌙' };
  var LABELS = { claro: 'Cambiar a tema oscuro', oscuro: 'Cambiar a tema claro' };

  function temaEfectivoActual() {
    var explicito = document.documentElement.getAttribute('data-theme');
    if (explicito === 'claro' || explicito === 'oscuro') return explicito;
    // 'auto': resolver contra el SO, sin escribir el atributo todavía.
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
      ? 'oscuro' : 'claro';
  }

  function pintarBoton(tema) {
    btn.querySelector('span').textContent = ICONOS[tema];
    btn.setAttribute('aria-label', LABELS[tema]);
  }

  pintarBoton(temaEfectivoActual());

  btn.addEventListener('click', function () {
    var actual = temaEfectivoActual();
    var nuevo = actual === 'oscuro' ? 'claro' : 'oscuro';

    document.documentElement.setAttribute('data-theme', nuevo);
    pintarBoton(nuevo);

    fetch('guardar_tema.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ tema: nuevo, csrf_token: btn.dataset.csrf })
    }); // optimista: si falla, no se revierte la UI
  });
})();
