<?php
/**
 * Official College Enrollment Form (printable, generated from DB records).
 * Reference: DATAMEX COLLEGE OF SAINT ADELINE paper form (layout/structure only).
 * Every value below comes from enrollment_applications + students + users +
 * guardians + classroom_members + student_charges + system_settings.
 * Nothing is hard-coded per student; nothing is editable by students here.
 *
 * @var array $app @var array $school @var array|null $guardian
 * @var array $subjects @var float $totalUnits @var array $charges
 * @var array $payments @var float $balance @var array $schedule
 * @var bool $isReturning @var array $yearLevel
 */
$fullName = trim(($app['first_name'] ?? '') . ' ' . ($app['middle_name'] ?? '') . ' ' . ($app['last_name'] ?? '') . ' ' . ($app['suffix'] ?? ''));
$printedName = trim(($app['first_name'] ?? '') . ' ' . ($app['middle_name'] ?? '') . ' ' . ($app['last_name'] ?? ''));
$guardianName = $guardian ? trim(($guardian['first_name'] ?? '') . ' ' . ($guardian['middle_name'] ?? '') . ' ' . ($guardian['last_name'] ?? '')) : '';
$sy = (string) ($app['school_year'] ?? '');
$sem = (string) ($app['semester'] ?? ($yearLevel['semester'] ?? ''));
$yr = (string) ($yearLevel['year'] ?? '');
$perLabel = (string) ($yearLevel['period_label'] ?? '');
if ($perLabel === '') { $pp = trim($app['period_code'] ?? ''); if ($pp === '') $pp = $sem; $perLabel = in_array($pp, ['1st','2nd'], true) ? ($pp . ' Semester') : ($pp !== '' ? $pp : '—'); }
$isCollegeForm = $yr !== '';
$isNew = !$isReturning;
$logo = is_file(ROOT_PATH . '/public_html/assets/img/school-logo.png') ? asset('img/school-logo.png') : null;
$photoFs = !empty($app['avatar_path']) ? (ROOT_PATH . '/public_html/' . ltrim((string) $app['avatar_path'], '/')) : '';
$photoUrl = (!empty($app['avatar_path']) && is_file($photoFs)) ? url((string) $app['avatar_path']) : null;
$rules = (string) ($school['scholarship_rules'] ?? '');
$notes = (string) ($school['form_notes'] ?? '');
$box = static function (bool $on): string { return $on ? '&#9746;' : '&#9744;'; };
$val = static function ($v): string { $v = trim((string) ($v ?? '')); return $v !== '' ? $v : ''; };
$money = static function ($v): string { return $v === null ? '' : 'Php ' . number_format((float) $v, 2); };
$due = static function ($v): string { return $v ? date('M d, Y', strtotime((string) $v)) : ''; };
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Enrollment Form — <?= e($app['student_no'] ?? $app['application_no']) ?> — <?= e($sy) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 0; background: #fff; font-size: 12px; }
  .paper { max-width: 820px; margin: 0 auto; padding: 26px 30px 34px; }
  .toolbar { text-align: center; padding: 12px; border-bottom: 1px solid #e2e8f0; margin-bottom: 10px; }
  .toolbar button { padding: 8px 26px; font-size: 13px; cursor: pointer; }
  .head { display: flex; align-items: center; gap: 14px; border-bottom: 2px solid #1e3a5f; padding-bottom: 10px; }
  .head img { height: 62px; width: auto; }
  .head .school { flex: 1; text-align: center; }
  .head .school h1 { font-size: 18px; margin: 0; letter-spacing: .05em; color: #1e3a5f; }
  .head .school p { margin: 2px 0 0; font-size: 11px; color: #334155; }
  .doctitle { text-align: center; margin: 12px 0 10px; }
  .doctitle h2 { font-size: 15px; margin: 0; letter-spacing: .12em; }
  .doctitle p { margin: 2px 0 0; font-size: 12px; font-weight: bold; }
  .grid-top { display: flex; gap: 10px; margin-bottom: 10px; }
  .idbox { border: 1px solid #1e3a5f; flex: 1; }
  .idbox .bar { background: #1e3a5f; color: #fff; font-size: 10px; font-weight: bold; padding: 3px 8px; letter-spacing: .06em; }
  .idbox .cells { display: flex; }
  .idbox .cell { flex: 1; padding: 6px 8px; border-right: 1px solid #94a3b8; font-size: 11px; }
  .idbox .cell:last-child { border-right: none; }
  .idbox .lbl { font-size: 9px; color: #475569; text-transform: uppercase; letter-spacing: .05em; }
  .idbox .v { font-weight: bold; font-size: 12px; margin-top: 2px; }
  .photo { width: 110px; border: 1px solid #1e3a5f; text-align: center; font-size: 11px; color: #475569; }
  .photo img { width: 100%; height: 110px; object-fit: cover; display: block; }
  .photo .ph { height: 110px; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: bold; }
  .section { border: 1px solid #1e3a5f; margin-bottom: 10px; }
  .section .bar { background: #1e3a5f; color: #fff; font-size: 10.5px; font-weight: bold; padding: 4px 10px; letter-spacing: .08em; }
  table.fields { width: 100%; border-collapse: collapse; }
  table.fields td { border: 1px solid #94a3b8; padding: 5px 8px; vertical-align: top; }
  table.fields .lbl { font-size: 9px; color: #475569; text-transform: uppercase; letter-spacing: .04em; }
  table.fields .v { font-size: 12px; font-weight: 600; margin-top: 1px; min-height: 15px; }
  table.subj { width: 100%; border-collapse: collapse; }
  table.subj th, table.subj td { border: 1px solid #94a3b8; padding: 4px 6px; font-size: 11px; }
  table.subj th { background: #f1f5f9; font-size: 10px; letter-spacing: .04em; }
  .rules { padding: 8px 12px; font-size: 11px; line-height: 1.55; white-space: pre-line; }
  table.pay { width: 100%; border-collapse: collapse; }
  table.pay th, table.pay td { border: 1px solid #94a3b8; padding: 5px 8px; font-size: 11.5px; }
  table.pay th { background: #f1f5f9; font-size: 10.5px; }
  .fees { padding: 8px 12px; font-size: 11px; line-height: 1.6; }
  .fees li { margin-bottom: 2px; }
  .signs { display: flex; gap: 10px; margin-top: 22px; }
  .sign { flex: 1; text-align: center; font-size: 11px; }
  .sign .line { border-top: 1px solid #111; margin: 34px 18px 4px; padding-top: 4px; font-weight: bold; letter-spacing: .06em; }
  .meta { margin-top: 12px; font-size: 10px; color: #64748b; border-top: 1px dashed #cbd5e1; padding-top: 6px; }
  @media print { .toolbar { display: none; } .paper { padding: 0; max-width: none; } body { font-size: 11px; } }
</style>
</head>
<body>
<div class="toolbar no-print">
  <button onclick="window.print()">Print / Save as PDF</button>
  <span style="font-size:11px;color:#64748b;margin-left:10px;">Official copy — generated <?= e(date('M d, Y g:i A')) ?> from enrollment <?= e($app['application_no'] ?? '') ?></span>
</div>
<div class="paper">
  <div class="head">
    <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="School logo"><?php endif; ?>
    <div class="school">
      <h1><?= e(strtoupper((string) ($school['name'] ?? 'DATAMEX COLLEGE OF SAINT ADELINE'))) ?></h1>
      <p><?= e((string) ($school['address'] ?? '')) ?><?= !empty($school['contact']) ? ' &middot; ' . e((string) $school['contact']) : '' ?></p>
    </div>
  </div>
  <div class="doctitle">
    <h2>ENROLLMENT FORM</h2>
    <p>SCHOOL YEAR <?= e($sy !== '' ? $sy : '____–____') ?></p>
  </div>

  <div class="grid-top">
    <div class="idbox">
      <div class="bar">STUDENT TYPE / ENROLLMENT</div>
      <div class="cells">
        <div class="cell">
          <div class="lbl">Enrollment Type</div>
          <div class="v"><?= $box($isNew) ?> New &nbsp; <?= $box(!$isNew) ?> Old</div>
          <div class="lbl" style="margin-top:6px;">Student Number (permanent)</div>
          <div class="v" style="font-family:monospace;letter-spacing:.06em;"><?= e($val($app['student_no']) !== '' ? $val($app['student_no']) : 'PENDING — assigned on approval') ?></div>
        </div>
        <div class="cell">
          <div class="lbl">Year Level (auto-selected)</div>
          <div class="v"><?= $box($yr === '1st Year') ?> 1st Year &nbsp; <?= $box($yr === '2nd Year') ?> 2nd Year</div>
          <div class="v" style="margin-top:2px;"><?= $box($yr === '3rd Year') ?> 3rd Year &nbsp; <?= $box($yr === '4th Year') ?> 4th Year</div>
          <?php if ($yr === ''): ?><div style="font-size:10px;color:#475569;"><?= e($app['level_name'] ?? '') ?></div><?php endif; ?>
        </div>
        <div class="cell">
          <?php if ($isCollegeForm): ?>
          <div class="lbl">Semester (auto-selected)</div>
          <div class="v"><?= $box($sem === '1st') ?> 1st Sem</div>
          <div class="v" style="margin-top:2px;"><?= $box($sem === '2nd') ?> 2nd Sem</div>
          <?php if ($sem === 'Summer'): ?><div class="v" style="margin-top:2px;"><?= $box(true) ?> Summer</div><?php endif; ?>
          <?php else: ?>
          <div class="lbl">Academic Period (auto-selected)</div>
          <div class="v"><?= e($perLabel) ?></div>
          <div style="font-size:10px;color:#475569;"><?= e($app['level_name'] ?? '') ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="photo">
      <?php if ($photoUrl): ?><img src="<?= e($photoUrl) ?>" alt="2x2 photo"><?php else: ?><div class="ph">2x2</div><?php endif; ?>
      <div style="border-top:1px solid #1e3a5f;padding:2px;">ID PHOTO</div>
    </div>
  </div>

  <div class="section">
    <div class="bar">PERSONAL INFORMATION (from student profile at time of enrollment)</div>
    <table class="fields">
      <tr>
        <td style="width:34%"><div class="lbl">First Name</div><div class="v"><?= e($val($app['first_name'])) ?></div></td>
        <td style="width:33%"><div class="lbl">Middle Name</div><div class="v"><?= e($val($app['middle_name'])) ?></div></td>
        <td><div class="lbl">Last Name</div><div class="v"><?= e($val($app['last_name'])) ?></div></td>
      </tr>
      <tr>
        <td><div class="lbl">Extension Name</div><div class="v"><?= e($val($app['suffix'])) ?></div></td>
        <td><div class="lbl">Gender</div><div class="v"><?= $box(strtolower((string)($app['gender'] ?? '')) === 'male') ?> Male &nbsp; <?= $box(strtolower((string)($app['gender'] ?? '')) === 'female') ?> Female<?= !empty($app['gender']) && !in_array(strtolower((string)$app['gender']), ['male','female'], true) ? ' (' . e((string)$app['gender']) . ')' : '' ?></div></td>
        <td><div class="lbl">Birthday</div><div class="v"><?= e(!empty($app['date_of_birth']) ? date('M d, Y', strtotime((string)$app['date_of_birth'])) : '') ?></div></td>
      </tr>
      <tr>
        <td colspan="1"><div class="lbl">Home Address</div><div class="v"><?= e($val($app['address_line1'])) ?></div></td>
        <td><div class="lbl">City</div><div class="v"><?= e($val($app['address_city'])) ?></div></td>
        <td><div class="lbl">Barangay / Province · ZIP</div><div class="v"><?= e(trim($val($app['address_province']) . ($app['address_zip'] ? ' · ' . $app['address_zip'] : ''))) ?></div></td>
      </tr>
      <tr>
        <td><div class="lbl">Mobile Number</div><div class="v"><?= e($val($app['phone'])) ?></div></td>
        <td><div class="lbl">Name of Guardian</div><div class="v"><?= e($val($guardianName)) ?></div></td>
        <td><div class="lbl">Guardian Contact Number</div><div class="v"><?= e($val($guardian['contact_no'] ?? '')) ?></div></td>
      </tr>
    </table>
  </div>

  <div class="section">
    <div class="bar">OTHER INFORMATION</div>
    <table class="fields">
      <tr>
        <td style="width:40%"><div class="lbl">Last School Attended</div><div class="v"><?= e($val($app['previous_school'])) ?></div></td>
        <td style="width:30%"><div class="lbl">Course / Strand</div><div class="v"><?= e($val($app['program_name']) ?: $val($app['last_grade_completed'])) ?></div></td>
        <td><div class="lbl">School Address (campus)</div><div class="v"><?= e(trim($val($app['campus_name']) . ($app['campus_address'] ? ' — ' . $app['campus_address'] : ''))) ?></div></td>
      </tr>
    </table>
  </div>

  <div class="section">
    <div class="bar">ENROLLED SUBJECTS — <?= e($sy) ?> &middot; <?= e($perLabel) ?> (from class offerings)</div>
    <?php if ($subjects === []): ?>
      <div class="rules">No subjects linked yet for this term. Subjects appear here once the student joins official class offerings (invite code) for <?= e($sy) ?> <?= e($perLabel) ?>.</div>
    <?php else: ?>
      <table class="subj">
        <thead><tr><th style="width:16%">Code</th><th>Subject</th><th style="width:8%">Units</th><th style="width:20%">Teacher</th><th style="width:22%">Schedule</th></tr></thead>
        <tbody>
          <?php foreach ($subjects as $s): ?>
          <tr>
            <td><strong><?= e($s['subject_code']) ?></strong></td>
            <td><?= e($s['subject_title']) ?><?= !empty($s['section_name']) ? ' <span style="color:#475569">(' . e($s['section_name']) . ')</span>' : '' ?></td>
            <td style="text-align:center"><?= e(number_format((float) $s['units'], 1)) ?></td>
            <td><?= e(trim((string)($s['instructor'] ?? '')) !== '' ? $s['instructor'] : 'TBA') ?></td>
            <td><?= e(trim((string)($s['schedule_note'] ?? '')) !== '' ? $s['schedule_note'] : 'TBA') ?><?= !empty($s['room']) ? ' · Rm ' . e($s['room']) : '' ?></td>
          </tr>
          <?php endforeach; ?>
          <tr><td colspan="2" style="text-align:right"><strong>TOTAL UNITS</strong></td><td style="text-align:center"><strong><?= e(number_format($totalUnits, 1)) ?></strong></td><td colspan="2"></td></tr>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="bar">SCHOLARSHIP RULES AND GUIDELINES (institutional — configurable by Admin)</div>
    <div class="rules"><?= e($rules !== '' ? $rules : 'Scholarship rules on file with the Registrar.') ?></div>
  </div>

  <div class="section">
    <div class="bar">PAYMENT SCHEDULE (from Accounting — amounts never invented)</div>
    <table class="pay">
      <thead><tr><th style="width:32%">Mode of Payment</th><th style="width:30%">Due Date</th><th>Amount</th></tr></thead>
      <tbody>
        <?php foreach ($schedule as $row): ?>
        <tr>
          <td><strong><?= e($row['label']) ?></strong><?php if (!empty($row['source'])): ?><div style="font-size:10px;color:#475569;"><?= e($row['source']) ?></div><?php endif; ?></td>
          <td><?= e($due($row['due'])) ?></td>
          <td><?= $row['amount'] === null ? '<span style="color:#64748b">Per Accounting assessment</span>' : e($money($row['amount'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="fees">
      <strong>NOTE:</strong>
      <div style="white-space:pre-line;"><?= e($notes) ?></div>
      <?php if ($charges !== []): ?>
        <div style="margin-top:6px;"><strong>Miscellaneous / assessed fees on record:</strong></div>
        <ul style="margin:4px 0 0;padding-left:18px;">
          <?php foreach ($charges as $c): ?>
            <li><?= e(($c['fee_name'] ?? '') !== '' ? $c['fee_name'] . ' — ' : '') ?><?= e($c['description'] ?? '') ?> — <strong><?= e($money($c['amount'])) ?></strong><?= !empty($c['charge_date']) ? ' (' . e(date('M d, Y', strtotime((string)$c['charge_date']))) . ')' : '' ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($balance > 0.01): ?><div style="margin-top:4px;">Outstanding balance on record: <strong><?= e($money($balance)) ?></strong></div><?php endif; ?>
    </div>
  </div>

  <div class="signs">
    <div class="sign"><div class="line">REGISTRAR</div><div>Academic processing</div></div>
    <div class="sign"><div class="line">ACCOUNTING</div><div>Financial processing</div></div>
    <div class="sign"><div class="line"><?= e(strtoupper($printedName !== '' ? $printedName : 'STUDENT')) ?></div><div>SIGNATURE OVER PRINTED NAME</div></div>
  </div>

  <div class="meta">
    Form for enrollment <?= e($app['application_no'] ?? '') ?> &middot; <?= e($app['level_name'] ?? '') ?><?= !empty($app['program_code']) ? ' · ' . e($app['program_code']) : '' ?> &middot; <?= e($perLabel) ?> &middot; Status: <?= e(strtoupper((string)($app['status'] ?? ''))) ?>.
    Student Number <?= e($app['student_no'] ?? '—') ?> is permanent and reused every term. Previous term forms remain in enrollment history.
  </div>
</div>
</body>
</html>
