# Blog Import Plugin

Temporary plugin to import blog posts from Squarespace XML export to WordPress custom blog post type.

## Usage

### 1. Activate Plugin
Go to WordPress Admin → Plugins → Activate "Blog Import Tool"

### 2. Run Commands

**Dry run (10 posts):**
```bash
wp blog-import --dry-run --limit=10
```

**Dry run (15 posts):**
```bash
wp blog-import --dry-run --limit=15
```

**Real import (10 posts):**
```bash
wp blog-import --no-dry-run --limit=10
```

**Full import:**
```bash
wp blog-import --no-dry-run --limit=999999
```

## What It Does

- Imports posts from XML to `blog` custom post type
- Maps categories to `blog-category` taxonomy
- Maps tags to `blog-tag` taxonomy
- Preserves post content, excerpts, dates, authors
- Handles featured images (thumbnail IDs)

## After Import

1. Verify imported posts in WordPress admin
2. Check categories and tags are properly assigned
3. Test blog post display on frontend
4. **Delete this plugin** when import is complete

## Files

- `blog-import.php` - Main plugin file
- `includes/class-import-command.php` - WP-CLI command class
- `data/Squarespace-Wordpress-Export-10-09-2025.xml` - Source XML file
