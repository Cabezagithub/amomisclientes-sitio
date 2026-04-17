/**
 * Widget flotante: iframe hacia PBO-AssistantIA o mensaje de configuración.
 */
(function () {
  function buildIframeUrl() {
    var c = window.AMO_PBO_CHAT_CONFIG || {};
    var base = (c.iframeUrl || '').trim();
    if (!base) return '';
    try {
      var u = new URL(base, window.location.href);
      var params = c.iframeParams || {};
      Object.keys(params).forEach(function (k) {
        var v = params[k];
        if (v != null && String(v) !== '') u.searchParams.set(k, String(v));
      });
      if (c.localeParam && c.syncLocaleFromPage !== false) {
        var lang = document.documentElement.getAttribute('lang') || 'es';
        u.searchParams.set(c.localeParam, lang);
      }
      return u.toString();
    } catch (e) {
      console.warn('[AmoChat] iframeUrl inválida', e);
      return '';
    }
  }

  function labelClose(closeBtn) {
    var d = window.amoI18nLastDict || {};
    var t =
      d.chat && d.chat.close
        ? d.chat.close
        : closeBtn && closeBtn.getAttribute('aria-label')
          ? closeBtn.getAttribute('aria-label')
          : 'Cerrar';
    if (closeBtn) closeBtn.setAttribute('aria-label', t);
  }

  function init() {
    var fab = document.getElementById('amo-chat-fab');
    var panel = document.getElementById('amo-chat-panel');
    var backdrop = document.getElementById('amo-chat-backdrop');
    var closeBtn = document.getElementById('amo-chat-close');
    labelClose(closeBtn);
    var iframe = document.getElementById('amo-chat-iframe');
    var fallback = document.getElementById('amo-chat-fallback');
    if (!fab || !panel || !iframe || !fallback) return;

    var url = buildIframeUrl();
    if (url) {
      iframe.removeAttribute('hidden');
      fallback.setAttribute('hidden', '');
    } else {
      iframe.setAttribute('hidden', '');
      fallback.removeAttribute('hidden');
    }

    function open() {
      panel.removeAttribute('hidden');
      if (backdrop) backdrop.removeAttribute('hidden');
      fab.setAttribute('aria-expanded', 'true');
      if (url) {
        if (!iframe.getAttribute('src')) iframe.setAttribute('src', url);
      }
      document.body.classList.add('amo-chat--open');
      if (closeBtn) closeBtn.focus();
    }

    function close() {
      panel.setAttribute('hidden', '');
      if (backdrop) backdrop.setAttribute('hidden', '');
      fab.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('amo-chat--open');
      fab.focus();
    }

    fab.addEventListener('click', function () {
      if (panel.hasAttribute('hidden')) open();
      else close();
    });
    if (closeBtn) closeBtn.addEventListener('click', close);
    if (backdrop) backdrop.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hasAttribute('hidden')) close();
    });
  }

  window.amoChat = { init: init };
})();
