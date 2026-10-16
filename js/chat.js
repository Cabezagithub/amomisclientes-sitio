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
        u.searchParams.set(c.localeParam, currentChatLang());
      }
      return u.toString();
    } catch (e) {
      console.warn('[AmoChat] iframeUrl inválida', e);
      return '';
    }
  }

  var CHAT_LANG_KEY = 'amo_chat_label_lang';

  function normalizeChatLang(raw) {
    var v = String(raw || '').toLowerCase();
    if (v.indexOf('pt') === 0) return 'pt';
    if (v.indexOf('es') === 0) return 'es';
    return '';
  }

  function currentChatLang() {
    var saved = '';
    try { saved = normalizeChatLang(localStorage.getItem(CHAT_LANG_KEY)); } catch (e) { saved = ''; }
    if (saved) return saved;
    var page = normalizeChatLang(document.documentElement.getAttribute('lang'));
    if (page) return page;
    var browser = (navigator.languages && navigator.languages[0]) || navigator.language || '';
    return normalizeChatLang(browser) || 'es';
  }

  function chatDict() {
    return (window.amoI18nLastDict && window.amoI18nLastDict.chat) || {};
  }

  var ASSISTANT = { es: 'Asistente', pt: 'Assistente' };
  var TITLES = { es: '¿En qué podemos ayudarte?', pt: 'Como podemos ajudar?' };
  var CLOSE = { es: 'Cerrar', pt: 'Fechar' };
  var chrome = { company: 'AmoMisClientes', status: '' };

  function labelClose(closeBtn) {
    var lang = currentChatLang();
    var t = CLOSE[lang] || chatDict().close || 'Cerrar';
    if (closeBtn) {
      closeBtn.setAttribute('aria-label', t);
      closeBtn.setAttribute('title', t);
    }
  }

  function markFlags(lang) {
    document.querySelectorAll('#amo-chat-langs [data-chat-lang]').forEach(function (btn) {
      var on = btn.getAttribute('data-chat-lang') === lang;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function paintSubtitle() {
    var el = document.getElementById('amo-chat-subtitle');
    if (!el) return;
    var lang = currentChatLang();
    var assistant = ASSISTANT[lang] || chatDict().assistant || 'Asistente';
    var company = chrome.company || 'AmoMisClientes';
    var line = assistant + ' ' + company;
    if (chrome.status) line += ' - ' + chrome.status;
    el.textContent = line;
    var title = document.getElementById('amo-chat-title');
    if (title && TITLES[lang]) title.textContent = TITLES[lang];
  }

  function init() {
    var fab = document.getElementById('amo-chat-fab');
    var panel = document.getElementById('amo-chat-panel');
    var backdrop = document.getElementById('amo-chat-backdrop');
    var closeBtn = document.getElementById('amo-chat-close');
    var iframe = document.getElementById('amo-chat-iframe');
    var fallback = document.getElementById('amo-chat-fallback');
    if (!fab || !panel || !iframe || !fallback) return;

    function postLocale(locale) {
      if (!iframe.getAttribute('src') || !iframe.contentWindow) return;
      var target = '*';
      try { target = new URL(iframe.src, window.location.href).origin; } catch (e) { target = '*'; }
      try {
        iframe.contentWindow.postMessage({ source: 'pbo-chat-shell', type: 'locale', lang: locale }, target);
      } catch (e) { /* el iframe todavía no está listo */ }
    }

    function applyChatChrome() {
      labelClose(closeBtn);
      markFlags(currentChatLang());
      paintSubtitle();
      postLocale(currentChatLang());
    }

    window.addEventListener('message', function (e) {
      if (!iframe.contentWindow || e.source !== iframe.contentWindow) return;
      var data = e.data;
      if (!data || data.source !== 'pbo-webchat' || data.type !== 'chrome') return;
      if (data.company) chrome.company = String(data.company);
      chrome.status = data.status ? String(data.status) : '';
      paintSubtitle();
    });

    document.querySelectorAll('#amo-chat-langs [data-chat-lang]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var next = normalizeChatLang(btn.getAttribute('data-chat-lang')) || 'es';
        try { localStorage.setItem(CHAT_LANG_KEY, next); } catch (e) { /* ignore */ }
        applyChatChrome();
      });
    });

    if (window.amoI18n && window.amoI18n.setLocale) {
      var origSetLocale = window.amoI18n.setLocale;
      window.amoI18n.setLocale = function (locale) {
        return Promise.resolve(origSetLocale.call(window.amoI18n, locale)).then(function (res) {
          applyChatChrome();
          return res;
        });
      };
    }

    var url = buildIframeUrl();
    applyChatChrome();
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
      url = buildIframeUrl();
      if (url) {
        if (!iframe.getAttribute('src')) iframe.setAttribute('src', url);
        else postLocale(currentChatLang());
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
