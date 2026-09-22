(() => {
  const grid = document.querySelector('.translation-provider-grid');
  if (!grid) return;

  const KEY = 'telegramrouter.integrations.openProvider.v10';
  const cards = Array.from(grid.children).filter(card =>
    card.querySelector('.provider-form input[name="provider"]')
  );
  if (!cards.length) return;

  const providerOf = card =>
    card.querySelector('.provider-form input[name="provider"]')?.value || '';

  const setOpen = (card, open, persist = true) => {
    const provider = card.dataset.provider || providerOf(card);
    card.classList.toggle('is-expanded', open);
    card.setAttribute('aria-expanded', open ? 'true' : 'false');

    if (!persist) return;
    try {
      if (open) sessionStorage.setItem(KEY, provider);
      else if (sessionStorage.getItem(KEY) === provider) sessionStorage.removeItem(KEY);
    } catch (_) {}
  };

  const toggle = card => {
    const open = !card.classList.contains('is-expanded');
    if (open) {
      cards.forEach(other => {
        if (other !== card) setOpen(other, false, false);
      });
    }
    setOpen(card, open, true);
  };

  let remembered = '';
  try { remembered = sessionStorage.getItem(KEY) || ''; } catch (_) {}

  cards.forEach(card => {
    card.querySelectorAll('.provider-actions button').forEach(button => {
      const label = (button.textContent || '').trim().toLowerCase();
      if (label.includes('testar conexão') || label.includes('testar conexao')) {
        button.classList.add('provider-test-action');
      }
    });
  });

  // Suppress only actual page-level connection-test notices. A previous
  // whole-document text search also matched parent containers (including
  // .saas-shell) and applied display:none!important to the entire sidebar.
  // Never hide an ancestor, a navigation element, or a provider card.
  const testFeedbackRx = /(?:conex[aã]o realizada com sucesso|falha ao testar conex[aã]o)/i;
  document.querySelectorAll('.saas-flash, .toast, .tmr-toast, [role="alert"]')
    .forEach(notice => {
      if (notice.closest('.translation-provider-grid, .saas-sidebar, .saas-nav')) return;
      if (notice.matches('.saas-shell, .saas-main, .saas-content, .saas-sidebar, .saas-nav')) return;
      if (!testFeedbackRx.test((notice.textContent || '').trim())) return;
      notice.classList.add('integrations-global-test-feedback');
      notice.setAttribute('hidden', '');
      notice.setAttribute('aria-hidden', 'true');
    });

  cards.forEach(card => {
    const provider = providerOf(card);
    card.dataset.provider = provider;
    card.tabIndex = 0;
    card.setAttribute('role', 'button');

    const test = card.querySelector(':scope > .provider-test');
    if (test) {
      let sibling = test.nextElementSibling;
      while (sibling) {
        if (!sibling.classList.contains('provider-form')) {
          sibling.classList.add('provider-telemetry-v10');
          sibling.setAttribute('aria-label', 'Telemetria do último teste');
        }
        sibling = sibling.nextElementSibling;
      }
    }

    setOpen(card, remembered !== '' && remembered === provider, false);

    card.addEventListener('click', event => {
      if (event.target.closest('input,select,textarea,button,a,label,[contenteditable="true"]')) return;
      toggle(card);
    });

    card.addEventListener('keydown', event => {
      if (event.target !== card) return;
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      toggle(card);
    });
  });

  const escapeHtml = value => String(value)
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');

  const markTelemetry = card => {
    const test = card.querySelector(':scope > .provider-test');
    if (!test) return;

    let sibling = test.nextElementSibling;
    while (sibling) {
      if (!sibling.classList.contains('provider-form')) {
        sibling.classList.add('provider-telemetry-v10');
        sibling.setAttribute('aria-label', 'Telemetria do último teste');
      }
      sibling = sibling.nextElementSibling;
    }
  };

  cards.forEach(markTelemetry);

  async function runConnectionTest(form, submitter, card, provider) {
    if (!submitter || submitter.dataset.testing === '1') return;

    submitter.dataset.testing = '1';
    const originalLabel = submitter.textContent || 'Testar conexão';
    submitter.classList.add('is-testing-connection');
    submitter.setAttribute('aria-busy', 'true');
    submitter.disabled = true;
    submitter.textContent = 'Testando conexão…';

    card.classList.add('is-testing-provider');
    setOpen(card, true, true);

    let testBox = card.querySelector(':scope > .provider-test');
    const startedAt = performance.now();

    if (testBox) {
      testBox.classList.remove('ok','bad','neutral');
      testBox.classList.add('testing');
      testBox.innerHTML =
        '<b>Teste de conexão</b>' +
        '<span>Testando conexão…</span>' +
        '<small>Aguardando resposta</small>';
    }

    try {
      let data;
      try {
        data = new FormData(form, submitter);
      } catch (_) {
        data = new FormData(form);
        const name = submitter.getAttribute('name') || 'action';
        const value = submitter.value || 'test_translation_provider';
        data.delete(name);
        data.append(name, value);
      }

      const submitterName = submitter.getAttribute('name') || 'action';
      const submitterValue = submitter.value || 'test_translation_provider';
      if (!data.has(submitterName)) data.append(submitterName, submitterValue);

      const actionUrl = form.getAttribute('action') || window.location.href;
      const response = await fetch(actionUrl, {
        method: (form.getAttribute('method') || 'POST').toUpperCase(),
        body: data,
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'follow',
        headers: { 'Accept': 'text/html' }
      });

      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }

      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const responseGrid = doc.querySelector('.translation-provider-grid');

      if (!responseGrid) {
        throw new Error('Resposta do teste sem o módulo de integrações.');
      }

      const responseCard = Array.from(responseGrid.children).find(item =>
        (item.querySelector('.provider-form input[name="provider"]')?.value || '') === provider
      );

      if (!responseCard) {
        throw new Error('Resposta do provedor não encontrada.');
      }

      const responseTest = responseCard.querySelector(':scope > .provider-test') ||
                           responseCard.querySelector('.provider-test');

      if (!responseTest) {
        throw new Error('Resultado do teste não encontrado.');
      }

      const responseTelemetry = [];
      let sibling = responseTest.nextElementSibling;
      while (sibling) {
        if (
          !sibling.classList.contains('provider-form') &&
          sibling.tagName !== 'SCRIPT' &&
          sibling.tagName !== 'STYLE'
        ) {
          responseTelemetry.push(sibling.cloneNode(true));
        }
        sibling = sibling.nextElementSibling;
      }

      card.querySelectorAll(':scope > .provider-telemetry-v10').forEach(el => el.remove());

      const currentTest = card.querySelector(':scope > .provider-test');
      const newTest = responseTest.cloneNode(true);
      if (currentTest) currentTest.replaceWith(newTest);
      else card.appendChild(newTest);

      responseTelemetry.forEach(el => {
        el.classList.add('provider-telemetry-v10');
        el.setAttribute('aria-label', 'Telemetria do último teste');
      });

      if (responseTelemetry.length) {
        newTest.after(...responseTelemetry);
      }

      // Keep result and telemetry inside the tested provider card.
      setOpen(card, true, true);
      markTelemetry(card);
    } catch (error) {
      const elapsed = Math.max(0, Math.round(performance.now() - startedAt));
      const when = new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'medium'
      }).format(new Date());

      testBox = card.querySelector(':scope > .provider-test');
      if (testBox) {
        testBox.className = 'provider-test bad';
        testBox.innerHTML =
          '<b>Último teste</b>' +
          '<span>Falha ao testar conexão</span>' +
          '<small>' + escapeHtml(when) + ' · ' + elapsed + ' ms</small>' +
          '<em>' + escapeHtml(error?.message || 'Falha inesperada no teste.') + '</em>';
      }
      setOpen(card, true, true);
    } finally {
      submitter.disabled = false;
      submitter.dataset.testing = '0';
      submitter.classList.remove('is-testing-connection');
      submitter.removeAttribute('aria-busy');
      submitter.textContent = originalLabel;
      card.classList.remove('is-testing-provider');
    }
  }

  // Test Connection is fully local to the provider card. No page navigation,
  // no black screen, and no live.js DOM replacement.
  window.addEventListener('submit', event => {
    const form = event.target.closest?.('.translation-provider-grid .provider-form');
    if (!form) return;

    const card = form.closest('.translation-provider-grid > *');
    const provider = card ? (card.dataset.provider || providerOf(card)) : '';
    const submitter = event.submitter;
    const action = submitter?.value || '';

    if (provider) {
      try { sessionStorage.setItem(KEY, provider); } catch (_) {}
    }

    if (action === 'test_translation_provider') {
      event.preventDefault();
      event.stopImmediatePropagation();
      runConnectionTest(form, submitter, card, provider);
      return;
    }

    // Saving may use the normal POST, but live.js must not intercept it.
    event.stopPropagation();
  }, true);

})();
