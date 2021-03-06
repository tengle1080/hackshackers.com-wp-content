<?php

/**
 * Useful for finding an image to display alongside/in representation of a specific post.
 *
 * Includes a few different methods, all of which return a similar-format array containing
 * details of any images found. Everything can (should) be called statically, it's just a
 * function-bucket. You can also call Jetpack_PostImages::get_image() to cycle through all of the methods until
 * one of them finds something useful.
 *
 * This file is included verbatim in Jetpack
 */
class Jetpack_PostImages {
	/**
	 * If a slideshow is embedded within a post, then parse out the images involved and return them
	 */
	static function from_slideshow( $post_id, $width = 200, $height = 200 ) {
		$post = get_post( $post_id );

		if ( false === strpos( $post->post_content, '[slideshow' ) )
			return false; // no slideshow - bail

		$permalink = get_permalink( $post->ID );

		$images = array();

		// Mechanic: Somebody set us up the bomb
		$old_post = $GLOBALS['post'];
		$GLOBALS['post'] = $post;
		$old_shortcodes = $GLOBALS['shortcode_tags'];
		$GLOBALS['shortcode_tags'] = array( 'slideshow' => $old_shortcodes['slideshow'] );

		// Find all the slideshows
		preg_match_all( '/' . get_shortcode_regex() . '/sx', $post->post_content, $slideshow_matches, PREG_SET_ORDER );

		ob_start(); // The slideshow shortcode handler calls wp_print_scripts and wp_print_styles... not too happy about that

		foreach ( $slideshow_matches as $slideshow_match ) {
			$slideshow = do_shortcode_tag( $slideshow_match );
			if ( false === $pos = stripos( $slideshow, 'slideShow.images' ) ) // must be something wrong - or we changed the output format in which case none of the following will work
				continue;
			$start = strpos( $slideshow, '[', $pos );
			$end = strpos( $slideshow, ']', $start );
			$post_images = json_decode( str_replace( "'", '"', substr( $slideshow, $start, $end - $start + 1 ) ) ); // parse via JSON
			foreach ( $post_images as $post_image ) {
				if ( !$post_image_id = absint( $post_image->id ) )
					continue;

				$meta = wp_get_attachment_metadata( $post_image_id );

				// Must be larger than 200x200 (or user-specified)
				if ( !isset( $meta['width'] ) || $meta['width'] < $width )
					continue;
				if ( !isset( $meta['height'] ) || $meta['height'] < $height )
					continue;

				$url = wp_get_attachment_url( $post_image_id );

				$images[] = array(
					'type'       => 'image',
					'from'       => 'slideshow',
					'src'        => $url,
					'src_width'  => $meta['width'],
					'src_height' => $meta['height'],
					'thumb'      => add_query_arg( $meta['width'] >= $meta['height'] ? 'w' : 'h', 200, $url ), // scale the bigger dimension
					'href'       => $permalink,
				);
			}
		}
		ob_end_clean();

		// Operator: Main screen turn on
		$GLOBALS['shortcode_tags'] = $old_shortcodes;
		$GLOBALS['post'] = $old_post;

		return $images;
	}

	/**
	 * If a gallery is detected, then get all the images from it.
	 */
	static function from_gallery( $post_id ) {
		$post = get_post( $post_id );

		if ( false === strpos( $post->post_content, '[gallery' ) )
			return false; // no gallery - bail

		$permalink = get_permalink( $post->ID );

		$images = array();

		// CATS: All your base are belong to us
		$old_post = $GLOBALS['post'];
		$GLOBALS['post'] = $post;
		$old_shortcodes = $GLOBALS['shortcode_tags'];
		$GLOBALS['shortcode_tags'] = array( 'gallery' => $old_shortcodes['gallery'] );

		// Find all the galleries
		preg_match_all( '/' . get_shortcode_regex() . '/s', $post->post_content, $gallery_matches, PREG_SET_ORDER );

		// We'll staticize this later
		// $imgpress_url = get_blogaddress_by_id( $GLOBALS['wpdb']->blogid ) . 'imgpress';

		foreach ( $gallery_matches as $gallery_match ) {
			$gallery = do_shortcode_tag( $gallery_match );

			// Um... no images in the gallery - bail
			if ( false === $pos = stripos( $gallery, '<img' ) )
				continue;

			preg_match_all( '/<img\s+[^>]*src=([\'"])([^\'"]*)\\1/', $gallery, $image_match, PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE );

			$a_pos = 0;
			foreach ( $image_match[2] as $src ) {
				list( $raw_src ) = explode( '?', $src[0] ); // pull off any Query string (?w=250)
				$raw_src = wp_specialchars_decode( $raw_src ); // rawify it
				$raw_src = esc_url_raw( $raw_src ); // clean it

				$a_pos = strrpos( substr( $gallery, 0, $src[1] ), '<a', $a_pos ); // is there surrounding <a>?

				if ( false !== $a_pos && preg_match( '/<a\s+[^>]*href=([\'"])([^\'"]*)\\1/', $gallery, $href_match, 0, $a_pos ) ) {
					$href = wp_specialchars_decode( $href_match[2] );
					$href = esc_url_raw( $href );
				} else {
					// CATS: You have no chance to survive make your time
					$href = $raw_src;
				}

				$a_pos = $src[1];

				$images[] = array(
					'type'  => 'image',
					'from'  => 'gallery',
	/*				Facebook doesn't like imgpress for some reason.
					'thumb' => staticize_subdomain( add_query_arg( array(
						'url' => urlencode( $raw_src ),
						'fit' => '200,200',
					), $imgpress_url ) ),
	*/
					'src'   => $raw_src,
					'thumb' => add_query_arg( 'w', 200, $raw_src ), // Stick to normal width adjustment for now.  FB seems to deal with portrait images (which are technically too big at width=90) OK.
					'href'  => $permalink, // $href,
				);
			}
		}

		// Captain: For great justice
		$GLOBALS['shortcode_tags'] = $old_shortcodes;
		$GLOBALS['post'] = $old_post;

		return $images;
	}

	/**
	 * Get attachment images for a specified post and return them. Also make sure
	 * their dimensions are at or above a required minimum.
	 */
	static function from_attachment( $post_id, $width = 200, $height = 200 ) {

		$post_images = get_posts( array(
			'post_parent' => $post_id,   // Must be children of post
			'numberposts' => 5,          // No more than 5
			'post_type' => 'attachment', // Must be attachments
			'post_mime_type' => 'image', // Must be images
		) );

		if ( !$post_images )
			return false;

		$permalink = get_permalink( $post->ID );

		$images = array();

		foreach ( $post_images as $post_image ) {
			$meta = wp_get_attachment_metadata( $post_image->ID );
			// Must be larger than 200x200
			if ( !isset( $meta['width'] ) || $meta['width'] < $width )
				continue;
			if ( !isset( $meta['height'] ) || $meta['height'] < $height )
				continue;

			$url = wp_get_attachment_url( $post_image->ID );

			$images[] = array(
				'type'       => 'image',
				'from'       => 'attachment',
				'src'        => $url,
				'src_width'  => $meta['width'],
				'src_height' => $meta['height'],
				'thumb'      => add_query_arg( $meta['width'] >= $meta['height'] ? 'w' : 'h', 200, $url ),
				'href'       => $permalink,
			);
		}

		return $images;
	}

	/**
	 * Check if a Featured Image is set for this post, and return it in a similar
	 * format to the other images?_from_*() methods.
	 * @param  int $post_id The post ID to check
	 * @return Array containing details of the Featured Image, or empty array if none.
	 */
	static function from_thumbnail( $post_id, $width = 200, $height = 200 ) {
		$images = array();
		$thumb = get_post_thumbnail_id( $post_id );

		if ( $thumb ) {
			$meta = wp_get_attachment_metadata( $thumb );

			// Must be larger than requested minimums
			if ( !isset( $meta['width'] ) || $meta['width'] < $width )
				return $images;
			if ( !isset( $meta['height'] ) || $meta['height'] < $height )
				return $images;

			$url = wp_get_attachment_url( $thumb );
			if ( stristr( $url, '?' ) )
				$url = substr( $url, 0, strpos( $url, '?' ) );

			$images = array( array( // Other methods below all return an array of arrays
				'type'       => 'image',
				'from'       => 'thumbnail',
				'src'        => $url,
				'src_width'  => $meta['width'],
				'src_height' => $meta['height'],
				'thumb'      => add_query_arg( $meta['width'] >= $meta['height'] ? 'w' : 'h', 200, $url ),
				'href'       => get_permalink( $thumb ),
			) );
		}
		return $images;
	}

	/**
	 * Very raw -- just parse the HTML and pull out any/all img tags and return their src
	 * @param  str $html The HTML string to parse for images, or a post object
	 * @return Array containing images
	 */
	static function from_html( $html ) {
		$images = array();

		if ( is_object( $html ) ) {
			if ( property_exists( $html, 'post_content' ) )
				$html = $html->post_content;
			else
				return $images;
		}

		preg_match_all( '!<img.*src="([^"]+)".*/>!iUs', $html, $matches );
		if ( !empty( $matches[1] ) ) {
			foreach ( $matches[1] as $match ) {
				if ( stristr( $match, '/smilies/' ) )
					continue;

				$images[] = array(
					'type'  => 'image',
					'from'  => 'html',
					'src'   => html_entity_decode( $match ),
					'thumb' => html_entity_decode( $match ), // For now, leaving it as fullsize
					'href'  => '', // No link to apply to these. Might potentially parse for that as well, but not for now
				);
			}
		}

		return $images;
	}

	/**
	 * @param    int $post_id The post ID to check
	 * @param    int $size
	 * @return Array containing details of the image, or empty array if none.
	 */
	static function from_blavatar( $post_id, $size = 96 ) {
		if ( !function_exists( 'blavatar_domain' ) || !function_exists( 'blavatar_exists' ) || !function_exists( 'blavatar_url' ) ) {
			return array();
		}

		$permalink = get_permalink( $post_id );
		$domain = blavatar_domain( $permalink );

		if ( !blavatar_exists( $domain ) ) {
			return array();
		}

		$url = blavatar_url( $domain, 'img', $size );

		return array( array(
			'type'       => 'image',
			'from'       => 'blavatar',
			'src'        => $url,
			'src_width'  => $size,
			'src_height' => $size,
			'thumb'      => $url, // For now, leaving it as fullsize
			'href'       => $permalink,
		) );
	}

	/**
	 * @param    int $post_id The post ID to check
	 * @param    int $size
	 * @param string $default The default image to use.
	 * @return Array containing details of the image, or empty array if none.
	 */
	static function from_gravatar( $post_id, $size = 96, $default = false ) {
		$post = get_post( $post_id );
		$permalink = get_permalink( $post_id );

		if ( function_exists( 'get_avatar_url' ) ) {
			$url = get_avatar_url( $post->post_author, $size, $default, true );
			if ( $url && is_array( $url ) ) {
				$url = $url[0];
			}
		} else {
			$has_filter = has_filter( 'pre_option_show_avatars', '__return_true' );
			if ( !$has_filter ) {
				add_filter( 'pre_option_show_avatars', '__return_true' );
			}
			$avatar = get_avatar( $post->post_author, $size, $default );
			if ( !$has_filter ) {
				remove_filter( 'pre_option_show_avatars', '__return_true' );
			}

			if ( !$avatar ) {
				return array();
			}

			if ( !preg_match( '/src=["\']([^"\']+)["\']/', $avatar, $matches ) ) {
				return array();
			}

			$url = wp_specialchars_decode( $matches[1], ENT_QUOTES );
		}

		return array( array(
			'type'       => 'image',
			'from'       => 'gravatar',
			'src'        => $url,
			'src_width'  => $size,
			'src_height' => $size,
			'thumb'      => $url, // For now, leaving it as fullsize
			'href'       => $permalink,
		) );
	}

	/**
	 * Run through the different methods that we have available to try to find a single good
	 * display image for this post.
	 * @param  int $post_id
	 * @param array $args Other arguments (currently width and height required for images where possible to determine)
	 * @return Array containing details of the best image to be used
	 */
	static function get_image( $post_id, $args = array() ) {
		$image = '';
		$media = self::get_images( $post_id, $args );

		if ( is_array( $media ) ) {
			foreach ( $media as $item ) {
				if ( 'image' == $item['type'] ) {
					$image = $item;
					break;
				}
			}
		}

		return $image;
	}

	/**
	 * Get an array containing a collection of possible images for this post, stopping once we hit a method
	 * that returns something useful.
	 * @param  int $post_id
	 * @param  array  $args Optional args, curently only width and height required for images
	 * @return Array containing images that would be good for representing this post
	 */
	static function get_images( $post_id, $args = array() ) {
		// Figure out which image to attach to this post.
		$media = false;

		$defaults = array(
			'width' => 200,  // Required minimum width (if possible to determine)
			'height' => 200, // Required minimum height (if possible to determine)
			'avatar_size' => 96,
			'fallback_to_avatars' => false,
			'gravatar_default' => false,
		);
		$args = wp_parse_args( $args, $defaults );

		$media = self::from_thumbnail( $post_id, $args['width'], $args['height'] );
		if ( !$media )
			$media = self::from_slideshow( $post_id, $args['width'], $args['height'] );
		if ( !$media )
			$media = self::from_gallery( $post_id );
		if ( !$media )
			$media = self::from_attachment( $post_id, $args['width'], $args['height'] );
		if ( !$media )
			$media = self::from_html( get_post( $post_id ) );

		if ( !$media && $args['fallback_to_avatars'] ) {
			$media = self::from_blavatar( $post_id, $args['avatar_size'] );
			if ( !$media )
				$media = self::from_gravatar( $post_id, $args['avatar_size'], $args['gravatar_default'] );
		}

		return $media;
	}
}
