# Estructura de extracción de documentos

Cómo se convierte un PDF de política en contenido estructurado **sin IA**: sólo
posición/estilo del PDF + expresiones regulares.

Piezas:

| Archivo | Rol |
| --- | --- |
| `assets/js/aidocs-pdf-structure.js` | PDF → **texto canónico** (pdf.js, en el editor) |
| `includes/aidocs-doc-parser.php` | texto canónico → **bloques** (regex) + render HTML |
| `tools/parse-check.php` | verificación por CLI sobre un corpus de textos |

## 1. Por qué dos pasos

Los documentos se escriben en Word y se exportan a PDF, así que cada decisión de
estructura del autor sobrevive **como maquetación**: la negrita de los títulos,
el cuerpo tipográfico (algunos usan 14/16 pt en secciones mayores), el margen
izquierdo (cada nivel de lista entra un paso más) y el espacio vertical (un
párrafo nuevo lleva aire; una línea continuada, no).

La capa de texto plano de un PDF descarta todo eso: quedan líneas cortadas a
~85 caracteres, sin distinguir un título de una frase corta. Por eso el paso 1
**vuelve a escribir esos hechos de maquetación dentro del texto** como marcas
que el paso 2 reconoce con regex, en lugar de adivinarlas.

Señales que usa el paso 1 (todas medibles, ninguna heurística de contenido):

- **Peso y estilo real de la fuente** — `page.commonObjs.get(fontId).name`
  devuelve el nombre embebido (`Caladea-Bold`, `Caladea-Italic`). `getTextContent()`
  por sí solo sólo informa `sans-serif`, de ahí que antes no hubiera negritas.
- **Cuerpo** — 15 pt o más ⇒ título de nivel 2.
- **Margen izquierdo** — el mínimo del documento es el margen de títulos; el
  cuerpo suele ir 24 pt adentro; cada `x` distinto de viñeta es un nivel.
- **Interlínea** — mediana de la página; un salto mayor a 1,35× abre párrafo.
- **Huecos horizontales** — más de 1,8× el cuerpo dentro de una línea ⇒ celdas
  de tabla.

## 2. Texto canónico (formato intermedio)

Una línea = un bloque. Los párrafos **nunca** van cortados.

```
# Título del documento
Teaser: resumen de un párrafo
Body:
## SECCIÓN EN MAYÚSCULAS        título nivel 2 (mayúsculas o ≥15 pt)
### Sección                     título nivel 3 (negrita, al margen)
#### Subsección                 título nivel 4 (negrita, indentado)
Un párrafo completo en una sola línea.
1. ítem ordenado                dos espacios de sangría por nivel
  a. ítem anidado
    i. ítem más profundo
- ítem con viñeta
  párrafo adicional del ítem anterior (indentado, sin marcador)
| celda | celda |               fila de tabla
**negrita** y *cursiva* en línea
Last Updated: June 2026 (Board of Trustees)
Document History: Adopted … · Revised …
```

Detalles que resuelve este paso:

- **Marcadores separados** — Word emite `1.` y su texto como ítems distintos con
  una tabulación; se detecta el marcador por el hueco y se conserva su valor
  (`3.`, `c.`, `iv.`), no se renumera.
- **Palabras cortadas** — `non-` + `refundable` se unen sin espacio.
- **Saltos de página** — no hay hueco vertical que medir; se continúa el bloque
  si la línea anterior no cerró la frase.
- **Énfasis partido en dos líneas** — `*Principles of* *Accreditation*` se
  suelda en un solo tramo.
- **Bandas de encabezado/pie** — se recorta sólo el 3,5 % superior e inferior:
  `Last Updated:` y `Document History:` viven al pie de la última página.

El texto canónico es lo que se ve en el modal “Page N” del editor y lo que se
manda al parser y (en el flujo con IA) al modelo.

## 3. Bloques (formato de almacenamiento)

`aidocs_parse_labeled_document()` devuelve:

```php
[
  'title'            => 'Dues, Fees, and Expenses',
  'teaser'           => 'The full schedule of institutional dues…',
  'last_updated'     => 'June 2026 (Board of Trustees)',   // → fecha con aidocs_normalize_doc_date()
  'document_history' => 'Approved: … · Revised: …',
  'labeled'          => true,      // el autor marcó Teaser:/Body:
  'blocks'           => [ … ],     // guardado en el meta _document_content
]
```

Bloques (JSON en `_document_content`):

```php
[ 'type' => 'heading',   'level' => 2|3|4, 'text' => …, 'runs' => [], 'id' => 'sec-…', 'note' => '' ]
[ 'type' => 'paragraph', 'text' => …, 'runs' => [] ]
[ 'type' => 'note',      'variant' => …, 'label' => 'Note', 'text' => …, 'runs' => [] ]
[ 'type' => 'list',      'ordered' => true, 'style' => 'decimal', 'start' => 3, 'items' => [
      [ 'text' => …, 'runs' => [], 'blocks' => [ … ] ]   // sublistas y párrafos del ítem
  ] ]
[ 'type' => 'table',     'head' => [ … ], 'rows' => [ [ ['text'=>…,'runs'=>[]], … ] ] ]
```

- `text` es siempre texto plano: la indexación de búsqueda y el contexto de IA
  (`aidocs_blocks_plain_text()`) no necesitan saber de `runs`.
- `runs` es `[ ['text'=>…, 'b'=>1, 'i'=>1], … ]` y sólo aparece cuando el
  original traía énfasis.
- `style` de lista: `decimal`, `lower-alpha`, `upper-alpha`, `lower-roman`,
  `upper-roman`, `bullet`. `start` conserva la numeración cuando un párrafo
  interrumpe la lista (los procedimientos numeran 1…12 de corrido).

### Notas

Etiquetas reconocidas (`AIDOCS_NOTE_PATTERNS`), y su `variant`:

| Etiqueta en el documento | variant |
| --- | --- |
| `Note to International Institutions` | `international` |
| `Note: Substantive Change` | `substantive-change` |
| `Note: Institutional Contingency Teach-Out Plan` | `teach-out` |
| `SUBSTANTIVE CHANGE RESTRICTION:` | `restriction` |
| `Reminder:` / `Exception:` / `Example:` | `reminder` / `exception` / `example` |
| `Important:` / `Caution:` / `Warning:` | `important` |
| `Note:` / `Notes:` / `Note for …:` | `note` |

Se distinguen dos formas, porque el documento las escribe distinto:

1. **Nota en línea** — la etiqueta abre un párrafo (`Note: An application which
   fails…`). Su extensión es exacta, así que se renderiza como recuadro
   (`<div class="aidocs-note aidocs-note--…">`).
2. **Sección de nota** — la etiqueta está sola en su línea, en negrita, igual
   que un título de sección. El PDF **no da ninguna señal de dónde termina**: los
   párrafos que siguen están maquetados igual que los del cuerpo que vienen
   después. Por eso se marca el título con `note` (`<h3 class="… aidocs-note-heading
   aidocs-note-heading--substantive-change">`) y el contenido queda en el orden
   de lectura del autor, en lugar de encerrar una extensión inventada.

### Enumeraciones en línea

Cuando el PDF pierde los saltos de línea de una enumeración —
`A. Denial of Candidacy B. Removal from Candidacy C. Denial of Initial Membership` —
se reconstruye la lista **sólo si los marcadores van en secuencia** desde el
primero (A, B, C… / I, II, III… / 1, 2, 3…). Esa condición es la que evita
convertir en lista iniciales (`Ms. A. Berger`), abreviaturas (`U.S. Department`)
o referencias. Un `[Título]` al inicio del ítem pasa a ser su entradilla en
negrita.

### Eco del título

Casi todos los documentos repiten su título dentro del cuerpo, en mayúsculas y
partido en dos o tres líneas (`THE APPEALS PROCEDURES` / `OF THE COLLEGE
DELEGATE ASSEMBLY`). Se vuelven a unir, se comparan con el título y se descartan:
la página ya lo muestra como encabezado.

## 4. Texto pegado a mano

Un texto sin marcas (`#`, sangrías) se detecta con `aidocs_text_is_annotated()`
y se procesa con las reglas de siempre: párrafos separados por línea en blanco,
viñetas por glifo, y títulos por `aidocs_detect_heading()` (mayúsculas o línea
corta en Title Case sin puntuación final). Los mismos bloques salen por el otro
lado, así que el render y el guardado no cambian.

## 5. Verificación

```bash
php tools/parse-check.php ruta/*.txt            # censo de bloques + avisos
php tools/parse-check.php --fields ruta/*.txt   # título, teaser, fecha, historia
php tools/parse-check.php --blocks doc.txt      # árbol de bloques en JSON
php tools/parse-check.php --html doc.txt        # HTML renderizado
```

Los avisos (`⚠`) no son errores del parser sino cosas que conviene mirar: sin
título, sin teaser, sin `Last Updated`, fecha no interpretable, o una etiqueta
de sección que quedó dentro del cuerpo.

Resultado sobre el corpus de 50 políticas (`Documentos_Politicas_divididos`):
50/50 con título y cuerpo, 48/50 con teaser y fecha (49 y 50 son fragmentos de
un documento mayor y no traen esas líneas), 30 títulos nivel 2, 296 nivel 3,
29 nivel 4, 10 secciones de nota, 25 notas en recuadro, 346 listas con 1 449
ítems hasta 4 niveles y 35 tablas.

## 6. Límites conocidos

- **Tablas sin líneas de rejilla** se reconstruyen por huecos horizontales. En
  las páginas con encabezados de columna girados (apéndice de tipos de cambio
  sustantivo) el texto sale desordenado dentro de la tabla; se conserva como
  filas, no se pierde.
- **Cabecera de tabla**: sólo se marca `<th>` si la fila entera venía en negrita;
  en caso contrario todas las filas son `<td>`.
- **PDF escaneado** (sin capa de texto) no produce nada: no hay OCR.
- **Fuentes sin nombre embebido**: si `commonObjs` no expone el nombre, se cae a
  las señales de margen e interlínea, y los títulos que no estén outdentados
  pasan a párrafo.
- **"The Commission"** es la única excepción de mayúsculas mixtas del corpus (el
  nombre de la organización, sustituido con case fijo incluso dentro de un
  título en mayúsculas: "AND ACTIONS OF The Commission"). Se ignora al medir
  si una línea está en mayúsculas — `isAllCaps()` en el JS, `aidocs_comparable()`
  y `aidocs_reads_like_heading()` en el parser. Otro reemplazo de marca con el
  mismo patrón necesitaría la misma excepción.
- **Enumeraciones en línea anidadas**: `aidocs_split_inline_enumeration()`
  reconstruye "1. [Título]… 2. [Título]…" pegados en una sola línea canónica,
  pero se detiene en el primer número que rompe la secuencia — no busca más
  allá — para no confundir la numeración de una sub-lista anidada (que
  reinicia en 1) con la continuación de la lista exterior. El resultado es
  conservador: en el peor caso no reconstruye (deja el texto como un solo
  ítem, igual que antes), nunca corta un ítem en el lugar equivocado.
- Cuando estos fixes cambian cómo se **parsea** el texto (no cómo se
  *renderiza* lo ya guardado), un documento ya extraído con una versión previa
  del parser no los refleja hasta volver a extraerlo — el botón "Extract
  content again" en el editor, o subir el PDF de nuevo.
