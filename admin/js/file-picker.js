/**
 * Import file picker. Selection, chunk progress, and errors share one state object.
 * Progress is updated only from byte counts the server confirms.
 */
(function (root, factory) {
	if (typeof module === 'object' && module.exports) {
		module.exports = factory();
	} else {
		root.JisentoFilePicker = factory();
	}
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
	'use strict';

	var NO_FILE = 'No file selected. Please choose a .jisento backup.';
	var WRONG_TYPE = 'Please choose a .jisento backup.';
	var EMPTY_FILE = 'The selected file is empty.';
	var UPLOAD_FAILED = 'Upload failed. Please try again.';
	var UPLOAD_INTERRUPTED = 'Upload interrupted. Press Resume to continue.';
	var DISK_FULL = 'Not enough free disk space for this package.';
	var LOST_SESSION = 'Upload session lost. Choose the file again and press Upload.';
	var CHUNK_RETRIES = 'A chunk failed after 3 retries. Press Resume to try again.';
	var DEFAULT_CHUNK = 8 * 1048576;
	var MIN_CHUNK = 1 * 1048576;
	var MAX_CONCURRENT = 2;

	function createState() {
		return {
			phase: 'empty',
			file: null,
			received: 0,
			total: 0,
			message: '',
			error: false,
			resumable: false
		};
	}

	function isJisentoName(name) {
		return /\.jisento$/i.test(String(name || ''));
	}

	function selectFile(file) {
		if (!file) {
			return createState();
		}
		if (!isJisentoName(file.name)) {
			return {
				phase: 'error',
				file: null,
				received: 0,
				total: 0,
				message: WRONG_TYPE,
				error: true,
				resumable: false
			};
		}
		if (!file.size) {
			return {
				phase: 'error',
				file: null,
				received: 0,
				total: 0,
				message: EMPTY_FILE,
				error: true,
				resumable: false
			};
		}
		return {
			phase: 'selected',
			file: file,
			received: 0,
			total: file.size,
			message: '',
			error: false,
			resumable: false
		};
	}

	function noFileError() {
		return {
			phase: 'error',
			file: null,
			received: 0,
			total: 0,
			message: NO_FILE,
			error: true,
			resumable: false
		};
	}

	function withProgress(state, received, total) {
		return {
			phase: 'uploading',
			file: state.file,
			received: received,
			total: total,
			message: '',
			error: false,
			resumable: false
		};
	}

	function percent(received, total) {
		if (!total || total < 1) {
			return 0;
		}
		return Math.min(100, Math.round((received / total) * 100));
	}

	function confirmedReceived(response, previous) {
		if (!response || response.received == null || !isFinite(Number(response.received))) {
			return null;
		}
		var received = Number(response.received);
		if (received <= previous) {
			return null;
		}
		return received;
	}

	function failureMessage(err) {
		var name = err && err.name ? err.name : '';
		var message = err && err.message ? String(err.message) : '';
		var status = err && err.status ? Number(err.status) : 0;
		var detail = err && err.detail ? String(err.detail) : message;
		if (status === 410 || /session expired|session lost/i.test(detail)) {
			return LOST_SESSION;
		}
		if (status === 507 || /disk space/i.test(detail)) {
			return DISK_FULL;
		}
		if (err && err.chunkRetries) {
			return CHUNK_RETRIES;
		}
		if (name === 'AbortError' || message === 'Request timed out' || message === 'Failed to fetch') {
			return message === 'Request timed out' ? UPLOAD_INTERRUPTED : UPLOAD_FAILED;
		}
		if (!message || message === 'Request failed') {
			return UPLOAD_FAILED;
		}
		if (/Request failed \(HTTP/.test(message) && detail) {
			return detail;
		}
		return message;
	}

	function resumeKeyFor(file) {
		return 'jisento-upload:' + file.name + ':' + file.size;
	}

	function readResumeId(file) {
		try {
			return sessionStorage.getItem(resumeKeyFor(file)) || '';
		} catch (e) {
			return '';
		}
	}

	function writeResumeId(file, id) {
		try {
			sessionStorage.setItem(resumeKeyFor(file), id);
		} catch (e) {}
	}

	function clearResumeId(file) {
		try {
			sessionStorage.removeItem(resumeKeyFor(file));
		} catch (e) {}
	}

	function hexFromBuffer(buf) {
		var bytes = new Uint8Array(buf);
		var out = '';
		for (var i = 0; i < bytes.length; i++) {
			out += (bytes[i] < 16 ? '0' : '') + bytes[i].toString(16);
		}
		return out;
	}

	async function sha256Hex(blob) {
		if (!rootCryptoSubtle()) {
			throw new Error(UPLOAD_FAILED);
		}
		var buffer = await blob.arrayBuffer();
		var digest = await rootCryptoSubtle().digest('SHA-256', buffer);
		return hexFromBuffer(digest);
	}

	function rootCryptoSubtle() {
		var c = typeof crypto !== 'undefined' ? crypto : (typeof window !== 'undefined' ? window.crypto : null);
		return c && c.subtle ? c.subtle : null;
	}

	function pendingOffsets(size, chunkSize, ranges) {
		var out = [];
		for (var offset = 0; offset < size; ) {
			var end = Math.min(size, offset + chunkSize);
			var done = false;
			(ranges || []).forEach(function (r) {
				if (Number(r[0]) <= offset && Number(r[1]) >= end) {
					done = true;
				}
			});
			if (!done) {
				out.push(offset);
			}
			offset = end;
		}
		return out;
	}

	function mount(rootEl, options) {
		if (!rootEl) {
			return;
		}
		var request = options.request;
		var formatBytes = options.formatBytes;
		var onReset = options.onReset || function () {};
		var onUploaded = options.onUploaded || function () {};
		var input = rootEl.querySelector('input[type="file"]');
		var button = rootEl.querySelector('[data-jisento-upload]');
		var selected = rootEl.querySelector('[data-jisento-selected]');
		var selectedName = rootEl.querySelector('[data-jisento-name]');
		var selectedSize = rootEl.querySelector('[data-jisento-size]');
		var status = rootEl.querySelector('[data-jisento-status]');
		var bytesLine = rootEl.querySelector('[data-jisento-bytes]');
		var bar = rootEl.querySelector('[data-jisento-bar]');
		var state = createState();
		var generation = 0;
		var chunkSizeRemembered = 0;

		function paint() {
			var file = state.file;
			if (selected) {
				selected.hidden = !file;
			}
			if (file && selectedName) {
				selectedName.textContent = file.name;
			}
			if (file && selectedSize) {
				selectedSize.textContent = 'Size: ' + formatBytes(file.size);
			}
			if (button) {
				var busy = state.phase === 'uploading' || state.phase === 'validating' || state.phase === 'ready';
				button.disabled = !file || busy;
				button.textContent = state.resumable && state.phase === 'error' ? 'Resume' : 'Upload';
			}
			if (status) {
				status.classList.toggle('is-error', !!state.error);
				if (state.phase === 'uploading') {
					status.textContent = 'Uploading... ' + percent(state.received, state.total) + '%';
				} else if (state.phase === 'complete' || state.phase === 'ready') {
					status.textContent = 'Upload complete';
				} else if (state.phase === 'validating') {
					status.textContent = 'Validating package...';
				} else {
					status.textContent = state.message || '';
				}
			}
			if (bytesLine) {
				var showBytes = state.phase === 'uploading' || state.phase === 'complete' || state.phase === 'validating' || state.phase === 'ready';
				bytesLine.hidden = !showBytes;
				if (showBytes) {
					bytesLine.textContent = formatBytes(state.received) + ' / ' + formatBytes(state.total);
				}
			}
			if (bar) {
				var showBar = state.phase === 'uploading' || state.phase === 'complete' || state.phase === 'validating' || state.phase === 'ready';
				bar.hidden = !showBar;
				var span = bar.querySelector('span');
				if (span) {
					span.style.width = percent(state.received, state.total) + '%';
				}
			}
		}

		function current() {
			return generation;
		}

		async function sendChunk(uploadId, file, offset, size, token) {
			var end = Math.min(file.size, offset + size);
			var blob = file.slice(offset, end);
			var sha = await sha256Hex(blob);
			if (token !== current()) {
				return null;
			}
			return request('upload/chunk?upload_id=' + encodeURIComponent(uploadId) + '&offset=' + offset, {
				method: 'POST',
				body: blob,
				headers: {
					'Content-Type': 'application/octet-stream',
					'X-Jisento-Chunk-SHA256': sha
				},
				timeout: 120000
			});
		}

		async function uploadOneWithRetry(uploadId, file, offset, size, token) {
			var attempt = 0;
			var currentSize = size;
			while (attempt < 3) {
				attempt += 1;
				try {
					return await sendChunk(uploadId, file, offset, currentSize, token);
				} catch (err) {
					if (token !== current()) {
						throw err;
					}
					if (err && Number(err.status) === 413 && currentSize > MIN_CHUNK) {
						currentSize = Math.max(MIN_CHUNK, Math.floor(currentSize / 2));
						chunkSizeRemembered = currentSize;
						await request('upload/init', {
							method: 'POST',
							body: {
								filename: file.name,
								size: file.size,
								upload_id: uploadId,
								chunk: currentSize
							}
						});
						attempt -= 1;
						continue;
					}
					if (attempt >= 3) {
						err.chunkRetries = true;
						throw err;
					}
				}
			}
			var fail = new Error(CHUNK_RETRIES);
			fail.chunkRetries = true;
			throw fail;
		}

		async function upload(file, token) {
			var saved = readResumeId(file);
			var init = await request('upload/init', {
				method: 'POST',
				body: {
					filename: file.name,
					size: file.size,
					upload_id: saved,
					chunk: chunkSizeRemembered || DEFAULT_CHUNK
				}
			});
			if (token !== current()) {
				return null;
			}
			writeResumeId(file, init.upload_id);
			var chunkSize = Number(init.chunk) || chunkSizeRemembered || DEFAULT_CHUNK;
			chunkSizeRemembered = chunkSize;
			var ranges = init.ranges || [];
			var received = Number(init.received) || 0;
			state = withProgress(state, received, file.size);
			paint();

			var queue = pendingOffsets(file.size, chunkSize, ranges);
			var cursor = 0;
			var active = 0;
			var fatal = null;

			await new Promise(function (resolve, reject) {
				function pump() {
					if (fatal) {
						reject(fatal);
						return;
					}
					if (token !== current()) {
						resolve(null);
						return;
					}
					while (active < MAX_CONCURRENT && cursor < queue.length) {
						(function (offset) {
							active += 1;
							uploadOneWithRetry(init.upload_id, file, offset, chunkSize, token)
								.then(function (result) {
									active -= 1;
									if (!result || token !== current()) {
										pump();
										return;
									}
									var next = confirmedReceived(result, state.received);
									if (next != null) {
										state = withProgress(state, next, Number(result.size) || file.size);
										paint();
									}
									if (result.chunk) {
										chunkSizeRemembered = Number(result.chunk) || chunkSizeRemembered;
									}
									pump();
								})
								.catch(function (err) {
									active -= 1;
									fatal = err;
									pump();
								});
						})(queue[cursor++]);
					}
					if (!fatal && active === 0 && cursor >= queue.length) {
						resolve(true);
					}
				}
				pump();
			});

			if (token !== current()) {
				return null;
			}
			state.phase = 'complete';
			state.received = file.size;
			state.total = file.size;
			state.message = '';
			state.error = false;
			paint();
			await new Promise(function (resolve) {
				setTimeout(resolve, 300);
			});
			if (token !== current()) {
				return null;
			}
			state.phase = 'validating';
			paint();
			var done = await request('upload/complete', {
				method: 'POST',
				body: { upload_id: init.upload_id },
				timeout: 180000
			});
			if (token !== current()) {
				return null;
			}
			clearResumeId(file);
			return done;
		}

		input.addEventListener('change', function () {
			generation += 1;
			onReset();
			var file = input.files && input.files[0] ? input.files[0] : null;
			state = selectFile(file);
			if (file && readResumeId(file)) {
				state.resumable = true;
				state.message = UPLOAD_INTERRUPTED;
			}
			paint();
		});

		button.addEventListener('click', function (event) {
			event.preventDefault();
			var file = state.file;
			if (!file) {
				state = noFileError();
				paint();
				return;
			}
			var token = generation;
			state = withProgress(state, state.received || 0, file.size);
			paint();
			upload(file, token)
				.then(function (done) {
					if (!done || token !== current()) {
						return null;
					}
					return Promise.resolve(onUploaded(done)).then(function () {
						if (token !== current()) {
							return;
						}
						state.phase = 'ready';
						state.error = false;
						state.message = '';
						state.resumable = false;
						paint();
					});
				})
				.catch(function (err) {
					if (token !== current()) {
						return;
					}
					state.phase = 'error';
					state.error = true;
					state.message = failureMessage(err);
					state.resumable = state.message === UPLOAD_INTERRUPTED || state.message === CHUNK_RETRIES || Number(err && err.status) !== 410;
					if (state.message === LOST_SESSION) {
						clearResumeId(file);
						state.resumable = false;
					}
					paint();
				});
		});

		if (input.form) {
			input.form.addEventListener('submit', function (event) {
				if (!state.file || state.phase === 'uploading' || state.phase === 'validating') {
					event.preventDefault();
				}
			});
		}

		paint();
	}

	return {
		createState: createState,
		selectFile: selectFile,
		noFileError: noFileError,
		percent: percent,
		confirmedReceived: confirmedReceived,
		failureMessage: failureMessage,
		pendingOffsets: pendingOffsets,
		mount: mount,
		messages: {
			noFile: NO_FILE,
			wrongType: WRONG_TYPE,
			emptyFile: EMPTY_FILE,
			uploadFailed: UPLOAD_FAILED,
			uploadInterrupted: UPLOAD_INTERRUPTED,
			diskFull: DISK_FULL,
			lostSession: LOST_SESSION,
			chunkRetries: CHUNK_RETRIES
		}
	};
});
