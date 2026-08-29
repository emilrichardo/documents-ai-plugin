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
| SACSCOC Institutions    | `sacscoc-institutions`      | `sacscoc-institutions.php`      | 0.1.0   |
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

Shared secrets: `SFTP_HOST`, `SFTP_PORT`, `SFTP_USERNAME`, `SFTP_PASSWORD`.

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
