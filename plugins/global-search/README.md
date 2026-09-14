# Global Search

A standalone, generic search plugin. It knows nothing about SACSCOC,
policies, or any other concrete project — it defines a small provider
contract, ships a WordPress-native provider that always works, and adapts to
the Policies (`ai-documents`) and Institutions (`sacscoc-institutions`)
plugins only when it detects them active. Neither is required.

## Architecture

```
Global Search Service
  └─ get_providers()  ← apply_filters( 'global_search_providers', [...] )
       ├─ Global_Search_WordPress_Provider     always available
       ├─ Global_Search_Documents_Provider     available iff AIDOCS_VERSION is defined
       └─ Global_Search_Institutions_Provider  available iff SACSCOC_INST_VERSION is defined + tables ready
```

Every provider implements `Global_Search_Provider`
(`includes/interface-provider.php`):

```php
interface Global_Search_Provider {
    public function get_id(): string;
    public function get_label(): string;
    public function is_available(): bool;
    public function search( string $query, array $args = [] ): array;
}
```

Add a new source (WooCommerce, Events, a knowledge base, any custom post
type) without touching this plugin's core:

```php
add_filter( 'global_search_providers', function ( array $providers ) {
    $providers[] = new My_Events_Provider();
    return $providers;
} );
```

Every provider returns the same normalized shape (enforced by
`gsearch_normalize_result()` in `includes/functions.php`, which also strips
any HTML out of `excerpt`):

```json
{
  "source": "documents",
  "type": "policy",
  "title": "Substantive Change Policy",
  "url": "https://…",
  "excerpt": "…",
  "meta": {},
  "score": 0.7
}
```

## Providers

- **WordPress** (`includes/provider-wordpress.php`) — Pages and Posts
  (configurable, plus any additional public post type added via Settings or
  the `global_search_wordpress_post_types` filter), via `WP_Query`'s native
  `s` parameter. Always intersected with `get_post_types(['public'=>true])`
  and never includes `attachment`, so a private or internal post type can
  never leak in however it was added.
- **Documents** (`includes/provider-documents.php`) — an adapter over the
  Policies plugin's `aidoc` CPT. Reuses its own snippet builder
  (`aidocs_search_snippet()`) and plain-text extractor when present, matching
  title + the same `_document_content`/`_document_description`/
  `_document_summary` postmeta the plugin's own AJAX search matches. Does
  **not** call `aidocs_search_ajax()` itself — that's a request handler
  (nonce + `$_POST` + `wp_send_json`), not a reusable function — so this is
  the minimal adapter, not a duplicate engine.
- **Institutions** (`includes/provider-institutions.php`) — calls the
  Institutions plugin's own `sacscoc_inst_search()` over its already-synced
  local tables and `sacscoc_inst_permalink()` for the URL. Never queries the
  SACSCOC API directly — the sync pipeline is entirely that plugin's job.

Each provider guards its `is_available()` on the other plugin's own version
constant (`AIDOCS_VERSION` / `SACSCOC_INST_VERSION`), so a missing or
inactive plugin is skipped, never a fatal error.

## Replacing the site's header search

The header search on this site is **not** Astra's. Astra's header builder has
no search element enabled (`header-desktop-items.primary.primary_right` is
`["menu-1", "social-icons-1"]`). It is an **Elementor Search widget** inside
the Theme Builder header template **`*GLOBAL_Header (All Pages)`**
(`elementor_library` post **75**), at
`container#9867790 > container#6927bca > widget/search#6ef4bda`, configured
with:

| Setting | Value |
| --- | --- |
| `search_input_placeholder_text` | `Search Site ...` |
| `submit_button_text` | `GO` |

It submits `?s=` to WordPress core search, which lands on the Theme Builder
template `*GLOBAL_Search Results Template` (post 748) — a Posts widget. That
reaches pages, posts and policies (`aidoc` is a public post type), and cannot
reach institutions at all: those live in this monorepo's own
`wp_sacscoc_*` tables, which `WP_Query` has never heard of.

### The "subscriber" display condition is stored but switched off

The widget carries this in its settings:

```json
"display_condition_list": [ { "display_condition_login_status": "subscriber", "_id": "1b98b8b" } ]
```

That is **not an active restriction**. It belongs to Ultimate Addons for
Elementor (`ultimate-elementor/modules/display-conditions/display-conditions.php`),
not to Elementor Pro, and that module only evaluates the list when
`display_condition_enable === 'yes'`:

```php
if ( isset( $settings['display_condition_enable'] ) && 'yes' === $settings['display_condition_enable'] ) {
```

The widget has no `display_condition_enable` key at all, so the filter returns
`$should_render` untouched. The stored row is UAEL's own hard-coded default for
the repeater (`role` / `is` / `subscriber`) — what the control shows before
anyone configures it. **Every visitor sees the header search today**, which a
logged-out request against the local copy confirms.

So there is nothing to preserve or decide here: "keep the condition" and "show
it to everyone" describe the same current behaviour. Removing the widget
removes the stored row with it.

### The swap, once approved

Nothing here has been changed — `*GLOBAL_Header (All Pages)` is untouched.

1. Elementor → Templates → Theme Builder → **`*GLOBAL_Header (All Pages)`**.
2. Select the **Search** widget in the right-hand container (`#6ef4bda`).
3. Replace it with a **Shortcode** widget in the same container, carrying:

   ```
   [global_search variant="compact" shape="rounded" button_label="GO" placeholder="Search Site ..." show_filters="no"]
   ```

   The placeholder and button label are deliberately the ones the header
   already uses: the results behind the box change, the control visitors know
   does not.
4. Settings → Global Search → **Results page** must name the page carrying
   `[global_search]`. Without it the box falls back to WordPress's own `?s=`
   search — plainer, never broken, but not the point of the exercise.
5. Check the container the widget sits in does not clip the dropdown:
   Advanced → Layout → **Overflow: Default** (not Hidden). The panel is
   positioned `absolute`, deliberately (see the stylesheet's own note), so a
   `hidden` overflow on an ancestor is the one thing that can cut it off.

### What changes for a visitor

| | Today | After |
| --- | --- | --- |
| Searches | Pages, posts, policies | Pages, posts, policies **and institutions** |
| Feedback | None until the results page | A grouped dropdown after 3 characters |
| GO / Enter | `/?s=…` → theme's results template | `/site-search/?q=…` → full results with source filters |
| Without JavaScript | Works | Works — it is a plain GET form either way |

## Shortcode

```
[global_search]
[global_search show_filters="yes" sources="wordpress,documents,institutions" results_per_page="20" variant="default" shape="rectangular"]
[global_search variant="compact" shape="rounded" button_label="GO" placeholder="Search Site ..." show_filters="no"]
```

| Attribute           | Default        | Notes                                          |
| ------------------- | -------------- | ----------------------------------------------- |
| `show_filters`      | `yes`          | `no`/`false`/`0` hides the source filter links  |
| `sources`            | *(all)*        | Comma list of provider ids to restrict to       |
| `results_per_page`   | 10             | Per-source cap, not a total — see Ranking below |
| `results_url`        | *(settings)*   | Where the form submits. A path, a page id, or a URL on this site; an off-site value is ignored. Empty → Settings → Results page, and failing that WordPress's own `?s=` search |
| `results`            | `auto`         | `dropdown` (a typeahead), `inline` (a results page), or `auto`: inline when the URL carries `?q=`, dropdown otherwise. `compact` is always a dropdown |
| `max_results`        | *(per variant)*| Rows the dropdown shows before "View all results". 6 for `compact`, `results_per_page` otherwise |
| `view_all`           | *(auto)*       | `no` drops the "View all results" link. On by default in a dropdown once there is a results page to link to |
| `variant`            | `default`      | `compact` — narrower, for a header or a navbar  |
| `placeholder`        | *(per variant)*| Overrides the input's placeholder text          |
| `button_label`       | `Search`       | The submit button's visible text. The theme's current header search says `GO`; a replacement that quietly renames a control visitors already know is a worse replacement. The button keeps `aria-label="Search"` either way |
| `shape`              | `rectangular`  | `rounded` — a pill-shaped input/button, joined at the seam, matching the existing Institutions navbar search widget |

### Two modes

```
[global_search]                    a search box; results in a dropdown
[global_search variant="compact"]  the same, sized and capped for a header
[global_search]  on /…/?q=term     a results page: filters, all results, open
```

The third is not a different shortcode. `results="auto"` (the default) looks
for `q` in the URL — this plugin's own parameter, never WordPress's `s` — and
renders inline when it finds one. That is what makes a page carrying a bare
`[global_search]` work as the destination for every other box on the site
without being configured as one.

### The form always works

`method="get"`, a real `action`, and a field named `q`. Submitting is a
navigation, not a fetch, so GO and the Enter key reach the results page with
the script absent, broken or still loading. The dropdown is layered over that,
never in front of it. The one exception is a default-variant box on a page
with no results page configured anywhere, which has nowhere to navigate to and
searches in place instead; `data-submit` on the wrapper says which case a given
box is.

### The dropdown

Opens after `min_characters` (3) and a `debounce_ms` (300) pause, capped at
`max_results` rows dealt **round-robin across sources** rather than sliced off
the top: a global `slice(0, 6)` of a score-ranked list lets one source take
every row, so "accreditation" would report that the site has no policies about
accreditation. Ends with "View all results →", pinned below the scroll area,
pointing at `<results page>?q=…`.

States are shown where the rows would be — `Searching…`, `No results found`,
`Search is temporarily unavailable.` — and never before a search has been
asked for. A failed request never takes the header down with it: the panel
says so and the form underneath still submits.

Keyboard: `↓`/`↑` move a row (`aria-selected`, `aria-activedescendant`), Enter
follows the selected row or, with none selected, submits the form; `Escape`
and a click outside close it. The input is a `role="combobox"` with
`aria-expanded`/`aria-controls` over a `role="listbox"`, and a polite live
region carries the same states to a screen reader.

Widths, by breakpoint:

| | Panel |
| --- | --- |
| Desktop | `max(box, 340px)`, growing **leftward** from the box's right edge — a 320px header box is too narrow to read results in |
| ≤ 782px | capped at `min(360px, 100vw − 32px)` |
| ≤ 600px | back to the box's own left and right edges, which on a phone header is most of the width and is inside the screen by construction |

The panel is `position: absolute` at every width, deliberately. `fixed` would
escape a header with `overflow: hidden` — the one thing that can clip it — but
inside any transformed or filtered ancestor (a sticky header, an animation)
`fixed` is positioned against *that ancestor* rather than the viewport and
lands somewhere arbitrary. Absolute is predictable everywhere, and the header
needing `overflow: visible` is documented rather than guessed at.

Renders only the search box; results are never on the page by default.
Nothing is queried until a real search runs (empty query ⇒ zero results from
every provider, no provider even called), and there is no visible "No
results" placeholder either — see "Presentation" below.

### Presentation

Results render into a dropdown panel anchored under the input — a typeahead,
not an always-visible block of markup on the page. It opens only once a
search returns at least one result, and closes again when the query is
cleared, when there are zero results, on outside click, or on Escape.
There is deliberately no visible "No results" line: an empty result set is
announced to screen readers only, through the (visually hidden)
`.global-search__status` region, so requirement #22's accessible
loading/empty states are still met without printing copy nobody asked to
see.

The "All | Policies | Institutions | Site" filters render as plain
underline-on-hover text links inside the panel (`.global-search__filter`),
not button chips — deliberately understated since they sit right above a
list of results, not as a separate UI element competing with the search box
itself.

## Gutenberg block

`Global Search` (`global-search/search`), a dynamic block with the same
attributes as the shortcode, rendered through the identical
`gsearch_render()` function — a block and the shortcode can never show
different markup for the same settings. Inspector controls: Placeholder,
Show source filters, Results per page, Variant, Shape (Rectangular /
Rounded), and a Sources checklist built from whichever providers are
actually available right now (a deactivated plugin's checkbox never
appears).

The block registers its stylesheet through `register_block_type()`'s own
`style` argument (`includes/blocks.php`) rather than only enqueueing it on
the front end — that's what gets the real `global-search.css` loaded inside
the block editor's iframe, so the editor preview matches the published page
instead of falling back to unstyled browser defaults.

## REST API

```
GET /wp-json/global-search/v1/search?q=accreditation
GET /wp-json/global-search/v1/search?q=accreditation&source=documents
GET /wp-json/global-search/v1/search?q=accreditation&sources=documents,institutions&results_per_page=20
GET /wp-json/global-search/v1/providers
```

Public, read-only, every parameter sanitized. Response:

```json
{
  "query": "accreditation",
  "total": 18,
  "counts": { "all": 18, "documents": 7, "institutions": 3, "wordpress": 8 },
  "results": [ /* normalized result objects, grouped by source */ ]
}
```

This is the first REST route in the monorepo — the other two plugins use
AJAX / plain GET-form submission. Chosen here because the brief asked for
REST explicitly and a stateless GET search is exactly what it's for.

## Filters (All / Policies / Institutions / Site)

Labels are never hardcoded in the frontend — they come from
`get_provider_metadata()`, which reads each provider's `get_label()` unless
overridden in Settings. **Switching a tab never triggers a new request**:
every search already asks every allowed provider at once (each capped
server-side), so the tabs just re-render the one cached response, grouped or
filtered by `result.source` in `assets/js/global-search.js`. This only holds
because V1 has no per-source pagination; if that's ever added, tab switches
for a paginated source would need their own request — noted here rather than
guessed at.

## Ranking

Scores are **not comparable across providers** — a WordPress title-match
0.7 and an Institutions name-match 0.7 carry no shared meaning. V1 does not
attempt to interleave them. Results are grouped by source (in
provider-registration order: WordPress, Documents, Institutions, then
whatever a filter appends) and sorted by score only *within* one provider's
own list, using a simple exact-title / partial-title / content-only
heuristic (`score()` in each provider). No cross-source ranking model is
built — there is no real signal for one yet.

## Admin settings

**Settings → Global Search**:

| Setting | What it does |
| --- | --- |
| Search Posts / Search Pages | Which core post types the WordPress provider covers |
| Results per source | The per-provider cap, not a total — see Ranking |
| Per-provider enable + label | Turn a source off, or rename it in the filters |
| **Results page** | The page carrying `[global_search]`, where every compact box sends a search and where "View all results" points. None → WordPress's own `?s=` search |
| **Rows in the compact dropdown** | How many matches a header box shows before "View all results". 6 by default |
| Live search | On/off, minimum characters (3), debounce ms (300) |

Changing any of these takes effect immediately: the five-minute result cache is
keyed on the settings as well as the query, so turning a provider off does not
leave it answering from cache.

## Independence

Each provider's `is_available()` guards on the other plugin's own version
constant, so Global Search runs correctly with any subset of
{Documents, Institutions} active or inactive — see `tests/harness-standalone.php`.

## Extending

- New source: implement `Global_Search_Provider`, append it via
  `global_search_providers`.
- New WordPress post type in the default provider: `Settings → Global
  Search`, or `add_filter( 'global_search_wordpress_post_types', ... )`.
- AI Chat (not built here on purpose — see brief section 19): call
  `gsearch_service()->search( $query, $args )` directly; it returns the same
  normalized array the REST endpoint serializes, ready to hand to an LLM as
  context.
