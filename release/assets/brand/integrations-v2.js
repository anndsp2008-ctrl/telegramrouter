(() => {
  const STORAGE_KEY = 'telegramrouter.integrations.openProvider.v3';

  const PROVIDERS = {
    azure: {
      mark: 'AZ',
      title: 'Microsoft Azure Translator',
      subtitle: 'Tradução neural da Microsoft'
    },
    gemini: {
      mark: 'GM',
      title: 'Google Gemini',
      subtitle: 'Tradução com modelo generativo'
    },
    google_cloud: {
      mark: 'GC',
      title: 'Google Cloud Translation',
      subtitle: 'API de tradução do Google Cloud'
    }
  };

  function getProvider(card) {
    return card.querySelector('.provider-form input[name="provider"]')?.value || '';
  }

  function getProviderTest(card) {
    const test = card.querySelector('.provider-test');
    if (!test) return {state:'neutral', text:'Não testado'};

    if (test.classList.contains('ok')) {
      return {state:'ok', text:'Conexão válida'};
    }
    if (test.classList.contains('bad')) {
      return {state:'bad', text:'Falha no teste'};
    }

    const text = (test.querySelector('span')?.textContent || '').trim();
    return {
      state:'neutral',
      text:text || 'Não testado'
    };
  }

  function isConfigured(card) {
    const helpText = Array.from(card.querySelectorAll('.field-help'))
      .map(el => el.textContent || '')
      .join(' ');
    if (/\bAtual\s*:/i.test(helpText)) return true;

    const secret = card.querySelector('input[type="password"]');
    const placeholder = secret?.getAttribute('placeholder') || '';
    if (placeholder.includes('•')) return true;

    return false;
  }

  function providerMeta(provider) {
    return PROVIDERS[provider] || {
      mark:'API',
      title:'Provedor de integração',
      subtitle:'Credenciais e conectividade'
    };
  }

  function setTextIfChanged(el, value) {
    if (el && el.textContent !== value) el.textContent = value;
  }

  function setClassIfChanged(el, value) {
    if (el && el.className !== value) el.className = value;
  }

  function updateSummary(card) {
    const provider = card.dataset.provider || getProvider(card);
    const meta = providerMeta(provider);
    const summary = card.querySelector(':scope > .provider-summary-v3');
    if (!summary) return;

    const configured = isConfigured(card);
    const test = getProviderTest(card);

    const title = summary.querySelector('.provider-title-v3 strong');
    const subtitle = summary.querySelector('.provider-title-v3 span');
    const state = summary.querySelector('.provider-state-v3');
    const testBadge = summary.querySelector('.provider-test-v3');

    setTextIfChanged(title, meta.title);
    setTextIfChanged(subtitle, meta.subtitle);

    if (state) {
      setClassIfChanged(state, 'provider-state-v3 ' + (configured ? 'is-configured' : 'is-empty'));
      setTextIfChanged(state, configured ? 'Configurado' : 'Não configurado');
    }

    if (testBadge) {
      setClassIfChanged(testBadge, 'provider-test-v3 is-' + test.state);
      setTextIfChanged(testBadge, test.text);
    }
  }

  function setOpen(card, open, persist = true) {
    const body = card.querySelector(':scope > .provider-body-v3');
    const summary = card.querySelector(':scope > .provider-summary-v3');
    const provider = card.dataset.provider || '';

    card.classList.toggle('provider-open', open);
    card.classList.toggle('provider-collapsed', !open);

    if (body) body.hidden = !open;
    if (summary) summary.setAttribute('aria-expanded', open ? 'true' : 'false');

    if (persist) {
      try {
        if (open) sessionStorage.setItem(STORAGE_KEY, provider);
        else if ((sessionStorage.getItem(STORAGE_KEY) || '') === provider) {
          sessionStorage.removeItem(STORAGE_KEY);
        }
      } catch (_) {}
    }
  }

  function closeOthers(grid, current) {
    Array.from(grid.querySelectorAll(':scope > .provider-shell-v3')).forEach(card => {
      if (card !== current) setOpen(card, false, false);
    });
  }

  function toggleCard(card) {
    const grid = card.closest('.translation-provider-grid');
    if (!grid) return;

    const open = !card.classList.contains('provider-open');
    if (open) closeOthers(grid, card);
    setOpen(card, open, true);


  }

  function makeSummary(provider, bodyId) {
    const meta = providerMeta(provider);
    const summary = document.createElement('div');
    summary.className = 'provider-summary-v3';
    summary.setAttribute('role', 'button');
    summary.setAttribute('tabindex', '0');
    summary.setAttribute('aria-expanded', 'false');
    summary.setAttribute('aria-controls', bodyId);

    summary.innerHTML =
      '<span class="provider-mark-v3" aria-hidden="true"></span>' +
      '<span class="provider-title-v3"><strong></strong><span></span></span>' +
      '<span class="provider-state-v3 is-empty">Não configurado</span>' +
      '<span class="provider-test-v3 is-neutral">Não testado</span>' +
      '<span class="provider-chevron-v3" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M6.75 9.25 12 14.5l5.25-5.25"/></svg></span>';

    summary.querySelector('.provider-mark-v3').textContent = meta.mark;
    summary.querySelector('.provider-title-v3 strong').textContent = meta.title;
    summary.querySelector('.provider-title-v3 span').textContent = meta.subtitle;

    return summary;
  }

  function prepareCard(card, index) {
    const provider = getProvider(card);
    if (!provider) return;

    card.dataset.provider = provider;

    if (!card.classList.contains('provider-shell-v3')) {
      card.classList.add('provider-shell-v3');

      // Remove the previous accordion button if an older version added it.
      card.querySelectorAll('.provider-collapse-toggle').forEach(el => el.remove());

      const body = document.createElement('div');
      body.className = 'provider-body-v3';
      body.id = 'provider-details-' + provider.replace(/[^a-z0-9_-]/gi, '-') + '-' + index;

      const children = Array.from(card.childNodes);
      children.forEach(node => body.appendChild(node));

      const oldHead =
        body.querySelector('.provider-head') ||
        body.querySelector('.saas-card-head');
      if (oldHead) oldHead.classList.add('provider-original-head-v3');

      const summary = makeSummary(provider, body.id);

      card.appendChild(summary);
      card.appendChild(body);

      card.setAttribute('role', 'group');

      card.addEventListener('click', event => {
        const interactive = event.target.closest(
          'input,select,textarea,button,a,label,[contenteditable="true"]'
        );
        if (interactive) return;

        toggleCard(card);
      });

      summary.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        toggleCard(card);
      });
    }

    updateSummary(card);
  }

  function init() {
    const grid = document.querySelector('.translation-provider-grid');
    if (!grid) return;

    const cards = Array.from(grid.children).filter(card =>
      card.querySelector('.provider-form input[name="provider"]') ||
      card.classList.contains('provider-shell-v3')
    );

    cards.forEach((card, index) => prepareCard(card, index));

    let remembered = '';
    try {
      remembered = sessionStorage.getItem(STORAGE_KEY) || '';
    } catch (_) {}

    let restored = false;
    cards.forEach(card => {
      const shouldOpen = !restored && remembered !== '' && card.dataset.provider === remembered;
      setOpen(card, shouldOpen, false);
      if (shouldOpen) restored = true;
    });
  }

  let scheduled = false;
  function scheduleInit() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => {
      scheduled = false;
      init();
    });
  }

  function nodeContainsFreshIntegrations(node) {
    if (!(node instanceof Element)) return false;

    if (node.matches('.translation-provider-grid')) return true;
    if (node.querySelector?.('.translation-provider-grid')) return true;

    // A provider card inserted directly into the grid by an async refresh.
    if (
      node.parentElement?.classList.contains('translation-provider-grid') &&
      !node.classList.contains('provider-shell-v3')
    ) return true;

    return false;
  }

  function start() {
    init();

    new MutationObserver(mutations => {
      let relevant = false;

      for (const mutation of mutations) {
        if (mutation.type !== 'childList') continue;

        for (const node of mutation.addedNodes) {
          if (nodeContainsFreshIntegrations(node)) {
            relevant = true;
            break;
          }
        }

        if (relevant) break;
      }

      if (relevant) scheduleInit();
    }).observe(document.body, {childList:true, subtree:true});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, {once:true});
  } else {
    start();
  }
})();