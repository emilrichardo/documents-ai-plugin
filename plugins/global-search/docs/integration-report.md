# Global Search 0.3.0 — implementación y QA

Fecha: 2026-09-16. Entorno remoto comprobado: https://4094.cirlot.com/.
Cambios limitados a `plugins/global-search/`. No se editó Elementor, contenido
de Staff, documentos de Barn2, Policies, Institutions ni sus integraciones.

## HEADER

- `variant="compact"` es siempre `data-mode="form"`, incluso con `results="inline"`.
- No genera panel, dropdown, filtros, resultados, live region ni atributos de
  combobox/autocomplete. `show_filters="yes"` también se ignora en compacto.
- Formulario GET con input `q` y action hacia la Results Page existente.
- GO y Enter se probaron por separado en el header real, obteniendo URLs
  `/site-search/?q=Stephen+Pruitt` y
  `/site-search/?q=SC+Reports+Prison+Education+Program`.
- Escribir no abre sugerencias. El JS retorna antes de fetch, listeners o
  debounce; el test ejecuta el archivo distribuido con live search ON y OFF.
- El renderer compacto solo solicita CSS. Un renderer completo en la misma
  petición puede solicitar el JS compartido, que igualmente omite compactos.
  Staging incluye ese script en HOME; no se afirma que toda la página carezca
  de JavaScript. La navegación del header es nativa, no un fetch ni un handler.
- Límite de QA: no se deshabilitó todo JavaScript en el navegador ni se obtuvo
  una captura de tráfico; la ausencia de requests del compacto se comprueba
  con el test del script y su guard temprano, además del markup sin panel.

Shortcode final, ya presente en el header de staging:

```text
[global_search variant="compact" shape="rounded" button_label="GO" placeholder="Search Site ..." show_filters="no"]
```

Results Page preservada: Search Results, ID `14250`, `/site-search/`.
`[global_search]` mantiene live search, dropdown/inline, filtros y navegación.
Se probó escribir en el input completo y obtener resultados dinámicos.

## STAFF / PODs

- Plugin: Pods – Custom Content Types and Fields, `3.3.9.2`.
- CPT: `staff`, public/queryable, almacenamiento Pods `post_type` + `meta`:
  posts en la tabla WordPress posts, campos en postmeta. No taxonomías.
- Nombre: `post_title`. Bio: `bio_description`. Cargo: `position`.
- Otros campos encontrados: `last_name`, `photo` y relación `_pods_photo`,
  `directory_order`, `presidents_cabinet`, `speaker`, `president_order`,
  `phone`, `email`. No se encontró campo departamento.
- Phone/email no participan en búsqueda ni se exponen en la respuesta.
- Provider: `Global_Search_Staff_Provider`, id/source/type `staff`.
- Busca título, content/excerpt de WordPress, `last_name`, `position` y
  `bio_description`. Devuelve cargo en `meta.title` y muestra View Profile.

Fixture real confirmado tanto en local como en el editor de staging:

```text
ID: 12103
post_type: staff
title: Dr. Stephen L. Pruitt
position: President
photo attachment: 12593
URL: https://4094.cirlot.com/staff/dr-stephen-l-pruitt/
bio_description: empieza con Lorem ipsum dolor sit amet...
post_content / post_excerpt: vacíos
```

QA remoto: `Stephen Pruitt` devuelve el perfil como primer resultado,
source/type `staff`, score `1.0`, cargo y excerpt del campo real.

El permalink funciona pero utiliza actualmente la plantilla normal del theme:
solo se observaron los templates Elementor header `75` y footer `94`, no una
plantilla single Staff. La página del perfil no presenta bio/cargo/foto.
No se inventó contenido ni se modificó esa presentación.

## DOCUMENT LIBRARY

- Plugin detectado en staging: Document Library Pro – Advanced.
- Versión: `4.1.0`. Vendor: Barn2 Plugins.
- Main file: `document-library-pro/document-library-pro.php`.
- CPT real: `dlp_document`, público; publicly_queryable depende de la licencia
  del plugin. La integración requiere que sea queryable y que sus APIs existan.
- Título: `post_title`; cuerpo: `post_content`; descripción: `post_excerpt`.
- Taxonomías: `doc_categories`, `doc_tags`, `doc_author`, `file_type`.
- Archivo: `_dlp_attached_file_id` enlaza un attachment; filename proviene de
  `_wp_attached_file`. URLs externas: `_dlp_direct_link_url`.
- Otros metadatos relevantes del modelo: `_dlp_document_link_type`,
  `_dlp_expiry_timestamp`, `_dlp_document_visibility`, `_dlp_version_history`.
  No se buscan claves de seguridad ni metadata arbitraria.
- Texto extraído existente: helper Barn2 `File_Content_Table('dlp')`, cuyo
  nombre se resuelve en runtime; columnas `post_id`, `attachment_id`,
  `content_text`, `error_message`. Solo filas exitosas del attachment actual.
- Campos extra: solo los campos documentales declarados por Easy Post Types
  and Fields, si esa integración existe. No se encontraron campos extra que
  fuera necesario inventar/configurar en staging.
- Provider independiente: `Global_Search_Document_Library_Provider`, id/source
  `document_library`, type `document`, label Document Library.
- Busca título, cuerpo, descripción, filename, taxonomías y texto ya extraído.
  No llama parsers ni dispara indexación, OCR o descarga de archivos.
- Reutiliza `dlp_get_document`, expiry y `Visibility::is_document_protected`
  de Barn2. Retorna el permalink documental, no un download que evite permisos.
- La fuente activa desaparece de REST/filtros cuando deja de estar disponible.
  En admin permanece como candidata no disponible, sin warnings ni fatals.

Documentos reales buscados por título después del despliegue:

1. SC Reports Program Expansion, ID `14912`:
   https://4094.cirlot.com/document/sc-reports-program-expansion/
2. SC Reports Prison Education Program, ID `14910`:
   https://4094.cirlot.com/document/sc-reports-prison-education-program/

También se verificaron `SCReportsProgram-Expansion.docx`, la descripción
`Added January 2026` (documentos Track A/B 2028), y texto extraído
`Statement Regarding the Report`. El fixture local adicional Track B 2028
proviene del documento real ID `14483`; datos en `tests/fixtures/document-library.json`.

## GLOBAL SEARCH

- Cinco fuentes activas comprobadas en REST y Overview:
  Site, Policies, Institutions, Staff, Document Library.
- Filtros y labels derivan de metadata de providers; se conservaron counts.
- La query remota `sit` dio All 41: Site 10, Policies 10, Institutions 10,
  Staff 1, Document Library 10. Son counts limitados por fuente, no totales
  exhaustivos del corpus. La página real mostró los cinco grupos.
- Regresión `university`: Site 10, Policies 6, Institutions 10, Library 1.
- Ranking: los providers previos no se reescribieron. Los nuevos ordenan
  exact title 1.0 > todas las palabras en título 0.85 > otros campos 0.4,
  antes del cap. Solo un nombre exacto Staff (sin honoríficos/iniciales)
  mueve el grupo Staff antes de las menciones de Site.
- Site excluye `staff`/`dlp_document` aun si fueran opt-in, para no duplicar
  resultados ni recuperar esas fuentes por Site cuando se apagan.
- Cache key agrega versión + IDs/clases de providers disponibles/habilitados,
  además de query, cap, sources y settings existentes.
- Con Document Library activa no se reutiliza cache compartida de resultados:
  permisos de Barn2 dependen del usuario, roles, cookies y reglas heredadas.
  Fuentes deshabilitadas/no disponibles no recuperan respuestas antiguas.

## ADMIN

- Overview muestra estado real de las cinco fuentes y versión 0.3.0.
- Settings muestra switches/labels y Available o unavailable según detección.
- Results Page, opción `gsearch_settings` y preferencias previas preservadas.
- Live search aclara que solo controla full/default.
- Se retiró el control obsoleto de filas del dropdown compacto; la key
  `compact_max_results` se conserva sin reset en almacenamiento.

## TESTS

| Suite | Passed | Failed |
| --- | ---: | ---: |
| WordPress integrado + admin | 146 | 0 |
| Standalone, plugins opcionales ausentes | 78 | 0 |
| Fuentes nuevas y fixtures equivalentes | 37 | 0 |
| JavaScript compacto | 2 | 0 |
| Total | 263 | 0 |

PHP lint, JS syntax y git diff --check: sin errores.
Los tests usan el socket existente de Local MySQL; no se cambió wp-config.
Los fixtures documentales se insertan con SQL dentro de una transacción,
usan tablas temporales y se revierten. Settings/plugins reales no se guardan.
Barn2 no está instalado localmente: modelo/visibility/table son dobles en esa
suite. La QA remota de búsquedas utiliza el plugin Barn2 real.
Desactivación y restricciones se prueban aisladas/localmente; no se desactivó
Barn2 ni se cambiaron permisos en staging para probar cada combinación ACL.

## DEMO

- https://4094.cirlot.com/ — escribir y GO en el header real.
- https://4094.cirlot.com/site-search/?q=Stephen+Pruitt
- https://4094.cirlot.com/site-search/?q=SC+Reports+Program+Expansion
- https://4094.cirlot.com/site-search/?q=SC+Reports+Prison+Education+Program
- https://4094.cirlot.com/site-search/?q=sit
- https://4094.cirlot.com/wp-admin/admin.php?page=global-search
- https://4094.cirlot.com/wp-admin/admin.php?page=global-search-settings
- REST `/wp-json/global-search/v1/providers` y `/search` con las queries arriba.

## FILES MODIFIED

Todo bajo `plugins/global-search/`:

- `global-search.php`
- `includes/functions.php`
- `includes/class-search-service.php`
- `includes/shortcode.php`
- `includes/admin-settings.php`
- `includes/provider-wordpress.php`
- `includes/provider-cpt-helpers.php` (nuevo)
- `includes/provider-staff.php` (nuevo)
- `includes/provider-document-library.php` (nuevo)
- `assets/js/global-search.js`
- `tests/harness.php`
- `tests/harness-admin.php`
- `tests/harness-standalone.php`
- `tests/harness-new-sources.php` (nuevo)
- `tests/test-compact.js` (nuevo)
- `tests/fixtures/document-library.json` (nuevo)
- `README.md`
- `docs/integration-report.md` (nuevo)

## PENDING

- Sustituir el Lorem ipsum de Stephen Pruitt por una bio aprobada y, si se
  desea mostrarla al abrir View Profile, configurar una plantilla de perfil.
  Esto requiere un trabajo de contenido/template separado; no impide encontrarlo.
- Despliegue directo SFTP a staging, sin commit ni push. Un deploy futuro desde
  main puede sobrescribirlo hasta que los cambios se publiquen en git.
- No se verificó otro dominio de producción ni se certificaron todas las
  combinaciones de permisos/cookies de Barn2 con usuarios reales.

Backup del runtime remoto previo, sin credenciales:
`/tmp/cirlot-gsearch-backup.TRinuu/`. Tests nuevos no se subieron al servidor.
