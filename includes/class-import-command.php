<?php

/**
 * WP-CLI Command for Blog Import
 *
 * Usage: wp blog-import --dry-run --limit=10
 */

if (! class_exists('WP_CLI')) {
	return;
}

class Blog_Import_Command extends WP_CLI_Command
{

	/**
	 * Import blog posts from XML
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Run in dry-run mode (default: true)
	 *
	 * [--limit=<number>]
	 * : Number of posts to process (default: 10)
	 *
	 * [--offset=<number>]
	 * : Skip a number of posts before starting (default: 0).
	 * 
	 * ## EXAMPLES
	 *
	 *     wp blog-import --dry-run --limit=10
	 *     wp blog-import --no-dry-run --limit=15
	 *     wp blog-import --no-dry-run --limit=999999
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function __invoke($args, $assoc_args)
	{
		$dry_run = WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', true);
		$limit   = WP_CLI\Utils\get_flag_value($assoc_args, 'limit', 10);
		$offset  = WP_CLI\Utils\get_flag_value($assoc_args, 'offset', 0);


		$importer = new BlogPostImporter($dry_run, $limit, $offset);
		$importer->import();
	}
}

class BlogPostImporter
{

	private $xml_file;
	private $dry_run;
	private $limit;
	private $offset;
	private $errors         = array();
	private $success        = array();
	private $attachments    = array();
	private $link_report    = array();
	private $import_report  = array();
	private $image_failures = array();
	private $post_failures  = array();

	public function __construct($dry_run = true, $limit = 10, $offset = 0)
	{
		$this->xml_file = BLOG_IMPORT_PLUGIN_DIR . 'data/Squarespace-Wordpress-Export-10-09-2025.xml';
		$this->dry_run  = $dry_run;
		$this->limit    = $limit + $offset;
		$this->offset   = $offset;
		WP_CLI::line(sprintf('Starting import - Dry Run: %s, Limit: %d', $this->dry_run ? 'YES' : 'NO', $this->limit));
	}

	public function import()
	{
		try {
			if (! file_exists($this->xml_file)) {
				$this->log_error('XML file not found: ' . $this->xml_file);
				return false;
			}

			$xml = $this->load_xml();
			if (! $xml) {
				return false;
			}

			// First pass: collect all attachments
			$this->collect_attachments($xml);

			// Second pass: process blog posts
			$this->process_posts($xml);

			$this->print_results();
		} catch (Exception $e) {
			$this->log_error('Fatal error: ' . $e->getMessage());
			WP_CLI::error('Import failed. Check error log above.');
		}
	}

	private function load_xml()
	{
		try {
			libxml_use_internal_errors(true);
			$xml = simplexml_load_file($this->xml_file);

			if (false === $xml) {
				$errors = libxml_get_errors();
				foreach ($errors as $error) {
					$this->log_error('XML Parse Error: ' . trim($error->message));
				}
				return false;
			}

			WP_CLI::line('XML loaded successfully');
			return $xml;
		} catch (Exception $e) {
			$this->log_error('Failed to load XML: ' . $e->getMessage());
			return false;
		}
	}

	private function collect_attachments($xml)
	{
		$namespaces = $xml->getNamespaces(true);

		foreach ($xml->channel->item as $item) {
			$wp        = $item->children($namespaces['wp']);
			$post_type = (string) $wp->post_type;

			if ('attachment' === $post_type) {
				$attachment_id  = (string) $wp->post_id;
				$attachment_url = (string) $wp->attachment_url;

				if ($attachment_url) {

					$this->attachments[$attachment_id] = $attachment_url;
				}
			}
		}

		WP_CLI::line(sprintf('Found %d attachments', count($this->attachments)));
	}

	private function process_posts($xml)
	{
		$processed   = 0;
		$namespaces  = $xml->getNamespaces(true);
		$total_items = count($xml->channel->item);

		WP_CLI::line(sprintf('Processing posts from %d total items...', $total_items));

		foreach ($xml->channel->item as $item) {


			if ($processed >= $this->limit) {
				break;
			}

			try {
				if (! $this->is_blog_post($item, $namespaces)) {
					continue;
				}
				if ($processed < $this->offset) {
					$processed++;
					continue;
				}
				$post_data = $this->extract_post_data($item, $namespaces);

				error_log(print_r($post_data, true));
				// Analyze links in content
				$links               = $this->analyze_post_links($post_data);
				$this->link_report[] = $links;
				error_log('post slug: ' . $post_data['slug']);

				WP_CLI::line(sprintf('Processing %d/%d: %s', $processed + 1, $this->limit, $post_data['title']));

				if ($this->dry_run) {
					$this->log_success('DRY RUN - Would import: ' . $post_data['title']);
					$this->print_post_preview($post_data);

					// Add to import report for dry run
					$this->import_report[] = array(
						'status'       => 'DRY_RUN',
						'title'        => $post_data['title'],
						'slug'         => $post_data['slug'],
						'original_url' => '/spa-theory-wellness-beauty-blog/' . $post_data['slug'],
						'new_url'      => home_url('/blog/' . $post_data['slug'] . '/'),
						'post_id'      => 'N/A',
						'links_count'  => count($links['links']),
					);
				} else {
					$post_id = $this->create_post($post_data);
					if ($post_id) {
						$this->log_success('Imported post ID: ' . $post_id . ' - ' . $post_data['title']);

						// Add to import report
						$this->import_report[] = array(
							'status'       => 'SUCCESS',
							'title'        => $post_data['title'],
							'slug'         => $post_data['slug'],
							'original_url' => '/spa-theory-wellness-beauty-blog/' . $post_data['slug'],
							'new_url'      => home_url('/blog/' . $post_data['slug'] . '/'),
							'post_id'      => $post_id,
							'links_count'  => count($links['links']),
						);
					} else {
						$this->import_report[] = array(
							'status'       => 'FAILED',
							'title'        => $post_data['title'],
							'slug'         => $post_data['slug'],
							'original_url' => '/spa-theory-wellness-beauty-blog/' . $post_data['slug'],
							'new_url'      => 'N/A',
							'post_id'      => 'N/A',
							'links_count'  => count($links['links']),
						);
					}
				}

				++$processed;
			} catch (Exception $e) {
				$this->log_error('Error processing post: ' . $e->getMessage());
				continue;
			}
		}

		WP_CLI::line(sprintf('Completed processing %d posts', $processed));

		// Generate reports
		$this->generate_reports();
	}

	private function analyze_post_links($post_data)
	{
		$content = $post_data['content'];
		$links   = array();

		// Find all links in content
		preg_match_all('/href="([^"]+)"/', $content, $matches);

		if (! empty($matches[1])) {
			foreach ($matches[1] as $link) {
				$links[] = $link;
			}
		}

		return array(
			'post_id'     => 'N/A',
			'title'       => $post_data['title'],
			'slug'        => $post_data['slug'],
			'total_links' => count($links),
			'links'       => $links,
		);
	}

	private function generate_reports()
	{
		// Create results directory
		$results_dir = BLOG_IMPORT_PLUGIN_DIR . 'results';
		if (! file_exists($results_dir)) {
			wp_mkdir_p($results_dir);
		}

		$timestamp = date('Y-m-d_H-i-s');

		// Generate links report
		$this->generate_links_report($results_dir, $timestamp);

		// Generate import report
		$this->generate_import_report($results_dir, $timestamp);

		// Generate error reports
		$this->generate_image_failures_report($results_dir, $timestamp);
		$this->generate_post_failures_report($results_dir, $timestamp);

		WP_CLI::line('');
		WP_CLI::line('=== REPORTS GENERATED ===');
		WP_CLI::line('Links Report: ' . $results_dir . '/links_report_' . $timestamp . '.txt');
		WP_CLI::line('Import Report: ' . $results_dir . '/import_report_' . $timestamp . '.txt');
		WP_CLI::line('Image Failures: ' . $results_dir . '/image_failures_' . $timestamp . '.txt');
		WP_CLI::line('Post Failures: ' . $results_dir . '/post_failures_' . $timestamp . '.txt');
	}

	private function generate_links_report($results_dir, $timestamp)
	{
		$report_content  = "BLOG POSTS LINKS ANALYSIS REPORT\n";
		$report_content .= 'Generated: ' . date('Y-m-d H:i:s') . "\n";
		$report_content .= 'Mode: ' . ($this->dry_run ? 'DRY RUN' : 'REAL IMPORT') . "\n";
		$report_content .= str_repeat('=', 80) . "\n\n";

		foreach ($this->link_report as $post_links) {
			$report_content .= 'POST: ' . $post_links['title'] . "\n";
			$report_content .= 'SLUG: ' . $post_links['slug'] . "\n";
			$report_content .= 'TOTAL LINKS: ' . $post_links['total_links'] . "\n";
			$report_content .= str_repeat('-', 40) . "\n";

			if (! empty($post_links['links'])) {
				foreach ($post_links['links'] as $index => $link) {
					$report_content .= sprintf("%d. %s\n", $index + 1, $link);
				}
			} else {
				$report_content .= "No links found in this post.\n";
			}

			$report_content .= "\n" . str_repeat('=', 80) . "\n\n";
		}

		file_put_contents($results_dir . '/links_report_' . $timestamp . '.txt', $report_content);
	}

	private function generate_import_report($results_dir, $timestamp)
	{
		$report_content  = "BLOG IMPORT MAPPING REPORT\n";
		$report_content .= 'Generated: ' . date('Y-m-d H:i:s') . "\n";
		$report_content .= 'Mode: ' . ($this->dry_run ? 'DRY RUN' : 'REAL IMPORT') . "\n";
		$report_content .= str_repeat('=', 80) . "\n\n";

		foreach ($this->import_report as $import) {
			$report_content .= 'STATUS: ' . $import['status'] . "\n";
			$report_content .= 'TITLE: ' . $import['title'] . "\n";
			$report_content .= 'POST ID: ' . $import['post_id'] . "\n";
			$report_content .= 'SLUG: ' . $import['slug'] . "\n";
			$report_content .= 'FROM: ' . $import['original_url'] . "\n";
			$report_content .= 'TO: ' . $import['new_url'] . "\n";
			$report_content .= 'LINKS COUNT: ' . $import['links_count'] . "\n";
			$report_content .= str_repeat('-', 80) . "\n\n";
		}

		file_put_contents($results_dir . '/import_report_' . $timestamp . '.txt', $report_content);
	}

	private function generate_image_failures_report($results_dir, $timestamp)
	{
		$report_content  = "IMAGE IMPORT FAILURES REPORT\n";
		$report_content .= 'Generated: ' . date('Y-m-d H:i:s') . "\n";
		$report_content .= 'Total Failures: ' . count($this->image_failures) . "\n";
		$report_content .= str_repeat('=', 80) . "\n\n";

		if (empty($this->image_failures)) {
			$report_content .= "No image import failures!\n";
		} else {
			foreach ($this->image_failures as $failure) {
				$report_content .= 'TIMESTAMP: ' . $failure['timestamp'] . "\n";
				$report_content .= 'IMAGE URL: ' . $failure['image_url'] . "\n";
				$report_content .= 'POST ID: ' . ($failure['post_id'] ?: 'N/A') . "\n";
				$report_content .= 'ERROR: ' . $failure['error'] . "\n";

				// Try to find which post this image belongs to
				if ($failure['post_id']) {
					$post = get_post($failure['post_id']);
					if ($post) {
						$report_content .= 'POST TITLE: ' . $post->post_title . "\n";
						$report_content .= 'POST URL: ' . home_url('/blog/' . $post->post_name . '/') . "\n";
					}
				}

				$report_content .= str_repeat('-', 80) . "\n\n";
			}
		}

		file_put_contents($results_dir . '/image_failures_' . $timestamp . '.txt', $report_content);
	}

	private function generate_post_failures_report($results_dir, $timestamp)
	{
		$report_content  = "POST IMPORT FAILURES REPORT\n";
		$report_content .= 'Generated: ' . date('Y-m-d H:i:s') . "\n";
		$report_content .= 'Total Failures: ' . count($this->post_failures) . "\n";
		$report_content .= str_repeat('=', 80) . "\n\n";

		if (empty($this->post_failures)) {
			$report_content .= "No post import failures!\n";
		} else {
			foreach ($this->post_failures as $failure) {
				$report_content .= 'TIMESTAMP: ' . $failure['timestamp'] . "\n";
				$report_content .= 'POST TITLE: ' . $failure['title'] . "\n";
				$report_content .= 'POST SLUG: ' . $failure['slug'] . "\n";
				$report_content .= 'ORIGINAL URL: ' . $failure['original_url'] . "\n";
				$report_content .= 'ERROR: ' . $failure['error'] . "\n";
				$report_content .= str_repeat('-', 80) . "\n\n";
			}
		}

		file_put_contents($results_dir . '/post_failures_' . $timestamp . '.txt', $report_content);
	}

	private function is_blog_post($item, $namespaces)
	{
		$wp        = $item->children($namespaces['wp']);
		$post_type = (string) $wp->post_type;
		$status    = (string) $wp->status;
		return ('post' === $post_type);
	}

	private function extract_post_data($item, $namespaces)
	{
		$wp      = $item->children($namespaces['wp']);
		$content = $item->children($namespaces['content']);
		$excerpt = $item->children($namespaces['excerpt']);
		$dc      = $item->children($namespaces['dc']);

		$post_data = array(
			'title'             => (string) $item->title,
			'content'           => (string) $content->encoded,
			'excerpt'           => (string) $excerpt->encoded,
			'slug'              => (string) $wp->post_name,
			'date'              => (string) $wp->post_date,
			'status'            => (string) $wp->status,
			'author'            => (string) $dc->creator,
			'categories'        => array(),
			'tags'              => array(),
			'featured_image_id' => null,
		);

		foreach ($item->category as $category) {
			$domain = (string) $category['domain'];
			$term   = (string) $category;

			if ('category' === $domain) {
				$post_data['categories'][] = $term;
			} elseif ('post_tag' === $domain) {
				$post_data['tags'][] = $term;
			}
		}

		// Extract featured image from postmeta
		foreach ($wp->postmeta as $meta) {
			if ('_thumbnail_id' === (string) $meta->meta_key) {
				$post_data['featured_image_id'] = (string) $meta->meta_value;
				break;
			}
		}

		return $post_data;
	}

	private function create_post($post_data)
	{
		$author_id = $this->get_or_create_author($post_data['author']);

		if (empty($post_data['content']) || empty($post_data['title'])) {
			$this->log_error('Post content is empty for: ' . $post_data['title']);
			return false;
		}
		// Process content images and links
		// error_log('*******************************');
		// error_log('ORIGINAL CONTENT: ' . print_r($post_data['content'], true));
		// error_log(' ');
		// error_log(' ################################################');
		// error_log(' ');


		$processed_content = $this->process_content_images($post_data['content'], $post_data['featured_image_id']);
		$processed_content = $this->process_content_links($processed_content);
		$processed_content = $this->convert_html_to_blocks($processed_content);
		// error_log('MODIFIED CONTENT: ' . print_r($processed_content, true));
		// error_log('');
		// error_log('');

		$post_args = array(
			'post_title'   => $post_data['title'],
			'post_content' => $processed_content,
			'post_excerpt' => $post_data['excerpt'],
			'post_name'    => $post_data['slug'],
			'post_date'    => $post_data['date'],
			'post_status'  => $post_data['status'],
			'post_type'    => 'blog',
			'post_author'  => $author_id,
		);

		$post_id = wp_insert_post($post_args, true);

		if (is_wp_error($post_id)) {
			$this->log_error('Failed to create post: ' . $post_id->get_error_message());
			return false;
		}

		// Set categories
		if (! empty($post_data['categories'])) {
			$this->set_post_terms($post_id, $post_data['categories'], 'blog-category');
		}

		// Set tags
		if (! empty($post_data['tags'])) {
			$this->set_post_terms($post_id, $post_data['tags'], 'blog-tag');
		}

		// Set featured image
		if ($post_data['featured_image_id'] && isset($this->attachments[$post_data['featured_image_id']])) {
			$featured_image_id = $this->import_image($this->attachments[$post_data['featured_image_id']], $post_id);
			if ($featured_image_id) {
				set_post_thumbnail($post_id, $featured_image_id);
			}
		}

		return $post_id;
	}

	private function process_content_images($content, $featured_image_id)
	{
		// Find all image URLs in content
		preg_match_all('/src="([^"]*squarespace-cdn\.com[^"]*)"/', $content, $matches);
		if (empty($matches[1])) {
			return $content;
		}
		$imported_featured_image_id = 0;
		$imported_featured_image_url = '';
		if ($featured_image_id && isset($this->attachments[$featured_image_id])) {
			$imported_featured_image_id = $this->import_image($this->attachments[$featured_image_id]);
			$imported_featured_image_url = wp_get_attachment_url($imported_featured_image_id);
		}
		foreach ($matches[1] as $image_url) {
			try {
				$new_attachment_id = $this->import_image($image_url);

				if ($new_attachment_id) {
					$new_image_url = wp_get_attachment_url($new_attachment_id);
					$imported_filename = basename($imported_featured_image_url);
					$new_filename = basename($new_image_url);
					if ($imported_filename === $new_filename) {
						$quoted_filename = preg_quote(basename($image_url), '/');
						// Build a regex pattern that matches the entire image block containing that filename
						$pattern = '/<img[^>]*' . $quoted_filename . '[^>]*>/i';
						$content = preg_replace($pattern, '', $content);
					} else {
						$content       = str_replace($image_url, $new_image_url, $content);
					}
				}
			} catch (Exception $e) {
				$this->log_error('Failed to process content image: ' . $image_url . ' - ' . $e->getMessage());
			}
		}
		return $content;
	}

	private function import_image($image_url, $post_id = 0)
	{
		try {
			// Check if already imported
			$existing = $this->get_attachment_by_url($image_url);
			if ($existing) {
				return $existing;
			}

			// Download image
			$tmp_file = download_url($image_url);
			if (is_wp_error($tmp_file)) {
				$this->log_image_failure($image_url, $post_id, 'Download failed: ' . $tmp_file->get_error_message());
				return false;
			}

			// Get proper filename with extension
			$filename = $this->get_proper_filename($image_url, $tmp_file);

			// Get file info
			$file_array = array(
				'name'     => $filename,
				'tmp_name' => $tmp_file,
			);

			// Import to media library
			$attachment_id = media_handle_sideload($file_array, $post_id);

			if (is_wp_error($attachment_id)) {
				@unlink($tmp_file);
				$this->log_image_failure($image_url, $post_id, 'Import failed: ' . $attachment_id->get_error_message());
				return false;
			}

			// Store original URL for reference
			update_post_meta($attachment_id, '_original_url', $image_url);

			return $attachment_id;
		} catch (Exception $e) {
			$this->log_image_failure($image_url, $post_id, 'Exception: ' . $e->getMessage());
			return false;
		}
	}

	private function get_proper_filename($image_url, $tmp_file)
	{
		// Get original filename
		$original_name = basename(parse_url($image_url, PHP_URL_PATH));

		// If no extension, detect from file content
		if (! pathinfo($original_name, PATHINFO_EXTENSION)) {
			$finfo     = finfo_open(FILEINFO_MIME_TYPE);
			$mime_type = finfo_file($finfo, $tmp_file);
			finfo_close($finfo);

			$extensions = array(
				'image/jpeg' => '.jpg',
				'image/png'  => '.png',
				'image/gif'  => '.gif',
				'image/webp' => '.webp',
			);

			if (isset($extensions[$mime_type])) {
				$original_name .= $extensions[$mime_type];
			} else {
				$original_name .= '.webp'; // Default fallback
			}
		}

		return $original_name;
	}

	private function log_image_failure($image_url, $post_id, $error_message)
	{
		$this->image_failures[] = array(
			'image_url' => $image_url,
			'post_id'   => $post_id,
			'error'     => $error_message,
			'timestamp' => date('Y-m-d H:i:s'),
		);

		$this->log_error('Failed to import image: ' . $image_url . ' - ' . $error_message);
	}

	private function process_content_links($content)
	{
		// First: Convert blog links to WordPress blog structure
		$content = preg_replace_callback(
			'/href="(?:https:\/\/www\.spatheory\.com)?\/spa-theory-wellness-beauty-blog\/([^"?#]+)(?:[?#][^"]*)?"/i',
			function ($matches) {
				$slug = $matches[1];
				return 'href="' . home_url('/blog/' . $slug . '/') . '"';
			},
			$content
		);

		// Second: Replace all remaining spatheory.com domains with current site
		$home_url = home_url();
		$content = str_replace('https://www.spatheory.com', $home_url, $content);

		return $content;
	}

	private function convert_html_to_blocks($content)
	{
		// WordPress built-in function to convert HTML to blocks
		if (function_exists('parse_blocks')) {
			// First try WordPress automatic conversion
			$blocks = parse_blocks($content);

			// If content is already in blocks format, return as is
			if (! empty($blocks) && ! empty($blocks[0]['blockName'])) {
				return $content;
			}
		}

		// Manual conversion for HTML content

		// 1. Paragraphs
		$content = preg_replace('/<p[^>]*>/', "\n<!-- wp:paragraph -->\n<p>", $content);
		$content = str_replace('</p>', "</p>\n<!-- /wp:paragraph -->\n", $content);

		// 2. Headings (H1-H6)
		$content = preg_replace('/<h([1-6])[^>]*>/', "\n<!-- wp:heading {\"level\":$1} -->\n<h$1>", $content);
		$content = preg_replace('/<\/h([1-6])>/', "</h$1>\n<!-- /wp:heading -->\n", $content);

		// 3. Images
		$content = preg_replace('/<img([^>]*)>/', "\n<!-- wp:image -->\n<figure class=\"wp-block-image\"><img$1></figure>\n<!-- /wp:image -->\n", $content);

		// 4. Lists (UL/OL) - handle data-rte-list attributes
		// Lists (UL/OL)
		$content = preg_replace('/<ul[^>]*>/', "\n<!-- wp:list -->\n<ul>", $content);
		$content = str_replace('</ul>', "</ul>\n<!-- /wp:list -->\n", $content);
		$content = preg_replace('/<ol[^>]*>/', "\n<!-- wp:list {\"ordered\":true} -->\n<ol>", $content);
		$content = str_replace('</ol>', "</ol>\n<!-- /wp:list -->\n", $content);

		// Remove Paragraph from LI tag
		$content = preg_replace('/<li>\n<!-- wp:paragraph -->\n<p>/', '<li>', $content);
		$content = preg_replace('/<\/p>\n<!-- \/wp:paragraph -->\n<\/li>/', '</li>', $content);


		// 5. Blockquotes
		$content = preg_replace('/<blockquote[^>]*>/', "\n<!-- wp:quote -->\n<blockquote>", $content);
		$content = str_replace('</blockquote>', "</blockquote>\n<!-- /wp:quote -->\n", $content);

		// add class  blockquotes to paragraph remvoing Blockquotes 
		$content = preg_replace(
			'/<!-- wp:quote -->\n<blockquote>\n<!-- wp:paragraph -->\n<p>/',
			"\n<!-- wp:paragraph -->\n<p class='blockquotes'>\n",
			$content
		);
		$content = preg_replace(
			'/<\/p><!-- \/wp:paragraph -->\n<\/blockquote>\n<!-- \/wp:quote -->/',
			"</p>\n<!-- /wp:paragraph -->\n",
			$content
		);

		// 6. Code blocks
		$content = preg_replace('/<pre[^>]*><code[^>]*>/', "\n<!-- wp:code -->\n<pre><code>", $content);
		$content = str_replace('</code></pre>', "</code></pre>\n<!-- /wp:code -->\n", $content);

		// 7. Simple code (inline)
		$content = preg_replace('/<pre[^>]*>/', "\n<!-- wp:preformatted -->\n<pre>", $content);
		$content = str_replace('</pre>', "</pre>\n<!-- /wp:preformatted -->\n", $content);

		// 8. Tables
		$content = preg_replace_callback('/<table.*?<\/table>/is', function ($matches) {
			$table = $matches[0];

			// Remove inline styles in <thead> or <tbody> (optional, for Gutenberg clean blocks)
			$table = preg_replace('/(<thead|<tbody)[^>]*>/i', '$1>', $table);

			// Trim whitespace from start/end of table
			$table = trim($table);

			// Wrap in clean Gutenberg block, no extra spaces/newlines
			return '<!-- wp:table --><figure class="wp-block-table">' . $table . '</figure><!-- /wp:table -->';
		}, $content);

		// 9. Remove problematic divs instead of converting to groups
		$content = preg_replace('/<div[^>]*class="[^"]*sqs-html-content[^"]*"[^>]*>/', '', $content);
		$content = str_replace('</div>', '', $content);

		// 10. Videos/Embeds
		$content = preg_replace('/<iframe[^>]*>.*?<\/iframe>/s', "\n<!-- wp:html -->\n$0\n<!-- /wp:html -->\n", $content);

		// 11. Inline formatting elements (preserve as-is)
		$content = str_replace(['<b>', '</b>'], ['<strong>', '</strong>'], $content);
		$content = str_replace(['<i>', '</i>'], ['<em>', '</em>'], $content);
		$content = str_replace('<br>', '<br />', $content);
		$content = str_replace('<hr>', '<hr />', $content);

		// Clean up extra newlines and empty blocks
		$content = preg_replace('/\n{3,}/', "\n\n", $content);
		$content = preg_replace('/\s{3,}/', " ", $content);
		$content = preg_replace('/<!-- wp:paragraph -->\s*<p>\s*<\/p>\s*<!-- \/wp:paragraph -->/', '', $content);

		return trim($content);
	}

	private function get_attachment_by_url($url)
	{
		global $wpdb;

		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_original_url' AND meta_value = %s",
				$url
			)
		);

		return $attachment_id ? (int) $attachment_id : false;
	}

	private function get_or_create_author($author_email)
	{
		$user = get_user_by('email', $author_email);
		return $user ? $user->ID : 1;
	}

	private function set_post_terms($post_id, $terms, $taxonomy)
	{
		$term_ids = array();

		foreach ($terms as $term_name) {
			$term = get_term_by('name', $term_name, $taxonomy);

			if (! $term) {
				$result = wp_insert_term($term_name, $taxonomy);
				if (! is_wp_error($result)) {
					$term_ids[] = $result['term_id'];
				}
			} else {
				$term_ids[] = $term->term_id;
			}
		}

		if (! empty($term_ids)) {
			wp_set_post_terms($post_id, $term_ids, $taxonomy);
		}
	}

	private function print_post_preview($post_data)
	{
		WP_CLI::line('  Title: ' . $post_data['title']);
		WP_CLI::line('  Slug: ' . $post_data['slug']);
		WP_CLI::line('  Date: ' . $post_data['date']);
		WP_CLI::line('  Author: ' . $post_data['author']);
		WP_CLI::line('  Categories: ' . implode(', ', $post_data['categories']));
		WP_CLI::line('  Tags: ' . implode(', ', $post_data['tags']));
		WP_CLI::line('  Content Length: ' . strlen($post_data['content']) . ' chars');
		WP_CLI::line('  Featured Image ID: ' . ($post_data['featured_image_id'] ?: 'None'));
		WP_CLI::line('  ---');
	}

	private function log_error($message)
	{
		$this->errors[] = $message;
		WP_CLI::warning($message);
	}

	private function log_success($message)
	{
		$this->success[] = $message;
		WP_CLI::success($message);
	}

	private function print_results()
	{
		WP_CLI::line('');
		WP_CLI::line('=== IMPORT RESULTS ===');
		WP_CLI::line('Successful: ' . count($this->success));
		WP_CLI::line('Errors: ' . count($this->errors));
		WP_CLI::line('Attachments Found: ' . count($this->attachments));

		if (! empty($this->errors)) {
			WP_CLI::line('');
			WP_CLI::line('=== ERRORS ===');
			foreach ($this->errors as $error) {
				WP_CLI::line('- ' . $error);
			}
		}
	}
}

WP_CLI::add_command('blog-import', 'Blog_Import_Command');
