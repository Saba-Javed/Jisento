(function () {
	'use strict';

	if (typeof jisentoAdmin === 'undefined') {
		return;
	}

	const jobTokens = {};

	function plainError(value, fallback) {
		let text = '';
		if (value && typeof value.message === 'string') {
			text = value.message;
		} else if (typeof value === 'string') {
			text = value;
		}
		if (/<[a-z][\s\S]*>/i.test(text)) {
			const wrapper = document.createElement('div');
			wrapper.innerHTML = text;
			text = wrapper.textContent || wrapper.innerText || '';
		}
		text = String(text || '').replace(/\s+/g, ' ').trim();
		return text || fallback || 'The server returned an empty error response.';
	}

	function rememberJob(job) {
		if (!job || !job.job_id || !job.continuation_token) {
			return;
		}
		jobTokens[job.job_id] = job.continuation_token;
		try {
			sessionStorage.setItem('jisento-job-token:' + job.job_id, job.continuation_token);
		} catch (e) {}
	}

	function storedJobToken(id) {
		if (jobTokens[id]) {
			return jobTokens[id];
		}
		try {
			return sessionStorage.getItem('jisento-job-token:' + id) || '';
		} catch (e) {
			return '';
		}
	}

	function withJobAuth(id, options) {
		const opts = Object.assign({}, options || {});
		const token = storedJobToken(id);
		if (token) {
			opts.jobToken = token;
		}
		return opts;
	}

	const api = {
		async req(path, options) {
			const opts = options || {};
			const timeout = opts.timeout || 0;
			const headers = Object.assign({}, opts.headers || {});
			if (opts.jobToken) {
				headers['X-Jisento-Job-Token'] = opts.jobToken;
			} else {
				headers['X-WP-Nonce'] = jisentoAdmin.nonce;
			}
			if (opts.body && !(opts.body instanceof FormData) && !headers['Content-Type']) {
				headers['Content-Type'] = 'application/json';
				opts.body = JSON.stringify(opts.body);
			}
			const sep = path.indexOf('?') === -1 ? '?' : '&';
			const fetchOpts = {
				method: opts.method || 'GET',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: headers
			};
			if (opts.body) {
				fetchOpts.body = opts.body;
			}
			if (timeout) {
				const controller = new AbortController();
				fetchOpts.signal = controller.signal;
				setTimeout(function () {
					controller.abort();
				}, timeout);
			}
			let res;
			try {
				res = await fetch(jisentoAdmin.root + path + sep + '_=' + Date.now(), fetchOpts);
			} catch (err) {
				const name = err && err.name ? err.name : '';
				throw new Error(name === 'AbortError' ? 'Request timed out' : ((err && err.message) || 'Failed to fetch'));
			}
			const text = await res.text();
			let data = {};
			try {
				data = text ? JSON.parse(text) : {};
			} catch (e) {
				data = { message: plainError(text.slice(0, 1000), '') };
			}
			if (!res.ok) {
				const detail = plainError(data.message, 'The server returned an empty error response.');
				throw new Error('Request failed (HTTP ' + res.status + ', ' + path + '): ' + detail);
			}
			return data;
		}
	};

	let currentJob = null;
	let paused = false;
	let uploadedPackage = '';
	let remoteSession = null;

	function $(sel) {
		return document.querySelector(sel);
	}
	function show(el) {
		if (el) {
			el.hidden = false;
		}
	}
	function hide(el) {
		if (el) {
			el.hidden = true;
		}
	}
	function headlines(job) {
		const type = job && job.type;
		const remote = job && job.state && job.state.remote && (job.state.remote.source_url || job.state.remote.session_id);
		if (type === 'export') {
			return {
				running: 'Export in Progress',
				complete: 'Export Completed',
				failed: 'Export Failed',
				paused: 'Export Paused',
				resume: 'Resume Export',
				cancel: 'Cancel Export',
				report: 'View Export Log'
			};
		}
		if (type === 'receive' || remote) {
			return {
				running: 'Migration in Progress',
				complete: 'Migration Completed Successfully',
				failed: 'Migration Failed',
				paused: 'Migration Interrupted',
				resume: 'Resume Migration',
				cancel: 'Cancel Migration',
				report: 'View Migration Report'
			};
		}
		return {
			running: 'Import in Progress',
			complete: 'Import Completed',
			failed: 'Import Failed',
			paused: 'Import Paused',
			resume: 'Resume Import',
			cancel: 'Cancel Import',
			report: 'View Import Log'
		};
	}

	function stageLabel(stage) {
		const map = {
			created: 'Preparing website',
			preparing: 'Preparing website',
			exporting_database: 'Exporting database',
			exporting_files: 'Exporting wp-content',
			packaging: 'Adding files to package',
			validating: 'Validating package',
			compatibility: 'Compatibility check',
			safety_backup: 'Creating safety backup',
			extracting: 'Extracting package',
			importing_database: 'Restoring database',
			importing_files: 'Restoring files',
			replacing_urls: 'Updating URLs',
			uploading: 'Transferring package',
			finalizing: 'Finalizing',
			completed: 'Backup completed',
			failed: 'Failed',
			cancelled: 'Cancelled',
			paused: 'Paused'
		};
		return map[stage] || stage;
	}
	function bytes(n) {
		n = Number(n) || 0;
		const u = ['B', 'KB', 'MB', 'GB', 'TB'];
		let i = 0;
		while (n >= 1024 && i < u.length - 1) {
			n /= 1024;
			i++;
		}
		return n.toFixed(2) + ' ' + u[i];
	}
	function num(n) {
		return Number(n || 0).toLocaleString();
	}
	function esc(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

	function renderProgress(job) {
		const box = $('#jisento-progress');
		if (!box) {
			return;
		}
		show(box);
		const act = (job.state && job.state.activity) || {};
		const label = act.label || stageLabel(job.stage);
		const pct = job.progress || 0;
		let counts = '';
		if (act.count_total > 0 && act.count_unit) {
			counts = '<p>' + esc(act.count_unit) + ': ' + num(act.count_done) + ' / ' + num(act.count_total) + (act.count_suffix ? ' ' + esc(act.count_suffix) : '') + '</p>';
		} else if (act.count_done > 0 && act.count_unit) {
			counts = '<p>' + esc(act.count_unit) + ': ' + num(act.count_done) + '</p>';
		}
		const detail = act.detail ? '<p>' + esc(act.detail) + '</p>' : '';
		const item = act.item && act.item !== act.detail ? '<p>Current: ' + esc(act.item) + '</p>' : '';
		const sizes = (job.state && job.state.sizes) || act.sizes || {};
		let sizeHtml = '';
		if (sizes.package > 0) {
			sizeHtml += '<p>Package: ' + bytes(sizes.package) + '</p>';
		}
		if (sizes.database > 0) {
			sizeHtml += '<p>Database: ' + bytes(sizes.database) + '</p>';
		}
		if (sizes.files > 0) {
			sizeHtml += '<p>Files: ' + bytes(sizes.files) + '</p>';
		}
		if (sizes.contents > 0) {
			sizeHtml += '<p>Uncompressed contents: ' + bytes(sizes.contents) + '</p>';
		}
		let measure = '';
		if (act.measure_kind === 'bytes' && act.measure_total > 0) {
			measure = '<p>' + esc(act.measure_label || 'Progress') + ': ' + bytes(act.measure_done || 0) + ' / ' + bytes(act.measure_total) + '</p>';
		}
		const stagePct = typeof act.stage_progress === 'number' ? '<p>This stage: ' + act.stage_progress + '%</p>' : '';
		const titles = headlines(job);
		box.innerHTML =
			'<h2>' + esc(titles.running) + '</h2>' +
			'<p><strong>' + esc(label) + '</strong></p>' +
			detail +
			counts +
			item +
			sizeHtml +
			measure +
			'<div class="jisento-progress-bar"><span style="width:' + pct + '%"></span></div>' +
			'<p>Overall Progress: ' + pct + '%</p>' +
			stagePct +
			'<p>Last update: ' + esc(job.updated_at || '—') + '</p>' +
			'<p><button type="button" class="button" id="jisento-pause">Pause</button> ' +
			'<button type="button" class="button" id="jisento-cancel">Cancel</button></p>';
		const pause = $('#jisento-pause');
		const cancel = $('#jisento-cancel');
		if (pause) {
			pause.addEventListener('click', async function () {
				paused = true;
				await api.req('jobs/' + job.job_id + '/pause', withJobAuth(job.job_id, { method: 'POST', body: {} }));
			});
		}
		if (cancel) {
			cancel.addEventListener('click', async function () {
				paused = true;
				await api.req('jobs/' + job.job_id + '/cancel', withJobAuth(job.job_id, { method: 'POST', body: {} }));
				box.innerHTML = '<p>Cancelled.</p>';
			});
		}
	}

	function renderComplete(job) {
		const box = $('#jisento-result');
		hide($('#jisento-progress'));
		show(box);
		if (job.type === 'export') {
			if (!job.package_ok || !(job.package_size > 0)) {
				renderFailed({
					type: 'export',
					stage: 'packaging',
					job_id: job.job_id,
					error_summary: job.error_summary || 'The .jisento file was not created.'
				});
				return;
			}
			const dl = (window.jisentoDownloadBase || '') + '&file=' + encodeURIComponent('packages/' + job.package_name);
			box.innerHTML =
				'<div class="jisento-card"><h2>' + esc(headlines(job).complete) + '</h2>' +
				'<p>Name: <code>' + job.package_name + '</code></p>' +
				'<p>Size: ' + bytes(job.package_size) + '</p>' +
				'<p>Status: Completed</p>' +
				'<p>Saved in wp-content/jisento/packages/</p>' +
				'<p><a class="button button-primary" href="' + dl + '">Download</a></p></div>';
			loadBackups().catch(function (err) {
				const table = $('#jisento-backups-table');
				if (table) {
					table.querySelector('tbody').innerHTML = '<tr><td colspan="6">' + err.message + '</td></tr>';
				}
			});
			return;
		}
		const report = (job.state && job.state.report) || {};
		const done = headlines(job);
		box.innerHTML =
			'<div class="jisento-card"><h2>' + esc(done.complete) + '</h2>' +
			'<p>Source: ' + (report.source || '') + '</p>' +
			'<p>Destination: ' + (report.destination || jisentoAdmin.home) + '</p>' +
			(report.package ? '<p>Package: <code>' + report.package + '</code></p>' : '') +
			timingLines(report.timings) +
			'<p><a class="button button-primary" href="' + jisentoAdmin.home + '" target="_blank">Open Website</a> ' +
			'<a class="button" href="admin.php?page=jisento-logs">' + esc(done.report) + '</a></p></div>';
	}

	function renderFailed(job) {
		const box = $('#jisento-result');
		hide($('#jisento-progress'));
		show(box);
		const problem = plainError(job && job.error_summary, 'Unknown error. Check the Jisento debug log and the server PHP error log.');
		box.innerHTML =
			'<div class="jisento-card jisento-warning"><h2>' + esc(headlines(job).failed) + '</h2>' +
			'<p>Stage: ' + stageLabel(job.stage) + '</p>' +
			'<p>Problem: ' + esc(problem) + '</p>' +
			'<p><button type="button" class="button button-primary" id="jisento-retry">Retry</button> ' +
			'<a class="button" href="admin.php?page=jisento-logs">Download Debug Log</a></p></div>';
		const retry = $('#jisento-retry');
		if (retry) {
			retry.addEventListener('click', function () {
				paused = false;
				runJob(job.job_id);
			});
		}
	}

	function renderInterrupted(job) {
		const box = $('#jisento-result');
		hide($('#jisento-progress'));
		show(box);
		const pausedTitles = headlines(job);
		box.innerHTML =
			'<div class="jisento-card"><h2>' + esc(pausedTitles.paused) + '</h2>' +
			'<p>Progress: ' + (job.progress || 0) + '%</p>' +
			'<p><button type="button" class="button button-primary" id="jisento-resume">' + esc(pausedTitles.resume) + '</button> ' +
			'<button type="button" class="button" id="jisento-cancel-2">' + esc(pausedTitles.cancel) + '</button></p></div>';
		$('#jisento-resume').addEventListener('click', async function () {
			paused = false;
			await api.req('jobs/' + job.job_id + '/resume', withJobAuth(job.job_id, { method: 'POST', body: {} }));
			hide(box);
			runJob(job.job_id);
		});
		$('#jisento-cancel-2').addEventListener('click', async function () {
			await api.req('jobs/' + job.job_id + '/cancel', withJobAuth(job.job_id, { method: 'POST', body: {} }));
			box.innerHTML = '<p>Cancelled.</p>';
		});
	}

	function isTransient(err) {
		const message = String(err && err.message ? err.message : err || '');
		return /504|502|503|524|timeout|timed out|Gateway|nginx|Failed to fetch|NetworkError|Load failed|AbortError|aborted/i.test(message);
	}

	function timingLines(timings) {
		if (!timings) {
			return '';
		}
		const labels = {
			validating: 'Validation',
			compatibility: 'Compatibility',
			extracting: 'Extraction',
			importing_database: 'Database restore',
			importing_files: 'File restore',
			replacing_urls: 'URL replacement',
			finalizing: 'Finalization'
		};
		let html = '';
		Object.keys(labels).forEach(function (key) {
			if (timings[key] > 0) {
				html += '<p>' + labels[key] + ': ' + Math.round(timings[key]) + 's</p>';
			}
		});
		return html;
	}

	function showJob(job) {
		if (!job) {
			return false;
		}
		if (job.status === 'completed') {
			if (job.type === 'export' && !job.package_ok) {
				renderFailed({
					type: 'export',
					stage: 'packaging',
					job_id: job.job_id,
					error_summary: job.error_summary || 'The .jisento file was not created.'
				});
				return true;
			}
			renderComplete(job);
			return true;
		}
		if (job.status === 'failed') {
			renderFailed(job);
			return true;
		}
		if (job.status === 'cancelled' || job.status === 'paused') {
			renderInterrupted(job);
			return true;
		}
		renderProgress(job);
		return false;
	}

	async function runJob(id) {
		currentJob = id;
		paused = false;
		let lastJob = null;
		let misses = 0;
		try {
			while (!paused) {
				let job = null;
				try {
					job = await api.req('jobs/' + id, withJobAuth(id, { method: 'GET', timeout: 20000 }));
					misses = 0;
				} catch (err) {
					if (isTransient(err) && misses < 12) {
						misses++;
						if (lastJob) {
							renderProgress(lastJob);
						}
						const box = $('#jisento-progress');
						if (box) {
							box.insertAdjacentHTML('beforeend', '<p>Reconnecting. The import is still running.</p>');
						}
						await new Promise(function (r) { setTimeout(r, 2000); });
						continue;
					}
					throw err;
				}
				lastJob = job;
				if (showJob(job)) {
					return job;
				}
				let stepped = null;
				try {
					stepped = await api.req('jobs/' + id, withJobAuth(id, { method: 'POST', body: {}, timeout: 45000 }));
					misses = 0;
				} catch (err) {
					if (isTransient(err) && misses < 12) {
						misses++;
						renderProgress(lastJob);
						const box = $('#jisento-progress');
						if (box) {
							box.insertAdjacentHTML('beforeend', '<p>The status request timed out. The import is still running.</p>');
						}
						await new Promise(function (r) { setTimeout(r, 2000); });
						continue;
					}
					throw err;
				}
				if (stepped && stepped.worker_busy) {
					await new Promise(function (r) { setTimeout(r, 1500); });
					continue;
				}
				if (stepped) {
					lastJob = stepped;
					if (showJob(stepped)) {
						return stepped;
					}
				}
				await new Promise(function (r) { setTimeout(r, 200); });
			}
		} catch (err) {
			renderFailed({
				stage: 'runtime',
				type: lastJob ? lastJob.type : '',
				error_summary: plainError(err, 'The runtime request failed without an error message. Check the server PHP error log.'),
				job_id: id
			});
		}
		return null;
	}

	function csv(val) {
		return (val || '')
			.split(',')
			.map(function (s) {
				return s.trim();
			})
			.filter(Boolean);
	}

	function destMode() {
		const checked = document.querySelector('input[name="jisento_dest_mode"]:checked');
		return checked ? checked.value : '';
	}

	function toggleModeUi() {
		const mode = destMode();
		hide($('#jisento-replace-warning'));
		hide($('#jisento-preserve-options'));
		hide($('#jisento-url-options'));
		if (mode === 'replace') {
			show($('#jisento-replace-warning'));
			show($('#jisento-url-options'));
		} else if (mode === 'preserve') {
			show($('#jisento-preserve-options'));
			show($('#jisento-url-options'));
		}
	}

	async function showValidatedPackage(pkg, manifest) {
		uploadedPackage = pkg;
		document.querySelectorAll('input[name="jisento_dest_mode"]').forEach(function (el) {
			el.checked = false;
		});
		const box = $('#jisento-validation');
		if (box) {
			show(box);
			const info = manifest || {};
			let sizes = '';
			if (info.database_size > 0) {
				sizes += '<li>Database: ' + bytes(info.database_size) + '</li>';
			}
			if (info.files_size > 0) {
				sizes += '<li>Files: ' + bytes(info.files_size) + '</li>';
			}
			if (info.uncompressed_size > 0) {
				sizes += '<li>Uncompressed contents: ' + bytes(info.uncompressed_size) + '</li>';
			}
			box.innerHTML =
				'<h2>Package validated ✓</h2>' +
				'<ul class="jisento-steps">' +
				'<li>✓ ' + esc(info.signature || 'JISENTO-PACKAGE-v1') + ' signature</li>' +
				'<li>✓ manifest.json</li>' +
				'<li>✓ Package version ' + esc((info.package_version) || '1.0') + '</li>' +
				sizes +
				'</ul>' +
				'<p>Choose how this site should handle the destination.</p>';
		}
		if ($('#jisento-source-url') && manifest && manifest.home_url) {
			$('#jisento-source-url').value = manifest.home_url;
		}
		show($('#jisento-dest-mode'));
		toggleModeUi();
	}

	function resetImportChoices() {
		uploadedPackage = '';
		hide($('#jisento-validation'));
		hide($('#jisento-dest-mode'));
		hide($('#jisento-replace-warning'));
		hide($('#jisento-preserve-options'));
		hide($('#jisento-url-options'));
		document.querySelectorAll('input[name="jisento_dest_mode"]').forEach(function (el) {
			el.checked = false;
		});
		const confirmReplace = $('#jisento-confirm-replace');
		if (confirmReplace) {
			confirmReplace.checked = false;
		}
	}

	function bindExport() {
		const open = $('#jisento-open-export');
		const modal = $('#jisento-export-modal');
		if (open && modal) {
			open.addEventListener('click', function () {
				show(modal);
			});
			modal.querySelectorAll('.jisento-close').forEach(function (btn) {
				btn.addEventListener('click', function () {
					hide(modal);
				});
			});
			$('#jisento-start-export').addEventListener('click', async function () {
				hide(modal);
				try {
					const job = await api.req('jobs', {
						method: 'POST',
						body: {
							type: 'export',
							options: {
								mode: 'full',
								backup_type: 'manual',
								skip_cache: $('#jisento-skip-cache') ? $('#jisento-skip-cache').checked : true,
								skip_backups: $('#jisento-skip-backups') ? $('#jisento-skip-backups').checked : true,
								exclude_plugins: csv($('#jisento-exclude-plugins') ? $('#jisento-exclude-plugins').value : ''),
								exclude_dirs: csv($('#jisento-exclude-dirs') ? $('#jisento-exclude-dirs').value : ''),
								exclude_tables: csv($('#jisento-exclude-tables') ? $('#jisento-exclude-tables').value : '')
							}
						}
					});
					rememberJob(job);
					runJob(job.job_id);
				} catch (err) {
					alert(err.message);
				}
			});
		}
		const bOpen = $('#jisento-open-backup');
		const bModal = $('#jisento-backup-modal');
		if (bOpen && bModal) {
			bOpen.addEventListener('click', function () {
				show(bModal);
			});
			bModal.querySelectorAll('.jisento-close').forEach(function (btn) {
				btn.addEventListener('click', function () {
					hide(bModal);
				});
			});
			$('#jisento-start-backup').addEventListener('click', async function () {
				hide(bModal);
				const mode = (document.querySelector('input[name="jisento_backup_mode"]:checked') || {}).value || 'full';
				try {
					const job = await api.req('jobs', {
						method: 'POST',
						body: { type: 'export', options: { mode: mode, backup_type: 'manual', skip_cache: true, skip_backups: true } }
					});
					rememberJob(job);
					runJob(job.job_id);
				} catch (err) {
					alert(err.message);
				}
			});
		}
	}

	function bindImport() {
		if (window.JisentoFilePicker && $('#jisento-file-picker')) {
			window.JisentoFilePicker.mount($('#jisento-file-picker'), {
				request: api.req.bind(api),
				formatBytes: bytes,
				onReset: resetImportChoices,
				onUploaded: function (done) {
					return showValidatedPackage(done.package, done.manifest);
				}
			});
		}
		document.querySelectorAll('input[name="jisento_dest_mode"]').forEach(function (el) {
			el.addEventListener('change', toggleModeUi);
		});
		const start = $('#jisento-start-import');
		if (start) {
			start.addEventListener('click', async function () {
				try {
					const mode = destMode();
					if (!mode) {
						alert('Choose Replace Destination or Preserve Destination.');
						return;
					}
					if (mode === 'replace' && !$('#jisento-confirm-replace').checked) {
						alert('Please confirm that you understand this will replace the destination site.');
						return;
					}
					if (!uploadedPackage) {
						alert('Select or upload a package first.');
						return;
					}
					const strategy = document.querySelector('input[name="jisento_plugin_strategy"]:checked');
					const theme = document.querySelector('input[name="jisento_theme_strategy"]:checked');
					const options = {
						package: uploadedPackage,
						destination_mode: mode,
						confirm_replace: mode === 'replace' && $('#jisento-confirm-replace').checked,
						safety_backup: false,
						replace_urls: $('#jisento-replace-urls').checked,
						source_url: $('#jisento-source-url').value,
						dest_url: $('#jisento-dest-url').value,
						plugin_strategy: strategy ? strategy.value : 'keep_destination',
						theme_strategy: theme ? theme.value : 'keep_destination'
					};
					document.querySelectorAll('.jisento-preserve').forEach(function (cb) {
						options[cb.getAttribute('data-key')] = cb.checked;
					});
					const job = await api.req('jobs', { method: 'POST', body: { type: 'import', options: options } });
					rememberJob(job);
					runJob(job.job_id);
				} catch (err) {
					alert(err.message);
				}
			});
		}
		const useExisting = $('#jisento-use-existing');
		if (useExisting) {
			useExisting.addEventListener('click', async function () {
				const selected = document.querySelector('input[name="jisento_existing"]:checked');
				if (!selected) {
					alert('Select an existing backup, or upload a package.');
					return;
				}
				try {
					let manifest = null;
					if (selected.getAttribute('data-manifest')) {
						manifest = JSON.parse(decodeURIComponent(selected.getAttribute('data-manifest')));
					}
					if (manifest && manifest.home_url && manifest.package_version) {
						await showValidatedPackage(selected.value, manifest);
					} else {
						const res = await api.req('packages/validate', { method: 'POST', body: { package: selected.value } });
						await showValidatedPackage(res.package, res.manifest);
					}
				} catch (err) {
					alert(err.message);
				}
			});
		}
	}

	async function loadExistingBackups() {
		const box = $('#jisento-existing-backups');
		if (!box) {
			return;
		}
		try {
			const rows = await api.req('backups');
			const available = rows.filter(function (r) {
				return r.available;
			});
			if (!available.length) {
				box.innerHTML = '<p>No local backups found.</p>';
				return;
			}
			box.innerHTML = available
				.map(function (row) {
					return (
						'<label class="jisento-choice"><input type="radio" name="jisento_existing" value="' +
						row.storage_key +
						'" data-manifest="' +
						encodeURIComponent(JSON.stringify(row.manifest || {})) +
						'"> ' +
						row.name +
						' — ' +
						row.size_label +
						' (' +
						row.type_label +
						')</label>'
					);
				})
				.join('');
		} catch (err) {
			box.innerHTML = '<p>Could not load backups: ' + err.message + '</p>';
		}
	}

	async function loadBackups() {
		const table = $('#jisento-backups-table');
		if (!table) {
			return;
		}
		const rows = await api.req('backups');
		const tbody = table.querySelector('tbody');
		tbody.innerHTML = '';
		if (!Array.isArray(rows)) {
			tbody.innerHTML = '<tr><td colspan="6">Could not load backups.</td></tr>';
			return;
		}
		if (!rows.length) {
			tbody.innerHTML = '<tr><td colspan="6">No backups yet.</td></tr>';
			return;
		}
		rows.forEach(function (row) {
			const tr = document.createElement('tr');
			const dl = row.id
				? window.jisentoDownloadBase + '&id=' + encodeURIComponent(row.id)
				: window.jisentoDownloadBase + '&file=' + encodeURIComponent(row.storage_key || row.name);
			let actions = '';
			if (row.available) {
				actions =
					'<a class="button" href="' +
					dl +
					'">Download</a> ' +
					'<button type="button" class="button" data-import="' +
					row.storage_key +
					'">Import</button> ';
			} else {
				actions = '<em>Backup unavailable</em> <button type="button" class="button" id="x" data-recreate="1">Recreate Backup</button> ';
			}
			actions += '<button type="button" class="button" data-del="' + (row.id || '') + '" data-name="' + row.name + '">Delete</button>';
			tr.innerHTML =
				'<td>' +
				esc(row.name) +
				'</td><td>' +
				esc(row.type_label || '') +
				'</td><td>' +
				esc(row.size_label || '') +
				'</td><td>' +
				esc(row.date_label || '') +
				'</td><td>' +
				esc(row.status_label || (row.available ? 'Completed' : 'Missing / corrupted')) +
				'</td><td>' +
				actions +
				'</td>';
			tbody.appendChild(tr);
		});
		tbody.querySelectorAll('[data-del]').forEach(function (btn) {
			btn.addEventListener('click', async function () {
				if (!confirm('Delete this backup?')) {
					return;
				}
				await api.req('backups/delete', {
					method: 'POST',
					body: { id: btn.getAttribute('data-del'), name: btn.getAttribute('data-name') }
				});
				loadBackups();
			});
		});
		tbody.querySelectorAll('[data-import]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				window.location = 'admin.php?page=jisento-import&package=' + encodeURIComponent(btn.getAttribute('data-import'));
			});
		});
		tbody.querySelectorAll('[data-recreate]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				const open = $('#jisento-open-backup');
				if (open) {
					open.click();
				}
			});
		});
	}

	async function loadKeys() {
		const table = $('#jisento-keys-table');
		if (!table) {
			return;
		}
		const rows = await api.req('keys');
		const tbody = table.querySelector('tbody');
		tbody.innerHTML = '';
		rows.forEach(function (row) {
			const tr = document.createElement('tr');
			tr.innerHTML =
				'<td>' +
				row.key_hint +
				'</td><td>' +
				row.status +
				'</td><td>' +
				row.expires_at +
				'</td><td>' +
				(row.status === 'active' ? '<button class="button" data-revoke="' + row.id + '">Revoke</button>' : '') +
				'</td>';
			tbody.appendChild(tr);
		});
		tbody.querySelectorAll('[data-revoke]').forEach(function (btn) {
			btn.addEventListener('click', async function () {
				await api.req('keys/' + btn.getAttribute('data-revoke') + '/revoke', { method: 'POST', body: {} });
				loadKeys();
			});
		});
	}

	function bindKeys() {
		const gen = $('#jisento-generate-key');
		if (gen) {
			gen.addEventListener('click', async function () {
				const created = await api.req('keys', { method: 'POST', body: {} });
				const box = $('#jisento-key-display');
				show(box);
				box.innerHTML =
					'<div>Key:</div><div>' +
					created.key +
					'</div><p>Expires: ' +
					created.expires_at +
					'</p><p>Connection: <code>' +
					created.connect +
					'</code></p><button type="button" class="button" id="jisento-revoke-new">Revoke Key</button>';
				$('#jisento-revoke-new').addEventListener('click', async function () {
					await api.req('keys/' + created.id + '/revoke', { method: 'POST', body: {} });
					box.textContent = 'Key revoked.';
					loadKeys();
				});
				loadKeys();
			});
		}
		const test = $('#jisento-test-connection');
		if (test) {
			test.addEventListener('click', async function () {
				const box = $('#jisento-test-results');
				show(box);
				box.textContent = 'Testing connection…';
				try {
					const data = await api.req('test-connection', {
						method: 'POST',
						body: { source_url: $('#jisento-connect-url').value, key: $('#jisento-connect-key').value }
					});
					box.innerHTML = (data.checks || [])
						.map(function (c) {
							return '<p>' + (c.ok ? '✓' : '✗') + ' ' + c.label + (c.detail ? ' — ' + c.detail : '') + '</p>';
						})
						.join('');
					if (data.ok) {
						box.innerHTML += '<p><strong>Connection OK. You can continue to migration.</strong></p>';
					}
				} catch (err) {
					box.textContent = err.message;
				}
			});
		}
		const connect = $('#jisento-connect');
		if (connect) {
			connect.addEventListener('click', async function () {
				try {
					const data = await api.req('connect', {
						method: 'POST',
						body: { source_url: $('#jisento-connect-url').value, key: $('#jisento-connect-key').value }
					});
					remoteSession = data;
					const info = data.source || {};
					const box = $('#jisento-source-info');
					show(box);
					box.innerHTML =
						'<h3>Source Site</h3>' +
						'<p>Domain: ' +
						(info.domain || '') +
						'</p><p>WordPress: ' +
						(info.wordpress_version || '') +
						'</p><p>PHP: ' +
						(info.php_version || '') +
						'</p><p>Database: ' +
						(info.database || '') +
						'</p><p>Files: ' +
						(info.files || '') +
						'</p><p>Total: ' +
						(info.total || '') +
						'</p>' +
						'<div class="jisento-grid">' +
						'<label class="jisento-mode-card"><input type="radio" name="jisento_dest_mode" value="replace"> <strong>Completely Replace Destination</strong></label>' +
						'<label class="jisento-mode-card"><input type="radio" name="jisento_dest_mode" value="preserve" checked> <strong>Preserve Existing Destination</strong></label>' +
						'</div><p><button type="button" class="button button-primary" id="jisento-continue-remote">Continue to Migration</button></p>';
					$('#jisento-continue-remote').addEventListener('click', async function () {
						const mode = destMode();
						if (mode === 'replace' && !confirm('This will replace the destination site. Continue?')) {
							return;
						}
						const job = await api.req('jobs', {
							method: 'POST',
							body: {
								type: 'receive',
								options: {
									destination_mode: mode,
									confirm_replace: mode === 'replace',
									safety_backup: false,
									replace_urls: true,
									source_url: info.home_url || $('#jisento-connect-url').value,
									dest_url: jisentoAdmin.home,
									session_id: data.session_id,
									token: data.token
								}
							}
						});
						rememberJob(job);
						runJob(job.job_id);
					});
				} catch (err) {
					alert(err.message);
				}
			});
		}
		const send = $('#jisento-open-key-send');
		if (send) {
			send.addEventListener('click', function () {
				window.location = 'admin.php?page=jisento-keys';
			});
		}
		const recv = $('#jisento-open-key-receive');
		if (recv) {
			recv.addEventListener('click', function () {
				window.location = 'admin.php?page=jisento-keys';
			});
		}
	}

	function bindSettings() {
		const form = $('#jisento-settings-form');
		if (form) {
			form.addEventListener('submit', async function (e) {
				e.preventDefault();
				const data = {};
				form.querySelectorAll('input, select').forEach(function (el) {
					if (!el.name) {
						return;
					}
					data[el.name] = el.type === 'checkbox' ? el.checked : el.value;
				});
				await api.req('settings', { method: 'POST', body: data });
				alert('Settings saved.');
			});
		}
		const diag = $('#jisento-run-diagnostics');
		if (diag) {
			diag.addEventListener('click', async function () {
				const box = $('#jisento-diagnostics');
				box.textContent = 'Running…';
				try {
					const data = await api.req('diagnostics');
					box.innerHTML = (data.items || [])
						.map(function (i) {
							return '<p>' + (i.ok ? '✓' : '✗') + ' <strong>' + i.label + ':</strong> ' + i.value + '</p>';
						})
						.join('');
				} catch (err) {
					box.textContent = err.message;
				}
			});
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		bindExport();
		bindImport();
		bindKeys();
		bindSettings();
		loadBackups().catch(function (err) {
			const table = $('#jisento-backups-table');
			if (table && table.querySelector('tbody')) {
				table.querySelector('tbody').innerHTML = '<tr><td colspan="6">' + err.message + '</td></tr>';
			}
		});
		loadKeys().catch(function () {});
		loadExistingBackups().catch(function () {});
		const params = new URLSearchParams(window.location.search);
		if (params.get('package') && $('#jisento-dest-mode')) {
			api.req('packages/validate', { method: 'POST', body: { package: params.get('package') } })
				.then(function (res) {
					return showValidatedPackage(res.package, res.manifest);
				})
				.catch(function (err) {
					alert(err.message);
				});
		}
	});
})();
