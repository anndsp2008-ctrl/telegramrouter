(() => {
  const interval = 1500;
  let busy = false;
  const main = () => document.querySelector('.saas-content');
  const shouldPause = () => {
    const active = document.activeElement;
    return !!(active && main()?.contains(active) && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName));
  };
  const replaceMain = (html) => {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const incoming = doc.querySelector('.saas-content');
    const current = main();
    if (incoming && current && incoming.innerHTML !== current.innerHTML) {
      current.innerHTML = incoming.innerHTML;
      window.dispatchEvent(new CustomEvent('painel-atualizado'));
    }
  };
  const sync = async () => {
    if (busy || shouldPause() || document.visibilityState === 'hidden') return;
    busy = true;
    try {
      /* integrations-no-live-poll */ if (document.querySelector('.translation-provider-grid')) return; const response = await fetch(window.location.href, { headers: { 'X-Atualizacao-Assincrona': '1' }, credentials: 'same-origin', cache: 'no-store' });
      if (response.ok) replaceMain(await response.text());
    } catch (_) {
      // A próxima consulta automática tentará novamente; não interromper a interface.
    } finally { busy = false; }
  };
  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.assincrono === 'nao' || form.querySelector('[name="action"]')?.value === 'logout') return;
    event.preventDefault();
    const button = form.querySelector('button[type="submit"], button:not([type])');
    if (button) { button.disabled = true; button.dataset.textoOriginal = button.textContent; button.textContent = 'Salvando…'; }
    try {
      const response = await fetch(form.getAttribute("action") || window.location.href, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Atualizacao-Assincrona': '1' } });
      replaceMain(await response.text());
    } catch (_) {
      if (button) { button.disabled = false; button.textContent = button.dataset.textoOriginal || 'Tentar novamente'; }
    }
  });
  setInterval(sync, interval);
  window.addEventListener('focus', sync);
  window.addEventListener('painel-atualizado', () => { document.querySelectorAll('.saas-flash').forEach((el) => setTimeout(() => el.remove(), 5000)); });
})();
