<?php
// Shortcode to Display the Search Form and Default Posts
function custom_ajax_search_shortcode() {
    ob_start();
    ?>
    <form id="custom-search-form" method="GET">
        <input type="text" name="search_query" placeholder="Search by title or content...">
        
        <select name="category">
            <option value="">Select Category</option>
            <?php
            // Get all categories from the 'jobs_category' taxonomy
            $categories = get_terms([
                'taxonomy' => 'jobs_category',
                'hide_empty' => false,
            ]);

            foreach ($categories as $category) {
                echo '<option value="' . esc_attr($category->slug) . '">' . esc_html($category->name) . '</option>';
            }
            ?>
        </select>

        <select name="company">
            <option value="">Select Location</option>
            <?php
            // Get all unique company names stored as post meta
            global $wpdb;
            $results = $wpdb->get_results("
                SELECT DISTINCT meta_value 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = 'company' AND meta_value != ''
            ");

            foreach ($results as $result) {
                echo '<option value="' . esc_attr($result->meta_value) . '">' . esc_html($result->meta_value) . '</option>';
            }
            ?>
        </select>
        
        <button type="submit">Search</button>
    </form>
    <div id="search-results">
        <!-- Default job posts will be loaded here -->
    </div>
    <button id="load-more-btn" data-page="2" style="display: block;">Load More</button> <!-- Default: Visible -->
    <?php
    return ob_get_clean();
}
add_shortcode('custom_ajax_search', 'custom_ajax_search_shortcode');



// AJAX Handler for Search and Load More
function custom_ajax_search_handler() {
    $search_query = isset($_GET['search_query']) ? sanitize_text_field($_GET['search_query']) : '';
    $category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';
    $company = isset($_GET['company']) ? sanitize_text_field($_GET['company']) : '';
    $paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;

    // Base Query Args
    $args = [
        'post_type' => 'job',
        'posts_per_page' => 9,
        'paged' => $paged,
    ];

    // Add search query if provided
    if (!empty($search_query)) {
        $args['s'] = $search_query;
    }

    // Add category filter if provided (using 'jobs_category' taxonomy)
    if (!empty($category)) {
        $args['tax_query'] = [
            [
                'taxonomy' => 'jobs_category',
                'field' => 'slug',
                'terms' => $category,
            ],
        ];
    }

    // Add company filter if selected from dropdown
    if (!empty($company)) {
        $args['meta_query'] = [
            [
                'key' => 'company',
                'value' => $company,
                'compare' => 'LIKE',
            ],
        ];
    }

    // Execute Query
    $query = new WP_Query($args);

    // If no posts are found, display the message and hide the "Load More" button
    if (!$query->have_posts()) {
        echo '<p>No job posts found.</p>';
        echo '<script>jQuery("#load-more-btn").hide();</script>'; // Hide the button when no posts are found
    } else {
        while ($query->have_posts()) {
            $query->the_post();
            ?>
            <div class="job-item">
                <div class="job-featured-image">
                    <?php if (has_post_thumbnail()) { ?>
                        <img src="<?php the_post_thumbnail_url('medium'); ?>" alt="<?php the_title(); ?>">
                    <?php } ?>
                </div>
                <h2 class="job-title"><?php the_title(); ?></h2>
                <p class="job-salary">
                    Salary: <?php echo get_post_meta(get_the_ID(), 'salary', true); ?>
                </p>
                <p class="job-location">
                    Location: <?php echo get_post_meta(get_the_ID(), 'company', true); ?>
                </p>
                <a class="job-details-link" href="<?php echo esc_url(get_post_meta(get_the_ID(), 'details_url', true)); ?>" target="_blank">
                    Read More
                </a>
            </div>
            <?php
        }

        // Add hidden indicator for "has more posts"
        if ($query->max_num_pages > $paged) {
            echo '<div class="has-more-posts" data-has-more-posts="yes"></div>';
        } else {
            echo '<div class="has-more-posts" data-has-more-posts="no"></div>';
        }        
    }

    wp_reset_postdata();
    wp_die();
}
add_action('wp_ajax_custom_ajax_search', 'custom_ajax_search_handler');
add_action('wp_ajax_nopriv_custom_ajax_search', 'custom_ajax_search_handler');


// Enqueue Scripts for AJAX Search and Load More
function custom_ajax_search_scripts() {
    wp_enqueue_script('jquery');

    wp_localize_script('jquery', 'ajax_object', [
        'ajax_url' => admin_url('admin-ajax.php'),
    ]);

    $script = '
    jQuery(document).ready(function ($) {
        function loadSearchResults(formData, append = false) {
            $.ajax({
                url: ajax_object.ajax_url,
                type: "GET",
                data: formData,
                beforeSend: function () {
                    if (!append) {
                        $("#search-results").html("<p>Loading...</p>");
                    }
                    $("#load-more-btn").text("Loading...").prop("disabled", true);
                },
                success: function (response) {
                    if (!append) {
                        $("#search-results").html(response);
                    } else {
                        $("#search-results").append(response);
                    }
    
                    let hasMorePosts = $(response).find(".has-more-posts").data("has-more-posts");
                    if (hasMorePosts === "yes") {
                        $("#load-more-btn").show().text("Load More").prop("disabled", false);
                    } else {
                        $("#load-more-btn").hide(); // Hide button if no more posts
                    }
    
                    // Run additional check
                    checkHasMorePosts();
                },
                error: function () {
                    $("#search-results").html("<p>Error occurred while loading posts.</p>");
                    $("#load-more-btn").hide();
                },
                complete: function () {
                    $("#load-more-btn").text("Load More").prop("disabled", false);
                }
            });
        }
    
        function checkHasMorePosts() {
            let hasMorePostsDiv = $(".has-more-posts[data-has-more-posts=\'no\']");
            let stillMorePostsDiv = $(".has-more-posts[data-has-more-posts=\'yes\']");
            
            if (hasMorePostsDiv.length > 0) {
                $("#load-more-btn").hide().css("display", "none !important");
            }
    
            if (stillMorePostsDiv.length > 0) {
                $("#load-more-btn").show().css("display", "block !important");
            }
        }
    
        $("#custom-search-form").on("submit", function (e) {
            e.preventDefault();
            let formData = $(this).serialize();
            formData += "&action=custom_ajax_search";
    
            loadSearchResults(formData, false);
            $("#load-more-btn").data("page", 2);
        });
    
        $("#load-more-btn").on("click", function () {
            let formData = $("#custom-search-form").serialize();
            let page = $(this).data("page");
            formData += "&action=custom_ajax_search";
            formData += "&paged=" + page;
    
            loadSearchResults(formData, true);
            $(this).data("page", page + 1);
        });
    
        let defaultData = {
            action: "custom_ajax_search",
            paged: 1
        };
        loadSearchResults(defaultData, false);
    
        // Run checkHasMorePosts after initial load
        checkHasMorePosts();
    });
    ';
    wp_add_inline_script('jquery', $script);
    
}
add_action('wp_enqueue_scripts', 'custom_ajax_search_scripts');
