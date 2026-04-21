<?php
/**
 * WPBakery Page Builder Sync Compatibility
 * 
 * This file handles WPBakery-specific sync functionality.
 * It only runs when "WPBakery" is selected in the plugin settings.
 * 
 * @package Staging_To_Live_Sync
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle WPBakery data during sync (staging side)
 */
function stls_wpbakery_add_to_sync_data($post_data, $post_id)
{
    if (get_option('stls_pagebuilder') !== 'wpbakery') {
        return $post_data;
    }

    $content = $post_data['content'];
    $wpbakery_images = array();

    // 1. Single image shortcodes: [vc_single_image image="123"]
    if (preg_match_all('/image=["\']([0-9]+)["\']/', $content, $matches)) {
        foreach ($matches[1] as $id) {
            $url = wp_get_attachment_url($id);
            if ($url) {
                $wpbakery_images[$id] = $url;
            }
        }
    }

    // 2. Gallery/Carousel shortcodes: [vc_gallery ids="1,2,3"] or [vc_gallery images="1,2,3"]
    // Handle potential spaces around = and different quote types
    if (preg_match_all('/(ids|images|include)\s*=\s*(["\'])([0-9,\s]+)\2/', $content, $matches)) {
        foreach ($matches[3] as $ids_str) {
            $ids = array_map('trim', explode(',', $ids_str));
            foreach ($ids as $id) {
                if (is_numeric($id)) {
                    $url = wp_get_attachment_url($id);
                    if ($url) {
                        $wpbakery_images[$id] = $url;
                    }
                }
            }
        }
    }

    // 3. Background images: background_image="123" or parallax_image="123"
    if (preg_match_all('/(background_image|parallax_image)\s*=\s*(["\'])([0-9]+)\2/', $content, $matches)) {
        foreach ($matches[3] as $id) {
            $url = wp_get_attachment_url($id);
            if ($url) {
                $wpbakery_images[$id] = $url;
            }
        }
    }

    // 4. Background images in CSS attributes: url(...?id=123)
    if (preg_match_all('/url\([^)]+\?id=([0-9]+)\)/', $content, $matches)) {
        foreach ($matches[1] as $id) {
            // Avoid duplicate extraction if already found
            if (!isset($wpbakery_images[$id])) {
                $url = wp_get_attachment_url($id);
                if ($url) {
                    $wpbakery_images[$id] = $url;
                }
            }
        }
    }

    if (!empty($wpbakery_images)) {
        $post_data['wpbakery_images'] = $wpbakery_images;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('STLS WPBakery Staging: Extracted ' . count($wpbakery_images) . ' images for post ' . $post_id);
        }
    }

    return $post_data;
}
add_filter('stls_post_data_before_sync', 'stls_wpbakery_add_to_sync_data', 10, 2);

/**
 * Save WPBakery data on live site after sync
 */
function stls_wpbakery_save_on_live($post_id, $post_data)
{
    if (get_option('stls_pagebuilder') !== 'wpbakery') {
        return;
    }

    $wpbakery_images = isset($post_data['wpbakery_images']) ? $post_data['wpbakery_images'] : array();
    $staging_url = isset($post_data['staging_url']) ? $post_data['staging_url'] : get_option('stls_staging_url', '');
    $live_url = home_url();

    if (empty($wpbakery_images) && empty($staging_url)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('STLS WPBakery Live: No images or staging URL found for sync');
        }
        return;
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('STLS WPBakery Live: Starting sync for post ' . $post_id . ' with ' . count($wpbakery_images) . ' images');
    }

    $post = get_post($post_id);
    if (!$post)
        return;

    $content = $post->post_content;
    $updated = false;

    // 1. Process and replace images
    if (!empty($wpbakery_images)) {
        foreach ($wpbakery_images as $old_id => $old_url) {
            // Download/get live attachment ID
            $new_id = stls_convert_image_data_to_attachment_id(array('url' => $old_url, 'attachment_id' => $old_id), $post_id);

            if ($new_id && !is_wp_error($new_id) && $new_id != $old_id) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('STLS WPBakery Live: Replacing ID ' . $old_id . ' with ' . $new_id);
                }
                // Replace in shortcodes
                // Single: image="123" -> image="456"
                // Handle potential spaces and preserve quotes using \2 backreference
                $content = preg_replace('/(image\s*=\s*(["\']))' . $old_id . '(\2)/', '${1}' . $new_id . '${3}', $content);
                $content = preg_replace('/(parallax_image\s*=\s*(["\']))' . $old_id . '(\2)/', '${1}' . $new_id . '${3}', $content);
                $content = preg_replace('/(background_image\s*=\s*(["\']))' . $old_id . '(\2)/', '${1}' . $new_id . '${3}', $content);

                // Multi: ids="...,123,..." or images="...,123,..."
                // Preserve the original separator and quotes
                $content = preg_replace_callback('/(ids|images|include)(\s*=\s*)(["\'])([0-9,\s]+)(\3)/', function ($matches) use ($old_id, $new_id) {
                    $ids = array_map('trim', explode(',', $matches[4]));
                    $key = array_search($old_id, $ids);
                    if ($key !== false) {
                        $ids[$key] = $new_id;
                    }
                    // matches[1] = attribute name, matches[2] = space/equal part, matches[3] = opening quote, matches[5] = closing quote
                    return $matches[1] . $matches[2] . $matches[3] . implode(',', $ids) . $matches[5];
                }, $content);

                $updated = true;
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('STLS WPBakery Live: Skipping ID ' . $old_id . ' (New ID: ' . $new_id . ')');
                }
            }
        }
    }

    // 2. Replace remaining staging URLs in content
    if (!empty($staging_url)) {
        $old_url_variations = array(
            $staging_url,
            str_replace('/', '\/', $staging_url),
            untrailingslashit($staging_url),
            str_replace('/', '\/', untrailingslashit($staging_url))
        );
        $new_url_variations = array(
            $live_url,
            str_replace('/', '\/', $live_url),
            untrailingslashit($live_url),
            str_replace('/', '\/', untrailingslashit($live_url))
        );

        $content = str_replace($old_url_variations, $new_url_variations, $content);

        // Handle WPBakery specific ?id=123 in CSS background URLs
        // Since we already replaced IDs in a previous loop, we could have done this there,
        // but it's safer to do a dedicated pass for ?id= matches
        if (!empty($wpbakery_images)) {
            foreach ($wpbakery_images as $old_id => $old_url) {
                // We need to find the new_id again or store it from previous loop
                $new_id = stls_convert_image_data_to_attachment_id(array('url' => $old_url, 'attachment_id' => $old_id), $post_id);
                if ($new_id && !is_wp_error($new_id) && $new_id != $old_id) {
                    $content = str_replace('?id=' . $old_id, '?id=' . $new_id, $content);
                }
            }
        }

        $updated = true;
    }

    if ($updated) {
        kses_remove_filters();
        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => wp_slash($content) // SLASH content for wp_update_post!
        ));
        kses_init_filters();
    }
}
add_action('stls_after_sync_post_meta', 'stls_wpbakery_save_on_live', 11, 2);
