'use strict';

var picker = require('../admin/js/file-picker.js');
var failed = 0;

function check(name, ok, detail) {
	if (ok) {
		console.log('OK  ' + name);
		return;
	}
	failed += 1;
	console.log('FAIL ' + name + (detail ? ' — ' + detail : ''));
}

var bigName = 'silentiumtechnologies.com-2026-09-23-0015.jisento';
var bigSize = 234689003;
var selected = picker.selectFile({ name: bigName, size: bigSize });
check('a jisento file is selected and progress starts at zero', selected.phase === 'selected' && selected.file.name === bigName && selected.total === bigSize && selected.received === 0 && selected.error === false);

var replaced = picker.selectFile({ name: 'other-site.jisento', size: 1024 });
check('choosing another file replaces the previous selection', replaced.file.name === 'other-site.jisento' && replaced.received === 0 && replaced.total === 1024);

var wrong = picker.selectFile({ name: 'backup.zip', size: 100 });
check('a non-jisento file is rejected', wrong.phase === 'error' && wrong.file === null && wrong.message === picker.messages.wrongType);

var empty = picker.selectFile({ name: 'empty.jisento', size: 0 });
check('an empty file is rejected', empty.message === picker.messages.emptyFile && empty.file === null);

check('no file uses the required message', picker.noFileError().message === picker.messages.noFile);

var received = 0;
var chunk = 8 * 1048576;
var steps = 0;
while (received < bigSize) {
	var next = Math.min(bigSize, received + chunk);
	var confirmed = picker.confirmedReceived({ received: next, size: bigSize }, received);
	if (confirmed !== next) {
		break;
	}
	received = confirmed;
	steps += 1;
}
check(
	'chunk progress follows server byte counts for a 224 MB package',
	received === bigSize && steps > 20 && picker.percent(Math.round(bigSize * 0.27), bigSize) === 27,
	'received=' + received + ' steps=' + steps
);
check('a chunk that does not advance is not shown as progress', picker.confirmedReceived({ received: 100 }, 100) === null);
check('a missing server count is not shown as progress', picker.confirmedReceived({}, 0) === null);
check('network failure has a visible message', picker.failureMessage({ name: 'TypeError', message: 'Failed to fetch' }) === picker.messages.uploadFailed);
check('a timeout says the upload was interrupted', picker.failureMessage({ name: 'AbortError', message: 'Request timed out' }) === picker.messages.uploadInterrupted);
check('a server validation error is kept', picker.failureMessage({ message: 'Invalid Jisento Package. The file is missing.' }) === 'Invalid Jisento Package. The file is missing.');
check('HTTP 410 is a lost session', picker.failureMessage({ status: 410, detail: 'Upload session expired. Please retry.', message: 'Request failed' }) === picker.messages.lostSession);
check('HTTP 507 is disk full', picker.failureMessage({ status: 507, detail: 'Not enough free disk space for this package.', message: 'Request failed' }) === picker.messages.diskFull);
check('three chunk failures use the retry message', picker.failureMessage({ chunkRetries: true, message: 'x' }) === picker.messages.chunkRetries);

var pending = picker.pendingOffsets(30, 10, [[0, 10], [20, 30]]);
check('pendingOffsets skips covered chunks and keeps the gap', pending.length === 1 && pending[0] === 10, JSON.stringify(pending));
check('pendingOffsets is empty when fully covered', picker.pendingOffsets(20, 10, [[0, 20]]).length === 0);
check('readPendingResume helper exported', typeof picker.readPendingResume === 'function');
check('clearResumeForMeta helper exported', typeof picker.clearResumeForMeta === 'function');

console.log(failed ? '\n' + failed + ' failed' : '\nFile picker checks passed');
process.exit(failed ? 1 : 0);
