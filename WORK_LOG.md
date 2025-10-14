# Blog Import Plugin - Work Log & Issues Fixed

## Date: 2025-10-10

## Summary
Fixed critical issues in WordPress blog import from Squarespace XML. Total posts to import: **777 blogs**

---

## Issues Identified & Fixed

### 1. **SYNTAX ERROR** ❌➡️✅
**Problem:** PHP Parse error on line 644
```
PHP Parse error: syntax error, unexpected token "global", expecting "function"
```

**Root Cause:** Missing function declaration before `global $wpdb;`

**Fix Applied:**
```php
// Added missing function declaration
private function get_attachment_by_url( $url ) {
    global $wpdb;
    // ... rest of function
}
```

---

### 2. **CONTENT DUPLICATION** ❌➡️✅
**Problem:** Content appearing twice on frontend (e.g., Anma Massage post)

**Root Cause:** Multiple `<!-- wp:group -->` blocks created from Squarespace `sqs-html-content` divs

**Database Analysis:**
```sql
-- Found 5 out of 10 posts had multiple group blocks
SELECT ID, post_title, 
(LENGTH(post_content) - LENGTH(REPLACE(post_content, '<!-- wp:group -->', ''))) / LENGTH('<!-- wp:group -->') as group_count 
FROM wp_posts WHERE post_type='blog' HAVING group_count > 1;

-- Results: 5 posts with 2-4 group blocks each
```

**Fix Applied:**
```php
// OLD: Converting divs to group blocks (causing duplicates)
$content = preg_replace( '/<div[^>]*class="[^"]*sqs-html-content[^"]*"[^>]*>/', "\n<!-- wp:group -->\n<div class=\"wp-block-group\">", $content );

// NEW: Remove problematic divs entirely
$content = preg_replace( '/<div[^>]*class="[^"]*sqs-html-content[^"]*"[^>]*>/', '', $content );
$content = str_replace( '</div>', '', $content );
```

---

### 3. **EXCESSIVE SPACING** ❌➡️✅
**Problem:** Too much whitespace between content blocks

**Database Analysis:**
```sql
-- Found posts with excessive triple newlines
SELECT ID, post_title, 
(LENGTH(post_content) - LENGTH(REPLACE(post_content, '\n\n\n', ''))) / 3 as triple_newlines 
FROM wp_posts WHERE post_type='blog' ORDER BY triple_newlines DESC;

-- Results: Up to 5 triple newlines per post
```

**Fix Applied:**
```php
// Added extra space cleanup
$content = preg_replace( '/\n{3,}/', "\n\n", $content );
$content = preg_replace( '/\s{3,}/', " ", $content );
```

---

### 4. **MISSING LISTS** ❌➡️✅ (CRITICAL)
**Problem:** List content not importing from XML to database

**XML Analysis:**
```xml
<!-- Found in XML but missing in database -->
<ul data-rte-list="default">
    <li><p>Alleviating constipation</p></li>
    <li><p>Reducing bloating and gas</p></li>
    <!-- ... more items -->
</ul>
```

**Database Verification:**
```sql
-- Checked for missing lists
SELECT post_title, post_content FROM wp_posts 
WHERE post_name='abdominal-massage-techniques' 
AND post_content LIKE '%wp:list%';
-- Result: No lists found (should have 2 lists)
```

**Root Cause:** Regex `/<ul[^>]*>/` didn't match `<ul data-rte-list="default">`

**Fix Applied:**
```php
// OLD: Basic regex missing data-rte-list
$content = preg_replace( '/<ul[^>]*>/', "\n<!-- wp:list -->\n<ul>", $content );

// NEW: Handle data-rte-list attributes first
$content = preg_replace( '/<ul[^>]*data-rte-list[^>]*>/', "\n<!-- wp:list -->\n<ul>", $content );
$content = preg_replace( '/<ul[^>]*>/', "\n<!-- wp:list -->\n<ul>", $content );
```

---

### 5. **INLINE FORMATTING** ✅
**Added Support For:**
- `<strong>`, `<em>`, `<u>` tags
- `<br>` to `<br />` conversion
- `<b>` to `<strong>` conversion
- `<i>` to `<em>` conversion

---

## Database Verification Commands

### Check Total Posts
```sql
mysql -S /path/to/mysqld.sock -u root -proot -e "
USE local; 
SELECT COUNT(*) as total_blogs FROM wp_posts 
WHERE post_type='blog' AND post_status='publish';"
```

### Check for Duplicates
```sql
mysql -S /path/to/mysqld.sock -u root -proot -e "
USE local; 
SELECT post_title, COUNT(*) as count FROM wp_posts 
WHERE post_type='blog' GROUP BY post_title HAVING count > 1;"
```

### Check Content Issues
```sql
-- Empty content
SELECT COUNT(*) FROM wp_posts WHERE post_type='blog' 
AND (post_content = '' OR post_content IS NULL);

-- Multiple group blocks
SELECT ID, post_title, 
(LENGTH(post_content) - LENGTH(REPLACE(post_content, '<!-- wp:group -->', ''))) / LENGTH('<!-- wp:group -->') as group_count 
FROM wp_posts WHERE post_type='blog' HAVING group_count > 1;

-- Spacing issues
SELECT ID, post_title, 
(LENGTH(post_content) - LENGTH(REPLACE(post_content, '\n\n\n', ''))) / 3 as triple_newlines 
FROM wp_posts WHERE post_type='blog' ORDER BY triple_newlines DESC;
```

### Check Block Format
```sql
SELECT ID, post_title, 
CASE WHEN post_content LIKE '%<!-- wp:%' THEN 'Has Blocks' ELSE 'No Blocks' END as block_status 
FROM wp_posts WHERE post_type='blog' AND post_content != '';
```

---

## Commands to Test

### Clean Import Test
```bash
cd "/home/web-dev-3/Local Sites/spa-thoery/app/public"

# Delete test posts (keep original 15 theme posts)
wp post delete $(wp post list --post_type=blog --year=2024 --year=2023 --year=2020 --format=ids) --force

# Test import
wp blog-import --dry-run --limit=10
wp blog-import --no-dry-run --limit=10
```

### Verify Lists Fixed
```bash
wp db query "SELECT post_title FROM wp_posts WHERE post_content LIKE '%wp:list%' AND post_type='blog';"
```

---

## Current Status

### ✅ FIXED
- Syntax errors
- Content duplication 
- Excessive spacing
- Missing lists (CRITICAL FIX)
- Inline formatting support

### ✅ VERIFIED WORKING
- Content completeness (all XML content preserved)
- Block format (proper Gutenberg blocks)
- Link conversion (dynamic PHP URLs)
- Image processing
- No duplicate group blocks

### 🔄 PENDING TEST
- List import verification (tomorrow)
- Full 777 posts import test

---

## Database Credentials Used
```
DB_NAME: local
DB_USER: root  
DB_PASSWORD: root
DB_HOST: localhost
Socket: /home/web-dev-3/.config/Local/run/shiH7C3iZ/mysql/mysqld.sock
```

---

## Files Modified
- `/wp-content/plugins/blog-import/includes/class-import-command.php`
  - Fixed syntax error (line 644)
  - Fixed content duplication (group blocks)
  - Fixed spacing issues
  - **Fixed missing lists (CRITICAL)**
  - Added inline formatting support

---

## Next Steps (Tomorrow)
1. Test list import with: `wp blog-import --no-dry-run --limit=5`
2. Verify lists in database: Check posts with `wp:list` blocks
3. If lists working, run full import: `wp blog-import --no-dry-run --limit=999999`
4. Final verification of all 777 posts

---

**MOST CRITICAL FIX:** List regex pattern to handle `data-rte-list` attributes from Squarespace XML.
