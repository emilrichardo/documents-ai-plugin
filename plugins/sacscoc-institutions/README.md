# SACSCOC Institutions

Keeps a local copy of the SACSCOC institution directory in WordPress,
synchronised from the SACSCOC API.

The API is the source of truth; WordPress holds a copy. Visitors are never sent
to the API — the directory reads local tables, so it stays fast and stays up
when the API does not.

- **Version** 0.9.0
- **Requires** PHP 8.0, WordPress 6.0
- **Depends on nothing.** No shared code, tables, options or hooks with the AI
  Documents plugin in this repository. Either can be installed, activated,
  updated and deployed without the other.
- **No AI**, by design.

## What is in this release

The synchronisation layer, the admin screens, and the public directory.

**0.9.0**:

- Activating the plugin now opens a one-time **setup wizard** — sync,
  choose a layout and page size, choose or create the Directory Page — instead
  of leaving all three to be found separately in Settings and Sync. Reachable
  again any time from **Run Setup Wizard** on the Settings screen. See
  `includes/onboarding.php`.
- A third Gutenberg block, **Institution**: one record, found by searching its
  name in the block's own Inspector Controls rather than typing an id — the
  native alternative to `[sacscoc_institution id="…"]`.
- The **Institutions Directory** block can restrict itself to one state,
  degree or reaffirmation year — "just Texas" — instead of only ever showing
  the unrestricted directory. The matching field disappears from the inline
  search, since changing it would do nothing.
- The **Institutions Search** block/`[sacscoc_institutions_search]` gained
  **Constrain width to match the directory** (on by default): capped at the
  same measure the directory itself uses and centred, so a search panel placed
  above a directory lines up with it instead of stretching full width.
- **Settings → Directory Page** and the setup wizard now always insert the
  **Institutions Directory** block itself into the chosen/created page — never
  a shortcode — so what Settings configures and what the page shows can never
  drift apart, and the block is there to click and customise the moment the
  page opens.

**0.8.0** gives the Institutions Search block/`[sacscoc_institutions_search]` a
Layout control of its own — Vertical (the panel) or Horizontal (a single
search bar) — independent of whatever layout a directory elsewhere on the page
is using. The two blocks also carry distinct icons in the inserter now, rather
than sharing one.

**0.7.0** adds two Gutenberg blocks — Institutions Directory and Institutions
Search — as a native alternative to typing out the shortcodes: every attribute
either shortcode takes is an Inspector Control instead, plus background
colour, text colour, padding and font size from the block's own standard
toolbar. **Create Institutions Page** now builds a page around the block
rather than a Shortcode block wrapping shortcode text, and the two headings
above the search panel and the results list are customisable, on both blocks
and both shortcodes. See [The public directory](#the-public-directory).

**0.6.0** adds **Create Institutions Page** to Settings — a page created,
published and pre-filled with the directory, in one click instead of leaving
Settings to build one by hand. An existing Directory Page whose content is
missing it gets a warning and an **Add the directory to this page now** button
in its place.

**0.4.0** splits the search form out of the directory: `show_search="no"` on
`[sacscoc_institutions]`, paired with `[sacscoc_institutions_search]` rendered
wherever that shortcode's own layout cannot reach — a custom block, a sidebar, a
template part. See
[The search form on its own](#the-search-form-on-its-own).

**0.3.0** is a frontend release. The directory was redressed in the Cirlot
site's own design language rather than the old sacscoc.org one — see
[Design and theme integration](#design-and-theme-integration) — and three things
were added around it:

- a **choice of layout**, two columns or one, in Settings or per page;
- **Results Per Page** as a setting rather than a constant;
- **`[sacscoc_institution id="…"]`**, which puts a single record on any page,
  offered ready to copy on that institution's own admin screen.

Two defects found while building it were fixed: a search matching nothing
printed no message at all, and a page set to a non-default page size silently
reverted to 25 on the first keystroke of a live filter.

Not yet included: **off-campus instructional sites** and the **review / meeting
history** on institution pages. Both need per-institution API calls — about
3,600 requests for the full dataset — so they need a batched, resumable sync
rather than the single request the institutions themselves take.

| Screen | What it is for |
| --- | --- |
| Institutions → All Institutions | The local copy, searchable and filterable. Clicking one opens its record: a read-only screen showing every stored field, grouped into what the institution is (main column) and what the record is (side column), with that institution's embed shortcode ready to copy. Inspection, not editing. |
| Institutions → Sync | Status, `Sync Now`, and the log of recent runs. |
| Institutions → Settings | API Base URL, Sync Frequency, API Timeout, the directory page, layout and page size, the URL base, the shared footer content, whether deleting the plugin takes the data with it, and a confirmed reset that empties the tables without uninstalling. |
| Institutions → Documentation | How to publish the directory — the three blocks, the three shortcodes underneath them, and what the settings currently say — then the API, the field map, and how a sync decides what to write. |

## The public directory

The no-code way to publish it: **Settings → Directory Page → Create
Institutions Page** creates a new, published page named "Institutions" with an
**Institutions Directory** block already on it, and opens the page for editing
straight away. Click the block to configure it from its own Inspector Controls
— no attribute is ever typed by hand — and use the block's own toolbar for
background colour, text colour, padding and font size, the same as any other
block on the page.

A `[sacscoc_institutions]` shortcode sits underneath the block and remains
fully supported on its own, for a page with no block editor or for the odd
attribute a control does not offer:

```
[sacscoc_institutions]
```

### The Gutenberg blocks

Three blocks, added from the inserter like any other:

**Institutions Directory** — the whole thing: search, results, pagination.
Its Inspector Controls panel offers:

| Control | What it does |
| --- | --- |
| Show the search form | Off drops the inline form entirely, for pairing with a separate Institutions Search block — see below |
| Layout | Two columns or one — leave unset to follow **Settings → Directory Layout** |
| Results per page | Leave blank to follow **Settings → Results Per Page** |
| Show the result count | Toggles the "Showing 1–25 of 1,201" line |
| State / Highest degree offered / Next reaffirmation year | Restricts every result — and drops the matching field from the inline search — to one value. Leave all three on "Any" for the ordinary, unrestricted directory. |
| Search panel heading / Results heading | Replace "Institution Search" / "Results" with any text |
| Group | Only shown once the search form is off; see pairing below |

**Institutions Search** — just the form, for a page that wants it somewhere
the directory block's own layout cannot reach: a sidebar, a header, another
column. Its Inspector Controls offer a **Layout** (Vertical, the panel, or
Horizontal, a single bar — independent of whatever layout the directory
elsewhere on the page is using), a **Heading**, a **Group**, and **Constrain
width to match the directory** (on by default) — capped at the directory's own
1200px measure and centred, so a search panel placed above a directory lines
up with it instead of stretching full width; turn it off for a panel placed
somewhere narrower, like a sidebar.

**Institution** — one record. Type a name into its own Inspector Controls and
pick it from the matches — the native alternative to typing
`[sacscoc_institution id="…"]` — plus toggles for the "Back to Results" button
and the "About SACSCOC" block, the same two things the shortcode's `back` and
`about` attributes control.

The Directory and Search blocks pair up purely in the browser — the same
runtime pairing `[sacscoc_institutions_search]` and `show_search="no"` already
use, by matching **Group** (default "default" on both, so an ordinary
one-of-each page needs neither field touched; set both to the same value only
when a page carries more than one pair).

All three blocks are dynamic: what shows in the editor is exactly what a
visitor gets, fetched from the same PHP the shortcodes call —
`sacscoc_inst_render_directory()` / `sacscoc_inst_render_search()` /
`sacscoc_inst_render_institution()` in `includes/frontend.php` — so a block
and its shortcode can never disagree about what a given set of settings
produces.

### The shortcodes underneath

Attributes, all optional — each one overrides its Settings value for that page
only, and maps one-to-one to a block Inspector Control above:

| Attribute | Values | Default |
| --- | --- | --- |
| `layout` | `two-column`, `one-column` | **Settings → Directory Layout** |
| `per_page` | 1–200 | **Settings → Results Per Page** (25) |
| `show_count` | `yes`, `no` | `yes` |
| `show_search` | `yes`, `no` | `yes` |
| `group` | any string | `default` |
| `search_heading` | any text | the built-in "Institution Search" |
| `results_heading` | any text | the built-in "Results" |

```
[sacscoc_institutions layout="one-column" per_page="50"]
```

`show_search` and `group` are for [The search form on its own](#the-search-form-on-its-own):
`show_search="no"` drops the inline form, and `group` pairs this shortcode with
a `[sacscoc_institutions_search]` rendering it elsewhere.

`layout`, `per_page`, `show_count`, `group` and `results_heading` travel with
the markup as data attributes and are posted back with every live filter, so a
page listing 50 under a custom heading keeps listing 50 under that same
heading after the first keystroke, instead of quietly reverting to the default.

A page picked as Directory Page whose content has neither the shortcode nor the
block yet — one chosen before this existed, or emptied out afterwards — gets a
warning right on the Settings screen with an **Add the directory to this page
now** button, so nothing is ever silently rewritten: the only two ways the
directory lands in a page's content are these two explicit clicks, or pasting
the block or the shortcode in yourself.

Institution pages are served at `/institutions/<slug>/` — the base is
configurable in Settings — and render inside the theme's own `get_header()` and
`get_footer()`, so they inherit the site's chrome and typography.
**Settings → Directory Page** is also what institution pages link back to.

The four filters are the ones the existing site offers: institution name, state
(including the *International* pseudo-option), highest degree offered, and next
reaffirmation year. Each is a `si_`-prefixed query parameter, so every result
set is a shareable URL and the back button behaves. Unrecognised values are
dropped rather than passed to the query.

The reaffirmation-year dropdown is built from the years that actually have
institutions behind them. The current site hardcodes 2021–2036 and the first
five of those now return nothing.

### One institution on any page

Every institution also has a shortcode of its own, which renders its full record
wherever it is pasted:

```
[sacscoc_institution id="1246"]
```

It is printed ready to copy on the institution's own admin screen —
**Institutions → All Institutions → (any institution) → Embed this record** — so
the id never has to be looked up by hand.

| Attribute | Values | Default |
| --- | --- | --- |
| `id` | the API numeric id | — |
| `slug` | the URL slug | — |
| `sf_id` | the Salesforce id | — |
| `back` | `yes`, `no` | `no` |
| `about` | `yes`, `no` | `yes` |

The id is the **API numeric id**, not the row's local `id`: it comes from the
source of truth, so it survives the local table being dropped and rebuilt. A
page written against a URL or against Salesforce can use `slug` or `sf_id`
instead; they are tried in that order.

It renders from `templates/institution.php` — the same file the
`/institutions/<slug>/` page uses, so an embedded record and a record on its own
page cannot drift apart. Two things differ, and both are attributes:

- **`back`** is off. The "Back to Results" button assumes the visitor arrived
  from the directory, which is not true of a record dropped into an editorial
  page.
- **`about`** is on, because the shared About SACSCOC block is a disclosure that
  belongs with the record — but turn it off when several records share a page
  and it would otherwise print the same 1,500 words for each.

An id that matches nothing renders nothing at all for a visitor. Anyone who can
edit posts gets a one-line note instead, because a blank space where a record
should be is the kind of mistake nobody notices until someone else does.

### Two layouts

**Institutions → Directory Layout** chooses where the search sits. Both are the
same template and the same markup — the difference is a class on the wrapper,
not a second set of files to keep in step, and the form stays first in source
order either way so the keyboard and screen-reader order never changes.

**Two columns** (default) — results left, search panel right, as the current
sacscoc.org directory has it. Below 900px it collapses to search-then-results.

**One column** — a search bar across the top and the results full width beneath
it, following the site's own
[Find an Institution](https://4094.cirlot.com/students-families/find-an-instituition/)
page: the fields joined into one square strip with the navy button welded to the
end of it. Two details follow from that page rather than from the default
layout:

- the field labels are visually hidden, because the placeholder and the
  "Any State"/"Any Degree" first option already say what each field is. They
  stay in the markup, so nothing changes for a screen reader.
- the *Search* button stays visible even though filtering is live. In the panel
  it is redundant once the script takes over and is hidden; in the bar it is
  part of the shape of the thing, so it stays and simply applies the filters at
  once.

Below 720px the strip becomes ordinary stacked fields with their labels back.

### The search form on its own

`show_search="no"` drops the form from `[sacscoc_institutions]` entirely — no
`<aside>`, results take the full width — for a page that wants the search
somewhere that shortcode's own layout cannot reach: a custom block, a sidebar, a
template part. `[sacscoc_institutions_search]` renders that form there instead:

```
[sacscoc_institutions show_search="no"]
```

placed anywhere else on the same page, in a custom block or widget, gives

```
[sacscoc_institutions_search]
```

Both are optional and both default to working exactly as before: leave
`show_search` out and `[sacscoc_institutions]` keeps its own inline form, the
same markup it has always rendered.

They find each other **purely at runtime**, by matching a `group` attribute —
default `"default"` on both, which is why the ordinary one-of-each case needs no
attribute on either shortcode at all. There is no server-side link between them;
`assets/js/directory.js` pairs them in the browser: a directory looks for a form
nested inside itself first, and only when there is none does it look elsewhere
on the page for an unclaimed `[sacscoc_institutions_search]` sharing its group.
A page with more than one directory/search pair needs `group="…"` on both halves
of each pair to keep them from claiming each other's form; everyone else never
touches the attribute.

Both render `templates/search-form.php` — the same file, the same markup,
whichever shortcode is asking for it — so an inline form and a standalone one
can never drift apart. What differs is entirely outside that file: where the
plain-GET, no-JavaScript fallback submits to, and the styling wrapper the
standalone shortcode puts around it. On the wrapper: it carries the class
`sacscoc-directory` — the stylesheet's general-purpose scoping class, not a
claim that this is literally a directory — purely so the standalone form picks
up the same colours, type, controls and buttons the inline one does, without
carrying the `data-sacscoc-directory` marker that tells the script "this is a
results region," which would make the script mistake it for one.

The standalone form's fallback action is always **Settings → Directory Page** —
never "whatever page this shortcode happens to be on" — because when the two
shortcodes are on different pages (or the setting is what actually locates the
results), guessing from the current request would point the form at itself.

It also offers the one-column "bar" visual on its own terms: `layout="horizontal"`
(default `vertical`), independent of `[sacscoc_institutions]`'s own layout —
where that attribute is a statement about how the *directory's* search sits next
to *its* results, this one is just about the shape of *this* form, wherever it
ends up. Both values render through the same `.sacscoc-search--stacked` CSS the
directory's own inline bar uses, so a standalone bar and an inline one look
identical; see [The Gutenberg blocks](#the-gutenberg-blocks) for the same choice
as an Inspector Control on the Institutions Search block.

### Live filtering

Filters apply as you type — 300 ms after the last keystroke — and immediately on
changing a select. Only the results region is re-rendered; the search panel and
the page around it never move. Each active filter grows a **×** that clears just
that one, and *Reset filters* clears them all.

The address bar is kept in step with `replaceState`, so a filtered view is still
a URL you can copy, bookmark or send to someone, and the back button leaves the
directory rather than unwinding twenty keystrokes.

**It is an enhancement, not a requirement.** The directory is a plain GET form
that works fully server-rendered: without JavaScript the *Search* button submits
and the page reloads with the filters in the query string, and every × is a real
link whose `href` drops exactly its own filter. The script hides the Search
button once it takes over — in the two-column panel, where it is redundant; the
one-column bar keeps it — and on any failure — network error, expired nonce,
a browser without `fetch` — it submits the form and lets the page reload. Every
URL the live version puts in the address bar renders identically on the server.

Requests are sequenced, so a slow early response can never overwrite a newer
one, and in-flight requests are aborted when a newer filter arrives. The results
stay visible and dim while a new set loads rather than collapsing to a blank
column.

A search that matches nothing says so. The two-column grid stands down and the
search panel takes the width, but the results column stays in the page — it is
what prints *No results found matching that search, please try again.* It used
to be hidden along with the grid, so a search with no matches simply showed the
filters again with no explanation.

### The degree filter returns different counts to the live site

Deliberately. The API's own `degree` filter excludes any institution whose
`master` field is `null` rather than the string `"No"`, because it compares with
`master = 'No'` and nothing in SQL equals NULL. That silently loses 13
institutions — including Kentucky College of Art and Design, which is currently
accredited. This plugin uses `COALESCE`, so:

| `degree` | API returns | This plugin |
| --- | --- | --- |
| `associate` | 357 | **365** |
| `baccalaureate` | 176 | **181** |
| `master` | 181 | 181 |
| `education_specialist` | 19 | 19 |
| `doctorate` | 392 | 392 |

If Cirlot would rather match the live site exactly, the fix is confined to
`sacscoc_inst_highest_degree_sql()` in `includes/query.php`.

### Design and theme integration

The reference for the design is **the Cirlot site itself**, not the old
sacscoc.org directory. An earlier version reproduced sacscoc.org component by
component — heavily rounded outlined panels, section titles notched into the top
border, everything centred — and dropped into this theme it read as a page from
another site pasted into this one. The layout it describes is still the same
(two columns, results left, search right, the same fields, the same record
sections); what changed is how it is dressed:

- **No boxes.** Sections are separated by space and a hairline rule. The only
  rounded things are the controls and the buttons, which is exactly where the
  theme rounds things too — its header search field and its pill buttons.
- **Results are rows,** the way `/insights-research/` lists news: a Montserrat
  title in navy, a gold arrow that slides on hover, a 1px divider.
- **Headings are left-aligned** over a hairline, not centred into a notch.
- **One measure:** 1200px, the theme's own container width, with real space
  above and below so the directory does not butt against the page banner.
- The search panel is a flat tint, not an outlined card.
- **Icons, not arrows.** Every link, field label and section heading carries a
  small inline SVG from `includes/icons.php` — an envelope on *Email*, a pin on
  *Address*, a chart on *Student Achievement Data*. They are drawn on a 24×24
  grid, stroked with `currentColor` and sized in `em`, so one rule
  (`.sacscoc-icon`) governs the set and an icon is never out of step with its
  label. There is no icon font and no image request; a theme can replace any
  path through the `sacscoc_inst_icon_paths` filter.
  The result titles used to carry a trailing gold arrow as well — with an icon
  on every link beside them the rows had become a field of arrows, so the title
  now shows it is a link by changing colour on hover, as the rest of the site
  does.

Colour and type are **read from the theme**, not declared here. Every token at
the top of `assets/css/sacscoc-institutions.css` reads an Elementor global first
and only falls back to a literal if the site has none:

| Token | Reads | Falls back to |
| --- | --- | --- |
| `--sacscoc-navy` | `--e-global-color-primary` | `#003a5d` |
| `--sacscoc-blue` | `--e-global-color-secondary` | `#4d758e` |
| `--sacscoc-gold` | `--e-global-color-accent` | `#cc9f53` |
| `--sacscoc-ink` | `--e-global-color-text` | `#454545` |
| `--sacscoc-font-heading` | `--e-global-typography-primary-font-family` | the theme's own face |

So re-branding the site re-brands the directory, with no edit here. A site
without Elementor gets the literals, which are the SACSCOC palette.

Rules and tints (`--sacscoc-line`, `--sacscoc-surface`) are stated as
percentages of black rather than as the theme's literal greys. `#ececec` is a
divider only on a white page; `rgba(0,0,0,0.09)` lands on the same value there
and still reads on a theme with a tinted background.

**Nothing is a fixed size.** Type and the gaps are all `clamp()`d — bounded in
px at both ends so they can never become unreadable or absurd, and stated in vw
between those bounds so they keep adapting instead of snapping at breakpoints.
The scale lands on the theme's: body 15 → 17px, row titles 18 → 22px, section
headings 24 → 32px, the record's title 30 → 44px.

The directory has its own scale rather than sizing off `em` multiples of the
theme's body text. A theme with an 18px base and large headings would otherwise
scale the whole directory up with it, which is what made the first version look
oversized.

Two things about integrating with a theme are worth knowing, because both were
real defects before they were fixed:

- **Block themes ship no `header.php`.** Calling `get_header()` on one finds
  nothing in the theme and falls through to WordPress's own deprecated
  `theme-compat/header.php` — a bare site title and an `<hr>`, with none of the
  theme's design. Institution pages therefore print the document wrapper
  themselves and pull in the theme's real `header`/`footer` template parts, the
  same way AI Documents does. See `sacscoc_inst_page_header()`.
- **A block theme's constrained layout caps content at its `contentSize`** —
  740px in the Cirlot theme, which squeezes the two-column directory into a
  column of wrapped words. That cap is written entirely with `:where()`, so it
  carries no specificity; the plugin lifts it with an id selector on
  `#sacscoc-directory` rather than with `!important`. The width is filterable:

```php
add_filter( 'sacscoc_inst_max_width', fn() => 'min(1400px, 100%)' );
```

A theme's own element-level form styles (`button[type="submit"]`,
`input:read-write`) are `(0,1,1)` and beat a plain class, so the button and
control rules are scoped under `.sacscoc-directory` / `.sacscoc-single` to reach
`(0,2,0)`. No `!important` anywhere.

For deeper changes, copy any of `templates/directory.php`,
`templates/institution.php` or `templates/single-institution.php` into a
`sacscoc-institutions/` folder in the theme and edit them there — the same
override convention WooCommerce uses. `single-institution.php` is only the page
shell, so overriding just `institution.php` changes the record layout without
reproducing the header/footer plumbing.

### Content shown on every institution page

The current site repeats ~1,500 words of "About SACSCOC and Accreditation" and
the complaints procedure on all 1,201 pages. That is one setting —
**Settings → Institution Footer Content** — not 1,201 stored copies. Leave it
empty and the block does not render at all, which is also how the content gets
handed to the theme later: delete the single
`sacscoc_inst_footer_content()` call in `templates/institution.php`.

### URLs under the same base keep working

Ordinary WordPress pages can live alongside institution pages —
`/institutions/third-party-comments/` and
`/institutions/accreditation-actions-and-disclosures/` both exist on the real
site. A URL under the base that is not an institution is handed back to
WordPress and resolves as the page it is, so no thought is needed when a new
child page is added.

## Configuration

Everything is under **Institutions → Settings**.

**API Base URL** — the host only, e.g. `https://api.sacscoc.org`. The plugin
appends the `/api/v1/…` paths itself, and a path typed here is stripped with a
warning. This is the only place the API host is configured: if the API moves to
another host, change this field and nothing else. The host appears literally
nowhere else in the plugin except the `SACSCOC_INST_DEFAULT_API_BASE` default in
`sacscoc-institutions.php`.

**Sync Frequency** — hourly, every 3 hours, every 6 hours (default), every 12
hours, or daily. Saving a new value reschedules the pending run immediately.

**API Timeout** — 5–300 seconds, default 60. The full directory is ~1.7 MB and
normally answers in about 3 seconds.

**Directory Layout** — two columns (default) or one column. See
[Two layouts](#two-layouts).

**Results Per Page** — 1–200, default 25 as the current site. Clamped on the way
in *and* on the way out, so a value written straight to the database by a
migration or WP-CLI still cannot produce a query for the whole table.

**Directory Page** — the page the directory lives on. **Create Institutions
Page** creates one from scratch, published, with the shortcode already written
into its content as a real, editable Shortcode block. Picking an existing page
here instead only sets where institution pages link back to; if that page's
content does not already have the shortcode, a warning and an **Add the
shortcode to this page now** button appear right below the dropdown. See
[The public directory](#the-public-directory).

**Institution URL Base** — one path segment, `institutions` by default. See
[URLs under the same base keep working](#urls-under-the-same-base-keep-working).
Changing it changes every institution URL, so old links stop resolving;
permalinks are refreshed automatically.

**Institution Footer Content** — the shared About SACSCOC block, stored once
rather than 1,201 times. See
[Content shown on every institution page](#content-shown-on-every-institution-page).
Leave it empty and the block does not render at all.

**Delete everything when this plugin is deleted** — off by default. See
[Deleting the plugin](#deleting-the-plugin). The same section holds
**Delete all stored data now…**, which empties the tables without uninstalling —
see [Starting over without deleting](#starting-over-without-deleting).

## How the sync works

```
GET the full directory  (one request, ~1.7 MB, ~2.5 s)
  ↓
sanity-check the payload
  ↓
fingerprint each record, compare with the stored fingerprint
  ↓
insert the new · rewrite the changed · leave the unchanged untouched
  ↓
mark anything the API no longer sends as missing — never delete it
```

Each institution is matched on `sf_id`, the stable Salesforce id — never on its
name, which is neither unique nor always present.

An unchanged institution is not written at all: not its data, not even a
timestamp on its own row. A steady-state sync of all 1,201 records issues no
data writes and takes about 2.5 seconds, effectively all of it download.

The API has no "changed since" endpoint, and its `updated_at` is rewritten on
every record on every refresh, so it cannot substitute for one. Hence the full
download and the local comparison. See `docs/API-FIELD-MAP.md`.

### A failing API never empties the directory

This is the rule the sync is built around. Every one of these ends the run
before a single row is written, records the reason, shows it in the admin, and
leaves the local copy exactly as it was:

| What happens | What the sync does |
| --- | --- |
| Timeout or DNS failure | Fails, keeps all data |
| HTTP 500 (or any non-200) | Fails, keeps all data |
| 200 with a body that is not JSON | Fails, keeps all data |
| 200 with an empty body | Fails, keeps all data |
| 200 with no `results` key | Fails, keeps all data |
| 200 with `results: []` while records are stored | Fails, keeps all data |
| A payload under 50% of the stored count | Fails, keeps all data |
| A payload above that threshold but short | Applies it, marks the absent ones missing |

The last row is the only one that changes anything, and it still deletes
nothing: an institution the API stops sending gets `missing_since` set, keeps
its data and its slug, and is flagged in the admin. If the API sends it again,
the mark clears. There is no code path in this plugin that deletes an
institution.

The 50% floor is filterable for the case where the directory genuinely shrinks
by more than half:

```php
add_filter( 'sacscoc_inst_min_payload_ratio', fn() => 0.2 );
```

## Automatic sync

A WP-Cron event, `sacscoc_institutions_sync`, on the configured schedule.

WP-Cron is traffic-driven: a site nobody visits runs nothing. The Sync screen
shows when the next run is due and warns if `DISABLE_WP_CRON` is set. On a quiet
staging site, a real system cron is the reliable arrangement:

```bash
*/15 * * * * curl -s https://example.org/wp-cron.php?doing_wp_cron > /dev/null
```

The event is rescheduled automatically when it is missing, so a deploy over
SFTP — which never fires the activation hook — still ends up with a working
schedule.

## Data model

Four tables, all prefixed `{$wpdb->prefix}sacscoc_`:

| Table | Contents |
| --- | --- |
| `sacscoc_institutions` | One row per institution, 51 columns. Written by this release. |
| `sacscoc_institution_sites` | Off-campus instructional sites. Created; populated next milestone. |
| `sacscoc_institution_meetings` | Reviews and meetings. Created; populated next milestone. |
| `sacscoc_sync_log` | One row per sync attempt, capped at the most recent 200. |

Own tables rather than posts and postmeta: 43 API fields per institution across
1,201 records is ~50,000 postmeta rows, and a filtered search — state plus
degree plus reaffirmation year — becomes three meta joins over that. With real
columns it is one indexed `WHERE`.

Each row also keeps `raw_json`, the untouched API record, so a mapping mistake
is recoverable and a field the API adds later can be adopted without
re-discovering the response.

An institution's `slug` is its public URL and is assigned once, on first insert,
then never rewritten — so an institution renamed upstream keeps the URL anyone
has already linked to or bookmarked.

Deactivating the plugin clears the cron event and nothing else. The tables and
their data survive, so deactivating and reactivating costs no re-download.

### Deleting the plugin

Deleting is the only thing that can remove the data, and by default it does not:
a delete-and-reinstall is usually a repair, so reinstalling picks the
institutions back up instead of re-downloading 1.7 MB.

To start from scratch instead, tick **Settings → Deleting this plugin → Delete
everything when this plugin is deleted** *before* deleting it. `uninstall.php`
then drops the four tables and every `sacscoc_inst_*` option and transient —
matched by prefix rather than from a hand-kept list, so it cannot fall behind
the code — and clears the cron event. Nothing outside the plugin is touched.

It is a setting rather than a prompt because WordPress asks "are you sure you
want to delete this plugin?" and nothing else: there is no hook that can add a
second question to that dialog, and `uninstall.php` cannot ask one either, since
by the time it runs there is no screen left to answer on. So the question is
asked in advance, and the screen says which way it is currently answered.

Note that a deploy is not a delete. Uploading the plugin files again over SFTP —
which is what the deploy workflow does — never runs `uninstall.php`, so the data
survives a redeploy whatever the box says. Only **Plugins → Delete** runs it.

### Starting over without deleting

**Settings → Deleting this plugin → Delete all stored data now…** empties the
four tables and clears the last sync's result, keeping the tables and every
setting, so the next sync refills the directory from the API. It is the answer
to "resync everything from nothing" that does not involve deleting the plugin.

It asks first, on a screen of its own rather than through a JavaScript
`confirm()` — a browser with the script blocked would otherwise run a
confirm-less delete. The confirmation is also where the one real cost is stated:
institution URLs are assigned on first insert, so they are assigned again on the
next sync, and institutions whose names collide take their numeric suffix in
whatever order they arrive. That order may differ, so a link to one of those
could end up pointing at the other.

`TRUNCATE` rather than `DELETE`, so the refilled tables number from 1 again,
with `DELETE` as the fallback for hosts that withhold the `DROP` privilege
`TRUNCATE` needs.

## Documentation

- `docs/API-FIELD-MAP.md` — every API field, its column, and what the existing
  frontend does with it, plus the related-data schemas and what is deliberately
  not stored.
- Institutions → Documentation renders the same map from the plugin's own field
  definitions, so it describes the mapping actually in force. It opens with
  **Putting it on the site**: the two blocks and both their Inspector Controls,
  the three shortcodes underneath them and their attributes, then the live
  values of the layout, page size, directory page and URL base — read from the
  settings rather than written down, so that screen cannot describe a
  configuration this site does not have.
- Every file carries its reasoning in its own header comment. The ones worth
  reading first are `includes/frontend.php` (the shortcodes and the institution
  URLs), `includes/blocks.php` and `assets/js/blocks.js` (the two Gutenberg
  blocks), `assets/css/sacscoc-institutions.css` (where the design comes from)
  and `includes/icons.php` (the icon set and how to replace it).

## Deployment

`../../.github/workflows/deploy-sacscoc-institutions.yml` deploys this
directory, and only this directory, to
`/public_html/wp-content/plugins/sacscoc-institutions`. A push touching only
`plugins/ai-documents/**` does not trigger it.
