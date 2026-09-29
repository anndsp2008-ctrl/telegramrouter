(() => {
  const isReportingPage = () => !!document.querySelector('.reporting-page');
  const interval = isReportingPage() ? 15000 : 1500;
  const reportingInteractionGraceMs = 7000;

  let busy = false;
  let reportingInteractionUntil = 0;

  const main = () => document.querySelector('.saas-content');
  const reportingHistoryWrap = () => document.querySelector('#reporting-history .saas-table-wrap');

  const markReportingInteraction = () => {
    if (!isReportingPage()) return;
    reportingInteractionUntil = Date.now() + reportingInteractionGraceMs;
  };

  const shouldPause = () => {
    const active = document.activeElement;
    const reportsDirty = document.querySelector('#reporting-settings-form[data-dirty="1"]');
    const reportingInteractionActive = isReportingPage() && Date.now() < reportingInteractionUntil;

    return !!reportsDirty
      || reportingInteractionActive
      || !!(active && main()?.contains(active) && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName));
  };

  const captureReportingState = () => {
    if (!isReportingPage()) return null;

    const wrap = reportingHistoryWrap();
    const expandedTargets = [...document.querySelectorAll('.reporting-history-toggle[aria-expanded="true"]')]
      .map((button) => button instanceof HTMLElement ? (button.dataset.historyTarget || '') : '')
      .filter(Boolean);

    return {
      pageX: window.scrollX,
      pageY: window.scrollY,
      historyLeft: wrap instanceof HTMLElement ? wrap.scrollLeft : 0,
      historyTop: wrap instanceof HTMLElement ? wrap.scrollTop : 0,
      expandedTargets,
    };
  };

  const restoreReportingState = (state) => {
    if (!state) return;

    const restore = () => {
      const wrap = reportingHistoryWrap();
      if (wrap instanceof HTMLElement) {
        wrap.scrollLeft = state.historyLeft;
        wrap.scrollTop = state.historyTop;
      }

      for (const rowId of state.expandedTargets) {
        const detailRow = document.getElementById(rowId);
        const toggle = [...document.querySelectorAll('.reporting-history-toggle')]
          .find((button) => button instanceof HTMLButtonElement && button.dataset.historyTarget === rowId);

        if (!(detailRow instanceof HTMLTableRowElement) || !(toggle instanceof HTMLButtonElement)) continue;

        toggle.setAttribute('aria-expanded', 'true');
        detailRow.hidden = false;
        toggle.closest('tr')?.classList.add('is-expanded');

        const label = toggle.querySelector('span');
        if (label) label.textContent = 'Ocultar jogos';
      }

      window.scrollTo(state.pageX, state.pageY);
    };

    requestAnimationFrame(restore);
  };

  const replaceMain = (html, force = false) => {
    if (!force && document.querySelector('#reporting-settings-form[data-dirty="1"]')) return;

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const incoming = doc.querySelector('.saas-content');
    const current = main();

    if (incoming && current && incoming.innerHTML !== current.innerHTML) {
      const reportingState = captureReportingState();
      current.innerHTML = incoming.innerHTML;
      window.dispatchEvent(new CustomEvent('painel-atualizado'));
      restoreReportingState(reportingState);
    }
  };

  const sync = async () => {
    if (busy || shouldPause() || document.visibilityState === 'hidden') return;

    busy = true;
    try {
      /* integrations-no-live-poll */
      if (document.querySelector('.translation-provider-grid')) return;

      const response = await fetch(window.location.href, {
        headers: { 'X-Atualizacao-Assincrona': '1' },
        credentials: 'same-origin',
        cache: 'no-store',
      });

      if (response.ok) {
        const html = await response.text();

        // A interação pode ter começado enquanto a requisição estava em andamento.
        // Nesse caso, não substitua a tabela sob o dedo do usuário.
        if (!shouldPause()) replaceMain(html);
      }
    } catch (_) {
      // A próxima consulta automática tentará novamente; não interromper a interface.
    } finally {
      busy = false;
    }
  };

  const historyInteraction = (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    if (target.closest('#reporting-history .saas-table-wrap, .reporting-history-toggle')) {
      markReportingInteraction();
    }
  };

  for (const eventName of ['pointerdown', 'pointermove', 'touchstart', 'touchmove', 'wheel']) {
    document.addEventListener(eventName, historyInteraction, { passive: true });
  }

  document.addEventListener('scroll', (event) => {
    const target = event.target;
    if (target instanceof Element && target.matches('#reporting-history .saas-table-wrap')) {
      markReportingInteraction();
    }
  }, true);

  document.addEventListener('click', historyInteraction);

  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)
      || form.dataset.assincrono === 'nao'
      || form.querySelector('[name="action"]')?.value === 'logout') return;

    event.preventDefault();

    const button = form.querySelector('button[type="submit"], button:not([type])');
    if (button) {
      button.disabled = true;
      button.dataset.textoOriginal = button.textContent;
      button.textContent = 'Salvando…';
    }

    try {
      const response = await fetch(form.getAttribute('action') || window.location.href, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Atualizacao-Assincrona': '1' },
      });
      replaceMain(await response.text(), true);
    } catch (_) {
      if (button) {
        button.disabled = false;
        button.textContent = button.dataset.textoOriginal || 'Tentar novamente';
      }
    }
  });

  setInterval(sync, interval);
  window.addEventListener('focus', sync);
  window.addEventListener('painel-atualizado', () => {
    document.querySelectorAll('.saas-flash').forEach((el) => setTimeout(() => el.remove(), 5000));
  });
})();
