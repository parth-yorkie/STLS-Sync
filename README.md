# Staging to Live Sync

A WordPress plugin that allows you to sync posts, pages, and custom post types from your staging environment to your live environment. Supports ACF fields and Gutenberg blocks.

## Features

- **Sync ALL post types** - Works with posts, pages, and all custom post types automatically
- **ACF Gutenberg Blocks Support** - Full compatibility with ACF Gutenberg blocks, including block field data
- **ACF Fields Support** - Complete support for ACF (Advanced Custom Fields) fields
- **Gutenberg Blocks** - Native Gutenberg block support
- **Taxonomy Terms** - Sync all taxonomy terms
- **Featured Images** - Sync featured images with alt text
- **Row Action Button** - Sync button appears in all post type admin lists
- **Configurable URLs** - Set staging and live URLs in settings
- **API Key Authentication** - Optional secure API key authentication with built-in generator

## Installation

1. Upload the `staging-to-live-sync` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Staging to Live Sync to configure your URLs

## Configuration

1. Navigate to **Settings > Staging to Live Sync**
2. Enter your **Staging URL** (e.g., `https://staging.example.com`)
3. Enter your **Live URL** (e.g., `https://example.com`)
4. **Generate API Keys** (Optional but recommended for security):
   - Click the **Generate** button next to "Live API Key" to create a secure random key
   - The generated key will appear in the input field
   - Click **Copy** to copy the key to your clipboard
   - **Important**: Use the same Live API Key on both your staging and live sites
   - The Staging API Key is optional and only needed for reverse sync operations
5. Click **Save Settings**

## Usage

1. Make sure you're working on your **staging site**
2. Navigate to any post type admin list (**Posts**, **Pages**, or any **Custom Post Type**)
3. Find the item you want to sync
4. Click the **Sync** link in the row actions (same row as Edit, Quick Edit, Trash, etc.)
5. The plugin will sync the post/page/custom post type to your live site
6. **All post types are automatically supported** - no configuration needed!

## What Gets Synced

- **Post Data**: Title, content, excerpt, status, slug/permalink
- **All Post Types**: Posts, pages, and all custom post types
- **ACF Fields**: All ACF field values and field objects
- **ACF Gutenberg Blocks**: Complete ACF block data including:
  - Block attributes
  - Block field values
  - Block field objects
  - Block metadata
- **Custom Meta Fields**: All custom post meta (excluding internal WordPress meta)
- **Taxonomy Terms**: All taxonomy terms for the post
- **Featured Image**: Featured image with alt text
- **Gutenberg Blocks**: All Gutenberg blocks in post content

## Requirements

- WordPress 5.0 or higher
- Both staging and live sites must have this plugin installed and activated
- REST API must be enabled on the live site

## Security

- The plugin uses WordPress nonces for security
- API key generation uses cryptographically secure random string generation
- Optionally uses API keys for authentication
- Requires appropriate user permissions

## API Key Setup (Recommended)

For secure authentication between staging and live sites:

1. **On Live Site:**
   - Go to Settings > Staging to Live Sync
   - Click "Generate" next to "Live API Key"
   - Copy the generated key

2. **On Staging Site:**
   - Go to Settings > Staging to Live Sync
   - Paste the same Live API Key you generated on the live site
   - Save settings

3. **Why use the same key?**
   - The staging site needs to authenticate with the live site
   - Both sites must have the same Live API Key configured
   - This ensures only authorized sync requests are processed

## Troubleshooting

### Sync button not appearing
- Make sure you've configured both Staging URL and Live URL in settings
- Verify you're on the staging site (URL matches configured staging URL)

### Sync fails
- Check that both sites have the plugin activated
- Verify REST API is enabled on live site
- Check API keys if configured
- Check error messages for specific issues

### ACF fields not syncing
- Make sure ACF is installed and active on both sites
- Verify field groups are the same on both sites

## Support

For issues and questions, please contact your developer or check the plugin documentation.

