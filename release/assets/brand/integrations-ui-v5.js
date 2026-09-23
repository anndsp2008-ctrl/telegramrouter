(() => {
  const STORAGE_KEY = 'telegramrouter.integrations.openProvider.v5';
  const grid = document.querySelector('.translation-provider-grid');
  if (!grid) return;

  const cards = Array.from(grid.children).filter(card =>
    card.querySelector('.provider-form input[name="provider"]')
  );
  if (!cards.length) return;

  function providerOf(card) {
    return card.querySelector('.provider-form input[name="provider"]')?.value || '';
  }

  function setOpen(card, open, persist = true) {
    const provider = card.dataset.provider || providerOf(card);
    card.classList.toggle('is-expanded', open);
    card.setAttribute('aria-expanded', open ? 'true' : 'false');

    if (!persist) return;
    try {
      if (open) sessionStorage.setItem(STORAGE_KEY, provider);
      else if (sessionStorage.getItem(STORAGE_KEY) === provider) sessionStorage.removeItem(STORAGE_KEY);
    } catch (_) {}
  }

  function closeOthers(current) {
    cards.forEach(card => {
      if (card !== current) setOpen(card, false, false);
    });
  }

  function toggle(card) {
    const willOpen = !card.classList.contains('is-expanded');
    if (willOpen) closeOthers(card);
    setOpen(card, willOpen, true);
  }

  let remembered = '';
  try { remembered = sessionStorage.getItem(STORAGE_KEY) || ''; } catch (_) {}

  cards.forEach(card => {
    const provider = providerOf(card);
    card.dataset.provider = provider;
    card.tabIndex = 0;
    card.setAttribute('role', 'group');

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

  // Prevent live.js from hijacking provider forms. These forms submit normally,
  // so the page reloads once after Save/Test and the accordion starts cleanly.
  document.addEventListener('submit', event => {
    if (!event.target.closest('.translation-provider-grid')) return;
    event.stopImmediatePropagation();
  }, true);
})();