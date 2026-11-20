// Vídeo do topo: só toca se a pessoa não pediu menos movimento e não está economizando dados.
(() => {
  'use strict';
  const v = document.querySelector('.hero-video');
  const btn = document.querySelector('.video-controle');
  if (!v || !btn) return;
  const calmo = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const economia = navigator.connection && (navigator.connection.saveData || /2g/.test(navigator.connection.effectiveType || ''));
  btn.hidden = false;

  function tocar() {
    v.preload = 'auto';
    const p = v.play();
    if (p) p.then(() => document.body.classList.add('video-on')).catch(() => btn.setAttribute('aria-pressed', 'true'));
    btn.setAttribute('aria-pressed', 'false');
  }
  function pausar() { v.pause(); btn.setAttribute('aria-pressed', 'true'); }

  if (calmo || economia) { btn.setAttribute('aria-pressed', 'true'); }
  else { tocar(); }

  btn.addEventListener('click', () => (v.paused ? tocar() : pausar()));

  // Fora da tela, pausa para economizar bateria.
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(([e]) => {
      if (btn.getAttribute('aria-pressed') === 'true') return;
      if (e.isIntersecting) v.play().catch(() => {}); else v.pause();
    }).observe(v);
  }
})();
