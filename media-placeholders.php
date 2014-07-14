<?php
/**
 * Plugin Name: Media Placeholders
 * Plugin URI:  http://github.com/x-team/wp-missing-upload-placeholders
 * Description: Redirect requests to non-existent uploaded images to a placeholder service like placehold.it or placekitten.com. For use during development.
 * Version:     0.9.2
 * Author:      X-Team
 * Author URI:  http://x-team.com/wordpress/
 * License:     GPLv2+
 */

/**
 * Copyright (c) 2013 X-Team (http://x-team.com/)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

class Media_Placeholders {
	/**
	 * Gathers information about WP attachments, used by holder.js on the front end
	 */
	private static $attachment_catalog = array(
		'catalog' => array(),
		'hash'    => '',
	);

	static function setup() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_missing_upload' ), 9 ); // at 9 so before redirect_canonical

		if ( apply_filters( 'media_placeholders_offline', false ) ) {
			add_action( 'init', array( __CLASS__, 'generate_attachments_catalog' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'init_front_end_rewrites' ) );
		}
	}

	/**
	 * @action wp_enqueue_scripts
	 */
	static function init_front_end_rewrites() {
		wp_enqueue_script( 'holder-js', plugins_url( 'js/holder.js', __FILE__ ), array(), '1.0.0', false );
		wp_enqueue_script( 'media-placeholders-catalog', plugins_url( 'js/attachment-catalog.js?' . self::$attachment_catalog['hash'], __FILE__ ), array( 'holder-js' ), '1.0.0', false );
		wp_enqueue_script( 'media-placeholders', plugins_url( 'js/media-placeholders.js', __FILE__ ), array( 'media-placeholders-catalog' ), '1.0.0', false );
	}

	/**
	 * Generates the attachments catalog
	 *
	 * TODO: What needs to be done for this to work on multisite?
	 */
	static function generate_attachments_catalog( $mime_types = 'image' ) {
		$args = array(
			'posts_per_page' => -1,
			'post_type' => 'attachment',
			'post_status' => 'any',
		);

		// TODO: Why doesn't this work?
		if ( ! empty( $mime_types ) ) {
			$args['post_mime_type'] = $mime_types;
		}
		$attachments = get_posts( $args );

		foreach ( $attachments as $attachment ) {
			if ( ! is_a( $attachment, 'WP_Post' ) ) {
				continue;
			}

			$attachment_meta = get_post_meta( $attachment->ID, '_wp_attachment_metadata', true );
			if ( isset( $attachment_meta['width'], $attachment_meta['height'], $attachment_meta['file'] ) ) {
				$attachment_catalog[ $attachment_meta['file'] ] = array(
					'width' => (int) $attachment_meta['width'],
					'height' => (int) $attachment_meta['height'],
				);
			}
		}
		self::$attachment_catalog = array(
			'catalog' => $attachment_catalog,
			'hash'    => md5( serialize( $attachment_catalog ) ),
		);
	}

	/**
	 * Returns the catalog as a JSON
	 */
	static function render_catalog() {
		status_header( 200 );

		header( 'Content-Type: application/javascript' );
		// TODO: This probably needs some intelligent caching logic, clients shouldn't need to ever
		// download the catalog again (see the unique query 'hash' parameter)

		$upload_dir = wp_upload_dir();
		$upload_url = preg_replace( '~^https?:~', '', $upload_dir['baseurl'] );

		$catalog_structure = array(
			'baseURL' => $upload_url,
			'catalog' => self::$attachment_catalog['catalog'],
		);
		echo 'var WPMediaPlaceholders = ';
		echo json_encode( $catalog_structure );
	}

	/**
	 * @action template_redirect
	 */
	static function handle_missing_upload() {
		global $wpdb;
		$upload_dir    = wp_upload_dir();
		$base_url_path = parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
		$catalog_url   = plugins_url( 'js/attachment-catalog.js', __FILE__ );

		if ( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) === parse_url( $catalog_url, PHP_URL_PATH ) ) {
			self::render_catalog();
			exit;
		}

		// Checking for is_404() is not helpful as WordPress will load the attachment template
		// if the uploaded file was deleted, so our first check is to see if we're requesting
		// something inside of the uploads directory, and if WordPress is serving this response
		// then obviously the file is missing.
		if ( strpos( $_SERVER['REQUEST_URI'], $base_url_path ) !== 0 ) {
			return;
		}

		// If we're offline, we don't need to check anything else, because all logic will be
		// done on front end side. We're sending 404 just to make super sure the <img> element
		// will fire an error event
		if ( apply_filters( 'media_placeholders_offline', false ) ) {
			status_header( 404 );
			exit;
		}

		$relative_upload_path = substr( $_SERVER['REQUEST_URI'], strlen( $base_url_path ) + 1 );
		$relative_upload_path = parse_url( $relative_upload_path, PHP_URL_PATH );

		$attached_file = $relative_upload_path;
		$width         = null;
		$height        = null;
		if ( preg_match( '#(.+)(?:-(\d+)x(\d+))(\.\w+)#', $relative_upload_path, $matches ) ) {
			$attached_file = $matches[1] . $matches[4];
			$width         = (int) $matches[2];
			$height        = (int) $matches[3];
		}

		$query = $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s", $attached_file );
		$attachment_id = $wpdb->get_var( $query );
		if ( empty( $attachment_id ) ) {
			return;
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		/**
		 * Optionally redirect missing image requests to another server
		 * Requires specifying a MISSING_UPLOADED_IMAGE_REDIRECT_SERVER constant,
		 * and/or the addition of missing_uploaded_image_redirect_server filter to return
		 * the domain of the server for which the attachment image is located on.
		 */
		$default_server = null;
		if ( defined( 'MISSING_UPLOADED_IMAGE_REDIRECT_SERVER' ) ) {
			$default_server = MISSING_UPLOADED_IMAGE_REDIRECT_SERVER;
		}
		$redirect_server = apply_filters( 'missing_uploaded_image_redirect_server', $default_server, $attachment_id );
		if ( $redirect_server ) {
			$url  = is_ssl() ? 'https://' : 'http://';
			$url .= $redirect_server;
			$url .= $_SERVER['REQUEST_URI'];
			wp_redirect( $url );
			exit;
		}

		// If no dimensions were requested, use original dimensions stored when image was uploaded
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $width ) && ! empty( $metadata['width'] ) ) {
			$width = $metadata['width'];
		}
		if ( empty( $height ) && ! empty( $metadata['height'] ) ) {
			$height = $metadata['height'];
		}

		// If no image dimensions could be found, use the large image size dimensions
		if ( empty( $width ) ) {
			$width = get_option( 'large_size_w' );
		}
		if ( empty( $height ) ) {
			$height = get_option( 'large_size_h' );
		}
		$filter_args = compact( 'attached_file', 'width', 'height', 'attachment_id' );

		$default_builtin = defined( 'MISSING_UPLOADED_IMAGE_PLACEHOLDER_BUILTIN' )
			? MISSING_UPLOADED_IMAGE_PLACEHOLDER_BUILTIN
			: 'placeholdit';

		$default_filter = array( __CLASS__, sprintf( 'filter_%s_image_url', $default_builtin ) );
		if ( is_callable( $default_filter ) ) {
			$url = call_user_func( $default_filter, null, $filter_args );
		}
		else {
			trigger_error( sprintf( 'Uncallable handler %s for missing image upload fallback', json_encode( $handler ) ), E_USER_WARNING );
		}
		$url = apply_filters( 'missing_uploaded_image_placeholder', $url, $filter_args );
		wp_redirect( $url );
		exit;
	}

	/**
	 * Generate catalog of all attachments and their dimensions, to be used in holder.js to determine placeholder dimensions
	 */
	static function generate_attachment_catalog_js() {
		header( 'Content-Type: application/javascript' );

		die;
	}

	/**
	 * Get URL to a placeholder image on placehold.it
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_placeholdit_image_url( $url, $args = '' ) {
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$name_hash = md5( $attached_file );
		$bgcolor   = substr( $name_hash, 0, 6 );
		$fgcolor   = substr( $name_hash, 6, 6 );
		$ext       = preg_replace( '/.+\./', '', $attached_file );
		$name      = rtrim( basename( $attached_file, $ext ), '.' );
		$ext       = 'png'; // yeah, JPEGs look awful
		$name     .= " ({$width}x{$height})";
		$name      = urlencode( $name );
		$url       = "http://placehold.it/{$width}x{$height}/{$bgcolor}/{$fgcolor}/{$ext}&text={$name}";
		return $url;
	}

	/**
	 * Get URL to a placeholder color image on placekitten.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_placekitten_color_image_url( $url, $args ) {
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://placekitten.com/$width/$height";
		return $url;
	}

	/**
	 * Get URL to a placeholder grayscale image on placekitten.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_placekitten_grayscale_image_url( $url, $args ) {
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://placekitten.com/g/$width/$height";
		return $url;
	}
}

add_action( 'plugins_loaded', array( 'Media_Placeholders', 'setup' ) );
