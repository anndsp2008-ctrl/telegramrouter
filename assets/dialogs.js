(() => {
  const ICONS = {
    primary: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M3 12h18"/></svg>',
    danger: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v5m0 4h.01"/><path d="M10.3 4.5 2.7 18a2 2 0 0 0 1.8 3h15a2 2 0 0 0 1.8-3L13.7 4.5a2 2 0 0 0-3.4 0Z"/></svg>',
    warning: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4m0 4h.01"/><circle cx="12" cy="12" r="9"/></svg>',
    info: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 11v6m0-10h.01"/><circle cx="12" cy="12" r="9"/></svg>'
  };

  let activeOverlay = null;

  const closeActive = (value = false) => {
    if (!activeOverlay || typeof activeOverlay.__tmrClose !== 'function') return;
    activeOverlay.__tmrClose(value);
  };

  const show = (options = {}) => new Promise(resolve => {
    closeActive(false);

    const tone = ['danger', 'warning', 'info', 'primary'].includes(options.tone) ? options.tone : 'primary';
    const title = String(options.title || 'Confirmar ação');
    const message = String(options.message || '');
    const detail = String(options.detail || '');
    const confirmText = String(options.confirmText || 'Confirmar');
    const cancelText = String(options.cancelText || 'Cancelar');
    const alertOnly = !!options.alertOnly;
    const dismissible = options.dismissible !== false;

    const overlay = document.createElement('div');
    overlay.className = 'tmr-dialog-overlay';
    overlay.setAttribute('role', 'presentation');

    const box = document.createElement('section');
    box.className = 'tmr-dialog';
    box.dataset.tone = tone;
    box.setAttribute('role', alertOnly ? 'alertdialog' : 'dialog');
    box.setAttribute('aria-modal', 'true');

    const titleId = 'tmr-dialog-title-' + Math.random().toString(36).slice(2);
    const messageId = 'tmr-dialog-message-' + Math.random().toString(36).slice(2);
    box.setAttribute('aria-labelledby', titleId);
    if (message) box.setAttribute('aria-describedby', messageId);

    box.innerHTML =
      '<div class="tmr-dialog-head">' +
        '<div class="tmr-dialog-icon">' + (ICONS[tone] || ICONS.primary) + '</div>' +
        '<div class="tmr-dialog-copy"><span class="tmr-dialog-kicker">' + (alertOnly ? 'INFORMAÇÃO' : 'CONFIRMAÇÃO') + '</span><h2 class="tmr-dialog-title" id="' + titleId + '"></h2></div>' +
        (dismissible ? '<button type="button" class="tmr-dialog-close" aria-label="Fechar">×</button>' : '') +
      '</div>' +
      '<div class="tmr-dialog-body">' +
        (message ? '<p class="tmr-dialog-message" id="' + messageId + '"></p>' : '') +
        (detail ? '<div class="tmr-dialog-detail"></div>' : '') +
        (!alertOnly && tone === 'danger' ? '<div class="tmr-dialog-note"><svg viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01"/><circle cx="12" cy="12" r="9"/></svg><span>Revise a ação antes de continuar.</span></div>' : '') +
      '</div>' +
      '<div class="tmr-dialog-actions' + (alertOnly ? ' is-single' : '') + '">' +
        (!alertOnly ? '<button type="button" class="tmr-dialog-cancel"></button>' : '') +
        '<button type="button" class="tmr-dialog-confirm"></button>' +
      '</div>';

    box.querySelector('.tmr-dialog-title').textContent = title;
    const messageEl = box.querySelector('.tmr-dialog-message');
    if (messageEl) messageEl.textContent = message;
    const detailEl = box.querySelector('.tmr-dialog-detail');
    if (detailEl) detailEl.textContent = detail;

    const confirmButton = box.querySelector('.tmr-dialog-confirm');
    confirmButton.textContent = confirmText;

    const cancelButton = box.querySelector('.tmr-dialog-cancel');
    if (cancelButton) cancelButton.textContent = cancelText;

    const previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    let closed = false;

    const close = value => {
      if (closed) return;
      closed = true;
      document.removeEventListener('keydown', onKeydown, true);
      overlay.remove();
      document.body.classList.remove('tmr-dialog-lock');
      if (activeOverlay === overlay) activeOverlay = null;
      if (previousFocus && document.contains(previousFocus)) previousFocus.focus({ preventScroll: true });
      resolve(value);
    };

    const onKeydown = event => {
      if (event.key === 'Escape' && dismissible) {
        event.preventDefault();
        close(false);
        return;
      }
      if (event.key !== 'Tab') return;
      const focusables = [...box.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),a[href]')];
      if (!focusables.length) return;
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    overlay.__tmrClose = close;
    activeOverlay = overlay;
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    document.body.classList.add('tmr-dialog-lock');
    document.addEventListener('keydown', onKeydown, true);

    if (dismissible) {
      overlay.addEventListener('click', event => {
        if (event.target === overlay) close(false);
      });
      box.querySelector('.tmr-dialog-close')?.addEventListener('click', () => close(false));
    }
    cancelButton?.addEventListener('click', () => close(false));
    confirmButton.addEventListener('click', () => close(true));

    requestAnimationFrame(() => (alertOnly ? confirmButton : (cancelButton || confirmButton)).focus());
  });

  const confirm = options => show({ ...options, alertOnly: false });
  const alert = options => show({ ...options, alertOnly: true, cancelText: '', confirmText: options?.confirmText || 'Entendi' });

  window.tmrDialog = { show, confirm, alert, close: closeActive };

  const formDefaults = form => {
    if (form.classList.contains('disconnect-form')) {
      return {
        tone: 'danger',
        title: 'Desconectar conta Telegram?',
        message: 'A sessão será encerrada e o trabalhador deixará de processar novas mensagens até uma nova conexão.',
        detail: 'As regras permanecem salvas; apenas a sessão do Telegram será desconectada.',
        confirmText: 'Desconectar'
      };
    }
    if (form.classList.contains('routing-delete')) {
      return {
        tone: 'danger',
        title: 'Excluir esta regra?',
        message: 'Esta regra deixará de encaminhar novas mensagens imediatamente.',
        detail: 'O histórico já registrado não será apagado.',
        confirmText: 'Excluir regra'
      };
    }
    if (form.classList.contains('reporting-review-resolve-form')) {
      const selected = form.querySelector('[name="manual_status"]');
      const label = selected instanceof HTMLSelectElement ? selected.options[selected.selectedIndex]?.textContent?.trim() : '';
      return {
        tone: 'warning',
        title: 'Confirmar resultado manual?',
        message: 'O resultado escolhido será aplicado a esta seleção e os cálculos do bilhete serão atualizados.',
        detail: label ? 'Resultado selecionado: ' + label : 'Confira o resultado selecionado antes de continuar.',
        confirmText: 'Aplicar resultado'
      };
    }
    return {};
  };

  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    const needsDialog =
      form.hasAttribute('data-dialog-confirm')
      || form.classList.contains('disconnect-form')
      || form.classList.contains('routing-delete')
      || form.classList.contains('reporting-review-resolve-form');

    if (!needsDialog) return;

    if (form.dataset.dialogApproved === '1') {
      delete form.dataset.dialogApproved;
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    const defaults = formDefaults(form);
    const accepted = await confirm({
      tone: form.dataset.dialogTone || defaults.tone || 'primary',
      title: form.dataset.dialogTitle || defaults.title || 'Confirmar ação?',
      message: form.dataset.dialogMessage || defaults.message || 'Deseja continuar com esta ação?',
      detail: form.dataset.dialogDetail || defaults.detail || '',
      confirmText: form.dataset.dialogConfirmText || defaults.confirmText || 'Confirmar',
      cancelText: form.dataset.dialogCancelText || 'Cancelar'
    });

    if (!accepted) return;

    form.dataset.dialogApproved = '1';
    const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
    if (typeof form.requestSubmit === 'function') {
      if (submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement) form.requestSubmit(submitter);
      else form.requestSubmit();
    } else {
      form.submit();
    }
  }, true);
})();
