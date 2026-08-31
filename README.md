# Cirlot WordPress plugins

A monorepo holding the WordPress plugins built for Cirlot. Each plugin under
`plugins/` is a complete, standalone WordPress plugin: its own main file, its
own version, its own database tables and options, its own deploy workflow. No
plugin requires another to be installed or active.

```
plugins/
├── ai-documents/          AI Documents — document library with AI-assisted
│                          metadata, semantic search and a conversational finder
└── sacscoc-institutions/  SACSCOC Institutions — synchronises the SACSCOC
                           institution directory into WordPress and publishes it
```

| Plugin                  | Directory / slug            | Main file                       | Version |
| ----------------------- | --------------------------- | ------------------------------- | ------- |
| AI Documents            | `ai-documents`              | `ai-documents.php`              | 1.4.0   |
| SACSCOC Institutions    | `sacscoc-institutions`      | `sacscoc-institutions.php`      | 0.9.0   |
|                         |                             |                                 |         |

Versions move independently. A release of one plugin never requires a version
bump of the other; each plugin's version lives only in its own plugin header
(and is read from there by its build script, so nothing can drift).

Each plugin keeps its own `README.md` in its directory — that is the place to
look for what the plugin does and how it is configured.

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
