<?php /** @var array $doc @var array $student @var array $school @var array $grades @var array $scales */
$docPeriodLabel = $docPeriodLabel ?? null;
if ($docPeriodLabel === null) { $dp = trim($doc['semester'] ?? ($grades[0]['semester'] ?? '')); $docPeriodLabel = in_array($dp, ['1st','2nd'], true) ? ($dp . ' Semester') : ($dp !== '' ? $dp : '—'); }
$name = trim(($student['first_name']??'').' '.($student['middle_name']??'').' '.($student['last_name']??''));
$logo = is_file(ROOT_PATH.'/public_html/assets/img/school-logo.png') ? asset('img/school-logo.png') : null;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Grade Equivalency — <?= e($student['student_no']??'') ?></title>
<style>body{font-family:Georgia,'Times New Roman',serif;color:#111;margin:0;background:#fff}.paper{max-width:760px;margin:0 auto;padding:28px 32px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #333;padding:5px 8px;font-size:13px}.meta td{border:none;padding:2px 4px;font-size:13px}.head{text-align:center;margin-bottom:12px}.head h1{font-size:19px;margin:6px 0 2px}.head p{margin:0;font-size:12px}.doctitle{text-align:center;font-weight:bold;text-decoration:underline;margin:14px 0}@media print{.no-print{display:none}}</style>
</head><body><div class="no-print" style="text-align:center;padding:12px"><button onclick="window.print()" style="padding:8px 22px">Print / Save PDF</button></div>
<div class="paper">
<div class="head"><?php if($logo): ?><img src="<?= e($logo) ?>" alt="logo" style="height:64px"><?php endif; ?>
<h1><?= e(strtoupper($school['name'])) ?></h1><p><?= e($school['address']) ?></p><div class="doctitle">GRADE EQUIVALENCY</div></div>
<table class="meta"><tr><td style="width:18%"><strong>NAME</strong></td><td style="width:42%"><?= e($name) ?></td><td style="width:18%"><strong>COURSE CODE</strong></td><td><?= e($student['program_code']??'—') ?></td></tr>
<tr><td><strong>STUDENT NO.</strong></td><td><?= e($student['student_no']??'—') ?></td><td><strong>ACADEMIC PERIOD</strong></td><td><?= e($docPeriodLabel) ?></td></tr>
<tr><td><strong>YEAR LEVEL</strong></td><td><?= e($student['level_name']??'—') ?></td><td><strong>School Year</strong></td><td><?= e($doc['school_year']??$grades[0]['school_year']??'') ?></td></tr></table>
<br><table><thead><tr><th>SUBJECT CODE</th><th>SUBJECTS</th><th>NUMERICAL GRADE</th><th>EQUIVALENT</th><th>REMARKS</th></tr></thead><tbody>
<?php foreach($grades as $g): $gInc=!empty($g['inc']); ?><tr><td><?= e($g['subject_code']) ?></td><td><?= e($g['subject_title']) ?></td><td style="text-align:center"><?= $gInc?'INC':e(number_format((float)$g['grade'],2)) ?></td><td style="text-align:center"><?= $gInc?'Incomplete':e($g['equivalent']??'—') ?></td><td style="text-align:center"><?= $gInc?'INCOMPLETE':e($g['scale_remarks']??'') ?></td></tr><?php endforeach; ?>
</tbody></table>
<br><div class="doctitle" style="text-decoration:none">OFFICIAL GRADING SCALE</div>
<table><thead><tr><th>RANGE</th><th>EQUIVALENT</th><th>REMARKS</th></tr></thead><tbody>
<?php foreach($scales as $s): ?><tr><td style="text-align:center"><?= e(number_format((float)$s['min_grade'],2)) ?> – <?= e(number_format((float)$s['max_grade'],2)) ?></td><td style="text-align:center"><?= e($s['equivalent']) ?></td><td style="text-align:center"><?= e($s['remarks']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<table class="meta" style="margin-top:16px"><tr><td><strong>DATE:</strong> <?= e(date('M d, Y')) ?></td><td style="text-align:right">______________________<br>REGISTRAR</td></tr></table>
<p style="font-size:11px;color:#444">Doc <?= e($doc['doc_no']) ?> · Based on finalized official grades · Scale set by the Registrar.</p>
</div></body></html>
