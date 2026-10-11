<?php
/**
 * Admin — tuition plan management (spec §29).
 *
 * Non-destructive: $plans and $stats are used exactly as before. The create
 * form keeps every field name (name, total_tuition, downpayment_amount,
 * installment_months, monthly_amount), its validation attributes and its
 * action; the per-plan Activate form keeps its action and CSRF token.
 *
 * What changed: the create form is grouped into labelled field sections, and
 * the plan list became pricing cards so a plan reads as a price rather than a
 * row of numbers.
 *
 * @var array $plans
 * @var array $stats
 */
$plans = $plans ?? [];
$stats = $stats ?? [];
?>

<?= edu_page_header([
    'eyebrow'     => 'Finance configuration',
    'title'       => 'Tuition payment plans',
    'description' => 'Plans apply to new checkouts immediately. Applications already in progress keep the snapshot they started with, so changing a plan never rewrites history.',
    'actions'     => '<a href="' . e(url('/admin/payments')) . '" class="btn btn-light btn-sm"><i class="bi bi-credit-card me-1"></i> Payments</a>',
]) ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Full-tuition plans', 'value' => (string) (int) ($stats['full'] ?? 0), 'icon' => 'bi-cash-coin', 'tone' => 'primary']) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Monthly plans', 'value' => (string) (int) ($stats['monthly'] ?? 0), 'icon' => 'bi-calendar3', 'tone' => 'info']) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Collected', 'value' => '<span style="font-size:1.2rem">' . money($stats['collected'] ?? 0) . '</span>', 'icon' => 'bi-graph-up-arrow', 'tone' => 'success']) ?></div>
    <div class="col-6 col-lg-3"><?= edu_stat_card(['label' => 'Receivable', 'value' => '<span style="font-size:1.2rem">' . money(max(0, (float) ($stats['receivable'] ?? 0))) . '</span>', 'icon' => 'bi-hourglass-split', 'tone' => 'warning', 'meta' => '<span>Plan totals &minus; paid</span>']) ?></div>
</div>

<!-- Create plan -->
<div class="edu-card mb-4">
    <div class="edu-card__head">
        <h2 class="edu-card__title"><i class="bi bi-plus-circle"></i> New plan</h2>
        <?= edu_badge('Activates immediately for new checkouts', 'accent', 'bi-lightning-charge') ?>
    </div>
    <div class="edu-card__body">
        <form method="post" action="<?= e(url('/admin/tuition-plans')) ?>" novalidate>
            <?= csrf_field() ?>

            <p class="small mb-3" style="color:var(--text-tertiary)">
                <i class="bi bi-info-circle me-1"></i>In-progress applications keep the snapshot they started with.
            </p>

            <div class="edu-form-section mb-3">
                <div class="edu-form-section__head">
                    <i class="bi bi-tag"></i> Identification
                    <span class="edu-form-section__hint">Shown to families at checkout</span>
                </div>
                <div class="edu-form-section__body">
                    <label class="form-label" for="tpName">Plan name <span class="req">*</span></label>
                    <input name="name" id="tpName" class="form-control" required maxlength="80" value="Default tuition" placeholder="e.g. Default tuition 2026-2027">
                </div>
            </div>

            <div class="edu-form-section mb-3">
                <div class="edu-form-section__head">
                    <i class="bi bi-cash-stack"></i> Amounts
                    <span class="edu-form-section__hint">All figures in Philippine pesos</span>
                </div>
                <div class="edu-form-section__body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="tpTotal">Total tuition <span class="req">*</span></label>
                            <input name="total_tuition" id="tpTotal" type="number" class="form-control" required min="1" step="0.01" value="10000" inputmode="decimal">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="tpDown">Downpayment <span class="req">*</span></label>
                            <input name="downpayment_amount" id="tpDown" type="number" class="form-control" required min="1" step="0.01" value="3000" inputmode="decimal">
                            <div class="form-text">Required before instalments begin.</div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="tpMonthly">Monthly amount</label>
                            <input name="monthly_amount" id="tpMonthly" type="number" class="form-control" min="1" step="0.01" placeholder="auto" inputmode="decimal">
                            <div class="form-text">Leave blank to calculate automatically.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="edu-form-section mb-3">
                <div class="edu-form-section__head">
                    <i class="bi bi-calendar-range"></i> Schedule
                    <span class="edu-form-section__hint">1&ndash;24 months</span>
                </div>
                <div class="edu-form-section__body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="tpMonths">Installment months <span class="req">*</span></label>
                            <input name="installment_months" id="tpMonths" type="number" class="form-control" required min="1" max="24" value="7" inputmode="numeric">
                            <div class="form-text">Between 1 and 24 months.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Save &amp; activate</button>
            </div>
        </form>
    </div>
</div>

<!-- Plans -->
<?= edu_section_head('Plans', count($plans) . ' plan' . (count($plans) === 1 ? '' : 's') . ' configured', '', 'bi-list-task') ?>

<?php if ($plans === []): ?>
  <?= edu_empty_state(
        'No tuition plans configured',
        'Create a plan above, or import database/patch_tuition_plans.sql to seed the defaults.',
        ['icon' => 'bi-cash-stack']
    ) ?>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($plans as $p):
        $isActive = !empty($p['is_active']);
        $total    = (float) $p['total_tuition'];
        $down     = (float) $p['downpayment_amount'];
        $months   = (int) $p['installment_months'];
        $downPct  = edu_percent($down, $total ?: 1, 0);
        $rest     = max(0, $total - $down);
        $monthly  = $p['monthly_amount'] !== null
            ? (float) $p['monthly_amount']
            : ($months > 0 ? $rest / $months : 0); ?>
      <div class="col-12 col-md-6 col-xl-4 d-flex">
        <article class="edu-card edu-card--hover d-flex flex-column w-100 <?= $isActive ? 'edu-card--primary' : '' ?>">
          <div class="edu-card__head">
            <h3 class="edu-card__title mb-0"><i class="bi bi-cash-stack"></i> <?= e($p['name']) ?></h3>
            <?= $isActive ? edu_badge('Active', 'success', 'bi-check-circle-fill') : edu_badge('Inactive', 'neutral', 'bi-dash-circle') ?>
          </div>

          <div class="edu-card__body flex-grow-1">
            <div class="mb-3">
              <div class="small" style="color:var(--text-tertiary)">Total tuition</div>
              <div class="fs-4 fw-bold" style="color:var(--primary-900)"><?= money($total) ?></div>
            </div>

            <?= edu_progress((float) $downPct, ['label' => 'Downpayment share', 'meta' => true, 'tone' => 'accent']) ?>

            <?= edu_dl([
                'Downpayment'   => '<strong>' . money($down) . '</strong>',
                'Months'        => $months . ' month' . ($months === 1 ? '' : 's'),
                'Monthly'       => $p['monthly_amount'] !== null
                    ? '<strong>' . money($p['monthly_amount']) . '</strong>'
                    : '<span style="color:var(--text-tertiary)">auto &rarr; ' . money($monthly) . '</span>',
                'After down'    => money($rest),
            ]) ?>
          </div>

          <div class="edu-card__foot">
            <?php if (!$isActive): ?>
              <form method="post" action="<?= e(url('/admin/tuition-plans/' . (int) $p['id'] . '/activate')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-primary w-100"><i class="bi bi-lightning-charge me-1"></i> Activate this plan</button>
              </form>
            <?php else: ?>
              <div class="small" style="color:var(--text-tertiary)">
                <i class="bi bi-check-circle me-1"></i>Active &mdash; applied to new checkouts
              </div>
            <?php endif; ?>
          </div>
        </article>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
