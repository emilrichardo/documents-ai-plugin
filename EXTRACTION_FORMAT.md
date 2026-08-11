# Document extraction structure

How a policy PDF becomes structured content **without AI**: PDF position/style
plus regular expressions, nothing else.

Pieces:

| File | Role |
| --- | --- |
| `assets/js/aidocs-pdf-structure.js` | PDF → **canonical text** (pdf.js, runs in the editor) |
| `includes/aidocs-doc-parser.php` | canonical text → **blocks** (regex) + HTML render |
| `tools/parse-check.php` | CLI check against a corpus of text files |

## 1. Why two steps

These documents are authored in Word and exported to PDF, so every structural
decision the author made survives **as layout**: bold headings, the body's own
point size (a few sections use 14/16pt), the left margin (each list level
indents one step further), and vertical spacing (a new paragraph carries air,
a wrapped line does not).

A PDF's plain text layer throws all of that away — lines get cut at roughly
85 characters, with no way to tell a heading from a short sentence. Step 1
**writes those layout facts back into the text** as markers step 2 recognises
with regex, instead of guessing at them.

Signals step 1 uses (all measurable, none a content heuristic):

- **The font's real weight and style** — `page.commonObjs.get(fontId).name`
  returns the embedded name (`Caladea-Bold`, `Caladea-Italic`). `getTextContent()`
  alone only reports `sans-serif`, which is why bold was invisible before.
- **Point size** — 15pt or more ⇒ a level-2 heading.
- **Left margin** — the document's minimum is the heading margin; the body
  usually sits 24pt further in; each distinct marker `x` is one list level.
- **Line spacing** — the page's median; a gap over 1.35× that opens a new paragraph.
- **Horizontal gaps** — more than 1.8× the body size within one line ⇒ table cells.

## 2. Canonical text (intermediate format)

One line = one block. Paragraphs are **never** cut across lines.

```
# Document title
Teaser: one-paragraph summary
Body:
## SECTION IN CAPS              level-2 heading (all caps or ≥15pt)
### Section                     level-3 heading (bold, at the margin)
#### Sub-section                level-4 heading (bold, indented)
A whole paragraph on one line.
1. ordered item                 two spaces of indent per level
  a. nested item
    i. deeper item
- bulleted item
  a further paragraph of the item above (indented, no marker)
| cell | cell |                 table row
**bold** and *italic* inline
Last Updated: June 2026 (Board of Trustees)
Document History: Adopted … · Revised …
```

What this step resolves:

- **Split markers** — Word emits `1.` and its text as separate items with a
  tab stop between them; the marker is detected by that gap and its own value
  is kept (`3.`, `c.`, `iv.`), never renumbered.
- **Hyphenated words cut at a line break** — `non-` + `refundable` are rejoined without a space.
- **Page breaks** — there is no vertical gap to measure there; a block continues
  if the line above it did not finish its sentence.
- **Emphasis split across two lines** — `*Principles of* *Accreditation*` is
  welded back into a single run.
- **Header/footer bands** — only the top and bottom 3.5% are clipped:
  `Last Updated:` and `Document History:` sit at the very bottom of the last page.

Canonical text is what shows in the editor's "Page N" modal, and what is sent
to the parser and, on the AI-restructure path, to the model.

## 3. Blocks (storage format)

`aidocs_parse_labeled_document()` returns:

```php
[
  'title'            => 'Dues, Fees, and Expenses',
  'teaser'           => 'The full schedule of institutional dues…',
  'last_updated'     => 'June 2026 (Board of Trustees)',   // → a date via aidocs_normalize_doc_date()
  'document_history' => 'Approved: … · Revised: …',
  'labeled'          => true,      // the author marked Teaser:/Body:
  'blocks'           => [ … ],     // saved to the _document_content meta
]
```

Blocks (JSON in `_document_content`):

```php
[ 'type' => 'heading',   'level' => 2|3|4, 'text' => …, 'runs' => [], 'id' => 'sec-…', 'note' => '' ]
[ 'type' => 'paragraph', 'text' => …, 'runs' => [] ]
[ 'type' => 'note',      'variant' => …, 'label' => 'Note', 'text' => …, 'runs' => [] ]
[ 'type' => 'list',      'ordered' => true, 'style' => 'decimal', 'start' => 3, 'items' => [
      [ 'text' => …, 'runs' => [], 'blocks' => [ … ] ]   // an item's own sub-lists and paragraphs
  ] ]
[ 'type' => 'table',     'head' => [ … ], 'rows' => [ [ ['text'=>…,'runs'=>[]], … ] ] ]
```

- `text` is always plain text: search indexing and AI context
  (`aidocs_blocks_plain_text()`) never need to know about `runs`.
- `runs` is `[ ['text'=>…, 'b'=>1, 'i'=>1], … ]` and only appears when the
  original text carried emphasis.
- List `style`: `decimal`, `lower-alpha`, `upper-alpha`, `lower-roman`,
  `upper-roman`, `bullet`. `start` keeps the numbering going when a paragraph
  interrupts a list (procedures often number 1…12 straight through).

### Notes

Recognised labels (`AIDOCS_NOTE_PATTERNS`) and their `variant`:

| Label in the document | variant |
| --- | --- |
| `Note to International Institutions` | `international` |
| `Note: Substantive Change` | `substantive-change` |
| `Note: Institutional Contingency Teach-Out Plan` | `teach-out` |
| `SUBSTANTIVE CHANGE RESTRICTION:` | `restriction` |
| `Reminder:` / `Exception:` / `Example:` | `reminder` / `exception` / `example` |
| `Important:` / `Caution:` / `Warning:` | `important` |
| `Note:` / `Notes:` / `Note for …:` | `note` |

Two shapes are distinguished, because the source documents write them differently:

1. **Inline note** — the label opens a paragraph (`Note: An application which
   fails…`). Its extent is exact, so it renders as a callout
   (`<div class="aidocs-note aidocs-note--…">`).
2. **Note section** — the label sits alone on its own line, in bold, exactly
   like a section title. The PDF gives **no signal for where it ends**: the
   paragraphs that follow are laid out identically to the ordinary body text
   that comes after them. So the heading itself is flagged as a `note`
   (`<h3 class="… aidocs-note-heading aidocs-note-heading--substantive-change">`)
   and its content stays in the author's own reading order, rather than boxing
   in a guessed extent.

### Inline enumerations

When a PDF loses the line breaks of an enumeration —
`A. Denial of Candidacy B. Removal from Candidacy C. Denial of Initial Membership` —
the list is recovered **only when the markers run in sequence** from the first
one (A, B, C… / I, II, III… / 1, 2, 3…). That condition is what keeps
initials (`Ms. A. Berger`), abbreviations (`U.S. Department`), and
cross-references from being read as list markers. A `[Title]` at the start of
an item becomes its own bold lead-in.

### Title echo

Almost every document repeats its own title inside the body, in all caps and
split across two or three lines (`THE APPEALS PROCEDURES` / `OF THE COLLEGE
DELEGATE ASSEMBLY`). Those lines are rejoined, compared against the title, and
dropped — the page already shows it as a heading.

## 4. Several policies in one file

The files the Commission publishes are single documents carrying dozens of
standalone policies one after another. `aidocs_split_multi_policy_text()` cuts one
into a text per policy, each of which is then parsed by
`aidocs_parse_labeled_document()` exactly as if it had been uploaded on its own —
so the split adds one rule and changes none of the others.

What delimits a policy is the label schema itself:

- **A policy carries exactly one `Body:` label**, so the number of those labels is
  the number of policies. A file with none cannot be split.
- **A policy starts at its title**, the heading run printed above its own
  `Teaser:`/`Body:` pair. The search for it is fenced by the previous policy's
  `Body:` label, so a title the layout lost is never looked for so far up that it
  lands inside the policy before it. Titles set over two and three lines are
  followed up and rejoined; `aidocs_is_trailer_text()` stops the run at the
  previous policy's dates and provenance lines, which a few documents set in a
  weight the extractor reads as a heading.
- **Anything before the first policy's title** — a cover page, a table of
  contents — is left out.

One catch the split has to correct: only the *first* heading of an extraction is
written as a level-1 `#` title (see `headingLevel()` in the JS), so from the second
policy on the title arrives as an all-caps level-2 heading. Each segment's leading
heading run is rewritten to the single `# Title` line the parser reads, which is
what makes a segment indistinguishable from a standalone upload.

`aidocs_count_policies()` is the same work for its count alone. On a single-policy
file it returns 1, so the rule needs no special case for the ordinary upload.

## 5. Hand-pasted text

Text without markers (`#`, indentation) is detected by
`aidocs_text_is_annotated()` and processed with the usual fallback rules:
paragraphs separated by a blank line, bullets by glyph, and headings by
`aidocs_detect_heading()` (all caps, or a short Title Case line with no
trailing punctuation). The same block shape comes out either way, so
rendering and storage never change.

## 6. Verification

```bash
php tools/parse-check.php path/*.txt            # block census + warnings
php tools/parse-check.php --fields path/*.txt   # title, teaser, date, history
php tools/parse-check.php --blocks doc.txt      # block tree as JSON
php tools/parse-check.php --html doc.txt        # rendered HTML
```

The warnings (`⚠`) are not parser errors, just things worth a look: no title,
no teaser, no `Last Updated`, an unparseable date, or a section label left
inside the body.

Result across the 50-policy corpus (`Documentos_Politicas_divididos`): 50/50
recover a title and body, 48/50 a teaser and date (49 and 50 are fragments of
a larger document and carry neither), 30 level-2 headings, 296 level-3, 29
level-4, 10 note sections, 25 boxed notes, 346 lists with 1,449 items up to 4
levels deep, and 35 tables.

## 7. Known limitations

- **Tables without grid lines** are reconstructed from horizontal gaps. On
  pages with rotated column headers (the substantive-change-type appendix)
  the text comes out disordered inside the table; it is kept as rows, not lost.
- **Table headers**: `<th>` is only used when the whole row was bold;
  otherwise every row is `<td>`.
- **A scanned PDF** (no text layer) produces nothing — there is no OCR.
- **Fonts with no embedded name**: when `commonObjs` does not expose one, the
  parser falls back to margin and line-spacing signals, and a heading that
  isn't outdented becomes a paragraph.
- **Splitting needs the labels.** A compilation whose policies carry no
  `Body:` label cannot be cut apart — there is no other reliable boundary, and
  guessing one would silently merge two policies or halve one. Those are uploaded
  a policy at a time.
- **"The Commission"** is the corpus's one mixed-case exception — the
  organisation's name, substituted at a fixed case even inside an all-caps
  title ("AND ACTIONS OF The Commission"). It is ignored when checking whether
  a line is all caps — `isAllCaps()` in the JS, `aidocs_comparable()` and
  `aidocs_reads_like_heading()` in the parser. A different brand substitution
  with the same pattern would need the same exception.
- **A heading that no longer reads like one once assembled** — several
  consecutive bold PDF lines, each individually short enough to look like a
  heading, accumulating into one that plainly is not (a page-spanning bold
  "Note:" paragraph is the recurring case) — is folded back into a paragraph
  or a note instead (`aidocs_reads_like_heading()`,
  `aidocs_append_downgraded_text()`). All-caps text is exempt from this check,
  since that is how these documents set their own titles, and a genuine one
  can legitimately run long.
- **Nested inline enumerations**: `aidocs_split_inline_enumeration()`
  reconstructs "1. [Title]… 2. [Title]…" run together on one canonical line,
  but stops at the first number that breaks the sequence rather than scanning
  past it, so a nested sub-list's own numbering (which restarts at 1) is never
  mistaken for the outer list's continuation. The result is conservative: in
  the worst case nothing is recovered (the text stays one item, same as
  before), never an item cut at the wrong place.
- When a fix like this changes how text is **parsed** rather than how already
  stored content is **rendered**, a document extracted with a previous version
  of the parser will not reflect it until it is extracted again — the
  "Extract content again" button in the editor, or re-uploading the PDF.
