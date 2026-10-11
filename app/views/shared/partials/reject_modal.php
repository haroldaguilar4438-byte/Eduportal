<?php
/**
 * Shared rejection modal partial.
 * Include once per page AFTER the table, then trigger with:
 * <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
 *   data-bs-target="#rejectModal" data-form-action="<?= e(url('/...')) ?>"
 *   data-record="EP-2026-... — Student Name" data-title="Reject Permit">Reject</button>
 * The modal form posts `reason` (required, min 5 chars). CSRF field is injected by JS from `window.eduCsrf`.
 */
?>
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="rejectForm" method="post" action="">
        <?= \App\Core\Csrf::field() ?>
        <div class="modal-header">
          <h5 class="modal-title" id="rejectModalLabel">Reject</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted mb-1">Record</p>
          <p class="fw-semibold" id="rejectRecord">—</p>
          <label class="small fw-semibold" for="rejectReason">Reason for rejection <span class="text-danger">*</span></label>
          <textarea id="rejectReason" name="reason" class="form-control" rows="3" required minlength="5" maxlength="500" placeholder="Please explain why this request is being rejected..."></textarea>
          <div class="small text-muted mt-1">Saved with the rejection, shown to the student, and kept in history/audit.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger" id="rejectSubmit">Reject</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function(){
  var modal = document.getElementById('rejectModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function(ev){
    var btn = ev.relatedTarget;
    var action = btn ? btn.getAttribute('data-form-action') : '';
    var record = btn ? (btn.getAttribute('data-record') || '—') : '—';
    var title = btn ? (btn.getAttribute('data-title') || 'Reject') : 'Reject';
    document.getElementById('rejectForm').setAttribute('action', action || '');
    document.getElementById('rejectRecord').textContent = record;
    document.getElementById('rejectModalLabel').textContent = title;
    document.getElementById('rejectSubmit').textContent = title;
    var ta = document.getElementById('rejectReason');
    ta.value = '';
    ta.classList.remove('is-invalid');
  });
  document.getElementById('rejectForm').addEventListener('submit', function(ev){
    var ta = document.getElementById('rejectReason');
    if (!ta.value || ta.value.trim().length < 5) {
      ev.preventDefault();
      ta.focus();
      ta.classList.add('is-invalid');
    }
  });
})();
</script>
