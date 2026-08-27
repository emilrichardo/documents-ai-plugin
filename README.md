# AI Documents

A WordPress plugin for publishing an institutional **information** repository.
Policies are uploaded as files, but the file is only ever a source of text: it is
read, structured with regular expressions — no AI required for that part — and
published as content. Nothing links to it, offers it for download or previews it,
because what the site publishes is the information, not the document it arrived
in.

A single upload can hold one article or fifty. The editor picks which at the top of
the screen, and a compilation is split into its individual articles, each becoming
its own entry.

Google Gemini is available as an opt-in layer for metadata suggestions,
re-structuring a misread file, and semantic search.

> **Looking for the illustrated manual?** [docs/DOCUMENTATION.md](docs/DOCUMENTATION.md)
> walks through every screen with screenshots. It is also the source of the
> plugin's own **Documents → Documentation** page and of the standalone landing
> page in `docs/index.html`; `bash tools/build-docs.sh` rebuilds all three and
> the downloadable zip from it. This README stays as the technical reference.

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

> **Pick a Flash model unless billing is enabled.** The free tier grants no
> allowance at all for the Pro and preview models, however capable they look in
> the picker: the API answers every request with a quota error whose limit is
> zero. The plugin recognises that answer and says so in one line — *"The model
> … has no quota on this API key's plan"* — rather than retrying something that
> can never succeed, or printing several hundred characters of Google's metric
> dump once per attempt. A genuine rate limit is retried, waiting as long as the
> API asks.

### Step 2 — Enter the key in the plugin

You can do this from either place — they write to the same setting:

- **Documents → Settings → AI**: paste the key, pick a model, click **"Save Settings"**.
- **Any document's editor**, under "Complete fields with AI (optional)": paste the key, click **"Check key & list models"** to validate it and list what it can reach, choose a model, click **"Save"**. This form only shows to administrators; anyone else editing a document without a key configured sees a note asking them to get one from an admin, since extraction above it works without one either way.

The model list ships with the current Gemini lineup — 3.6/3.5 Flash, 3.1 and 3
Pro Preview, and the `-latest` aliases that always resolve to the newest of
their family. **`gemini-3.6-flash` is the default.** The retired 2.x models are
gone from it: Google no longer serves them to new users, and every AI feature
failed with *"this model is no longer available"* while one of them was
configured. **"Refresh from API"** / **"Check key & list models"** replaces the
list with exactly what your key can reach — image, text-to-speech, and other
non-chat models are filtered out automatically, since Gemini's own
model-listing endpoint mixes every product line together with nothing that
tells them apart from the id alone.

---

## Settings

**Documents → Settings** is one page with three sections. Entries live under
`/documents/` — `/documents/{entry}/` for each one — which is not configurable.

### AI
- **Gemini API Key** and **Gemini Model** — see the previous section.

### Taxonomy
- **Document Types** — one per line. Defaults to `Policies`, `Guidelines`, `Good Practices`, `Position Statements`. Add a line to make a new type available everywhere Document Type is used — the AI is validated against this same list and never creates a type on its own.

### Shortcodes
A reference of every shortcode parameter with copy-to-clipboard examples.

---

## Managing documents

### Creating a document

1. Go to **Documents → Add New Document** in the admin sidebar.
2. Fill in the form:

| Field | Description |
|---|---|
| **What are you uploading?** | **One article**, or **a document holding several articles**. Everything below depends on this — see [Uploading several articles at once](#uploading-several-articles-at-once). Defaults to "One article", but switches itself to "several articles" as soon as a PDF's own text shows more than one — no need to notice that ahead of time and pick it by hand. |
| **Source file** | Upload a file (PDF, Word, Excel) through the media picker. It is read for its text only, and is never published, linked or offered for download. Text and structure are extracted from PDF and Word (`.docx`) files automatically; Excel is accepted but only for reference — it is not parsed. |
| **Title** | Entry name |
| **Last Updated** | Read from the document's own `Last Updated` label when it has one |
| **Document Type** | One or more types |
| **Description** | Read from the document's own `Teaser` label when it has one |

3. Click **"Publish"**.

### Editing an existing document

Once a document has content, its editor screen looks different from the Add New
screen: the "what are you uploading?" question, the source-file upload card, and (for
a compilation) the article-splitting panel are all gone — they only ever apply while a
document is being set up for the first time. What's left is every field editable
directly: Title, Last Updated, Document Type, Description, Document
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

### Uploading several articles at once

The files the Commission publishes are single documents carrying dozens of
standalone articles one after another, and each of those has to become an entry of
its own. Pick **"A document holding several articles"** at the top of the editor
and the upload is read for what it holds instead of being parsed as one document.
**PDF and Word (`.docx`)** — the split reads the same extracted text either produces,
so it works the same way for both; an Excel file cannot be split, since it isn't
extracted at all. Uploading a PDF that turns out to hold more than one article
switches this automatically — there's no need to notice that ahead of time and pick
the mode by hand.

1. Upload the PDF or Word file — the articles are found on their own, with no AI and no API key.
2. **"Complete fields with AI, per article"** — tick which of Title or Description
   the AI should fill for each article. Document Type has its own
   **"By AI" / "Manually"** switch above the list: manual applies one value to
   every entry the upload creates, while "By AI" reads each article on its own.
   Title and Description are usually already read from the labels, so leave them
   unticked unless a particular upload is missing them.
3. **"Articles in this document"** lists each one with the title, date, description and block count read from it. Untick anything that should not be imported.
4. **"Create N entries"** writes them, one published entry per article.

**The upload's own entry is not one of the articles.** It exists to carry the
file and the detected segments, so once every entry has been created it is moved
to the trash and the editor is sent to the Documents list — where the new
entries are. Earlier versions wrote the first article over it, which left a
document whose URL and title described the upload rather than any article in it.

**The import runs as a background job, not as a loop this page drives.** Forty-nine
articles, each with a Gemini call and an embedding of its own, is several minutes
of work, and it used to stop the moment the tab was closed or one of the links it
had just written was followed. Progress is now saved after every article, so:

- You can close the page. Reopening the upload rejoins the run and shows where it
  has got to.
- A run interrupted halfway resumes rather than starting over, so no entry is
  created twice.
- Where the host allows WordPress's loopback requests, wp-cron carries the run on
  with the page closed. Where it doesn't, the run continues the next time the
  upload is opened. The status line promises only the second, since the first
  depends on the host.

The single-article fields — Description, Last Updated, Document Type
— and the "Complete fields with AI (optional)" panel below extraction are all
hidden in this mode: none of them can hold one value that fits every article in
the upload, which is exactly why the fields are completed per article above
instead. Applied without a manual review step per article — reviewing forty-nine
proposals one at a time is what this batch flow exists to avoid — so an AI
field ticked here is written as soon as each entry is created, same as the
deterministic ones. If the Gemini call fails, the entries are still created
without those fields and the panel says why; it used to swallow the failure and
leave the columns silently empty.

What delimits the articles is the same label schema above: an article carries exactly
one `Body:` label, so counting those counts the articles, and each one starts at
the title printed above its own `Teaser:`/`Body:` pair. A file without the labels
cannot be split — upload those articles one at a time. Anything before the first
article's title, such as a cover page or a table of contents, is left out.

An editorial note authored between the title and the labels — `Note on currency:
…`, `Note: this guideline states …`, left for whoever republishes the document —
does not cost the article its title. The search for the title looks past a few
such lines, stopping at the previous article's revision history so it can never
climb into the article above.

The imported entries carry no source file: the file held fifty articles and none of
them is it.

> The parsers live in `includes/aidocs-doc-parser.php`: `aidocs_split_multi_policy_text()` (compilation → one text per article), `aidocs_parse_labeled_document()` (label schema) and `aidocs_parse_structured_content()` (body → blocks, with a heuristic fallback for hand-pasted text). These are the only three functions to adjust for a different document family — the rest of the plugin depends only on the block format. To check a change against a corpus without going through WordPress: `php tools/parse-check.php path/*.txt`.

### Completing fields with AI (optional)

Below extraction, the collapsible **"Complete fields with AI (optional)"** panel proposes values for the fields the label schema doesn't cover — typically `Document Type` — or offers a second opinion on the others:

1. Tick the fields to propose (`Title`, `Description`, `Document Type`).
2. Click **"Propose with AI"**.
3. Each proposed field appears as its own card, alongside the current value, editable before applying. **Nothing is written into the form until you click "Apply"** (or "Apply all") — "Discard" drops a proposal without touching anything.

A proposed `Title` comes back in normal title case. Source headings are
routinely set in ALL CAPS for print, and transcribing one verbatim put a
shouting title on the page.

### Restructuring content with AI (optional, whole document)

A separate action, in its own box below the field proposals — it sends the **entire** document in one request, so it costs measurably more than the field suggestions above, and it is not the same kind of task. **"Restructure content with AI"** is for a **PDF or Word (`.docx`)** file whose layout the regex extractor misread: a heading left as a paragraph, a list flattened into prose. The AI does not write anything — it re-decides which structural role each piece of the already-extracted text has, reusing that text verbatim.

1. Click **"Restructure content with AI"**.
2. **The review is a list of corrections**, not a wall of content: one row per
   piece the AI gave a different role than the extractor did, plus anything it
   added, left out or reworded. Rows are colour-coded — re-typed, added, dropped,
   wording changed — and lines both read the same way are counted at the foot
   rather than listed, so a fifty-page document corrected in three places shows
   those three.
3. A fidelity report above it answers the other question: whether the AI stayed
   with the document's own words. Its verdict keys on **invented** words, because
   a correct result always leaves some out — see below. The full restructured
   content is there too, collapsed, for a last look.
4. **"Apply these corrections"** writes it, or **"Discard"** keeps the extracted
   version. Nothing is written to the document until you choose.

**What it deliberately leaves out.** Two things in the body are metadata this
plugin already stores in fields of its own, and repeating them inside the content
prints them twice on the page:

- The document's own title, echoed at the top of the body in capitals and often
  broken over two lines, together with the line naming the kind of document that
  follows it (`A Position Statement`, `Guidelines`, `Good Practices`).
- The provenance block at the end — `Last Updated:`, `[Document History]`, and the
  dated `Approved:` / `Endorsed:` / `Revised:` / `Edited:` lines under it.

Because of that, a correct result always reports some words left out. The
report says so, and the dropped rows in the diff are there to confirm they were
only those.

**Why it finds headings the extractor can't.** Word authors most section headings
in these documents as bold body text rather than as a Word heading, so nothing
marks them structurally and the extractor has no way to tell them from a
paragraph. The text sent to the AI keeps its bold markers for exactly this
reason, and the prompt names them as the strongest cue it is given. Measured on
the Commission's own compilations, an article the extractor returned with zero
headings came back with six sections and sixteen sub-sections, with nothing
invented.

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
built-in date filter does.

---

## Search shortcode

Add the document search to any page or post with:

```
[aidocs_search]
```

By default, `/{slug}/` (see Settings → Display) already shows this same search — the shortcode is what to use for embedding it somewhere else too, like a second page with different pre-selected filters.

### What the search does

- **Keyword field** — type in any language. After roughly 600ms of no typing, the AI reads the query and surfaces the one to three documents that address it, with a short explanation (requires a configured API key; the field still does a plain WordPress keyword search without one).
- **Filters** — a Document Type tab bar to narrow results.
- **Clear button (×)** — empties the field and reloads the full catalog.
- **Search button** — runs a plain WordPress keyword search and shows the full results list.
- **Pagination** — 20 results per page by default.

Clicking a result card goes straight to that entry's own page. Results carry no format tag and no download link — every card leads to the content itself.

### The single entry page

Each entry has its own URL (`/documents/{entry}/`), rendered inside your theme's header and footer: a type tag, the description, a metadata grid, a table of contents when the content has more than one section, the content (as collapsible sections), the revision history, and — when an API key is configured — an Ask AI bar pinned to the bottom of the page for questions about that entry. There is no download button and no file preview: the source file is not part of what a reader is offered.

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
| Inline recommendation | Search field | The one to three documents that address the question, explained, in whatever language you typed |
| Complete fields with AI | Document editor | Proposes values for Title/Description/Document Type from the extracted text, reviewed before applying |
| Restructure content with AI | Document editor | Re-types a misread PDF or `.docx`'s extracted text into the right block types, reviewed as a list of corrections and checked for invented words before applying |
| Complete fields per article | Multi-article upload | Fills Title/Description/Document Type for each article of a compilation as its entry is created |
| Ask AI (single document) | Document page | Answers questions about that one document |

All of these use the Gemini model configured in **Settings → AI**, and answer in whichever language the question was asked in.

---

## Shortcode parameter reference

```
[aidocs_search
  type="..."
  per_page="20"
  show_ai="true"
]
```

| Parameter | Default | Description |
|---|---|---|
| `type` | *(empty)* | Pre-selects a document type. Also reads `?type=` from the URL. |
| `per_page` | `20` | Results per page (max 50). |
| `show_ai` | `true` | Set `"false"` to disable the inline AI recommendation in the search field. |

### Examples

```
[aidocs_search]

[aidocs_search type="Policies"]

[aidocs_search per_page="10"]

[aidocs_search show_ai="false"]
```
