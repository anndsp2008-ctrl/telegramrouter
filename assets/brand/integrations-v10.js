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

  // Keep Save/Test deterministic: one server request, no live DOM replacement.
  document.addEventListener('submit', event => {
    if (!event.target.closest('.translation-provider-grid')) return;
    event.stopImmediatePropagation();
  }, true);
})();