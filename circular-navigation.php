class CircularNavigation {
    private $post_type;
    private $script_version;

    public function __construct() {
        $this->post_type = 'dilemma-posts';
        $this->script_version = time();
        
        add_shortcode('circular_navigation', [$this, 'render_shortcode']);
        add_action('wp_ajax_fetch_post_content', [$this, 'handle_fetch_post_content']);
        add_action('wp_ajax_nopriv_fetch_post_content', [$this, 'handle_fetch_post_content']);
        add_action('wp_ajax_fetch_hierarchical_posts', [$this, 'handle_fetch_hierarchical_posts']);
        add_action('wp_ajax_nopriv_fetch_hierarchical_posts', [$this, 'handle_fetch_hierarchical_posts']);
        
        $this->setup_rest_routes();
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(['post_type' => $this->post_type], $atts);
        $this->enqueue_assets();
        $this->localize_script_data($atts['post_type']);
        
        return $this->get_svg_container();
    }

    private function enqueue_assets() {
        wp_enqueue_script('d3-script', 'https://d3js.org/d3.v7.min.js', [], null, true);
        wp_enqueue_script('circular-nav-script', 
            get_stylesheet_directory_uri() . '/js/circular-navigation.js', 
            ['d3-script', 'jquery'], 
            $this->script_version, 
            true
        );
        wp_enqueue_style('circular-nav-style', 
            get_stylesheet_directory_uri() . '/css/circular-navigation.css', 
            [], 
            $this->script_version
        );
    }

    private function localize_script_data($post_type) {
        wp_localize_script('circular-nav-script', 'circularNavData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'post_type' => $post_type,
            'nonce' => wp_create_nonce('circular_nav_nonce')
        ]);
    }

    private function get_svg_container() {
        return '<div class="circular-navigation-container">
            <svg id="circular-nav-svg" width="100%" height="100%" 
                viewBox="-500 -400 1000 800" 
                preserveAspectRatio="xMidYMid meet">
            </svg>
        </div>';
    }

    public function handle_fetch_post_content() {
        check_ajax_referer('circular_nav_nonce', 'nonce');
        
        $post_id = intval($_POST['post_id']);
        $post = get_post($post_id);
        
        if (!$post) {
            wp_send_json_error('Post not found');
        }

        wp_send_json_success([
            'title' => $post->post_title,
            'content' => apply_filters('the_content', $post->post_content)
        ]);
    }

    public function handle_fetch_hierarchical_posts() {
        check_ajax_referer('circular_nav_nonce', 'nonce');
        $data = $this->get_hierarchical_posts($_POST['post_type']);
        wp_send_json_success($data);
    }

    private function get_hierarchical_posts($post_type = null) {
        $post_type = $post_type ?? $this->post_type;
        
        $root_posts = get_posts([
            'post_type' => $post_type,
            'posts_per_page' => 1,
            'orderby' => 'menu_order',
            'order' => 'ASC',
            'post_parent' => 0
        ]);

        if (empty($root_posts)) {
            return null;
        }

        $root_post = $root_posts[0];
        return $this->build_post_hierarchy($root_post);
    }

    private function build_post_hierarchy($post) {
        $hierarchy = [
            'name' => $post->post_title,
            'id' => $post->ID,
            'info' => wp_trim_words($post->post_content, 20),
            'children' => []
        ];

        $children = get_posts([
            'post_type' => $post->post_type,
            'posts_per_page' => -1,
            'post_parent' => $post->ID,
            'orderby' => 'menu_order',
            'order' => 'ASC'
        ]);

        foreach ($children as $child) {
            $hierarchy['children'][] = $this->build_post_hierarchy($child);
        }

        return $hierarchy;
    }

    private function setup_rest_routes() {
        add_action('rest_api_init', function() {
            register_rest_route('my-custom-route/v1', '/elementor-content/(?P<id>\d+)', [
                'methods' => 'GET',
                'callback' => [$this, 'get_elementor_content'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => [
                        'validate_callback' => 'is_numeric'
                    ]
                ]
            ]);
        });
    }

    public function get_elementor_content($request) {
        $post_id = intval($request['id']);
        $post = get_post($post_id);

        if (!$post) {
            return new WP_Error('no_post', 'Post not found', ['status' => 404]);
        }

        $content = class_exists('\Elementor\Plugin') 
            ? (new \Elementor\Frontend())->get_builder_content_for_display($post_id, true)
            : apply_filters('the_content', $post->post_content);

        return [
            'content' => $content,
            'version' => get_post_modified_time('U', true, $post_id)
        ];
    }
}

// Initialize the class
new CircularNavigation();
