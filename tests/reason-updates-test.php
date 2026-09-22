<?php
/**
 * Plain-PHP tests for reason-updates.php.
 * Run: php tests/reason-updates-test.php
 */

namespace ReasonDev\PluginUpdateChecker\v5 {
	//Stand-in for the real factory: records what it was called with.
	class PucFactory {
		public static $calls = array();

		public static function buildUpdateChecker() {
			self::$calls[] = func_get_args();
			return 'CHECKER';
		}
	}
}

namespace {
	use ReasonDev\PluginUpdateChecker\ReasonUpdates;
	use ReasonDev\PluginUpdateChecker\v5\PucFactory;

	require __DIR__ . '/check.php';

	//Minimal WordPress stub, defined before loading the file under test.
	$urlFilter = null;
	function apply_filters($tag, $value) {
		global $urlFilter;
		if ( $tag === 'reason_packages_metadata_url' && $urlFilter !== null ) {
			return call_user_func_array($urlFilter, array_slice(func_get_args(), 1));
		}
		return $value;
	}

	function throws_invalid_argument($callback) {
		try {
			call_user_func($callback);
		} catch ( InvalidArgumentException $e ) {
			return true;
		}
		return false;
	}

	require __DIR__ . '/../reason-updates.php';
	//Simulate a second bundled copy of the library loading the same file again.
	require __DIR__ . '/../reason-updates.php';

	$defaultUrl = 'https://packages.reason.com/reason-password-reset/?action=get_metadata';

	// --- normalizeBaseUrl ---
	check('base: null means default', ReasonUpdates::normalizeBaseUrl(null) === 'https://packages.reason.com/');
	check('base: empty means default', ReasonUpdates::normalizeBaseUrl('') === 'https://packages.reason.com/');
	check('base: whitespace means default', ReasonUpdates::normalizeBaseUrl('   ') === 'https://packages.reason.com/');
	check('base: non-string means default', ReasonUpdates::normalizeBaseUrl(array('x')) === 'https://packages.reason.com/');
	check('base: adds missing trailing slash', ReasonUpdates::normalizeBaseUrl('https://staging.example.com') === 'https://staging.example.com/');
	check('base: collapses extra trailing slashes', ReasonUpdates::normalizeBaseUrl('https://staging.example.com///') === 'https://staging.example.com/');
	check('base: trims whitespace', ReasonUpdates::normalizeBaseUrl(" https://staging.example.com/ \n") === 'https://staging.example.com/');
	check('base: keeps a path prefix', ReasonUpdates::normalizeBaseUrl('https://example.com/packages') === 'https://example.com/packages/');

	// --- metadataUrl ---
	check('url: default format', ReasonUpdates::metadataUrl('reason-password-reset') === $defaultUrl);
	check('url: slug with disallowed characters throws', throws_invalid_argument(function () { ReasonUpdates::metadataUrl('my plugin&x'); }));
	check('url: slug with a slash throws', throws_invalid_argument(function () { ReasonUpdates::metadataUrl('a/b'); }));
	check('url: all allowed characters accepted', ReasonUpdates::metadataUrl('Ab0-_.,+!') === 'https://packages.reason.com/' . rawurlencode('Ab0-_.,+!') . '/?action=get_metadata');
	check('url: empty slug throws', throws_invalid_argument(function () { ReasonUpdates::metadataUrl(''); }));
	check('url: whitespace slug throws', throws_invalid_argument(function () { ReasonUpdates::metadataUrl('   '); }));
	check('url: null slug throws', throws_invalid_argument(function () { ReasonUpdates::metadataUrl(null); }));
	check('url: non-string slug throws', throws_invalid_argument(function () { ReasonUpdates::metadataUrl(123); }));

	$seen = null;
	$urlFilter = function ($url, $slug) use (&$seen) {
		$seen = array($url, $slug);
		return 'https://packages.reason.com/v2/' . $slug . '/metadata';
	};
	check('filter: rewrites the URL', ReasonUpdates::metadataUrl('reason-password-reset') === 'https://packages.reason.com/v2/reason-password-reset/metadata');
	check('filter: receives the built URL and the slug', $seen === array($defaultUrl, 'reason-password-reset'));
	$urlFilter = function () { return ''; };
	check('filter: empty result falls back', ReasonUpdates::metadataUrl('reason-password-reset') === $defaultUrl);
	$urlFilter = function () { return array('nope'); };
	check('filter: non-string result falls back', ReasonUpdates::metadataUrl('reason-password-reset') === $defaultUrl);
	$urlFilter = null;

	check('auth: not loaded before build()', !function_exists('ReasonDev\\PluginUpdateChecker\\ReasonPackages\\filter_http_request_args'));

	// --- build ---
	check('build: returns what the factory returns', ReasonUpdates::build('reason-password-reset', '/wp/plugins/rpr/rpr.php') === 'CHECKER');
	check('build: loads the update-key filter when missing', function_exists('ReasonDev\\PluginUpdateChecker\\ReasonPackages\\filter_http_request_args'));
	check('build: passes URL, path, slug and default options', PucFactory::$calls[0] === array($defaultUrl, '/wp/plugins/rpr/rpr.php', 'reason-password-reset', 12, '', ''));
	ReasonUpdates::build('my-theme', '/wp/themes/my-theme', 6, 'my_option', 'mu.php');
	check('build: passes optional arguments through', PucFactory::$calls[1] === array('https://packages.reason.com/my-theme/?action=get_metadata', '/wp/themes/my-theme', 'my-theme', 6, 'my_option', 'mu.php'));
	check('build: empty slug throws', throws_invalid_argument(function () { ReasonUpdates::build('', '/wp/plugins/x/x.php'); }));
	check('build: factory not called for a bad slug', count(PucFactory::$calls) === 2);

	// --- REASON_PACKAGES_URL (last: constants can't be undefined) ---
	define('REASON_PACKAGES_URL', 'https://staging-packages.example.com');
	check('constant: changes the base address', ReasonUpdates::metadataUrl('reason-password-reset') === 'https://staging-packages.example.com/reason-password-reset/?action=get_metadata');

	finish_tests();
}
