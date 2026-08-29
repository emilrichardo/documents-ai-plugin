# SACSCOC Institutions

Keeps a local copy of the SACSCOC institution directory in WordPress,
synchronised from the SACSCOC API.

The API is the source of truth; WordPress holds a copy. Visitors are never sent
to the API — the directory reads local tables, so it stays fast and stays up
when the API does not.

- **Version** 0.2.0
- **Requires** PHP 8.0, WordPress 6.0
- **Depends on nothing.** No shared code, tables, options or hooks with the AI
  Documents plugin in this repository. Either can be installed, activated,
  updated and deployed without the other.
- **No AI**, by design.

## What is in this release

The synchronisation layer, the admin screens, and the public directory.

Not yet included: **off-campus instructional sites** and the **review / meeting
history** on institution pages. Both need per-institution API calls — about
3,600 requests for the full dataset — so they need a batched, resumable sync
rather than the single request the institutions themselves take.

| Screen | What it is for |
| --- | --- |
| Institutions → All Institutions | The local copy, searchable and filterable. Clicking one opens its record: a read-only screen showing every stored field, grouped into what the institution is (main column) and what the record is (side column). Inspection, not editing. |
| Institutions → Sync | Status, `Sync Now`, and the log of recent runs. |
| Institutions → Settings | API Base URL, Sync Frequency, API Timeout, the directory page, layout and page size, the URL base, and the shared footer content. |
| Institutions → Documentation | The API, the field map, and how a sync decides what to write. |

## The public directory

Put the shortcode on any WordPress page and that page becomes the directory:

```
[sacscoc_institutions]
```

Attributes, all optional — each one overrides its Settings value for that page
only:

| Attribute | Values | Default |
| --- | --- | --- |
| `layout` | `two-column`, `one-column` | **Settings → Directory Layout** |
| `per_page` | 1–200 | **Settings → Results Per Page** (25) |
| `show_count` | `yes`, `no` | `yes` |

```
[sacscoc_institutions layout="one-column" per_page="50"]
```

Both travel with the markup as data attributes and are posted back with every
live filter, so a page listing 50 keeps listing 50 after the first keystroke
instead of quietly reverting to the default.

Institution pages are served at `/institutions/<slug>/` — the base is
configurable in Settings — and render inside the theme's own `get_header()` and
`get_footer()`, so they inherit the site's chrome and typography. Set
**Settings → Directory Page** so institution pages know where to link "back to
all institutions".

The four filters are the ones the existing site offers: institution name, state
(including the *International* pseudo-option), highest degree offered, and next
reaffirmation year. Each is a `si_`-prefixed query parameter, so every result
set is a shareable URL and the back button behaves. Unrecognised values are
dropped rather than passed to the query.

The reaffirmation-year dropdown is built from the years that actually have
institutions behind them. The current site hardcodes 2021–2036 and the first
five of those now return nothing.

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

## Documentation

- `docs/API-FIELD-MAP.md` — every API field, its column, and what the existing
  frontend does with it, plus the related-data schemas and what is deliberately
  not stored.
- Institutions → Documentation renders the same map from the plugin's own field
  definitions, so it describes the mapping actually in force.

## Deployment

`../../.github/workflows/deploy-sacscoc-institutions.yml` deploys this
directory, and only this directory, to
`/public_html/wp-content/plugins/sacscoc-institutions`. A push touching only
`plugins/ai-documents/**` does not trigger it.
