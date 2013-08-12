=== Media Placeholders ===
Contributors:      X-team, westonruter
Tags:              placeholders, uploads, development, images, 404
Requires at least: 3.5
Tested up to:      3.6
Stable tag:        trunk
License:           GPLv2 or later
License URI:       http://www.gnu.org/licenses/gpl-2.0.html

Redirect requests to non-existent uploaded images to a placeholder service like placehold.it or placekitten.com. For use during development.

== Description ==

Activate this plugin to redirect all requests for missing uploaded images on your blog to your favorite placeholder image service, such as [placehold.it](http://placehold.it) or [placekitten.com](http://placekitten.com/). Note that although kittens are cute, the placehold.it service is actually more useful because the background and foreground color can remain consistant across all image sizes (e.g. full size vs thumbnail in a gallery), and so it is easier to see which images in a page are related to each other. (You can change the default placehold.it service to placekitten.com by defining `MISSING_UPLOADED_IMAGE_PLACEHOLDER_BUILTIN` to be `placekitten_color` or `placekitten_grayscale`, or supplying those same values via the `missing_uploaded_image_placeholder_builtin` filter).

**This plugin is for use during development only.** It is expected that this plugin will be activated on your local development environment (e.g. on Vagrant or XAMPP), or on your staging server. This plugin is especially useful when working on a team where you share around a database dump but not the uploaded images (which should always be omitted from the code repository), so if you give a database dump to another developer but don't include the uploaded images, with this plugin enabled they will see a placeholder where the uploaded image appears. This plugin is an alternative approach to what is offered by the [Uploads by Proxy](http://wordpress.org/plugins/uploads-by-proxy/) plugin.

If you have applied the production database to another environment which lacks the uploaded files, but you know that all images referenced in the database do exist on production, you can define the `MISSING_UPLOADED_IMAGE_REDIRECT_SERVER` constant or filter `missing_uploaded_image_redirect_server` to short-circuit the placeholder service and redirect the image request to that server.

This plugin will not work if you are on a multisite network that uses the old system for referring to uploaded files, where the URL includes `/files/` which is intercepted by a rewrite rule and passed directly to `ms-files.php`. See [#19235](http://core.trac.wordpress.org/ticket/19235 "Turn ms-files.php off by default"). Similarly, make sure that missing uploaded files get served by the WordPress 404 handler, not Apache/Nginx. If you are using Nginx with the default Varying Vagrant Vagrants config, you'll want to remove `png|jpg|jpeg|gif` from the following location rule in `nginx-wp-common.conf` (or remove it altogether):

	# Handle all static assets by serving the file directly. Add directives 
	# to send expires headers and turn off 404 error logging.
	location ~* \.(js|css|png|jpg|jpeg|gif|ico)$ {
		expires 24h;
		log_not_found off;
	}

You can add support for your own favorite placeholder services by filtering `missing_uploaded_image_placeholder`.
For example, you can add this to your `functions.php` or drop it into a `mu-plugin`:

	<?php
	/**
	 * Use Flickholdr as placeholder service
	 * @param null|string $url
	 * @param array $args  {attached_file, width, height, attachment_id}
	 */
	function my_filter_missing_uploaded_image_placeholder( $url, $args ) {
		$attachment = get_post( $args['attachment_id'] );
		$tags = join( ' ', array(
			$attachment->post_title,
			$attachment->post_excerpt,
			$attachment->post_content,
			$attachment->_wp_attachment_image_alt
		) );
		$tags = strtolower( preg_replace( '#[^A-Za-z0-9]+#', ',', $tags ) );
		$tags = trim( $tags, ',' );
		$url = sprintf( 'http://flickholdr.com/%d/%d/%s', $args['width'], $args['height'], $tags );
		return $url;
	}
	add_filter( 'missing_uploaded_image_placeholder', 'my_filter_missing_uploaded_image_placeholder', 10, 2 );

**Development of this plugin is done [on GitHub](https://github.com/x-team/wp-media-placeholders). Pull requests welcome.**

== Changelog ==

= 0.9.1 =
Prevent default WordPress 404 handler from breaking placeholder redirect ([#5](https://github.com/x-team/wp-media-placeholders/pull/5))

= 0.9 =
First Release