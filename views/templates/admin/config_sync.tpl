<div class="panel alloia-sync-panel">
  <h3><i class="icon-refresh"></i> {l s='Product sync' mod='alloiaprestashop'}</h3>
  <p>{l s='Synchronize your products with the AlloIA knowledge graph. Save your API key above first.' mod='alloiaprestashop'}</p>

  <div class="form-group">
    <strong>{l s='Total products:' mod='alloiaprestashop'}</strong>
    <span id="alloia_total_products">{$alloia_total_products|intval}</span>
  </div>
  <div id="alloia_last_sync_block" class="form-group alloia-last-sync">
    <strong>{l s='Last sync:' mod='alloiaprestashop'}</strong>
    <span id="alloia_last_sync_text">
      {if $alloia_last_sync_date_formatted}
      <span id="alloia_last_sync_date_part">{$alloia_last_sync_date_formatted|escape:'html':'UTF-8'}</span> — <span id="alloia_last_sync_result">{$alloia_last_sync_sent|intval}/{$alloia_last_sync_total|intval} {l s='products synced' mod='alloiaprestashop'}{if $alloia_last_sync_ignored > 0}. {l s='%d ignored (inactive, hidden or private).' sprintf=[$alloia_last_sync_ignored] mod='alloiaprestashop'}{/if}{if $alloia_last_sync_failed > 0} {l s='%d failed.' sprintf=[$alloia_last_sync_failed] mod='alloiaprestashop'}{/if}</span>
      {else}
      <span id="alloia_last_sync_never">{l s='Never' mod='alloiaprestashop'}</span>
      {/if}
    </span>
  </div>

  <div class="form-group">
    <button type="button" id="alloia_sync_all_products" class="btn btn-primary">
      <i class="icon-upload"></i> {l s='Synchronize all products' mod='alloiaprestashop'}
    </button>
    <span id="alloia_sync_status" class="alloia-status"></span>
  </div>
  <div class="form-group">
    <button type="button" id="alloia_validate_api_key" class="btn btn-default">
      <i class="icon-check"></i> {l s='Validate API key' mod='alloiaprestashop'}
    </button>
    <span id="alloia_api_key_status" class="alloia-status"></span>
  </div>
</div>

<script>
  (function() {
    var validateUrl = '{$alloia_validate_url|escape:'javascript'}';
    var syncUrl = '{$alloia_sync_url|escape:'javascript'}';
    var apiKeyInput = document.querySelector('input[name="ALLOIA_API_KEY"]');
    var validateBtn = document.getElementById('alloia_validate_api_key');
    var validateStatus = document.getElementById('alloia_api_key_status');
    var syncBtn = document.getElementById('alloia_sync_all_products');
    var syncStatus = document.getElementById('alloia_sync_status');

    function setStatus(el, msg, isError) {
      el.textContent = msg;
      el.className = 'alloia-status ' + (isError ? 'text-danger' : 'text-success');
    }

    if (validateBtn) {
      validateBtn.addEventListener('click', function() {
        var key = apiKeyInput ? apiKeyInput.value.trim() : '';
        if (!key) {
          setStatus(validateStatus, 'Enter an API key first.', true);
          return;
        }
        validateBtn.disabled = true;
        setStatus(validateStatus, 'Checking...', false);
        fetch(validateUrl + '&api_key=' + encodeURIComponent(key), { method: 'GET', credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (data.success && data.valid) {
              setStatus(validateStatus, 'API key valid.', false);
            } else {
              var errMsg = (data.error && data.error.message) ? data.error.message : (typeof data.error === 'string' ? data.error : 'Invalid');
              setStatus(validateStatus, errMsg || 'Invalid', true);
            }
          })
          .catch(function(err) {
            setStatus(validateStatus, 'Request failed.', true);
          })
          .then(function() { validateBtn.disabled = false; });
      });
    }

    function formatSyncResult(data) {
      var total = data.total_products != null ? data.total_products : 0;
      var sent = data.products_sent != null ? data.products_sent : 0;
      var ignored = data.products_ignored != null ? data.products_ignored : 0;
      var failed = data.products_failed != null ? data.products_failed : 0;
      var text = sent + '/' + total + ' products synced.';
      if (ignored > 0) text += ' ' + ignored + ' ignored (inactive, hidden or private).';
      if (failed > 0) text += ' ' + failed + ' failed.';
      return text;
    }

    function updateLastSyncFromResponse(data) {
      var totalEl = document.getElementById('alloia_total_products');
      var textEl = document.getElementById('alloia_last_sync_text');
      if (data.total_products != null && totalEl) totalEl.textContent = data.total_products;
      if (!textEl) return;
      var now = new Date();
      var dateStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0') + ' ' + String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
      textEl.textContent = dateStr + ' — ' + formatSyncResult(data);
    }

    if (syncBtn) {
      syncBtn.addEventListener('click', function() {
        syncBtn.disabled = true;
        setStatus(syncStatus, 'Syncing...', false);
        fetch(syncUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (data.success) {
              var msg = data.message || 'Sync completed.';
              if (data.products_processed !== undefined) {
                msg += ' ' + (data.products_sent != null ? data.products_sent : data.products_processed) + '/' + (data.total_products != null ? data.total_products : '') + ' synced.';
                if (data.products_ignored > 0) msg += ' ' + data.products_ignored + ' ignored.';
                if (data.products_failed > 0) msg += ' ' + data.products_failed + ' failed.';
              }
              setStatus(syncStatus, msg, false);
              if (data.total_products != null) updateLastSyncFromResponse(data);
            } else {
              setStatus(syncStatus, data.error || 'Sync failed.', true);
            }
          })
          .catch(function(err) {
            setStatus(syncStatus, 'Request failed.', true);
          })
          .then(function() { syncBtn.disabled = false; });
      });
    }
  })();
</script>
