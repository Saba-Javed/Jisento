'use strict';

var fs = require('fs');
var path = require('path');

var target = process.env.ADMIN_JS ? path.resolve(process.env.ADMIN_JS) : path.join(__dirname, '..', 'admin', 'js', 'admin.js');
var source = fs.readFileSync(target, 'utf8');
var failed = 0;

function check(name, ok, detail) {
	if (ok) {
		console.log('OK  ' + name);
		return;
	}
	failed += 1;
	console.log('FAIL ' + name + (detail ? ' — ' + detail : ''));
}

function count(re) {
	var matches = source.match(re);
	return matches ? matches.length : 0;
}

console.log('Checking ' + target + '\n');

check('no .innerHTML assignments', count(/\.innerHTML\s*\+?=(?!=)/g) === 0, count(/\.innerHTML\s*\+?=(?!=)/g) + ' found');
check('no insertAdjacentHTML', count(/insertAdjacentHTML/g) === 0, count(/insertAdjacentHTML/g) + ' found');
check('no outerHTML', count(/outerHTML/g) === 0, count(/outerHTML/g) + ' found');
check('no sessionStorage (job tokens must survive a reload)', count(/sessionStorage/g) === 0, count(/sessionStorage/g) + ' found');
check('plainError does not parse HTML through a live element', /DOMParser\(\)\.parseFromString\(/.test(source));
check('uses localStorage', /localStorage/.test(source));
check('uses textContent', /textContent/.test(source));
check('stores the active job id', source.indexOf("'jisento-active-job'") !== -1);
check('stores job tokens under jisento-job-token:', source.indexOf("'jisento-job-token:'") !== -1);
check('non-JSON bodies report a PHP error', source.indexOf("'The server returned a PHP error (HTTP '") !== -1);
check('retry uses the retry route', /'jobs\/'\s*\+[^;\n]*\+\s*'\/retry'/.test(source));
check('placeholder repair route is present', /'jobs\/'\s*\+[^;\n]*\+\s*'\/repair-placeholders'/.test(source));
check('debug log route is present', /jobRoute\(id, 'log'\)|'\/log'/.test(source));
check('users_replaced shows the source login hint', source.indexOf("Log in with the SOURCE site's username and password.") !== -1);
check('validation card says format marker, not signature', /Format marker/.test(source) && !/' signature</.test(source));
check('import sends replace_tables and replace_guids', /replace_tables/.test(source) && /replace_guids/.test(source));
check('HTTP 409 on job creation is handled', /status === 409/.test(source));
check('esc() helper removed', !/function esc\(/.test(source));

var helpers = null;
try {
	delete require.cache[require.resolve(target)];
	helpers = require(target);
} catch (e) {
	helpers = null;
}
var hasHelpers = !!(helpers && typeof helpers.nonJsonMessage === 'function' && typeof helpers.jobIdFromPath === 'function');
check('pure helpers are exported', hasHelpers);

if (hasHelpers) {
	check('jobIdFromPath reads job routes', helpers.jobIdFromPath('jobs/abc_123XYZ/retry') === 'abc_123XYZ' && helpers.jobIdFromPath('jobs/abc_123') === 'abc_123');
	check('jobIdFromPath ignores non-job routes', helpers.jobIdFromPath('jobs') === '' && helpers.jobIdFromPath('backups') === '' && helpers.jobIdFromPath('') === '');
	check(
		'nonJsonMessage on a job route names the job',
		helpers.nonJsonMessage(500, 'jobs/job_abcdef12') === 'The server returned a PHP error (HTTP 500). Check error_log. Job: job_abcdef12'
	);
	check('nonJsonMessage on other routes', helpers.nonJsonMessage(500, 'jobs') === 'The server returned a PHP error (HTTP 500). Check error_log.');
	if (typeof helpers.isTransient === 'function') {
		check('gateway errors are transient', [502, 503, 504, 524].every(function (s) {
			return helpers.isTransient({ status: s, message: 'x' });
		}));
		check('network and timeouts are transient', helpers.isTransient({ network: true }) && helpers.isTransient({ timeout: true }) && helpers.isTransient(new Error('Failed to fetch')));
		check('PHP fatal and 409 are not transient', !helpers.isTransient({ status: 500, message: 'The server returned a PHP error (HTTP 500). Check error_log.' }) && !helpers.isTransient({ status: 409, message: 'busy' }));
	}
	if (typeof helpers.csv === 'function') {
		var parsed = helpers.csv(' wp_posts, ,wp_postmeta ');
		check('csv splits table names', parsed.length === 2 && parsed[0] === 'wp_posts' && parsed[1] === 'wp_postmeta');
	}
}

console.log(failed ? '\n' + failed + ' failed' : '\nAdmin JS checks passed');
process.exit(failed ? 1 : 0);
