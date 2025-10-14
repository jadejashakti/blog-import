<?php

// Test the link processing patterns
$test_content = '
<a href="/spa-theory-wellness-beauty-blog/exploring-different-types-of-massage-oils">massage oils</a>
<a href="https://www.spatheory.com/spa-theory-wellness-beauty-blog/hot-stone-massage-benefits">hot stone</a>
<a href="/spa-theory-wellness-beauty-blog/what-is-sports-massage">sports massage</a>
<a href="/spa-theory-wellness-beauty-blog?category=Swedish Massage">Swedish</a>
';

echo "Original content:\n";
echo $test_content . "\n\n";

// Apply the same patterns as in the import function
$patterns = array(
    // Pattern 1: /spa-theory-wellness-beauty-blog/post-slug
    '/href="\/spa-theory-wellness-beauty-blog\/([^"]+)"/',
    // Pattern 2: Full domain links to blog
    '/href="https?:\/\/[^\/]+\/spa-theory-wellness-beauty-blog\/([^"]+)"/',
);

$processed_content = $test_content;

foreach ( $patterns as $pattern ) {
    $processed_content = preg_replace_callback( $pattern, function( $matches ) {
        $slug = $matches[1];
        // Remove any query parameters or anchors
        $slug = preg_replace( '/[?#].*$/', '', $slug );
        // Create new WordPress blog URL
        $new_url = '/blog/' . $slug . '/';
        return 'href="' . $new_url . '"';
    }, $processed_content );
}

echo "Processed content:\n";
echo $processed_content . "\n";
