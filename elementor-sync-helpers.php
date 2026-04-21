<?php

/**
 * Recursively download images from Elementor data
 * 
 * @param array &$data Elementor data array (passed by reference)
 * @param int $post_id Post ID
 * @param string $staging_url Staging URL
 * @param array &$downloaded_images Map of staging_url => live_url (passed by reference)
 * @param array &$id_map Map of staging_id => live_id (passed by reference)
 */
function stls_elementor_download_images_recursive(&$data, $post_id, $staging_url, &$downloaded_images, &$id_map = array())
{
    if (!is_array($data)) {
        if (is_string($data) && !empty($data)) {
            // Check if this string looks like HTML and might contain images
            if (stls_elementor_contains_html_in_structure($data)) {
                stls_elementor_process_html_for_images($data, $post_id, $staging_url, $downloaded_images, $id_map);
            }
        }
        return;
    }

    $staging_url = untrailingslashit($staging_url);

    foreach ($data as $key => &$value) {
        if (is_array($value)) {
            // Check if this is an image object with URL
            if (isset($value['url']) && is_string($value['url'])) {
                $image_url = $value['url'];

                // Only download if it's from staging (check variations)
                $is_staging_image = (strpos($image_url, $staging_url) !== false);

                if ($is_staging_image) {
                    // Store staging ID if it exists
                    $staging_id = isset($value['id']) ? intval($value['id']) : 0;

                    // Download the image
                    $attachment_id = stls_download_and_attach_image($image_url, $post_id, true);

                    if ($attachment_id && !is_wp_error($attachment_id)) {
                        $new_url = wp_get_attachment_url($attachment_id);
                        if ($new_url) {
                            // Store the mapping
                            $downloaded_images[$image_url] = $new_url;

                            if ($staging_id > 0) {
                                $id_map[$staging_id] = intval($attachment_id);
                            }

                            // Update the URL and ID in the data
                            $value['url'] = $new_url;
                            if (isset($value['id'])) {
                                $value['id'] = $attachment_id;
                            }
                        }
                    }
                }
            } else {
                // Recursively process nested arrays
                stls_elementor_download_images_recursive($value, $post_id, $staging_url, $downloaded_images, $id_map);
            }
        } elseif (is_string($value) && !empty($value)) {
            // Check if this string looks like HTML and might contain images
            if (stls_elementor_contains_html_in_structure($value)) {
                stls_elementor_process_html_for_images($value, $post_id, $staging_url, $downloaded_images, $id_map);
            }
        }
    }
}

/**
 * Identify and download images within an HTML string
 * 
 * @param string &$html HTML content (passed by reference)
 * @param int $post_id Post ID
 * @param string $staging_url Staging URL
 * @param array &$downloaded_images Map of staging_url => live_url (passed by reference)
 * @param array &$id_map Map of staging_id => live_id (passed by reference)
 */
function stls_elementor_process_html_for_images(&$html, $post_id, $staging_url, &$downloaded_images, &$id_map)
{
    if (empty($html) || !is_string($html)) {
        return;
    }

    $staging_url = untrailingslashit($staging_url);
    $staging_url_pattern = preg_quote($staging_url, '/');

    // Pattern to find <img> tags with src from staging
    // Handles src="...", src='...', and escaped src=\"...\"
    $pattern = '/<img[^>]+src=(["\'\\\]+)(' . $staging_url_pattern . '[^"\'\\\]+)(["\'\\\]+)/i';

    if (preg_match_all($pattern, $html, $matches)) {
        foreach ($matches[2] as $index => $image_url) {
            // Normalize URL (unslash if needed)
            $normalized_url = str_replace('\\/', '/', $image_url);

            // Skip if already downloaded in this pass
            if (isset($downloaded_images[$normalized_url])) {
                $new_url = $downloaded_images[$normalized_url];
            } else {
                // Download the image
                $attachment_id = stls_download_and_attach_image($normalized_url, $post_id, true);

                if ($attachment_id && !is_wp_error($attachment_id)) {
                    $new_url = wp_get_attachment_url($attachment_id);
                    if ($new_url) {
                        $downloaded_images[$normalized_url] = $new_url;

                        // Try to find the attachment ID from the HTML (wp-image-XXX class)
                        $img_tag = $matches[0][$index];
                        if (preg_match('/wp-image-([0-9]+)/i', $img_tag, $id_matches)) {
                            $old_id = $id_matches[1];
                            $id_map[$old_id] = intval($attachment_id);

                            // Update ID class in HTML
                            $html = str_replace('wp-image-' . $old_id, 'wp-image-' . $attachment_id, $html);
                        }
                    } else {
                        continue;
                    }
                } else {
                    continue;
                }
            }

            // Replace URL in HTML (handle escaped slashes if original was escaped)
            $replacement_url = $new_url;
            if (strpos($image_url, '\\/') !== false) {
                $replacement_url = str_replace('/', '\\/', $new_url);
            }
            $html = str_replace($image_url, $replacement_url, $html);
        }
    }
}


/**
 * Recursively replace image URLs in Elementor data
 * 
 * @param array &$data Elementor data array (passed by reference)
 * @param array $url_map Map of old_url => new_url
 */
function stls_elementor_replace_image_urls_recursive(&$data, $url_map)
{
    if (!is_array($data)) {
        return;
    }

    foreach ($data as $key => &$value) {
        if (is_array($value)) {
            stls_elementor_replace_image_urls_recursive($value, $url_map);
        } elseif (is_string($value)) {
            // Replace any matching URLs
            foreach ($url_map as $old_url => $new_url) {
                if (strpos($value, $old_url) !== false) {
                    $value = str_replace($old_url, $new_url, $value);
                }
            }
        }
    }
}

/**
 * Thoroughly replace URLs in a string, handling variations and escaped slashes
 * 
 * @param string $content Content to process
 * @param string $staging_url Staging URL
 * @param string $live_url Live URL
 * @return string Processed content
 */
function stls_elementor_replace_urls_thoroughly($content, $staging_url, $live_url)
{
    if (empty($staging_url) || !is_string($content)) {
        return $content;
    }

    $staging_url = untrailingslashit($staging_url);
    $live_url = untrailingslashit($live_url);

    // Variations of staging URL
    $variations = array(
        // Full URL variations
        trailingslashit($staging_url),
        $staging_url,
        // Escaped variations (for JSON) e.g. http:\/\/domain\/
        str_replace('/', '\/', trailingslashit($staging_url)),
        str_replace('/', '\/', $staging_url),
        // Slashed variations (in case they are double escaped or something weird)
        str_replace('/', '\\\/', trailingslashit($staging_url)),
        str_replace('/', '\\\/', $staging_url),
    );

    // Corresponding live URL variations
    $replacements = array(
        trailingslashit($live_url),
        $live_url,
        str_replace('/', '\/', trailingslashit($live_url)),
        str_replace('/', '\/', $live_url),
        str_replace('/', '\/', trailingslashit($live_url)), // Keep it consistent
        str_replace('/', '\/', $live_url),
    );

    // Domain variations (without protocol)
    $staging_domain = str_replace(array('http://', 'https://'), '', $staging_url);
    $live_domain = str_replace(array('http://', 'https://'), '', $live_url);

    if (!empty($staging_domain)) {
        $variations[] = trailingslashit($staging_domain);
        $variations[] = $staging_domain;
        $variations[] = str_replace('/', '\/', trailingslashit($staging_domain));
        $variations[] = str_replace('/', '\/', $staging_domain);

        $replacements[] = trailingslashit($live_domain);
        $replacements[] = $live_domain;
        $replacements[] = str_replace('/', '\/', trailingslashit($live_domain));
        $replacements[] = str_replace('/', '\/', $live_domain);
    }

    return str_replace($variations, $replacements, $content);
}