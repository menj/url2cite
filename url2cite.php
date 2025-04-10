<?php
/**
 * Plugin Name: URL2Cite
 * Description: Generate citations from URLs via admin interface, shortcode, or auto-detection with multiple style support.
 * Version: 1.7.2
 * Author: MENJ
 * Author URI: https://menj.net
 * Text Domain: url2cite
 */

if (!defined('ABSPATH')) {
    exit;
}

class URL2Cite {
    private $citation_styles = [
        'apa' => 'APA',
        'mla' => 'MLA',
        'chicago' => 'Chicago',
        'harvard' => 'Harvard',
        'ieee' => 'IEEE',
        'vancouver' => 'Vancouver',
        'turabian' => 'Turabian',
        'bluebook' => 'Bluebook',
        'cse' => 'CSE',
        'wikipedia' => 'Wikipedia'
    ];

    public function __construct() {
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('rest_api_init', [$this, 'rest_api_init']);
        add_action('wp_footer', [$this, 'output_json_ld']);
        add_action('wp_ajax_url2cite_clear_cache', [$this, 'clear_cache']);
    }

    public function init() {
        add_shortcode('url2cite', [$this, 'shortcode_handler']);
        
        if (get_option('url2cite_auto_inject', true)) {
            add_filter('the_content', [$this, 'auto_detect_urls']);
        }
        
        if (function_exists('register_block_type')) {
            add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
        }
    }

    public function admin_menu() {
        add_menu_page(
            'URL2Cite',
            'URL2Cite',
            'manage_options',
            'url2cite',
            [$this, 'admin_page'],
            'dashicons-book-alt'
        );
        
        add_submenu_page(
            'url2cite',
            'URL2Cite Settings',
            'Settings',
            'manage_options',
            'url2cite-settings',
            [$this, 'settings_page']
        );
        
        register_setting('url2cite_settings', 'url2cite_default_style');
        register_setting('url2cite_settings', 'url2cite_cache_days');
        register_setting('url2cite_settings', 'url2cite_auto_inject');
        register_setting('url2cite_settings', 'url2cite_seo_microdata');
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'url2cite'));
        }
        
        echo '<div class="wrap"><h1>' . esc_html__('URL2Cite Citation Generator', 'url2cite') . '</h1>';
        
        if (isset($_POST['url2cite_url']) && check_admin_referer('url2cite_generate')) {
            $url = esc_url_raw($_POST['url2cite_url']);
            $citation = $this->generate_citation($url);
            
            if (is_wp_error($citation)) {
                echo '<div class="notice notice-error"><p>' . esc_html($citation->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><pre>' . esc_html($citation) . '</pre></div>';
            }
        }
        
        echo '<form method="post">';
        wp_nonce_field('url2cite_generate');
        echo '<input type="text" name="url2cite_url" 
               aria-label="' . esc_attr__('Enter URL to generate citation', 'url2cite') . '"
               style="width: 60%" 
               placeholder="' . esc_attr__('Enter URL', 'url2cite') . '" 
               required>
              <input type="submit" class="button-primary" value="' . esc_attr__('Generate Citation', 'url2cite') . '">';
        echo '</form></div>';
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'url2cite'));
        }
        
        echo '<div class="wrap"><h1>' . esc_html__('URL2Cite Settings', 'url2cite') . '</h1>
              <form method="post" action="options.php">';
        
        settings_fields('url2cite_settings');
        do_settings_sections('url2cite_settings');
        
        echo '<table class="form-table">
              <tr><th scope="row">' . esc_html__('Default Citation Style', 'url2cite') . '</th><td><select name="url2cite_default_style">';
        
        foreach ($this->citation_styles as $key => $style) {
            echo '<option value="' . esc_attr($key) . '" ' . selected(get_option('url2cite_default_style', 'apa'), $key, false) . '>' . esc_html($style) . '</option>';
        }
        
        echo '</select></td></tr>
              <tr><th scope="row">' . esc_html__('Cache Duration (days)', 'url2cite') . '</th><td>
              <input type="number" name="url2cite_cache_days" min="1" max="30" value="' . esc_attr(get_option('url2cite_cache_days', 7)) . '"></td></tr>
              <tr><th scope="row">' . esc_html__('Auto-detect URLs', 'url2cite') . '</th><td>
              <input type="checkbox" name="url2cite_auto_inject" value="1" ' . checked(1, get_option('url2cite_auto_inject', true), false) . '></td></tr>
              <tr><th scope="row">' . esc_html__('SEO Microdata', 'url2cite') . '</th><td>
              <input type="checkbox" name="url2cite_seo_microdata" value="1" ' . checked(1, get_option('url2cite_seo_microdata', false), false) . '></td></tr>
              <tr><th scope="row">' . esc_html__('Cache Management', 'url2cite') . '</th><td>
              <button type="button" class="button" id="url2cite-clear-cache">' . esc_html__('Clear Cache', 'url2cite') . '</button>
              <span class="description">' . esc_html__('Clears all cached citations', 'url2cite') . '</span>
              <script>
              jQuery("#url2cite-clear-cache").click(function() {
                  wp.ajax.post("url2cite_clear_cache").done(function() {
                      alert("' . esc_js(__('Cache cleared successfully', 'url2cite')) . '");
                  });
              });
              </script></td></tr>
              </table>';
        submit_button();
        echo '</form></div>';
    }

    public function generate_citation($url, $style = null) {
        if (!wp_http_validate_url($url)) {
            return new WP_Error('invalid_url', __('Invalid URL provided', 'url2cite'));
        }
        
        $style = $style ?: get_option('url2cite_default_style', 'apa');
        $cache_key = 'url2cite_' . md5($url . $style);
        
        if ($citation = get_transient($cache_key)) {
            return $citation;
        }
        
        $metadata = $this->fetch_metadata($url);
        if (is_wp_error($metadata)) {
            return $metadata;
        }
        
        $citation = $this->format_citation($metadata, $style);
        set_transient($cache_key, $citation, DAY_IN_SECONDS * (int) get_option('url2cite_cache_days', 7));
        
        if (is_singular() && $post_id = get_the_ID()) {
            $citations = get_post_meta($post_id, '_url2cite_citations', true) ?: [];
            $citations[] = $metadata;
            update_post_meta($post_id, '_url2cite_citations', $citations);
        }
        
        return $citation;
    }

    private function fetch_metadata($url) {
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'redirection' => 2,
            'headers' => ['Accept' => 'text/html,application/xhtml+xml']
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $html = wp_remote_retrieve_body($response);
        if (!$html) return new WP_Error('empty_content', __('No content found', 'url2cite'));
        
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        try {
            @$doc->loadHTML($html);
        } catch (Exception $e) {
            return new WP_Error('parse_error', __('Content parsing failed', 'url2cite'));
        }
        
        $xpath = new DOMXPath($doc);
        $metadata = [
            'title' => $xpath->evaluate("string(//meta[@property='og:title']/@content)") 
                      ?: $doc->getElementsByTagName('title')->item(0)->textContent 
                      ?? __('No Title', 'url2cite'),
            'author' => $xpath->evaluate("string(//meta[@itemprop='author']/@content)") 
                       ?: $xpath->evaluate("string(//meta[@name='author']/@content)"),
            'site_name' => $xpath->evaluate("string(//meta[@property='og:site_name']/@content)"),
            'publish_date' => $xpath->evaluate("string(//meta[@itemprop='datePublished']/@content)") 
                            ?: ($xpath->query("//time[@datetime]")->item(0)->getAttribute('datetime') ?? ''),
            'url' => $url
        ];
        
        return $metadata;
    }

    private function format_citation($metadata, $style) {
        $author = $metadata['author'] ?: 'Anonymous';
        $date = (!empty($metadata['publish_date']) && strtotime($metadata['publish_date'])) 
                ? strtotime($metadata['publish_date']) 
                : time();
        $site_name = $metadata['site_name'] ?: parse_url($metadata['url'], PHP_URL_HOST);
        
        switch ($style) {
            case 'apa':
                return sprintf(
                    '%s. (%s). %s. Retrieved from %s',
                    $author,
                    date('Y', $date),
                    $metadata['title'],
                    $metadata['url']
                );
                
            case 'mla':
                return sprintf(
                    '"%s." %s, %s. Accessed %s. <%s>.',
                    $metadata['title'],
                    $site_name,
                    $metadata['publish_date'] ? date('j M. Y', $date) : 'n.d.',
                    date('j M. Y'),
                    $metadata['url']
                );
                
            case 'chicago':
                return sprintf(
                    '%s. "%s." %s. %s. Accessed %s. %s.',
                    $author,
                    $metadata['title'],
                    $site_name,
                    $metadata['publish_date'] ? date('F j, Y', $date) : 'n.d.',
                    date('F j, Y'),
                    $metadata['url']
                );
                
            case 'harvard':
                return sprintf(
                    '%s (%s) %s. Available at: %s (Accessed: %s)',
                    $author,
                    date('Y', $date),
                    $metadata['title'],
                    $metadata['url'],
                    date('j F Y')
                );
                
            case 'ieee':
                return sprintf(
                    '[1] %s, "%s," %s, %s. [Online]. Available: %s. Accessed: %s.',
                    $author,
                    $metadata['title'],
                    $site_name,
                    date('M. d, Y', $date),
                    $metadata['url'],
                    date('M. d, Y')
                );
                
            case 'vancouver':
                return sprintf(
                    '%s. %s [Internet]. %s [cited %s]. Available from: %s',
                    $author,
                    $metadata['title'],
                    $site_name,
                    date('Y M d', $date),
                    $metadata['url']
                );
                
            case 'turabian':
                return sprintf(
                    '%s. "%s." %s. %s. Accessed %s. %s.',
                    $author,
                    $metadata['title'],
                    $site_name,
                    $metadata['publish_date'] ? date('F j, Y', $date) : 'n.d.',
                    date('F j, Y'),
                    $metadata['url']
                );
                
            case 'bluebook':
                return sprintf(
                    '%s, %s, %s (%s) <%s>',
                    $author,
                    $metadata['title'],
                    $site_name,
                    date('Y', $date),
                    $metadata['url']
                );
                
            case 'cse':
                return sprintf(
                    '%s. %s [Internet]. %s: %s [cited %s]. Available from: %s',
                    $author,
                    $metadata['title'],
                    date('Y', $date),
                    $site_name,
                    date('Y M d', $date),
                    $metadata['url']
                );
                
            default: // Wikipedia
                return sprintf(
                    '"%s". %s. %s. Retrieved %s from %s.',
                    $metadata['title'],
                    $site_name,
                    $author,
                    $metadata['publish_date'] ? date('j F Y', $date) : date('j F Y'),
                    $metadata['url']
                );
        }
    }

    public function shortcode_handler($atts) {
        $atts = shortcode_atts([
            'url' => '',
            'style' => ''
        ], $atts);
        
        if (empty($atts['url'])) {
            return '';
        }
        
        $citation = $this->generate_citation($atts['url'], $atts['style']);
        if (is_wp_error($citation)) {
            return '';
        }
        
        $output = '<div class="url2cite-citation">' . esc_html($citation) . '</div>';
        
        if (get_option('url2cite_seo_microdata')) {
            $output = '<div itemscope itemtype="http://schema.org/CreativeWork">' . $output . '</div>';
        }
        
        return $output;
    }

    public function auto_detect_urls($content) {
        return preg_replace_callback(
            '/https?:\/\/[\w\.-]+(?:\/[\w\-\.\/?=&%#]*)?/',
            function($matches) {
                $citation = $this->generate_citation($matches[0]);
                if (is_wp_error($citation)) {
                    return $matches[0];
                }
                
                $output = $matches[0] . '<div class="url2cite-citation">' . esc_html($citation) . '</div>';
                
                if (get_option('url2cite_seo_microdata')) {
                    $output = '<div itemscope itemtype="http://schema.org/CreativeWork">' . $output . '</div>';
                }
                
                return $output;
            },
            $content
        );
    }

    public function rest_api_init() {
        register_rest_route('url2cite/v1', '/cite', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_citation'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
            'args' => [
                'url' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return filter_var($param, FILTER_VALIDATE_URL);
                    },
                    'sanitize_callback' => 'esc_url_raw'
                ],
                'style' => [
                    'required' => false,
                    'validate_callback' => function($param) {
                        return array_key_exists($param, $this->citation_styles);
                    }
                ]
            ]
        ]);
    }

    public function rest_citation($request) {
        $url = $request->get_param('url');
        $style = $request->get_param('style');
        
        $citation = $this->generate_citation($url, $style);
        if (is_wp_error($citation)) {
            return new WP_Error('citation_error', $citation->get_error_message(), ['status' => 400]);
        }
        
        return [
            'citation' => $citation,
            'metadata' => [
                'url' => $url,
                'style' => $style ?: get_option('url2cite_default_style', 'apa')
            ]
        ];
    }

    public function output_json_ld() {
        if (!is_single() || !get_option('url2cite_seo_microdata')) {
            return;
        }
        
        global $post;
        $citations = get_post_meta($post->ID, '_url2cite_citations', true);
        if (empty($citations)) {
            return;
        }
        
        $json_ld = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => []
        ];
        
        foreach ($citations as $index => $citation) {
            $json_ld['itemListElement'][] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'item' => [
                    '@type' => 'CreativeWork',
                    'name' => $citation['title'],
                    'url' => $citation['url'],
                    'author' => $citation['author'] ?: null,
                    'datePublished' => $citation['publish_date'] ?: null
                ]
            ];
        }
        
        echo '<script type="application/ld+json">' . wp_json_encode($json_ld) . '</script>';
    }

    public function clear_cache() {
        global $wpdb;
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_url2cite_%'");
        wp_send_json_success();
    }

    public function enqueue_block_editor_assets() {
        wp_enqueue_script(
            'url2cite-block',
            plugins_url('blocks/block.js', __FILE__),
            ['wp-blocks', 'wp-element', 'wp-editor', 'wp-api-fetch'],
            filemtime(plugin_dir_path(__FILE__) . 'blocks/block.js') 
        );
        
        wp_localize_script('url2cite-block', 'url2citeSettings', [
            'styles' => $this->citation_styles,
            'defaultStyle' => get_option('url2cite_default_style', 'apa')
        ]);
    }
}

new URL2Cite();