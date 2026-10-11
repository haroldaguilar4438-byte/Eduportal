<?php /** @var array $doc @var array $student @var array $school @var array $regs @var float $totalUnits */
$docPeriodLabel = $docPeriodLabel ?? null;
if ($docPeriodLabel === null) { $dp = trim($doc['semester'] ?? ($regs[0]['semester'] ?? '')); $docPeriodLabel = in_array($dp, ['1st','2nd'], true) ? ($dp . ' Semester') : ($dp !== '' ? $dp : '—'); }
$name = trim(($student['first_name']??'').' '.($student['middle_name']??'').' '.($student['last_name']??''));
$logo = is_file(ROOT_PATH.'/public_html/assets/img/school-logo.png') ? asset('img/school-logo.png') : null;
function split_sched(?string $s): array {
    $s = trim((string)$s);
    if (preg_match('/^(.*?)\s+(\d{1,2}:\d{2}.*)$/', $s, $m)) return [trim($m[1]), trim($m[2])];
    return [$s !== '' ? $s : 'TBA', '—'];
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Registration Form — <?= e($student['student_no']??'') ?></title>
<style>body{font-family:Georgia,'Times New Roman',serif;color:#111;margin:0;background:#fff}.paper{max-width:800px;margin:0 auto;padding:28px 32px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #333;padding:5px 8px;font-size:12.5px}.meta td{border:none;padding:2px 4px;font-size:13px}.head{text-align:center;margin-bottom:12px}.head h1{font-size:19px;margin:6px 0 2px;letter-spacing:.04em}.head p{margin:0;font-size:12px}.doctitle{text-align:center;font-weight:bold;text-decoration:underline;margin:14px 0}@media print{.no-print{display:none}}</style>
</head><body><div class="no-print" style="text-align:center;padding:12px"><button onclick="window.print()" style="padding:8px 22px">Print / Save PDF</button></div>
<div class="paper">
<div class="head"><?php if($logo): ?><img src="<?= e($logo) ?>" alt="logo" style="height:64px"><?php endif; ?>
<h1><?= e(strtoupper($school['name'])) ?></h1><p><?= e($school['address']) ?></p><div class="doctitle">REGISTRATION FORM</div></div>
<table class="meta"><tr><td style="width:16%"><strong>NAME</strong></td><td style="width:44%"><?= e($name) ?></td><td style="width:16%"><strong>COURSE</strong></td><td><?= e($student['program_name']??'—') ?></td></tr>
<tr><td><strong>STUDENT NO.</strong></td><td><?= e($student['student_no']??'—') ?></td><td><strong>COURSE CODE</strong></td><td><?= e($student['program_code']??'—') ?></td></tr>
<tr><td><strong>YEAR LEVEL</strong></td><td><?= e($student['level_name']??'—') ?> <?= e($student['section_name']??'') ?></td><td><strong>ACADEMIC PERIOD</strong></td><td><?= e($docPeriodLabel) ?></td></tr>
<tr><td><strong>School Year</strong></td><td><?= e($doc['school_year']??$regs[0]['school_year']??'') ?></td><td><strong>STATUS</strong></td><td><?= e(strtoupper($student['status']??'')) ?></td></tr></table>
<br><table><thead><tr><th>SUBJECT CODE</th><th>SUBJECTS</th><th>UNITS</th><th>TEACHER</th><th>DAY</th><th>TIME</th></tr></thead><tbody>
<?php foreach($regs as $r): [$day,$time]=split_sched($r['schedule_note']??''); ?><tr><td><?= e($r['subject_code']) ?></td><td><?= e($r['subject_title']) ?></td><td style="text-align:center"><?= e(number_format((float)$r['units'],1)) ?></td><td><?= e(trim($r['instructor']??'')!==''?$r['instructor']:'TBA') ?></td><td style="text-align:center"><?= e($day) ?></td><td style="text-align:center"><?= e($time) ?><?= !empty($r['room'])?' ('.e($r['room']).')':'' ?></td></tr><?php endforeach; ?>
<tr><td colspan="2" style="text-align:right"><strong>TOTAL</strong></td><td style="text-align:center"><strong><?= e(number_format($totalUnits,1)) ?></strong></td><td colspan="3"></td></tr>
</tbody></table>
<p style="font-size:12px"><strong>NOTE:</strong> Additional 100.00 penalty for LATE PAYMENT. Enrollment verified by Accounting &amp; Registrar.</p>
<table class="meta"><tr><td><strong>DATE:</strong> <?= e(date('M d, Y')) ?></td><td style="text-align:center">______________________<br>STUDENT SIGNATURE</td><td style="text-align:center">______________________<br>REGISTRAR</td></tr></table>
<p style="font-size:11px;color:#444">Doc <?= e($doc['doc_no']) ?> · Released <?= e($doc['released_at']??'') ?> · Official enrollment data — not editable.</p>
</div></body></html>
