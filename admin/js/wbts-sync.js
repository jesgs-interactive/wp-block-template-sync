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
						var hasChanges = !!(data.data && data.data.has_changes);
						if ( hasChanges ) {
							diffContainer.innerHTML = data.data.diff || '<pre>Changes detected</pre>';
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

