<?php
/**
 * Documents → Documentation — the manual, inside the plugin.
 *
 * The page renders docs/generated/admin-page.html, which tools/build-docs.php
 * produces from docs/DOCUMENTATION.md — the same source, the same wording and
 * the same screenshots as the standalone landing page in docs/index.html. Only
 * the stylesheet differs: this one is WordPress-admin flavoured, and sections
 * marked `<!-- only:landing -->` in the source (the plugin download, which has
 * nothing to offer someone already running the plugin) never reach it.
 *
 * Nothing here is authored by hand. To change what this page says, edit
 * docs/DOCUMENTATION.md and run `bash tools/build-docs.sh`.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AIDOCS_DOCS_FRAGMENT', AIDOCS_DIR . 'docs/generated/admin-page.html' );
define( 'AIDOCS_DOCS_URL', AIDOCS_URL . 'docs/' );

add_action( 'admin_menu', 'aidocs_documentation_menu' );
function aidocs_documentation_menu() {
    add_submenu_page(
        'edit.php?post_type=aidoc',
        __( 'AI Documents Documentation' ),
        __( 'Documentation' ),
        'edit_posts',
        'aidocs-documentation',
        'aidocs_documentation_page'
    );
}

function aidocs_documentation_page() {
    if ( ! current_user_can( 'edit_posts' ) ) return;

    $html = is_readable( AIDOCS_DOCS_FRAGMENT ) ? file_get_contents( AIDOCS_DOCS_FRAGMENT ) : '';
    ?>
    <div class="wrap aidocs-docs-page">
        <h1><?php esc_html_e( 'Documentation' ); ?>
            <span class="aidocs-docs-version"><?php echo esc_html( 'v' . AIDOCS_VERSION ); ?></span>
        </h1>

        <?php if ( $html === '' ) : ?>
            <div class="notice notice-warning"><p>
                <?php
                printf(
                    /* translators: %s: build command */
                    esc_html__( 'The documentation has not been generated yet. Run %s in the plugin folder.' ),
                    '<code>bash tools/build-docs.sh</code>'
                );
                ?>
            </p></div>
        <?php else : ?>
            <p class="aidocs-docs-intro">
                <?php esc_html_e( 'Everything below describes this plugin as it is installed right now. It is generated from the plugin\'s own manual, so it stays in step with the version you are running.' ); ?>
            </p>
            <div class="aidocs-docs-layout">
                <?php
                // Generated from our own Markdown by our own build script — no
                // external or user-supplied HTML ever reaches this echo.
                echo str_replace( '%%AIDOCS_DOCS_URL%%', esc_url( AIDOCS_DOCS_URL ), $html ); // phpcs:ignore WordPress.Security.EscapeOutput
                ?>
            </div>
        <?php endif; ?>
    </div>

    <style>
    .aidocs-docs-page h1 .aidocs-docs-version{
        font-size:12px;font-weight:600;vertical-align:middle;margin-left:8px;padding:3px 9px;
        border-radius:999px;background:#f0f6ff;color:#2271b1;
    }
    .aidocs-docs-page .aidocs-docs-intro{max-width:70ch;color:#646970;font-size:13px;margin:6px 0 20px;}

    .aidocs-docs-layout{display:grid;grid-template-columns:220px minmax(0,1fr);gap:32px;align-items:start;max-width:1180px;}

    /* Contents rail */
    .aidocs-docs-page .aidoc-toc{position:sticky;top:52px;background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:14px 6px 14px 14px;}
    .aidocs-docs-page .aidoc-toc strong{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#8c8f94;margin-bottom:8px;}
    .aidocs-docs-page .aidoc-toc ul{margin:0;padding:0;list-style:none;max-height:calc(100vh - 160px);overflow-y:auto;}
    .aidocs-docs-page .aidoc-toc li{margin:0;}
    .aidocs-docs-page .aidoc-toc a{display:block;padding:4px 8px;border-radius:4px;color:#50575e;text-decoration:none;font-size:13px;line-height:1.4;}
    .aidocs-docs-page .aidoc-toc a:hover{background:#f0f0f1;color:#1d2327;}
    .aidocs-docs-page .aidoc-toc a.is-current{background:#f0f6ff;color:#2271b1;font-weight:600;}

    /* Body */
    .aidocs-docs-page .aidoc-body{min-width:0;}
    .aidocs-docs-page .aidoc-section{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:22px 28px 26px;margin-bottom:18px;scroll-margin-top:60px;}
    .aidocs-docs-page .aidoc-section h2{margin:0 0 14px;font-size:19px;line-height:1.3;color:#1d2327;}
    .aidocs-docs-page .aidoc-section h3{margin:26px 0 8px;font-size:15px;color:#1d2327;}
    .aidocs-docs-page .aidoc-section h4{margin:18px 0 6px;font-size:13px;color:#50575e;text-transform:none;}
    .aidocs-docs-page .aidoc-section p,
    .aidocs-docs-page .aidoc-section li{font-size:13.5px;line-height:1.7;color:#3c434a;}
    .aidocs-docs-page .aidoc-section p{margin:0 0 12px;max-width:80ch;}
    .aidocs-docs-page .aidoc-section ul{margin:0 0 14px;padding-left:20px;list-style:disc;}
    .aidocs-docs-page .aidoc-section li{margin-bottom:5px;}
    .aidocs-docs-page .aidoc-section hr{border:0;border-top:1px solid #e0e0e0;margin:22px 0;}

    /* Numbered procedures */
    .aidocs-docs-page ol.aidoc-steps{counter-reset:aidocstep;list-style:none;margin:0 0 16px;padding:0;}
    .aidocs-docs-page ol.aidoc-steps>li{counter-increment:aidocstep;position:relative;padding-left:34px;margin-bottom:10px;}
    .aidocs-docs-page ol.aidoc-steps>li::before{
        content:counter(aidocstep);position:absolute;left:0;top:2px;width:22px;height:22px;border-radius:50%;
        background:#2271b1;color:#fff;font-size:11px;font-weight:700;line-height:22px;text-align:center;
    }

    /* Notes */
    .aidocs-docs-page .aidoc-note{margin:14px 0;padding:10px 14px;background:#fcf9e8;border:1px solid #f0d191;border-left-width:3px;border-radius:4px;}
    .aidocs-docs-page .aidoc-note p{margin:0 0 8px;}
    .aidocs-docs-page .aidoc-note p:last-child{margin:0;}

    /* Code */
    .aidocs-docs-page code{background:#f0f0f1;border-radius:3px;padding:1px 5px;font-size:12px;font-family:ui-monospace,Consolas,monospace;}
    .aidocs-docs-page .aidoc-code{position:relative;margin:0 0 14px;}
    .aidocs-docs-page .aidoc-code pre{margin:0;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:12px 14px;overflow-x:auto;}
    .aidocs-docs-page .aidoc-code pre code{background:none;padding:0;font-size:12.5px;line-height:1.6;}
    .aidocs-docs-page .aidoc-copy{
        position:absolute;top:7px;right:7px;padding:3px 10px;border-radius:3px;border:none;
        background:#2271b1;color:#fff;font-size:11px;cursor:pointer;opacity:0;transition:opacity .12s;
    }
    .aidocs-docs-page .aidoc-code:hover .aidoc-copy,
    .aidocs-docs-page .aidoc-copy:focus{opacity:1;}
    .aidocs-docs-page .aidoc-copy.is-done{background:#46b450;}

    /* Tables */
    .aidocs-docs-page .aidoc-table-wrap{overflow-x:auto;margin:0 0 16px;border:1px solid #dcdcde;border-radius:4px;}
    .aidocs-docs-page .aidoc-table-wrap table{border-collapse:collapse;width:100%;font-size:12.5px;}
    .aidocs-docs-page .aidoc-table-wrap th{text-align:left;padding:7px 12px;background:#f0f0f1;border-bottom:1px solid #dcdcde;white-space:nowrap;}
    .aidocs-docs-page .aidoc-table-wrap td{padding:7px 12px;border-bottom:1px solid #f0f0f1;vertical-align:top;color:#50575e;}
    .aidocs-docs-page .aidoc-table-wrap tr:last-child td{border-bottom:0;}

    /* Screenshots */
    .aidocs-docs-page .aidoc-shot{margin:16px 0 20px;}
    .aidocs-docs-page .aidoc-shot img{display:block;max-width:100%;height:auto;border:1px solid #dcdcde;border-radius:5px;cursor:zoom-in;background:#f6f7f7;}
    .aidocs-docs-page .aidoc-shot figcaption{margin-top:6px;font-size:12px;color:#8c8f94;}
    .aidocs-docs-page .aidoc-shot.is-missing{display:none;}

    .aidocs-docs-lightbox{position:fixed;inset:0;z-index:100000;background:rgba(16,20,26,.9);display:flex;align-items:center;justify-content:center;padding:40px;}
    .aidocs-docs-lightbox img{max-width:100%;max-height:100%;border-radius:4px;}

    @media (max-width:960px){
        .aidocs-docs-layout{grid-template-columns:1fr;}
        .aidocs-docs-page .aidoc-toc{position:static;}
        .aidocs-docs-page .aidoc-toc ul{max-height:none;column-count:2;}
    }
    </style>

    <script>
    (function(){
        var page = document.querySelector('.aidocs-docs-page');
        if (!page) return;

        // Copy buttons on the shortcode examples.
        page.querySelectorAll('.aidoc-copy').forEach(function(btn){
            btn.addEventListener('click', function(){
                var code = btn.parentNode.querySelector('code');
                if (!code || !navigator.clipboard) return;
                navigator.clipboard.writeText(code.textContent).then(function(){
                    var was = btn.textContent;
                    btn.textContent = <?php echo wp_json_encode( __( 'Copied' ) ); ?>;
                    btn.classList.add('is-done');
                    setTimeout(function(){ btn.textContent = was; btn.classList.remove('is-done'); }, 1600);
                });
            });
        });

        // A screenshot that has not been captured yet is hidden rather than
        // shown as a broken image.
        page.querySelectorAll('.aidoc-shot img').forEach(function(img){
            img.addEventListener('error', function(){ img.closest('.aidoc-shot').classList.add('is-missing'); });
            img.addEventListener('click', function(){
                var box = document.createElement('div');
                box.className = 'aidocs-docs-lightbox';
                var big = document.createElement('img');
                big.src = img.currentSrc || img.src;
                big.alt = img.alt;
                box.appendChild(big);
                box.addEventListener('click', function(){ box.remove(); });
                document.body.appendChild(box);
            });
        });

        // Highlight the section currently on screen in the contents rail.
        var links = {};
        page.querySelectorAll('.aidoc-toc a').forEach(function(a){ links[a.getAttribute('href')] = a; });
        var seen = new Set();
        var sections = page.querySelectorAll('.aidoc-section');
        if (!sections.length || !('IntersectionObserver' in window)) return;
        var io = new IntersectionObserver(function(entries){
            entries.forEach(function(e){ e.isIntersecting ? seen.add(e.target.id) : seen.delete(e.target.id); });
            var first = null;
            sections.forEach(function(s){ if (!first && seen.has(s.id)) first = s; });
            for (var k in links) links[k].classList.remove('is-current');
            if (first && links['#' + first.id]) links['#' + first.id].classList.add('is-current');
        }, { rootMargin: '-8% 0px -70% 0px' });
        sections.forEach(function(s){ io.observe(s); });
    })();
    </script>
    <?php
}
