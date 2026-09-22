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

		//Download URLs already carry the key as a query parameter.
		if ( isset($parts['query']) ) {
			parse_str($parts['query'], $query);
			if ( isset($query['key']) ) {
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
