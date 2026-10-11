<?php /** @var array $doc @var array $student @var array $school @var array $grades @var float $totalUnits @var float|null $gwa @var int $failed */
$incomplete = 0; foreach ($grades as $gg) { if (!empty($gg['inc'])) $incomplete++; }
$docPeriodLabel = $docPeriodLabel ?? null;
if ($docPeriodLabel === null) { $dp = trim($doc['semester'] ?? ($grades[0]['semester'] ?? '')); $docPeriodLabel = in_array($dp, ['1st','2nd'], true) ? ($dp . ' Semester') : ($dp !== '' ? $dp : '—'); }
$name = trim(($student['first_name']??'').' '.($student['middle_name']??'').' '.($student['last_name']??''));
$logo = is_file(ROOT_PATH.'/public_html/assets/img/school-logo.png') ? asset('img/school-logo.png') : null;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Grade Slip — <?= e($student['student_no']??'') ?></title>
<style>body{font-family:Georgia,'Times New Roman',serif;color:#111;margin:0;background:#fff}.paper{max-width:760px;margin:0 auto;padding:28px 32px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #333;padding:5px 8px;font-size:13px}.meta td{border:none;padding:2px 4px;font-size:13px}.head{text-align:center;margin-bottom:12px}.head h1{font-size:19px;margin:6px 0 2px;letter-spacing:.04em}.head p{margin:0;font-size:12px}.doctitle{text-align:center;font-weight:bold;text-decoration:underline;margin:14px 0}@media print{.no-print{display:none}}</style>
</head><body><div class="no-print" style="text-align:center;padding:12px"><button onclick="window.print()" style="padding:8px 22px">Print / Save PDF</button></div>
<div class="paper">
<div class="head"><?php if($logo): ?><img src="<?= e($logo) ?>" alt="logo" style="height:64px"><?php endif; ?>
<h1><?= e(strtoupper($school['name'])) ?></h1><p><?= e($school['address']) ?></p><div class="doctitle">GRADE SLIP</div></div>
<table class="meta"><tr><td style="width:16%"><strong>NAME</strong></td><td style="width:44%"><?= e($name) ?></td><td style="width:18%"><strong>COURSE</strong></td><td><?= e($student['program_name']??'—') ?></td></tr>
<tr><td><strong>STUDENT NO.</strong></td><td><?= e($student['student_no']??'—') ?></td><td><strong>COURSE CODE</strong></td><td><?= e($student['program_code']??'—') ?></td></tr>
<tr><td><strong>YEAR LEVEL</strong></td><td><?= e($student['level_name']??'—') ?></td><td><strong>ACADEMIC PERIOD</strong></td><td><?= e($docPeriodLabel) ?></td></tr>
<tr><td><strong>School Year</strong></td><td><?= e($doc['school_year']??$grades[0]['school_year']??'') ?></td><td><strong>SECTION</strong></td><td><?= e($student['section_name']??'—') ?></td></tr></table>
<br><table><thead><tr><th>SUBJECT CODE</th><th>SUBJECTS</th><th>UNITS</th><th>GRADES</th><th>REMARKS</th></tr></thead><tbody>
<?php foreach($grades as $g): $gInc=!empty($g['inc']); ?><tr><td><?= e($g['subject_code']) ?></td><td><?= e($g['subject_title']) ?></td><td style="text-align:center"><?= e(number_format((float)$g['units'],1)) ?></td><td style="text-align:center"><?= $gInc?'INC':e(number_format((float)$g['grade'],2)) ?></td><td style="text-align:center"><?= $gInc?'INCOMPLETE':e($g['scale_remarks'] ?: ($g['passing']?'PASSED':'FAILED')) ?></td></tr><?php endforeach; ?>
<tr><td colspan="2" style="text-align:right"><strong>TOTAL / GWA</strong></td><td style="text-align:center"><strong><?= e(number_format($totalUnits,1)) ?></strong></td><td style="text-align:center"><strong><?= $gwa!==null?e(number_format($gwa,2)):'—' ?></strong></td><td style="text-align:center"><strong><?= $failed>0 ? $failed.' FAILED' : ($incomplete>0 ? $incomplete.' INCOMPLETE' : 'PASSED') ?></strong></td></tr>
</tbody></table>
<p style="font-size:12px">Academic standing: <strong><?= $failed>0 ? 'With Deficiency' : ($incomplete>0 ? 'Incomplete — completion required (e.g. summer)' : 'Good Standing') ?></strong> · GWA computed from finalized official grades only (INC excluded).</p>
<table class="meta"><tr><td><strong>DATE:</strong> <?= e(date('M d, Y')) ?></td><td style="text-align:right"><strong>RELEASED BY</strong><br><br>______________________<br>REGISTRAR</td></tr></table>
<p style="font-size:11px;color:#444">Doc <?= e($doc['doc_no']) ?> · Released <?= e($doc['released_at']??'') ?> · Verify at the Registrar's Office. Unauthorized alteration is a major offense.</p>
</div></body></html>
