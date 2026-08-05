# AI Documents

Plugin de WordPress para gestionar, publicar y buscar documentos institucionales con búsqueda inteligente impulsada por IA (Google Gemini).

---

## Tabla de contenidos

1. [Requisitos](#requisitos)
2. [Instalación](#instalación)
3. [Configuración de la API key de Gemini](#configuración-de-la-api-key-de-gemini)
4. [Ajustes del plugin (Settings)](#ajustes-del-plugin-settings)
5. [Gestión de documentos](#gestión-de-documentos)
6. [Shortcode de búsqueda](#shortcode-de-búsqueda)
7. [Funcionalidades de IA](#funcionalidades-de-ia)
8. [Referencia de parámetros del shortcode](#referencia-de-parámetros-del-shortcode)

---

## Requisitos

- WordPress 6.0 o superior
- PHP 8.0 o superior
- Cuenta de Google con acceso a [Google AI Studio](https://aistudio.google.com/) (para la API key de Gemini)

---

## Instalación

1. Sube la carpeta `ai-documents` a `/wp-content/plugins/`.
2. Activa el plugin desde **WordPress Admin → Plugins → Plugins instalados**.
3. Ve a **Documents → Settings** en el menú lateral del administrador.
4. Configura tu API key de Gemini (ver sección siguiente).

---

## Configuración de la API key de Gemini

Las funciones de IA del plugin utilizan la API de **Google Gemini**. Para obtener una API key gratuita:

### Paso 1 — Crear una API key en Google AI Studio

1. Abre [https://aistudio.google.com/app/apikey](https://aistudio.google.com/app/apikey) en tu navegador.
2. Inicia sesión con tu cuenta de Google.
3. Haz clic en **"Create API key"**.
4. Selecciona un proyecto de Google Cloud existente o crea uno nuevo cuando se te pida.
5. Copia la API key generada (empieza con `AIza…`).

> **Nota:** La capa gratuita de Gemini incluye un límite generoso de solicitudes por minuto y por día, suficiente para uso institucional normal. Consulta [https://ai.google.dev/pricing](https://ai.google.dev/pricing) para ver los límites actualizados.

### Paso 2 — Ingresar la API key en el plugin

1. En WordPress, ve a **Documents → Settings → AI**.
2. Pega tu API key en el campo **"Gemini API Key"**.
3. Selecciona el modelo en **"Gemini Model"**:
   - `gemini-2.5-flash` — Recomendado: rápido y muy capaz (predeterminado)
   - `gemini-2.0-flash` — Alternativa más liviana
   - `gemini-1.5-pro` — Mayor capacidad de razonamiento, más lento
4. Haz clic en **"Save Settings"**.
5. Usa el botón **"Test Connection"** para verificar que la API key funciona correctamente.

---

## Ajustes del plugin (Settings)

El panel de ajustes (**Documents → Settings**) es una sola vista con tres secciones:

### AI
- **Gemini API Key** — Clave de la API de Google Gemini.
- **Gemini Model** — Modelo de IA a utilizar.

### Taxonomy
- **Audiences** — Lista de audiencias disponibles (una por línea). Predeterminado: `Institution`, `Evaluator`, `Public`.
- **Document Types** — Lista de tipos de documento disponibles (una por línea). Predeterminado: `Policies`, `Guidelines`, `Good Practices`, entre otros.

### Shortcodes
Referencia de todos los shortcodes disponibles con ejemplos listos para copiar.

> El nombre del menú (Documents), su ícono, el slug del archivo (`documents`) y los formatos de archivo permitidos (PDF, Word, Excel) ya no son configurables — están fijos.

---

## Gestión de documentos

### Crear un documento

1. Ve a **Documents → Add New Document** en el menú lateral.
2. Completa el formulario:

| Campo | Descripción |
|---|---|
| **Title** | Nombre del documento |
| **File** | Sube el archivo (PDF, Word, Excel, etc.) usando el selector de medios |
| **Publication Date** | Fecha de publicación del documento |
| **Audience** | Selecciona una o varias audiencias (checkboxes) |
| **Document Type** | Selecciona uno o varios tipos (checkboxes) |
| **Description** | Descripción del documento (texto libre) |

3. Haz clic en **"Publish"**.

### Extraer el contenido estructurado (por defecto, sin IA)

Al subir un PDF, el plugin extrae su texto y **automáticamente** parsea el cuerpo del documento con regex — sin llamar a la IA, sin necesidad de API key — y lo guarda como una secuencia de bloques (títulos de 3 niveles, párrafos, notas, listas anidadas y tablas) que el frontend renderiza como HTML. Título, teaser, fecha y esas notas se completan de una vez si el documento usa el esquema de etiquetas (ver abajo).

1. Sube el PDF — la extracción de texto y el parseo del contenido corren solos.
2. Debajo del archivo aparece **"Document content"**, con la cantidad de bloques guardados y un desplegable **"Review extracted content"** para revisar el resultado antes de publicar.
3. Si necesitás volver a correr el parser (por ejemplo tras editar el PDF), usá **"Extract content again"**.

El PDF se lee en dos pasos: `assets/js/aidocs-pdf-structure.js` convierte la maquetación del PDF (negrita, cuerpo, margen izquierdo, interlínea) en un **texto canónico** con marcas, y `includes/aidocs-doc-parser.php` lo convierte en bloques con expresiones regulares. El formato completo, las variantes de nota reconocidas y los límites conocidos están en [EXTRACTION_FORMAT.md](EXTRACTION_FORMAT.md).

#### Esquema de etiquetas

Si el documento fue redactado con el esquema de etiquetas de SACSCOC, la extracción es **determinística** (no heurística) y completa varios campos de una sola vez:

```
<Título del documento>
Teaser: <resumen de un párrafo>      → Description
Body:                                → Contenido estructurado
<párrafos y requisitos con viñetas>
Last Updated: <Mes AAAA> (<órgano>)  → Publication Date
Document History: <procedencia>      → historial, se muestra al pie del contenido
```

Cada marcador se acepta como `Label:`, `[Label]`, `[Label] contenido` o una línea que contenga sólo la etiqueta. Validado contra los 50 documentos del compilado maestro: 50/50 con título y cuerpo, 48/50 con teaser y fecha (los otros dos son fragmentos de un documento mayor y no traen esas líneas).

La descripción y la fecha **no se sobreescriben** si ya tienen valor — una corrección manual del editor gana sobre el parser.

> Los parsers viven en `includes/aidocs-doc-parser.php`: `aidocs_parse_labeled_document()` (esquema de etiquetas) y `aidocs_parse_structured_content()` (cuerpo → bloques, con fallback heurístico para texto pegado a mano). Son las únicas funciones a ajustar si aparece otra familia de documentos: el resto del plugin depende sólo del formato de bloques. Para verificar un cambio contra un corpus sin pasar por WordPress: `php tools/parse-check.php ruta/*.txt`.

### Completar campos con IA (opcional)

Debajo de la extracción, el panel colapsable **"Complete fields with AI (optional)"** propone valores para los campos que no vienen del esquema de etiquetas — típicamente `Audience` y `Document Type` — o para revisarlos con otro criterio:

1. Marcá los campos a proponer (`Title`, `Description`, `Audience`, `Document Type`).
2. Hacé clic en **"Propose with AI"**.
3. Si todavía no hay una API key de Gemini configurada, el panel la pide ahí mismo: pegá la clave, **"Check key & list models"** la valida y lista los modelos disponibles, elegí uno y **"Save"**. Sólo un administrador ve este formulario; se guarda en Documents → Settings y la usan también la búsqueda semántica y el asistente.
4. Cada campo propuesto aparece como una tarjeta con el valor actual al lado, editable antes de aplicar. **Nada se escribe en el formulario hasta que hacés clic en "Apply"** (o "Apply all") — "Discard" descarta la propuesta sin tocar nada.

Esto es independiente de la extracción: la extracción reconstruye el *cuerpo* del documento y completa los campos que el propio documento declara (teaser, fecha); la IA sólo entra si elegís pedirle una propuesta para los campos restantes, y siempre bajo revisión.

---

## Shortcode de búsqueda

Inserta el buscador de documentos en cualquier página o entrada usando el shortcode:

```
[aidocs_search]
```

### Funcionalidad del buscador

El buscador incluye:

- **Barra de búsqueda inteligente** — Escribe en cualquier idioma. Después de ~600 ms sin escribir, la IA analiza la consulta y muestra el documento más relevante con una explicación.
- **Autocompletado** — Mientras escribes aparece un menú desplegable con documentos que coinciden con las palabras clave.
- **Filtros** — Menú desplegable de Audience y Document Type para refinar resultados.
- **Botón limpiar (×)** — Borra el campo y recarga todos los documentos.
- **Botón Search** — Ejecuta la búsqueda tradicional de WordPress y muestra el listado completo de resultados.
- **Paginación** — Resultados paginados (20 por página por defecto).

### Modal de detalle

Al hacer clic en cualquier tarjeta de documento se abre un modal con dos pestañas:

- **Content** — primero los campos extraídos (descripción, audiencia, tipo, fecha, formato) y debajo el **contenido estructurado** del documento (títulos, párrafos y listas extraídos del PDF por regex). Se carga on-demand para que el listado de resultados siga liviano.
- **Preview** — el PDF embebido en el navegador.

**Ask AI** ya no es una pestaña: es una **barra fija** al pie del modal, disponible mientras se lee cualquier sección. Las respuestas aparecen en un panel colapsable sobre la barra.

El pie del modal tiene **Download**, **Open full page** (link a la vista individual) y **Close**.

### Vista individual del documento

Cada documento tiene su propia URL (`/documents/{slug}/`) que muestra lo mismo que el modal abierto, integrado con el header y footer del tema: encabezado con formato/audiencia/tipo, descripción, grilla de metadatos, contenido estructurado, preview del PDF en un bloque desplegable, y la barra Ask AI fija al pie mientras se hace scroll.

### Burbuja de chat AI (Ask AI)

El botón flotante **"Ask AI"** en la esquina inferior derecha abre un chat conversacional:
- Escribe tu pregunta en **cualquier idioma** (español, inglés, francés, etc.).
- La IA busca en el catálogo de documentos y te recomienda el más relevante, explicando por qué.
- Cada respuesta incluye una tarjeta del documento recomendado con botón de descarga y acceso al modal de detalle.
- Soporta conversación con contexto — puedes hacer preguntas de seguimiento.

**Ejemplo de uso:**
> *"¿Cómo puedo obtener créditos reducidos para título de pregrado?"*
> → La IA entiende la consulta y responde: *"Te recomiendo el documento 'Reglamento de Graduación' porque contiene los procedimientos para solicitar reducción de créditos en programas de pregrado."*

---

## Funcionalidades de IA

| Función | Dónde | Descripción |
|---|---|---|
| **Autocompletado** | Barra de búsqueda | Sugerencias rápidas mientras se escribe |
| **Recomendación AI** | Barra de búsqueda | Documento sugerido con explicación en cualquier idioma |
| **Chat Ask AI** | Botón flotante | Chat conversacional para encontrar documentos |
| **Process with AI** | Admin → Editar documento | Rellena metadatos analizando el PDF |

Todas las funciones de IA utilizan el modelo Gemini configurado en **Settings → AI** y responden siempre en el mismo idioma que usa el usuario.

---

## Referencia de parámetros del shortcode

```
[aidocs_search
  type="..."
  audience="..."
  per_page="20"
  show_ai="true"
  show_chat="true"
]
```

| Parámetro | Predeterminado | Descripción |
|---|---|---|
| `type` | *(vacío)* | Pre-selecciona un tipo de documento. También lee `?type=` de la URL. |
| `audience` | *(vacío)* | Pre-selecciona una audiencia. También lee `?audience=` de la URL. |
| `per_page` | `20` | Cantidad de resultados por página (máximo 50). |
| `show_ai` | `true` | `"false"` desactiva las sugerencias AI inline en la barra de búsqueda. |
| `show_chat` | `true` | `"false"` oculta la burbuja flotante de chat AI. |

### Ejemplos

```
[aidocs_search]

[aidocs_search type="Policies" audience="Institution"]

[aidocs_search per_page="10"]

[aidocs_search show_chat="false"]

[aidocs_search show_ai="false" show_chat="false"]
```
