/**
 * Configuración del chat → PBO-AssistantIA (chat propio / embed NodeIA-ServidorIA).
 * Completá iframeUrl cuando tengáis la URL oficial del widget; podés pasar emp_id u otros query params.
 */
window.AMO_PBO_CHAT_CONFIG = {
  /** URL base del embed (sin query opcionales si preferís armarlos en iframeParams) */
  iframeUrl: '',

  /** Se añaden como ?clave=valor a iframeUrl */
  iframeParams: {
    // emp_id: '91',
  },

  /** Si definís un nombre (ej. lang), se rellena con document.documentElement.lang al abrir */
  localeParam: 'lang',
  syncLocaleFromPage: true,
};
