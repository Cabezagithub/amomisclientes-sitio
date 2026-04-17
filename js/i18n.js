/**
 * i18n estático: es.json es la fuente de verdad; el locale activo se fusiona encima
 * y las claves faltantes caen al español.
 */
(function () {
  const STORAGE_KEY = 'amo_lang';
  const DEFAULT_LOCALE = 'es';
  const SUPPORTED = ['es', 'en', 'pt'];
  let currentLocale = DEFAULT_LOCALE;
  let cachedEs = null;

  function getQueryLang() {
    const q = new URLSearchParams(window.location.search).get('lang');
    return q && SUPPORTED.includes(q) ? q : null;
  }

  function resolveLocale() {
    return getQueryLang() || localStorage.getItem(STORAGE_KEY) || DEFAULT_LOCALE;
  }

  function getByPath(obj, path) {
    return path.split('.').reduce(function (acc, key) {
      return acc && acc[key] !== undefined ? acc[key] : undefined;
    }, obj);
  }

  function deepMerge(base, over) {
    if (!over || typeof over !== 'object') return base;
    const out = Array.isArray(base) ? base.slice() : { ...base };
    Object.keys(over).forEach(function (k) {
      const bv = base[k];
      const ov = over[k];
      if (ov && typeof ov === 'object' && !Array.isArray(ov) && bv && typeof bv === 'object' && !Array.isArray(bv)) {
        out[k] = deepMerge(bv, ov);
      } else if (ov !== undefined) {
        out[k] = ov;
      }
    });
    return out;
  }

  async function loadJson(path) {
    const res = await fetch(path, { cache: 'no-store' });
    if (!res.ok) throw new Error('No se pudo cargar ' + path);
    return res.json();
  }

  function applyStrings(dict) {
    document.querySelectorAll('[data-i18n]').forEach(function (el) {
      const key = el.getAttribute('data-i18n');
      const val = getByPath(dict, key);
      if (val !== undefined && val !== null) el.textContent = val;
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(function (el) {
      const key = el.getAttribute('data-i18n-placeholder');
      const val = getByPath(dict, key);
      if (val !== undefined && val !== null) el.setAttribute('placeholder', val);
    });
    const title = getByPath(dict, 'meta.title');
    if (title) document.title = title;
    const desc = getByPath(dict, 'meta.description');
    let meta = document.querySelector('meta[name="description"]');
    if (desc && meta) meta.setAttribute('content', desc);
  }

  function renderLanguageSelector(locale) {
    document.querySelectorAll('[data-lang]').forEach(function (el) {
      const isActive = el.getAttribute('data-lang') === locale;
      el.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      el.classList.toggle('is-active', isActive);
    });
  }

  async function loadMergedDict(locale) {
    if (!cachedEs) cachedEs = await loadJson('locales/es.json');
    let merged = cachedEs;
    if (locale !== DEFAULT_LOCALE) {
      try {
        const loc = await loadJson('locales/' + locale + '.json');
        merged = deepMerge(cachedEs, loc);
      } catch (_) {
        merged = cachedEs;
      }
    }
    return merged;
  }

  function bindLanguageSelector() {
    document.querySelectorAll('[data-lang]').forEach(function (el) {
      if (el.dataset.bound === 'true') return;
      el.dataset.bound = 'true';
      el.addEventListener('click', function () {
        const next = el.getAttribute('data-lang');
        window.amoI18n.setLocale(next);
      });
    });
  }

  function bindLanguageSelectorFallback() {
    const selector = document.getElementById('amo-lang-selector');
    if (!selector || selector.dataset.bound === 'true') return;
    selector.dataset.bound = 'true';
    selector.addEventListener('change', function (event) {
      const next = String(event.target.value || '');
      window.amoI18n.setLocale(next);
    });
  }

  window.amoI18n = {
    init: async function () {
      let locale = resolveLocale();
      if (!SUPPORTED.includes(locale)) locale = DEFAULT_LOCALE;
      localStorage.setItem(STORAGE_KEY, locale);
      currentLocale = locale;
      document.documentElement.lang = locale;

      const merged = await loadMergedDict(locale);
      window.amoI18nLastDict = merged;
      applyStrings(merged);
      bindLanguageSelector();
      bindLanguageSelectorFallback();
      renderLanguageSelector(locale);
      return { locale, dict: merged };
    },
    setLocale: async function (locale) {
      if (!SUPPORTED.includes(locale)) return { locale: currentLocale, dict: window.amoI18nLastDict || null };
      localStorage.setItem(STORAGE_KEY, locale);
      currentLocale = locale;
      document.documentElement.lang = locale;
      const merged = await loadMergedDict(locale);
      window.amoI18nLastDict = merged;
      applyStrings(merged);
      renderLanguageSelector(locale);
      return { locale, dict: merged };
    },
    getLocale: function () {
      return currentLocale;
    },
  };
})();
