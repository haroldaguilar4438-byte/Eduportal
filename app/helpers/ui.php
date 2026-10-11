<?php
declare(strict_types=1);

/**
 * EduPortal — UI helper layer.
 * Loaded by core/bootstrap.php right after helpers/functions.php.
 *
 * These helpers ONLY format and return HTML fragments. They never touch
 * the database, sessions, routes or authorization, so adding or changing
 * presentation can never alter backend behaviour.
 */

/* ------------------------------------------------------------------ *
 * Status vocabulary
 *
 * Maps any status string coming from the database onto one of four
 * semantic tones. Colour is never the only signal — every rendered
 * badge also carries an icon and the original text label.
 * ------------------------------------------------------------------ */

/**
 * Canonical status -> tone map.
 * Tones: success | warning | danger | info | neutral
 */
function edu_status_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [
        /* ---- success ---- */
        'approved'          => 'success',
        'active'            => 'success',
        'paid'              => 'success',
        'verified'          => 'success',
        'completed'         => 'success',
        'cleared'           => 'success',
        'enrolled'          => 'success',
        'finalized'         => 'success',
        'confirmed'         => 'success',
        'published'         => 'success',
        'released'          => 'success',
        'signed'            => 'success',
        'passed'            => 'success',
        'valid'             => 'success',
        'complete'          => 'success',
        'done'              => 'success',
        'accepted'          => 'success',
        'success'           => 'success',
        'graduated'         => 'success',
        'fully_paid'        => 'success',
        'ok'                => 'success',

        /* ---- warning ---- */
        'pending'           => 'warning',
        'processing'        => 'warning',
        'under_review'      => 'warning',
        'submitted'         => 'warning',
        'for_review'        => 'warning',
        'partially_paid'    => 'warning',
        'awaiting'          => 'warning',
        'incomplete'        => 'warning',
        'in_progress'       => 'warning',
        'on_hold'           => 'warning',
        'hold'              => 'warning',
        'draft_pending'     => 'warning',
        'partial'           => 'warning',
        'scheduled'         => 'warning',
        'near_due'          => 'warning',

        /* ---- danger ---- */
        'rejected'          => 'danger',
        'failed'            => 'danger',
        'overdue'           => 'danger',
        'blocked'           => 'danger',
        'cancelled'         => 'danger',
        'canceled'          => 'danger',
        'denied'            => 'danger',
        'declined'          => 'danger',
        'expired'           => 'danger',
        'unpaid'            => 'danger',
        'locked'            => 'danger',
        'banned'            => 'danger',
        'suspended'         => 'danger',
        'returned'          => 'danger',
        'disqualified'      => 'danger',
        'over_amount'       => 'danger',
        'failing'           => 'danger',
        'inc'               => 'danger',
        'incomplete_final'  => 'danger',

        /* ---- info ---- */
        'approved_pending_payment' => 'info',
        'processing_payment'       => 'info',
        'sent'              => 'info',
        'review'            => 'info',
        'enrolled_pending'  => 'info',
        'new'               => 'info',
        'scheduled_publish' => 'info',
        'notified'          => 'info',
        'awaiting_payment'  => 'info',
        'payment_pending'   => 'info',
        'payment_submitted' => 'info',

        /* ---- neutral ---- */
        'draft'             => 'neutral',
        'inactive'          => 'neutral',
        'archived'          => 'neutral',
        'archived_announcement' => 'neutral',
        'unassigned'        => 'neutral',
        'optional'          => 'neutral',
        'not_applicable'    => 'neutral',
        'na'                => 'neutral',
        'none'              => 'neutral',
        'unknown'           => 'neutral',
        'open'              => 'info',
    ];

    return $map;
}

/**
 * Resolve a raw status string to a semantic tone.
 */
function edu_status_tone(?string $status): string
{
    if ($status === null || trim($status) === '') {
        return 'neutral';
    }
    $key = strtolower(trim($status));
    $map = edu_status_map();

    if (isset($map[$key])) {
        return $map[$key];
    }
    // Normalise separators so "under-review", "under review" and
    // "under_review" all resolve to the same tone.
    $normalized = str_replace([' ', '-', '.'], '_', $key);
    if (isset($map[$normalized])) {
        return $map[$normalized];
    }

    /* Fallback heuristics for statuses not explicitly enumerated above. */
    if (str_contains($normalized, 'reject') || str_contains($normalized, 'fail')
        || str_contains($normalized, 'cancel') || str_contains($normalized, 'decline')
        || str_contains($normalized, 'overdue') || str_contains($normalized, 'block')) {
        return 'danger';
    }
    if (str_contains($normalized, 'approv') || str_contains($normalized, 'paid')
        || str_contains($normalized, 'clear') || str_contains($normalized, 'complete')
        || str_contains($normalized, 'verified') || str_contains($normalized, 'valid')) {
        return 'success';
    }
    if (str_contains($normalized, 'pend') || str_contains($normalized, 'review')
        || str_contains($normalized, 'wait') || str_contains($normalized, 'process')) {
        return 'warning';
    }

    return 'neutral';
}

/**
 * Icon glyph for a status, so meaning is never carried by colour alone.
 */
function edu_status_icon(?string $status): string
{
    return match (edu_status_tone($status)) {
        'success' => 'bi-check-circle-fill',
        'warning' => 'bi-clock-fill',
        'danger'  => 'bi-x-circle-fill',
        'info'    => 'bi-info-circle-fill',
        default   => 'bi-dash-circle-fill',
    };
}

/** Human label for a status string. */
function edu_status_label(?string $status): string
{
    $status = trim((string) $status);
    if ($status === '') {
        return 'Unknown';
    }
    return ucwords(str_replace(['_', '-'], ' ', $status));
}

/**
 * Render a status badge.
 *
 * @param string|null $status Raw status value from the database
 * @param string      $size   '' | 'lg'
 */
function edu_status_badge(?string $status, string $size = ''): string
{
    $tone   = edu_status_tone($status);
    $icon   = edu_status_icon($status);
    $label  = edu_status_label($status);
    $sizeCl = $size === 'lg' ? ' edu-badge--lg' : '';

    return '<span class="edu-badge edu-badge--' . $tone . $sizeCl . '">'
        . '<i class="bi ' . $icon . '" aria-hidden="true"></i>'
        . '<span>' . e($label) . '</span></span>';
}

/**
 * Render a static (non-status) badge in a chosen tone.
 */
function edu_badge(string $label, string $tone = 'primary', ?string $icon = null, string $size = ''): string
{
    $allowed = ['success', 'warning', 'danger', 'info', 'neutral', 'primary', 'accent', 'solid'];
    $tone    = in_array($tone, $allowed, true) ? $tone : 'primary';
    $sizeCl  = $size === 'lg' ? ' edu-badge--lg' : '';
    $iconHtml = $icon !== null ? '<i class="bi ' . e($icon) . '" aria-hidden="true"></i>' : '';

    return '<span class="edu-badge edu-badge--' . $tone . $sizeCl . '">'
        . $iconHtml . '<span>' . e($label) . '</span></span>';
}

/* ------------------------------------------------------------------ *
 * Icons
 * ------------------------------------------------------------------ */

/**
 * Render a single icon span.
 */
function edu_icon(string $icon, string $class = '', bool $decorative = true): string
{
    $aria = $decorative ? ' aria-hidden="true"' : '';
    $cls  = trim('bi ' . $icon . ' ' . $class);
    return '<i class="' . e($cls) . '"' . $aria . '></i>';
}

/* ------------------------------------------------------------------ *
 * Page header
 * ------------------------------------------------------------------ */

/**
 * Render the standard page header: eyebrow, title, description, actions.
 *
 * @param array{title?:string,description?:string,eyebrow?:string,actions?:string,icon?:string,compact?:bool} $opts
 */
function edu_page_header(array $opts = []): string
{
    $title = (string) ($opts['title'] ?? '');
    $desc  = trim((string) ($opts['description'] ?? ''));
    $eyebrow = trim((string) ($opts['eyebrow'] ?? ''));
    $icon  = trim((string) ($opts['icon'] ?? ''));
    $actions = (string) ($opts['actions'] ?? '');
    $compact = !empty($opts['compact']);

    $classes = 'edu-page-header' . ($compact ? ' edu-page-header--compact' : '');

    $left = '';
    if ($eyebrow !== '') {
        $left .= '<div class="edu-page-header__eyebrow">'
            . ($icon !== '' ? edu_icon($icon) : '') . '<span>' . e($eyebrow) . '</span></div>';
    }
    if ($title !== '') {
        $left .= '<h1 class="edu-page-header__title">' . e($title) . '</h1>';
    }
    if ($desc !== '') {
        $left .= '<p class="edu-page-header__desc">' . $desc . '</p>';
    }

    $right = $actions !== ''
        ? '<div class="edu-page-header__actions">' . $actions . '</div>'
        : '';

    return '<header class="' . $classes . '">'
        . '<div class="flex-1 min-w-0">' . $left . '</div>'
        . $right
        . '</header>';
}

/* ------------------------------------------------------------------ *
 * Breadcrumb
 * ------------------------------------------------------------------ */

/**
 * Render a breadcrumb trail.
 *
 * @param array<int,array{label:string,url?:string}> $items
 */
function edu_breadcrumb(array $items, string $class = ''): string
{
    if ($items === []) {
        return '';
    }

    $html = '<nav class="edu-breadcrumb ' . e($class) . '" aria-label="Breadcrumb"><ol class="flex items-center gap-2 flex-wrap m-0 p-0 list-none">';

    $last = count($items) - 1;
    foreach ($items as $i => $item) {
        $label = (string) ($item['label'] ?? '');
        $url   = $item['url'] ?? null;

        $html .= '<li class="edu-breadcrumb__item flex items-center gap-2">';
        if ($url !== null && $url !== '' && $i < $last) {
            $html .= '<a href="' . e($url) . '">' . e($label) . '</a>';
            $html .= '<span class="edu-breadcrumb__sep" aria-hidden="true">' . edu_icon('bi-chevron-right') . '</span>';
        } else {
            $html .= '<span class="edu-breadcrumb__current" aria-current="page">' . e($label) . '</span>';
        }
        $html .= '</li>';
    }

    return $html . '</ol></nav>';
}

/* ------------------------------------------------------------------ *
 * Stat card
 * ------------------------------------------------------------------ */

/**
 * Render a KPI stat card.
 *
 * @param array{label:string,value:string,icon?:string,tone?:string,meta?:string,href?:string,size?:string} $opts
 */
function edu_stat_card(array $opts): string
{
    $label = (string) ($opts['label'] ?? '');
    $value = (string) ($opts['value'] ?? '');
    $icon  = trim((string) ($opts['icon'] ?? ''));
    $tone  = trim((string) ($opts['tone'] ?? ''));
    $meta  = trim((string) ($opts['meta'] ?? ''));
    $href  = $opts['href'] ?? null;
    $small = !empty($opts['size']) && $opts['size'] === 'sm';

    $iconHtml = $icon !== ''
        ? '<span class="edu-stat__icon' . ($tone !== '' ? ' edu-stat__icon--' . e($tone) : '') . '" aria-hidden="true">'
            . edu_icon($icon) . '</span>'
        : '';

    $metaHtml = $meta !== ''
        ? '<div class="edu-stat__meta">' . $meta . '</div>'
        : '';

    $valueHtml = '<div class="edu-stat__value' . ($small ? ' edu-stat__value--sm' : '') . '">' . $value . '</div>';

    $linkHtml = '';
    $inner = '<div class="edu-stat__head"><div class="min-w-0 flex-1">'
        . '<div class="edu-stat__label">' . e($label) . '</div>'
        . $valueHtml
        . '</div>' . $iconHtml . '</div>' . $metaHtml;

    if ($href !== null && $href !== '') {
        $linkHtml = '<a class="edu-stat__link" href="' . e($href) . '" aria-label="' . e($label) . ': ' . strip_tags($value) . '"></a>';
    }

    return '<div class="edu-stat">' . $inner . $linkHtml . '</div>';
}

/* ------------------------------------------------------------------ *
 * Empty / loading / error states
 * ------------------------------------------------------------------ */

/**
 * Render an empty state. Always explain what is missing and what to do next.
 */
function edu_empty_state(string $title, string $description = '', array $opts = []): string
{
    $icon    = (string) ($opts['icon'] ?? 'bi-inbox');
    $actions = (string) ($opts['actions'] ?? '');
    $variant = (string) ($opts['variant'] ?? '');   // '', 'error', 'warning'
    $compact = !empty($opts['compact']);

    $classes = 'edu-empty' . ($variant !== '' ? ' edu-empty--' . e($variant) : '') . ($compact ? ' edu-empty--compact' : '');

    $html = '<div class="' . $classes . '">'
        . '<div class="edu-empty__icon" aria-hidden="true">' . edu_icon($icon) . '</div>'
        . '<strong class="edu-empty__title">' . e($title) . '</strong>';

    if ($description !== '') {
        $html .= '<p class="edu-empty__desc">' . e($description) . '</p>';
    }
    if ($actions !== '') {
        $html .= '<div class="edu-empty__actions">' . $actions . '</div>';
    }

    return $html . '</div>';
}

/**
 * Render a callout / inline notice.
 */
function edu_callout(string $message, array $opts = []): string
{
    $tone  = (string) ($opts['tone'] ?? 'info');
    $icon  = (string) ($opts['icon'] ?? match ($tone) {
        'success' => 'bi-check-circle-fill',
        'warning' => 'bi-exclamation-triangle-fill',
        'danger'  => 'bi-exclamation-octagon-fill',
        'accent'  => 'bi-stars',
        default   => 'bi-info-circle-fill',
    });
    $title = trim((string) ($opts['title'] ?? ''));

    $allowed = ['info', 'success', 'warning', 'danger', 'accent'];
    $tone = in_array($tone, $allowed, true) ? $tone : 'info';

    $html = '<div class="edu-callout' . ($tone === 'info' ? '' : ' edu-callout--' . $tone) . '" role="note">'
        . '<i class="bi ' . e($icon) . ' edu-callout__icon" aria-hidden="true"></i>'
        . '<div class="min-w-0 flex-1">';
    if ($title !== '') {
        $html .= '<strong class="d-block mb-1">' . e($title) . '</strong>';
    }
    return $html . '<div>' . $message . '</div></div></div>';
}

/**
 * Render a skeleton loading block (used while a section hydrates).
 */
function edu_skeleton_rows(int $rows = 4, int $cols = 5): string
{
    $html = '<div class="edu-table-wrap" aria-busy="true" aria-label="Loading">'
        . '<div class="edu-table-scroll"><table class="edu-table"><thead><tr>';
    for ($c = 0; $c < $cols; $c++) {
        $html .= '<th><span class="skeleton d-block" style="width:70%"></span></th>';
    }
    $html .= '</tr></thead><tbody>';
    for ($r = 0; $r < $rows; $r++) {
        $html .= '<tr>';
        for ($c = 0; $c < $cols; $c++) {
            $html .= '<td><span class="skeleton d-block" style="width:' . (55 + (($r + $c) % 4) * 12) . '%"></span></td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div></div>';
}

/* ------------------------------------------------------------------ *
 * Section heading
 * ------------------------------------------------------------------ */

function edu_section_head(string $title, string $sub = '', string $actions = '', string $icon = ''): string
{
    $html = '<div class="edu-section-head"><div class="min-w-0">'
        . '<h2 class="edu-section-head__title">' . ($icon !== '' ? edu_icon($icon) : '') . '<span>' . e($title) . '</span></h2>';
    if ($sub !== '') {
        $html .= '<p class="edu-section-head__sub">' . e($sub) . '</p>';
    }
    $html .= '</div>';
    if ($actions !== '') {
        $html .= '<div class="edu-section-head__actions">' . $actions . '</div>';
    }
    return $html . '</div>';
}

/* ------------------------------------------------------------------ *
 * Definition list
 * ------------------------------------------------------------------ */

/**
 * Render label/value pairs.
 *
 * @param array<string|int,string> $rows  Associative (label => value) or list of ['label'=>..,'value'=>..]
 * @param bool $stacked Render label above value (used on mobile)
 */
function edu_dl(array $rows, bool $stacked = false, array $opts = []): string
{
    if ($rows === []) {
        return '';
    }
    $html = '<dl class="edu-dl' . ($stacked ? ' edu-dl--stacked' : '') . '">';
    foreach ($rows as $key => $row) {
        if (is_array($row)) {
            $label = (string) ($row['label'] ?? '');
            $value = (string) ($row['value'] ?? '');
        } else {
            $label = (string) $key;
            $value = (string) $row;
        }
        if (($opts['skip_empty'] ?? true) && trim($value) === '') {
            continue;
        }
        $html .= '<div class="edu-dl__row"><dt>' . e($label) . '</dt><dd>' . $value . '</dd></div>';
    }
    return $html . '</dl>';
}

/* ------------------------------------------------------------------ *
 * Progress
 * ------------------------------------------------------------------ */

/**
 * Render a labelled progress bar.
 *
 * @param float $percent 0-100 (clamped)
 */
function edu_progress(float $percent, array $opts = []): string
{
    $pct = max(0.0, min(100.0, $percent));
    $tone = (string) ($opts['tone'] ?? '');
    $size = (string) ($opts['size'] ?? '');
    $label = trim((string) ($opts['label'] ?? ''));
    $showMeta = !empty($opts['meta']);

    $allowedTones = ['success', 'warning', 'danger', 'accent'];
    $barClass = 'edu-progress__bar' . (in_array($tone, $allowedTones, true) ? ' edu-progress__bar--' . $tone : '');
    $sizeClass = $size !== '' ? ' edu-progress--' . e($size) : '';

    $html = '';
    if ($showMeta || $label !== '') {
        $html .= '<div class="edu-progress__meta">'
            . '<span>' . ($label !== '' ? e($label) : '') . '</span>'
            . '<span>' . number_format($pct, 0) . '%</span>'
            . '</div>';
    }
    $html .= '<div class="edu-progress' . $sizeClass . '" role="progressbar"'
        . ' aria-valuenow="' . (int) round($pct) . '" aria-valuemin="0" aria-valuemax="100"'
        . ' aria-label="' . e($label !== '' ? $label : 'Progress') . '">'
        . '<div class="' . $barClass . '" style="width:' . number_format($pct, 2) . '%"></div></div>';

    return $html;
}

/* ------------------------------------------------------------------ *
 * Tabs
 * ------------------------------------------------------------------ */

/**
 * Render a tab bar.
 *
 * @param array<int,array{label:string,url:string,count?:int|string|null,icon?:string}> $tabs
 * @param string $activeKey Key of the active tab, matched against each tab's `key`
 */
function edu_tabs(array $tabs, ?string $activeKey = null): string
{
    if ($tabs === []) {
        return '';
    }
    $html = '<nav class="edu-tabs" role="tablist">';
    foreach ($tabs as $tab) {
        $key   = (string) ($tab['key'] ?? $tab['url'] ?? '');
        $label = (string) ($tab['label'] ?? '');
        $url   = (string) ($tab['url'] ?? '#');
        $count = $tab['count'] ?? null;
        $icon  = trim((string) ($tab['icon'] ?? ''));

        $isActive = $activeKey !== null ? ($key === $activeKey) : is_active_path($url, true);

        $html .= '<a class="edu-tab' . ($isActive ? ' is-active' : '') . '" href="' . e($url) . '"'
            . ($isActive ? ' aria-current="page"' : '')
            . ($icon !== '' ? ' title="' . e($icon) . '"' : '') . '>'
            . ($icon !== '' ? edu_icon($icon) : '')
            . '<span>' . e($label) . '</span>'
            . ($count !== null && $count !== '' ? '<span class="edu-tab__count">' . e((string) $count) . '</span>' : '')
            . '</a>';
    }
    return $html . '</nav>';
}

/* ------------------------------------------------------------------ *
 * Timeline
 * ------------------------------------------------------------------ */

/**
 * Render a vertical verification / activity timeline.
 *
 * @param array<int,array{title:string,meta?:string,body?:string,state?:string}> $items
 *        state: complete | current | failed | '' (pending)
 */
function edu_timeline(array $items): string
{
    if ($items === []) {
        return '';
    }
    $html = '<div class="edu-timeline">';
    foreach ($items as $item) {
        $state = (string) ($item['state'] ?? '');
        $class = $state !== '' ? ' is-' . e($state) : '';
        /* Accept stepper-shaped data too (label/hint): two callers were
           passing those keys, which rendered as empty untitled markers. */
        $title = (string) ($item['title'] ?? $item['label'] ?? '');
        $meta  = (string) ($item['meta'] ?? $item['hint'] ?? '');
        $html .= '<div class="edu-timeline-item' . $class . '">'
            . '<div class="edu-timeline-item__title">' . e($title) . '</div>';
        if ($meta !== '') {
            $html .= '<div class="edu-timeline-item__meta">' . e($meta) . '</div>';
        }
        if (!empty($item['body'])) {
            $html .= '<div class="edu-timeline-item__body">' . $item['body'] . '</div>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

/* ------------------------------------------------------------------ *
 * Stepper
 * ------------------------------------------------------------------ */

/**
 * Render a horizontal (or vertical) progress stepper.
 *
 * @param array<int,array{label:string,hint?:string,state?:string}> $steps
 *        state: complete | current | '' (upcoming)
 * @param bool $vertical
 */
function edu_stepper(array $steps, bool $vertical = false): string
{
    if ($steps === []) {
        return '';
    }
    $html = '<ol class="edu-stepper' . ($vertical ? ' edu-stepper--vertical' : '') . '">';
    $last = count($steps) - 1;

    foreach ($steps as $i => $step) {
        $state  = (string) ($step['state'] ?? '');
        $class  = $state !== '' ? ' is-' . e($state) : '';
        $label  = (string) ($step['label'] ?? '');
        $hint   = trim((string) ($step['hint'] ?? ''));

        $marker = match ($state) {
            'complete' => edu_icon('bi-check-lg'),
            'current'  => '<span class="stat-count-pulse" style="background:#fff"></span>',
            default    => '<span>' . ($i + 1) . '</span>',
        };

        $html .= '<li class="edu-stepper__item' . $class . '">'
            . '<span class="edu-stepper__marker" aria-hidden="true">' . $marker . '</span>'
            . '<span class="edu-stepper__text">'
            . '<span class="edu-stepper__label">' . e($label) . '</span>'
            . ($hint !== '' ? '<span class="edu-stepper__hint">' . e($hint) . '</span>' : '')
            . '</span>';

        if ($i < $last) {
            $lineClass = $state === 'complete' ? ' is-complete' : '';
            $html .= '<span class="edu-stepper__line' . $lineClass . '" aria-hidden="true"></span>';
        }

        $html .= '</li>';
    }

    return $html . '</ol>';
}

/* ------------------------------------------------------------------ *
 * Formatting
 * ------------------------------------------------------------------ */

/** Compact money for dense tables (₱1,250). */
function edu_money_compact(float|int|string|null $amount): string
{
    $amount = (float) $amount;
    if (abs($amount) >= 1000000) {
        return '₱' . rtrim(rtrim(number_format($amount / 1000000, 2), '0'), '.') . 'M';
    }
    if (abs($amount) >= 1000) {
        return '₱' . rtrim(rtrim(number_format($amount / 1000, 1), '0'), '.') . 'K';
    }
    return '₱' . number_format($amount, 2);
}

/** Format a date, or a dash when empty. */
function edu_date(?string $date, string $format = 'M j, Y'): string
{
    $date = trim((string) $date);
    if ($date === '' || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($date);
    return $ts === false ? '—' : date($format, $ts);
}

/** Format a date + time, or a dash when empty. */
function edu_datetime(?string $date): string
{
    return edu_date($date, 'M j, Y · g:i A');
}

/** Initials for avatar fallbacks. */
function edu_initials(?string $first, ?string $last): string
{
    $a = strtoupper(substr(trim((string) $first), 0, 1));
    $b = strtoupper(substr(trim((string) $last), 0, 1));
    $out = trim($a . $b);
    return $out !== '' ? $out : 'U';
}

/** Percentage helper that guards divide-by-zero. */
function edu_percent(float|int $part, float|int $total, int $decimals = 0): float
{
    $total = (float) $total;
    if ($total <= 0) {
        return 0.0;
    }
    return round(((float) $part) / $total * 100, $decimals);
}

/** Tone for a percentage (used for capacity meters and balances). */
function edu_percent_tone(float $percent): string
{
    if ($percent >= 100) return 'danger';
    if ($percent >= 85)  return 'warning';
    if ($percent >= 60)  return 'success';
    return 'info';
}
