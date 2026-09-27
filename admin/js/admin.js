(function () {
	'use strict';

	const TOKEN_PREFIX = 'jisento-job-token:';
	const ACTIVE_JOB_KEY = 'jisento-active-job';
	const TRANSIENT_STATUSES = [502, 503, 504, 524];

	function jobIdFromPath(path) {
		const match = /^jobs\/([A-Za-z0-9_]+)/.exec(String(path || ''));
		return match ? match[1] : '';
	}

	function nonJsonMessage(status, path) {
		const id = jobIdFromPath(path);
		return 'The server returned a PHP error (HTTP ' + status + '). Check error_log.' + (id ? ' Job: ' + id : '');
	}

	function isTransient(err) {
		if (!err) {
			return false;
		}
		if (err.timeout || err.network) {
			return true;
		}
		if (err.status) {
			return TRANSIENT_STATUSES.indexOf(Number(err.status)) !== -1;
		}
		const message = String(err.message ? err.message : err);
		return /504|502|503|524|timeout|timed out|Gateway|Failed to fetch|NetworkError|Load failed|AbortError|aborted/i.test(message);
	}

	function csv(val) {
		return String(val || '')
			.split(',')
			.map(function (s) {
				return s.trim();
			})
			.filter(Boolean);
	}

	const helpers = {
		jobIdFromPath: jobIdFromPath,
		nonJsonMessage: nonJsonMessage,
		isTransient: isTransient,
		csv: csv
	};
	if (typeof module === 'object' && module.exports) {
		module.exports = helpers;
	}
	if (typeof window !== 'undefined') {
		window.JisentoAdminHelpers = helpers;
	}

	if (typeof jisentoAdmin === 'undefined') {
		return;
	}

	const jobTokens = {};

	function appendChildren(node, children) {
		if (children == null || children === false || children === '') {
			return node;
		}
		if (Array.isArray(children)) {
			children.forEach(function (child) {
				appendChildren(node, child);
			});
			return node;
		}
		if (children instanceof Node) {
			node.appendChild(children);
			return node;
		}
		node.appendChild(document.createTextNode(String(children)));
		return node;
	}

	function el(tag, attrs, children) {
		const node = document.createElement(tag);
		const values = attrs || {};
		Object.keys(values).forEach(function (key) {
			const value = values[key];
			if (value == null || value === false) {
				return;
			}
			if (key.indexOf('on') === 0 && typeof value === 'function') {
				node.addEventListener(key.slice(2).toLowerCase(), value);
			} else if (key === 'className') {
				node.className = value;
			} else if (key === 'style' && typeof value === 'object') {
				Object.assign(node.style, value);
			} else if (key === 'hidden' || key === 'checked' || key === 'disabled') {
				node[key] = !!value;
			} else {
				node.setAttribute(key, value === true ? '' : String(value));
			}
		});
		return appendChildren(node, children);
	}

	function fill(target, children) {
		if (!target) {
			return;
		}
		target.replaceChildren();
		appendChildren(target, children);
	}

	function plainError(value, fallback) {
		let text = '';
		if (value && typeof value.message === 'string') {
			text = value.message;
		} else if (typeof value === 'string') {
			text = value;
		}
		if (/<[a-z][\s\S]*>/i.test(text)) {
			text = new DOMParser().parseFromString(text, 'text/html').body.textContent || '';
		}
		text = String(text || '').replace(/\s+/g, ' ').trim();
		return text || fallback || 'The server returned an empty error response.';
	}

	function storageGet(key) {
		try {
			return localStorage.getItem(key) || '';
		} catch (e) {
			return '';
		}
	}

	function storageSet(key, value) {
		try {
			localStorage.setItem(key, value);
		} catch (e) {}
	}

	function storageRemove(key) {
		try {
			localStorage.removeItem(key);
		} catch (e) {}
	}

	function rememberJob(job) {
		if (!job || !job.job_id || !job.continuation_token) {
			return;
		}
		jobTokens[job.job_id] = job.continuation_token;
		storageSet(TOKEN_PREFIX + job.job_id, job.continuation_token);
		storageSet(ACTIVE_JOB_KEY, JSON.stringify({ id: job.job_id, type: job.type || '' }));
	}

	function activeJob() {
		const raw = storageGet(ACTIVE_JOB_KEY);
		if (!raw) {
			return null;
		}
		try {
			const parsed = JSON.parse(raw);
			return parsed && parsed.id ? parsed : null;
		} catch (e) {
			return null;
		}
	}

	function forgetActiveJob(id, dropToken) {
		const active = activeJob();
		if (active && active.id === id) {
			storageRemove(ACTIVE_JOB_KEY);
		}
		if (dropToken) {
			delete jobTokens[id];
			storageRemove(TOKEN_PREFIX + id);
		}
	}

	function storedJobToken(id) {
		if (jobTokens[id]) {
			return jobTokens[id];
		}
		return storageGet(TOKEN_PREFIX + id);
	}

	function withJobAuth(id, options) {
		const opts = Object.assign({}, options || {});
		opts.jobAuth = true;
		opts.jobToken = storedJobToken(id);
		return opts;
	}

	function jobRoute(id, action) {
		return 'jobs/' + id + (action ? '/' + action : '');
	}

	const api = {
		async req(path, options) {
			const opts = options || {};
			const timeout = opts.timeout || 0;
			const headers = Object.assign({}, opts.headers || {});
			if (opts.jobAuth) {
				if (!opts.jobToken) {
					const missing = new Error('This job can no longer be controlled from this browser because its job token is missing. Job: ' + (jobIdFromPath(path) || 'unknown'));
					missing.missingToken = true;
					throw missing;
				}
				headers['X-Jisento-Job-Token'] = opts.jobToken;
			} else {
				headers['X-WP-Nonce'] = jisentoAdmin.nonce;
			}
			let body = opts.body;
			if (body && !(body instanceof FormData) && !(body instanceof Blob) && !(ArrayBuffer.isView(body)) && !(body instanceof ArrayBuffer) && !headers['Content-Type']) {
				headers['Content-Type'] = 'application/json';
				body = JSON.stringify(body);
			}
			const sep = path.indexOf('?') === -1 ? '?' : '&';
			const fetchOpts = {
				method: opts.method || 'GET',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: headers
			};
			if (body) {
				fetchOpts.body = body;
			}
			let timer = null;
			if (timeout) {
				const controller = new AbortController();
				fetchOpts.signal = controller.signal;
				timer = setTimeout(function () {
					controller.abort();
				}, timeout);
			}
			let res;
			let text = '';
			try {
				res = await fetch(jisentoAdmin.root + path + sep + '_=' + Date.now(), fetchOpts);
				text = await res.text();
			} catch (err) {
				const aborted = err && err.name === 'AbortError';
				const netErr = new Error(aborted ? 'Request timed out' : ((err && err.message) || 'Failed to fetch'));
				netErr.timeout = aborted;
				netErr.network = !aborted;
				throw netErr;
			} finally {
				if (timer) {
					clearTimeout(timer);
				}
			}
			let data = {};
			if (text) {
				try {
					data = JSON.parse(text);
				} catch (e) {
					const phpErr = new Error(nonJsonMessage(res.status, path));
					phpErr.phpError = true;
					phpErr.status = res.status;
					phpErr.body = plainError(text.slice(0, 1000), '');
					throw phpErr;
				}
			}
			if (!res.ok) {
				const detail = plainError(data && data.message, 'The server returned an empty error response.');
				const err = new Error('Request failed (HTTP ' + res.status + ', ' + path + '): ' + detail);
				err.status = res.status;
				err.code = data && data.code ? data.code : '';
				err.detail = detail;
				err.data = data;
				throw err;
			}
			return data;
		}
	};

	let paused = false;
	let runGeneration = 0;
	let uploadedPackage = '';
	let remoteSession = null;

	function $(sel) {
		return document.querySelector(sel);
	}
	function show(node) {
		if (node) {
			node.hidden = false;
		}
	}
	function hide(node) {
		if (node) {
			node.hidden = true;
		}
	}
	function wait(ms) {
		return new Promise(function (r) {
			setTimeout(r, ms);
		});
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
		return map[stage] || stage || '';
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
	function percentValue(value) {
		const n = Number(value) || 0;
		return Math.max(0, Math.min(100, n));
	}

	function showNotice(anchor, message) {
		if (!anchor) {
			return;
		}
		const host = anchor.closest('p') || anchor;
		let notice = host.nextElementSibling;
		if (!notice || !notice.classList.contains('jisento-start-notice')) {
			notice = el('div', { className: 'notice notice-error inline jisento-start-notice', role: 'alert' });
			host.after(notice);
		}
		fill(notice, el('p', null, message));
		show(notice);
	}

	function clearNotice(anchor) {
		if (!anchor) {
			return;
		}
		const host = anchor.closest('p') || anchor;
		const notice = host.nextElementSibling;
		if (notice && notice.classList.contains('jisento-start-notice')) {
			notice.remove();
		}
	}

	async function createJob(body, button, noticeAnchor) {
		const anchor = noticeAnchor || button;
		if (button) {
			button.disabled = true;
		}
		clearNotice(anchor);
		let job = null;
		try {
			job = await api.req('jobs', { method: 'POST', body: body });
		} catch (err) {
			const message = err.status === 409 ? (err.detail || plainError(err, 'Another import or export is already running on this site.')) : plainError(err, 'The job could not be started.');
			showNotice(anchor, message);
			alert(message);
			return null;
		} finally {
			if (button) {
				button.disabled = false;
			}
		}
		rememberJob(job);
		runJob(job.job_id);
		return job;
	}

	function setStatusLine(box, text) {
		if (!box) {
			return;
		}
		let line = box.querySelector('.jisento-reconnect');
		if (!line) {
			line = el('p', { className: 'jisento-reconnect', role: 'status' });
			box.appendChild(line);
		}
		line.textContent = text;
	}

	function renderProgress(job) {
		const box = $('#jisento-progress');
		if (!box) {
			return;
		}
		show(box);
		const act = (job.state && job.state.activity) || {};
		const label = act.label || stageLabel(job.stage);
		const pct = percentValue(job.progress);
		const parts = [];
		const titles = headlines(job);
		parts.push(el('h2', null, titles.running));
		if (job.worker_mode === 'browser') {
			parts.push(el('p', { className: 'jisento-notice jisento-warning', id: 'jisento-loopback-notice' }, 'Server loopback is blocked on this host. Keep this tab open — it drives each migration step.'));
		}
		if (job.stalled) {
			parts.push(el('p', { className: 'jisento-notice jisento-warning', id: 'jisento-stalled-notice' }, 'This job looks stalled (no heartbeat for about 2 minutes).'));
		}
		parts.push(el('p', null, el('strong', null, label)));
		if (act.detail) {
			parts.push(el('p', null, act.detail));
		}
		if (act.count_total > 0 && act.count_unit) {
			parts.push(el('p', null, act.count_unit + ': ' + num(act.count_done) + ' / ' + num(act.count_total) + (act.count_suffix ? ' ' + act.count_suffix : '')));
		} else if (act.count_done > 0 && act.count_unit) {
			parts.push(el('p', null, act.count_unit + ': ' + num(act.count_done)));
		}
		if (act.item && act.item !== act.detail) {
			parts.push(el('p', null, 'Current: ' + act.item));
		}
		const sizes = (job.state && job.state.sizes) || act.sizes || {};
		if (sizes.package > 0) {
			parts.push(el('p', null, 'Package: ' + bytes(sizes.package)));
		}
		if (sizes.database > 0) {
			parts.push(el('p', null, 'Database: ' + bytes(sizes.database)));
		}
		if (sizes.files > 0) {
			parts.push(el('p', null, 'Files: ' + bytes(sizes.files)));
		}
		if (sizes.contents > 0) {
			parts.push(el('p', null, 'Uncompressed contents: ' + bytes(sizes.contents)));
		}
		if (act.measure_kind === 'bytes' && act.measure_total > 0) {
			parts.push(el('p', null, (act.measure_label || 'Progress') + ': ' + bytes(act.measure_done || 0) + ' / ' + bytes(act.measure_total)));
		}
		parts.push(el('div', { className: 'jisento-progress-bar' }, el('span', { style: { width: pct + '%' } })));
		parts.push(el('p', null, 'Overall Progress: ' + pct + '%'));
		if (typeof act.stage_progress === 'number') {
			parts.push(el('p', null, 'This stage: ' + percentValue(act.stage_progress) + '%'));
		}
		parts.push(el('p', null, 'Last update: ' + (job.updated_at || '—')));
		const id = job.job_id;
		const controls = [
			el('button', {
				type: 'button',
				className: 'button',
				id: 'jisento-pause',
				onClick: async function (e) {
					paused = true;
					e.currentTarget.disabled = true;
					try {
						const latest = await api.req(jobRoute(id, 'pause'), withJobAuth(id, { method: 'POST', body: {} }));
						showJob(latest && latest.job_id ? latest : await api.req(jobRoute(id), withJobAuth(id, { method: 'GET', timeout: 20000 })));
					} catch (err) {
						setStatusLine(box, plainError(err, 'Pause failed.'));
					}
				}
			}, 'Pause'),
			' ',
			el('button', {
				type: 'button',
				className: 'button',
				id: 'jisento-cancel',
				onClick: async function (e) {
					paused = true;
					e.currentTarget.disabled = true;
					try {
						await api.req(jobRoute(id, 'cancel'), withJobAuth(id, { method: 'POST', body: {} }));
						forgetActiveJob(id, false);
						fill(box, el('p', null, 'Cancelled.'));
					} catch (err) {
						setStatusLine(box, plainError(err, 'Cancel failed.'));
					}
				}
			}, 'Cancel')
		];
		if (job.stalled) {
			controls.push(' ');
			controls.push(el('button', {
				type: 'button',
				className: 'button button-primary',
				id: 'jisento-resume-stalled',
				onClick: async function (e) {
					const button = e.currentTarget;
					button.disabled = true;
					try {
						const resumed = await api.req(jobRoute(id, 'resume'), withJobAuth(id, { method: 'POST', body: {} }));
						if (resumed && resumed.job_id) {
							storageSet(ACTIVE_JOB_KEY, JSON.stringify({ id: id, type: resumed.type || job.type || '' }));
						}
						paused = false;
						runJob(id);
					} catch (err) {
						button.disabled = false;
						setStatusLine(box, plainError(err, 'Resume failed.'));
					}
				}
			}, 'Resume'));
		}
		parts.push(el('p', null, controls));
		fill(box, parts);
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
			fill(box, el('div', { className: 'jisento-card' }, [
				el('h2', null, headlines(job).complete),
				el('p', null, ['Name: ', el('code', null, job.package_name || '')]),
				el('p', null, 'Size: ' + bytes(job.package_size)),
				el('p', null, 'Status: Completed'),
				el('p', null, 'Saved in wp-content/jisento/packages/'),
				el('p', null, el('a', { className: 'button button-primary', href: dl }, 'Download'))
			]));
			loadBackups().catch(function (err) {
				const table = $('#jisento-backups-table');
				if (table) {
					fill(table.querySelector('tbody'), messageRow(plainError(err, 'Could not load backups.')));
				}
			});
			return;
		}
		const report = (job.state && job.state.report) || {};
		const done = headlines(job);
		const isImport = job.type === 'import' || job.type === 'receive';
		fill(box, el('div', { className: 'jisento-card' }, [
			el('h2', null, done.complete),
			isImport && report.users_replaced === true
				? el('p', { className: 'jisento-login-hint' }, el('strong', null, "Log in with the SOURCE site's username and password."))
				: null,
			el('p', null, 'Source: ' + (report.source || '')),
			el('p', null, 'Destination: ' + (report.destination || jisentoAdmin.home)),
			report.package ? el('p', null, ['Package: ', el('code', null, report.package)]) : null,
			timingLines(report.timings),
			el('p', null, [
				el('a', { className: 'button button-primary', href: jisentoAdmin.home, target: '_blank', rel: 'noopener' }, 'Open Website'),
				' ',
				el('a', { className: 'button', href: 'admin.php?page=jisento-logs' }, done.report)
			])
		]));
	}

	async function downloadDebugLog(id, fallbackHref) {
		try {
			const data = await api.req(jobRoute(id, 'log'), withJobAuth(id, { method: 'GET', timeout: 60000 }));
			if (!data || typeof data.text !== 'string') {
				throw new Error('The log response was empty.');
			}
			const url = URL.createObjectURL(new Blob([data.text], { type: 'text/plain;charset=utf-8' }));
			const link = el('a', { href: url, download: 'jisento-' + id + '-debug-log.txt', hidden: true });
			document.body.appendChild(link);
			link.click();
			link.remove();
			setTimeout(function () {
				URL.revokeObjectURL(url);
			}, 1000);
		} catch (err) {
			window.location.href = fallbackHref;
		}
	}

	function renderFailed(job) {
		const box = $('#jisento-result');
		hide($('#jisento-progress'));
		show(box);
		const id = job.job_id;
		const serverFailed = job.status === 'failed';
		const problem = plainError(job && job.error_summary, 'Unknown error. Check the Jisento debug log and the server PHP error log.');
		const placeholders = job.state && job.state.v1_placeholders && typeof job.state.v1_placeholders === 'object' ? job.state.v1_placeholders : null;
		const status = el('p', { className: 'jisento-action-status', role: 'status' });
		const logsHref = 'admin.php?page=jisento-logs';

		async function recover(route, button, label) {
			button.disabled = true;
			status.textContent = label;
			try {
				if (route) {
					await api.req(route, withJobAuth(id, { method: 'POST', body: {}, timeout: 60000 }));
				}
				paused = false;
				hide(box);
				runJob(id);
			} catch (err) {
				button.disabled = false;
				status.textContent = plainError(err, 'The request failed.');
			}
		}

		const children = [
			el('h2', null, headlines(job).failed),
			el('p', null, 'Stage: ' + stageLabel(job.stage)),
			el('p', null, 'Problem: ' + problem)
		];
		if (placeholders && id) {
			children.push(el('div', { className: 'jisento-card jisento-warning jisento-placeholder-warning' }, [
				el('p', null, 'This package was created by an older Jisento version that replaced every % character with a placeholder. Re-export the source site with this version (recommended), or repair the placeholders and continue.'),
				placeholders.tokens || placeholders.occurrences
					? el('p', null, 'Placeholder tokens: ' + num(placeholders.tokens) + ', occurrences: ' + num(placeholders.occurrences))
					: null,
				el('p', null, el('button', {
					type: 'button',
					className: 'button',
					id: 'jisento-repair-placeholders',
					onClick: function (e) {
						recover('jobs/' + id + '/repair-placeholders', e.currentTarget, 'Repairing placeholders…');
					}
				}, 'Repair placeholders and continue'))
			]));
		}
		children.push(el('p', null, [
			id ? el('button', {
				type: 'button',
				className: 'button button-primary',
				id: 'jisento-retry',
				onClick: function (e) {
					recover(serverFailed ? 'jobs/' + id + '/retry' : '', e.currentTarget, 'Retrying…');
				}
			}, 'Retry') : null,
			' ',
			el('a', {
				className: 'button',
				href: logsHref,
				onClick: function (e) {
					if (!id) {
						return;
					}
					e.preventDefault();
					downloadDebugLog(id, logsHref);
				}
			}, 'Download Debug Log')
		]));
		children.push(status);
		fill(box, el('div', { className: 'jisento-card jisento-warning' }, children));
	}

	function renderInterrupted(job) {
		const box = $('#jisento-result');
		hide($('#jisento-progress'));
		show(box);
		const id = job.job_id;
		const titles = headlines(job);
		const status = el('p', { className: 'jisento-action-status', role: 'status' });
		fill(box, el('div', { className: 'jisento-card' }, [
			el('h2', null, titles.paused),
			el('p', null, 'Progress: ' + percentValue(job.progress) + '%'),
			el('p', null, [
				el('button', {
					type: 'button',
					className: 'button button-primary',
					id: 'jisento-resume',
					onClick: async function (e) {
						const button = e.currentTarget;
						button.disabled = true;
						try {
							const resumed = await api.req(jobRoute(id, 'resume'), withJobAuth(id, { method: 'POST', body: {} }));
							if (resumed && resumed.job_id) {
								storageSet(ACTIVE_JOB_KEY, JSON.stringify({ id: id, type: resumed.type || job.type || '' }));
							}
							paused = false;
							hide(box);
							runJob(id);
						} catch (err) {
							button.disabled = false;
							status.textContent = plainError(err, 'Resume failed.');
						}
					}
				}, titles.resume),
				' ',
				el('button', {
					type: 'button',
					className: 'button',
					id: 'jisento-cancel-2',
					onClick: async function (e) {
						const button = e.currentTarget;
						button.disabled = true;
						try {
							await api.req(jobRoute(id, 'cancel'), withJobAuth(id, { method: 'POST', body: {} }));
							forgetActiveJob(id, false);
							fill(box, el('p', null, 'Cancelled.'));
						} catch (err) {
							button.disabled = false;
							status.textContent = plainError(err, 'Cancel failed.');
						}
					}
				}, titles.cancel)
			]),
			status
		]));
	}

	function timingLines(timings) {
		if (!timings) {
			return null;
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
		const lines = [];
		Object.keys(labels).forEach(function (key) {
			if (timings[key] > 0) {
				lines.push(el('p', null, labels[key] + ': ' + Math.round(timings[key]) + 's'));
			}
		});
		return lines;
	}

	function showJob(job) {
		if (!job) {
			return false;
		}
		if (job.status === 'completed') {
			forgetActiveJob(job.job_id, true);
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
			if (job.status === 'cancelled') {
				forgetActiveJob(job.job_id, false);
			}
			renderInterrupted(job);
			return true;
		}
		renderProgress(job);
		return false;
	}

	async function runJob(id, runOptions) {
		const settings = runOptions || {};
		const generation = ++runGeneration;
		paused = false;
		let lastJob = null;
		let misses = 0;

		function alive() {
			return !paused && generation === runGeneration;
		}

		async function reconnect(err) {
			misses++;
			if (lastJob) {
				renderProgress(lastJob);
			}
			const reason = err && err.status ? 'HTTP ' + err.status : (err && err.timeout ? 'request timed out' : 'network error');
			setStatusLine($('#jisento-progress'), 'Reconnecting... (' + reason + ', attempt ' + misses + ')');
			await wait(Math.min(15000, 2000 + misses * 1000));
		}

		try {
			while (alive()) {
				let job = null;
				try {
					job = await api.req(jobRoute(id), withJobAuth(id, { method: 'GET', timeout: 20000 }));
				} catch (err) {
					if (isTransient(err)) {
						await reconnect(err);
						continue;
					}
					throw err;
				}
				if (!alive()) {
					break;
				}
				misses = 0;
				lastJob = job;
				if (showJob(job)) {
					return job;
				}
				const browserWorker = job.worker_mode === 'browser';
				if (browserWorker) {
					let stepped = null;
					try {
						stepped = await api.req(jobRoute(id), withJobAuth(id, { method: 'POST', body: {}, timeout: 45000 }));
					} catch (err) {
						if (isTransient(err)) {
							await reconnect(err);
							continue;
						}
						throw err;
					}
					if (!alive()) {
						break;
					}
					misses = 0;
					if (stepped && stepped.worker_busy) {
						await wait(1500);
						continue;
					}
					if (stepped && stepped.job_id) {
						lastJob = stepped;
						if (showJob(stepped)) {
							return stepped;
						}
					}
					await wait(200);
					continue;
				}
				// Server worker: poll only. Status GET also kicks a stale step.
				await wait(2000);
			}
		} catch (err) {
			if (generation !== runGeneration) {
				return null;
			}
			if (settings.resumed && !lastJob && (err.missingToken || err.status === 401 || err.status === 403 || err.status === 404)) {
				forgetActiveJob(id, true);
				hide($('#jisento-progress'));
				return null;
			}
			renderFailed({
				stage: 'runtime',
				status: 'runtime_error',
				type: lastJob ? lastJob.type : (activeJob() && activeJob().id === id ? activeJob().type : ''),
				error_summary: plainError(err, 'The runtime request failed without an error message. Check the server PHP error log.'),
				job_id: id
			});
		}
		return null;
	}

	function resumeActiveJob() {
		if (!$('#jisento-progress')) {
			return;
		}
		const active = activeJob();
		if (!active || !storedJobToken(active.id)) {
			return;
		}
		runJob(active.id, { resumed: true });
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
		document.querySelectorAll('input[name="jisento_dest_mode"]').forEach(function (input) {
			input.checked = false;
		});
		const box = $('#jisento-validation');
		if (box) {
			show(box);
			const info = manifest || {};
			const items = [
				el('li', null, '✓ Format marker: ' + (info.format_marker || info.signature || 'JISENTO-PACKAGE-v1')),
				el('li', null, '✓ manifest.json'),
				el('li', null, '✓ Package version ' + (info.package_version || '1.0'))
			];
			if (info.database_size > 0) {
				items.push(el('li', null, 'Database: ' + bytes(info.database_size)));
			}
			if (info.files_size > 0) {
				items.push(el('li', null, 'Files: ' + bytes(info.files_size)));
			}
			if (info.uncompressed_size > 0) {
				items.push(el('li', null, 'Uncompressed contents: ' + bytes(info.uncompressed_size)));
			}
			fill(box, [
				el('h2', null, 'Package validated ✓'),
				el('ul', { className: 'jisento-steps' }, items),
				el('p', null, 'Choose how this site should handle the destination.')
			]);
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
		document.querySelectorAll('input[name="jisento_dest_mode"]').forEach(function (input) {
			input.checked = false;
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
			const startExport = $('#jisento-start-export');
			startExport.addEventListener('click', function () {
				hide(modal);
				createJob({
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
				}, startExport, open);
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
			const startBackup = $('#jisento-start-backup');
			startBackup.addEventListener('click', async function () {
				hide(bModal);
				const mode = (document.querySelector('input[name="jisento_backup_mode"]:checked') || {}).value || 'full';
				bOpen.disabled = true;
				try {
					await createJob(
						{ type: 'export', options: { mode: mode, backup_type: 'manual', skip_cache: true, skip_backups: true } },
						startBackup,
						bOpen
					);
				} finally {
					bOpen.disabled = false;
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
		document.querySelectorAll('input[name="jisento_dest_mode"]').forEach(function (input) {
			input.addEventListener('change', toggleModeUi);
		});
		const start = $('#jisento-start-import');
		if (start) {
			start.addEventListener('click', function () {
				const mode = destMode();
				if (!mode) {
					alert('Choose Replace Destination or Preserve Destination.');
					return;
				}
				const confirmReplace = $('#jisento-confirm-replace');
				if (mode === 'replace' && !(confirmReplace && confirmReplace.checked)) {
					alert('Please confirm that you understand this will replace the destination site.');
					return;
				}
				if (!uploadedPackage) {
					alert('Select or upload a package first.');
					return;
				}
				const strategy = document.querySelector('input[name="jisento_plugin_strategy"]:checked');
				const theme = document.querySelector('input[name="jisento_theme_strategy"]:checked');
				const replaceUrls = $('#jisento-replace-urls');
				const sourceUrl = $('#jisento-source-url');
				const destUrl = $('#jisento-dest-url');
				const options = {
					package: uploadedPackage,
					destination_mode: mode,
					confirm_replace: mode === 'replace' && !!(confirmReplace && confirmReplace.checked),
					safety_backup: false,
					replace_urls: replaceUrls ? replaceUrls.checked : true,
					source_url: sourceUrl ? sourceUrl.value : '',
					dest_url: destUrl ? destUrl.value : '',
					plugin_strategy: strategy ? strategy.value : 'keep_destination',
					theme_strategy: theme ? theme.value : 'keep_destination'
				};
				const replaceTables = $('#jisento-replace-tables');
				if (replaceTables) {
					options.replace_tables = csv(replaceTables.value);
				}
				const replaceGuids = $('#jisento-replace-guids');
				if (replaceGuids) {
					options.replace_guids = !!replaceGuids.checked;
				}
				const repair = $('#jisento-repair-placeholders');
				options.repair_placeholders = !!(repair && repair.checked);
				const engines = $('#jisento-restore-engines');
				options.restore_original_engines = !!(engines && engines.checked);
				document.querySelectorAll('.jisento-preserve').forEach(function (cb) {
					options[cb.getAttribute('data-key')] = cb.checked;
				});
				createJob({ type: 'import', options: options }, start);
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
					alert(plainError(err, 'The package could not be validated.'));
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
			const available = Array.isArray(rows)
				? rows.filter(function (r) {
					return r.available;
				})
				: [];
			if (!available.length) {
				fill(box, el('p', null, 'No local backups found.'));
				return;
			}
			fill(box, available.map(function (row) {
				return el('label', { className: 'jisento-choice' }, [
					el('input', {
						type: 'radio',
						name: 'jisento_existing',
						value: row.storage_key || '',
						'data-manifest': encodeURIComponent(JSON.stringify(row.manifest || {}))
					}),
					' ' + (row.name || '') + ' — ' + (row.size_label || '') + ' (' + (row.type_label || '') + ')'
				]);
			}));
		} catch (err) {
			fill(box, el('p', null, 'Could not load backups: ' + plainError(err, '')));
		}
	}

	function messageRow(text) {
		return el('tr', null, el('td', { colspan: '6' }, text));
	}

	async function loadBackups() {
		const table = $('#jisento-backups-table');
		if (!table) {
			return;
		}
		const rows = await api.req('backups');
		const tbody = table.querySelector('tbody');
		if (!Array.isArray(rows)) {
			fill(tbody, messageRow('Could not load backups.'));
			return;
		}
		if (!rows.length) {
			fill(tbody, messageRow('No backups yet.'));
			return;
		}
		fill(tbody, rows.map(function (row) {
			const dl = row.id
				? window.jisentoDownloadBase + '&id=' + encodeURIComponent(row.id)
				: window.jisentoDownloadBase + '&file=' + encodeURIComponent(row.storage_key || row.name || '');
			const actions = [];
			if (row.available) {
				actions.push(
					el('a', { className: 'button', href: dl }, 'Download'),
					' ',
					el('button', {
						type: 'button',
						className: 'button',
						onClick: function () {
							window.location = 'admin.php?page=jisento-import&package=' + encodeURIComponent(row.storage_key || '');
						}
					}, 'Import'),
					' '
				);
			} else {
				actions.push(
					el('em', null, 'Backup unavailable'),
					' ',
					el('button', {
						type: 'button',
						className: 'button',
						onClick: function () {
							const open = $('#jisento-open-backup');
							if (open) {
								open.click();
							}
						}
					}, 'Recreate Backup'),
					' '
				);
			}
			actions.push(el('button', {
				type: 'button',
				className: 'button',
				onClick: async function (e) {
					if (!confirm('Delete this backup?')) {
						return;
					}
					const button = e.currentTarget;
					button.disabled = true;
					try {
						await api.req('backups/delete', {
							method: 'POST',
							body: { id: row.id || '', name: row.name || '' }
						});
					} catch (err) {
						button.disabled = false;
						alert(plainError(err, 'The backup could not be deleted.'));
						return;
					}
					loadBackups().catch(function () {});
				}
			}, 'Delete'));
			const statusText = (row.status_label || (row.available ? 'Completed' : 'Missing / corrupted')) + (!row.available && row.reason ? ' — ' + row.reason : '');
			return el('tr', null, [
				el('td', null, row.name || ''),
				el('td', null, row.type_label || ''),
				el('td', null, row.size_label || ''),
				el('td', null, row.date_label || ''),
				el('td', null, statusText),
				el('td', null, actions)
			]);
		}));
	}

	async function loadKeys() {
		const table = $('#jisento-keys-table');
		if (!table) {
			return;
		}
		const rows = await api.req('keys');
		const tbody = table.querySelector('tbody');
		fill(tbody, (Array.isArray(rows) ? rows : []).map(function (row) {
			return el('tr', null, [
				el('td', null, row.key_hint == null ? '' : String(row.key_hint)),
				el('td', null, row.status || ''),
				el('td', null, row.expires_at || ''),
				el('td', null, row.status === 'active'
					? el('button', {
						type: 'button',
						className: 'button',
						onClick: async function (e) {
							e.currentTarget.disabled = true;
							try {
								await api.req('keys/' + encodeURIComponent(row.id) + '/revoke', { method: 'POST', body: {} });
							} catch (err) {
								alert(plainError(err, 'The key could not be revoked.'));
							}
							loadKeys().catch(function () {});
						}
					}, 'Revoke')
					: null)
			]);
		}));
	}

	function bindKeys() {
		const gen = $('#jisento-generate-key');
		if (gen) {
			gen.addEventListener('click', async function () {
				const box = $('#jisento-key-display');
				gen.disabled = true;
				let created;
				try {
					created = await api.req('keys', { method: 'POST', body: {} });
				} catch (err) {
					show(box);
					fill(box, el('p', null, plainError(err, 'The key could not be generated.')));
					return;
				} finally {
					gen.disabled = false;
				}
				show(box);
				fill(box, [
					el('div', null, 'Key:'),
					el('div', null, created.key || ''),
					el('p', null, 'Expires: ' + (created.expires_at || '')),
					el('p', null, ['Connection: ', el('code', null, created.connect || '')]),
					el('button', {
						type: 'button',
						className: 'button',
						id: 'jisento-revoke-new',
						onClick: async function () {
							try {
								await api.req('keys/' + encodeURIComponent(created.id) + '/revoke', { method: 'POST', body: {} });
								box.textContent = 'Key revoked.';
							} catch (err) {
								box.textContent = plainError(err, 'The key could not be revoked.');
							}
							loadKeys().catch(function () {});
						}
					}, 'Revoke Key')
				]);
				loadKeys().catch(function () {});
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
					const lines = (data.checks || []).map(function (c) {
						return el('p', null, (c.ok ? '✓' : '✗') + ' ' + (c.label || '') + (c.detail ? ' — ' + c.detail : ''));
					});
					if (data.ok) {
						lines.push(el('p', null, el('strong', null, 'Connection OK. You can continue to migration.')));
					}
					fill(box, lines);
				} catch (err) {
					box.textContent = plainError(err, 'The connection test failed.');
				}
			});
		}
		const connect = $('#jisento-connect');
		if (connect) {
			connect.addEventListener('click', async function () {
				let data;
				try {
					data = await api.req('connect', {
						method: 'POST',
						body: { source_url: $('#jisento-connect-url').value, key: $('#jisento-connect-key').value }
					});
				} catch (err) {
					alert(plainError(err, 'The connection failed.'));
					return;
				}
				remoteSession = data;
				const info = data.source || {};
				const box = $('#jisento-source-info');
				show(box);
				const continueRemote = el('button', { type: 'button', className: 'button button-primary', id: 'jisento-continue-remote' }, 'Continue to Migration');
				continueRemote.addEventListener('click', function () {
					const mode = destMode();
					if (mode === 'replace' && !confirm('This will replace the destination site. Continue?')) {
						return;
					}
					createJob({
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
					}, continueRemote);
				});
				fill(box, [
					el('h3', null, 'Source Site'),
					el('p', null, 'Domain: ' + (info.domain || '')),
					el('p', null, 'WordPress: ' + (info.wordpress_version || '')),
					el('p', null, 'PHP: ' + (info.php_version || '')),
					el('p', null, 'Database: ' + (info.database || '')),
					el('p', null, 'Files: ' + (info.files || '')),
					el('p', null, 'Total: ' + (info.total || '')),
					el('div', { className: 'jisento-grid' }, [
						el('label', { className: 'jisento-mode-card' }, [
							el('input', { type: 'radio', name: 'jisento_dest_mode', value: 'replace' }),
							' ',
							el('strong', null, 'Completely Replace Destination')
						]),
						el('label', { className: 'jisento-mode-card' }, [
							el('input', { type: 'radio', name: 'jisento_dest_mode', value: 'preserve', checked: true }),
							' ',
							el('strong', null, 'Preserve Existing Destination')
						])
					]),
					el('p', null, continueRemote)
				]);
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
				form.querySelectorAll('input, select').forEach(function (input) {
					if (!input.name) {
						return;
					}
					data[input.name] = input.type === 'checkbox' ? input.checked : input.value;
				});
				try {
					await api.req('settings', { method: 'POST', body: data });
					alert('Settings saved.');
				} catch (err) {
					alert(plainError(err, 'Settings could not be saved.'));
				}
			});
		}
		const diag = $('#jisento-run-diagnostics');
		if (diag) {
			diag.addEventListener('click', async function () {
				const box = $('#jisento-diagnostics');
				box.textContent = 'Running…';
				try {
					const data = await api.req('diagnostics');
					fill(box, (data.items || []).map(function (i) {
						return el('p', null, [
							(i.ok ? '✓' : '✗') + ' ',
							el('strong', null, (i.label || '') + ':'),
							' ' + (i.value == null ? '' : String(i.value))
						]);
					}));
				} catch (err) {
					box.textContent = plainError(err, 'Diagnostics failed.');
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
				fill(table.querySelector('tbody'), messageRow(plainError(err, 'Could not load backups.')));
			}
		});
		loadKeys().catch(function () {});
		loadExistingBackups().catch(function () {});
		resumeActiveJob();
		const params = new URLSearchParams(window.location.search);
		if (params.get('package') && $('#jisento-dest-mode')) {
			api.req('packages/validate', { method: 'POST', body: { package: params.get('package') } })
				.then(function (res) {
					return showValidatedPackage(res.package, res.manifest);
				})
				.catch(function (err) {
					alert(plainError(err, 'The package could not be validated.'));
				});
		}
	});
})();
