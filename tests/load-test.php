<?php
/**
 * Plain-PHP smoke test: the library loads under the ReasonDev namespace, and the
 * autoloader finds every class. Written without version numbers so it keeps
 * working after an upstream merge renames Puc/v5pN and load-v5pN.php.
 * Run: php tests/load-test.php
 */

require __DIR__ . '/check.php';

$root = dirname(__DIR__);
$composer = json_decode(file_get_contents($root . '/composer.json'), true);
$loader = isset($composer['autoload']['files'][0]) ? $composer['autoload']['files'][0] : '';

check('composer.json names a startup file that exists', $loader !== '' && is_file($root . '/' . $loader));
check('composer.json classmap still lists reason-updates.php', in_array('reason-updates.php', $composer['autoload']['classmap'], true));
check('plugin-update-checker.php requires the same startup file', strpos(file_get_contents($root . '/plugin-update-checker.php'), "'/" . $loader . "'") !== false);

require $root . '/plugin-update-checker.php';

check('major-version factory is under ReasonDev', class_exists('ReasonDev\\PluginUpdateChecker\\v5\\PucFactory'));
check('ReasonUpdates is loaded', class_exists('ReasonDev\\PluginUpdateChecker\\ReasonUpdates', false));
check('update-key filter function is loaded', function_exists('ReasonDev\\PluginUpdateChecker\\ReasonPackages\\filter_http_request_args'));

//These two live in vendor/ in the global namespace. The autoloader only finds them
//if its DEFAULT_NS_PREFIX constant matches the library's real namespace.
check('autoloader finds PucReadmeParser', class_exists('PucReadmeParser'));
check('autoloader finds Parsedown', class_exists('Parsedown'));

$minorFactory = get_parent_class('ReasonDev\\PluginUpdateChecker\\v5\\PucFactory');
check('minor-version factory is under ReasonDev', is_string($minorFactory) && strpos($minorFactory, 'ReasonDev\\PluginUpdateChecker\\v5p') === 0);
check('versioned classes autoload', class_exists(preg_replace('/PucFactory$/', 'Plugin\\UpdateChecker', (string)$minorFactory)));

$upstream = array_filter(get_declared_classes(), function ($name) {
	return strpos($name, 'YahnisElsts\\') === 0;
});
check('no upstream-namespace classes declared', count($upstream) === 0);

finish_tests();
