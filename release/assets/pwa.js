(() => {
  'use strict';

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
  }

  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (standalone) {
    document.documentElement.classList.add('pwa-standalone');
    return;
  }

  let deferredPrompt = null;
  const isiOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  let button = null;

  function ensureButton() {
    if (button || document.getElementById('tmr-pwa-install')) return button;
    button = document.createElement('button');
    button.id = 'tmr-pwa-install';
    button.type = 'button';
    button.setAttribute('aria-label', 'Instalar Telegram Media Router');
    button.innerHTML = '<span aria-hidden="true">↓</span><span>Instalar app</span>';
    Object.assign(button.style, {
      position:'fixed', right:'16px', bottom:'16px', zIndex:'2147483646',
      display:'none', alignItems:'center', gap:'8px',
      border:'1px solid rgba(120,255,210,.25)', borderRadius:'999px',
      padding:'11px 15px', background:'#162c2f', color:'#f4faf9',
      font:'600 14px/1.1 system-ui,-apple-system,Segoe UI,sans-serif',
      boxShadow:'0 12px 32px rgba(0,0,0,.28)', cursor:'pointer'
    });
    button.addEventListener('click', async () => {
      if (deferredPrompt) {
        const prompt = deferredPrompt;
        deferredPrompt = null;
        button.style.display='none';
        await prompt.prompt();
        try { await prompt.userChoice; } catch (_) {}
        return;
      }
      if (isiOS) {
        showIOSHelp();
      }
    });
    document.body.appendChild(button);
    return button;
  }

  function showIOSHelp() {
    let modal = document.getElementById('tmr-pwa-ios-help');
    if (modal) { modal.style.display='flex'; return; }
    modal = document.createElement('div');
    modal.id='tmr-pwa-ios-help';
    Object.assign(modal.style,{
      position:'fixed', inset:'0', zIndex:'2147483647', display:'flex',
      alignItems:'flex-end', justifyContent:'center', padding:'18px',
      background:'rgba(0,0,0,.58)', backdropFilter:'blur(6px)'
    });
    modal.innerHTML = `
      <div style="width:min(460px,100%);background:#102023;color:#f4faf9;border:1px solid rgba(120,255,210,.18);border-radius:22px;padding:20px;box-shadow:0 22px 60px rgba(0,0,0,.42);font-family:system-ui,-apple-system,Segoe UI,sans-serif">
        <div style="font-size:18px;font-weight:750;margin-bottom:8px">Instalar no iPhone/iPad</div>
        <div style="font-size:14px;line-height:1.5;color:#c8d9d7">
          No Safari, toque em <b>Compartilhar</b> e depois em <b>Adicionar à Tela de Início</b>. Confirme em <b>Adicionar</b>.
        </div>
        <button type="button" id="tmr-pwa-ios-close" style="width:100%;margin-top:16px;border:0;border-radius:14px;padding:12px;background:#244a4e;color:#fff;font-weight:700">Entendi</button>
      </div>`;
    document.body.appendChild(modal);
    modal.addEventListener('click', e => { if (e.target === modal) modal.style.display='none'; });
    modal.querySelector('#tmr-pwa-ios-close').addEventListener('click', () => modal.style.display='none');
  }

  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    deferredPrompt = event;
    const btn = ensureButton();
    btn.style.display='flex';
  });

  window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    if (button) button.remove();
  });

  window.addEventListener('DOMContentLoaded', () => {
    if (isiOS) {
      const btn = ensureButton();
      btn.style.display='flex';
    }
  });
})();