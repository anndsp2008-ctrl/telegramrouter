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

  // Remove provider-test feedback rendered outside the provider grid,
  // regardless of the notification component/class used by the base app.
  const testFeedbackRx = /(conex[aã]o realizada com sucesso|falha ao testar conex[aã]o)/i;
  const allOutside = Array.from(document.body.querySelectorAll('*')).filter(el =>
    !el.closest('.translation-provider-grid') &&
    testFeedbackRx.test((el.textContent || '').trim())
  );

  // Work from deepest nodes upward and hide the highest contiguous wrapper
  // containing only that same feedback text. This removes both banner + toast
  // without touching the surrounding page layout.
  allOutside
    .sort((a,b) => {
      const depth = el => {
        let n=0,p=el;
        while(p && p.parentElement){ n++; p=p.parentElement; }
        return n;
      };
      return depth(b)-depth(a);
    })
    .forEach(el => {
      if (el.closest('.integrations-global-test-feedback')) return;

      let target = el;
      const normalized = (el.textContent || '').replace(/\s+/g,' ').trim();
      while (
        target.parentElement &&
        target.parentElement !== document.body &&
        !target.parentElement.closest('.translation-provider-grid') &&
        (target.parentElement.textContent || '').replace(/\s+/g,' ').trim() === normalized
      ) {
        target = target.parentElement;
      }

      target.classList.add('integrations-global-test-feedback');
      target.setAttribute('hidden','');
      target.setAttribute('aria-hidden','true');
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

  // Provider forms must bypass live.js completely. Capture on window runs
  // before document/form listeners, but does not cancel the browser's normal POST.
  window.addEventListener('submit', event => {
    const form = event.target.closest?.('.translation-provider-grid .provider-form');
    if (!form) return;

    const card = form.closest('.translation-provider-grid > *');
    const provider = card?.dataset.provider || providerOf(card);
    const submitter = event.submitter;
    const action = submitter?.value || '';

    try {
      if (provider) sessionStorage.setItem(KEY, provider);
      sessionStorage.setItem('telegramrouter.integrations.pendingAction', action);
      sessionStorage.setItem('telegramrouter.integrations.pendingProvider', provider || '');
    } catch (_) {}

    if (submitter && action === 'test_translation_provider') {
      submitter.classList.add('is-testing-connection');
      submitter.setAttribute('aria-busy', 'true');
      submitter.textContent = 'Testando conexão…';
    } else if (submitter && action === 'save_translation_provider') {
      submitter.classList.add('is-saving-provider');
      submitter.setAttribute('aria-busy', 'true');
      submitter.textContent = 'Salvando…';
    }

    // Prevent live.js from intercepting/replacing the page. Do not preventDefault:
    // the native form submit must continue so the clicked button name/value is sent.
    event.stopPropagation();
  }, true);

  // After a test POST reloads the page, keep the tested provider open and let the
  // provider-local result be the feedback instead of a duplicate global flash.
  try {
    const pendingAction = sessionStorage.getItem('telegramrouter.integrations.pendingAction') || '';
    const pendingProvider = sessionStorage.getItem('telegramrouter.integrations.pendingProvider') || '';

    if (pendingAction && pendingProvider) {
      const card = cards.find(item => item.dataset.provider === pendingProvider);
      if (card) setOpen(card, true, false);

      if (pendingAction === 'test_translation_provider' && card) {
        const test = card.querySelector(':scope > .provider-test');
        const hasFreshResult = test && !test.classList.contains('neutral') &&
          !/Ainda não testado/i.test(test.textContent || '');

        if (hasFreshResult) {
          document.querySelectorAll('.saas-content > .saas-flash').forEach(flash => {
            flash.hidden = true;
          });
        }
      }

      sessionStorage.removeItem('telegramrouter.integrations.pendingAction');
      sessionStorage.removeItem('telegramrouter.integrations.pendingProvider');
    }
  } catch (_) {}
})();