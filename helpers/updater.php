<?php

// Subpackage namespace
namespace LittleBizzy\DashboardCleanup\Helpers;

/**
 * Updater class
 *
 * @package WordPress Plugin
 * @subpackage Helpers
 */
class Updater {



	/**
	 * The common option across PBP plugins to check last plugin timestamp
	 */
	const TIMESTAMP_OPTION_NAME = 'lbpbp_update_plugins_ts';



	/**
	 * Interval between update checks
	 */
	const INTERVAL_UPDATE_CHECK = 6 * 3600; // 6 hours



	/**
	 * Random time added to the update interval
	 */
	const INTERVAL_UPDATE_CHECK_RAND  = 3600; // 1 hour



	/**
	 * Parser endpoint for remote readme.txt
	 */
	//const README_PARSER_ENDPOINT_URL = 'http://littlebizzy.local/wp-json/readme-parser/v1/parse';
	const README_PARSER_ENDPOINT_URL = 'https://pauiglesias.com/wp-json/readme-parser/v1/parse';



	/**
	 * Plugin constants
	 */
	private $file;
	private $prefix;
	private $version;
	private $repo;



	/**
	 * Plugin directory/file key
	 */
	private $key;



	/**
	 * Constructor
	 */
	public function __construct($file, $prefix, $version, $repo) {

		// Set plugin data
		$this->file 	= $file;
		$this->prefix 	= $prefix;
		$this->version 	= $version;
		$this->repo 	= $repo;

		// Check plugin file based key
		if (false === ($this->key = $this->fileKey())) {
			return;
		}

		// HTTP Request Args short-circuit
		add_filter('http_request_args', [$this, 'httpRequestArgs'], PHP_INT_MAX, 2);

		// Filters the plugin api information
		add_filter('plugins_api', [$this, 'pluginsAPI'], PHP_INT_MAX, 3);

		// Check repo for scheduling
		if (!empty($this->repo)) {
			$this->scheduling();
		}

// Test
/* $upgrade = $this->upgrade();
if (!empty($upgrade['readme'])) {
	$url = add_query_arg('url', $upgrade['readme'], self::README_PARSER_ENDPOINT_URL);
	$a = wp_remote_retrieve_body(wp_remote_get($url));
	$a = @json_decode($a, true);
	print_r($a); die;
} */

	}



	/**
	 * Handles HTTP requests looking for plugin updates
	 * and removes any reference of the current plugin
	 */
	public function httpRequestArgs($args, $url) {

		// Check args
		if (empty($args) || !is_array($args)) {
			return $args;
		}

		// Check endpoint
		if (false === strpos($url, '://api.wordpress.org/plugins/update-check/')) {
			return $args;
		}

		// Check method
		if (empty($args['method']) || 'POST' != $args['method']) {
			return $args;
		}

		// Check plugins argument
		if (empty($args['body']) || !is_array($args['body']) || empty($args['body']['plugins'])) {
			return $args;
		}

		// Check plugins list
		$data = @json_decode($args['body']['plugins'], true);
		if (empty($data) || !is_array($data)) {
			return $args;
		}

		// Plugins list
		if (!empty($data['plugins']) && is_array($data['plugins']) && isset($data['plugins'][$this->key])) {
			$modified = true;
			unset($data['plugins'][$this->key]);
		}

		// Check active plugins
		if (!empty($data['active']) && is_array($data['active']) && in_array($this->key, $data['active'])) {
			$modified = true;
			$data['active'] = array_diff($data['active'], [$this->key]);
		}

		// Modifications
		if ($modified) {

			// Set new plugins body data
			$args['body']['plugins'] = wp_json_encode($data);

			// Filter the response
			$upgrade = $this->upgrade();
			if (!empty($upgrade)) {
				add_filter('http_response', [$this, 'httpResponse'], PHP_INT_MAX, 3);
			}
		}

		// Done
		return $args;
	}



	/**
	 * Check filter response
	 */
	public function httpResponse($response, $r, $url) {

		// First remove this filter
		remove_filter('http_response', [$this, 'httpResponse'], PHP_INT_MAX);

		// Check endpoint
		if (false === strpos($url, '://api.wordpress.org/plugins/update-check/')) {
			return $response;
		}

		// Check response
		if (is_wp_error($response) || !isset($response['body'])) {
			return $response;
		}

		// Check plugin data
		$upgrade = $this->upgrade();
		if (empty($upgrade)) {
			return $response;
		}

		// Cast to array
		$payload = @json_decode($response['body'], true);
		if (empty($payload) || !is_array($payload)) {
			$payload = [];
		}

		// Check plugins
		if (empty($payload['plugins']) || !is_array($payload['plugins'])) {
			$payload['plugins'] = [];
		}

		// Set this plugin info
		$payload['plugins'][$this->key] = [
			'slug' 				=> dirname($this->key),
			'plugin' 			=> $this->key,
			'new_version' 		=> $upgrade['version'],
			'package' 			=> $upgrade['package'],
			'upgrade_notice' 	=> $upgrade['notice'],
			'icons'				=> $upgrade['icons'],
			'banners'			=> $upgrade['banners'],
			'tested'			=> $upgrade['tested'],
			'requires_php'		=> $upgrade['requires_php'],
		];

		// Back to JSON
		$response['body'] = @json_encode($payload);

		// Done
		return $response;
	}



	/**
	 * Filters the plugin API
	 */
	public function pluginsAPI($default, $action, $args) {

		// Check info
		if ('plugin_information' != $action) {
			return $default;
		}

		// Check arguments
		if (empty($args) || !is_object($args)) {
			return $default;
		}

		// Check slug argument
		if (empty($args->slug) || $args->slug != dirname($this->key)) {
			return $default;
		}

		// Check local data
		$upgrade = $this->upgrade();
		if (empty($upgrade) || !is_array($upgrade)) {
			return $default;
		}

		// Check readme info
		if (empty($upgrade['readme'])) {
			return $default;
		}

		// Make an API request to parse the readme file
		$url = add_query_arg('url', $upgrade['readme'], self::README_PARSER_ENDPOINT_URL);
		$json = wp_remote_retrieve_body(wp_remote_get($url));
		$json = @json_decode($json, true);

		// Check results
		if (empty($json) || !is_array($json)) {
			return $default;
		}

		/**
		 * Debug:
		 *
		 * x [0] => name
		 * x [1] => slug
		 * [2] => version
		 * [3] => author
		 * [4] => author_profile
		 * [5] => requires
		 * [6] => tested
		 * [7] => requires_php
		 * [8] => compatibility
		 * [9] => rating
		 * [10] => ratings
		 * [11] => num_ratings
		 * [12] => support_threads
		 * [13] => support_threads_resolved
		 * [14] => active_installs
		 * [15] => last_updated
		 * [16] => added
		 * [17] => homepage
		 * [18] => sections
		 * [19] => download_link
		 * [20] => screenshots
		 * [21] => tags
		 * [22] => versions
		 * [23] => donate_link
		 * [24] => banners
		 * [25] => contributors
		 *
		 * End debug */

/*
		 [0] => name
	     [1] => tags
	     [2] => requires_at_least
	     [3] => tested_up_to
	     [4] => stable_tag
	     [5] => contributors
	     [6] => donate_link
	     [7] => license
	     [8] => license_uri
	     [9] => short_description
	     [10] => screenshots
	     [11] => is_excerpt
	     [12] => is_truncated
	     [13] => sections
	     [14] => remaining_content
	     [15] => upgrade_notice
		 [16] => slug
	     [17] => icons
	     [18] => banners
*/

		// Prepare banner
		$banners = [];
		if (isset($upgrade['banners']['1x'])) {
			$banners['low'] = $upgrade['banners']['1x'];
		}
		if (isset($upgrade['banners']['2x'])) {
			$banners['high'] = $upgrade['banners']['2x'];
		}

		// Prepare object
		$json = (object) $json;
		$json->slug 	= dirname($this->key);
		$json->banners 	= $banners;
		$json->version	= $upgrade['version'];

//$a = (array) $json; error_log(print_r(array_keys($a), true));die;

		// Done
		return $json;
	}



	/**
	 * Schedule update checks
	 */
	private function scheduling() {

return;

// Debug point
//$this->checkUpdates(); return;

		// Global timestamp option
		global $lbpbp_update_plugins_ts;
		if (!isset($lbpbp_update_plugins_ts)) {

			// Retrieve global plugin timestamps data
			$lbpbp_update_plugins_ts = @json_decode(get_option(self::TIMESTAMP_OPTION_NAME), true);
			if (empty($lbpbp_update_plugins_ts) || !is_array($lbpbp_update_plugins_ts)) {
				$lbpbp_update_plugins_ts = [];
			}

			// Timestamps saving
			add_action('init', [$this, 'timestamps']);
		}

		// Check last update check
		$timestamp = empty($lbpbp_update_plugins_ts[$this->key])? 0 : (int) $lbpbp_update_plugins_ts[$this->key];
		if (!empty($timestamp) && time() < $timestamp + self::INTERVAL_UPDATE_CHECK + self::INTERVAL_UPDATE_CHECK_RAND) {
			return;
		}

		// Update check
		$lbpbp_update_plugins_ts[$this->key] = time() + rand(0, self::INTERVAL_UPDATE_CHECK_RAND);

		// Set scheduling
		// ..

		/* if (!wp_next_scheduled('pbp_update_plugins_'.$this->repo)) {
			wp_schedule_event(time(), 'hourly', 'checkUpdates');
		} */
	}



	/**
	 * Save common timestamps option
	 */
	public function timestamps() {

		// Globals
		global $lbpbp_update_plugins_ts;

		// Current timestamp
		$time = time();

		// Clean outdated
		foreach ($lbpbp_update_plugins_ts as $key => $timestamp) {
			if ($key != $this->key && $time >= $timestamp + self::INTERVAL_UPDATE_CHECK + self::INTERVAL_UPDATE_CHECK_RAND) {
				unset($lbpbp_update_plugins_ts[$key]);
			}
		}

		// Update once for al PBP plugins
		update_option(self::TIMESTAMP_OPTION_NAME, @json_encode($lbpbp_update_plugins_ts), true);
	}



	/**
	 * Check for private repo plugin updates
	 */
	private function checkUpdates() {

		// Compose URL
		$url = str_replace('%repo%', $this->repo, 'https://raw.githubusercontent.com/littlebizzy/%repo%/master/releases.json');

		// Request attempt
		$request = wp_remote_get($url.'?'.rand(0, 99999));
		if (empty($request) || !is_array($request) || empty($request['body'])) {
			return;
		}

		// Check response
		if (empty($request['response']) || !is_array($request['response']) ||
			empty($request['response']['code']) || '200' != $request['response']['code']) {
			return;
		}

		// Check json
		$versions = @json_decode($request['body'], true);
		if (empty($versions) || !is_array($versions)) {
			return;
		}

		// Enum json version
		foreach ($versions as $version => $info) {

			// Check basic package data
			if (empty($info['package'])) {
				continue;
			}

			// Compare first with current version
			if (empty($info) || version_compare($version, $this->version, '<=')) {
				continue;
			}

			// Add if there is a new version, or compare with registered new version (this avoid order issues)
			if (empty($greater) || version_compare($version, $greater['version'], '>')) {
				$greater = $info;
				$greater['version'] = $version;
			}
		}

		// Check update data
		if (!empty($greater)) {

			// Safe data
			$upgrade = [
				'version' 		=> $greater['version'],
				'package' 		=> $greater['package'],
				'readme' 		=> empty($greater['readme'])? '' : $greater['readme'],
				'notice'		=> empty($greater['notice'])? '' : $greater['notice'],
				'icons'			=> (empty($greater['icons']) || !is_array($greater['icons']))? [] : $greater['icons'],
				'banners'		=> (empty($greater['banners']) || !is_array($greater['banners']))? [] : $greater['banners'],
				'tested'		=> empty($greater['tested'])? '' : $greater['tested'],
				'requires_php' 	=> empty($greater['requires_php'])? '' : $greater['requires_php'],
			];

			// Save data
			$this->upgrade($upgrade);

		// No plugin info
		} else {

			// Remove update if not empty
			$upgrade = $this->upgrade();
			if (!empty($upgrade)) {
				$this->upgrade([]);
			}
		}
	}



	/**
	 * Read or save plugins data
	 */
	private function upgrade($upgrade = null) {

		// Option name
		$option = $this->prefix.'_update_plugins';

		// Update
		if (isset($upgrade)) {

			// Save plugins data
			update_option($option, @json_encode($upgrade), false);

		// Retrieve
		} else {

			// Local cache
			static $value;
			if (isset($value)) {
				return $value;
			}

			// Retrieve plugins list
			$value = @json_decode(get_option($option), true);
			if (empty($value) || !is_array($value)) {
				$value = [];
			}

			// Done
			return $value;
		}
	}



	/**
	 * Compose plugin key based on main plugin file
	 */
	private function fileKey() {

		// This plugin main file
		if (empty($this->file)) {
			return false;
		}

		// Split in slugs
		$parts = explode('/', $this->file);
		if (count($parts) < 2) {
			return false;
		}

		// Check dir and file
		$dir  = $parts[count($parts) - 2];
		$file = $parts[count($parts) - 1];
		if ('' === $dir || '' === $file) {
			return false;
		}

		// Compose key
		$key = $dir.'/'.$file;

		// Done
		return $key;
	}



}