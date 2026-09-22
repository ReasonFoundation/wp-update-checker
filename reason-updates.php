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
 * Several plugins on a site can bundle this library. Because the class is declared
 * only if it doesn't already exist, the first bundled copy of this file to load
 * supplies it for the whole site. The constant and the filter work no matter which
 * copy that is.
 */

namespace ReasonDev\PluginUpdateChecker;

if ( !class_exists(ReasonUpdates::class, false) ):

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
		 * @return \ReasonDev\PluginUpdateChecker\v5p6\Plugin\UpdateChecker|\ReasonDev\PluginUpdateChecker\v5p6\Theme\UpdateChecker
		 * @throws \InvalidArgumentException When the slug is empty or not a string.
		 */
		public static function build($slug, $fullPath, $checkPeriod = 12, $optionName = '', $muPluginFile = '') {
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
		 * @throws \InvalidArgumentException When the slug is empty or not a string.
		 */
		public static function metadataUrl($slug) {
			if ( !is_string($slug) || trim($slug) === '' ) {
				throw new \InvalidArgumentException('ReasonUpdates: the package slug must be a non-empty string.');
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
		 * @return string Always ends with exactly one slash.
		 */
		public static function baseUrl() {
			return self::normalizeBaseUrl(defined('REASON_PACKAGES_URL') ? constant('REASON_PACKAGES_URL') : null);
		}

		/**
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
