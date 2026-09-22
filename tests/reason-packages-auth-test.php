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

require __DIR__ . '/../reason-packages-auth.php';

use function ReasonDev\PluginUpdateChecker\ReasonPackages\add_auth_header;

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

echo "\n" . ($failures === 0 ? 'ALL PASSED' : "$failures FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
