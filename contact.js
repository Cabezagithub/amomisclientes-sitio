/**
 * contact.js — Formulario de contacto AmoMisClientes
 * Pipeline: validación → reCAPTCHA v3 (opcional) → fetch contact.php → GA4 → success
 *
 * ⚠️  Si usás reCAPTCHA: reemplazá RECAPTCHA_SITE_KEY con tu site key real.
 *     Si la dejás vacía (''), el form funciona igual sin reCAPTCHA.
 */
(function () {
  'use strict';

  var RECAPTCHA_SITE_KEY = '';   // ← pegá tu site key acá, o dejá vacío para arrancar sin reCAPTCHA
  var FORM_ENDPOINT      = 'contact.php';

  var form      = document.getElementById('contact-form');
  var submitBtn = document.getElementById('cf-submit');
  var errBox    = document.getElementById('cf-error');
  var success   = document.getElementById('cf-success');

  if (!form) return;

  // ── UI helpers ───────────────────────────────────────────
  function setLoading(on) {
    submitBtn.disabled = on;
    var label   = submitBtn.querySelector('.cf-btn-label');
    var spinner = submitBtn.querySelector('.cf-btn-loading');
    if (label)   label.hidden   = on;
    if (spinner) spinner.hidden = !on;
  }

  function showError(msg) {
    if (msg) errBox.textContent = msg;
    errBox.hidden = false;
    errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function hideError() {
    errBox.hidden = true;
    errBox.textContent = '';
  }

  function val(id) {
    var el = document.getElementById(id);
    return el ? el.value.trim() : '';
  }

  // ── Validación cliente-side ───────────────────────────────
  function validate() {
    var name    = val('f-name');
    var email   = val('f-email');
    var message = val('f-message');

    if (name.length < 2) {
      document.getElementById('f-name').focus();
      return 'Completá el campo Nombre (mínimo 2 caracteres).';
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      document.getElementById('f-email').focus();
      return 'Ingresá un email válido.';
    }
    if (message.length < 10) {
      document.getElementById('f-message').focus();
      return 'El mensaje debe tener al menos 10 caracteres.';
    }
    return null; // ok
  }

  // ── Envío al backend ─────────────────────────────────────
  function doSubmit(recaptchaToken) {
    var lang = (window.amoI18n && window.amoI18n.getLocale) ? window.amoI18n.getLocale() : 'es';

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
      meta: {
        referrer: document.referrer,
        screen:   screen.width + 'x' + screen.height,
        viewport: innerWidth + 'x' + innerHeight,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        language: navigator.language,
        platform: navigator.platform,
        touch:    ('ontouchstart' in window || navigator.maxTouchPoints > 0) ? 'si' : 'no',
      },
    };

    fetch(FORM_ENDPOINT, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    })
      .then(function (res) {
        return res.json().then(function (data) {
          return { status: res.status, data: data };
        });
      })
      .then(function (r) {
        setLoading(false);
        if (r.data.ok) {
          form.hidden    = true;
          success.hidden = false;
          success.scrollIntoView({ behavior: 'smooth', block: 'center' });
          // GA4: evento de conversión
          if (window.gtag) {
            gtag('event', 'generate_lead', {
              event_category: 'contacto',
              interest: payload.interest,
              rubro:    payload.rubro,
              lang:     lang,
            });
          }
        } else {
          var errMsg = 'Hubo un problema al enviar. Por favor intentá de nuevo.';
          if (r.data.error === 'recaptcha_failed')  errMsg = 'La verificación de seguridad falló. Actualizá la página e intentá de nuevo.';
          if (r.data.error === 'email_domain')      errMsg = 'El dominio del email no existe o no tiene registros de correo válidos.';
          if (r.data.error === 'validation')        errMsg = 'Revisá que los campos obligatorios estén completos.';
          if (r.data.error === 'mail_failed')       errMsg = 'Error interno al enviar el email. Por favor escribinos directamente a hola@amomisclientes.com';
          showError(errMsg);
        }
      })
      .catch(function (err) {
        setLoading(false);
        showError('Error de conexión. Revisá tu internet e intentá de nuevo.');
        console.error('AMC contact error:', err);
      });
  }

  // ── Submit handler ───────────────────────────────────────
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    hideError();

    var validationError = validate();
    if (validationError) {
      showError(validationError);
      return;
    }

    setLoading(true);

    // Con reCAPTCHA configurado
    if (RECAPTCHA_SITE_KEY && window.grecaptcha) {
      window.grecaptcha.ready(function () {
        window.grecaptcha.execute(RECAPTCHA_SITE_KEY, { action: 'contact' })
          .then(doSubmit)
          .catch(function (err) {
            console.warn('reCAPTCHA error, enviando sin token:', err);
            doSubmit('');
          });
      });
    } else {
      // Sin reCAPTCHA configurado: envía directo (el PHP acepta token vacío si no hay secret)
      doSubmit('');
    }
  });

})();
