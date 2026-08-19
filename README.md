# AI Documents

A WordPress plugin for publishing an institutional **information** repository.
Policies are uploaded as files, but the file is only ever a source of text: it is
read, structured with regular expressions — no AI required for that part — and
published as content. Nothing links to it, offers it for download or previews it,
because what the site publishes is the information, not the document it arrived
in.

A single upload can hold one policy or fifty. The editor picks which at the top of
the screen, and a compilation is split into its individual policies, each becoming
its own entry.

Google Gemini is available as an opt-in layer for metadata suggestions,
re-structuring a misread file, and semantic search.

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

**Documents → Settings** is one page with three sections. Entries live under
`/documents/` — `/documents/{entry}/` for each one — which is not configurable.

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
| **What are you uploading?** | **One policy**, or **a document holding several policies**. Everything below depends on this — see [Uploading several policies at once](#uploading-several-policies-at-once). Defaults to "One policy", but switches itself to "several policies" as soon as a PDF's own text shows more than one — no need to notice that ahead of time and pick it by hand. |
| **Source file** | Upload a file (PDF, Word, Excel) through the media picker. It is read for its text only, and is never published, linked or offered for download. Text and structure are extracted from PDF and Word (`.docx`) files automatically; Excel is accepted but only for reference — it is not parsed. |
| **Title** | Entry name |
| **Last Updated** | Read from the document's own `Last Updated` label when it has one |
| **Audience** | One or more audiences |
| **Document Type** | One or more types |
| **Description** | Read from the document's own `Teaser` label when it has one |

3. Click **"Publish"**.

### Editing an existing document

Once a document has content, its editor screen looks different from the Add New
screen: the "what are you uploading?" question, the source-file upload card, and (for
a compilation) the policy-splitting panel are all gone — they only ever apply while a
document is being set up for the first time. What's left is every field editable
directly: Title, Last Updated, Audience, Document Type, Description, Document
History (the source document's own provenance line), and the content itself (see
[Extracting structured content](#extracting-structured-content-default-no-ai)).

### Extracting structured content (default, no AI)

Uploading a PDF or a Word (`.docx`) file extracts its text and **automatically** parses the document body with regular expressions — no AI call, no API key required — storing it as a sequence of blocks (three heading levels, paragraphs, notes, nested lists, tables) that the frontend renders as HTML. When the document follows the labelled schema described below, the title, teaser, last-updated date, and revision history are filled in at the same time.

1. Upload the PDF or Word file — text extraction and content parsing run on their own.
2. Below the file, **"Document content"** shows how many blocks were saved, a **"Review extracted content"** panel to check the rendered result, and an **"Edit extracted content"** panel with the plain text the parser read, editable before or after publishing.
3. To re-run the parser from the source file — after editing the source document, for instance — use **"Extract content again"**. To fix a misread line by hand instead, edit the text in **"Edit extracted content"** and click **"Apply edited content"**.

A PDF is read in two steps: `assets/js/aidocs-pdf-structure.js` turns the PDF's own layout (bold weight, point size, left margin, line spacing) into **canonical text** carrying that structure as markers. A Word file skips the layout-sniffing — `assets/js/aidocs-docx-structure.js` reads the styles mammoth.js (vendored at `assets/js/vendor/mammoth.browser.min.js`) already recovers from the `.docx` itself. Either way, `includes/aidocs-doc-parser.php` turns the same canonical text into blocks with regular expressions, so nothing downstream cares which kind of file it came from. The full format, recognised note variants, and known limitations are documented in [EXTRACTION_FORMAT.md](EXTRACTION_FORMAT.md).

#### Labelled schema

When a document is authored with the SACSCOC label schema, extraction is **deterministic**, not heuristic, and fills several fields at once:

```
<Document title>
Teaser: <one-paragraph summary>       → Description
Body:                                 → Structured content
<paragraphs and bulleted requirements>
Last Updated: <Month YYYY> (<body>)   → Last Updated
Document History: <provenance>        → shown at the foot of the content
```

Each marker is accepted as `Label:`, `[Label]`, `[Label] content`, or a line holding nothing but the label. Validated against the 50 documents of the master compilation: 50/50 recover a title and body, 48/50 a teaser and date (the other two are fragments of a larger document and carry neither).

The description and date are **never overwritten** if already set — a manual correction always outranks the parser.

### Uploading several policies at once

The files the Commission publishes are single documents carrying dozens of
standalone policies one after another, and each of those has to become an entry of
its own. Pick **"A document holding several policies"** at the top of the editor
and the upload is read for what it holds instead of being parsed as one document.
**PDF and Word (`.docx`)** — the split reads the same extracted text either produces,
so it works the same way for both; an Excel file cannot be split, since it isn't
extracted at all. Uploading a PDF that turns out to hold more than one policy
switches this automatically — there's no need to notice that ahead of time and pick
the mode by hand.

1. Upload the PDF or Word file — the policies are found on their own, with no AI and no API key.
2. **"Complete fields with AI, per policy"** — tick which of Title, Description,
   Audience or Document Type the AI should fill for each policy. Audience and
   Document Type are ticked by default: the label schema has no section for
   either, so there is nothing deterministic to read them from. Title and
   Description are usually already read from the labels, so leave them unticked
   unless a particular upload is missing them.
3. **"Policies in this document"** lists each one with the title, date, description and block count read from it. Untick anything that should not be imported.
4. **"Create N entries"** writes them. The first policy is written over the entry you are editing; the rest are added as new ones. The import runs a few at a time — fewer when AI fields are ticked, since each one is then an extra Gemini call alongside the embedding every entry already gets.
5. Click **"Update"** afterwards, so the form and the entry it is open on agree.

The single-policy fields — Description, Last Updated, Audience, Document Type
— and the "Complete fields with AI (optional)" panel below extraction are all
hidden in this mode: none of them can hold one value that fits every policy in
the upload, which is exactly why the fields are completed per policy above
instead. Applied without a manual review step per policy — reviewing forty-nine
proposals one at a time is what this batch flow exists to avoid — so an AI
field ticked here is written as soon as each entry is created, same as the
deterministic ones.

What delimits the policies is the same label schema above: a policy carries exactly
one `Body:` label, so counting those counts the policies, and each one starts at
the title printed above its own `Teaser:`/`Body:` pair. A file without the labels
cannot be split — upload those policies one at a time. Anything before the first
policy's title, such as a cover page or a table of contents, is left out.

The imported entries carry no source file: the file held fifty policies and none of
them is it.

> The parsers live in `includes/aidocs-doc-parser.php`: `aidocs_split_multi_policy_text()` (compilation → one text per policy), `aidocs_parse_labeled_document()` (label schema) and `aidocs_parse_structured_content()` (body → blocks, with a heuristic fallback for hand-pasted text). These are the only three functions to adjust for a different document family — the rest of the plugin depends only on the block format. To check a change against a corpus without going through WordPress: `php tools/parse-check.php path/*.txt`.

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

### Browsing by Document Type

**Documents** in the admin sidebar reads All Documents, Add New, then one submenu
item per configured Document Type (Policies, Guidelines, Good Practices, Position
Statements, and whatever else is listed under Settings → Taxonomy), set apart under
their own "Browse by Type" label — indented, with a divider below the last one before
Settings — so they read as their own group. Each one opens the same Documents list,
pre-filtered to that type.

The list itself also has a **Type** dropdown next to the Published/Draft/Trash tabs
and the search box — an independent filter, not a replacement for those: picking a
type narrows whichever status tab (or search) is already active, the same way the
built-in date filter does. Audience has no equivalent yet — it stays exactly as it
is, unchanged, pending a decision on whether it's still needed.

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

Clicking a result card goes straight to that entry's own page. Results carry no format tag and no download link — every card leads to the content itself.

### The single entry page

Each entry has its own URL (`/documents/{entry}/`), rendered inside your theme's header and footer: audience and type tags, the description, a metadata grid, a table of contents when the content has more than one section, the content (as collapsible sections), the revision history, and — when an API key is configured — an Ask AI bar pinned to the bottom of the page for questions about that entry. There is no download button and no file preview: the source file is not part of what a reader is offered.

---

## Document shortcode

Embed one specific entry's own content — the same rendering as its `/documents/{entry}/` page, minus the "Back to all topics" link — inside any post or page:

```
[aidocs_document id="123"]
[aidocs_document slug="document-slug"]
```

Pass either `id` (the entry's post ID) or `slug` (its URL slug). If neither resolves to a published document, administrators see a note; other visitors see nothing.

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
