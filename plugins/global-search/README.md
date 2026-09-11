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

## Shortcode

```
[global_search]
[global_search show_filters="yes" sources="wordpress,documents,institutions" results_per_page="20" variant="default"]
```

| Attribute           | Default   | Notes                                          |
| ------------------- | --------- | ----------------------------------------------- |
| `show_filters`      | `yes`     | `no`/`false`/`0` hides the source tabs          |
| `sources`            | *(all)*   | Comma list of provider ids to restrict to       |
| `results_per_page`   | 10        | Per-source cap, not a total — see Ranking below |
| `variant`            | `default` | `compact` is reserved for a future header use   |

Renders only the search box + an empty "No results" state. Nothing is
queried until a real search runs (empty query ⇒ zero results from every
provider, no provider even called).

## Gutenberg block

`Global Search` (`global-search/search`), a dynamic block with the same
attributes as the shortcode, rendered through the identical
`gsearch_render()` function — a block and the shortcode can never show
different markup for the same settings. Inspector controls: Placeholder,
Show source filters, Results per page, Variant, and a Sources checklist
built from whichever providers are actually available right now (a
deactivated plugin's checkbox never appears).

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

**Settings → Global Search**: search Posts / search Pages toggles, results
per source, per-provider enable + label override, and live-search
(on/off, minimum characters, debounce ms).

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
