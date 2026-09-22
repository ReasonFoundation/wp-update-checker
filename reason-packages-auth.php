<?php
/**
 * Reason Foundation: send REASON_PACKAGES_UPDATES_KEY to packages.reason.com.
 *
 * The update server gates metadata behind a shared key (SIMPLE_UPDATE_KEY on the
 * server). If a site defines REASON_PACKAGES_UPDATES_KEY, this adds it to metadata
 * requests as an "Authorization: Bearer" header.
 *
 * Download URLs are left alone: the server already embeds the key in them, and
 * WordPress re-sends request headers when it follows the server's redirect to S3,
 * which S3 rejects alongside its own presigned signature.
 *
 * Several plugins on a site can bundle this same library. Because the functions
 * below are declared only if they don't already exist, whichever bundled copy of
 * this file loads first is the one that supplies them for the whole site -- a
 * behavior change here only reaches a site once that first-loaded copy is updated.
 */

namespace ReasonDev\PluginUpdateChecker\ReasonPackages;

if ( !function_exists(__NAMESPACE__ . '\\add_auth_header') ):

	/**
	 * Add "Authorization: Bearer <key>" to an HTTP args array if the request qualifies.
	 *
	 * @param array $args WordPress HTTP API request args.
	 * @param string $url Request URL.
	 * @param mixed $key Raw value of REASON_PACKAGES_UPDATES_KEY.
	 * @param string[] $hosts Hostnames allowed to receive the key.
	 * @return array
	 */
	function add_auth_header($args, $url, $key, $hosts) {
		if ( !is_string($key) || trim($key) === '' ) {
			return $args;
		}

		$parts = parse_url($url);
		if ( !is_array($parts) || !isset($parts['scheme'], $parts['host']) ) {
			return $args;
		}
		if ( strtolower($parts['scheme']) !== 'https' ) {
			return $args;
		}
		if ( !in_array(strtolower($parts['host']), array_map('strtolower', $hosts), true) ) {
			return $args;
		}

		//Never add the header to download requests: the server only puts a key on
		//download URLs once it requires one, so during rollout a download URL may
		//have no key at all -- and even when it does, WordPress resends this header
		//when it follows the server's redirect to S3, which S3 then rejects because
		//the request also carries S3's own presigned signature.
		if ( isset($parts['query']) ) {
			parse_str($parts['query'], $query);
			if ( isset($query['action']) && is_string($query['action']) && $query['action'] === 'download' ) {
				return $args;
			}
			if ( isset($query['key']) && is_string($query['key']) && $query['key'] !== '' ) {
				return $args;
			}
		}

		if ( !isset($args['headers']) ) {
			$args['headers'] = array();
		}
		if ( !is_array($args['headers']) ) {
			return $args;
		}
		foreach ( array_keys($args['headers']) as $name ) {
			if ( strcasecmp($name, 'Authorization') === 0 ) {
				return $args;
			}
		}

		$args['headers']['Authorization'] = 'Bearer ' . trim($key);
		return $args;
	}

endif;

if ( !function_exists(__NAMESPACE__ . '\\filter_http_request_args') ):

	/**
	 * http_request_args callback: attach REASON_PACKAGES_UPDATES_KEY when defined.
	 *
	 * @param array $args
	 * @param string $url
	 * @return array
	 */
	function filter_http_request_args($args, $url) {
		if ( !defined('REASON_PACKAGES_UPDATES_KEY') ) {
			return $args;
		}

		$hosts = apply_filters('reason_packages_updates_hosts', array('packages.reason.com'));
		if ( !is_array($hosts) ) {
			return $args;
		}

		return add_auth_header($args, $url, constant('REASON_PACKAGES_UPDATES_KEY'), $hosts);
	}

endif;

//Every bundled copy of the library loads this file; register the filter only once.
//The function_exists check keeps the library loadable outside WordPress.
if (
	function_exists('add_filter')
	&& !has_filter('http_request_args', __NAMESPACE__ . '\\filter_http_request_args')
) {
	//phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.http_request_args -- Adds an auth header only; doesn't modify timeouts.
	add_filter('http_request_args', __NAMESPACE__ . '\\filter_http_request_args', 10, 2);
}
