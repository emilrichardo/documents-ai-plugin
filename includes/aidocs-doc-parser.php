<?php
/**
 * Policy document parser and renderer.
 *
 * Turns the text of a policy document into a uniform tree of content blocks
 * with regular expressions only — no AI, no per-document tuning.
 *
 * The input is the canonical text produced by assets/js/aidocs-pdf-structure.js,
 * which writes the layout of the PDF back into the text as markers:
 *
 *     # Document title
 *     Teaser: one-paragraph summary
 *     Body:
 *     ## SECTION IN CAPS            level-2 heading
 *     ### Section heading           level-3 heading
 *     #### Sub-heading              level-4 heading
 *     One paragraph per line, never hard-wrapped.
 *     1. ordered item               two spaces of indent per list level
 *       a. nested item
 *     - bulleted item
 *       a second paragraph of the item above
 *     | cell | cell |              table row
 *     Last Updated: June 2026 (Board of Trustees)
 *     Document History: Adopted … · Revised …
 *
 * Text pasted in by hand carries none of those markers, so every rule here has
 * a fallback that works on plain text: bullet glyphs, blank-line paragraph
 * breaks and the heading heuristics in aidocs_detect_heading().
 *
 * The block shapes produced are the plugin's storage format (post meta
 * `_document_content`) and the contract for the renderer:
 *
 *     [ 'type' => 'heading',   'level' => 2|3|4, 'text' => …, 'runs' => [], 'id' => …, 'note' => variant|'' ]
 *     [ 'type' => 'paragraph', 'text' => …, 'runs' => [] ]
 *     [ 'type' => 'note',      'variant' => …, 'label' => …, 'text' => …, 'runs' => [] ]
 *     [ 'type' => 'list',      'ordered' => bool, 'style' => …, 'items' => [ [ 'text', 'runs', 'blocks' ] ] ]
 *     [ 'type' => 'table',     'head' => [ … ], 'rows' => [ [ … ] ] ]
 *
 * A `runs` entry is a list of [ 'text' => …, 'b' => 1, 'i' => 1 ] spans and is
 * only present when the source had emphasis. `text` is always the plain text,
 * so search indexing and the AI context never need to know about runs.
 *
 * @package aidocs
 */

defined( 'ABSPATH' ) || defined( 'AIDOCS_PARSER_CLI' ) || exit;

/**
 * Labels that delimit the sections of a policy document.
 *
 * The source documents are authored in Word with these literal text labels, so
 * they survive PDF export and text extraction intact — unlike the light-blue
 * heading colour, which is a style property and is lost with the layout.
 * Matching on the labels is what makes extraction deterministic.
 */
const AIDOCS_DOC_LABELS = [ 'Teaser', 'Body', 'Last Updated', 'Document History' ];

/** Provenance lines belong to the document history even without their label. */
const AIDOCS_HISTORY_LINE = '/^(Adopted|Approved|Revised|Edited|Reformatted|Updated|Renamed|Reinstated|Amended)\b/i';

/**
 * Note labels, most specific first.
 *
 * Each entry maps a pattern to the variant the renderer styles by. The
 * documents use these labels both as section titles ("Note: Substantive
 * Change" on a line of its own) and inline ("Note: An application which fails
 * to provide evidence…"), and the same table recognises both.
 */
const AIDOCS_NOTE_PATTERNS = [
    'international'      => '/^Notes?\s+to\s+International\s+Institutions?\b/i',
    'substantive-change' => '/^Notes?\s*:?\s*Substantive\s+Change\b/i',
    'teach-out'          => '/^Notes?\s*:?\s*Institutional\s+Contingency\s+Teach[-\s]?Out\s+Plan\b/i',
    'restriction'        => '/^Substantive\s+Change\s+Restriction\b/i',
    'reminder'           => '/^Reminders?\s*:/i',
    'exception'          => '/^Exceptions?\s*:/i',
    'example'            => '/^Examples?\s*:/i',
    'important'          => '/^(Important|Caution|Warning)\s*:/i',
    'note'               => '/^Notes?\b\s*(?::|to\b|for\b)/i',
];

// ──────────────────────────────────────────────
// Document level
// ──────────────────────────────────────────────

/**
 * The pattern that matches a section label at the head of a line.
 *
 * The authored files use four interchangeable spellings for a marker:
 * "Label:", "[Label]", "**Label:**" and a line holding nothing but the label. A
 * delimiter of some kind is always required, so prose that merely starts with a
 * label word ("Bodies of work…") is not a section boundary.
 */
function aidocs_doc_label_pattern() {
    static $pattern = null;
    if ( $pattern === null ) {
        $alt     = implode( '|', array_map( 'preg_quote', AIDOCS_DOC_LABELS ) );
        $pattern = '/^(' . $alt . ')\s*(?::\s*(.*))?$/i';
    }
    return $pattern;
}

/**
 * Split a compilation into the standalone policies it holds.
 *
 * The files the Commission publishes are single Word documents carrying dozens
 * of separate policies one after another, and each of those has to become an
 * entry of its own. What delimits them is the same label schema one policy is
 * authored with: a policy has exactly one "Body" label, so counting those labels
 * counts the policies, and each policy begins at the title printed above its own
 * "Teaser"/"Body" pair.
 *
 * Only the first heading of an extraction is written as a level-1 title (see
 * aidocs-pdf-structure.js), so from the second policy on the title arrives as an
 * all-caps level-2 heading. Each segment's title is rewritten to the single
 * level-1 heading aidocs_parse_labeled_document() reads, which is what lets one
 * segment be parsed exactly as if it had been uploaded on its own.
 *
 * @param string $raw_text Canonical text of the whole compilation.
 * @return string[] One text per policy, in the order they appear. Empty when the
 *                  text carries no label schema at all.
 */
function aidocs_split_multi_policy_text( $raw_text ) {
    $lines   = aidocs_normalize_lines( $raw_text );
    $pattern = aidocs_doc_label_pattern();

    $labels = [];   // line index => label, lower-case
    foreach ( $lines as $index => $line ) {
        $bare = aidocs_label_candidate( $line );
        if ( $bare === null || ! preg_match( $pattern, $bare, $m ) ) continue;
        $labels[ $index ] = strtolower( $m[1] );
    }

    $bodies = [];
    foreach ( $labels as $index => $label ) {
        if ( $label === 'body' ) $bodies[] = $index;
    }
    if ( ! $bodies ) return [];

    // Where each policy begins. The search for a title is fenced by the previous
    // policy's "Body" label, so a title the layout lost can never be looked for
    // so far up that it lands inside the policy before it.
    $starts = [];
    foreach ( $bodies as $position => $body ) {
        $floor = $position === 0 ? 0 : $bodies[ $position - 1 ] + 1;

        // The teaser sits between the title and the body, so when one was
        // authored the title is above its label rather than above the body's.
        $head = $body;
        for ( $index = $body - 1; $index >= $floor; $index-- ) {
            if ( ( $labels[ $index ] ?? '' ) === 'teaser' ) { $head = $index; break; }
        }

        $starts[] = aidocs_policy_start_line( $lines, $labels, $head, $floor );
    }

    $count = count( $lines );
    $out   = [];
    foreach ( $starts as $position => $start ) {
        $end   = isset( $starts[ $position + 1 ] ) ? $starts[ $position + 1 ] : $count;
        $slice = array_slice( $lines, $start, $end - $start );
        $out[] = implode( "\n", aidocs_promote_policy_title( $slice ) );
    }

    return $out;
}

/**
 * How many standalone policies a document holds.
 *
 * @param string $raw_text Canonical text.
 * @return int Zero when the text carries no label schema, so nothing can be told.
 */
function aidocs_count_policies( $raw_text ) {
    return count( aidocs_split_multi_policy_text( $raw_text ) );
}

/**
 * The line a policy starts on: the top of the heading run holding its title.
 *
 * Titles are set over two and three lines often enough that the run has to be
 * followed up rather than stopping at the first heading. Two things end it:
 *
 *  - **A different heading level.** A title broken across lines is one heading,
 *    so every line of it carries the same level. The heading above a title at a
 *    shallower level is the compilation's own section header — "2. Policies —"
 *    ahead of the first policy in it — and belongs to no policy.
 *  - **The previous policy's trailer.** Its dates and provenance lines are, in a
 *    few documents, set in a weight the extractor reads as a heading, so
 *    aidocs_is_trailer_text() stops the run there.
 *
 * @param string[] $lines  All lines.
 * @param array    $labels Line index => label.
 * @param int      $head   The policy's first label line.
 * @param int      $floor  Lowest line index the search may reach.
 * @return int The start line, or $head when no title precedes the labels.
 */
function aidocs_policy_start_line( array $lines, array $labels, $head, $floor ) {
    $index = $head - 1;
    while ( $index >= $floor && trim( $lines[ $index ] ) === '' ) $index--;
    if ( $index < $floor || isset( $labels[ $index ] ) ) return $head;

    // The heading level of a title line, or 0 when the line is not one.
    $title_level = function ( $line ) {
        if ( ! preg_match( '/^(#{1,6})\s+(\S.*)$/', ltrim( $line ), $m ) ) return 0;
        return aidocs_is_trailer_text( $m[2] ) ? 0 : strlen( $m[1] );
    };

    $level = $title_level( $lines[ $index ] );

    // A title the extractor did not read as a heading at all: the single line
    // above the labels is taken as the title, unless it is the tail of the
    // previous policy rather than the head of this one.
    if ( ! $level ) {
        $text = aidocs_plain_text( $lines[ $index ] );
        if ( ! aidocs_is_trailer_text( $text ) && aidocs_reads_like_heading( $text ) ) return $index;

        // An editorial note is authored between the title and the labels often
        // enough — "Note: this guideline states …", "Note on currency: …", left
        // for whoever re-publishes the document — that stopping at the first
        // line which is not a heading loses the title outright, and the policy
        // imports as "Untitled". Keep looking up for the heading.
        //
        // Not far, and never past anything belonging to the policy before this
        // one: the floor is only the previous policy's "Body" label, so an
        // unbounded walk would happily climb into that policy's own headings.
        // A trailer line ("Revised: …", "Endorsed: …") is where the previous
        // policy ends, and in each of these documents it is exactly what sits
        // above the title — so it is the boundary that matters most here.
        $skipped = 0;
        for ( $up = $index - 1; $up >= $floor && $skipped < 4; $up-- ) {
            if ( trim( $lines[ $up ] ) === '' ) continue;
            if ( isset( $labels[ $up ] ) ) break;
            if ( aidocs_is_trailer_text( aidocs_plain_text( $lines[ $up ] ) ) ) break;

            $level = $title_level( $lines[ $up ] );
            if ( $level ) { $index = $up; break; }
            $skipped++;
        }

        if ( ! $level ) return $head;
    }

    $start = $index;
    while ( $start - 1 >= $floor && $title_level( $lines[ $start - 1 ] ) === $level ) $start--;
    return $start;
}

/**
 * Rewrite a segment's title into the one level-1 heading the parser reads.
 *
 * @param string[] $lines One policy's lines.
 * @return string[] The same lines with their leading heading run collapsed.
 */
function aidocs_promote_policy_title( array $lines ) {
    $pattern = aidocs_doc_label_pattern();

    $title = [];
    $index = 0;
    $count = count( $lines );
    // The heading level of the run being collected, once one has started. A
    // title set over two lines is one heading repeated at one level; a line at
    // a different level, or no heading at all, is something else — an editorial
    // note authored between the title and the labels, most often — and folding
    // that into the title produces a title no echo of it can ever match, and a
    // sentence of commentary standing in for the policy's name.
    $run_level = null;

    while ( $index < $count ) {
        $line = $lines[ $index ];
        if ( trim( $line ) === '' ) { $index++; continue; }

        $bare = aidocs_label_candidate( $line );
        if ( $bare !== null && preg_match( $pattern, $bare ) ) break;

        $is_heading = preg_match( '/^(#{1,6})\s+(\S.*)$/', ltrim( $line ), $m );
        $level      = $is_heading ? strlen( $m[1] ) : 0;

        if ( $run_level !== null && $level !== $run_level ) break;
        // Prose where the title should be, with no heading run started: keep
        // the previous behaviour of taking it, since plenty of documents carry
        // a title the extractor never marked up as a heading at all.
        if ( $run_level === null && $level ) $run_level = $level;

        $title[] = trim( $is_heading ? $m[2] : $line );
        $index++;
    }

    if ( ! $title ) return $lines;

    return array_merge( [ '# ' . aidocs_join_plain( $title ) ], array_slice( $lines, $index ) );
}

/**
 * Split a single policy document into its labelled parts.
 *
 * @param string $raw_text     Text extracted from the document.
 * @param string $known_title  The document's title, when the caller already
 *                             knows it and the text may not carry one. Re-parsing
 *                             stored content is the case that needs this: what is
 *                             kept in `_document_raw_text` is the body alone, with
 *                             no title line and no labels, so the fallback below
 *                             would take the body's first section heading for a
 *                             title and aidocs_drop_title_echo() would then
 *                             remove it as an echo of itself — losing a heading
 *                             the original parse had kept, and making the
 *                             editor's Preview tab disagree with the published
 *                             page. Passing the title the entry already has
 *                             makes a re-parse produce what the first parse did.
 * @return array{title:string,teaser:string,last_updated:string,document_history:string,blocks:array,labeled:bool}
 */
function aidocs_parse_labeled_document( $raw_text, $known_title = '' ) {
    $lines   = aidocs_normalize_lines( $raw_text );
    $pattern = aidocs_doc_label_pattern();

    $title     = '';
    $preamble  = [];
    $sections  = [];      // label (lower-case) => array of lines
    $current   = null;
    $has_label = false;   // was a Teaser/Body label actually authored?

    foreach ( $lines as $line ) {
        // The document title is the one level-1 heading, written by the
        // extractor for the line above the first label.
        if ( $title === '' && preg_match( '/^#\s+(.+)$/', $line, $m ) ) {
            $title = aidocs_plain_text( $m[1] );
            continue;
        }

        $bare = aidocs_label_candidate( $line );

        if ( $bare !== null && preg_match( $pattern, $bare, $m ) ) {
            $current = strtolower( $m[1] );
            $rest    = isset( $m[2] ) ? trim( $m[2] ) : '';
            if ( in_array( $current, [ 'teaser', 'body' ], true ) ) $has_label = true;
            if ( ! isset( $sections[ $current ] ) ) $sections[ $current ] = [];
            if ( $rest !== '' ) $sections[ $current ][] = $rest;
            continue;
        }

        // Several documents carry an appendix after their trailer. A section
        // heading below "Last Updated:" or the history lines is where the
        // document starts talking again, so the body reopens there. The dates
        // and provenance lines of the trailer are themselves sometimes set like
        // headings, and those stay part of the history.
        if ( in_array( $current, [ 'last updated', 'document history' ], true )
             && preg_match( '/^#{2,6}\s+(\S.*)$/', ltrim( $line ), $m )
             && ! aidocs_is_trailer_text( $m[1] ) ) {
            $current = 'body';
        }

        // Provenance lines are the document history even when their label was
        // lost, which happens when it sat inside the clipped footer band.
        if ( $current === null && $bare !== null && isset( $sections['last updated'] )
             && preg_match( AIDOCS_HISTORY_LINE, $bare ) ) {
            $current = 'document history';
        }

        if ( $current === null ) $preamble[] = $line;
        else                     $sections[ $current ][] = $line;
    }

    // "Last Updated" holds a single value, but the provenance lines that follow
    // it are not always introduced by their own label. Keep the first line as
    // the date and hand the remainder to the document history.
    if ( ! empty( $sections['last updated'] ) ) {
        $updated  = array_values( array_filter( array_map( 'trim', $sections['last updated'] ), 'strlen' ) );
        $sections['last updated'] = array_slice( $updated, 0, 1 );
        $overflow = array_slice( $updated, 1 );
        if ( $overflow ) {
            $sections['document history'] = array_merge( $sections['document history'] ?? [], $overflow );
        }
    }

    // A labelled document is one the authors marked up: it carries a "Body:"
    // label, with or without the teaser above it. A stray "Body:" inside prose
    // would not be at the head of a line on its own, so it cannot trigger this.
    $labeled = $has_label;

    // A title the caller already knows beats one guessed from the text, and is
    // the only way to be right about body-only text: nothing in it is the
    // title, so anything taken for one is really the first line of content.
    if ( $title === '' && trim( (string) $known_title ) !== '' ) {
        $title = trim( (string) $known_title );
    }

    if ( $title === '' ) {
        // No level-1 heading: the title is whatever precedes the first label.
        //
        // Those lines are usually headings, and their "## " markers have to come
        // off before they are joined — a title still carrying them can never
        // match the body's own echo of itself, which is what
        // aidocs_drop_title_echo() compares against, so the echo survives into
        // the content and the document renders its title twice, split across
        // however many lines the layout wrapped it over.
        //
        // Only the opening run of same-level headings is taken. A title set over
        // two lines is one heading repeated at one level; the heading below it at
        // a different level is a subtitle or a section of its own ("A Position
        // Statement"), and folding that into the title breaks the same echo
        // match a different way. Text that opens with no heading at all keeps the
        // previous up-to-three-lines behaviour.
        $title_lines = [];
        $run_level   = null;
        foreach ( array_values( array_filter( $preamble, 'strlen' ) ) as $line ) {
            if ( ! preg_match( '/^(#{1,6})\s+(\S.*)$/', ltrim( $line ), $m ) ) break;
            $level = strlen( $m[1] );
            if ( $run_level !== null && $level !== $run_level ) break;
            $run_level     = $level;
            $title_lines[] = $m[2];
        }

        if ( ! $title_lines ) {
            $title_lines = array_slice( array_values( array_filter( $preamble, 'strlen' ) ), 0, 3 );
        }

        $title = aidocs_plain_text( implode( ' ', $title_lines ) );
    }

    $body_lines = $labeled ? ( $sections['body'] ?? [] ) : array_merge( $preamble, $sections['body'] ?? [] );
    $body_text  = implode( "\n", $body_lines );

    return [
        'title'            => $title,
        'teaser'           => aidocs_join_plain( $sections['teaser'] ?? [] ),
        'last_updated'     => aidocs_join_plain( $sections['last updated'] ?? [] ),
        'document_history' => aidocs_join_history( $sections['document history'] ?? [] ),
        // Whether the text is the extractor's own canonical format is a
        // question about the whole document, not about its body. An article
        // whose body happens to contain no heading, list or table — several of
        // them here are four plain paragraphs — has no marker in it to find,
        // and was being read as a PDF text layer instead: its authored
        // paragraphs merged into one, and any bold line promoted to a heading
        // by guesswork. The document's own title line and label schema are the
        // evidence, and they sit outside the body.
        'blocks'           => aidocs_parse_structured_content(
            $body_text,
            $title,
            aidocs_text_is_annotated( $lines )
        ),
        // The body on its own, still carrying the extractor's markers. The
        // blocks above cover exactly this much of the document and nothing
        // else, so anything re-deriving them — the AI restructure pass — has
        // to work from the same span to be comparable.
        'body_text'        => $body_text,
        'labeled'          => $labeled,
    ];
}

/**
 * Normalise raw text to a list of lines, keeping the leading indent that
 * carries the list nesting and dropping the layout noise around it.
 *
 * @return string[]
 */
function aidocs_normalize_lines( $raw_text ) {
    $text = str_replace( [ "\r\n", "\r" ], "\n", (string) $raw_text );
    $text = preg_replace( '/\x{00A0}|\x{2007}|\x{202F}/u', ' ', $text );
    // Page markers written by the editor's extractor, and bare page numbers.
    $text = preg_replace( '/^\s*-{2,}\s*Page\s+\d+\s*-{2,}\s*$/mi', '', $text );

    $out = [];
    foreach ( explode( "\n", $text ) as $line ) {
        $line = rtrim( $line );
        $bare = ltrim( $line );
        if ( $bare === '' ) { $out[] = ''; continue; }
        if ( preg_match( '/^\d{1,3}$/', $bare ) ) continue;          // page number
        if ( preg_match( '/^\[\s*\]$/', $bare ) ) continue;          // empty placeholder
        // A stray bullet or marker with nothing after it: the PDF text layer
        // emits these when a list item's text was clipped away.
        if ( preg_match( '/^[\x{2022}\x{00B7}\x{25CF}\x{25AA}\x{25E6}\x{2043}\x{2023}\x{2013}\-\*]+$/u', $bare ) ) continue;
        $out[] = $line;
    }
    return $out;
}

/**
 * The comparable form of a line for label matching: no heading marker, no
 * emphasis, no square brackets around it.
 *
 * @return string|null Null when the line cannot be a label at all.
 */
function aidocs_label_candidate( $line ) {
    $bare = ltrim( $line );
    if ( $bare === '' ) return null;
    if ( preg_match( '/^(#{1,4})\s+(.*)$/', $bare, $m ) ) $bare = $m[2];
    $bare = trim( aidocs_plain_text( $bare ) );
    // Bracketed labels, written either on their own — "[Document History]" — or
    // with their content trailing behind them on the same line.
    $bare = preg_replace( '/^\[\s*([^\]:]{3,30}?)\s*\]\s*:?\s*/u', '$1: ', $bare );
    $bare = trim( $bare );
    return $bare === '' ? null : $bare;
}

/**
 * Is this line part of a document's trailer rather than its content?
 *
 * The provenance lines are set in italics and, in a few documents, in a weight
 * the extractor reads as a heading: "Revised: Executive Council, March 2022",
 * "June 2025; December 2025". Either way they belong to the history.
 */
function aidocs_is_trailer_text( $text ) {
    $text = trim( aidocs_plain_text( $text ) );
    if ( $text === '' ) return true;
    if ( preg_match( AIDOCS_HISTORY_LINE, $text ) ) return true;
    // Nothing but months, years and separators.
    return (bool) preg_match(
        '/^(?:January|February|March|April|May|June|July|August|September|October|November|December|\d{4}|[\s,;·\-\/]|and)+$/i',
        $text
    );
}

/** Collapse a section's lines into one plain-text paragraph. */
function aidocs_join_plain( array $lines ) {
    $lines = array_filter( array_map( 'trim', $lines ), 'strlen' );
    return trim( preg_replace( '/\s+/u', ' ', aidocs_plain_text( implode( ' ', $lines ) ) ) );
}

/**
 * Join the provenance lines of the document history into one "·"-separated run.
 *
 * Authors write the history either as a single "·"-separated sentence that
 * wraps across lines, or as one event per line. Splitting on the line breaks
 * would cut the first style mid-phrase ("Revised and / Approved: June 2003"),
 * so the lines are joined first and then cut where a new event demonstrably
 * begins: on a "·", or on a provenance verb that follows a finished date.
 */
function aidocs_join_history( array $lines ) {
    $text = [];
    foreach ( $lines as $line ) {
        // A provenance line the extractor read as a heading arrives with its
        // marker still on it — a few documents set the trailer in a weight it
        // cannot tell from a section title. The marker is layout, not history.
        $line = preg_replace( '/^\s*#{1,6}\s+/', '', $line );
        $line = trim( trim( aidocs_plain_text( $line ), "[] \t" ) );
        if ( $line !== '' ) $text[] = $line;
    }
    $text = trim( preg_replace( '/\s+/u', ' ', implode( ' ', $text ) ) );
    if ( $text === '' ) return '';

    $verbs  = 'Adopted|Approved|Revised|Edited|Reformatted|Updated|Renamed|Reinstated|Amended|Endorsed';
    $events = preg_split( '/\s*·\s*|(?<=[\d.)])\s+(?=(?:' . $verbs . ')\b)/u', $text );

    $events = array_filter( array_map( 'trim', (array) $events ), 'strlen' );
    return implode( ' · ', array_unique( $events ) );
}

/**
 * Normalise an authored "Last Updated" value to a YYYY-MM-DD date.
 *
 * Accepts "June 2026 (Board of Trustees)", "March 11, 2026", "2026-06-01".
 * A month without a day is pinned to the first of that month.
 *
 * @return string Empty string when no date can be read.
 */
function aidocs_normalize_doc_date( $value ) {
    $value = trim( (string) $value );
    if ( $value === '' ) return '';

    // Drop a trailing parenthetical such as "(Board of Trustees)".
    $value = trim( preg_replace( '/\([^)]*\)\s*$/u', '', $value ) );

    if ( preg_match( '/\b(\d{4})-(\d{2})-(\d{2})\b/', $value, $m ) ) {
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    $months = [
        'january' => '01', 'february' => '02', 'march'     => '03', 'april'   => '04',
        'may'     => '05', 'june'     => '06', 'july'      => '07', 'august'  => '08',
        'september' => '09', 'october' => '10', 'november' => '11', 'december' => '12',
    ];
    $month_alt = implode( '|', array_keys( $months ) );

    // "March 11, 2026"
    if ( preg_match( '/\b(' . $month_alt . ')\s+(\d{1,2}),?\s+(\d{4})\b/i', $value, $m ) ) {
        return sprintf( '%s-%s-%02d', $m[3], $months[ strtolower( $m[1] ) ], (int) $m[2] );
    }
    // "June 2026"
    if ( preg_match( '/\b(' . $month_alt . ')\s+(\d{4})\b/i', $value, $m ) ) {
        return $m[2] . '-' . $months[ strtolower( $m[1] ) ] . '-01';
    }

    return '';
}

// ──────────────────────────────────────────────
// Body → blocks
// ──────────────────────────────────────────────

/**
 * Turn the body of a document into content blocks.
 *
 * @param string    $raw_text  Body text, canonical or plain.
 * @param string    $title     Document title, so the body's echo of it can be dropped.
 * @param bool|null $annotated Whether the text carries the extractor's structure
 *                             markers, when the caller already knows. Sniffed from
 *                             the body alone when null — see the caller in
 *                             aidocs_parse_labeled_document() for why that is not
 *                             good enough on its own.
 * @return array List of blocks.
 */
function aidocs_parse_structured_content( $raw_text, $title = '', $annotated = null ) {
    $lines     = aidocs_normalize_lines( $raw_text );
    $annotated = $annotated === null ? aidocs_text_is_annotated( $lines ) : (bool) $annotated;

    $blocks  = [];
    $items   = [];   // the flat list items collected so far, with their levels
    $para    = [];   // buffered paragraph lines (unannotated text wraps)

    // A list is built flat and turned into a tree when it closes, so nothing
    // depends on holding a reference into a nested array while parsing.
    $flush_list = function () use ( &$blocks, &$items ) {
        if ( ! $items ) return;
        $index    = 0;
        $blocks[] = aidocs_list_from_flat( $items, $index );
        $items    = [];
    };

    $flush_para = function () use ( &$blocks, &$para, &$flush_list ) {
        if ( ! $para ) return;
        $text = trim( preg_replace( '/\s+/u', ' ', implode( ' ', $para ) ) );
        $para = [];
        if ( $text === '' ) return;
        $flush_list();
        foreach ( aidocs_paragraph_blocks( $text ) as $block ) $blocks[] = $block;
    };

    foreach ( $lines as $line ) {
        if ( trim( $line ) === '' ) {
            // A blank line ends a paragraph. It does not end a list: PDFs space
            // their bullets out, and the canonical text has no blank lines.
            $flush_para();
            continue;
        }

        $indent = strlen( $line ) - strlen( ltrim( $line ) );
        $bare   = ltrim( $line );

        // ── Heading ──
        if ( preg_match( '/^(#{1,6})\s+(.+)$/', $bare, $m ) ) {
            $flush_para();
            $flush_list();

            // A "heading" that no longer reads like one is prose the extractor
            // over-merged: several consecutive bold PDF lines, each individually
            // short enough to look like a heading on its own, accumulate into a
            // block that plainly is not one once joined — a page-spanning bold
            // "Note:" paragraph is the recurring case. Route it through the
            // paragraph pipeline instead, so a "Note:" opening still becomes a
            // callout rather than a fake heading.
            if ( ! aidocs_reads_like_heading( $m[2] ) ) {
                aidocs_append_downgraded_text( $blocks, $m[2] );
                continue;
            }

            $blocks[] = aidocs_heading_block( max( 2, min( 4, strlen( $m[1] ) ) ), $m[2] );
            continue;
        }

        // ── Table row ──
        if ( preg_match( '/^\|(.+)\|$/', $bare, $m ) ) {
            $flush_para();
            $flush_list();
            aidocs_append_table_row( $blocks, array_map( 'trim', explode( '|', $m[1] ) ) );
            continue;
        }

        // ── List item ──
        if ( preg_match( '/^([\x{2022}\x{00B7}\x{25CF}\x{25AA}\x{25E6}\x{2043}\x{2023}\x{2023}\x{2013}\-\*]|\d{1,2}[.)]|[a-zA-Z][.)])\s+(\S.*)$/u', $bare, $m ) ) {
            $flush_para();
            $level = $annotated ? intdiv( $indent, 2 ) + 1 : 1;

            // The rest of the enumeration can be sitting inside this one
            // captured line — "1. [Composition…] text… 2. [Duties…] text…" —
            // because the source wrapped the whole thing without a line break
            // between items. Recovered, those become siblings; left alone,
            // "2.", "3." and everything after them stay buried inside item
            // "1"'s own text.
            $split = aidocs_split_inline_enumeration( $bare );
            if ( $split && trim( $split['lead'] ) === '' ) {
                foreach ( $split['items'] as $sub ) {
                    $items[] = [ 'level' => $level, 'marker' => $m[1], 'text' => $sub['text'], 'blocks' => [] ];
                }
                continue;
            }

            $items[] = [
                'level'  => $level,
                'marker' => $m[1],
                'text'   => $m[2],
                'blocks' => [],
            ];
            continue;
        }

        // ── A further paragraph inside the item above ──
        if ( $items && $annotated && $indent > 0 ) {
            $flush_para();
            $last = count( $items ) - 1;
            $items[ $last ]['blocks'][] = aidocs_paragraph_block( $bare );
            continue;
        }

        // ── Anything else is prose, and closes any open list ──
        if ( $indent === 0 || ! $annotated ) $flush_list();

        // In plain text a heading is only recognisable by how it reads, and it
        // interrupts a paragraph, so the test happens line by line.
        if ( ! $annotated && ( $heading = aidocs_detect_heading( $bare ) ) ) {
            $flush_para();
            $blocks[] = aidocs_heading_block( $heading['level'], $bare );
            continue;
        }

        // In canonical text, a line set in bold from end to end is a section
        // heading. Word authors most of the headings in these documents that
        // way — "Purposes of a Credential", "Air Travel", "Online Security" —
        // rather than with a Word heading style, so nothing else marks them and
        // they were landing as paragraphs. Level 3: they divide a document into
        // its sections, which is what the accordion collapses.
        if ( $annotated && aidocs_is_bold_heading_line( $bare ) ) {
            $flush_para();
            $blocks[] = aidocs_heading_block( 3, $bare );
            continue;
        }

        $para[] = $bare;
        // A canonical paragraph is one line; only unannotated text wraps.
        if ( $annotated ) $flush_para();
    }

    $flush_para();
    $flush_list();

    $blocks = aidocs_demote_heading_runs( $blocks );
    $blocks = aidocs_mark_note_headings( $blocks );
    $blocks = aidocs_drop_title_echo( $blocks, $title );

    return $blocks;
}

/**
 * Does this text carry the extractor's structure markers?
 *
 * When it does, one line is one block and the indentation is meaningful. When
 * it does not — text pasted from a plain PDF text layer — paragraphs are
 * wrapped across lines and headings have to be guessed at.
 */
/**
 * Is this canonical line a section heading the source set in bold?
 *
 * Three things have to hold, and the last two are what keep ordinary prose out:
 *
 *  - **Every run is bold.** A bold phrase inside a sentence is emphasis, and a
 *    bold label opening a paragraph ("Note:", "Substantive Change:") leaves the
 *    rest of that line unbold, so neither qualifies.
 *  - **It reads like a heading** — aidocs_reads_like_heading(), the same test the
 *    plain-text path uses: short, no trailing sentence punctuation, not a clause
 *    trailing off on a function word.
 *  - **It is not a note label.** A callout the document labels is a note block,
 *    which renders as a callout and deliberately never collapses.
 */
function aidocs_is_bold_heading_line( $bare ) {
    if ( strpos( $bare, '**' ) === false ) return false;

    $runs = aidocs_inline_runs( $bare );
    if ( ! $runs ) return false;

    foreach ( $runs as $run ) {
        if ( empty( $run['b'] ) ) return false;
        if ( trim( $run['text'] ) === '' ) continue;
    }

    $plain = trim( aidocs_plain_text( $bare ) );
    if ( $plain === '' ) return false;
    if ( aidocs_note_variant( $plain ) !== '' ) return false;

    return aidocs_reads_like_heading( $plain );
}

function aidocs_text_is_annotated( array $lines ) {
    foreach ( $lines as $line ) {
        $bare = ltrim( $line );
        if ( preg_match( '/^#{1,6}\s+\S/', $bare ) ) return true;
        // Emphasis and table markers are the extractor's too, and a body can
        // easily hold no heading at all — several policies here are four plain
        // paragraphs with a bold section title. Judging by '#' alone, the
        // canonical text of such a body read as a raw PDF text layer, and the
        // editor's Preview tab, which re-parses exactly that text, disagreed
        // with the published page about the whole document's structure.
        if ( strpos( $bare, '**' ) !== false ) return true;
        if ( preg_match( '/^\|.*\|$/', $bare ) ) return true;
    }
    return false;
}

/**
 * Whether a `#`-marked line still reads like a heading once assembled.
 *
 * All-caps text is exempt from the length and sentence checks below: that is
 * how these documents set their own titles, and a genuine one can run long.
 * Anything else has to look like a label, not a sentence — a heading never
 * opens mid-phrase, the way a paragraph's wrapped continuation reliably does
 * when it is what got mis-merged into this line.
 */
function aidocs_reads_like_heading( $text ) {
    $text = trim( $text );
    if ( $text === '' ) return false;

    // "The Commission" is this corpus's one recurring mixed-case exception —
    // the org's name, substituted at a fixed case even inside otherwise
    // all-caps titles. See isAllCaps() in aidocs-pdf-structure.js.
    $letters = preg_replace( '/[^\p{L}]/u', '', preg_replace( '/\bThe Commission\b/u', '', $text ) );
    if ( $letters !== '' && mb_strtoupper( $letters, 'UTF-8' ) === $letters ) return true;

    if ( preg_match( '/^\p{Ll}/u', $text ) ) return false;
    if ( mb_strlen( $text ) > 160 ) return false;
    if ( preg_match( '/[.;,]$/u', $text ) ) return false;
    // A heading is a label, not a clause, so it does not trail off on a
    // function word — a sure sign of a sentence cut short where the
    // extractor lost the rest of it, not of a heading ending.
    if ( preg_match( '/\b(?:a|an|the|and|or|but|of|to|in|on|at|by|for|with|from|as|that|which|be|is|are|was|were|may|will|shall|should|must|more|less|than)$/iu', $text ) ) return false;
    return preg_match( '/\p{Ll}{2}[.?]\s+\p{Lu}/u', $text ) !== 1;
}

/**
 * Fold text that turned out not to be a heading back into ordinary content:
 * onto the block right before it when that reads as this text's own
 * continuation, or as a fresh paragraph — a note, if it opens like one —
 * otherwise.
 */
function aidocs_append_downgraded_text( array &$blocks, $text ) {
    $last = $blocks ? count( $blocks ) - 1 : -1;
    $prev = $last >= 0 ? $blocks[ $last ] : null;

    $continues = $prev && in_array( $prev['type'] ?? '', [ 'note', 'paragraph' ], true )
        && ! preg_match( '/[.!?:;”"\')\]]\s*$/u', trim( (string) ( $prev['text'] ?? '' ) ) );

    if ( $continues ) {
        $joined = trim( $prev['text'] ) . ' ' . trim( aidocs_plain_text( $text ) );
        $blocks[ $last ]['text'] = $joined;
        $blocks[ $last ]['runs'] = aidocs_inline_runs( $joined );
        return;
    }

    foreach ( aidocs_paragraph_blocks( $text ) as $block ) $blocks[] = $block;
}

/** A heading block, with an anchor id for in-page navigation. */
function aidocs_heading_block( $level, $text ) {
    $plain = aidocs_plain_text( $text );
    return [
        'type'  => 'heading',
        'level' => (int) $level,
        'text'  => $plain,
        'runs'  => aidocs_inline_runs( $text ),
        'id'    => aidocs_anchor_id( $plain ),
        'note'  => aidocs_note_variant( $plain ),
    ];
}

/**
 * The blocks a paragraph of prose turns into.
 *
 * Most paragraphs are one block. Two things split them:
 *
 *  - A note label at the front makes the paragraph a callout.
 *  - An enumeration written inline — "A. Denial of Candidacy B. Removal from
 *    Candidacy C. Denial of Initial Membership" — is a list that lost its line
 *    breaks in the PDF, and is recovered when the markers run in sequence.
 */
function aidocs_paragraph_blocks( $text ) {
    $blocks = [];

    $variant = aidocs_note_variant( $text );
    if ( $variant !== '' ) {
        // The label is split off the plain text, and the same number of
        // characters is dropped from the runs, so a note that was authored in
        // bold from end to end does not lose its inner emphasis.
        $plain = aidocs_plain_text( $text );
        $label = '';
        $body  = $plain;
        if ( preg_match( '/^([^:]{1,60}?)\s*:\s*(\S.*)$/u', $plain, $m ) ) {
            $label = trim( $m[1] );
            $body  = trim( $m[2] );
        }
        $runs = aidocs_runs_after( aidocs_inline_runs( $text ), mb_strlen( $plain ) - mb_strlen( $body ) );

        return [ [
            'type'    => 'note',
            'variant' => $variant,
            'label'   => $label,
            'text'    => $body,
            // Bold across the whole note is how the label was set, not emphasis
            // inside it; the callout already carries that weight.
            'runs'    => aidocs_runs_unbold( $runs ),
        ] ];
    }

    $split = aidocs_split_inline_enumeration( $text );
    if ( $split ) {
        if ( $split['lead'] !== '' ) $blocks[] = aidocs_paragraph_block( $split['lead'] );
        $items = [];
        foreach ( $split['items'] as $item ) {
            $items[] = [
                'text'   => aidocs_plain_text( $item['text'] ),
                'runs'   => aidocs_inline_runs( $item['text'] ),
                'blocks' => [],
            ];
        }
        $blocks[] = [
            'type'    => 'list',
            'ordered' => true,
            'style'   => $split['style'],
            'items'   => $items,
        ];
        return $blocks;
    }

    return [ aidocs_paragraph_block( $text ) ];
}

function aidocs_paragraph_block( $text ) {
    return [
        'type' => 'paragraph',
        'text' => aidocs_plain_text( $text ),
        'runs' => aidocs_inline_runs( $text ),
    ];
}

/**
 * Recover an enumeration that was written inline.
 *
 * Only a run of markers in sequence counts — "A." followed by "B.", "1." by
 * "2." — which is what keeps initials ("Ms. A. Berger"), abbreviations ("U.S.
 * Department") and cross-references from being read as list markers.
 *
 * @return array{lead:string,items:array,style:string}|null
 */
function aidocs_split_inline_enumeration( $text ) {
    $styles = [
        'upper-alpha' => [ '/(?:(?<=^)|(?<=[\s\p{P}]))([A-Z])\.\s+(?=\p{Lu})/u', 'aidocs_alpha_index' ],
        'upper-roman' => [ '/(?:(?<=^)|(?<=[\s\p{P}]))((?:X{0,2})(?:IX|IV|V?I{0,3}))\.\s+(?=\p{Lu})/u', 'aidocs_roman_index' ],
        'decimal'     => [ '/(?:(?<=^)|(?<=[\s\p{P}]))(\d{1,2})\.\s+(?=[\[\p{Lu}])/u', 'intval' ],
        // A capital letter has to follow here too, same as the styles above —
        // otherwise "e.g." and "i.e." read as the openings of a lower-alpha
        // or lower-roman enumeration.
        'lower-alpha' => [ '/(?:(?<=^)|(?<=[\s\p{P}]))([a-z])\.\s+(?=\p{Lu})/u', 'aidocs_alpha_index' ],
        'lower-roman' => [ '/(?:(?<=^)|(?<=[\s\p{P}]))((?:x{0,2})(?:ix|iv|v?i{0,3}))\.\s+(?=\p{Lu})/u', 'aidocs_roman_index' ],
    ];

    foreach ( $styles as $style => $spec ) {
        list( $pattern, $indexer ) = $spec;
        if ( ! preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) continue;

        // Keep only the markers that continue the sequence from its first
        // value, and stop at the first one that doesn't: a nested list can
        // restart its own numbering at 1 and, from there, happen to keep pace
        // with the outer sequence's count by coincidence — "2.", "3." inside
        // a nested "a." matching the outer item 2, 3 that comes later — so
        // skipping past a mismatch and looking further is what would let a
        // nested marker get mistaken for the next outer one. Stopping instead
        // means a document too deeply nested for this loses only the later
        // items' recovery, never gets a wrong split.
        $kept     = [];
        $expected = 1;
        foreach ( $matches[1] as $index => $match ) {
            $value = call_user_func( $indexer, $match[0] );
            if ( $value !== $expected ) break;
            $kept[]   = [ 'offset' => $matches[0][ $index ][1], 'marker' => $match[0] ];
            $expected++;
        }
        if ( count( $kept ) < 2 ) continue;

        $lead  = trim( substr( $text, 0, $kept[0]['offset'] ) );
        $items = [];
        foreach ( $kept as $index => $marker ) {
            $start  = $marker['offset'] + strlen( $marker['marker'] ) + 1;
            $end    = isset( $kept[ $index + 1 ] ) ? $kept[ $index + 1 ]['offset'] : strlen( $text );
            $body   = trim( substr( $text, $start, $end - $start ) );
            // "1. [Duties of the Executive Council] The Council shall…" — the
            // bracketed lead-in is the item's own title.
            $body   = preg_replace( '/^\[([^\]]{3,90})\]\s*/u', '**$1** ', $body );
            if ( $body !== '' ) $items[] = [ 'text' => $body ];
        }
        if ( count( $items ) < 2 ) continue;

        return [ 'lead' => $lead, 'items' => $items, 'style' => $style ];
    }

    return null;
}

function aidocs_alpha_index( $letter ) {
    return ord( strtoupper( $letter ) ) - 64;
}

function aidocs_roman_index( $roman ) {
    $values = [ 'I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100 ];
    $roman  = strtoupper( $roman );
    $total  = 0;
    for ( $i = 0; $i < strlen( $roman ); $i++ ) {
        $value = $values[ $roman[ $i ] ] ?? 0;
        $next  = $values[ $roman[ $i + 1 ] ?? '' ] ?? 0;
        $total += $value < $next ? -$value : $value;
    }
    return $total;
}

/**
 * Build one list block, with its sub-lists, from the flat items collected while
 * scanning. Items deeper than the current level become a nested list inside the
 * item above them.
 *
 * @param array $items Flat items: level, marker, text, blocks.
 * @param int   $index Cursor into $items, advanced as items are consumed.
 * @return array A list block.
 */
function aidocs_list_from_flat( array $items, &$index ) {
    $level = $items[ $index ]['level'];
    $style = aidocs_marker_style( $items[ $index ]['marker'] );
    $list  = [
        'type'    => 'list',
        'ordered' => $style !== 'bullet',
        'style'   => $style,
        'items'   => [],
    ];

    // A list interrupted by a paragraph resumes its own numbering — the source
    // documents number their procedures 1…12 straight through — so the first
    // marker's value is carried into the markup.
    $start = aidocs_marker_ordinal( $items[ $index ]['marker'], $style );
    if ( $start > 1 ) $list['start'] = $start;

    $count = count( $items );
    while ( $index < $count ) {
        $item = $items[ $index ];

        if ( $item['level'] < $level ) break;

        if ( $item['level'] > $level ) {
            $nested = aidocs_list_from_flat( $items, $index );
            if ( ! $list['items'] ) $list['items'][] = [ 'text' => '', 'runs' => [], 'blocks' => [] ];
            $last = count( $list['items'] ) - 1;
            $list['items'][ $last ]['blocks'][] = $nested;
            continue;
        }

        $list['items'][] = [
            'text'   => aidocs_plain_text( $item['text'] ),
            'runs'   => aidocs_inline_runs( $item['text'] ),
            'blocks' => $item['blocks'],
        ];
        $index++;
    }

    return $list;
}

/** Append a row to the table being built, starting one when needed. */
function aidocs_append_table_row( array &$blocks, array $cells ) {
    $last = $blocks ? $blocks[ count( $blocks ) - 1 ] : null;
    if ( ! $last || $last['type'] !== 'table' ) {
        // A row whose every cell is bold is a header; anything else is data.
        $bold = true;
        foreach ( $cells as $cell ) {
            if ( ! preg_match( '/^\*\*.+\*\*$/s', $cell ) ) { $bold = false; break; }
        }
        $blocks[] = [
            'type' => 'table',
            'head' => $bold ? array_map( 'aidocs_plain_text', $cells ) : [],
            'rows' => $bold ? [] : [ array_map( 'aidocs_cell', $cells ) ],
        ];
        return;
    }
    $blocks[ count( $blocks ) - 1 ]['rows'][] = array_map( 'aidocs_cell', $cells );
}

function aidocs_cell( $text ) {
    return [ 'text' => aidocs_plain_text( $text ), 'runs' => aidocs_inline_runs( $text ) ];
}

/** The position a marker names in its own numbering: "c." is 3, "iv." is 4. */
function aidocs_marker_ordinal( $marker, $style ) {
    $marker = rtrim( trim( (string) $marker ), '.)' );
    switch ( $style ) {
        case 'decimal':     return max( 1, (int) $marker );
        case 'lower-alpha':
        case 'upper-alpha': return max( 1, aidocs_alpha_index( $marker ) );
        case 'lower-roman':
        case 'upper-roman': return max( 1, aidocs_roman_index( $marker ) );
    }
    return 1;
}

/** Which numbering a marker belongs to. */
function aidocs_marker_style( $marker ) {
    $marker = rtrim( $marker, '.)' );
    if ( preg_match( '/^\d+$/', $marker ) ) return 'decimal';
    if ( preg_match( '/^(?:x{0,2})(?:ix|iv|v?i{1,3})$/', $marker ) ) return 'lower-roman';
    if ( preg_match( '/^(?:X{0,2})(?:IX|IV|V?I{1,3})$/', $marker ) ) return 'upper-roman';
    if ( preg_match( '/^[a-z]$/', $marker ) ) return 'lower-alpha';
    if ( preg_match( '/^[A-Z]$/', $marker ) ) return 'upper-alpha';
    return 'bullet';
}

/**
 * Which note a label introduces, if any.
 *
 * @return string Variant slug, or '' when the text is not a note.
 */
function aidocs_note_variant( $text ) {
    $text = trim( aidocs_plain_text( $text ) );
    foreach ( AIDOCS_NOTE_PATTERNS as $variant => $pattern ) {
        if ( preg_match( $pattern, $text ) ) return $variant;
    }
    return '';
}

/**
 * Flag the headings that are note labels.
 *
 * "Note: Substantive Change" and "Note to International Institutions" are
 * authored exactly like section headings — bold, on their own line — and the
 * PDF gives no signal for where such a note ends: the paragraphs under it are
 * set identically to the body paragraphs that follow them. So the note is
 * rendered as a labelled heading rather than a box around a guessed extent,
 * and the content under it stays in the reading order the author wrote.
 */
function aidocs_mark_note_headings( array $blocks ) {
    foreach ( $blocks as $index => $block ) {
        if ( ( $block['type'] ?? '' ) !== 'heading' ) continue;
        if ( ! empty( $block['note'] ) ) continue;
        $blocks[ $index ]['note'] = aidocs_note_variant( $block['text'] ?? '' );
    }
    return $blocks;
}

/**
 * Drop the body's repetition of the document title.
 *
 * Most documents open their body with the title again, set in caps and often
 * broken over two or three lines ("THE APPEALS PROCEDURES" / "OF THE COLLEGE
 * DELEGATE ASSEMBLY"). Once the parts are put back together and match the
 * title, they are noise on a page that already shows it as a heading.
 */
function aidocs_drop_title_echo( array $blocks, $title ) {
    $needle = aidocs_comparable( $title );
    if ( $needle === '' ) return $blocks;

    $out   = [];
    $limit = 4;                 // only the opening headings of the body
    $run   = [];

    foreach ( $blocks as $block ) {
        $type = $block['type'] ?? '';

        // Any opening heading or paragraph counts, whatever its level. The echo
        // is authored as whatever the source made it and typed as whatever the
        // parser then made of that: a Word document sets it in bold body text,
        // which becomes a level-3 heading here, while a PDF's all-caps title
        // line becomes a level-2 one. Pinning this to level 2 meant the title
        // was printed again at the top of the content of every .docx-sourced
        // document. Only the opening blocks are ever considered, and only a run
        // whose text actually reads as the title (aidocs_is_title_echo) is
        // dropped, so an opening section that merely names the subject is safe.
        $is_candidate = count( $out ) < $limit
            && ( $type === 'paragraph' || $type === 'heading' );

        if ( $is_candidate ) {
            $run[] = $block;
            $joined = aidocs_comparable( implode( ' ', array_column( $run, 'text' ) ) );
            if ( aidocs_is_title_echo( $joined, $needle ) ) { $run = []; continue; }  // the echo, dropped
            if ( strpos( $needle, $joined ) === 0 ) continue;           // still matching
            foreach ( $run as $held ) $out[] = $held;                   // not the title
            $run = [];
            continue;
        }

        foreach ( $run as $held ) $out[] = $held;
        $run   = [];
        $out[] = $block;
    }

    foreach ( $run as $held ) $out[] = $held;
    return $out;
}

/**
 * Does $joined read as the document's title, allowing for the extra word or
 * two ("Policy Statement") a few documents print on the same caps heading
 * right after the title itself?
 *
 * The trailer has to be short: a heading that starts with the title's words
 * but goes on to become a materially different, longer heading — a real
 * section, not an echo — must not be swallowed by this.
 */
function aidocs_is_title_echo( $joined, $needle ) {
    if ( $joined === $needle ) return true;
    if ( $needle === '' || strpos( $joined, $needle ) !== 0 ) return false;
    return strlen( $joined ) - strlen( $needle ) <= 20;
}

/**
 * Letters and digits only, lower-cased — for comparing a heading to a title.
 *
 * "The Commission" and bare "Commission" are normalised to the same string:
 * the org's name is substituted inconsistently between a document's title
 * and its own body echo of that title (one carries "The", the other doesn't),
 * which otherwise breaks the echo match over a difference that isn't the
 * author's — it's an artefact of the substitution, not a real title change.
 */
function aidocs_comparable( $text ) {
    $comparable = preg_replace( '/[^a-z0-9]+/', '', strtolower( aidocs_plain_text( (string) $text ) ) );
    return str_replace( 'thecommission', 'commission', $comparable );
}

/**
 * Number of consecutive heading-looking lines that mark a block of addressed
 * text rather than a run of real headings.
 */
const AIDOCS_HEADING_RUN_LIMIT = 4;

/**
 * Demote long runs of consecutive headings back to paragraphs.
 *
 * Letterheads, mailing addresses and signature blocks are made of short
 * Title Case lines, so line-by-line they are indistinguishable from headings.
 * What separates them is that real headings introduce body text, so they do not
 * stack: a caps title followed by a section title is two in a row, never five.
 */
function aidocs_demote_heading_runs( array $blocks ) {
    $out   = [];
    $count = count( $blocks );

    for ( $i = 0; $i < $count; $i++ ) {
        if ( ( $blocks[ $i ]['type'] ?? '' ) !== 'heading' ) {
            $out[] = $blocks[ $i ];
            continue;
        }

        $run = $i;
        while ( $run < $count && ( $blocks[ $run ]['type'] ?? '' ) === 'heading' ) $run++;

        if ( $run - $i >= AIDOCS_HEADING_RUN_LIMIT ) {
            for ( $j = $i; $j < $run; $j++ ) {
                $out[] = [
                    'type' => 'paragraph',
                    'text' => $blocks[ $j ]['text'] ?? '',
                    'runs' => $blocks[ $j ]['runs'] ?? [],
                ];
            }
        } else {
            for ( $j = $i; $j < $run; $j++ ) $out[] = $blocks[ $j ];
        }
        $i = $run - 1;
    }

    return $out;
}

/**
 * Decide whether a single line of unannotated text reads as a section heading.
 *
 * @return array|null A heading level, or null when the line is body text.
 */
function aidocs_detect_heading( $line ) {
    $line = aidocs_plain_text( $line );
    $len  = mb_strlen( $line );
    if ( $len === 0 || $len > 90 ) return null;

    // Headings do not end in sentence punctuation.
    if ( preg_match( '/[\.;,]$/u', $line ) ) return null;
    // A line with several sentences is a paragraph regardless of length.
    if ( preg_match_all( '/\p{Ll}{2}[\.\?]\s/u', $line ) > 1 ) return null;
    // Comma plus digits reads as an address or a dateline ("Decatur, Georgia
    // 30033-4097", "Edited, Executive Council, March 11, 2026"), not a heading.
    if ( strpos( $line, ',' ) !== false && preg_match( '/\d/', $line ) ) return null;

    $letters = preg_replace( '/[^\p{L}]/u', '', $line );
    if ( $letters === '' ) return null;

    // ALL CAPS → document-level heading. "The Commission" is excluded first:
    // it is substituted at a fixed mixed case even inside an otherwise
    // all-caps title (see isAllCaps() in aidocs-pdf-structure.js).
    $caps_check = preg_replace( '/[^\p{L}]/u', '', preg_replace( '/\bThe Commission\b/u', '', $line ) );
    if ( $caps_check !== '' && mb_strtoupper( $caps_check, 'UTF-8' ) === $caps_check && mb_strlen( $letters ) > 2 ) {
        return [ 'level' => 2 ];
    }

    // Short Title Case line ("Policy Statement", "Document History") → section.
    if ( $len <= 70 ) {
        $words = preg_split( '/\s+/u', $line );
        $significant = array_values( array_filter( $words, function ( $w ) {
            $w = preg_replace( '/[^\p{L}]/u', '', $w );
            return $w !== '' && ! in_array( mb_strtolower( $w ), [ 'of', 'the', 'and', 'for', 'in', 'to', 'a', 'an', 'on', 'by' ], true );
        } ) );
        if ( ! $significant ) return null;
        $capped = array_filter( $significant, function ( $w ) {
            $first = mb_substr( preg_replace( '/[^\p{L}]/u', '', $w ), 0, 1 );
            return $first !== '' && mb_strtoupper( $first, 'UTF-8' ) === $first;
        } );
        if ( count( $capped ) === count( $significant ) && count( $words ) <= 8 ) {
            return [ 'level' => 3 ];
        }
    }

    return null;
}

// ──────────────────────────────────────────────
// Inline runs
// ──────────────────────────────────────────────

/**
 * Parse the inline emphasis of a canonical line into styled runs.
 *
 * @return array List of [ 'text' => …, 'b' => 1, 'i' => 1 ]; empty when the
 *               text has no emphasis at all, so plain content stays compact.
 */
function aidocs_inline_runs( $text ) {
    $text = (string) $text;
    if ( strpos( $text, '*' ) === false ) return [];

    $runs   = [];
    $buffer = '';
    $bold   = false;
    $italic = false;
    $length = strlen( $text );

    $push = function () use ( &$runs, &$buffer, &$bold, &$italic ) {
        if ( $buffer === '' ) return;
        $run = [ 'text' => $buffer ];
        if ( $bold )   $run['b'] = 1;
        if ( $italic ) $run['i'] = 1;
        $runs[]  = $run;
        $buffer  = '';
    };

    for ( $i = 0; $i < $length; $i++ ) {
        $char = $text[ $i ];

        if ( $char === '\\' && $i + 1 < $length && ( $text[ $i + 1 ] === '*' || $text[ $i + 1 ] === '\\' ) ) {
            $buffer .= $text[ ++$i ];
            continue;
        }
        if ( $char === '*' && $i + 1 < $length && $text[ $i + 1 ] === '*' ) {
            $push();
            $bold = ! $bold;
            $i++;
            continue;
        }
        if ( $char === '*' ) {
            $push();
            $italic = ! $italic;
            continue;
        }
        $buffer .= $char;
    }
    $push();

    // Emphasis that never closed: the asterisks were literal text.
    $styled = false;
    foreach ( $runs as $run ) {
        if ( ! empty( $run['b'] ) || ! empty( $run['i'] ) ) { $styled = true; break; }
    }
    return $styled ? $runs : [];
}

/**
 * Drop the first $skip characters of text from a list of runs.
 *
 * @return array The remaining runs, or [] when nothing is left.
 */
function aidocs_runs_after( array $runs, $skip ) {
    if ( ! $runs || $skip <= 0 ) return $runs;

    $out = [];
    foreach ( $runs as $run ) {
        $text   = (string) ( $run['text'] ?? '' );
        $length = mb_strlen( $text );
        if ( $skip >= $length ) { $skip -= $length; continue; }
        if ( $skip > 0 ) {
            $run['text'] = mb_substr( $text, $skip );
            $skip = 0;
        }
        $out[] = $run;
    }

    // A run of pure whitespace at the front is left over from the label.
    while ( $out && trim( $out[0]['text'] ) === '' ) array_shift( $out );
    return $out;
}

/** Remove bold from every run, and the runs entirely when nothing is left. */
function aidocs_runs_unbold( array $runs ) {
    $styled = false;
    foreach ( $runs as $index => $run ) {
        unset( $runs[ $index ]['b'] );
        if ( ! empty( $run['i'] ) ) $styled = true;
    }
    return $styled ? array_values( $runs ) : [];
}

/** The text of a canonical line with its emphasis markers removed. */
function aidocs_plain_text( $text ) {
    $text = (string) $text;
    if ( strpos( $text, '*' ) === false && strpos( $text, '\\' ) === false ) return trim( $text );
    $runs = aidocs_inline_runs( $text );
    if ( $runs ) {
        return trim( implode( '', array_column( $runs, 'text' ) ) );
    }
    return trim( preg_replace( '/\\\\([*\\\\])/', '$1', $text ) );
}

/**
 * Undo the canonical format's backslash escapes, keeping its emphasis markers.
 *
 * For the one caller that wants the "**bold**" cue but must not hand a
 * backslash to a language model: "\*" is how the extractor writes a literal
 * asterisk, and a model told to reuse text verbatim copies it into a JSON
 * string, where it is an escape sequence JSON does not define. Unescaping
 * first means the reply can only contain characters JSON is happy with.
 *
 * A literal asterisk becomes indistinguishable from emphasis by doing this.
 * That is the right trade here: the caller wants to know which lines were
 * bold, and a lone asterisk in a policy document is vanishingly rare next to
 * a bold section heading on nearly every page.
 */
function aidocs_unescape_markers( $text ) {
    $text = (string) $text;
    if ( strpos( $text, '\\' ) === false ) return $text;
    return preg_replace( '/\\\\([*\\\\])/', '$1', $text );
}

/** A stable anchor id for a heading. */
function aidocs_anchor_id( $text ) {
    $slug = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', aidocs_plain_text( $text ) ) );
    $slug = trim( $slug, '-' );
    return $slug === '' ? '' : 'sec-' . substr( $slug, 0, 60 );
}

// ──────────────────────────────────────────────
// Plain text (search indexing, AI context)
// ──────────────────────────────────────────────

/**
 * Flatten blocks back to plain text.
 *
 * @param array $blocks
 * @return string
 */
function aidocs_blocks_plain_text( array $blocks, $depth = 0 ) {
    $out    = [];
    $prefix = str_repeat( '  ', $depth );

    foreach ( $blocks as $block ) {
        switch ( $block['type'] ?? '' ) {
            case 'heading':
                if ( ! empty( $block['text'] ) ) $out[] = $block['text'];
                break;
            case 'paragraph':
                if ( ! empty( $block['text'] ) ) $out[] = $prefix . $block['text'];
                break;
            case 'note':
                $label = ! empty( $block['label'] ) ? $block['label'] . ': ' : '';
                $out[] = $prefix . $label . ( $block['text'] ?? '' );
                break;
            case 'list':
                foreach ( (array) ( $block['items'] ?? [] ) as $item ) {
                    // Content stored before nested items existed holds plain strings.
                    $item = is_array( $item ) ? $item : [ 'text' => (string) $item ];
                    if ( ! empty( $item['text'] ) ) $out[] = $prefix . '- ' . $item['text'];
                    if ( ! empty( $item['blocks'] ) ) {
                        $nested = aidocs_blocks_plain_text( $item['blocks'], $depth + 1 );
                        if ( $nested !== '' ) $out[] = $nested;
                    }
                }
                break;
            case 'table':
                if ( ! empty( $block['head'] ) ) $out[] = $prefix . implode( ' | ', (array) $block['head'] );
                foreach ( (array) ( $block['rows'] ?? [] ) as $row ) {
                    $out[] = $prefix . implode( ' | ', array_column( (array) $row, 'text' ) );
                }
                break;
        }
    }

    return implode( "\n", $out );
}

// ──────────────────────────────────────────────
// Canonical text (reverse of parsing — see EXTRACTION_FORMAT.md §2)
// ──────────────────────────────────────────────

/**
 * Reconstruct canonical text from already-parsed blocks — the reverse of
 * aidocs_parse_structured_content(). Feeding the result back through the
 * parser reproduces the same blocks, so this is what backs the "Edit
 * extracted content" textarea for a document extracted before
 * _document_raw_text existed to store the text it actually came from: rather
 * than leaving that textarea empty, it has an equivalent to start editing
 * from.
 */
function aidocs_blocks_to_canonical_text( array $blocks, $depth = 1 ) {
    $indent = str_repeat( '  ', max( 0, $depth - 1 ) );
    $lines  = [];

    foreach ( $blocks as $block ) {
        switch ( $block['type'] ?? '' ) {
            case 'heading':
                $level   = max( 2, min( 4, (int) ( $block['level'] ?? 3 ) ) );
                $lines[] = $indent . str_repeat( '#', $level ) . ' ' . ( $block['text'] ?? '' );
                break;

            case 'paragraph':
                $lines[] = $indent . aidocs_runs_to_markdown( $block );
                break;

            case 'note':
                $label   = ! empty( $block['label'] ) ? $block['label'] . ': ' : '';
                $lines[] = $indent . $label . aidocs_runs_to_markdown( $block );
                break;

            case 'list':
                $style = (string) ( $block['style'] ?? ( ! empty( $block['ordered'] ) ? 'decimal' : 'bullet' ) );
                $index = max( 1, (int) ( $block['start'] ?? 1 ) );
                foreach ( (array) ( $block['items'] ?? [] ) as $item ) {
                    // Content stored before nested items existed holds plain strings.
                    $item    = is_array( $item ) ? $item : [ 'text' => (string) $item ];
                    $marker  = $style === 'bullet' ? '-' : aidocs_list_marker( $style, $index++ );
                    $lines[] = $indent . $marker . ' ' . aidocs_runs_to_markdown( $item );
                    if ( ! empty( $item['blocks'] ) ) {
                        $nested = aidocs_blocks_to_canonical_text( (array) $item['blocks'], $depth + 1 );
                        if ( $nested !== '' ) $lines[] = $nested;
                    }
                }
                break;

            case 'table':
                $rows = [];
                if ( ! empty( $block['head'] ) ) $rows[] = (array) $block['head'];
                foreach ( (array) ( $block['rows'] ?? [] ) as $row ) $rows[] = (array) $row;
                foreach ( $rows as $row ) {
                    $cells   = array_map( function ( $cell ) {
                        return is_array( $cell ) ? ( $cell['text'] ?? '' ) : (string) $cell;
                    }, $row );
                    $lines[] = $indent . '| ' . implode( ' | ', $cells ) . ' |';
                }
                break;
        }
    }

    return implode( "\n", $lines );
}

/** A list item's ordinal marker at $index, in its own numbering style. */
function aidocs_list_marker( $style, $index ) {
    switch ( $style ) {
        case 'lower-alpha': return aidocs_index_to_alpha( $index ) . '.';
        case 'upper-alpha': return strtoupper( aidocs_index_to_alpha( $index ) ) . '.';
        case 'lower-roman': return aidocs_index_to_roman( $index ) . '.';
        case 'upper-roman': return strtoupper( aidocs_index_to_roman( $index ) ) . '.';
        default:            return $index . '.'; // decimal
    }
}

/** 1 → a, 2 → b, … 26 → z, 27 → aa, … spreadsheet-column style — the reverse of aidocs_alpha_index(). */
function aidocs_index_to_alpha( $index ) {
    $out = '';
    while ( $index > 0 ) {
        $index--;
        $out   = chr( 97 + ( $index % 26 ) ) . $out;
        $index = intdiv( $index, 26 );
    }
    return $out;
}

/** 1 → i, 2 → ii, … lowercase roman numerals — the reverse of aidocs_roman_index(). */
function aidocs_index_to_roman( $index ) {
    $map = [ 1000 => 'm', 900 => 'cm', 500 => 'd', 400 => 'cd', 100 => 'c', 90 => 'xc',
             50 => 'l', 40 => 'xl', 10 => 'x', 9 => 'ix', 5 => 'v', 4 => 'iv', 1 => 'i' ];
    $out = '';
    foreach ( $map as $value => $symbol ) {
        while ( $index >= $value ) {
            $out   .= $symbol;
            $index -= $value;
        }
    }
    return $out;
}

/** A block's runs re-rendered as markdown emphasis, falling back to its plain text. */
function aidocs_runs_to_markdown( array $block ) {
    $runs = (array) ( $block['runs'] ?? [] );
    if ( ! $runs ) return (string) ( $block['text'] ?? '' );

    $out = '';
    foreach ( $runs as $run ) {
        $text = (string) ( $run['text'] ?? '' );
        if ( $text === '' ) continue;
        $text = str_replace( [ '\\', '*' ], [ '\\\\', '\\*' ], $text );

        if ( empty( $run['b'] ) && empty( $run['i'] ) ) { $out .= $text; continue; }

        preg_match( '/^(\s*)(.*?)(\s*)$/s', $text, $m );
        [ , $lead, $core, $tail ] = $m + [ '', '', '', '' ];
        if ( $core === '' ) { $out .= $text; continue; }

        $mark = ( ! empty( $run['b'] ) && ! empty( $run['i'] ) ) ? '***' : ( ! empty( $run['b'] ) ? '**' : '*' );
        $out .= $lead . $mark . $core . $mark . $tail;
    }
    return $out !== '' ? $out : (string) ( $block['text'] ?? '' );
}

// ──────────────────────────────────────────────
// Rendering
// ──────────────────────────────────────────────

/**
 * Render content blocks as frontend HTML. Output is fully escaped.
 *
 * Every level-2 or level-3 heading that is not a note starts a collapsible
 * section — an accordion item whose summary is the heading and whose panel is
 * everything up to the next such heading. Sections start closed, so a reader
 * sees the document's outline first and opens what they need. A note heading
 * never collapses: what follows it is a callout the reader needs to see, not
 * a section to tuck away.
 */
function aidocs_render_content_blocks( array $blocks ) {
    if ( ! $blocks ) return '';
    return '<div class="aidocs-content">' . aidocs_render_sections( $blocks ) . '</div>';
}

/** Group blocks by their section headings and render each as an accordion item. */
function aidocs_render_sections( array $blocks ) {
    $html = '';
    foreach ( aidocs_group_sections( $blocks ) as $section ) {
        if ( ! $section['heading'] ) {
            $html .= aidocs_render_blocks( $section['blocks'] );
            continue;
        }

        $heading = $section['heading'];

        // A heading directly followed by another section heading — nothing of
        // its own in between — has no content to hide. It is a label
        // introducing the sections that follow it ("Procedures" ahead of
        // "Eligibility Process", "Determination", …), not a section in its
        // own right, so it renders as plain text rather than a collapsible
        // item whose panel would always be empty.
        if ( ! $section['blocks'] ) {
            $html .= aidocs_render_heading( $heading );
            continue;
        }

        $id    = ! empty( $heading['id'] ) ? ' id="' . esc_attr( $heading['id'] ) . '"' : '';
        $level = max( 2, min( 3, (int) ( $heading['level'] ?? 3 ) ) );

        $html .= '<details class="aidocs-accordion-item"' . $id . '>'
               . '<summary class="aidocs-accordion-summary aidocs-content-h' . $level . '">'
               . aidocs_render_runs( $heading )
               . '</summary>'
               . '<div class="aidocs-accordion-panel">' . aidocs_render_blocks( $section['blocks'] ) . '</div>'
               . '</details>';
    }
    return $html;
}

/**
 * Split a document body into sections at each heading that opens one.
 *
 * @return array List of {heading: block|null, blocks: block[]}. The first
 *               entry holds whatever precedes the first section heading, and
 *               is only included when that content is non-empty.
 */
function aidocs_group_sections( array $blocks ) {
    $sections = [ [ 'heading' => null, 'blocks' => [] ] ];

    foreach ( $blocks as $block ) {
        $opens_section = ( $block['type'] ?? '' ) === 'heading'
            && in_array( (int) ( $block['level'] ?? 0 ), [ 2, 3 ], true )
            && empty( $block['note'] );

        if ( $opens_section ) {
            $sections[] = [ 'heading' => $block, 'blocks' => [] ];
            continue;
        }
        $sections[ count( $sections ) - 1 ]['blocks'][] = $block;
    }

    if ( $sections[0]['heading'] === null && ! $sections[0]['blocks'] ) {
        array_shift( $sections );
    }
    return $sections;
}

function aidocs_render_blocks( array $blocks ) {
    $html = '';
    foreach ( $blocks as $block ) {
        switch ( $block['type'] ?? '' ) {
            case 'heading':
                $html .= aidocs_render_heading( $block );
                break;
            case 'paragraph':
                $html .= '<p class="aidocs-content-p">' . aidocs_render_runs( $block ) . '</p>';
                break;
            case 'note':
                $html .= aidocs_render_note( $block );
                break;
            case 'list':
                $html .= aidocs_render_list( $block );
                break;
            case 'table':
                $html .= aidocs_render_table( $block );
                break;
        }
    }
    return $html;
}

function aidocs_render_heading( array $block ) {
    $level = max( 2, min( 4, (int) ( $block['level'] ?? 3 ) ) );
    $note  = (string) ( $block['note'] ?? '' );
    $class = 'aidocs-content-h' . $level;
    if ( $note !== '' ) $class .= ' aidocs-note-heading aidocs-note-heading--' . $note;
    $id = ! empty( $block['id'] ) ? ' id="' . esc_attr( $block['id'] ) . '"' : '';

    return sprintf(
        '<h%1$d%2$s class="%3$s">%4$s</h%1$d>',
        $level,
        $id,
        esc_attr( $class ),
        aidocs_render_runs( $block )
    );
}

function aidocs_render_note( array $block ) {
    $variant = (string) ( $block['variant'] ?? 'note' );
    $label   = (string) ( $block['label'] ?? '' );

    $html  = '<div class="aidocs-note aidocs-note--' . esc_attr( $variant ) . '">';
    if ( $label !== '' ) {
        $html .= '<span class="aidocs-note-label">' . esc_html( $label ) . '</span>';
    }
    $html .= '<p class="aidocs-note-text">' . aidocs_render_runs( $block ) . '</p>';
    return $html . '</div>';
}

function aidocs_render_list( array $block ) {
    $style = (string) ( $block['style'] ?? ( ! empty( $block['ordered'] ) ? 'decimal' : 'bullet' ) );
    $tag   = ( $style === 'bullet' ) ? 'ul' : 'ol';
    $class = 'aidocs-content-list aidocs-content-list--' . $style;

    // The number is a CSS counter badge, not the browser's own marker (see
    // aidocs_content_block_css()), so continuing a list's numbering after an
    // interrupting paragraph — "start" — has to prime that counter directly;
    // the HTML start="" attribute alone would only affect a marker nothing
    // here still uses.
    $start = '';
    if ( $tag === 'ol' && ! empty( $block['start'] ) ) {
        $n     = (int) $block['start'];
        $start = ' start="' . $n . '" style="counter-reset:cd-item ' . ( $n - 1 ) . '"';
    }

    $html = '<' . $tag . $start . ' class="' . esc_attr( $class ) . '">';
    foreach ( (array) ( $block['items'] ?? [] ) as $item ) {
        // Content stored before nested items existed holds plain strings.
        $item  = is_array( $item ) ? $item : [ 'text' => (string) $item ];
        $html .= '<li>' . aidocs_render_runs( $item );
        if ( ! empty( $item['blocks'] ) ) {
            $html .= aidocs_render_blocks( (array) $item['blocks'] );
        }
        $html .= '</li>';
    }
    return $html . '</' . $tag . '>';
}

function aidocs_render_table( array $block ) {
    $html = '<div class="aidocs-table-wrap"><table class="aidocs-content-table">';
    if ( ! empty( $block['head'] ) ) {
        $html .= '<thead><tr>';
        foreach ( (array) $block['head'] as $cell ) {
            $html .= '<th>' . esc_html( is_array( $cell ) ? ( $cell['text'] ?? '' ) : $cell ) . '</th>';
        }
        $html .= '</tr></thead>';
    }
    $html .= '<tbody>';
    foreach ( (array) ( $block['rows'] ?? [] ) as $row ) {
        $html .= '<tr>';
        foreach ( (array) $row as $cell ) {
            $html .= '<td>' . aidocs_render_runs( is_array( $cell ) ? $cell : [ 'text' => $cell ] ) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}

/**
 * Render a block's text, honouring the bold and italic runs of the source.
 */
function aidocs_render_runs( array $block ) {
    $runs = (array) ( $block['runs'] ?? [] );
    if ( ! $runs ) return esc_html( (string) ( $block['text'] ?? '' ) );

    $html = '';
    foreach ( $runs as $run ) {
        $text = esc_html( (string) ( $run['text'] ?? '' ) );
        if ( $text === '' ) continue;
        if ( ! empty( $run['b'] ) ) $text = '<strong>' . $text . '</strong>';
        if ( ! empty( $run['i'] ) ) $text = '<em>' . $text . '</em>';
        $html .= $text;
    }
    return $html === '' ? esc_html( (string) ( $block['text'] ?? '' ) ) : $html;
}

/**
 * The CSS for rendered content blocks, shared by the single view and the
 * search modal so both render a document identically.
 */
function aidocs_content_block_css() {
    return <<<'CSS'
/* Theme hand-off: a block theme (this site's included) publishes its palette
   and its button rounding as these custom properties on every frontend page.
   Reading them here — with a fallback for wp-admin previews, where they are
   never defined — is what lets buttons, badges and section headers pick up
   the active theme's look instead of a colour fixed at build time. */
.aidocs-content{
    --cd-primary:var(--wp--preset--color--raft-accent,#C26148);
    --cd-secondary:var(--wp--preset--color--raft-accent-secondary,#AC5039);
    --cd-base:var(--wp--preset--color--raft-fg-alt,#FDFDFD);
    --cd-contrast:var(--wp--preset--color--raft-fg,#1D1F25);
    --cd-radius:var(--wp--custom--button-border-radius,4px);
}
.aidocs-content strong{font-weight:700;color:var(--cd-contrast);}
.aidocs-content em{font-style:italic;}
.aidocs-content-h4{font-size:13px;font-weight:700;color:var(--cd-primary);margin:18px 0 6px;}

/* Accordion: every level-2/3 section heading is collapsible, starting closed. */
.aidocs-accordion-item{border:1px solid #e5e9ef;border-radius:var(--cd-radius);margin:0 0 10px;overflow:hidden;}
.aidocs-accordion-item + .aidocs-accordion-item{margin-top:10px;}
.aidocs-accordion-summary{cursor:pointer;list-style:none;display:flex;align-items:center;gap:10px;padding:13px 16px;margin:0;background:#f7f9f8;user-select:none;}
.aidocs-accordion-summary::-webkit-details-marker{display:none;}
.aidocs-accordion-summary::before{content:'';flex-shrink:0;width:7px;height:7px;border-right:2px solid var(--cd-primary);border-bottom:2px solid var(--cd-primary);transform:rotate(45deg);transition:transform .18s;}
.aidocs-accordion-item[open] > .aidocs-accordion-summary::before{transform:rotate(-135deg);}
.aidocs-accordion-item[open] > .aidocs-accordion-summary{border-bottom:1px solid #e5e9ef;}
.aidocs-accordion-summary:hover{background:color-mix(in srgb,var(--cd-primary) 8%,#f7f9f8);}
.aidocs-accordion-summary.aidocs-content-h2,.aidocs-accordion-summary.aidocs-content-h3{margin:0;color:var(--cd-contrast);}
.aidocs-accordion-panel{padding:15px 16px 4px;}
.aidocs-accordion-panel > .aidocs-content-h2:first-child,.aidocs-accordion-panel > .aidocs-content-h3:first-child,.aidocs-accordion-panel > .aidocs-content-h4:first-child{margin-top:0;}

/* Lists: the marker is a small rounded badge carrying the item's own number
   or letter, not the browser's native mark — set with a CSS counter so the
   count still advances correctly through a., b., c. … or i., ii., iii. … */
.aidocs-content-list{list-style:none;padding-left:0;counter-reset:cd-item;}
.aidocs-content-list > li{position:relative;padding-left:32px;counter-increment:cd-item;}
.aidocs-content-list > li::before{
    content:counter(cd-item);
    position:absolute;left:0;top:1px;
    display:inline-flex;align-items:center;justify-content:center;
    min-width:22px;height:22px;padding:0 4px;box-sizing:border-box;
    background:var(--cd-secondary);color:var(--cd-base);
    font-size:11.5px;font-weight:700;line-height:1;
    border-radius:var(--cd-radius);
}
.aidocs-content-list--lower-alpha > li::before{content:counter(cd-item,lower-alpha);}
.aidocs-content-list--upper-alpha > li::before{content:counter(cd-item,upper-alpha);}
.aidocs-content-list--lower-roman > li::before{content:counter(cd-item,lower-roman);}
.aidocs-content-list--upper-roman > li::before{content:counter(cd-item,upper-roman);}
.aidocs-content-list--bullet > li::before{content:'';width:8px;min-width:0;height:8px;padding:0;top:8px;border-radius:50%;background:var(--cd-secondary);}
.aidocs-content-list .aidocs-content-list{margin:8px 0 4px;}
.aidocs-content-list .aidocs-content-p{margin:6px 0;}

/* Notes: a labelled heading for note sections, a boxed callout for inline notes */
.aidocs-note-heading{position:relative;padding-left:13px;border-left:3px solid #c8a24a;color:#8a6d1f;text-transform:none;letter-spacing:0;}
.aidocs-note-heading--international{border-left-color:#3d7ea6;color:#2c5f7c;}
.aidocs-note-heading--substantive-change{border-left-color:#c0562b;color:#9c4522;}
.aidocs-note-heading--teach-out{border-left-color:#5b7c3d;color:#456029;}
.aidocs-note-heading--restriction{border-left-color:#b0203c;color:#8c1930;}
.aidocs-note{margin:0 0 16px;padding:12px 15px;background:#fdfaf2;border:1px solid #f0e3c4;border-left:3px solid #c8a24a;border-radius:0 var(--cd-radius) var(--cd-radius) 0;}
.aidocs-note--international{background:#f4f9fc;border-color:#d3e6f0;border-left-color:#3d7ea6;}
.aidocs-note--substantive-change{background:#fdf5f2;border-color:#f3ded4;border-left-color:#c0562b;}
.aidocs-note--teach-out{background:#f6faf3;border-color:#dfead4;border-left-color:#5b7c3d;}
.aidocs-note--restriction{background:#fdf3f5;border-color:#f4d6dd;border-left-color:#b0203c;}
.aidocs-note-label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#8a6d1f;margin-bottom:5px;}
.aidocs-note--international .aidocs-note-label{color:#2c5f7c;}
.aidocs-note--substantive-change .aidocs-note-label{color:#9c4522;}
.aidocs-note--teach-out .aidocs-note-label{color:#456029;}
.aidocs-note--restriction .aidocs-note-label{color:#8c1930;}
.aidocs-note-text{margin:0;font-size:14px;line-height:1.75;color:#374151;}
/* Tables */
.aidocs-table-wrap{overflow-x:auto;margin:0 0 18px;}
.aidocs-content-table{border-collapse:collapse;width:100%;font-size:13px;border-radius:var(--cd-radius);}
.aidocs-content-table th,.aidocs-content-table td{border:1px solid #e5e9ef;padding:8px 10px;text-align:left;vertical-align:top;line-height:1.6;color:#374151;}
.aidocs-content-table th{background:#f6f8fa;font-weight:700;color:var(--cd-contrast);}
CSS;
}
