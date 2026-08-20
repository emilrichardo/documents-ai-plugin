<?php
/**
 * Documentation generator — one Markdown source, three outputs.
 *
 *   docs/DOCUMENTATION.md   (source, and the repository's own manual)
 *        │
 *        ├──▶ docs/index.html              standalone landing, publishable anywhere
 *        └──▶ docs/generated/admin-page.html   fragment for Documents → Documentation
 *
 * Both outputs carry the same HTML with the same class names; only the
 * stylesheet around them differs, so the landing and the in-plugin page can
 * never drift apart in wording, order or screenshots.
 *
 * Run it through tools/build-docs.sh, which builds the download zip first so
 * the landing can state its real filename and size.
 *
 * Usage: php tools/build-docs.php
 */

if ( PHP_SAPI !== 'cli' ) { fwrite( STDERR, "CLI only.\n" ); exit( 1 ); }

$root    = dirname( __DIR__ );
$src     = $root . '/docs/DOCUMENTATION.md';
$landing = $root . '/docs/index.html';
$adminfr = $root . '/docs/generated/admin-page.html';

if ( ! is_file( $src ) ) { fwrite( STDERR, "Missing $src\n" ); exit( 1 ); }

// ── Facts read from the plugin itself, never typed twice ──────────────────
$header  = file_get_contents( $root . '/ai-documents.php', false, null, 0, 2000 );
preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $header, $m );
$version = trim( $m[1] ?? '0.0.0' );
preg_match( '/^\s*\*\s*Plugin Name:\s*(.+)$/mi', $header, $m );
$plugin_name = trim( $m[1] ?? 'AI Documents' );
preg_match( '/^\s*\*\s*Description:\s*(.+)$/mi', $header, $m );
$tagline = trim( $m[1] ?? '' );

$zips     = glob( $root . '/docs/downloads/*.zip' );
$zip_file = $zips ? basename( $zips[0] ) : '';
$zip_size = $zips ? size_format_bytes( filesize( $zips[0] ) ) : '';
$built_on = date( 'j F Y' );

function size_format_bytes( $bytes ) {
    $units = [ 'B', 'KB', 'MB', 'GB' ];
    $i = 0;
    while ( $bytes >= 1024 && $i < 3 ) { $bytes /= 1024; $i++; }
    return ( $bytes >= 10 || $i === 0 ? round( $bytes ) : round( $bytes, 1 ) ) . ' ' . $units[ $i ];
}

// ──────────────────────────────────────────────────────────────────────────
// Markdown → HTML. A deliberately small subset — headings, paragraphs, lists,
// tables, fenced code, blockquotes, images, rules and inline emphasis — which
// is everything DOCUMENTATION.md uses and nothing else, so a surprise in the
// source shows up as visibly wrong output rather than as silent HTML.
// ──────────────────────────────────────────────────────────────────────────

function md_slug( $text ) {
    $text = strtolower( trim( strip_tags( $text ) ) );
    $text = preg_replace( '/[^a-z0-9]+/', '-', $text );
    return trim( $text, '-' );
}

/**
 * Two headings that reduce to the same slug would both answer to one anchor,
 * and every link to it would land on whichever came first. Renaming one of
 * them is the fix; silently suffixing the second would only move the surprise
 * to whoever wrote the link. Reported once, up front, against the source.
 */
function md_warn_duplicate_slugs( $markdown ) {
    $seen = [];
    preg_match_all( '/^#{2,6}\s+(.+)$/m', $markdown, $m );
    foreach ( $m[1] as $heading ) {
        $slug = md_slug( $heading );
        if ( isset( $seen[ $slug ] ) ) {
            fwrite( STDERR, sprintf(
                "  ⚠ duplicate anchor #%s — \"%s\" and \"%s\". Rename one; links to it are ambiguous.\n",
                $slug, $seen[ $slug ], trim( $heading )
            ) );
        }
        $seen[ $slug ] = trim( $heading );
    }
}

function md_inline( $text ) {
    // Code spans are lifted out first so nothing below rewrites their contents.
    $codes = [];
    $text = preg_replace_callback( '/`([^`]+)`/', function ( $m ) use ( &$codes ) {
        $key = "\x00C" . count( $codes ) . "\x00";
        $codes[ $key ] = '<code>' . htmlspecialchars( $m[1], ENT_QUOTES ) . '</code>';
        return $key;
    }, $text );

    $text = htmlspecialchars( $text, ENT_QUOTES );

    // Images before links: ![alt](src) also matches the link pattern.
    $text = preg_replace_callback( '/!\[([^\]]*)\]\(([^)\s]+)\)/', function ( $m ) {
        return '<img src="' . md_img_src( $m[2] ) . '" alt="' . $m[1] . '">';
    }, $text );

    $text = preg_replace_callback( '/\[([^\]]*)\]\(([^)\s]+)\)/', function ( $m ) {
        $ext = preg_match( '#^[a-z]+://#i', $m[2] ) ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . $m[2] . '"' . $ext . '>' . $m[1] . '</a>';
    }, $text );

    $text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
    $text = preg_replace( '/(?<![*\w])\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text );

    return strtr( $text, $codes );
}

/**
 * Screenshot paths are written once in the Markdown, relative to docs/, and
 * rewritten per output: the landing serves them from the same folder, the
 * admin page from the plugin's own URL. The token below is what the plugin
 * substitutes at render time.
 */
$GLOBALS['md_img_base'] = '';
function md_img_src( $src ) {
    if ( preg_match( '#^[a-z]+://#i', $src ) ) return $src;
    return $GLOBALS['md_img_base'] . $src;
}

function md_blocks( array $lines ) {
    $out = '';
    $i   = 0;
    $n   = count( $lines );

    while ( $i < $n ) {
        $line = $lines[ $i ];

        if ( trim( $line ) === '' ) { $i++; continue; }

        // ── HTML comment (directives live here; nothing is emitted) ──
        if ( strpos( ltrim( $line ), '<!--' ) === 0 ) {
            while ( $i < $n && strpos( $lines[ $i ], '-->' ) === false ) $i++;
            $i++;
            continue;
        }

        // ── Fenced code ──
        if ( preg_match( '/^```\s*([\w+-]*)\s*$/', $line, $m ) ) {
            $lang = $m[1];
            $buf  = [];
            $i++;
            while ( $i < $n && ! preg_match( '/^```\s*$/', $lines[ $i ] ) ) { $buf[] = $lines[ $i ]; $i++; }
            $i++;
            $out .= '<div class="aidoc-code"><button class="aidoc-copy" type="button" aria-label="Copy">Copy</button>'
                  . '<pre><code' . ( $lang ? ' class="lang-' . $lang . '"' : '' ) . '>'
                  . htmlspecialchars( implode( "\n", $buf ), ENT_QUOTES )
                  . "</code></pre></div>\n";
            continue;
        }

        // ── Headings (h2 opens a section upstream; h3/h4 land here) ──
        if ( preg_match( '/^(#{3,6})\s+(.+)$/', $line, $m ) ) {
            $lvl  = strlen( $m[1] );
            $text = trim( $m[2] );
            $out .= sprintf( "<h%d id=\"%s\">%s</h%d>\n", $lvl, md_slug( $text ), md_inline( $text ), $lvl );
            $i++;
            continue;
        }

        // ── Horizontal rule ──
        if ( preg_match( '/^(-{3,}|\*{3,}|_{3,})\s*$/', $line ) ) { $out .= "<hr>\n"; $i++; continue; }

        // ── Table ──
        if ( strpos( $line, '|' ) !== false && isset( $lines[ $i + 1 ] )
             && preg_match( '/^\s*\|?[\s:\-|]*-[\s:\-|]*$/', $lines[ $i + 1 ] ) ) {
            $rows = [];
            while ( $i < $n && strpos( $lines[ $i ], '|' ) !== false && trim( $lines[ $i ] ) !== '' ) {
                $rows[] = $lines[ $i ];
                $i++;
            }
            $cells = function ( $row ) {
                $row = trim( $row );
                $row = preg_replace( '/^\||\|$/', '', $row );
                return array_map( 'trim', explode( '|', $row ) );
            };
            $head = $cells( array_shift( $rows ) );
            array_shift( $rows ); // separator
            $out .= "<div class=\"aidoc-table-wrap\"><table>\n<thead><tr>";
            foreach ( $head as $c ) $out .= '<th>' . md_inline( $c ) . '</th>';
            $out .= "</tr></thead>\n<tbody>\n";
            foreach ( $rows as $r ) {
                $out .= '<tr>';
                foreach ( $cells( $r ) as $c ) $out .= '<td>' . md_inline( $c ) . '</td>';
                $out .= "</tr>\n";
            }
            $out .= "</tbody></table></div>\n";
            continue;
        }

        // ── Blockquote → callout ──
        if ( preg_match( '/^>\s?(.*)$/', $line ) ) {
            $buf = [];
            while ( $i < $n && preg_match( '/^>\s?(.*)$/', $lines[ $i ], $m ) ) { $buf[] = $m[1]; $i++; }
            $out .= '<blockquote class="aidoc-note">' . md_blocks( $buf ) . "</blockquote>\n";
            continue;
        }

        // ── Standalone image → figure, captioned with its alt text ──
        if ( preg_match( '/^!\[([^\]]*)\]\(([^)\s]+)\)\s*$/', trim( $line ), $m ) ) {
            $out .= '<figure class="aidoc-shot">'
                  . '<img src="' . md_img_src( $m[2] ) . '" alt="' . htmlspecialchars( $m[1], ENT_QUOTES ) . '" loading="lazy">'
                  . ( $m[1] !== '' ? '<figcaption>' . md_inline( $m[1] ) . '</figcaption>' : '' )
                  . "</figure>\n";
            $i++;
            continue;
        }

        // ── Lists ──
        if ( preg_match( '/^(\s*)([-*]|\d+\.)\s+/', $line ) ) {
            $buf = [];
            while ( $i < $n ) {
                $cur = $lines[ $i ];
                if ( trim( $cur ) === '' ) {
                    // A blank line only stays inside the list if the list continues after it.
                    $j = $i + 1;
                    while ( $j < $n && trim( $lines[ $j ] ) === '' ) $j++;
                    if ( $j < $n && preg_match( '/^(\s+|\s*([-*]|\d+\.)\s)/', $lines[ $j ] ) ) {
                        $buf[] = '';
                        $i     = $j;
                        continue;
                    }
                    break;
                }
                if ( ! preg_match( '/^(\s*)([-*]|\d+\.)\s+/', $cur ) && ! preg_match( '/^\s+\S/', $cur ) ) break;
                $buf[] = $cur;
                $i++;
            }
            $out .= md_list( $buf );
            continue;
        }

        // ── Paragraph ──
        $buf = [];
        while ( $i < $n && trim( $lines[ $i ] ) !== ''
                && ! preg_match( '/^(#{1,6}\s|```|>|\s*([-*]|\d+\.)\s)/', $lines[ $i ] )
                && strpos( ltrim( $lines[ $i ] ), '<!--' ) !== 0 ) {
            $buf[] = trim( $lines[ $i ] );
            $i++;
        }
        if ( $buf ) $out .= '<p>' . md_inline( implode( ' ', $buf ) ) . "</p>\n";
    }

    return $out;
}

/** Turns one run of list lines into nested <ul>/<ol>, one indent level at a time. */
function md_list( array $lines ) {
    $base = null;
    foreach ( $lines as $l ) {
        if ( preg_match( '/^(\s*)([-*]|\d+\.)\s+/', $l, $m ) ) { $base = strlen( $m[1] ); break; }
    }
    if ( $base === null ) return '';

    $ordered = false;
    $items   = [];   // each: array of its own lines, first one already stripped of the marker
    foreach ( $lines as $l ) {
        if ( preg_match( '/^(\s*)([-*]|\d+\.)\s+(.*)$/', $l, $m ) && strlen( $m[1] ) === $base ) {
            $ordered = $ordered || $m[2] !== '-' && $m[2] !== '*';
            $items[] = [ $m[3] ];
        } elseif ( $items ) {
            $items[ count( $items ) - 1 ][] = preg_replace( '/^\s{1,' . ( $base + 3 ) . '}/', '', $l );
        }
    }

    $tag  = $ordered ? 'ol' : 'ul';
    $html = "<$tag" . ( $ordered ? ' class="aidoc-steps"' : '' ) . ">\n";
    foreach ( $items as $item ) {
        $first = array_shift( $item );
        while ( $item && trim( end( $item ) ) === '' ) array_pop( $item );
        $rest  = $item;
        $block = (bool) array_filter( $rest, function ( $l ) {
            return trim( $l ) !== '' && preg_match( '/^(\s*([-*]|\d+\.)\s|```|\||!\[|>)/', $l );
        } );
        if ( ! $rest ) {
            $html .= '<li>' . md_inline( $first ) . "</li>\n";
        } elseif ( $block ) {
            $html .= '<li>' . md_inline( $first ) . md_blocks( $rest ) . "</li>\n";
        } else {
            $html .= '<li>' . md_inline( trim( $first . ' ' . implode( ' ', array_map( 'trim', $rest ) ) ) ) . "</li>\n";
        }
    }
    return $html . "</$tag>\n";
}

// ──────────────────────────────────────────────────────────────────────────
// Split the source into sections at every `## `, honouring `<!-- only:… -->`
// ──────────────────────────────────────────────────────────────────────────
function md_sections( $markdown ) {
    $lines    = explode( "\n", str_replace( "\r\n", "\n", $markdown ) );
    $title    = '';
    $intro    = [];
    $sections = [];
    $cur      = null;

    foreach ( $lines as $line ) {
        if ( preg_match( '/^#\s+(.+)$/', $line, $m ) && $title === '' ) { $title = trim( $m[1] ); continue; }
        if ( preg_match( '/^##\s+(.+)$/', $line, $m ) ) {
            if ( $cur ) $sections[] = $cur;
            $cur = [ 'title' => trim( $m[1] ), 'id' => md_slug( $m[1] ), 'only' => '', 'lines' => [], 'subs' => [] ];
            continue;
        }
        if ( $cur === null ) { $intro[] = $line; continue; }
        if ( preg_match( '/^<!--\s*only:\s*(\w+)\s*-->$/', trim( $line ), $m ) ) { $cur['only'] = $m[1]; continue; }
        if ( preg_match( '/^###\s+(.+)$/', $line, $m ) ) $cur['subs'][] = [ 'title' => trim( $m[1] ), 'id' => md_slug( $m[1] ) ];
        $cur['lines'][] = $line;
    }
    if ( $cur ) $sections[] = $cur;

    return [ $title, $intro, $sections ];
}

function md_render_sections( array $sections, $target ) {
    $html = '';
    foreach ( $sections as $s ) {
        if ( $s['only'] !== '' && $s['only'] !== $target ) continue;
        $html .= '<section class="aidoc-section" id="' . $s['id'] . '">' . "\n"
               . '<h2>' . md_inline( $s['title'] ) . "</h2>\n"
               . md_blocks( $s['lines'] )
               . "</section>\n";
    }
    return $html;
}

$markdown = file_get_contents( $src );
md_warn_duplicate_slugs( $markdown );
list( $doc_title, $intro_lines, $sections ) = md_sections( $markdown );

// ── Output 1: the in-plugin page fragment ────────────────────────────────
$GLOBALS['md_img_base'] = '%%AIDOCS_DOCS_URL%%';
$admin_nav  = '';
foreach ( $sections as $s ) {
    if ( $s['only'] !== '' && $s['only'] !== 'plugin' ) continue;
    $admin_nav .= '<li><a href="#' . $s['id'] . '">' . md_inline( $s['title'] ) . "</a></li>\n";
}
$admin_html = "<!-- Generated by tools/build-docs.php from docs/DOCUMENTATION.md. Do not edit. -->\n"
            . '<nav class="aidoc-toc"><strong>On this page</strong><ul>' . $admin_nav . "</ul></nav>\n"
            . '<div class="aidoc-body">' . md_render_sections( $sections, 'plugin' ) . "</div>\n";
file_put_contents( $adminfr, $admin_html );

// ── Output 2: the standalone landing ─────────────────────────────────────
$GLOBALS['md_img_base'] = '';
$nav = '';
foreach ( $sections as $s ) {
    if ( $s['only'] !== '' && $s['only'] !== 'landing' ) continue;
    $nav .= '<li><a href="#' . $s['id'] . '">' . md_inline( $s['title'] ) . '</a>';
    if ( $s['subs'] ) {
        $nav .= '<ul>';
        foreach ( $s['subs'] as $sub ) $nav .= '<li><a href="#' . $sub['id'] . '">' . md_inline( $sub['title'] ) . '</a></li>';
        $nav .= '</ul>';
    }
    $nav .= "</li>\n";
}

$intro_html = md_blocks( $intro_lines );
$body_html  = md_render_sections( $sections, 'landing' );

$download = $zip_file
    ? '<a class="aidoc-btn aidoc-btn-primary" href="downloads/' . htmlspecialchars( $zip_file, ENT_QUOTES ) . '" download>'
      . '<span class="aidoc-btn-icon">↓</span> Download the plugin'
      . '<small>' . htmlspecialchars( $zip_file . ' · ' . $zip_size, ENT_QUOTES ) . '</small></a>'
    : '';

$tpl = file_get_contents( __DIR__ . '/templates/landing.html' );
$landing_html = strtr( $tpl, [
    '{{TITLE}}'       => htmlspecialchars( $doc_title, ENT_QUOTES ),
    '{{PLUGIN_NAME}}' => htmlspecialchars( $plugin_name, ENT_QUOTES ),
    '{{TAGLINE}}'     => htmlspecialchars( $tagline, ENT_QUOTES ),
    '{{VERSION}}'     => htmlspecialchars( $version, ENT_QUOTES ),
    '{{BUILT_ON}}'    => htmlspecialchars( $built_on, ENT_QUOTES ),
    '{{DOWNLOAD}}'    => $download,
    '{{INTRO}}'       => $intro_html,
    '{{NAV}}'         => $nav,
    '{{BODY}}'        => $body_html,
] );
file_put_contents( $landing, $landing_html );

printf(
    "Documentation built\n  version   %s\n  sections  %d\n  landing   docs/index.html (%s)\n  admin     docs/generated/admin-page.html (%s)\n  download  %s\n",
    $version,
    count( $sections ),
    size_format_bytes( strlen( $landing_html ) ),
    size_format_bytes( strlen( $admin_html ) ),
    $zip_file ? 'docs/downloads/' . $zip_file . ' (' . $zip_size . ')' : '— none built —'
);
