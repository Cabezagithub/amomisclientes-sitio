/**
 * i18n estático: es.json es la fuente de verdad; el locale activo se fusiona encima
 * y las claves faltantes caen al español.
 */
(function () {
  const STORAGE_KEY = 'amo_lang';
  const DEFAULT_LOCALE = 'es';
  const SUPPORTED = ['es', 'en', 'pt'];

  function getQueryLang() {
    const q = new URLSearchParams(window.location.search).get('lang');
    return q && SUPPORTED.includes(q) ? q : null;
  }

  function resolveLocale() {
    return (
      getQueryLang() ||
      localStorage.getItem(STORAGE_KEY) ||
      (navigator.language || DEFAULT_LOCALE).slice(0, 2).toLowerCase() ||
      DEFAULT_LOCALE
    );
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

  window.amoI18n = {
    init: async function () {
      let locale = resolveLocale();
      if (!SUPPORTED.includes(locale)) locale = DEFAULT_LOCALE;
      localStorage.setItem(STORAGE_KEY, locale);
      document.documentElement.lang = locale === 'es' ? 'es' : locale;

      const es = await loadJson('locales/es.json');
      let merged = es;
      if (locale !== DEFAULT_LOCALE) {
        try {
          const loc = await loadJson('locales/' + locale + '.json');
          merged = deepMerge(es, loc);
        } catch (_) {
          merged = es;
        }
      }
      window.amoI18nLastDict = merged;
      applyStrings(merged);
      return { locale, dict: merged };
    },
  };
})();
