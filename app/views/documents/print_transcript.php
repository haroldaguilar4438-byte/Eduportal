<?php /** @var array $doc @var array $student @var array $school @var array $grades @var float|null $gwa */
$name = trim(($student['first_name']??'').' '.($student['middle_name']??'').' '.($student['last_name']??''));
$logo = is_file(ROOT_PATH.'/public_html/assets/img/school-logo.png') ? asset('img/school-logo.png') : null;
$byTerm = [];
foreach($grades as $g) { $tp = trim($g['eff_period'] ?? ($g['semester'] ?? '')); if (in_array($tp, ['1st','2nd'], true)) $tp .= ' Semester'; $byTerm[$g['school_year'].' · '.($tp !== '' ? $tp : '—')][] = $g; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Transcript of Records — <?= e($student['student_no']??'') ?></title>
<style>body{font-family:Georgia,'Times New Roman',serif;color:#111;margin:0;background:#fff}.paper{max-width:760px;margin:0 auto;padding:28px 32px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #333;padding:5px 8px;font-size:13px}.meta td{border:none;padding:2px 4px;font-size:13px}.head{text-align:center;margin-bottom:12px}.head h1{font-size:19px;margin:6px 0 2px}.head p{margin:0;font-size:12px}.doctitle{text-align:center;font-weight:bold;text-decoration:underline;margin:14px 0}@media print{.no-print{display:none}}</style>
</head><body><div class="no-print" style="text-align:center;padding:12px"><button onclick="window.print()" style="padding:8px 22px">Print / Save PDF</button></div>
<div class="paper">
<div class="head"><?php if($logo): ?><img src="<?= e($logo) ?>" alt="logo" style="height:64px"><?php endif; ?>
<h1><?= e(strtoupper($school['name'])) ?></h1><p><?= e($school['address']) ?></p><div class="doctitle">TRANSCRIPT OF RECORDS</div></div>
<table class="meta"><tr><td style="width:18%"><strong>NAME</strong></td><td style="width:42%"><?= e($name) ?></td><td style="width:18%"><strong>COURSE</strong></td><td><?= e($student['program_name']??'—') ?> (<?= e($student['program_code']??'—') ?>)</td></tr>
<tr><td><strong>STUDENT NO.</strong></td><td><?= e($student['student_no']??'—') ?></td><td><strong>YEAR LEVEL</strong></td><td><?= e($student['level_name']??'—') ?></td></tr></table>
<?php if($grades===[]): ?><p>No finalized academic records yet.</p><?php endif; ?>
<?php foreach($byTerm as $term=>$rows): ?><p style="font-weight:bold;margin:14px 0 4px"><?= e($term) ?></p>
<table><thead><tr><th>CODE</th><th>SUBJECTS</th><th>UNITS</th><th>GRADE</th><th>REMARKS</th></tr></thead><tbody>
<?php foreach($rows as $g): $gInc=!empty($g['inc']); ?><tr><td><?= e($g['subject_code']) ?></td><td><?= e($g['subject_title']) ?></td><td style="text-align:center"><?= e(number_format((float)$g['units'],1)) ?></td><td style="text-align:center"><?= $gInc?'INC':e(number_format((float)$g['grade'],2)) ?></td><td style="text-align:center"><?= $gInc?'INCOMPLETE':e($g['scale_remarks']??'') ?></td></tr><?php endforeach; ?>
</tbody></table><?php endforeach; ?>
<p style="font-size:13px">Cumulative GWA (finalized): <strong><?= $gwa!==null?e(number_format($gwa,2)):'—' ?></strong></p>
<table class="meta"><tr><td><strong>DATE:</strong> <?= e(date('M d, Y')) ?></td><td style="text-align:right">______________________<br>REGISTRAR</td></tr></table>
<p style="font-size:11px;color:#444">Doc <?= e($doc['doc_no']) ?> · System-generated from finalized official records.</p>
</div></body></html>
