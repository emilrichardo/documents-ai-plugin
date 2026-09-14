# Cirlot WordPress plugins

A monorepo holding the WordPress plugins built for Cirlot. Each plugin under
`plugins/` is a complete, standalone WordPress plugin: its own main file, its
own version, its own database tables and options, its own deploy workflow. No
plugin requires another to be installed or active.

```
plugins/
├── ai-documents/          AI Policies — policy library with AI-assisted
│                          metadata, semantic search and a conversational finder
├── sacscoc-institutions/  SACSCOC Institutions — synchronises the SACSCOC
│                          institution directory into WordPress and publishes it
└── global-search/         Global Search — a generic unified search box, with
                           WordPress/Policies/Institutions as its first three
                           providers behind a provider interface
```

| Plugin                  | Directory / slug            | Main file                       | Version |
| ----------------------- | --------------------------- | ------------------------------- | ------- |
| AI Policies             | `ai-documents`              | `ai-documents.php`              | 1.6.0   |
| SACSCOC Institutions    | `sacscoc-institutions`      | `sacscoc-institutions.php`      | 0.10.0  |
| Global Search           | `global-search`              | `global-search.php`             | 0.2.1   |
|                         |                             |                                 |         |

Global Search deliberately depends on neither other plugin: it detects
Policies/Institutions at runtime through a provider interface and runs on
WordPress content alone if either (or both) is missing. See its own
`README.md` for how that provider system works.

AI Policies was called AI Documents through 1.4.0. Everything a reader or an
editor sees — the plugin's name, its menu, its public URLs — was renamed in
1.5.0; the directory, the slug, the text domain and every internal identifier
stayed `ai-documents` / `aidocs_*` on purpose, so the rename needed no data
migration. See that plugin's own `README.md` for the full reasoning.

Versions move independently. A release of one plugin never requires a version
bump of the other; each plugin's version lives only in its own plugin header
(and is read from there by its build script, so nothing can drift).

Each plugin keeps its own `README.md` in its directory — that is the place to
look for what the plugin does and how it is configured.

## One pattern the three share: the form here, the results there

All three plugins now answer the same question the same way, on purpose. A
search box does not have to sit on top of the results it produces — a
promotional page can offer the search and send the visitor to whichever page
actually lists things:

```
a form, on a page with no results       →  a URL  →  the page that lists them

[sacscoc_institution_search results_url="/institutions/"]  →  /institutions/?si_q=…
[aidocs_policy_search results_url="/policies/"]            →  /policies/?q=…&type=…
[global_search variant="compact"]                          →  /site-search/?q=…
```

Each plugin resolves that destination through its own function
(`sacscoc_inst_results_url()`, `aidocs_search_results_url()`,
`gsearch_results_url()`), and all three accept the same four things and treat
them identically:

| Given | Resolves to |
| --- | --- |
| nothing | that plugin's own "where the listing lives" setting |
| `"123"` | that page, if it is published |
| `"/institutions/"` | a site-relative path |
| `https://thissite/...` | an absolute URL, **but only on this site** |

An off-site URL is ignored rather than honoured, in all three: this is a
visitor's search, and a typo in a shortcode attribute must not be able to send
it to a third party.

The code is deliberately *not* shared — the plugins stay standalone, which is
the whole premise of this repo — so these are three parallel implementations of
one contract. The point of writing them alike is that an editor configuring
this site learns it once, not three times.

Each receiving page reads its parameters back, refills its own fields and runs
the search on arrival, so a redirect never costs the visitor their query.

## Embedded form appearance

Institutions Search and Policies Search share the same optional visual API:

| Attribute | Values |
| --- | --- |
| `theme` | `light`, `dark` |
| `layout` | `horizontal`, `vertical` |
| `size` | `compact`, `default`, `large` |
| `width` | `auto`, `contained`, `full` |
| `show_labels` | `yes`, `no` |

Omitting these options preserves each plugin's existing presentation, including
its label and width defaults. Hidden labels remain available to assistive
technology. Valid explicit width options take priority over Institutions'
older `contain_width` option. The existing Institutions layout aliases remain
supported.

For a dark institution hero:

```text
[sacscoc_institution_search results_url="/institutions/" show_heading="no" theme="dark" layout="vertical" size="compact" width="full" show_labels="yes"]
```

For a larger policy search section:

```text
[aidocs_policy_search results_url="/policies/" theme="light" layout="horizontal" size="large" width="contained" show_labels="yes"]
```

These options work in Elementor Shortcode widgets. The Institutions Search
block exposes the same controls; Policies keeps a reusable presentation helper
for a future form block. Each plugin owns its scoped CSS variables and works
independently. Search parameters, destinations, result rendering and Global
Search are unaffected. See each plugin's README for its CSS tokens.

## Deployment

`.github/workflows/deploy-<slug>.yml` deploys one plugin, over SFTP, to
`/public_html/wp-content/plugins/<slug>` on staging. The `paths:` filter is
what keeps them independent: a push touching only
`plugins/sacscoc-institutions/**` runs the Institutions workflow and leaves
Documents alone. Either workflow can also be run by hand from the Actions tab.

The remote directory names match the directory names here, so the plugin slugs
WordPress stores in `active_plugins` are unchanged by this layout — nothing is
deactivated by a deploy.

Shared secrets: `SFTP_HOST`, `SFTP_PORT`, `SFTP_USERNAME`, `SFTP_PASSWORD`. A
GitHub secret is write-only once saved — nobody can read the value back,
including whoever set it — so deploying never requires knowing it. The two
scripts below reflect that: one needs it, one does not.

### `scripts/publish-plugin.sh` — the everyday way, no credentials needed

Commits one plugin's local changes and pushes to `main`, which is all
deploying takes: the GitHub Actions workflow above is already watching for
exactly that push and does the actual SFTP upload itself. Nothing here ever
touches `SFTP_PASSWORD` or needs to.

```bash
scripts/publish-plugin.sh sacscoc-institutions "Add the Layout control to the search block"
# or, one command each:
scripts/publish-sacscoc.sh "…"
scripts/publish-ai-documents.sh "…"
```

Only stages `plugins/<slug>/` — never `-A` — so it can never sweep in a change
sitting somewhere unrelated in the working tree. If a workflow run fails after
pushing, `gh run watch` (the script prints the exact command) or the Actions
tab shows why.

### `scripts/deploy-plugin.sh` — instant preview, if you hold the SFTP password

Pushes a plugin's local working copy straight to the demo site over SFTP,
bypassing git entirely — useful for checking work before it is worth a commit,
but only usable by whoever actually has `SFTP_PASSWORD` (typically whoever
manages the site's hosting; it is not recoverable from the GitHub secret).

```bash
cp .env.example .env   # fill in the four values
scripts/deploy-plugin.sh sacscoc-institutions
# or: scripts/deploy-sacscoc.sh / scripts/deploy-ai-documents.sh
```

`.env` is git-ignored; nothing here is ever committed. Since this bypasses git,
anything pushed this way that never gets committed is overwritten by the next
`publish-plugin.sh` (or any other push to `main`) — `main` stays the source of
truth for what is actually live.

No custom upload API for either script, on purpose: the demo host only accepts
password-authenticated SFTP (no shell, no keys — which is also why
`deploy-plugin.sh` needs `sshpass`, not plain `ssh`/`rsync`), so a hand-rolled
HTTP endpoint on the WordPress side would be new attack surface on the live
site for no real gain over the transport it would still have to tunnel through
underneath.

## Local development

The repository is checked out inside the local WordPress install, at
`wp-content/plugins/cirlot-plugins`, and each plugin is symlinked into
`wp-content/plugins/` under its real slug so WordPress sees them the same way
it does on staging:

```bash
cd wp-content/plugins
ln -s cirlot-plugins/plugins/ai-documents         ai-documents
ln -s cirlot-plugins/plugins/sacscoc-institutions sacscoc-institutions
```

Edit files in the repository; WordPress picks them up through the symlink.
Because the checkout itself has no plugin header at its root, WordPress does
not list `cirlot-plugins` as a plugin.

## Adding a plugin

1. `plugins/<slug>/<slug>.php` with a WordPress plugin header, `Version: 0.1.0`.
2. Prefix every option, table, hook, cron event and function with something
   unique to that plugin, so two plugins can never collide.
3. Copy a `deploy-<slug>.yml` from an existing one and change the slug in the
   three places it appears.
4. Add the row to the table above and symlink it locally.
