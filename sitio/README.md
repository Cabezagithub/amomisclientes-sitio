# Sitio de ventas AmoMisClientes — propuesta técnica (esqueleto)

## Enfoque

- **HTML + CSS + JS** estático: sin framework obligatorio; fácil de hospedar en CDN/nginx y muy liviano.
- **Textos por idioma** en `locales/`. El **español (`es.json`) es la fuente de verdad**: todas las claves deben existir ahí. Los demás archivos solo traducen; si falta una clave, el runtime usa el valor en español (merge con fallback).
- Con **20–30 idiomas** repetís el patrón: un `xx.json` por locale (p. ej. `en.json`, `pt.json`, `de.json`), siempre alineado a las mismas claves que `es.json`.

## Cómo elegir idioma (esqueleto)

1. Query: `?lang=en`
2. Si no hay query: `localStorage` (`amo_lang`)
3. Si no: idioma del navegador si tenemos archivo; si no, `es`

En producción conviene además **rutas o subdominios** por idioma (`/en/`, `/es/`) para SEO y `hreflang`; esto es ampliable sin cambiar el modelo de archivos JSON.

## Cómo servir localmente

Los JSON se cargan con `fetch`; abrir el HTML como archivo suelto puede fallar por CORS. Usar por ejemplo:

```bash
npx --yes serve .
```

## Archivos

| Ruta | Rol |
|------|-----|
| `index.html` | Página base con `data-i18n="clave.anidada"` |
| `css/styles.css` | Estilos mínimos responsivos |
| `js/i18n.js` | Carga `locales/es.json` + locale activo, aplica textos y `lang`/`title` |
| `locales/es.json` | **Principal** — definir siempre todas las claves aquí |
| `locales/en.json` | Ejemplo de traducción parcial o completa |
| `locales/pt.json` | Portugués (Brasil / LatAm) |
| `css/chat.css` | Estilos del widget flotante |
| `js/chat-config.js` | **URL del iframe PBO-AssistantIA** (`iframeUrl`, `iframeParams`) |
| `js/chat.js` | Abre/cierra panel, carga el embed |

## Chat → PBO-AssistantIA

1. Obtené del stack NodeIA/ServidorIA la **URL del chat embebido** (iframe) para la empresa AmoMisClientes (`emp_id`, etc.).
2. Editá `js/chat-config.js`: `iframeUrl: 'https://…'` y, si hace falta, `iframeParams` (por ejemplo `emp_id`).
3. Por defecto se envía `lang` según `<html lang>` (clave configurable con `localeParam`).
4. El `sandbox` del iframe permite scripts, formularios y popups; si PBO requiere más permisos, ajustalo con cuidado.

## Próximos pasos sugeridos

- Script que compare `es.json` con cada `xx.json` y liste claves faltantes (CI o npm script).
- Sitemap y meta `hreflang` por página e idioma.
- Mismo patrón en páginas HTML adicionales (precios, FAQ, legal).
