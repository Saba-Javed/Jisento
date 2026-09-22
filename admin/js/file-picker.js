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
	var UPLOAD_INTERRUPTED = 'Upload interrupted. Please try again.';

	function createState() {
		return {
			phase: 'empty',
			file: null,
			received: 0,
			total: 0,
			message: '',
			error: false
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
				error: true
			};
		}
		if (!file.size) {
			return {
				phase: 'error',
				file: null,
				received: 0,
				total: 0,
				message: EMPTY_FILE,
				error: true
			};
		}
		return {
			phase: 'selected',
			file: file,
			received: 0,
			total: file.size,
			message: '',
			error: false
		};
	}

	function noFileError() {
		return {
			phase: 'error',
			file: null,
			received: 0,
			total: 0,
			message: NO_FILE,
			error: true
		};
	}

	function withProgress(state, received, total) {
		return {
			phase: 'uploading',
			file: state.file,
			received: received,
			total: total,
			message: '',
			error: false
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
		if (name === 'AbortError' || message === 'Request timed out' || message === 'Failed to fetch') {
			return message === 'Request timed out' ? UPLOAD_INTERRUPTED : UPLOAD_FAILED;
		}
		if (!message || message === 'Request failed') {
			return UPLOAD_FAILED;
		}
		return message;
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
				button.disabled = !file || state.phase === 'uploading' || state.phase === 'validating' || state.phase === 'ready';
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

		async function upload(file, token) {
			var resumeKey = 'jisento-upload:' + file.name + ':' + file.size;
			var saved = '';
			try {
				saved = sessionStorage.getItem(resumeKey) || '';
			} catch (e) {
				saved = '';
			}
			var init = await request('upload/init', {
				method: 'POST',
				body: { filename: file.name, size: file.size, upload_id: saved }
			});
			if (token !== current()) {
				return null;
			}
			try {
				sessionStorage.setItem(resumeKey, init.upload_id);
			} catch (e) {}
			var chunkSize = init.chunk || (8 * 1048576);
			var offset = Number(init.received) || 0;
			state = withProgress(state, offset, file.size);
			paint();
			while (offset < file.size) {
				if (token !== current()) {
					return null;
				}
				var blob = file.slice(offset, offset + chunkSize);
				var fd = new FormData();
				fd.append('upload_id', init.upload_id);
				fd.append('offset', String(offset));
				fd.append('chunk', blob, 'chunk.bin');
				var result = await request('upload/chunk', { method: 'POST', body: fd, timeout: 120000 });
				if (token !== current()) {
					return null;
				}
				var received = confirmedReceived(result, offset);
				if (received == null) {
					throw new Error(UPLOAD_FAILED);
				}
				offset = received;
				state = withProgress(state, offset, Number(result.size) || file.size);
				paint();
			}
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
			try {
				sessionStorage.removeItem(resumeKey);
			} catch (e) {}
			return done;
		}

		input.addEventListener('change', function () {
			generation += 1;
			onReset();
			var file = input.files && input.files[0] ? input.files[0] : null;
			state = selectFile(file);
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
			state = withProgress(state, 0, file.size);
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
		mount: mount,
		messages: {
			noFile: NO_FILE,
			wrongType: WRONG_TYPE,
			emptyFile: EMPTY_FILE,
			uploadFailed: UPLOAD_FAILED,
			uploadInterrupted: UPLOAD_INTERRUPTED
		}
	};
});
