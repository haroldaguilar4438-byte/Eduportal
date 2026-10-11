<?php
/**
 * Student schedule — a real weekly academic calendar (spec §10).
 *
 * Non-destructive: $rows and $student are exactly what StudentPortalController
 * passed before; every field, filter and link is preserved. The only change is
 * presentation: a day-by-day timetable grouped from `schedule_note` instead of
 * a flat table, plus a chronological list for narrow screens.
 *
 * @var array $rows
 * @var array $student
 */

/**
 * Resolve the weekday(s) a class meets.
 *
 * Handles both full names ("Wednesday") and the compact patterns schools
 * actually type into a schedule note ("MWF", "TThS", "Tue/Thu", "Sat").
 * Returns every matching day index so a MWF class appears on three columns.
 *
 * @return int[] Zero or more of 0..6 (0 = Monday)
 */
$scheduleDays = static function (string $note): array {
    $n = strtolower($note);
    if (trim($n) === '') {
        return [];
    }

    /* Long form first, so "Monday" wins before the "mon" token test. */
    $long = [
        'monday' => 0, 'tuesday' => 1, 'wednesday' => 2, 'thursday' => 3,
        'friday' => 4, 'saturday' => 5, 'sunday' => 6,
    ];
    $days = [];
    foreach ($long as $needle => $idx) {
        if (str_contains($n, $needle)) {
            $days[$idx] = true;
        }
    }
    if ($days !== []) {
        return array_keys($days);
    }

    /* Only inspect the leading run of day tokens, before any time text. */
    $head = preg_split('/\d|time|am|pm/i', $n)[0] ?? $n;
    $head = strtolower(preg_replace('/[^a-z]/', '', $head) ?? '');
    if ($head === '') {
        return [];
    }

    /*
     * Compact clusters ("MWF", "TThS", "TTF", "TH") are ambiguous without an
     * ordered scan, so match the longest day token at each position and
     * consume it. Ordered longest-first so "thu" beats "t" and "su" beats "s".
     *
     * The whole prefix must be consumed by day tokens. That single rule rejects
     * prose and placeholders — "Section 1A", "TBA" and "Online" all leave
     * unconsumed characters, so they fall through to the "no day" state instead
     * of being misread as Tuesday/Saturday.
     */
    $tokens = [
        'mond' => 0, 'mon' => 0, 'tues' => 1, 'tue' => 1, 'wednes' => 2, 'weds' => 2, 'wed' => 2,
        'thurs' => 3, 'thur' => 3, 'thu' => 3, 'th' => 3,
        'satu' => 5, 'sat' => 5, 'sund' => 6, 'sun' => 6, 'su' => 6,
        'fri' => 4,
        'm' => 0, 't' => 1, 'w' => 2, 'h' => 3, 'f' => 4, 's' => 5,
    ];

    $days = [];
    $len  = strlen($head);
    $i    = 0;
    $matched = false;
    while ($i < $len) {
        $hit = false;
        foreach ($tokens as $token => $idx) {
            if (substr_compare($head, $token, $i, strlen($token)) === 0) {
                $days[$idx] = true;
                $i    += strlen($token);
                $hit  = true;
                break;
            }
        }
        if (!$hit) {
            return []; /* unconsumed character: not a pure day cluster */
        }
        $matched = true;
    }

    return $matched ? array_keys($days) : [];
};

/** The schedule query exposes no offering id, so link via the LMS-safe route. */
$classLink = static function (array $r): string {
    if (isset($r['offering_id']) && (int) $r['offering_id'] > 0) {
        return url('/student/class-offerings/' . (int) $r['offering_id']);
    }
    return url('/student/my-classes');
};

$DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$todayIdx = (int) date('N') - 1;

/** Group the rows into weekday buckets, preserving source order. */
$byDay = array_fill(0, 7, []);
$unscheduled = [];
foreach ($rows as $r) {
    $note = (string) ($r['schedule_note'] ?? '');
    $days = $scheduleDays($note);
    if ($days === []) {
        $unscheduled[] = $r;
        continue;
    }
    foreach ($days as $d) {
        $byDay[$d][] = $r;
    }
}

/** Deterministic accent per subject so a class is recognisable at a glance. */
$subjectTone = static function (string $seed): string {
    $tones = ['', 'accent', 'info', 'success', 'warning'];
    return $tones[abs(crc32($seed)) % count($tones)];
};

$totalUnits = 0.0;
foreach ($rows as $r) {
    $totalUnits += (float) ($r['units'] ?? 0);
}
?>

<?= edu_page_header([
    'eyebrow'     => 'Academic calendar',
    'title'       => 'My schedule',
    'description' => count($rows) . ' registered class' . (count($rows) === 1 ? '' : 'es')
                  . ' · ' . e(number_format($totalUnits, 1)) . ' unit(s) this term.',
    'actions'     => '<a href="' . e(url('/student/class-offerings')) . '" class="btn btn-light btn-sm"><i class="bi bi-easel2 me-1"></i> Browse offerings</a>'
                   . '<a href="' . e(url('/student/my-classes')) . '" class="btn btn-primary btn-sm"><i class="bi bi-journal-bookmark me-1"></i> My classes</a>',
]) ?>

<?php if ($rows === []): ?>
    <?= edu_empty_state(
        "You're not enrolled in any classes yet",
        'Once you join a subject its schedule will appear here, organised by day.',
        [
            'icon'    => 'bi-calendar-x',
            'actions' => '<a href="' . e(url('/student/class-offerings')) . '" class="btn btn-primary btn-sm">Browse class offerings</a>',
        ]
    ) ?>
<?php else: ?>

    <!-- Weekly grid -->
    <div class="edu-week mb-4">
        <?php foreach ($DAYS as $i => $day):
            $items = $byDay[$i]; ?>
          <div class="edu-week__day<?= $i === $todayIdx ? ' is-today' : '' ?>">
            <div class="edu-week__dayhead">
              <?= e($day) ?><?= $i === $todayIdx ? ' · Today' : '' ?>
            </div>
            <?php if ($items === []): ?>
              <p class="small mb-0" style="color:var(--text-tertiary);opacity:.7">&mdash;</p>
            <?php else: ?>
              <?php foreach ($items as $r): ?>
                <a class="edu-week__item" href="<?= e($classLink($r)) ?>"
                   title="<?= e(trim(($r['subject_code'] ?? '') . ' ' . ($r['subject_title'] ?? ''))) ?>">
                  <span class="d-block fw-semibold"><?= e($r['subject_code'] ?? '') ?></span>
                  <span class="d-block" style="opacity:.85"><?= e($r['schedule_note'] ?: 'Time TBA') ?></span>
                  <?php if (!empty($r['room'])): ?>
                    <span class="d-block" style="opacity:.7"><i class="bi bi-geo-alt"></i> <?= e($r['room']) ?></span>
                  <?php endif; ?>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
    </div>

    <!-- Chronological list -->
    <div class="edu-card">
        <div class="edu-card__head">
            <h2 class="edu-card__title"><i class="bi bi-list-ul"></i> Class list</h2>
            <span class="edu-badge edu-badge--neutral"><?= count($rows) ?> class<?= count($rows) === 1 ? '' : 'es' ?></span>
        </div>
        <div class="edu-card__body">
            <?php foreach ($rows as $r):
                $note   = (string) ($r['schedule_note'] ?? '');
                $tone   = $subjectTone((string) ($r['subject_code'] ?? ''));
                $instructor = trim((string) ($r['instructor'] ?? '')); ?>
              <div class="edu-sched-row">
                <div class="edu-sched-row__time">
                    <?= e($note !== '' ? $note : 'Time TBA') ?>
                    <?php if (!empty($r['section_name'])): ?>
                      <div class="fw-normal small" style="color:var(--text-tertiary)">
                        Section <?= e($r['section_name']) ?>
                      </div>
                    <?php endif; ?>
                </div>
                <div class="edu-sched-row__body">
                    <div class="edu-sched-row__title">
                        <span class="edu-badge edu-badge--<?= e($tone === '' ? 'primary' : $tone) ?> me-2"><?= e($r['subject_code'] ?? '') ?></span>
                        <?= e($r['subject_title'] ?? '') ?>
                    </div>
                    <div class="edu-sched-row__meta d-flex flex-wrap gap-3 mt-1">
                        <span><i class="bi bi-person me-1"></i><?= $instructor !== '' ? e($instructor) : 'TBA' ?></span>
                        <span><i class="bi bi-geo-alt me-1"></i><?= !empty($r['room']) ? e($r['room']) : 'Room TBA' ?></span>
                        <span><i class="bi bi-clock me-1"></i><?= e(number_format((float) ($r['units'] ?? 0), 1)) ?> units</span>
                    </div>
                </div>
                <div class="flex-none">
                    <a href="<?= e($classLink($r)) ?>"
                       class="btn btn-sm btn-outline-primary" aria-label="Open <?= e($r['subject_code'] ?? '') ?>">Open</a>
                </div>
              </div>
            <?php endforeach; ?>

            <?php if ($unscheduled !== []): ?>
              <div class="edu-callout mt-3" role="note">
                <i class="bi bi-info-circle edu-callout__icon" aria-hidden="true"></i>
                <div>
                  <?= count($unscheduled) ?> class<?= count($unscheduled) === 1 ? '' : 'es' ?>
                  <?= count($unscheduled) === 1 ? 'has' : 'have' ?> no day on record yet &mdash;
                  the teacher has not published a meeting pattern.
                </div>
              </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>
