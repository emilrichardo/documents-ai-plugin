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

**Reproducido con PDF reales de este sitio** (29/10). No es el archivo exacto
que usó el cliente, pero sí del mismo corpus y con el mismo mecanismo.

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

**Medido sobre los PDF de la biblioteca del sitio:**

| | resultado |
| --- | --- |
| `Reduced-Credit-Undergraduate-Degree-Guideline.pdf` (4 pág.) | quita los 4 números de página (`1 2 3 4`), línea base a **52,6 pt** del borde — bien fuera de la banda de 27,7 pt, que es por lo que llegaban a la página. Nada más. `Document History:` y `Revised:` **conservados**. |
| `R1-Summit-Fall-2025-Summary-Report.pdf` (19 pág.) | quita los 19 pies `SACSCOC R1 Summit Report – Page N`. Nada más. |
| `24-Credit-Hours.pdf` (5 pág.) | **no toca nada** — no tiene mobiliario de página. `Last Updated:`, `Document History:`, `Edited:` y `Revised:` intactos. |

El tercero es el que importa tanto como los dos primeros: es la comprobación
de que el detector no inventa mobiliario donde no lo hay.

La geometría de línea de dos de ellos quedó congelada en
`tests/fixtures/pdf-pages.json` como prueba de regresión.

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

## 5. 1.6.2 — listas en PDF y números de página en PDF cortos

Hallado con el PDF que subió el cliente para «Antitrust Compliance» (3 páginas).

- **Ítems 10 a 15 pegados en un párrafo.** El importador reconoce un número de
  lista por el hueco que lo separa del texto. «10.» es más ancho que «9.» y la
  tabulación está a la misma distancia del margen, así que su hueco (4,2 pt) caía
  por debajo del mínimo (0,45 em). Para números de dos dígitos el mínimo es ahora
  0,3 em; un espacio normal entre palabras mide 0,22 em.
- **«A.» como fila de tabla.** «A.» en el margen con el texto 36 pt más allá
  superaba el hueco de columna y el marcador contaba como celda. Ya no.
- **PDF de 1 o 2 páginas.** Sin páginas entre las que comparar no hay
  recurrencia, así que solo se quita la primera o la última línea de la página si
  es *únicamente* un número de página («3», «Page 3», «Page 3 of 71», «- 3 -») y
  está separada del texto. Un número pegado al texto, «Appendix 1», «10%» o una
  página que sea solo un número se conservan. Riesgo residual: un número suelto
  real, aislado al borde de la página, se tomaría por folio.

Pruebas: `tests/test-pdf-markers.js` (nuevo, 4 casos) y 4 casos nuevos en
`tests/test-pdf-furniture.js`. Comparado antes/después sobre 40 PDF locales:
solo cambian el del cliente y una política de una página que terminaba en «1».

## 6. 1.6.3 — membrete de la página 1 en los PDF

Pedido del cliente: el membrete (nombre de la organización y dirección postal,
centrados sobre el título) no debe llegar al texto importado. Antes salía como
un párrafo en cursiva delante del título.

`stripLetterhead()` lo reconoce por la dirección, no por quién sea: una línea de
calle (empieza por número) justo encima de una línea «Ciudad, Estado ZIP», entre
las primeras 5 líneas de la página 1 y seguida de un hueco mayor que el de un
párrafo. Se quita desde el principio de la página hasta la línea del ZIP, porque
el nombre de arriba es parte del mismo bloque. Solo la página 1.

Efecto lateral esperado: el título pasa a ser lo primero del texto y sale como
`#` (título) en lugar de `##`, igual que en los PDF que no tienen membrete.

Riesgos: un documento que abra con una dirección postal por otro motivo (una
carta) la pierde; y una plantilla con un membrete sin dirección postal no se
limpia. Pruebas: `tests/test-pdf-letterhead.js` (7 casos). Comprobado con los dos
PDF de SACSCOC que hay en local; en 40 PDF locales solo cambian esos.

## 7. 1.6.4 — los cambios sin aplicar ya no se pierden al guardar

Reporte del cliente: «añadí un enlace y no funciona».

- **Causa confirmada en staging.** El texto de «Edit content» solo se guarda con
  el botón «Apply edited content» (petición AJAX): el `textarea` no tiene `name`,
  así que el formulario nunca lo envía. Update, Publish y Save Draft lo dejan
  atrás. La pestaña Preview dibuja lo que hay escrito, aplicado o no, de modo
  que el enlace se ve funcionando y luego desaparece al guardar (el borrador de
  prueba volvió vacío, «No content»).
- **Ahora.** Si al enviar el formulario el texto de la caja difiere del último
  aplicado, el envío espera a la petición de «Apply» y sigue solo si el servidor
  la aceptó. Si falla, el editor se queda en la página con su texto y el error a
  la vista, en lugar de guardar sin él. Se reenvía con el mismo botón
  (`requestSubmit`), así que Publish sigue siendo Publish.
- **Direcciones sin esquema.** `commissioncu.org/policies/x/` pasa a
  `https://commissioncu.org/policies/x/`. Una ruta como `policies/credit-hours`
  (sin `https://` ni `/` inicial) se rechaza con un aviso: `esc_url()` de
  WordPress la convertía en `http://policies/credit-hours`, un enlace muerto.

Sin probar en navegador al escribir esto (el sitio local no estaba en marcha);
el comportamiento del formulario se comprueba en staging. Pruebas:
`tests/test-link-markup.js` (+4 casos para las direcciones).

## 8. 1.6.5 — lo que cuelga de «A.» vuelve a quedar dentro de «A.»

Reporte del cliente (Antitrust Compliance): la segunda lista numerada, y el
párrafo que la introduce, salían alineados con «A.» y «B.» y deben quedar bajo
«A.», igual que la primera.

Mi comprobación anterior de esta política contó cuatro listas y no miró dónde
estaba cada una; por eso dije que estaba bien cuando no lo estaba.

- **Qué pasaba.** El Word no dice que ese párrafo cuelgue de «A.»: es un párrafo
  normal y «A.» es texto escrito. El PDF solo lo dice con la sangría. Al
  analizar, A terminaba en su primera sub-lista y el párrafo y la segunda
  sub-lista quedaban sueltos, a nivel de A y B.
- **Ahora.** `aidocs_fold_outline_interludes()` mete lo que hay entre dos
  elementos con letra dentro del primero, y une las dos listas con letras en
  una. Solo cuando hay una sub-lista de rango inferior entre medias **y** la
  letra siguiente continúa la cuenta (B tras A). Un párrafo suelto entre dos
  letras, un encabezado, una nota o un procedimiento numerado con dígitos no
  se tocan.
- **Qué no cambia solo.** Las políticas ya publicadas guardan sus bloques ya
  analizados: hay que volver a pulsar «Apply edited content» para que la
  política tome la estructura nueva.
- **No medido.** No pude medir cuántas de las 71 políticas tienen esta forma ni
  cuáles cambiarían al reaplicarse; la regla se ha estrechado a propósito para
  limitar ese riesgo.

Pruebas: la prueba «The reported case, end to end» de `tests/test-lists.php`
cambió de expectativa (era exactamente el comportamiento reportado) y hay 4
casos nuevos. Con el PDF real del cliente: lista A/B con las dos sub-listas a
profundidad 2 y el párrafo dentro del `li` de A.

## 9. 1.6.6 — Substantive Change: restos de imagen en el Word

Reporte del cliente: la sección «Procedures» de Substantive Change importada
«parece un desastre» frente al Word original.

Comparado el texto guardado en staging con una extracción nueva del mismo Word:

- **El texto guardado era de una importación antigua**, no del importador
  actual: 210 líneas con letras publicadas como dígitos, 45 con números romanos
  publicados como dígitos y 160 numeradas publicadas como viñetas (de ahí que
  «2. A committee visit» y «3. A fee» salgan como viñetas y «4.» como «1.»), y
  ningún enlace del cuerpo (205 líneas difieren más allá del marcador). El
  importador actual lee bien ese Word: `1.`, `2.`/`3.` (`start="2"`) y `4.`
  (`start="4"`), intercalados con las viñetas igual que en Word.
- **Lo único que el importador actual hace mal en ese Word:** el archivo trae
  escrito a mano, como texto, `{width="2.5…in" height="2.8…in"}` (resto de una
  conversión con pandoc) en dos sitios, y llegaba al texto
  («1. {width=…}The change requires either»). `tidy()` ya lo quita; solo con
  ese patrón exacto (`width=`/`height=` entre comillas dentro de llaves), no con
  cualquier llave.
- **Los enlaces a `sacscoc.org/app/uploads/…` siguen sin enlazarse**: son medios
  del sitio antiguo y `aidocs_classify_link()` los descarta a propósito. Hay
  siete en la lista «Policies» de ese documento; se rehacen a mano con el botón
  de enlace apuntando a la política correspondiente del sitio nuevo.

Pruebas: 2 casos nuevos en `tests/test-docx-links.js`.

## 10. 1.6.7 — enlazar en un texto largo sin volver a la barra

Reporte del cliente: resaltar texto, pulsar el icono de enlace, poner un enlace
interno y publicar tampoco funcionaba, y en bloques largos hay que subir hasta
el icono.

- **Publicar con un enlace nuevo** es el fallo de 1.6.4 (el texto sin aplicar no
  se guardaba). Comprobado en staging con esos pasos exactos y un enlace
  interno `/policies/credit-hours/`: Publish publica, el enlace queda en el
  editor y en la página, y la dirección responde 200.
- **La barra de herramientas se queda pegada** bajo la barra de WordPress
  (`position: sticky`). El editor crece hasta alojar todo el texto (205 414 px
  en Substantive Change), así que la barra estaba a miles de píxeles de la frase
  que se edita. Para que `sticky` funcione, `#cd-extract-wrap` y el contenedor
  de EasyMDE pasan de `overflow:hidden` a `overflow:clip`: `hidden` crea un
  contenedor de scroll y la barra se pegaba a una caja que nunca se desplaza.
  `hidden` se mantiene como respaldo en navegadores sin `clip`; ahí la barra
  simplemente no se pega.
- **Ctrl/Cmd+K inserta el enlace.** EasyMDE ya enlazaba `Cmd-K` a su propio
  `drawLink`, que escribe `[texto](#url#)` sin ninguno de los escapes del botón
  de este plugin. Se desactiva ese atajo y se enlaza el mismo código del botón,
  así que atajo y botón hacen exactamente lo mismo.

Pruebas: el comportamiento es de navegador; se comprueba en staging.

## 11. 1.6.8 — caracteres raros, línea h4 enorme y tipo de bloque en el editor

Tres observaciones del cliente sobre Substantive Change.

- **Caracteres raros (cuadrito) en el texto y en un encabezado del acordeón.**
  Están en el propio Word: 161 caracteres de uso privado (U+E000–U+F8FF), que son
  los códigos de una fuente de iconos (Wingdings/Symbol) y sin esa fuente se
  ven como un cuadro vacío. U+F0FE (29) va delante de «What to submit»; U+F06E
  (57) y U+F098 (75) están solos en un párrafo. Una de esas líneas está en
  negrita y la regla «una línea en negrita de punta a punta es un encabezado»
  la convertía en una sección del acordeón titulada con un cuadro. No he podido
  confirmar a qué fuente pertenecen (las ejecuciones no la nombran) ni qué dibujo
  tenían; son decorativos. Ahora se descartan al extraer (`ICON_GLYPH_RE`) y,
  como red de seguridad, en `aidocs_normalize_lines()` — así también se limpian
  textos ya guardados al volver a aplicarlos y textos pegados. Un párrafo que
  era solo un glifo desaparece. Los demás símbolos (•, ✓, comillas…) no se tocan.
- **La línea h4 era enorme (y el cursor también).** El CSS que oculta los `####`
  los metía en una caja de ancho cero; allí cada `#` saltaba a su propia línea
  (CodeMirror pone `word-wrap: break-word`), así que un h4 medía cuatro líneas y
  un h3 tres. Con `white-space: nowrap` la línea mide lo que su texto: h3 71→27 px,
  h4 81→25 px y el cursor 81→25 px, medido en la página real de staging.
- **El editor no decía qué es cada línea.** Un párrafo y un h4 se ven parecidos y
  ninguno lleva marca. La barra tiene ahora un selector «tipo de bloque»
  (Párrafo, Título, Heading 2/3/4) que muestra el de la línea bajo el cursor y
  permite cambiarlo; sustituye a los botones H2/H3. Una línea de lista o de
  tabla muestra su tipo y no se cambia (perdería su marcador), y una línea en
  negrita avisa de que se lee como Heading 3. La lógica está en
  `assets/js/aidocs-block-type.js`, sin DOM, con `tests/test-block-type.js`.

Pruebas: 3 casos nuevos en `tests/test-docx-links.js`, 1 en `tests/test-lists.php`
y 7 en `tests/test-block-type.js`. De camino, los casos añadidos a
`test-docx-links.js` en 1.6.6 quedaron tras el bloque que imprime resultados y no
se mostraban uno a uno (sí contaban en el total); corregido.

## Verificado en el navegador (29/10)

Todo lo anterior se probó además en el sitio local `cirlot.local`, que lleva
la copia de producción del 10/09: 65 políticas, el plugin activo, los mismos
plugins de producción. El plugin está symlinkeado al repo, así que lo que
corrió fue el código de la rama, no un paquete.

**1 — Enlaces con corchetes.** En el editor real de *Antitrust Compliance*
(post 13315), con el texto `Credit Hours [PDF]` y la selección puesta sobre
`[PDF]`, un clic real en el botón «Insert link» produjo

    Credit Hours [\[PDF\]](https://stage.commissioncu.org/document/credit-hours/)

en el editor y en el textarea espejo. La pestaña **Preview** —que reparsea en
el servidor— lo renderiza como `<a href="https://stage.commissioncu.org/document/credit-hours/">[PDF]</a>`.
Los corchetes siguen siendo texto visible.

**2 — Numeración DOCX.** `DecompressionStream` existe en este navegador y
`AidocsDocxNumbering.read()` lee `numbering.xml` de la compilación de 317 KB
en **66 ms**, con 1.215 entradas en el índice. Los resultados son idénticos a
los de Node: alfabéticos 13 → 337, romanos 0 → 45, decimales 1.048 → 679,
mismo número de líneas, **texto visible idéntico**.

**3 — Mobiliario de página.** Con pdf.js real sobre
`Reduced-Credit-Undergraduate-Degree-Guideline.pdf`:

| | |
| --- | --- |
| banda inferior | 27,7 pt |
| línea base de los números de página | **52,6 pt** |
| sin el arreglo | los cuatro llegan al contenido (`pageLines` los devuelve) |
| con el arreglo | se quitan exactamente esos cuatro |
| `**Document History**` al pie de la última página | **conservado** |

Esa es la cadena completa: la banda nunca los alcanzó, que es exactamente lo
que se había diagnosticado, y ahora medido en el navegador en vez de deducido.

Nada se guardó: la política 13315 conserva su `post_modified` del 28/08 y su
contenido original. Los dos ajustes temporales que hizo falta hacer en el
sitio local —dos `define()` en `wp-config.php` para el puerto, y un mu-plugin
de acceso— se revirtieron.

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
| `tests/test-pdf-furniture.js` | **nuevo** — 11 casos, 2 contra PDF reales |
| `tests/fixtures/pdf-pages.json` | **nuevo** — geometría real de dos PDF del sitio |
| `tests/test-lists.php` | **nuevo** — 12 casos |
| `tests/test-links.php` | 7 casos nuevos de corchetes; casos D, D2–D4 reescritos |

`php tests/run.php` — **150 pruebas, todas verdes** (94 antes de empezar).

### Pruebas existentes que cambiaron de expectativa

El caso D de `tests/test-links.php` afirmaba que `/wp-content/uploads/…` es un
enlace obsoleto, y el caso `unknown` que `/about/staff` se descarta. Afirmar
eso **era** el defecto que reportó Stuart. Ahora afirman lo contrario, a
propósito. Los fixtures que representan «un archivo del sitio anterior» pasaron
a nombrar un host ajeno (`https://oldsite.com/wp-content/uploads/old.pdf`),
porque una ruta a secas ya no lo es.

La clase `unknown` desapareció en lugar de quedar como sinónimo, para que nada
pueda seguir comprobándola en silencio.
