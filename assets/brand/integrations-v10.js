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