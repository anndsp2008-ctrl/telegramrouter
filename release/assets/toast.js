(() => {
  const style = document.createElement('style');
  style.textContent = `.tmr-toasts{position:fixed;left:22px;top:22px;right:auto;bottom:auto;z-index:9999;display:grid;gap:10px;width:min(390px,calc(100vw - 30px));pointer-events:none}.tmr-toast{pointer-events:auto;display:flex;align-items:flex-start;gap:11px;padding:14px 16px;border:1px solid #ffffff22;border-radius:12px;background:#12242b;color:#eafffc;box-shadow:0 16px 45px #0008;font:600 13px/1.45 system-ui,sans-serif;animation:tmr-toast-in .25s ease}.tmr-toast.success{border-color:#58e2ae66}.tmr-toast.error{border-color:#ff778566}.tmr-toast .tmr-icon{font-size:17px;line-height:1}.tmr-toast button{margin-left:auto;border:0;background:transparent;color:#a9c4c7;font-size:18px;line-height:1;cursor:pointer}@keyframes tmr-toast-in{from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:none}}@media(max-width:600px){.tmr-toasts{left:15px;top:15px;right:auto;bottom:auto}}`;
  document.head.appendChild(style);
  const container = document.createElement('div'); container.className = 'tmr-toasts'; document.body.appendChild(container);
  const show = (message, type = 'success') => {
    if (!message || !message.trim()) return;
    const toast = document.createElement('div'); toast.className = `tmr-toast ${type}`;
    toast.innerHTML = `<span class="tmr-icon">${type === 'success' ? '✓' : '!'}</span><span></span><button type="button" aria-label="Fechar">×</button>`;
    toast.querySelector('span:nth-child(2)').textContent = message.trim();
    toast.querySelector('button').addEventListener('click', () => toast.remove());
    container.appendChild(toast);
    window.setTimeout(() => toast.remove(), 5500);
  };
  window.tmrToast = show;
  document.querySelectorAll('.saas-flash.success,.flash.success,.alert.success').forEach(el => { show(el.textContent, 'success'); el.setAttribute('data-toast-shown','1'); });
  document.querySelectorAll('.saas-flash.error,.flash.error,.alert.error').forEach(el => { show(el.textContent, 'error'); el.setAttribute('data-toast-shown','1'); });
})();
