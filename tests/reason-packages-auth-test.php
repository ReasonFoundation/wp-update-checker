<?php
/**
 * Plain-PHP tests for reason-packages-auth.php.
 * Run: php tests/reason-packages-auth-test.php
 */

$failures = 0;
function check($name, $condition) {
	global $failures;
	echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
	if ( !$condition ) {
		$failures++;
	}
}

//Minimal WordPress stubs, defined before loading the file under test.
$registeredFilters = array();
$hostOverride = null;
function add_filter($tag, $callback, $priority = 10, $acceptedArgs = 1) {
	global $registeredFilters;
	$registeredFilters[] = array($tag, $callback, $priority, $acceptedArgs);
	return true;
}
function has_filter($tag, $callback = false) {
	global $registeredFilters;
	foreach ( $registeredFilters as $filter ) {
		if ( $filter[0] === $tag && $filter[1] === $callback ) {
			return $filter[2];
		}
	}
	return false;
}
function apply_filters($tag, $value) {
	global $hostOverride;
	if ( $tag === 'reason_packages_updates_hosts' && $hostOverride !== null ) {
		return $hostOverride;
	}
	return $value;
}

require __DIR__ . '/../reason-packages-auth.php';
//Simulate a second bundled copy of the library loading the same file again.
require __DIR__ . '/../reason-packages-auth.php';

use function ReasonDev\PluginUpdateChecker\ReasonPackages\add_auth_header;
use function ReasonDev\PluginUpdateChecker\ReasonPackages\filter_http_request_args;

$hosts   = array('packages.reason.com');
$metaUrl = 'https://packages.reason.com/my-plugin/?action=get_metadata&installed_version=1.0';
$dlUrl   = 'https://packages.reason.com/my-plugin/?action=download&slug=my-plugin&key=sekret';
$base    = array('timeout' => 3, 'headers' => array('Accept' => 'application/json'));

// --- add_auth_header ---
$out = add_auth_header($base, $metaUrl, 'sekret', $hosts);
check('adds bearer header to metadata request', $out['headers']['Authorization'] === 'Bearer sekret');
check('keeps existing headers', $out['headers']['Accept'] === 'application/json');
check('keeps other args', $out['timeout'] === 3);

check('trims key', add_auth_header($base, $metaUrl, "  sekret \n", $hosts)['headers']['Authorization'] === 'Bearer sekret');
check('empty key: unchanged', add_auth_header($base, $metaUrl, '', $hosts) === $base);
check('whitespace key: unchanged', add_auth_header($base, $metaUrl, '   ', $hosts) === $base);
check('non-string key: unchanged', add_auth_header($base, $metaUrl, true, $hosts) === $base);
check('other host: unchanged', add_auth_header($base, 'https://api.wordpress.org/plugins/update-check/1.1/', 'sekret', $hosts) === $base);
check('lookalike host: unchanged', add_auth_header($base, 'https://packages.reason.com.evil.example/x/', 'sekret', $hosts) === $base);
check('plain http: unchanged', add_auth_header($base, 'http://packages.reason.com/my-plugin/?action=get_metadata', 'sekret', $hosts) === $base);
check('download URL with key param: unchanged', add_auth_header($base, $dlUrl, 'sekret', $hosts) === $base);
check('host match is case-insensitive', isset(add_auth_header($base, 'https://Packages.Reason.COM/my-plugin/', 'sekret', $hosts)['headers']['Authorization']));

$withAuth = array('headers' => array('authorization' => 'Basic abc'));
check('existing Authorization (any case) kept', add_auth_header($withAuth, $metaUrl, 'sekret', $hosts) === $withAuth);

$noHeaders = add_auth_header(array(), $metaUrl, 'sekret', $hosts);
check('creates headers array when missing', $noHeaders['headers'] === array('Authorization' => 'Bearer sekret'));

$stringHeaders = array('headers' => "Accept: application/json\r\n");
check('string-form headers: unchanged', add_auth_header($stringHeaders, $metaUrl, 'sekret', $hosts) === $stringHeaders);

// --- registration ---
$callback = 'ReasonDev\\PluginUpdateChecker\\ReasonPackages\\filter_http_request_args';
check('registered exactly once', count($registeredFilters) === 1);
check('registered on http_request_args, priority 10, 2 args', $registeredFilters[0] === array('http_request_args', $callback, 10, 2));

// --- filter_http_request_args ---
check('constant undefined: unchanged', filter_http_request_args($base, $metaUrl) === $base);

define('REASON_PACKAGES_UPDATES_KEY', 'sekret');
check('constant defined: header added', filter_http_request_args($base, $metaUrl)['headers']['Authorization'] === 'Bearer sekret');
check('constant defined, download URL: unchanged', filter_http_request_args($base, $dlUrl) === $base);

$stagingUrl = 'https://abc123.execute-api.us-east-1.amazonaws.com/my-plugin/?action=get_metadata';
check('staging host not allowed by default', filter_http_request_args($base, $stagingUrl) === $base);
$hostOverride = array('packages.reason.com', 'abc123.execute-api.us-east-1.amazonaws.com');
check('host filter extends allowlist', isset(filter_http_request_args($base, $stagingUrl)['headers']['Authorization']));
$hostOverride = 'not-an-array';
check('bad host filter value: unchanged', filter_http_request_args($base, $metaUrl) === $base);
$hostOverride = null;

echo "\n" . ($failures === 0 ? 'ALL PASSED' : "$failures FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
