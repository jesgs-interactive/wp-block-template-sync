(function () {
	if ( 'undefined' === typeof wbtsSync ) {
		return;
	}

	document.addEventListener('DOMContentLoaded', function () {
		const previewBtn = document.getElementById('wbts-sync-preview');
		const commitBtn = document.getElementById('wbts-sync-commit');
		const diffContainer = document.getElementById('wbts-sync-diff');

		if ( previewBtn ) {
			previewBtn.addEventListener('click', function (e) {
				e.preventDefault();
				previewBtn.disabled = true;
				diffContainer.innerHTML = 'Generating preview...';

				fetch(wbtsSync.ajax_url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
					},
					body: 'action=wbts_sync_preview&nonce=' + encodeURIComponent(wbtsSync.nonce)
				})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					previewBtn.disabled = false;
					if ( data.success ) {
                        // Prefer explicit has_changes from server; fall back to a
                        // heuristic comparing returned pretty strings when absent.
                        let hasChanges;
                        var themeVal = data.data && data.data.theme;
                        var dbVal = data.data && data.data.db;

                        // Normalize string 'null' (some servers may double-encode) to actual null
                        if ( typeof dbVal === 'string' && dbVal === 'null' ) {
                            dbVal = null;
                        }

                        if ( data.data && typeof data.data.has_changes !== 'undefined' ) {
                            hasChanges = !!data.data.has_changes;
                        } else if ( typeof themeVal !== 'undefined' && typeof dbVal !== 'undefined' ) {
                            // If DB has no global styles (null), treat as a change so the theme.json
                            // can be written to the DB. Otherwise compare the pretty-printed strings.
                            if ( dbVal === null ) {
                                hasChanges = true;
                            } else {
                                hasChanges = themeVal !== dbVal;
                            }
                        } else {
                            // Last resort: check diff content presence
                            hasChanges = !!(data.data && data.data.diff && data.data.diff.indexOf('No differences') === -1);
                        }

                        if ( hasChanges ) {
                            // If DB is empty/null, show a clearer message and still display diff/theme
                            if ( dbVal === null ) {
                                var msg = '<div class="notice notice-warning">No global styles found in the database. Previewing theme.json which will be written to the DB.</div>';
                                diffContainer.innerHTML = msg + (data.data.diff || ('<pre>' + (data.data.theme || 'theme.json') + '</pre>'));
                            } else {
                                diffContainer.innerHTML = data.data.diff || '<pre>Changes detected</pre>';
                            }
                            if ( commitBtn ) {
                                commitBtn.style.display = 'inline-block';
                            }
                        } else {
                            diffContainer.innerHTML = '<div class="notice notice-info">No changes to sync — theme.json matches the database.</div>';
                            if ( commitBtn ) {
                                commitBtn.style.display = 'none';
                            }
                        }
					} else {
						diffContainer.innerHTML = '<div class="error">Preview failed</div>';
					}
				})
				.catch(function () {
					previewBtn.disabled = false;
					diffContainer.innerHTML = '<div class="error">Preview failed (network)</div>';
				});
			});
		}

		if ( commitBtn ) {
			commitBtn.addEventListener('click', function (e) {
				e.preventDefault();
				if ( ! confirm('This will overwrite the database global styles with values from theme.json. Proceed?') ) {
					return;
				}
				commitBtn.disabled = true;
				commitBtn.textContent = 'Syncing...';

				fetch(wbtsSync.ajax_url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
					},
					body: 'action=wbts_sync_commit&nonce=' + encodeURIComponent(wbtsSync.nonce)
				})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					commitBtn.disabled = false;
					commitBtn.textContent = 'Sync theme.json → Database';
					if ( data.success ) {
						diffContainer.innerHTML = '<div class="notice notice-success">Sync completed.</div>';
						commitBtn.style.display = 'none';
					} else {
						diffContainer.innerHTML = '<div class="notice notice-error">Sync failed.</div>';
					}
				})
				.catch(function () {
					commitBtn.disabled = false;
					commitBtn.textContent = 'Sync theme.json → Database';
					diffContainer.innerHTML = '<div class="notice notice-error">Sync failed (network).</div>';
				});
			});
		}
	});
})();

