<?php /** @var array $doc @var array $student @var array $school @var array $grades @var float|null $gwa @var array $regs */
$docPeriodLabel = $docPeriodLabel ?? null;
if ($docPeriodLabel === null) { $dp = trim($doc['semester'] ?? ''); $docPeriodLabel = in_array($dp, ['1st','2nd'], true) ? ($dp . ' Semester') : ($dp !== '' ? $dp : ''); }
$name = trim(($student['first_name']??'').' '.($student['middle_name']??'').' '.($student['last_name']??''));
$logo = is_file(ROOT_PATH.'/public_html/assets/img/school-logo.png') ? asset('img/school-logo.png') : null;
$titles = ['enrollment_cert'=>'CERTIFICATE OF ENROLLMENT','grades_cert'=>'CERTIFICATE OF GRADES','history'=>'ACADEMIC HISTORY','other'=>'CERTIFICATION'];
$heading = $titles[$doc['doc_type']] ?? 'CERTIFICATION';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= e($heading) ?> — <?= e($student['student_no']??'') ?></title>
<style>body{font-family:Georgia,'Times New Roman',serif;color:#111;margin:0;background:#fff}.paper{max-width:720px;margin:0 auto;padding:36px 40px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #333;padding:5px 8px;font-size:13px}.head{text-align:center;margin-bottom:12px}.head h1{font-size:19px;margin:6px 0 2px}.head p{margin:0;font-size:12px}.doctitle{text-align:center;font-weight:bold;text-decoration:underline;margin:16px 0} p.body{font-size:14px;line-height:1.8;text-align:justify}@media print{.no-print{display:none}}</style>
</head><body><div class="no-print" style="text-align:center;padding:12px"><button onclick="window.print()" style="padding:8px 22px">Print / Save PDF</button></div>
<div class="paper">
<div class="head"><?php if($logo): ?><img src="<?= e($logo) ?>" alt="logo" style="height:64px"><?php endif; ?>
<h1><?= e(strtoupper($school['name'])) ?></h1><p><?= e($school['address']) ?></p><div class="doctitle"><?= e($heading) ?></div></div>
<p class="body">This is to certify that <strong><?= e($name) ?></strong>, Student No. <strong><?= e($student['student_no']??'—') ?></strong>,
<?php if($doc['doc_type']==='enrollment_cert'): ?>
is officially <strong>enrolled</strong> in <strong><?= e($student['program_name']??'—') ?> (<?= e($student['program_code']??'—') ?>)</strong>, <?= e($student['level_name']??'') ?>, for S.Y. <strong><?= e($doc['school_year']??'') ?></strong><?= $docPeriodLabel !== '' ? ', <strong>'.e($docPeriodLabel).'</strong>' : '' ?>, with <strong><?= count($regs) ?></strong> subject(s) registered.
<?php elseif($doc['doc_type']==='grades_cert'): ?>
has the following <strong>finalized official grades</strong> for S.Y. <strong><?= e($doc['school_year']??'') ?></strong><?= $docPeriodLabel !== '' ? ', <strong>'.e($docPeriodLabel).'</strong>' : '' ?><?php if($gwa!==null): ?> with a term GWA of <strong><?= e(number_format($gwa,2)) ?></strong><?php endif; ?>.
<?php elseif($doc['doc_type']==='history'): ?>
has the academic record summarized below, drawn from finalized official records across school years.
<?php else: ?>
is a bona fide student of this institution<?= $student['program_name']?' under <strong>'.e($student['program_name']).'</strong>':'' ?>. This certification is issued upon request for <strong><?= e($doc['purpose']??'official purposes') ?></strong>.
<?php endif; ?>
</p>
<?php if(in_array($doc['doc_type'],['grades_cert','history'],true) && $grades!==[]): ?>
<table><thead><tr><th>CODE</th><th>SUBJECT</th><th>TERM</th><th>GRADE</th><th>REMARKS</th></tr></thead><tbody>
<?php foreach($grades as $g): $gInc=!empty($g['inc']); ?><tr><td><?= e($g['subject_code']) ?></td><td><?= e($g['subject_title']) ?></td><td><?= e($g['school_year']) ?> <?= e($g['period_label'] ?? ($g['semester'] ?? '')) ?></td><td style="text-align:center"><?= $gInc?'INC':e(number_format((float)$g['grade'],2)) ?></td><td style="text-align:center"><?= $gInc?'INCOMPLETE':e($g['scale_remarks']??'') ?></td></tr><?php endforeach; ?>
</tbody></table><br><?php endif; ?>
<?php if($doc['doc_type']==='enrollment_cert' && $regs!==[]): ?>
<table><thead><tr><th>CODE</th><th>SUBJECT</th><th>SECTION</th></tr></thead><tbody>
<?php foreach($regs as $r): ?><tr><td><?= e($r['subject_code']) ?></td><td><?= e($r['subject_title']) ?></td><td><?= e($r['section_name']) ?></td></tr><?php endforeach; ?>
</tbody></table><br><?php endif; ?>
<p class="body">Issued this <strong><?= e(date('jS \d\a\y \o\f F Y')) ?></strong> at <?= e($school['name']) ?>.</p>
<table style="margin-top:28px"><tr><td style="border:none;text-align:right">______________________<br>REGISTRAR</td></tr></table>
<p style="font-size:11px;color:#444">Doc <?= e($doc['doc_no']) ?> · Released <?= e($doc['released_at']??'') ?> · System-generated from official records.</p>
</div></body></html>
