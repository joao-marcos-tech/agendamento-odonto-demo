(() => {
  'use strict';
  const b = document.getElementById('cancelar');
  const erro = document.getElementById('erro-cancelar');
  if (!b) return;
  let confirmando = false;
  b.addEventListener('click', async () => {
    // Confirmação em dois toques, sem janela do navegador.
    if (!confirmando) { confirmando = true; b.textContent = 'Toque de novo para cancelar'; b.classList.add('alerta'); return; }
    b.disabled = true;
    try {
      const r = await fetch('/api/cancelar.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': b.dataset.csrf },
        body: JSON.stringify({ codigo: b.dataset.codigo, token: b.dataset.token }),
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(j.erro || 'Não foi possível cancelar agora.');
      document.getElementById('situacao').textContent = 'Cancelado';
      document.getElementById('situacao').className = 'selo selo-cancelado';
      document.querySelector('.consulta-acoes').replaceChildren(Object.assign(document.createElement('p'), { textContent: 'Consulta cancelada. O horário já está livre para outra pessoa.' }));
    } catch (e) {
      erro.textContent = e.message; erro.hidden = false; b.disabled = false;
    }
  });
})();
