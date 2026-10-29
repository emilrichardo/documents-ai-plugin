# Correcciones reportadas por Cirlot — octubre 2026

Rama `fix/policies-bugs-cirlot-2026-10`. Nada desplegado, nada reimportado,
ningún contenido publicado modificado.

Los tres problemas que reportó el cliente son **defectos del plugin**, no
funcionalidades nuevas. Se agrega además la corrección de compatibilidad de
enlaces que Stuart pidió por separado.

---

## 1. Enlaces manuales sobre textos como `[PDF]`

**Reportado:** `Credit Hours [[PDF]](https://stage.commissioncu.org/document/credit-hours/)`
se publicaba como texto literal.

**Causa.** No era el editor ni el almacenamiento: era la gramática.
`AIDOCS_LINK_PATTERN` admitía un corchete escapado con barra invertida —que es
lo que escribe `aidocs_link_markup()`— pero no un par balanceado, que es lo que
escribe cualquier editor markdown genérico. El botón «link» de EasyMDE envolvió
una selección que ya decía `[PDF]` y produjo una sintaxis que el parser no lee.
El destino clasificaba bien; el fallo era de análisis.

**Corrección, en dos mitades.**

- *Al escribir.* `assets/js/aidocs-link-markup.js` es ahora el único lugar que
  sabe deletrear un enlace para esta gramática, y el botón del editor lo usa.
  Escapa los corchetes de la etiqueta y codifica en porcentaje los espacios y
  paréntesis del destino —lo que además rescata las URL de SharePoint y
  OneDrive, que morían calladas en el primer espacio.
- *Al leer.* La gramática acepta un par de corchetes balanceado en la etiqueta,
  de modo que **lo ya guardado se recupera solo**, sin reimportar.

**De paso.** `aidocs_inline_runs()` preguntaba «¿hay un enlace en lo que
queda?» cuando quería preguntar «¿empieza uno aquí?». Con un `[` que no abría
enlace seguido de uno que sí, avanzaba el cursor por la longitud de una
coincidencia que empezaba en otro lado y corrompía la línea. Ahora el patrón
está anclado.

## 2. Jerarquía y numeración de las listas de Word

**Reportado:** «pérdida de formato» en *Antitrust Compliance*, pestaña
*Definitions*.

Lo que se había perdido era el **sentido**. El original dice

```
A. Antitrust laws of the United States: …
   1…15. Fixing fees / Rigging bids / …
   Higher education related activities … may include:
   1…7. Policies or practices …
B. The Commission representative(s): …
```

y la página publicaba los subítems renumerados como `B. C. D.` y la definición
`B.` como ítem 3 de la lista de infracciones. Una definición aparecía
clasificada como ejemplo de violación antitrust.

**Dos defectos distintos.**

*a) El esquema se aplanaba.* En Word, `A.` y `B.` son texto literal en párrafos
corrientes; solo los dígitos son una lista automática. mammoth los entrega a
todos como hermanos al mismo nivel. `aidocs_list_from_flat()` tomaba el estilo
del primer marcador y dejaba que todo lo demás se sumara a esa misma lista.
Ahora un estilo que rankea más profundo en el esquema (`I.` → `A.` → `1.` →
`a.` → `i.`) y empieza en uno abre una sublista del ítem anterior; cualquier
otro cambio cierra la lista y abre la siguiente. (`$flush_list` además
descartaba el resto de los ítems tras un cambio de estilo: se perdían.)

*b) El formato de numeración se descartaba.* `listMarker()` escribía siempre
`1. 2. 3.`, porque mammoth lee `numbering.xml` y tira el `numFmt`. Medido sobre
las cuatro compilaciones: **395 de 1.623 párrafos numerados** llevan en Word un
formato que no es decimal.

`assets/js/aidocs-docx-numbering.js` abre el .docx por su cuenta —con
`DecompressionStream('deflate-raw')`, sin añadir una librería zip— lee
`numbering.xml`, simula el contador de Word nivel por nivel y devuelve un
índice de texto → marcador. El emparejamiento es **por texto, no por posición**:
mammoth fusiona y anida listas con sus propias reglas, y un marcador puesto en
el ítem equivocado es peor que ninguno.

**Medido sobre el corpus real, antes y después:**

| | antes | ahora |
| --- | ---: | ---: |
| marcadores decimales | 1.048 | 679 |
| marcadores alfabéticos | 13 | 337 |
| marcadores romanos | 0 | 45 |
| listas | 401 | 441 |
| encabezados / párrafos / notas / tablas | 409 / 1.649 / 34 / 6 | idénticos |
| **texto visible de cada bloque** | — | **idéntico carácter a carácter** |

Las otras tres compilaciones no cambian en nada: sus listas sí eran decimales
y con viñetas.

La gramática canónica se amplió en consecuencia: marcadores romanos de varios
caracteres (`iii.`) y decimales de tres dígitos. Las letras siguen limitadas a
un carácter a propósito — dos harían que `Mr. Smith`, `Dr. Jones` y `St. Louis`
abrieran una lista.

## 3. Saltos y números de página en los PDF

**No reproducido todavía con el archivo del cliente.** Lo que sigue corrige el
mecanismo identificado; hasta pasarlo por ese PDF no se puede dar por cerrado.

El recorte por bandas no puede alcanzarlos: recorta 3,5 % de la página —27,7 pt
en carta— y el pie por defecto de Word está a media pulgada, 36 pt. El
mobiliario cae *dentro* del área de contenido. Ensanchar la banda se llevaría
`Last Updated:` y `Document History:`, que estos documentos escriben al pie de
la última página.

`stripPageFurniture()` va por **recurrencia**. Una línea es mobiliario cuando
está en el bloque que la separación vertical aísla en un borde de página, y su
texto —con los dígitos normalizados, para que `Page 3 of 71` case con
`Page 4 of 71`— aparece a la misma altura en al menos tres páginas y en la
mayoría de ellas. Un documento de una o dos páginas queda intacto, y también
cualquier cosa que aparezca una sola vez: eso es lo que protege
`Last Updated:`.

Una primera versión usaba «las dos líneas de cada borde», y una prueba
detectó que con un encabezado de una línea eso alcanzaba la primera línea del
cuerpo. Los candidatos se eligen ahora por la separación vertical.

## 4. Compatibilidad de enlaces al sitio actual

**Reportado por Stuart:** el plugin descarta enlaces a archivos del sitio
actual.

`AIDOCS_LEGACY_LINK_PATHS` nombra las rutas de subida del sitio **anterior**, y
una de ellas, `/wp-content/uploads/`, es también la del sitio actual. Un enlace
a un archivo de commissioncu.org se leía como enlace muerto al sitio viejo.

Ahora hay una clase `internal_valid` y la lista negra solo se consulta para un
host que no es nuestro. Los hosts salen de `home_url()` y `site_url()` —nunca
de un dominio escrito en el código, porque este proyecto ya cambió de URL una
vez— más lo que agregue la opción o el filtro `aidocs_link_allowed_hosts`. El
emparejamiento cruza un nivel de subdominio en ambos sentidos, así que
producción y staging reconocen los enlaces del otro.

**Lo que deliberadamente NO se hizo.** `/app/uploads/` —donde el sitio viejo,
que es Bedrock, guarda realmente sus archivos— no se agregó a la lista negra.
194 de los 238 hipervínculos del corpus apuntan ahí, sacscoc.org sigue en
línea y esos enlaces resuelven hoy. Agregarlo los tiraría todos en la próxima
extracción: el mismo daño al revés. Si el cliente decide dejar de citar el
sitio viejo, el filtro `aidocs_keep_link` es el lugar.

---

## Archivos

| Archivo | Qué cambia |
| --- | --- |
| `assets/js/aidocs-link-markup.js` | **nuevo** — escapar etiqueta y codificar URL |
| `assets/js/aidocs-docx-numbering.js` | **nuevo** — `numbering.xml` desde el .docx |
| `assets/js/aidocs-docx-structure.js` | marcador real de Word; nivel real de Word |
| `assets/js/aidocs-pdf-structure.js` | `stripPageFurniture()` por recurrencia |
| `includes/aidocs-doc-parser.php` | gramática de enlaces; clasificador por host; esquema de listas; marcadores romanos y de tres dígitos |
| `ai-documents.php` | botón de enlace propio en EasyMDE; encolado de los módulos nuevos |
| `EXTRACTION_FORMAT.md` | numeración, esquemas, mobiliario de página, clases de enlace |
| `tests/mini-dom.js` | **nuevo** — DOM mínimo, extraído para compartirlo |
| `tests/test-link-markup.js` | **nuevo** — 9 casos |
| `tests/test-docx-numbering.js` | **nuevo** — 14 casos |
| `tests/test-pdf-furniture.js` | **nuevo** — 9 casos |
| `tests/test-lists.php` | **nuevo** — 12 casos |
| `tests/test-links.php` | 7 casos nuevos de corchetes; casos D, D2–D4 reescritos |

`php tests/run.php` — **148 pruebas, todas verdes** (94 antes de empezar).

### Pruebas existentes que cambiaron de expectativa

El caso D de `tests/test-links.php` afirmaba que `/wp-content/uploads/…` es un
enlace obsoleto, y el caso `unknown` que `/about/staff` se descarta. Afirmar
eso **era** el defecto que reportó Stuart. Ahora afirman lo contrario, a
propósito. Los fixtures que representan «un archivo del sitio anterior» pasaron
a nombrar un host ajeno (`https://oldsite.com/wp-content/uploads/old.pdf`),
porque una ruta a secas ya no lo es.

La clase `unknown` desapareció en lugar de quedar como sinónimo, para que nada
pueda seguir comprobándola en silencio.
