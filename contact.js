/**
 * contact.js — Formulario de contacto AmoMisClientes
 * Pipeline: validación → reCAPTCHA v3 → fetch contact.php → GA4 → success
 *
 * Requiere:
 *   - reCAPTCHA v3 cargado en el head con la site key correcta
 *   - GA4 cargado en el head (window.gtag)
 *   - i18n.js inicializado antes (amoI18n.getLocale())
 */
(function () {
  'use strict';

  // ── Configuración ────────────────────────────────────────
  // ⚠️ CAMBIAR: misma site key que pusiste en el <script> del head
  var RECAPTCHA_SITE_KEY = 'YOUR_SITE_KEY';
  var FORM_ENDPOINT      = 'contact.php';

  // ── Referencias al DOM ───────────────────────────────────
  var form      = document.getElementById('contact-form');
  var submitBtn = document.getElementById('cf-submit');
  var errBox    = document.getElementById('cf-error');
  var success   = document.getElementById('cf-success');

  if (!form) return; // si no hay form en la página, salir

  // ── Helpers ──────────────────────────────────────────────
  function setLoading(on) {
    submitBtn.disabled = on;
    submitBtn.querySelector('.cf-btn-label').hidden = on;
    submitBtn.querySelector('.cf-btn-loading').hidden = !on;
  }

  function showError(msg) {
    if (msg) errBox.textContent = msg;
    errBox.hidden = false;
    errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function hideError() {
    errBox.hidden = true;
  }

  function val(id) {
    var el = document.getElementById(id);
    return el ? el.value.trim() : '';
  }

  // Validación mínima cliente-side (el server valida de nuevo)
  function validate() {
    var name    = val('f-name');
    var email   = val('f-email');
    var message = val('f-message');

    if (name.length < 2) {
      showError(null); // usa el texto del data-i18n ya aplicado
      document.getElementById('f-name').focus();
      return false;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      document.getElementById('f-email').focus();
      return false;
    }
    if (message.length < 10) {
      document.getElementById('f-message').focus();
      return false;
    }
    return true;
  }

  // ── Submit ───────────────────────────────────────────────
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    hideError();

    if (!validate()) {
      showError();
      return;
    }

    setLoading(true);

    var lang = (window.amoI18n && window.amoI18n.getLocale) ? window.amoI18n.getLocale() : 'es';

    var meta = {
      referrer: document.referrer,
      screen:   screen.width + 'x' + screen.height,
      viewport: innerWidth + 'x' + innerHeight,
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      language: navigator.language,
      platform: navigator.platform,
      touch:    ('ontouchstart' in window || navigator.maxTouchPoints > 0) ? 'si' : 'no',
    };

    function doSubmit(recaptchaToken) {
      var payload = {
        name:            val('f-name'),
        email:           val('f-email'),
        company:         val('f-company'),
        phone:           val('f-phone'),
        country:         val('f-country'),
        rubro:           val('f-rubro'),
        sucursales:      val('f-sucursales'),
        interest:        val('f-interest'),
        message:         val('f-message'),
        extra:           document.getElementById('f-extra') ? document.getElementById('f-extra').value : '',
        recaptcha_token: recaptchaToken || '',
        lang:            lang,
        meta:            meta,
      };

      fetch(FORM_ENDPOINT, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload),
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          setLoading(false);
          if (data.ok) {
            // Ocultar form y mostrar success con scroll
            form.hidden = true;
            success.hidden = false;
            success.scrollIntoView({ behavior: 'smooth', block: 'center' });

            // GA4: evento de conversión
            if (window.gtag) {
              gtag('event', 'generate_lead', {
                event_category: 'contacto',
                interest:  payload.interest,
                rubro:     payload.rubro,
                lang:      lang,
              });
            }
          } else {
            showError();
          }
        })
        .catch(function () {
          setLoading(false);
          showError();
        });
    }

    // Ejecutar con reCAPTCHA si está disponible
    if (window.grecaptcha && RECAPTCHA_SITE_KEY && RECAPTCHA_SITE_KEY !== 'YOUR_SITE_KEY') {
      window.grecaptcha.ready(function () {
        window.grecaptcha.execute(RECAPTCHA_SITE_KEY, { action: 'contact' })
          .then(doSubmit)
          .catch(function () { doSubmit(''); });
      });
    } else {
      // Sin reCAPTCHA: igualmente envía (el PHP acepta token vacío si no hay secret)
      doSubmit('');
    }
  });

})();
