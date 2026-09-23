<?php
/**
 * Reason Foundation: one-line update checker setup for packages.reason.com.
 *
 *     $checker = ReasonUpdates::build( 'my-plugin', __FILE__ );
 *
 * builds the metadata URL (https://packages.reason.com/<slug>/?action=get_metadata)
 * and returns the same object PucFactory::buildUpdateChecker() does. A site can
 * change the base address with the REASON_PACKAGES_URL constant, and the whole URL
 * with the reason_packages_metadata_url filter.
 *
 * Several plugins on a site can bundle this library. Composer only runs the
 * first-loaded plugin's copy of each version's startup file (load-v5p7.php for
 * this version; copies of other versions run their own), so that copy supplies this class,
 * if it has one. But this class is also registered with each plugin's own Composer
 * class loader (see composer.json's "classmap" entry), so ReasonUpdates::build() works
 * from any bundled copy even when an older, class-less copy loaded first -- and in
 * that case, build() loads its own copy's update-key support too, if the first-loaded
 * copy predates it. The constant and the filter work no matter which copy's class
 * runs, but a change to the built-in URL format itself only reaches a site once its
 * first-loaded copy is updated -- in practice, once every Reason plugin on the site is.
 */

namespace ReasonDev\PluginUpdateChecker;

if ( !class_exists(ReasonUpdates::class, false) ):

	/**
	 * Because the first-loaded bundled copy of this library supplies this class for
	 * every plugin on the site (see the file docblock above), its public methods must
	 * never be changed or removed once released -- only added. A caller that wants to
	 * use a method added after the library's first release should check
	 * method_exists() first, since an older copy may be the one that loaded.
	 */
	class ReasonUpdates {
		const DEFAULT_BASE_URL = 'https://packages.reason.com/';

		/**
		 * Build an update checker for a package served by packages.reason.com.
		 *
		 * @param string $slug Package slug on the server. Always pass it explicitly: it isn't guaranteed to match the install directory.
		 * @param string $fullPath Full path to the main plugin file, or to the theme directory.
		 * @param int $checkPeriod How often to check for updates (in hours).
		 * @param string $optionName Where to store bookkeeping info about update checks.
		 * @param string $muPluginFile The plugin filename relative to the mu-plugins directory.
		 * @return \ReasonDev\PluginUpdateChecker\v5p7\Plugin\UpdateChecker|\ReasonDev\PluginUpdateChecker\v5p7\Theme\UpdateChecker|\ReasonDev\PluginUpdateChecker\v5p7\Vcs\BaseChecker
		 * @throws \InvalidArgumentException When the slug is empty, not a string, or contains a character other than a letter, a number, or - _ . , + !
		 */
		public static function build($slug, $fullPath, $checkPeriod = 12, $optionName = '', $muPluginFile = '') {
			//If an older bundled copy of this library loaded first, Composer skipped this
			//copy's load-v5p7.php, so the update-key filter may be missing. Load it from here.
			if ( !function_exists('ReasonDev\\PluginUpdateChecker\\ReasonPackages\\filter_http_request_args') ) {
				require_once __DIR__ . '/reason-packages-auth.php';
			}

			return v5\PucFactory::buildUpdateChecker(
				self::metadataUrl($slug),
				$fullPath,
				$slug,
				$checkPeriod,
				$optionName,
				$muPluginFile
			);
		}

		/**
		 * The metadata URL for a package slug.
		 *
		 * @param string $slug
		 * @return string
		 * @throws \InvalidArgumentException When the slug is empty, not a string, or contains a character other than a letter, a number, or - _ . , + !
		 */
		public static function metadataUrl($slug) {
			if ( !is_string($slug) || trim($slug) === '' ) {
				throw new \InvalidArgumentException('ReasonUpdates: the package slug must be a non-empty string.');
			}
			if ( !preg_match('/^[A-Za-z0-9\-_.,+!]+$/', $slug) ) {
				throw new \InvalidArgumentException('ReasonUpdates: the package slug may only contain letters, numbers, and - _ . , + !');
			}

			$url = self::baseUrl() . rawurlencode($slug) . '/?action=get_metadata';

			if ( function_exists('apply_filters') ) {
				$filtered = apply_filters('reason_packages_metadata_url', $url, $slug);
				if ( is_string($filtered) && $filtered !== '' ) {
					$url = $filtered;
				}
			}

			return $url;
		}

		/**
		 * The base address: REASON_PACKAGES_URL if the site defines it, otherwise packages.reason.com.
		 *
		 * @internal
		 * @return string Always ends with exactly one slash.
		 */
		public static function baseUrl() {
			return self::normalizeBaseUrl(defined('REASON_PACKAGES_URL') ? constant('REASON_PACKAGES_URL') : null);
		}

		/**
		 * @internal
		 * @param mixed $value Raw base address. Anything but a non-empty string means "use the default".
		 * @return string Always ends with exactly one slash.
		 */
		public static function normalizeBaseUrl($value) {
			if ( !is_string($value) || trim($value) === '' ) {
				$value = self::DEFAULT_BASE_URL;
			}
			return rtrim(trim($value), '/') . '/';
		}
	}

endif;
