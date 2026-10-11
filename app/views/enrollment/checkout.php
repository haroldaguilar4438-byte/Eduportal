<?php
/**
 * Checkout — payment experience (spec §12).
 *
 * Non-destructive: $app, $step, $plan, $summary, $activePlan, $due, $intentId
 * and $methods are used exactly as before. Every form keeps its action URL,
 * its CSRF token and its field names (plan_type, payment_method). Both polling
 * loops (payment-status) and the plan/method card scripts are preserved
 * verbatim, so PayRex behaviour and the webhook flow are untouched.
 *
 * @var array      $app
 * @var string     $step
 * @var array|null $plan
 * @var array|null $summary
 * @var array|null $activePlan
 * @var array|null $due
 * @var string|null $intentId
 */
$ref = $app['transaction_ref'] ?? $app['application_no'] ?? 'ENR-' . $app['id'];
$step = $step ?? 'pay';
$plan = $plan ?? null;
$summary = $summary ?? null;
$activePlan = $activePlan ?? null;
$due = $due ?? null;
$intentId = $app['payrex_payment_intent_id'] ?? $intentId ?? null;

/* Spec §12 lists Card as a method alongside GCash / Maya / QR Ph. Card was not
   in the original list, so it is added as a fourth PayRex channel. */
$methods = [
    'gcash' => ['label' => 'GCash', 'icon' => 'bi-phone',        'color' => '#007DFF', 'desc' => 'E-wallet'],
    'maya'  => ['label' => 'Maya',  'icon' => 'bi-wallet2',      'color' => '#00A651', 'desc' => 'E-wallet'],
    'card'  => ['label' => 'Card',  'icon' => 'bi-credit-card',  'color' => '#2E6DA4', 'desc' => 'Credit / debit'],
    'qrph'  => ['label' => 'QR Ph', 'icon' => 'bi-qr-code',      'color' => '#173B6C', 'desc' => 'Any bank app'],
];

$total = $summary['total'] ?? ($app['amount_due'] ?? 10000);
$planName = $activePlan['name'] ?? 'Tuition';
$settle = $settle ?? null;
$settleAmount = is_array($settle) ? (float) ($settle['amount'] ?? 0) : 0.0;
$isPaid = ($app['pay_status'] ?? 'pending') === 'paid' && ($summary === null || ($summary['remaining'] ?? 1) <= 0.009);
$isProcessing = ($app['pay_status'] ?? '') === 'processing' && $due === null;
$showPlanStep = ($step === 'plan' || $due === null);
?>

<?= edu_page_header([
    'eyebrow'     => 'Secure checkout',
    'title'       => 'Choose how to pay',
    'description' => 'Accounting and the Registrar have approved your application. Pay the exact amount due through PayRex.',
    'actions'     => '<a href="' . e(url('/my-enrollments')) . '" class="btn btn-light btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to applications</a>',
]) ?>

<div class="row g-4">
  <!-- ================= Main column ================= -->
  <div class="col-12 col-lg-7">

    <?php if ($isPaid): ?>
      <?= edu_empty_state(
            'Fully paid — you are enrolled',
            'Your receipt has been sent to your email. A copy is always available in your documents.',
            ['icon' => 'bi-check-circle-fill', 'variant' => '', 'compact' => true]
        ) ?>
      <div class="d-flex justify-content-center gap-2">
        <?= edu_badge('Paid', 'success', 'bi-check-lg', 'lg') ?>
        <?= edu_badge('PayRex', 'neutral', 'bi-shield-check') ?>
      </div>

    <?php elseif ($isProcessing): ?>
      <?= edu_callout(
            'Payment received — awaiting admin verification. This page refreshes automatically when it clears.',
            ['tone' => 'warning', 'icon' => 'bi-hourglass-split', 'title' => 'Processing']
        ) ?>
      <div class="text-center small" style="color:var(--text-tertiary)">
        Ref <?= e($ref) ?> &middot; admin will verify the PayRex webhook before approving.
      </div>
      <div class="text-center mt-3">
        <span class="spinner-border spinner-border-sm" style="color:var(--warning)" role="status" aria-label="Checking payment"></span>
        <span class="small ms-2" style="color:var(--text-tertiary)">Auto-checking every 2.5&hellip;</span>
      </div>
      <script>(function(){ const u=<?= json_encode(url('/enroll/' . $app['id'] . '/payment-status')) ?>; function p(){fetch(u,{credentials:'same-origin'}).then(r=>r.json()).then(j=>{if(j.pay_status==='paid'||j.enrollment_status==='approved')location.reload();}).catch(()=>{});} setInterval(p,2500); p(); })();</script>

    <?php elseif ($showPlanStep): ?>
      <!-- Step 1: choose a plan -->
      <form method="post" action="<?= e(url('/enroll/' . $app['id'] . '/plan')) ?>">
        <?= csrf_field() ?>

        <div class="edu-card mb-3">
          <div class="edu-card__head">
            <h2 class="edu-card__title"><i class="bi bi-signpost-split"></i> Step 1 of 2 &mdash; choose a payment plan</h2>
            <?= edu_badge('Required', 'warning', 'bi-asterisk') ?>
          </div>
          <div class="edu-card__body">
            <div class="row g-3">
              <div class="col-12">
                <label class="w-100 m-0" style="cursor:pointer">
                  <input type="radio" name="plan_type" value="full" class="d-none plan-radio" checked>
                  <div class="edu-card edu-card--hover p-3 h-100 plan-card" data-plan="full">
                    <div class="d-flex justify-content-between align-items-center gap-3">
                      <div>
                        <div class="fw-bold" style="color:var(--primary-900)">Full tuition</div>
                        <div class="small" style="color:var(--text-tertiary)">Pay the full tuition amount at once.</div>
                      </div>
                      <div class="text-end flex-none">
                        <div class="fw-bold" style="color:var(--success-text);font-size:1.25rem"><?= money($total) ?></div>
                        <div class="small" style="color:var(--text-tertiary)">Pay once</div>
                      </div>
                    </div>
                  </div>
                </label>
              </div>

              <div class="col-12">
                <label class="w-100 m-0" style="cursor:pointer">
                  <input type="radio" name="plan_type" value="monthly" class="d-none plan-radio">
                  <div class="edu-card edu-card--hover p-3 h-100 plan-card" data-plan="monthly">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                      <div>
                        <div class="fw-bold" style="color:var(--primary-900)">Monthly payment</div>
                        <div class="small" style="color:var(--text-tertiary)">Pay a required downpayment first, then settle the remainder in monthly instalments.</div>
                      </div>
                    </div>
                    <?php if ($activePlan !== null): ?>
                      <?= edu_dl([
                          'Total tuition'        => '<strong>' . money($activePlan['total_tuition']) . '</strong>',
                          'Required downpayment' => '<strong>' . money(min($activePlan['downpayment_amount'], $activePlan['total_tuition'])) . '</strong>',
                          'Remaining balance'    => '<strong>' . money($activePlan['total_tuition'] - min($activePlan['downpayment_amount'], $activePlan['total_tuition'])) . '</strong>',
                          'Monthly instalment'   => '<strong>' . money(\App\Core\Tuition::monthlyFor($activePlan['total_tuition'], min($activePlan['downpayment_amount'], $activePlan['total_tuition']), $activePlan['installment_months'], $activePlan['monthly_amount'])) . ' × ' . (int) $activePlan['installment_months'] . ' months</strong>',
                      ], true) ?>
                    <?php else: ?>
                      <?= edu_callout('No monthly plan is configured for this programme yet. Please contact the accounting office.', ['tone' => 'info', 'icon' => 'bi-info-circle']) ?>
                    <?php endif; ?>
                  </div>
                </label>
              </div>
            </div>
          </div>
          <div class="edu-card__foot">
            <button type="submit" class="btn btn-primary w-100 py-2"><i class="bi bi-arrow-right me-1"></i> Continue to payment</button>
          </div>
        </div>
      </form>
      <style>
      .plan-card.selected { border-color: var(--primary-600) !important; background: var(--primary-50) !important; box-shadow: 0 0 0 3px rgba(46,109,164,.12) !important; }
      </style>
      <script>
      (function(){
        const cards=document.querySelectorAll('.plan-card');
        const radios=document.querySelectorAll('.plan-radio');
        function update(){ cards.forEach(c=>c.classList.remove('selected')); radios.forEach(r=>{ if(r.checked) document.querySelector('.plan-card[data-plan="'+r.value+'"]').classList.add('selected'); }); }
        radios.forEach(r=>r.addEventListener('change',update));
        cards.forEach(c=>c.addEventListener('click',()=>{ document.querySelector('.plan-radio[value="'+c.dataset.plan+'"]').checked=true; update(); }));
        update();
      })();
      </script>

    <?php else: ?>
      <?php
      $dueAmount  = (float) $due['amount'];
      $dueLabel   = (string) $due['label'];
      $paid       = (float) ($summary['paid'] ?? 0);
      $remaining  = (float) ($summary['remaining'] ?? 0);
      $isDown     = $due['kind'] === 'downpayment';
      $isInst     = $due['kind'] === 'installment';
      ?>

      <!-- Payment timeline -->
      <div class="edu-card mb-3">
        <div class="edu-card__head">
          <h2 class="edu-card__title"><i class="bi bi-list-check"></i> Payment timeline</h2>
          <?= edu_badge('PayRex', 'neutral', 'bi-shield-check') ?>
        </div>
        <div class="edu-card__body">
          <?= edu_timeline([
              ['title' => 'Application approved',            'meta' => 'Accounting + Registrar',            'state' => 'complete'],
              ['title' => 'Payment plan selected',           'meta' => ($summary['plan_type'] ?? '') === 'monthly' ? 'Monthly instalments' : 'Full payment', 'state' => 'complete'],
              ['title' => 'Payment submitted (' . $dueLabel . ')', 'meta' => money($dueAmount) . ' due now', 'state' => 'current'],
              ['title' => 'Webhook verified by admin',       'meta' => 'PayRex payment_intent.succeeded',    'state' => ''],
              ['title' => 'Receipt issued and enrolment on', 'meta' => 'Receipt emailed to you',            'state' => ''],
          ]) ?>
        </div>
      </div>

      <!-- Tuition breakdown -->
      <div class="edu-card mb-3">
        <div class="edu-card__head">
          <h2 class="edu-card__title"><i class="bi bi-receipt"></i> Tuition breakdown</h2>
          <?= edu_badge($isDown ? 'Downpayment' : ($isInst ? 'Instalment' : 'Payment'), 'info') ?>
        </div>
        <div class="edu-card__body">
          <?= edu_dl([
              'Tuition fee'        => money($total),
              'Payment plan'       => ($summary['plan_type'] ?? '') === 'monthly' ? 'Monthly' : 'Full payment',
              'Paid so far'        => money($paid),
              'Remaining balance'  => '<strong>' . money($remaining) . '</strong>',
              'Reference'          => '<code>' . e($ref) . '</code>',
          ]) ?>

          <hr style="border-color:var(--border)">
          <div class="d-flex justify-content-between align-items-center">
            <span class="fw-bold" style="color:var(--primary-900)">Now due &mdash; <?= e($dueLabel) ?></span>
            <span class="fs-4 fw-bold" style="color:var(--success-text)"><?= money($dueAmount) ?></span>
          </div>
          <?php if ($settleAmount > 0.009): ?>
          <div class="d-flex justify-content-between align-items-center mt-1">
            <span class="fw-semibold small" style="color:var(--text-secondary)">Full remaining — pay off anytime</span>
            <span class="fw-bold" style="color:var(--success-text)"><?= money($settleAmount) ?></span>
          </div>
          <?php endif; ?>
          <?php if (!empty($summary['next']['due_date'])): ?>
            <div class="small text-end" style="color:var(--text-tertiary)">
              Due date <?= e(edu_date($summary['next']['due_date'])) ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Instalment schedule -->
      <?php if (!empty($summary['installments'])): ?>
        <div class="edu-card mb-3">
          <div class="edu-card__head">
            <h2 class="edu-card__title"><i class="bi bi-calendar3"></i> Instalment schedule</h2>
            <?= edu_badge(count($summary['installments']) . ' instalments', 'neutral') ?>
          </div>
          <div class="edu-card__body">
            <div class="edu-table-wrap">
              <div class="edu-table-scroll">
                <table class="edu-table edu-table--compact edu-table--cards">
                  <thead><tr><th>#</th><th class="edu-table__num">Amount</th><th>Due date</th><th>Status</th></tr></thead>
                  <tbody>
                  <?php foreach ($summary['installments'] as $ins):
                      $instStatus = (string) ($ins['display_status'] ?? $ins['status'] ?? ''); ?>
                    <tr>
                      <td data-label="#" class="fw-semibold"><?= (int) $ins['n'] ?></td>
                      <td data-label="Amount" class="edu-table__num"><?= money($ins['amount']) ?></td>
                      <td data-label="Due date"><?= e(edu_date($ins['due_date'] ?? null)) ?></td>
                      <td data-label="Status"><?= edu_status_badge($instStatus) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div id="qrPollStatus" class="alert alert-warning py-2 small text-center mb-3 d-none"><i class="bi bi-hourglass-split"></i> Checking payment...</div>

      <form method="post" action="<?= e(url('/enroll/' . $app['id'] . '/plan/clear')) ?>" class="mb-3">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-light btn-sm w-100"><i class="bi bi-arrow-left me-1"></i> Change payment plan (Full &harr; Monthly)</button>
        <div class="form-text text-center">Available until a payment is submitted.</div>
      </form>

      <!-- Payment method + submit -->
      <form method="post" action="<?= e(url('/enroll/' . $app['id'] . '/pay')) ?>" id="payForm">
        <?= csrf_field() ?>
        <div class="edu-card mb-3">
          <div class="edu-card__head">
            <h2 class="edu-card__title"><i class="bi bi-credit-card"></i> Step 2 of 2 &mdash; payment method</h2>
            <?= edu_badge('Required', 'warning', 'bi-asterisk') ?>
          </div>
          <div class="edu-card__body">
            <?php if ($settleAmount > 0.009): ?>
            <div class="row g-2 mb-3" id="scopeGrid" role="radiogroup" aria-label="Choose what to pay">
              <div class="col-12">
                <label class="w-100 m-0" style="cursor:pointer">
                  <input type="radio" name="pay_scope" value="due" class="d-none scope-radio" checked>
                  <div class="scope-card edu-card edu-card--hover p-3 h-100" data-scope="due">
                    <div class="d-flex justify-content-between align-items-center gap-3">
                      <div>
                        <div class="fw-bold" style="color:var(--primary-900)">Pay on schedule</div>
                        <div class="small" style="color:var(--text-tertiary)"><?= e($dueLabel) ?><?php if (!empty($summary['next']['due_date'])): ?> · due <?= e(edu_date($summary['next']['due_date'])) ?><?php endif; ?></div>
                      </div>
                      <div class="text-end flex-none">
                        <div class="fw-bold" style="color:var(--primary-900);font-size:1.25rem"><?= money($dueAmount) ?></div>
                        <div class="small" style="color:var(--text-tertiary)">Next due only</div>
                      </div>
                    </div>
                  </div>
                </label>
              </div>
              <div class="col-12">
                <label class="w-100 m-0" style="cursor:pointer">
                  <input type="radio" name="pay_scope" value="settle" class="d-none scope-radio">
                  <div class="scope-card edu-card edu-card--hover p-3 h-100" data-scope="settle">
                    <div class="d-flex justify-content-between align-items-center gap-3">
                      <div>
                        <div class="fw-bold" style="color:var(--primary-900)">Pay full remaining balance</div>
                        <div class="small" style="color:var(--text-tertiary)">Pay off anytime — clears all future dues at once.</div>
                      </div>
                      <div class="text-end flex-none">
                        <div class="fw-bold" style="color:var(--success-text);font-size:1.25rem"><?= money($settleAmount) ?></div>
                        <div class="small" style="color:var(--text-tertiary)">Nothing left after</div>
                      </div>
                    </div>
                  </div>
                </label>
              </div>
            </div>
            <?php endif; ?>
            <div class="row g-2" id="methodGrid">
              <?php foreach ($methods as $key => $m): ?>
              <div class="col-6">
                <label class="w-100 m-0" style="cursor:pointer">
                  <input type="radio" name="payment_method" value="<?= e($key) ?>" class="d-none method-radio" <?= $key === 'gcash' ? 'checked' : '' ?>>
                  <div class="method-card edu-card edu-card--hover p-3 text-center h-100" data-method="<?= e($key) ?>">
                    <div class="mx-auto d-flex align-items-center justify-content-center mb-2" style="width:42px;height:42px;border-radius:12px;background:<?= e($m['color']) ?>;color:#fff">
                      <i class="bi <?= e($m['icon']) ?>" style="font-size:1.1rem"></i>
                    </div>
                    <div class="fw-bold small"><?= e($m['label']) ?></div>
                    <div class="small" style="color:var(--text-tertiary);font-size:.7rem"><?= e($m['desc']) ?></div>
                    <div class="small mt-1"><?= edu_badge('PayRex', 'neutral') ?></div>
                  </div>
                </label>
              </div>
              <?php endforeach; ?>
            </div>

            <div class="mt-3"><?= edu_callout(
                'After you pay you will be marked <strong>Processing</strong>, and the admin verifies via the PayRex webhook <code>payment_intent.succeeded</code> at <code>/api/payrex/webhook.php</code> &rarr; <strong>Paid</strong> + receipt.',
                ['tone' => 'info', 'icon' => 'bi-info-circle-fill']
            ) ?></div>
          </div>
          <div class="edu-card__foot">
            <button type="submit" class="btn btn-success w-100 py-3 fw-bold" id="payBtn"
                    data-amount-due="<?= e(number_format($dueAmount, 2, '.', '')) ?>"
                    data-label-due="<?= e($dueLabel) ?>"
                    <?php if ($settleAmount > 0.009): ?>data-amount-settle="<?= e(number_format($settleAmount, 2, '.', '')) ?>"<?php endif; ?>>
              <i class="bi bi-lock me-1"></i> Pay <?= money($dueAmount) ?> &mdash; Notify Admin
            </button>
            <div class="small text-center mt-2" style="color:var(--text-tertiary)">
              <i class="bi bi-shield-check me-1"></i> Secure via PayRex &middot; Webhook live &middot; Test mode<br>
              Billing questions? Contact <a href="<?= e(url('/support')) ?>">Support</a>.
            </div>
          </div>
        </div>
      </form>
      <style>
      .method-card.selected, .scope-card.selected { border-color: var(--primary-600) !important; background: var(--primary-50) !important; box-shadow: 0 0 0 3px rgba(46,109,164,.12) !important; }
      .method-card.selected .edu-badge { background: var(--primary-600) !important; color: #fff !important; border-color: var(--primary-700) !important; }
      </style>
      <script>
      (function(){
        const cards=document.querySelectorAll('.method-card');
        const radios=document.querySelectorAll('.method-radio');
        const btn=document.getElementById('payBtn');
        const amtDue=btn.getAttribute('data-amount-due');
        const lblDue=btn.getAttribute('data-label-due');
        const amtSettle=btn.getAttribute('data-amount-settle');
        const hasSettle=amtSettle!==null&&amtSettle!==''&&parseFloat(amtSettle)>0;
        function money(n){ return '\u20B1'+Number(n).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
        function scopeVal(){ var s=document.querySelector('.scope-radio:checked'); return s?s.value:'due'; }
        function update(){
          cards.forEach(c=>c.classList.remove('selected'));
          radios.forEach(r=>{ if(r.checked){ var mc=document.querySelector('.method-card[data-method="'+r.value+'"]'); if(mc) mc.classList.add('selected'); } });
          document.querySelectorAll('.scope-card').forEach(c=>c.classList.remove('selected'));
          document.querySelectorAll('.scope-radio').forEach(r=>{ if(r.checked){ var sc=document.querySelector('.scope-card[data-scope="'+r.value+'"]'); if(sc) sc.classList.add('selected'); } });
          const useSettle=hasSettle&&scopeVal()==='settle';
          const amt=useSettle?amtSettle:amtDue;
          const lbl=useSettle?'Full Remaining Balance':lblDue;
          const sel=document.querySelector('.method-radio:checked');
          const mname=!sel?'PayRex':(sel.value==='qrph'?'QR Ph':sel.value.charAt(0).toUpperCase()+sel.value.slice(1));
          btn.innerHTML='<i class="bi bi-lock"></i> Pay '+money(amt)+' ('+lbl+') via '+mname+' — Notify Admin';
        }
        radios.forEach(r=>r.addEventListener('change',update));
        cards.forEach(c=>c.addEventListener('click',()=>{ var mr=document.querySelector('.method-radio[value="'+c.dataset.method+'"]'); if(mr){ mr.checked=true; } update(); }));
        document.querySelectorAll('.scope-radio').forEach(r=>r.addEventListener('change',update));
        document.querySelectorAll('.scope-card').forEach(c=>c.addEventListener('click',()=>{ var sr=document.querySelector('.scope-radio[value="'+c.dataset.scope+'"]'); if(sr){ sr.checked=true; } update(); }));
        update();
        const statusUrl=<?= json_encode(url('/enroll/' . $app['id'] . '/payment-status')) ?>;
        let statusInit=null;
        function poll(){ fetch(statusUrl,{credentials:'same-origin'}).then(r=>r.json()).then(j=>{ if(j.pay_status==='processing'){ document.getElementById('qrPollStatus').classList.remove('d-none'); document.getElementById('qrPollStatus').innerHTML='<i class="bi bi-check-circle-fill"></i> <strong>Payment received!</strong> Awaiting admin verification'; } var cur=j.pay_status+'|'+j.enrollment_status; if(statusInit===null){ statusInit=cur; return; } if(cur!==statusInit&&(j.pay_status==='paid'||j.enrollment_status==='approved')) location.reload(); }).catch(()=>{}); }
        setInterval(poll,2800); poll();
      })();
      </script>
    <?php endif; ?>
  </div>

  <!-- ================= Summary sidebar ================= -->
  <div class="col-12 col-lg-5">
    <div class="edu-card edu-card--primary" style="position:sticky;top:5rem">
      <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-receipt-cutoff"></i> Order summary</h2>
        <?= edu_status_badge((string) ($app['status'] ?? 'pending')) ?>
      </div>
      <div class="edu-card__body">
        <?= edu_dl([
            'Application' => '<strong>' . e($app['application_no'] ?? '') . '</strong>',
            'Level'       => e($app['level_name'] ?? '') . (!empty($app['program_name']) ? ' &mdash; ' . e($app['program_name']) : ''),
            'Plan'        => e($planName),
            'Reference'   => '<code>' . e($ref) . '</code>',
            'Status'      => edu_status_badge((string) ($app['status'] ?? 'pending')),
        ]) ?>

        <hr style="border-color:var(--border)">
        <div class="d-flex justify-content-between align-items-baseline">
          <span class="fw-semibold" style="color:var(--text-secondary)">Total tuition</span>
          <span class="fs-5 fw-bold" style="color:var(--primary-900)"><?= money($total) ?></span>
        </div>

        <?php if ($summary !== null): ?>
          <div class="mt-2 pt-2 border-top" style="border-color:var(--border)!important">
            <div class="d-flex justify-content-between small" style="color:var(--text-tertiary)">
              <span>Paid</span><span><?= money($summary['paid'] ?? 0) ?></span>
            </div>
            <div class="d-flex justify-content-between small fw-semibold" style="color:var(--text-secondary)">
              <span>Remaining</span><span><?= money($summary['remaining'] ?? 0) ?></span>
            </div>
          </div>
          <?= edu_progress((float) edu_percent((float) ($summary['paid'] ?? 0), (float) ($total ?: 1), 1), [
              'label' => 'Paid so far',
              'tone'  => 'success',
              'meta'  => true,
          ]) ?>
        <?php endif; ?>

        <?php if (!empty($intentId)): ?>
          <div class="small mt-3" style="color:var(--text-tertiary)">
            PayRex intent: <code><?= e($intentId) ?></code>
          </div>
        <?php endif; ?>

        <div class="mt-3 pt-3 border-top" style="border-color:var(--border)!important">
          <?= edu_dl([
              'Gateway'  => 'PayRex',
              'Webhook'  => 'Live',
              'Mode'     => 'Test',
          ], true, ['skip_empty' => true]) ?>
        </div>
      </div>
    </div>
  </div>
</div>
