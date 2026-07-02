(function () {
	'use strict';

	var cfg = typeof leadwerkWebpBody === 'undefined' ? null : leadwerkWebpBody;
	if (!cfg || !cfg.ajaxUrl || !cfg.nonce) {
		return;
	}

	function qs(sel) {
		return document.querySelector(sel);
	}

	function post(action, fields) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.nonce);
		if (fields) {
			Object.keys(fields).forEach(function (k) {
				body.set(k, fields[k]);
			});
		}
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		}).then(function (r) {
			return r.json();
		});
	}

	function setProgress(visible, text) {
		var wrap = qs('#leadwerk-webp-progress');
		var span = qs('#leadwerk-webp-status');
		if (!wrap || !span) {
			return;
		}
		wrap.style.display = visible ? 'block' : 'none';
		span.textContent = text || '';
	}

	function handleError(res) {
		var msg = (res && res.data && res.data.message) ? res.data.message : 'Request failed';
		setProgress(true, (cfg.strings && cfg.strings.error ? cfg.strings.error + ' ' : '') + msg);
	}

	var dryBtn = qs('#leadwerk-webp-dry-run');
	if (dryBtn) {
		dryBtn.addEventListener('click', function () {
			setProgress(true, cfg.strings && cfg.strings.progress ? cfg.strings.progress : '…');
			dryBtn.disabled = true;
			post('leadwerk_webp_body_start', { dry_run: '1' })
				.then(function (res) {
					dryBtn.disabled = false;
					if (!res.success) {
						handleError(res);
						return;
					}
					var job = res.data && res.data.job ? res.data.job : {};
					var st = job.stats || {};
					var line =
						'Dry-Run: Body-Posts ' +
						(st.posts_scanned || 0) +
						', Beitragsbild-JPEG/PNG ' +
						(st.posts_with_jpeg_png_featured || 0) +
						', Attachments ' +
						(st.attachments_found || 0);
					setProgress(true, line);
					window.location.reload();
				})
				.catch(function () {
					dryBtn.disabled = false;
					setProgress(true, 'Network error');
				});
		});
	}

	var liveBtn = qs('#leadwerk-webp-live');
	if (liveBtn) {
		liveBtn.addEventListener('click', function () {
			if (!window.confirm('Live-Konvertierung starten? post_content und ggf. Beitragsbild (_thumbnail_id) werden angepasst.')) {
				return;
			}
			setProgress(true, cfg.strings && cfg.strings.progress ? cfg.strings.progress : '…');
			liveBtn.disabled = true;
			post('leadwerk_webp_body_start', {})
				.then(function (res) {
					if (!res.success) {
						liveBtn.disabled = false;
						handleError(res);
						return;
					}
					var job = res.data && res.data.job ? res.data.job : {};
					if (job.status === 'completed') {
						liveBtn.disabled = false;
						setProgress(true, job.message || (cfg.strings && cfg.strings.done) || 'OK');
						window.location.reload();
						return;
					}
					return runSteps();
				})
				.catch(function () {
					liveBtn.disabled = false;
					setProgress(true, 'Network error');
				});

			function runSteps() {
				return post('leadwerk_webp_body_step', {})
					.then(function (res) {
						if (!res.success) {
							liveBtn.disabled = false;
							handleError(res);
							return;
						}
						var job = res.data && res.data.job ? res.data.job : {};
						var cur = job.cursor != null ? job.cursor : 0;
						var q = job.queue && job.queue.length ? job.queue.length : 0;
						setProgress(true, 'Konvertierung… ' + cur + '/' + q);
						if (job.status === 'completed') {
							liveBtn.disabled = false;
							setProgress(true, job.message || (cfg.strings && cfg.strings.done) || 'OK');
							window.location.reload();
							return;
						}
						if (job.status === 'failed') {
							liveBtn.disabled = false;
							handleError({ data: { message: job.message || 'failed' } });
							return;
						}
						return runSteps();
					})
					.catch(function () {
						liveBtn.disabled = false;
						setProgress(true, 'Network error');
					});
			}
		});
	}

	var delBtn = qs('#leadwerk-webp-delete-ajax');
	var delCb = qs('#leadwerk-webp-delete-confirm-ajax');
	if (delBtn && delCb) {
		delBtn.addEventListener('click', function () {
			if (!delCb.checked) {
				alert('Bitte „Loeschen bestaetigen“ aktivieren.');
				return;
			}
			if (!window.confirm('Alte JPEG/PNG-Anhaenge gemaess Manifest loeschen?')) {
				return;
			}
			delBtn.disabled = true;
			setProgress(true, 'Loeschen…');
			post('leadwerk_webp_body_delete', { confirm: '1' })
				.then(function (res) {
					delBtn.disabled = false;
					if (!res.success) {
						handleError(res);
						return;
					}
					setProgress(true, 'Geloescht: ' + (res.data.deleted || 0) + ', uebersprungen: ' + (res.data.skipped || 0));
					window.location.reload();
				})
				.catch(function () {
					delBtn.disabled = false;
					setProgress(true, 'Network error');
				});
		});
	}

	function runFieldRemap(dryRun) {
		var btnDry = qs('#leadwerk-webp-field-dry');
		var btnLive = qs('#leadwerk-webp-field-live');
		function dis(x) {
			if (btnDry) {
				btnDry.disabled = x;
			}
			if (btnLive) {
				btnLive.disabled = x;
			}
		}
		dis(true);
		setProgress(true, 'Feld-Remap…');
		var initFields = dryRun ? { dry_run: '1' } : {};
		post('leadwerk_webp_body_field_remap_init', initFields)
			.then(function (res) {
				if (!res.success) {
					dis(false);
					handleError(res);
					return;
				}
				function step() {
					return post('leadwerk_webp_body_field_remap_step', {}).then(function (r2) {
						if (!r2.success) {
							dis(false);
							handleError(r2);
							return;
						}
						var j = r2.data && r2.data.job ? r2.data.job : {};
						var pc = j.post_cursor != null ? j.post_cursor : 0;
						var pt = j.post_ids && j.post_ids.length ? j.post_ids.length : 0;
						var oc = j.option_cursor != null ? j.option_cursor : 0;
						var ot = j.option_names && j.option_names.length ? j.option_names.length : 0;
						setProgress(true, 'Feld-Remap… Posts ' + pc + '/' + pt + ', Optionen ' + oc + '/' + ot);
						if (j.status === 'completed') {
							dis(false);
							setProgress(true, j.message || (cfg.strings && cfg.strings.done) || 'OK');
							window.location.reload();
							return;
						}
						return step();
					});
				}
				return step();
			})
			.catch(function () {
				dis(false);
				setProgress(true, 'Network error');
			});
	}

	var fd = qs('#leadwerk-webp-field-dry');
	var fl = qs('#leadwerk-webp-field-live');
	if (fd) {
		fd.addEventListener('click', function () {
			runFieldRemap(true);
		});
	}
	if (fl) {
		fl.addEventListener('click', function () {
			if (!window.confirm('Feld-Remap Live ausfuehren? Meta/Optionen werden geschrieben.')) {
				return;
			}
			runFieldRemap(false);
		});
	}
})();
