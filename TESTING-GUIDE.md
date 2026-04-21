# Testing Guide - Image Sync for Icon List

## What Was Fixed

The plugin now properly handles **ACF repeater fields with image URLs** (like Sef Icon List). Previously, it only detected:
- Direct attachment IDs
- ACF image arrays

Now it also detects:
- **Image URLs stored in repeater fields**
- Nested repeater structures
- URL-format ACF image fields

## How to Test

### 1. On Staging Site

1. **Edit the post** with ID 10058 (or create a new post)
2. **Add/Update "Sef Icon List" block**
3. **Upload a NEW image** to the Icon field (or change existing)
4. **Save the post**
5. **Click "Sync"** button

### 2. Check Debug Logs (Staging)

Open `/wp-content/debug.log` on staging and look for:

```
STLS: Found URL-format image in repeater - Key: sef_left_icon, URL: http://...
STLS Image Conversion: ID=123, URL=http://...
STLS Sync Success for Post 10058
```

**Expected:**
- You should see "Found URL-format image" for each icon in the repeater
- You should see "Image Conversion" for each image
- You should see "Sync Success"

### 3. Check Debug Logs (Live)

Open `/wp-content/debug.log` on live and look for:

```
STLS Download: Attempting to download image from: http://staging...
STLS Download: Successfully created attachment with ID: 456
STLS: Converted URL field - Old: http://staging... -> New: http://live...
```

**Expected:**
- You should see download attempts for each image
- You should see "Successfully created attachment"
- You should see "Converted URL field" showing old staging URL → new live URL

### 4. Verify on Live Site

1. **Edit the same post** on live site
2. **Check the Icon List block**
3. **Verify images are showing** in the Icon fields
4. **View the page** on frontend
5. **Images should be visible** in the Icon List

## Troubleshooting

### If Images Still Don't Sync:

1. **Check if images are detected on staging:**
   - Look for "Found URL-format image" in staging debug.log
   - If NOT found, images might be stored differently than expected

2. **Check if download happens on live:**
   - Look for "STLS Download" messages in live debug.log
   - If NOT found, data isn't reaching the live site (API key issue?)

3. **Check if images are in live media library:**
   - Go to Media Library on live site
   - Search for the image filename
   - If not found, download failed

4. **Check image URLs:**
   - On staging, inspect the Icon List block
   - Copy the image URL
   - Try opening it in a browser
   - If it doesn't load, the image file is missing

## What Gets Synced

The plugin now syncs:
- ✅ Post title, content, excerpt
- ✅ Post status, type, template
- ✅ Featured image
- ✅ ACF fields (all types)
- ✅ ACF Gutenberg blocks
- ✅ ACF image fields (ID format)
- ✅ ACF image fields (Array format)
- ✅ **ACF image fields (URL format) ← NEW**
- ✅ **Images in repeater fields ← NEW**
- ✅ **Nested repeater structures ← NEW**
- ✅ All post meta (including custom fields)
- ✅ Taxonomy terms

## Technical Details

### How URL Detection Works:

1. **Staging scans all post meta** for:
   - Numeric values (attachment IDs)
   - ACF arrays with 'ID' key
   - **String values that are image URLs**

2. **URL identification:**
   - Must contain `/wp-content/uploads/`
   - Must have image extension (jpg, png, gif, webp, svg, etc.)

3. **URL conversion:**
   - Converts URL → Attachment ID → Image Data
   - Marks as `was_url: true`
   - Sends to live site

4. **Live site processing:**
   - Downloads image from staging URL
   - Creates new attachment in live media library
   - If `was_url: true`, returns new live URL
   - If `was_url: false`, returns new attachment ID

This ensures ACF fields configured for "URL" return format work correctly.


