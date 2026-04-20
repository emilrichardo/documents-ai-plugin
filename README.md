# Cirlot Documents

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
8. [Campos personalizados](#campos-personalizados)
9. [Referencia de parámetros del shortcode](#referencia-de-parámetros-del-shortcode)

---

## Requisitos

- WordPress 6.0 o superior
- PHP 8.0 o superior
- Cuenta de Google con acceso a [Google AI Studio](https://aistudio.google.com/) (para la API key de Gemini)

---

## Instalación

1. Sube la carpeta `cirlot-documents` a `/wp-content/plugins/`.
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

El panel de ajustes está organizado en cinco pestañas:

### General
- **Archive Slug** — Slug de la URL del archivo de documentos (predeterminado: `documents`).

### AI
- **Gemini API Key** — Clave de la API de Google Gemini.
- **Gemini Model** — Modelo de IA a utilizar.
- **Test Connection** — Verifica que la API key y el modelo sean válidos.

### Taxonomy
- **Audiences** — Lista de audiencias disponibles (una por línea). Predeterminado: `Institution`, `Evaluator`, `Public`.
- **Document Types** — Lista de tipos de documento disponibles (una por línea). Predeterminado: `Policies`, `Guidelines`, `Good Practices`, entre otros.

### Custom Fields
Define los campos personalizados globales que aparecen en todos los documentos:
- El campo **Document Description** está incluido por defecto.
- Puedes agregar nuevos campos con nombre, tipo (`text`, `textarea`, `list`) e identificador.
- Los campos se pueden reordenar arrastrando y eliminar con el botón `×`.
- Estos campos aparecen en el formulario de edición de cada documento y en el modal de detalle de la búsqueda pública.

### Shortcodes
Referencia de todos los shortcodes disponibles con ejemplos listos para copiar.

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
| **Custom Fields** | Campos personalizados definidos en Settings → Custom Fields |

3. Haz clic en **"Publish"**.

### Completar campos con IA

En el formulario de edición, el bloque **"Process with AI"** permite autocompletar los metadatos del documento:

1. Sube el archivo PDF primero — el plugin extrae el texto automáticamente.
2. Selecciona los campos que deseas completar con los checkboxes (ej. `Document Description`, `Audience`, `Document Type`).
3. Haz clic en **"Process with AI"**.
4. La IA analiza el contenido del PDF y sugiere valores para cada campo seleccionado.
5. Revisa y ajusta los valores antes de guardar.

> **Nota:** Esta función requiere que la API key de Gemini esté configurada correctamente.

---

## Shortcode de búsqueda

Inserta el buscador de documentos en cualquier página o entrada usando el shortcode:

```
[cirlot_document_search]
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

Al hacer clic en cualquier tarjeta de documento se abre un modal con:
- Nombre del documento y etiquetas de formato, audiencia y tipo.
- Todos los campos personalizados definidos en Settings.
- Fecha de publicación.
- Botón de **Download** para descargar el archivo directamente.

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

## Campos personalizados

Los campos personalizados son **globales** — se definen una sola vez en **Settings → Custom Fields** y aparecen en todos los documentos.

### Tipos de campo disponibles

| Tipo | Descripción |
|---|---|
| `textarea` | Área de texto multilínea (ideal para descripciones) |
| `text` | Campo de texto de una sola línea |
| `list` | Área de texto para listas (mayor altura) |

### Campo predeterminado

El campo **Document Description** (`id: description`) está incluido por defecto. Si se elimina y se vuelve a crear con el mismo `id`, los valores guardados se recuperan automáticamente.

### Cómo agregar un campo

1. Ve a **Documents → Settings → Custom Fields**.
2. Completa **Field Label** (nombre visible), **Field ID** (identificador único, solo letras/números/guión bajo) y **Type**.
3. Haz clic en **"Add Field"**.
4. Guarda con **"Save Settings"**.

El nuevo campo aparecerá inmediatamente en el formulario de edición de todos los documentos y en el modal de detalle del buscador.

---

## Referencia de parámetros del shortcode

```
[cirlot_document_search
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
[cirlot_document_search]

[cirlot_document_search type="Policies" audience="Institution"]

[cirlot_document_search per_page="10"]

[cirlot_document_search show_chat="false"]

[cirlot_document_search show_ai="false" show_chat="false"]
```
