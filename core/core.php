<?php

// Subpackage namespace
namespace LittleBizzy\DashboardCleanup\Core;

// Aliased namespaces
use \LittleBizzy\DashboardCleanup\Helpers;

/**
 * Core class
 *
 * @package Dashboard Cleanup
 * @subpackage Core
 */
final class Core extends Helpers\Singleton {



	/**
	 * Pseudo constructor
	 */
	protected function onConstruct() {

		// Check admin area
		if ($this->plugin->context()->admin()) {

			// Factory object
			$factory = new Factory($this->plugin);

			// Elements
			add_filter('admin_footer_text', [$factory->elements(), 'footerText']);
			add_action('admin_init', [$factory->elements(), 'WPORGShortcutLinks']);
			add_action('admin_init', [$factory->elements(), 'linkManagerMenu']);
			add_filter('install_plugins_tabs', [$factory->elements(), 'addPluginTabs']);

			// Dashboard
			add_action('admin_init', [$factory->dashboard(), 'quickDraft']);
			add_action('admin_init', [$factory->dashboard(), 'welcomePanel']);
			add_action('admin_init', [$factory->dashboard(), 'eventsAndNews']);

			// WooCommerce
			add_filter('woocommerce_helper_suppress_connect_notice', [$factory->woocommerce(), 'connectStore']);
			add_filter('woocommerce_show_admin_notice', [$factory->woocommerce(), 'productsBlock'], 10, 2);

		// Check frontend execution
		} elseif ($this->plugin->context()->front()) {

			// Factory object
			$factory = new Factory($this->plugin);

			// Remove WP shortcut link before the template load
			add_action('template_redirect', [$factory->elements(), 'WPORGShortcutLinks']);
		}
	}



}