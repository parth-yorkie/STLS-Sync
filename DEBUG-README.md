# Debugging Image Sync Issues

If images aren't syncing from staging to live, follow these steps to debug:

## Step 1: Enable WordPress Debug Mode

Add these lines to your `wp-config.php` file on **both staging and live sites**:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

This will create a `debug.log` file in `/wp-content/` directory.

## Step 2: Perform a Sync

1. Upload a new image to an ACF Gutenberg block on staging
2. Save the post
3. Click the "Sync" button

## Step 3: Check Debug Logs

### On Staging Site

Check `/wp-content/debug.log` for lines starting with `STLS`:

```
STLS Image Conversion: ID=123, URL=https://staging.example.com/wp-content/uploads/2025/01/image.jpg
STLS Sync Success for Post 456
```

**Look for:**
- Are images being detected? (`STLS Image Conversion` lines)
- Are the URLs correct?
- Is the sync completing successfully?

### On Live Site

Check `/wp-content/debug.log` for lines starting with `STLS Download`:

```
STLS Download: Attempting to download image from: https://staging.example.com/...
STLS Download: File downloaded to: /tmp/...
STLS Download: Successfully created attachment with ID: 789
```

**Look for:**
- Is the download attempt happening?
- Are there any download errors?
- Is the attachment being created?

## Step 4: Common Issues

### Issue: "STLS Download Error: Could not access file"
**Solution:** The live site can't access the staging URL. Make sure:
- Staging site is publicly accessible
- No firewall blocking the live site from accessing staging
- URLs in settings are correct

### Issue: "STLS Upload Error: Sorry, this file type is not permitted"
**Solution:** The image type is not allowed. Check WordPress upload settings.

### Issue: No "STLS Download" lines in live site logs
**Solution:** Images aren't being sent. Check staging site logs to see if they're being detected.

### Issue: No "STLS Image Conversion" lines in staging logs
**Solution:** Images aren't being detected. This means:
- Images might not be saved properly in ACF
- Meta data isn't being scanned correctly

## Step 5: Manual Test

Try this in WordPress console on **staging site**:

```php
// Check if image exists in media library
$image_id = 123; // Replace with actual image ID
$image_url = wp_get_attachment_url( $image_id );
echo "Image URL: " . $image_url;
```

Then try accessing that URL in your browser. If it doesn't load, the image isn't accessible.

## Step 6: Share Debug Info

If still not working, share:
1. Relevant lines from staging `debug.log`
2. Relevant lines from live `debug.log`
3. The block type you're using
4. How you're uploading the image (ACF field type)

This will help identify exactly where the issue is occurring.


