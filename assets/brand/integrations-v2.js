(() => {
  const STORAGE_KEY = 'telegramrouter.integrations.openProvider';

  function providerName(card, provider) {
    const title = card.querySelector('h2,h3,.provider-title');
    if (title && title.textContent.trim()) return title.textContent.trim();
    const labels = {
      azure: 'Microsoft Azure Translator',
      gemini: 'Google Gemini',
      google_cloud: 'Google Cloud Translation'
    };
    return labels[provider] || 'provedor';
  }

  function initIntegrationsAccordion() {
    const grid = document.querySelector('.translation-provider-grid');
    if (!grid) return;

    const cards = Array.from(grid.children).filter(card =>
      card.querySelector('.provider-form input[name="provider"]')
    );
    if (!cards.length) return;

    let remembered = '';
    try {
      remembered = sessionStorage.getItem(STORAGE_KEY) || '';
    } catch (_) {}

    const entries = [];

    const setOpen = (entry, open, persist = true) => {
      const {card, button, provider, name, form} = entry;

      card.classList.toggle('provider-open', open);
      card.classList.toggle('provider-collapsed', !open);

      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      button.setAttribute('aria-label', (open ? 'Recolher ' : 'Expandir ') + name);
      button.innerHTML =
        '<span class="provider-toggle-label">' + (open ? 'Recolher' : 'Expandir') + '</span>' +
        '<span class="provider-chevron" aria-hidden="true">⌄</span>';

      if (form) form.setAttribute('aria-hidden', open ? 'false' : 'true');

      if (persist) {
        try {
          if (open) sessionStorage.setItem(STORAGE_KEY, provider);
          else if ((sessionStorage.getItem(STORAGE_KEY) || '') === provider) {
            sessionStorage.removeItem(STORAGE_KEY);
          }
        } catch (_) {}
      }
    };

    cards.forEach((card, index) => {
      const providerInput = card.querySelector('.provider-form input[name="provider"]');
      const provider = providerInput?.value || ('provider-' + index);
      const name = providerName(card, provider);
      const form = card.querySelector('.provider-form');

      card.classList.add('provider-collapsible');
      card.dataset.provider = provider;

      const header =
        card.querySelector('.provider-head') ||
        card.querySelector('.saas-card-head') ||
        card.firstElementChild;

      let button = card.querySelector(':scope .provider-collapse-toggle');
      if (!button) {
        button = document.createElement('button');
        button.type = 'button';
        button.className = 'provider-collapse-toggle';
        button.dataset.providerToggle = provider;

        if (header) {
          header.appendChild(button);
        } else {
          card.insertBefore(button, card.firstChild);
        }
      }

      if (form) {
        if (!form.id) form.id = 'provider-form-' + provider.replace(/[^a-z0-9_-]/gi, '-');
        button.setAttribute('aria-controls', form.id);
      }

      const entry = {card, button, provider, name, form};
      entries.push(entry);

      if (!button.dataset.accordionBound) {
        button.dataset.accordionBound = '1';
        button.addEventListener('click', () => {
          const currentGrid = button.closest('.translation-provider-grid');
          const currentCard = button.closest('.provider-collapsible');
          if (!currentGrid || !currentCard) return;

          const willOpen = !currentCard.classList.contains('provider-open');

          Array.from(currentGrid.querySelectorAll(':scope > .provider-collapsible')).forEach(otherCard => {
            if (otherCard === currentCard) return;
            const otherButton = otherCard.querySelector(':scope .provider-collapse-toggle');
            const otherForm = otherCard.querySelector('.provider-form');
            const otherProvider = otherCard.dataset.provider || '';
            if (!otherButton) return;
            const otherEntry = {
              card: otherCard,
              button: otherButton,
              provider: otherProvider,
              name: providerName(otherCard, otherProvider),
              form: otherForm
            };
            setOpen(otherEntry, false, false);
          });

          const currentEntry = {
            card: currentCard,
            button,
            provider: currentCard.dataset.provider || provider,
            name: providerName(currentCard, currentCard.dataset.provider || provider),
            form: currentCard.querySelector('.provider-form')
          };
          setOpen(currentEntry, willOpen, true);

          if (willOpen) {
            requestAnimationFrame(() => {
              currentCard.scrollIntoView({behavior:'smooth', block:'nearest'});
            });
          }
        });
      }
    });

    // Default: all collapsed. If the user already opened one during this
    // browsing session, restore only that provider.
    entries.forEach(entry => {
      setOpen(entry, remembered !== '' && entry.provider === remembered, false);
    });

    // Never allow multiple providers to start expanded.
    const openEntries = entries.filter(entry => entry.card.classList.contains('provider-open'));
    openEntries.slice(1).forEach(entry => setOpen(entry, false, false));
  }

  let observerScheduled = false;
  const scheduleInit = () => {
    if (observerScheduled) return;
    observerScheduled = true;
    requestAnimationFrame(() => {
      observerScheduled = false;
      initIntegrationsAccordion();
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      initIntegrationsAccordion();
      new MutationObserver(scheduleInit).observe(document.body, {childList:true, subtree:true});
    }, {once:true});
  } else {
    initIntegrationsAccordion();
    new MutationObserver(scheduleInit).observe(document.body, {childList:true, subtree:true});
  }
})();