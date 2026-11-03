(() => {
  const menu = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  menu?.addEventListener('click', () => sidebar?.classList.toggle('open'));
  document.querySelectorAll('[data-modal]').forEach(btn => btn.addEventListener('click', () => {
    const modal = document.getElementById(btn.dataset.modal);
    modal?.classList.add('open');
  }));
  document.querySelectorAll('.modal').forEach(modal => {
    modal.querySelector('.modal-close')?.addEventListener('click', () => modal.classList.remove('open'));
    modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('open'); });
  });
  document.querySelectorAll('[data-table-search]').forEach(input => {
    input.addEventListener('input', () => {
      const table = document.getElementById(input.dataset.tableSearch);
      const q = input.value.trim().toLowerCase();
      table?.querySelectorAll('tbody tr').forEach(row => row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none');
    });
  });
  document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => window.print()));
  document.querySelectorAll('select[name="inventory_item_id"]').forEach(select => select.addEventListener('change', () => {
    const price = select.selectedOptions[0]?.dataset.price;
    const field = document.querySelector('input[name="cash_price"]');
    if (price && field && !field.value) field.value = price;
  }));

  // PWA installation experience for Android/Chromium and iOS Safari.
  const installButton = document.getElementById('pwaInstallButton');
  const liveRegion = document.getElementById('pwaLiveRegion');
  let installPrompt = null;
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const announce = message => { if (liveRegion) liveRegion.textContent = message; };

  function showIOSInstallHelp() {
    const existing = document.getElementById('pwaInstallHelp');
    if (existing) { existing.hidden = false; return; }
    const modal = document.createElement('div');
    modal.id = 'pwaInstallHelp';
    modal.className = 'pwa-help-backdrop';
    modal.innerHTML = `<section class="pwa-help-card" role="dialog" aria-modal="true" aria-labelledby="pwaHelpTitle"><button class="pwa-help-close" type="button" aria-label="Close">×</button><div class="pwa-help-mark">F</div><h2 id="pwaHelpTitle">Install FoneFlex on iPhone or iPad</h2><ol><li>Open this page in <b>Safari</b>.</li><li>Tap the <b>Share</b> button <span aria-hidden="true">□↑</span>.</li><li>Scroll and choose <b>Add to Home Screen</b>.</li><li>Keep <b>Open as Web App</b> enabled, then tap <b>Add</b>.</li></ol><p>FoneFlex will appear on your Home Screen like an app. Sign in using your normal account.</p></section>`;
    document.body.appendChild(modal);
    modal.querySelector('.pwa-help-close')?.addEventListener('click', () => modal.remove());
    modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
  }

  if (installButton && !isStandalone) {
    if (isIOS) {
      installButton.hidden = false;
      installButton.addEventListener('click', showIOSInstallHelp);
    } else {
      window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();
        installPrompt = event;
        installButton.hidden = false;
      });
      installButton.addEventListener('click', async () => {
        if (!installPrompt) {
          announce('Use your browser menu and choose Install app or Add to Home Screen.');
          return;
        }
        installPrompt.prompt();
        const choice = await installPrompt.userChoice;
        if (choice?.outcome === 'accepted') announce('FoneFlex installation started.');
        installPrompt = null;
        installButton.hidden = true;
      });
      window.addEventListener('appinstalled', () => {
        installButton.hidden = true;
        announce('FoneFlex has been installed.');
      });
    }
  }

  // Connectivity indicator: online status does not imply that unsaved work is safe.
  const updateConnection = () => {
    document.documentElement.dataset.connection = navigator.onLine ? 'online' : 'offline';
    if (!navigator.onLine) announce('You are offline. Customer, payment and agreement actions require a live connection.');
  };
  window.addEventListener('online', updateConnection);
  window.addEventListener('offline', updateConnection);
  updateConnection();

  // Register under the current application directory; works with query-string routes.
  if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', async () => {
      try {
        const swUrl = new URL('sw.js', document.baseURI);
        const registration = await navigator.serviceWorker.register(swUrl.href, { scope: new URL('./', document.baseURI).pathname });
        if (registration.waiting) announce('A FoneFlex update is ready. Close and reopen the app to use it.');
        registration.addEventListener('updatefound', () => {
          const worker = registration.installing;
          worker?.addEventListener('statechange', () => {
            if (worker.state === 'installed' && navigator.serviceWorker.controller) announce('A FoneFlex update is ready. Refresh to load the latest version.');
          });
        });
      } catch (error) {
        console.warn('FoneFlex service worker registration failed:', error);
      }
    });
  }
})();
