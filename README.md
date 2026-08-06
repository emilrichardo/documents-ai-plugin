# AI Documents

A WordPress plugin for publishing and searching institutional documents. PDF
content is extracted and structured automatically with regular expressions —
no AI required for that part — and Google Gemini is available as an
opt-in layer for metadata suggestions, re-structuring a misread PDF, and
semantic search.

---

## Table of contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Configuring the Gemini API key](#configuring-the-gemini-api-key)
4. [Settings](#settings)
5. [Managing documents](#managing-documents)
6. [Search shortcode](#search-shortcode)
7. [AI features](#ai-features)
8. [Shortcode parameter reference](#shortcode-parameter-reference)

---

## Requirements

- WordPress 6.0 or later
- PHP 8.0 or later
- A Google account with access to [Google AI Studio](https://aistudio.google.com/) — only needed for the AI features; extraction and basic search work without one.

---

## Installation

1. Upload the `ai-documents` folder to `/wp-content/plugins/`.
2. Activate the plugin from **WordPress Admin → Plugins → Installed Plugins**.
3. Go to **Documents → Settings** in the admin sidebar.
4. Optionally configure a Gemini API key (see the next section) to enable the AI features.

---

## Configuring the Gemini API key

Every AI feature in this plugin — metadata suggestions, content restructuring, and semantic search — uses **Google Gemini**. To get a free API key:

### Step 1 — Create a key in Google AI Studio

1. Open [https://aistudio.google.com/app/apikey](https://aistudio.google.com/app/apikey).
2. Sign in with your Google account.
3. Click **"Create API key"**.
4. Pick an existing Google Cloud project, or create one when prompted.
5. Copy the generated key (starts with `AIza…`).

> **Note:** Gemini's free tier includes a generous per-minute and per-day request limit — enough for normal institutional use. See [https://ai.google.dev/pricing](https://ai.google.dev/pricing) for current limits.

### Step 2 — Enter the key in the plugin

You can do this from either place — they write to the same setting:

- **Documents → Settings → AI**: paste the key, pick a model, click **"Save Settings"**.
- **Any document's editor**, under "Complete fields with AI (optional)": paste the key, click **"Check key & list models"** to validate it and list what it can reach, choose a model, click **"Save"**. This form only shows to administrators; anyone else editing a document without a key configured sees a note asking them to get one from an admin, since extraction above it works without one either way.

The model list ships with the current Gemini lineup (3.6/3.5/3.1 Flash and Pro, down to 2.5). **"Refresh from API"** / **"Check key & list models"** replaces it with exactly what your key can reach — image, text-to-speech, and other non-chat models are filtered out automatically, since Gemini's own model-listing endpoint mixes every product line together with nothing that tells them apart from the id alone.

---

## Settings

**Documents → Settings** is one page with four sections:

### Display
- **URL Slug** — the segment documents live under: `/{slug}/` for the listing, `/{slug}/{document}/` for each one. Changing it moves both together and updates permalinks immediately.
- **Listing Template** — "Document search" (this plugin's search UI, the default) or "Theme default" (leaves `/{slug}/` to whatever your active theme would otherwise show there — normally a bare title-and-excerpt list).
- **Document Page Template** — "Structured view" (this plugin's extracted content, download button, and Ask AI bar, the default) or "Theme default" (the theme's own single-post template, untouched).

### AI
- **Gemini API Key** and **Gemini Model** — see the previous section.

### Taxonomy
- **Audiences** — one per line. Defaults to `Institution`, `Evaluator`, `Public`.
- **Document Types** — one per line. Defaults to `Policies`, `Guidelines`, `Good Practices`, and others.

### Shortcodes
A reference of every shortcode parameter with copy-to-clipboard examples.

---

## Managing documents

### Creating a document

1. Go to **Documents → Add New Document** in the admin sidebar.
2. Fill in the form:

| Field | Description |
|---|---|
| **Title** | Document name |
| **File** | Upload a file (PDF, Word, Excel) through the media picker |
| **Publication Date** | The document's publication date |
| **Audience** | One or more audiences (checkboxes) |
| **Document Type** | One or more types (checkboxes) |
| **Description** | Free-text description |

3. Click **"Publish"**.

### Extracting structured content (default, no AI)

Uploading a PDF extracts its text and **automatically** parses the document body with regular expressions — no AI call, no API key required — storing it as a sequence of blocks (three heading levels, paragraphs, notes, nested lists, tables) that the frontend renders as HTML. When the document follows the labelled schema described below, the title, teaser, publication date, and revision history are filled in at the same time.

1. Upload the PDF — text extraction and content parsing run on their own.
2. Below the file, **"Document content"** shows how many blocks were saved and a **"Review extracted content"** panel to check the result before publishing.
3. To re-run the parser — after editing the source PDF, for instance — use **"Extract content again"**.

The PDF is read in two steps: `assets/js/aidocs-pdf-structure.js` turns the PDF's own layout (bold weight, point size, left margin, line spacing) into **canonical text** carrying that structure as markers, and `includes/aidocs-doc-parser.php` turns that text into blocks with regular expressions. The full format, recognised note variants, and known limitations are documented in [EXTRACTION_FORMAT.md](EXTRACTION_FORMAT.md).

#### Labelled schema

When a document is authored with the SACSCOC label schema, extraction is **deterministic**, not heuristic, and fills several fields at once:

```
<Document title>
Teaser: <one-paragraph summary>       → Description
Body:                                 → Structured content
<paragraphs and bulleted requirements>
Last Updated: <Month YYYY> (<body>)   → Publication Date
Document History: <provenance>        → shown at the foot of the content
```

Each marker is accepted as `Label:`, `[Label]`, `[Label] content`, or a line holding nothing but the label. Validated against the 50 documents of the master compilation: 50/50 recover a title and body, 48/50 a teaser and date (the other two are fragments of a larger document and carry neither).

The description and date are **never overwritten** if already set — a manual correction always outranks the parser.

> The parsers live in `includes/aidocs-doc-parser.php`: `aidocs_parse_labeled_document()` (label schema) and `aidocs_parse_structured_content()` (body → blocks, with a heuristic fallback for hand-pasted text). These are the only two functions to adjust for a different document family — the rest of the plugin depends only on the block format. To check a change against a corpus without going through WordPress: `php tools/parse-check.php path/*.txt`.

### Completing fields with AI (optional)

Below extraction, the collapsible **"Complete fields with AI (optional)"** panel proposes values for the fields the label schema doesn't cover — typically `Audience` and `Document Type` — or offers a second opinion on the others:

1. Tick the fields to propose (`Title`, `Description`, `Audience`, `Document Type`).
2. Click **"Propose with AI"**.
3. Each proposed field appears as its own card, alongside the current value, editable before applying. **Nothing is written into the form until you click "Apply"** (or "Apply all") — "Discard" drops a proposal without touching anything.

### Restructuring content with AI (optional, whole document)

A separate action, in its own box below the field proposals — it sends the **entire** document in one request, so it costs measurably more than the field suggestions above, and it is not the same kind of task. **"Restructure content with AI"** is for a PDF whose layout the regex extractor misread: a heading left as a paragraph, a list flattened into prose. The AI does not write anything — it re-decides which structural role each piece of the already-extracted text has, reusing that text verbatim.

1. Click **"Restructure content with AI"**.
2. The result is compared word-for-word against the current extracted content, and a fidelity report shows what — if anything — was added or dropped. A clean result reads "Text is verbatim — every word matches the extracted content."
3. Review the restructured content in the preview, then **"Replace content with this"** to apply it, or **"Discard"** to keep the extracted version. Nothing is written to the document until you choose to apply it.

---

## Search shortcode

Add the document search to any page or post with:

```
[aidocs_search]
```

By default, `/{slug}/` (see Settings → Display) already shows this same search — the shortcode is what to use for embedding it somewhere else too, like a second page with different pre-selected filters.

### What the search does

- **Keyword field** — type in any language. After roughly 600ms of no typing, the AI reads the query and surfaces the single most relevant document with a short explanation (requires a configured API key; the field still does a plain WordPress keyword search without one).
- **Filters** — Audience and Document Type dropdowns to narrow results.
- **Clear button (×)** — empties the field and reloads the full catalog.
- **Search button** — runs a plain WordPress keyword search and shows the full results list.
- **Pagination** — 20 results per page by default.

Clicking a result card goes straight to that document's own page.

### The single document page

Each document has its own URL (`/{slug}/{document}/`), rendered inside your theme's header and footer: a header with format/audience/type tags and a download button, the description, a PDF preview, a metadata grid, the structured content (as collapsible sections), the document's revision history, and — when an API key is configured — an Ask AI bar pinned to the bottom of the page for questions about that specific document.

---

## AI features

| Feature | Where | Description |
|---|---|---|
| Inline recommendation | Search field | Most relevant document, with an explanation, in whatever language you typed |
| Complete fields with AI | Document editor | Proposes values for Title/Description/Audience/Document Type from the extracted text, reviewed before applying |
| Restructure content with AI | Document editor | Re-types a misread PDF's extracted text into the right block types, verified word-for-word before applying |
| Ask AI (single document) | Document page | Answers questions about that one document |

All of these use the Gemini model configured in **Settings → AI**, and answer in whichever language the question was asked in.

---

## Shortcode parameter reference

```
[aidocs_search
  type="..."
  audience="..."
  per_page="20"
  show_ai="true"
]
```

| Parameter | Default | Description |
|---|---|---|
| `type` | *(empty)* | Pre-selects a document type. Also reads `?type=` from the URL. |
| `audience` | *(empty)* | Pre-selects an audience. Also reads `?audience=` from the URL. |
| `per_page` | `20` | Results per page (max 50). |
| `show_ai` | `true` | Set `"false"` to disable the inline AI recommendation in the search field. |

### Examples

```
[aidocs_search]

[aidocs_search type="Policies" audience="Institution"]

[aidocs_search per_page="10"]

[aidocs_search show_ai="false"]
```
