<?php
/**
 * Plugin Name: Media Placeholders
 * Plugin URI:  http://github.com/x-team/wp-missing-upload-placeholders
 * Description: Redirect requests to non-existent uploaded images to a placeholder service like placehold.it or placekitten.com. For use during development.
 * Version:     0.9.3
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
	static function setup() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_missing_upload' ), 9 ); // at 9 so before redirect_canonical
	}

	/**
	 * @action template_redirect
	 */
	static function handle_missing_upload() {
		global $wpdb;
		$upload_dir    = wp_upload_dir();
		$base_url_path = parse_url( $upload_dir['baseurl'], PHP_URL_PATH );

		// Checking for is_404() is not helpful as WordPress will load the attachment template
		// if the uploaded file was deleted, so our first check is to see if we're requesting
		// something inside of the uploads directory, and if WordPress is serving this response
		// then obviously the file is missing.
		if ( strpos( $_SERVER['REQUEST_URI'], $base_url_path ) !== 0 ) {
			return;
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

	/**
	 * Get URL to a placeholder grayscale image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_grayscale_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/g/$width/$height";
		return $url;
	}

	/**
	 * Get URL to a placeholder image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/$width/$height";
		return $url;
	}

	/**
	 * Get URL to a abstract placeholder grayscale image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_abstract_grayscale_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/g/$width/$height/abstract";
		return $url;
	}

	/**
	 * Get URL to a abstract placeholder image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_abstract_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/$width/$height/abstract";
		return $url;
	}

	/**
	 * Get URL to a city placeholder grayscale image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_city_grayscale_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/g/$width/$height/city";
		return $url;
	}

	/**
	 * Get URL to a city placeholder image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_city_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/$width/$height/city";
		return $url;
	}

	/**
	 * Get URL to a people placeholder grayscale image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_people_grayscale_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/g/$width/$height/people";
		return $url;
	}

	/**
	 * Get URL to a people placeholder image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_people_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/$width/$height/people";
		return $url;
	}

	/**
	 * Get URL to a transport placeholder grayscale image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_transport_grayscale_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/g/$width/$height/transport";
		return $url;
	}

	/**
	 * Get URL to a transport placeholder image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_transport_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/$width/$height/transport";
		return $url;
	}

	/**
	 * Get URL to a animals placeholder grayscale image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_animals_grayscale_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/g/$width/$height/animals";
		return $url;
	}

	/**
	 * Get URL to a animals placeholder image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_animals_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/$width/$height/animals";
		return $url;
	}

	/**
	 * Get URL to a food placeholder grayscale image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_food_grayscale_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/g/$width/$height/food";
		return $url;
	}

	/**
	 * Get URL to a food placeholder image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_food_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/$width/$height/food";
		return $url;
	}

	/**
	 * Get URL to a nature placeholder grayscale image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_nature_grayscale_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/g/$width/$height/nature";
		return $url;
	}

	/**
	 * Get URL to a nature placeholder image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_nature_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/$width/$height/nature";
		return $url;
	}

	/**
	 * Get URL to a business placeholder grayscale image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_business_grayscale_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/g/$width/$height/business";
		return $url;
	}

	/**
	 * Get URL to a business placeholder image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_business_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/$width/$height/business";
		return $url;
	}

	/**
	 * Get URL to a cats placeholder grayscale image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_cats_grayscale_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/g/$width/$height/cats";
		return $url;
	}

	/**
	 * Get URL to a cats placeholder image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_cats_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/$width/$height/cats";
		return $url;
	}

	/**
	 * Get URL to a nightlife placeholder grayscale image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_nightlife_grayscale_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/g/$width/$height/nightlife";
		return $url;
	}

	/**
	 * Get URL to a nightlife placeholder image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_nightlife_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/$width/$height/nightlife";
		return $url;
	}

	/**
	 * Get URL to a sports placeholder grayscale image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_sports_grayscale_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/g/$width/$height/sports";
		return $url;
	}

	/**
	 * Get URL to a sports placeholder image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_sports_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/$width/$height/sports";
		return $url;
	}

	/**
	 * Get URL to a fashion placeholder grayscale image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_fashion_grayscale_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/g/$width/$height/fashion";
		return $url;
	}

	/**
	 * Get URL to a fashion placeholder image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_fashion_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/$width/$height/fashion";
		return $url;
	}

	/**
	 * Get URL to a technics placeholder grayscale image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_technics_grayscale_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/g/$width/$height/technics";
		return $url;
	}

	/**
	 * Get URL to a technics placeholder image on lorempixel.com
	 * @param string|null $url
	 * @param array|string $args {attached_file, width, height, attachment_id}
	 * @return string
	 */
	static function filter_lorem_technics_image_url( $url, $args ){
		extract( wp_parse_args( $args ) );
		/**
		 * @var string $attached_file
		 * @var int $width
		 * @var int $height
		 * @var int $attachment_id
		 */
		$url = "http://lorempixel.com/$width/$height/technics";
		return $url;
	}
}

add_action( 'plugins_loaded', array( 'Media_Placeholders', 'setup' ) );
