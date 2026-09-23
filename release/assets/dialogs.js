(() => {
  const makeDialog = (title, message, actionText, actionClass = 'dialog-primary') => new Promise(resolve => {
    const overlay = document.createElement('div');
    overlay.className = 'dialog-overlay';
    overlay.innerHTML = `<section class="dialog-box" role="dialog" aria-modal="true" aria-labelledby="dialog-title"><button class="dialog-close" type="button" aria-label="Fechar">×</button><div class="dialog-mark ${actionClass}">!</div><h2 id="dialog-title">${title}</h2><p>${message}</p><div class="dialog-actions"><button type="button" class="dialog-cancel">Cancelar</button><button type="button" class="${actionClass}">${actionText}</button></div></section>`;
    const close = result => { overlay.remove(); document.body.classList.remove('dialog-open'); resolve(result); };
    overlay.addEventListener('click', e => { if (e.target === overlay) close(false); });
    overlay.querySelector('.dialog-close').onclick = () => close(false);
    overlay.querySelector('.dialog-cancel').onclick = () => close(false);
    overlay.querySelector(`.dialog-actions button.${actionClass}`).onclick = () => close(true);
    document.body.appendChild(overlay); document.body.classList.add('dialog-open');
    overlay.querySelector(`.dialog-actions button.${actionClass}`).focus();
  });
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.classList.contains('disconnect-form')) return;
    event.preventDefault();
    if (await makeDialog('Desconectar conta Telegram?', 'A sessão local será removida e o trabalhador deixará de processar novas mensagens até uma nova conexão.', 'Desconectar', 'dialog-danger')) form.submit();
  });
})();
