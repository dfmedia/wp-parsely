<?php
/*
Plugin Name: Parse.ly
Plugin URI: http://www.parsely.com/
Description: This plugin makes it a snap to add Parse.ly tracking code to your WordPress blog.
Author: Mike Sukmanowsky (mike@parsely.com)
Version: 1.10.3
Requires at least: 4.0.0
Author URI: http://www.parsely.com/
License: GPL2

Copyright 2012  Parsely Incorporated

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

Authors: Mike Sukmanowsky (mike@parsely.com), Xand Lourenco (xand@parsely.com), James O'Toole (james.otoole@parsely.com)
*/

/* TODO List:
 * Wordpress Network support - going to hold off on any specific support here as content id prefix should work ok for now
 * Allow the user to map get_post_types() to Parse.ly post types
 * Support: is_search(), is_404()
*/

class Parsely {
    /**
     * @codeCoverageIgnoreStart
     */
    const VERSION             = '1.10.3';
    const MENU_SLUG           = 'parsely';             // Defines the page param passed to options-general.php
    const MENU_TITLE          = 'Parse.ly';            // Text to be used for the menu as seen in Settings sub-menu
    const MENU_PAGE_TITLE     = 'Parse.ly > Settings'; // Text shown in <title></title> when the settings screen is viewed
    const OPTIONS_KEY         = 'parsely';             // Defines the key used to store options in the WP database
    const CAPABILITY          = 'manage_options';      // The capability required for the user to administer settings

    private $optionDefaults     = array('apikey' => '',
                                        'content_id_prefix' => '',
                                        'api_secret' => '',
                                        'use_top_level_cats' => false,
                                        'custom_taxonomy_section' => 'category',
                                        'cats_as_tags' => false,
                                        'track_authenticated_users' => true,
                                        'lowercase_tags' => true,
                                        'force_https_canonicals' => false,
                                        'disable_javascript' => false);
    private $implementationOpts = array('standard' => 'Standard',
                                        'dom_free' => 'DOM-Free');

    public function __construct() {
        // Run upgrade options if they exist for the version currently defined
        $options = $this->get_options();
        if ( empty($options['plugin_version']) || $options['plugin_version'] != Parsely::VERSION ) {
            $method = 'upgrade_plugin_to_version_' . str_replace('.', '_', Parsely::VERSION);
            if ( method_exists($this, $method) ) {
                call_user_func_array(array($this, $method), array($options));
            }
            // Update our version info
            $options['plugin_version'] = Parsely::VERSION;
            update_option(Parsely::OPTIONS_KEY, $options);
        }

        // admin_menu and a settings link
        add_action('admin_head', array($this, 'add_admin_header'));
        add_action('admin_menu', array($this, 'add_settings_sub_menu'));
        add_action('admin_init', array($this, 'initialize_settings'));
        // display warning when plugin hasn't been configured
        add_action('admin_footer', array($this, 'display_admin_warning'));

        $basename = plugin_basename(__FILE__);
        add_filter('plugin_action_links_' . $basename,
                   array($this, 'add_plugin_meta_links'));

        // inserting parsely code
        add_action('wp_head', array($this, 'insert_parsely_page'));
        add_action('wp_footer', array($this, 'insert_parsely_javascript'));
        add_action('instant_articles_compat_registry_analytics', array($this, 'insert_parsely_tracking_fbia'));
        add_action('pre_amp_render_post', array($this, 'parsely_add_amp_actions'));
        if (!defined('WP_PARSELY_TESTING'))
        {
            function wp_parsely_style_init() {
                wp_enqueue_style('wp-parsely-style', plugins_url('wp-parsely.css', __FILE__), array(), filemtime(get_stylesheet_directory()));
            }
            add_action('wp_enqueue_scripts', 'wp_parsely_style_init');
        }

    }

    public function add_admin_header() {
        include('parsely-admin-header.php');
    }

    /* Parsely settings page in Wordpress settings menu. */
    public function add_settings_sub_menu() {
        add_options_page(Parsely::MENU_PAGE_TITLE,
                         Parsely::MENU_TITLE,
                         Parsely::CAPABILITY,
                         Parsely::MENU_SLUG,
                         array($this, 'display_settings'));
    }

    /* Parse.ly settings screen (options-general.php?page=[MENU_SLUG]) */
    public function display_settings() {
        if ( !current_user_can(Parsely::CAPABILITY) ) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        include('parsely-settings.php');
    }

    public function initialize_settings() {
        // All our options are actually stored in one single array to reduce
        // DB queries
        register_setting(Parsely::OPTIONS_KEY, Parsely::OPTIONS_KEY,
                         array($this, 'validate_options'));

        // Required Settings
        add_settings_section('required_settings', 'Required Settings',
                             array($this, 'print_required_settings'),
                             Parsely::MENU_SLUG);

        // API Key
        $h = 'Your Site ID is your own site domain (e.g. `mydomain.com`)';
        $field_args = array(
            'option_key' => 'apikey',
            'help_text' => $h
        );
        add_settings_field('apikey',
                           'Parse.ly Site ID <div class="help-icons"></div>',
                           array($this, 'print_text_tag'),
                           Parsely::MENU_SLUG, 'required_settings',
                           $field_args);


        // Optional Settings
        add_settings_section('optional_settings', 'Optional Settings',
                             array($this, 'print_optional_settings'),
                             Parsely::MENU_SLUG);

        $h = 'Your API secret is your secret code to <a href="https://www.parse.ly/help/api/analytics/">access our API.</a>
            It can be found at dash.parsely.com/yoursitedomain/settings/api
         (replace yoursitedown with your domain name, e.g. `mydomain.com`) If you haven\'t purchased access to the API, and would
          like to do so, email your account manager or support@parsely.com!';
        $field_args = array(
            'option_key' => 'api_secret',
            'help_text' => $h
        );
        add_settings_field('api_secret',
            'Parse.ly API Secret <div class="help-icons"></div>',
            array($this, 'print_text_tag'),
            Parsely::MENU_SLUG, 'optional_settings',
            $field_args);
        // Content ID Prefix
        $h = 'If you use more than one content management system (e.g. ' .
             'WordPress and Drupal), you may end up with duplicate content ' .
             'IDs. Adding a Content ID Prefix will ensure the content IDs ' .
             'from WordPress will not conflict with other content management ' .
             'systems. We recommend using "WP-" for your prefix.';
        $field_args = array(
            'option_key' => 'content_id_prefix',
            'optional_args' => array(
                'placeholder' => 'WP-'),
            'help_text' => $h,
            'requires_recrawl' => true
        );
        add_settings_field('content_id_prefix',
                           'Content ID Prefix <div class="help-icons"></div>',
                           array($this, 'print_text_tag'),
                           Parsely::MENU_SLUG, 'optional_settings',
                           $field_args);

        // Disable javascript
        $h = 'If you use a separate system for Javascript tracking (Tealium / Segment / other tag manager solution) ' .
            'you may want to use that instead of having the plugin load the tracker. WARNING: disabling this option ' .
            'will also disable the "Personalize Results" section of the recommended widget! We highly recommend leaving ' .
            'this option on!';
        add_settings_field('disable_javascript',
            'Disable Javascript <div class="help-icons"></div>',
            array($this, 'print_binary_radio_tag'),
            Parsely::MENU_SLUG, 'optional_settings',
            array('option_key' => 'disable_javascript',
                'help_text' => $h,
                'requires_recrawl' => false));

         // Use top-level cats
        $h = 'wp-parsely will use the first category assigned to a post. ' .
             'With this option selected, if you post a story to News > ' .
             'National > Florida, wp-parsely will use the "News" for the ' .
             'section name in your dashboard instead of "Florida".';
        add_settings_field('use_top_level_cats',
                           'Use Top-Level Categories for Section <div class="help-icons"></div>',
                           array($this, 'print_binary_radio_tag'),
                           Parsely::MENU_SLUG, 'optional_settings',
                           array('option_key' => 'use_top_level_cats',
                                 'help_text' => $h,
                                 'requires_recrawl' => true));

        // Allow use of custom taxonomy to populate articleSection in parselyPage; defaults to category
        $h = 'By default, the section value in your Parse.ly dashboard maps to a post\'s category. ' .
             'You can optionally choose a custom taxonomy, if you\'ve created one, to ' .
             'populate the section value instead. <br>';
        add_settings_field('custom_taxonomy_section',
                           'Use Custom Taxonomy for Section  <div class="help-icons"></div>',
                           array($this, 'print_select_tag'),
                           Parsely::MENU_SLUG, 'optional_settings',
                           array('option_key' => 'custom_taxonomy_section',
                                 'help_text' => $h,
                                 // filter Wordpress taxonomies under the hood that should not appear in dropdown
                                 'select_options' => array_diff(get_taxonomies(), array('post_tag', 'nav_menu', 'author', 'link_category', 'post_format')),
                                 'requires_recrawl' => true));

        // Use categories and custom taxonomies as tags
        $h = 'You can use this option to add all assigned categories and taxonomies to ' .
             'your tags.  For example, if you had a post assigned to ' .
             'the categories: "Business/Tech", "Business/Social", your tags would include ' .
             '"Business/Tech" and "Business/Social" in addition to your other tags.';
        add_settings_field('cats_as_tags',
                           'Add Categories to Tags <div class="help-icons"></div>',
                           array($this, 'print_binary_radio_tag'),
                           Parsely::MENU_SLUG, 'optional_settings',
                           array('option_key' => 'cats_as_tags',
                                 'help_text' => $h,
                                 'requires_recrawl' => true));

        // // Append custom taxonomy values in parselyPage tags field; disabled by default
        // $h = 'Use this option to append custom taxonomy values to your tags field.<br>';
        // add_settings_field('custom_taxonomy_tags',
        //                    'Use Custom Taxonomies as Section  <div class="help-icons"></div>',
        //                    array($this, 'print_select_tag'),
        //                    Parsely::MENU_SLUG, 'print_binary_radio_tag',
        //                    array('option_key' => 'custom_taxonomy_tags',
        //                          'help_text' => $h,
        //                          // filter Wordpress taxonomies under the hood that should not appear in dropdown
        //                          //TODO: pull the values below from the array of taxonomies that gets added to tags
        //                          //'select_options' => array_diff(get_taxonomies(), array('post_tag', 'nav_menu', 'author', 'link_category', 'post_format')),
        //                          'requires_recrawl' => true));

        // Track logged-in users
        $h = 'By default, wp-parsely will track the activity of users that ' .
             'are logged into this site. You can change this setting to only ' .
             'track the activity of anonymous visitors. Note: You will no ' .
             'longer see the Parse.ly tracking code on your site if you ' .
             'browse while logged in.';
        add_settings_field('track_authenticated_users',
                           'Track Logged-in Users <div class="help-icons"></div>',
                           array($this, 'print_binary_radio_tag'),
                           Parsely::MENU_SLUG, 'optional_settings',
                           array('option_key' => 'track_authenticated_users',
                                 'help_text' => $h,
                                 'requires_recrawl' => true));

        // Lowercase all tags
        $h = 'By default, wp-parsely will use lowercase versions of your ' .
             'tags to correct for potential misspellings. You can change this '.
             'setting to ensure that tag names are used verbatim.';
        add_settings_field('lowercase_tags',
                           'Lowercase All Tags <div class="help-icons"></div>',
                           array($this, 'print_binary_radio_tag'),
                           Parsely::MENU_SLUG, 'optional_settings',
                           array('option_key' => 'lowercase_tags',
                                 'help_text' => $h,
                                 'requires_recrawl' => true));

        $h = 'wp-parsely uses http canonical URLs by default. If this needs to be forced to use https, set this option ' .
            ' to true. Note: the default is fine for almost all publishers, it\'s unlikely you\'ll have to change this unless' .
            ' directed to do so by a Parsely support rep.';
        add_settings_field('force_https_canonicals',
            'Force HTTPS canonicals <div class="help-icons"></div>',
            array($this, 'print_binary_radio_tag'),
            Parsely::MENU_SLUG, 'optional_settings',
            array('option_key' => 'force_https_canonicals',
                'help_text' => $h,
                'requires_recrawl' => true));

        // Dynamic tracking note
        add_settings_field('dynamic_tracking_note', 'Note: ',
                            array($this, 'print_dynamic_tracking_note'),
                            Parsely::MENU_SLUG, 'optional_settings');

    }

    public function validate_options($input) {
        if ( empty($input['apikey']) ) {
            add_settings_error(Parsely::OPTIONS_KEY, 'apikey',
                               'Please specify the Site ID');
        } else {
            $input['apikey'] = strtolower($input['apikey']);
            $input['apikey'] = sanitize_text_field($input['apikey']);
            if ( strpos($input['apikey'], '.') === false ||
                strpos($input['apikey'], ' ') !== false)
                add_settings_error(Parsely::OPTIONS_KEY, 'apikey',
                                   'Your Parse.ly Site ID looks incorrect, it should look like "example.com".');  

        }

        $input['api_secret'] = sanitize_text_field($input['api_secret']);
        // Content ID prefix
        $input['content_id_prefix'] = sanitize_text_field($input['content_id_prefix']);
        $input['custom_taxonomy_section'] = sanitize_text_field($input['custom_taxonomy_section']);

        // Custom taxonomy as section

        // Top-level categories
        if ( $input['use_top_level_cats'] !== 'true' && $input['use_top_level_cats'] !== 'false' ) {
            add_settings_error(Parsely::OPTIONS_KEY, 'use_top_level_cats',
                               'Value passed for use_top_level_cats must be either "true" or "false".');
        } else {
            $input['use_top_level_cats'] = $input['use_top_level_cats'] === 'true' ? true : false;
        }

        // Child categories as tags
        if ( $input['cats_as_tags'] !== 'true' && $input['cats_as_tags'] !== 'false' ) {
            add_settings_error(Parsely::OPTIONS_KEY, 'cats_as_tags',
                               'Value passed for cats_as_tags must be either "true" or "false".');
        } else {
            $input['cats_as_tags'] = $input['cats_as_tags'] === 'true' ? true : false;
        }

        // Track authenticated users
        if ( $input['track_authenticated_users'] !== 'true' && $input['track_authenticated_users'] !== 'false' ) {
            add_settings_error(Parsely::OPTIONS_KEY, 'track_authenticated_users',
                               'Value passed for track_authenticated_users must be either "true" or "false".');
        } else {
            $input['track_authenticated_users'] = $input['track_authenticated_users'] === 'true' ? true : false;
        }

        // Lowercase tags
        if ( $input['lowercase_tags'] !== 'true' && $input['lowercase_tags'] !== 'false' ) {
            add_settings_error(Parsely::OPTIONS_KEY, 'lowercase_tags',
                               'Value passed for lowercase_tags must be either "true" or "false".');
        } else {
            $input['lowercase_tags'] = $input['lowercase_tags'] === 'true' ? true : false;
        }

        if ( $input['force_https_canonicals'] !== 'true' && $input['force_https_canonicals'] !== 'false' ) {
            add_settings_error(Parsely::OPTIONS_KEY, 'force_https_canonicals',
                'Value passed for force_https_canonicals must be either "true" or "false".');
        } else {
            $input['force_https_canonicals'] = $input['force_https_canonicals'] === 'true' ? true : false;
        }

        if ( $input['disable_javascript'] !== 'true' && $input['disable_javascript'] !== 'false' ) {
            add_settings_error(Parsely::OPTIONS_KEY, 'disable_javascript',
                'Value passed for disable_javascript must be either "true" or "false".');
        } else {
            $input['disable_javascript'] = $input['disable_javascript'] === 'true' ? true : false;
        }

        return $input;
    }

    public function print_required_settings() {
        // We can optionally print some text here in the future, but we don't
        // need to now
    }

    public function print_optional_settings() {
        // We can optionally print some text here in the future, but we don't
        // need to now
        return;
    }

    /**
    * Adds a 'Settings' link to the Plugins screen in WP admin
    */
    public function add_plugin_meta_links($links) {
        array_unshift($links, '<a href="'. $this->get_settings_url() . '">' . __('Settings'). '</a>');
        return $links;
    }

    public function display_admin_warning() {
        $options = $this->get_options();
        if ( !isset($options['apikey']) || empty($options['apikey']) ) {
            ?>
            <div id='message' class='error'>
                <p>
                    <strong>Parse.ly - Dash plugin is not active.</strong>
                    You need to
                    <a href='<?php echo esc_html($this->get_settings_url()); ?>'>
                        provide your Parse.ly Dash Site ID
                    </a>
                    before things get cooking.
                </p>
            </div>
            <?php
        }
    }

    public function print_dynamic_tracking_note() {
        $note = "This plugin does not currently support dynamic tracking (the tracking of multiple pageviews on a single page). Some common use-cases for dynamic tracking are slideshows or articles loaded via AJAX calls in single-page applications -- situations in which new content is loaded without a full page refresh. Tracking these events requires manually implementing additional JavaScript above <a href='http://www.parsely.com/help/integration/basic/'>the standard Parse.ly include</a> that the plugin injects into your page source. Please consult <a href='https://www.parsely.com/help/integration/dynamic/'>the Parse.ly documentation on dynamic tracking</a> for instructions on implementing dynamic tracking, or contact Parse.ly support (<a href='support@parsely.com'>support@parsely.com</a>) for additional assistance.";
        echo $note;
    }

    /**
     * @codeCoverageIgnoreEnd
     */

    /**
    * Actually inserts the code for the <meta name='parsely-page'> parameter within the <head></head> tag.
    */
    public function insert_parsely_page() {
        $parselyOptions = $this->get_options();

        // If we don't have an API key or if we aren't supposed to show to logged in users, there's no need to proceed.
        if ( empty($parselyOptions['apikey']) || (!$parselyOptions['track_authenticated_users'] && $this->parsely_is_user_logged_in()) ) {
            return '';
        }

        global $wp_query;
        global $post;
        // Assign default values for LD+JSON
        // TODO: Maping of an install's post types to Parse.ly post types (namely page/post)
        $parselyPage = array(
            "@context" => "http://schema.org",
            "@type" => "WebPage"
        );
        $currentURL = $this->get_current_url();
        if ( is_single() && $post->post_status == 'publish' ) {
            $authors    = $this->get_author_names($post);
            $category   = $this->get_category_name($post, $parselyOptions);
            $postId     = $parselyOptions['content_id_prefix'] . (string)get_the_ID();

            if ( has_post_thumbnail() ) {
                $image_id = get_post_thumbnail_id();
                $image_url = wp_get_attachment_image_src($image_id);
                $image_url = $image_url[0];
            }
            else {
                $image_url = $this->get_first_image($post);
            }

            $tags = $this->get_tags($post->ID);
            if ( $parselyOptions['cats_as_tags'] ) {
                $tags = array_merge($tags, $this->get_categories($post->ID));
                // add custom taxonomy values
                $tags = array_merge($tags, $this->get_custom_taxonomy_values($post, $parselyOptions));
            }
            // the function 'mb_strtolower' is not enabled by default in php, so this check
            // falls back to the native php function 'strtolower' if necessary
            if ( function_exists('mb_strtolower') ) {
                $lowercase_callback = 'mb_strtolower';
            } else {
                $lowercase_callback = 'strtolower';
            }
            if ( $parselyOptions['lowercase_tags'] ) {
                $tags = array_map($lowercase_callback, $tags);
            }
            $tags = apply_filters('wp_parsely_post_tags', $tags, $post->ID);
            $tags = array_map(array($this, 'get_clean_parsely_page_value'), $tags);
            $tags = array_values(array_unique($tags));

            $parselyPage['@type']          = 'NewsArticle';
            $parselyPage['mainEntityOfPage'] = array(
                '@type' => 'WebPage',
                '@id' => $this->get_current_url('post')
            );
            $parselyPage['headline']       = $this->get_clean_parsely_page_value(get_the_title());
            $parselyPage['url']            = $this->get_current_url('post');
            $parselyPage['thumbnailUrl']   = $image_url;
            $parselyPage['image']          = array(
                '@type' => 'ImageObject',
                'url' => $image_url
            );
            $parselyPage['dateCreated']    = gmdate('Y-m-d\TH:i:s\Z', get_post_time('U', true));
            $parselyPage['datePublished']  = gmdate('Y-m-d\TH:i:s\Z', get_post_time('U', true));
            if (get_the_modified_date('U', true) >= get_post_time('U', true)) {
                $parselyPage['dateModified']   = gmdate('Y-m-d\TH:i:s\Z', get_the_modified_date('U', true));
            }
            else {
                // Use the post time as the earliest possible modification date
                $parselyPage['dateModified']   = gmdate('Y-m-d\TH:i:s\Z', get_post_time('U', true));
            }
            $parselyPage['articleSection'] = $category;
            $author_objects                = array();
            foreach ($authors as $author) {
                $author_tag = array(
                    '@type' => 'Person',
                    'name' => $author
                );
                array_push($author_objects, $author_tag);  
            }
            $parselyPage['author']         = $author_objects;
            $parselyPage['creator']        = $authors;
            $parselyPage['publisher']      = array(
                '@type' => 'Organization',
                'name' => get_bloginfo('name')
            ); 
            $parselyPage['keywords']       = $tags;
        } elseif ( is_page() && $post->post_status == 'publish' ) {
            $parselyPage['headline']       = $this->get_clean_parsely_page_value(get_the_title());
            $parselyPage['url']            = $this->get_current_url('post');
        } elseif ( is_author() ) {
            // TODO: why can't we have something like a WP_User object for all the other cases? Much nicer to deal with than functions
            $author = (get_query_var('author_name')) ? get_user_by('slug', get_query_var('author_name')) : get_userdata(get_query_var('author'));
            $parselyPage['headline']       = $this->get_clean_parsely_page_value('Author - '.$author->data->display_name);
            $parselyPage['url']            = $currentURL;
        } elseif ( is_category() ) {
            $category = get_the_category();
            $category = $category[0];
            $parselyPage['headline']       = $this->get_clean_parsely_page_value($category->name);
            $parselyPage['url']            = $currentURL;
        } elseif ( is_date() ) {
            if ( is_year() ) {
                $parselyPage['headline']   = 'Yearly Archive - ' . get_the_time('Y');
            } elseif(is_month() ) {
                $parselyPage['headline']   = 'Monthly Archive - ' . get_the_time('F, Y');
            } elseif ( is_day() ) {
                $parselyPage['headline']   = 'Daily Archive - ' . get_the_time('F jS, Y');
            } elseif ( is_time() ) {
                $parselyPage['headline']   = 'Hourly, Minutely, or Secondly Archive - ' . get_the_time('F jS g:i:s A');
            }
            $parselyPage['url']            = $currentURL;
        } elseif ( is_tag() ) {
            $tag = single_tag_title('', FALSE);
            if ( empty($tag) ) {
                $tag = single_term_title('', FALSE);
            }
            $parselyPage['headline']       = $this->get_clean_parsely_page_value('Tagged - '.$tag);
            $parselyPage['url']            = $currentURL; // get_tag_link(get_query_var('tag_id'));
        } elseif ( is_front_page() ) {
            $parselyPage['headline']       = $this->get_clean_parsely_page_value(get_bloginfo('name', 'raw'));
            $parselyPage['url']            = home_url(); // site_url();?
        }
        $parselyPage = apply_filters('after_set_parsely_page', $parselyPage, $post, $parselyOptions);
        include('parsely-parsely-page.php');
        return $parselyPage;
    }

    /**
    * Inserts the JavaScript code required to send off beacon requests
    */
    public function insert_parsely_javascript() {
        $parselyOptions = $this->get_options();
        // If we don't have an API key, there's no need to proceed.
        if ( empty($parselyOptions['apikey']) || $parselyOptions['disable_javascript'] ) {
            return '';
        }

        global $post;
        $display = TRUE;

        if ( is_single() && $post->post_status != 'publish' ) {
            $display = FALSE;
        }
        if (!$parselyOptions['track_authenticated_users'] && $this->parsely_is_user_logged_in()) {
            $display = FALSE;
        }
        if ( $display ) {
            include('parsely-javascript.php');
        }
    }

    public function print_select_tag($args) {
        $options = $this->get_options();
        $name = $args['option_key'];
        $select_options = $args['select_options'];
        $selected = isset($options[$name]) ? $options[$name] : NULL;
        $optional_args = isset($args['optional_args']) ? $args['optional_args'] : array();
        $id = esc_attr($name);
        $name = Parsely::OPTIONS_KEY."[$id]";

        $tag = '<div class="parsely-form-controls"';
        if ( isset($args['help_text']) ) {
            $tag .= ' data-has-help-text="true"';
        }
        if ( isset($args['requires_recrawl']) ) {
            $tag .= ' data-requires-recrawl="true"';
        }
        $tag .= '>';

        $tag .= "<select name='$name' id='$name'";
        foreach ( $optional_args as $key => $val ) {
            $tag .= ' ' . esc_attr($key) . '="' . esc_attr($val) . '"';
        }
        $tag .= '>';

        foreach ( $select_options as $key => $val ) {
            $tag .= '<option value="' . esc_attr($key) . '" ';
            $tag .= selected($selected, $key, false) . '>';
            $tag .= esc_html($val);
            $tag .= '</option>';
        }
        $tag .= '</select>';


        if ( isset($args['help_text']) ) {
            $tag .= '<div class="help-text">'.
                    '<p class="description">'.$args['help_text'].'</p>'.
                    '</div>';
        }
        $tag .= '</div>';
        echo $tag;
    }

    public function print_binary_radio_tag($args) {
        $options = $this->get_options();
        $name = $args['option_key'];
        $value = $options[$name];
        $id = esc_attr($name);
        $name = Parsely::OPTIONS_KEY."[$id]";

        $tag = '<div class="parsely-form-controls"';
        if ( isset($args['help_text']) ) {
            $tag .= ' data-has-help-text="true"';
        }
        if ( isset($args['requires_recrawl']) ) {
            $tag .= ' data-requires-recrawl="true"';
        }
        $tag .= '>';

        $tag .= "<input type='radio' name='$name' id='$id"."_true' value='true' " .
                checked($value == true, true, false) . ' />' .
                "<label for='$id"."_true'>Yes</label> " .
                "<input type='radio' name='$name' id='$id"."_false' value='false' " .
                checked($value != true, true, false) . ' />' .
                "<label for='$id"."_false'>No</label>";

        if ( isset($args['help_text']) ) {
            $tag .= '<div class="help-text">'.
                    '<p class="description">'.$args['help_text'].'</p>'.
                    '</div>';
        }
        $tag .= '</div>';

        echo $tag;
    }

    public function print_text_tag($args) {
        $options = $this->get_options();
        $name = $args['option_key'];
        $value = isset($options[$name]) ? $options[$name] : '';
        $optional_args = isset($args['optional_args']) ? $args['optional_args'] : array();
        $id = esc_attr($name);
        $name = Parsely::OPTIONS_KEY."[$id]";
        $value = esc_attr($value);

        $tag = '<div class="parsely-form-controls"';
        if ( isset($args['help_text']) ) {
            $tag .= ' data-has-help-text="true"';
        }
        if ( isset($args['requires_recrawl']) ) {
            $tag .= ' data-requires-recrawl="true"';
        }
        $tag .= '>';

        $tag .= "<input type='text' name='$name' id='$id' value='$value'";
        foreach ( $optional_args as $key => $val ) {
            $tag .= ' ' . esc_attr($key) . '="' . esc_attr($val) . '"';
        }
        if ( isset($args['requires_recrawl']) ) {
            $tag .= ' data-requires-recrawl="true"';
        }
        $tag .= ' />';

        if ( isset($args['help_text']) ) {
            $tag .= ' <div class="help-text" id="'.
                    esc_attr($args['option_key']).'_help_text">'.
                    '<p class="description">'.$args['help_text'].'</p>'.
                    '</div>';
        }
        echo $tag;
    }

    /**
    * Extracts a host (not TLD) from a URL
    */
    private function get_host_from_url($url) {
        if ( preg_match('/^https?:\/\/([^\/]+)\/.*$/', $url, $matches) ) {
            return $matches[1];
        } else {
            return $url;
        }
    }

    /**
    * Returns the tags associated with this page or post
    */
    private function get_tags($postId) {
        $tags = array();
        $wpTags = wp_get_post_tags($postId);
        foreach ( $wpTags as $wpTag ) {
            array_push($tags, $wpTag->name);
        }

        return $tags;
    }

    /**
     * Returns an array of all the child categories for the current post
     */
    private function get_categories($postID, $delimiter='/') {
        $tags = array();
        $categories = get_the_category($postID);
        foreach( $categories as $category ) {
            $hierarchy = get_category_parents($category, FALSE, $delimiter);
            $hierarchy = rtrim($hierarchy, '/');
            array_push($tags, $hierarchy);
        }
        // take last element in the hierarchy, a string representing the full parent->child tree, 
        // and split it into individual category names
        $tags = explode('/', end($tags));
        // remove uncategorized value from tags
        $tags = array_diff($tags, array('Uncategorized'));
        return $tags;
    }

    /**
    * Safely returns options for the plugin by assigning defaults contained in optionDefaults.  As soon as actual
    * options are saved, they override the defaults.  This prevents us from having to do a lot of isset() checking
    * on variables.
    */
    private function get_options() {
        $options = get_option(Parsely::OPTIONS_KEY);
        if ( $options === false ) {
            $options = $this->optionDefaults;
        } else {
            $options = array_merge($this->optionDefaults, $options);
        }
        return $options;
    }

    /**
    * Returns a properly cleaned category/taxonomy value and will optionally use the top-level category/taxonomy value
    * if so instructed via the `use_top_level_cats` option.
    */
     private function get_category_name($postObj, $parselyOptions) { 
        $taxonomy_dropdown_choice = get_the_terms($postObj->ID, $parselyOptions['custom_taxonomy_section']);
        // Get top-level taxonomy name for chosen taxonomy and assign to $parent_name; it will be used 
        // as the category value if 'use_top_level_cats' option is checked. 
        // Assign as "Uncategorized" if no value is checked for the chosen taxonomy.
        if ( !empty($taxonomy_dropdown_choice) ) {
            $first_term = array_shift($taxonomy_dropdown_choice);
            $parent_name = $this->get_top_level_term($first_term->term_id, $first_term->taxonomy);
            $child_name = $this->get_bottom_level_term($postObj->ID, $parselyOptions['custom_taxonomy_section']);
            $category = $parselyOptions['use_top_level_cats'] ? $parent_name : $child_name;
        } else {
            $category = 'Uncategorized';
        }
        $category = apply_filters( 'wp_parsely_post_category', $category, $postObj, $parselyOptions );
        $category = $this->get_clean_parsely_page_value( $category );
        return $category;
    }

    /**
    * Return the top-most category/taxonomy value in a hierarcy given a taxonomy value's ID 
    * (Wordpress calls taxonomy values 'terms').
    */
    private function get_top_level_term($term_id, $taxonomy_name) {
        $parent = get_term_by( 'id', $term_id, $taxonomy_name );
        while ( $parent->parent != 0 ){
            $parent = get_term_by( 'id', $parent->parent, $taxonomy_name );
        }
        return $parent->name;
    }

    private function get_bottom_level_term($postId, $taxonomy_name) {
        $terms = get_the_terms($postId, $taxonomy_name);
        $term_ids = wp_list_pluck($terms, 'term_id');
        $parents = array_filter(wp_list_pluck($terms, 'parent'));
        //Get array of IDs of terms which are not parents.
        $term_ids_not_parents = array_diff($term_ids,  $parents);
        //Get corresponding term objects, which are mapped to array index keys
        $terms_not_parents = array_intersect_key($terms,  $term_ids_not_parents);
        //remove array index keys
        $terms_not_parents_cleaned = array();
        foreach ($terms_not_parents as $index => $value) {
            array_push($terms_not_parents_cleaned, $value);
        }
        //if you assign multiple child terms in a custom taxonomy, will only return the first
        return $terms_not_parents_cleaned[0]->name;
    }

    // Get all term values from custom taxonomies
    private function get_custom_taxonomy_values($postObj, $parselyOptions) {
        // filter out default WordPress taxonomies
        $all_taxonomies = array_diff(get_taxonomies(), array('post_tag', 'nav_menu', 'author', 'link_category', 'post_format'));
        $all_values = array();
        if (is_array($all_taxonomies)) {
            foreach ( $all_taxonomies as $taxonomy ) {
                $custom_taxonomy_objects = get_the_terms($postObj->ID, $taxonomy);
                if (is_array($custom_taxonomy_objects)) {
                    foreach ( $custom_taxonomy_objects as $custom_taxonomy_object ) {
                        array_push($all_values, $custom_taxonomy_object->name);
                    }
                }
            }
        }
        return $all_values;
    }

    /**
     * Returns a list of coauthors for a post assuming the coauthors plugin is
     * installed. Borrowed from
     * https://github.com/Automattic/Co-Authors-Plus/blob/master/template-tags.php#L3-35
     */
    private function get_coauthor_names($post_id) {
        $coauthors = array();
        if (class_exists('coauthors_plus')) {
            global $post, $post_ID, $coauthors_plus, $wpdb;

            $post_id = (int)$post_id;
            if ( !$post_id && $post_ID )
                $post_id = $post_ID;
            if ( !$post_id && $post )
                $post_id = $post->ID;

            if ( $post_id ) {
                $coauthor_terms = get_the_terms( $post_id, $coauthors_plus->coauthor_taxonomy );

                if ( is_array( $coauthor_terms ) && !empty( $coauthor_terms ) ) {
                    foreach( $coauthor_terms as $coauthor ) {
                        $coauthor_slug = preg_replace( '#^cap\-#', '', $coauthor->slug );
                        $post_author =  $coauthors_plus->get_coauthor_by( 'user_nicename', $coauthor_slug );
                        // In case the user has been deleted while plugin was deactivated
                        if ( !empty( $post_author ) )
                            $coauthors[] = $post_author;
                    }
                } else if ( !$coauthors_plus->force_guest_authors ) {
                    if ( $post && $post_id == $post->ID ) {
                        $post_author = get_userdata( $post->post_author );
                    } else {
                        $post_author = get_userdata( $wpdb->get_var( $wpdb->prepare("SELECT post_author FROM $wpdb->posts WHERE ID = %d", $post_id ) ) );
                    }
                    if ( !empty( $post_author ) )
                        $coauthors[] = $post_author;
                } // the empty else case is because if we force guest authors, we don't ever care what value wp_posts.post_author has.
            }
        }
        return $coauthors;
    }

    /**
    * Determine author name from display name, falling back to firstname +
    * lastname, then nickname and finally the nicename.
    */
    private function get_author_name($author) {
        $author_name = $author->display_name;
        if ( !empty($author_name) ) {
            return $author_name;
        }

        $author_name = $author->user_firstname . ' ' . $author->user_lastname;
        if ( $author_name != ' ' ) {
            return $author_name;
        }

        $author_name = $author->nickname;
        if ( !empty($author_name) ) {
            return $author_name;
        }

        return $author->user_nicename;
    }

    /**
     * Retrieve all the authors for a post as an array. Can include multiple
     * authors if coauthors plugin is in use.
     */
    private function get_author_names($post) {
        $authors = $this->get_coauthor_names($post->ID);
        if ( empty($authors) ) {
            $authors = array(get_user_by('id', $post->post_author));
        }
        $authors = array_map(array($this, 'get_author_name'), $authors);
        $authors = apply_filters( 'wp_parsely_post_authors', $authors, $post );
        $authors = array_map(array($this, 'get_clean_parsely_page_value'), $authors);
        return $authors;
    }

    /* sanitize content
    */
    private function get_clean_parsely_page_value($val) {
        if ( is_string($val) ) {
            $val = str_replace("\n", '', $val);
            $val = str_replace("\r", '', $val);
            $val = strip_tags($val);
            $val = trim($val);
            return $val;
        } else {
            return $val;
        }
    }


    /* Get the URL of the plugin settings page */
    private function get_settings_url() {
        return admin_url('options-general.php?page='.Parsely::MENU_SLUG);
    }


    /**
    * Get the URL of the current PHP script.
    * A fall-back implementation to determine permalink
    */
    private function get_current_url($post = 'nonpost') {
        $options = $this->get_options();
        $scheme = ( $options['force_https_canonicals'] ? 'https://' : 'http://');
        if ($post == 'post') {
            $permalink = get_permalink();
            $parsed_canonical = parse_url($permalink);
            $canonical = $scheme . $parsed_canonical['host'] . $parsed_canonical['path'];
            return $canonical;
        }
        $pageURL = $scheme . $_SERVER['HTTP_HOST'];
        if ( $_SERVER['SERVER_PORT'] != '80' || $_SERVER['SERVER_PORT'] != '443' ) {
            $pageURL .= ':'.$_SERVER['SERVER_PORT'];
        }
        $pageURL .= $_SERVER['REQUEST_URI'];
        return $pageURL;
    }

    /* https://css-tricks.com/snippets/wordpress/get-the-first-image-from-a-post/ */
    function get_first_image($post) {
        ob_start();
        ob_end_clean();
        if (preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $post->post_content, $matches)) {
            $first_img = $matches[1][0];
            return $first_img;
        }
        return '';
    }

    public function insert_parsely_tracking_fbia(&$registry) {
        $options = $this->get_options();
        $display_name = 'Parsely Analytics';
        $identifier = 'parsely-analytics-for-wordpress';
        $embed_code = '<script>
            PARSELY = {
                autotrack: false,
                onload: function() {
                    PARSELY.beacon.trackPageView({
                        urlref: \'http://facebook.com/instantarticles\'
                    });
                    return true;
                }
            }
        </script>
        <div id="parsely-root" style="display: none">
            <span id="parsely-cfg" data-parsely-site="'. esc_attr( $options['apikey'] ) .'"></span>
        </div>
        <script>
            (function(s, p, d) {
            var h=d.location.protocol, i=p+"-"+s,
            e=d.getElementById(i), r=d.getElementById(p+"-root"),
            u=h==="https:"?"d1z2jf7jlzjs58.cloudfront.net"
            :"static."+p+".com";
            if (e) return;
            e = d.createElement(s); e.id = i; e.async = true;
            e.src = h+"//"+u+"/p.js"; r.appendChild(e);
            })("script", "parsely", document);
        </script>
        <!-- END Parse.ly Include: Standard -->';

        $registry[$identifier] = array(
            'name' => $display_name,
            'payload' => $embed_code
        );

        return $embed_code;
    }

    public function parsely_add_amp_actions() {
        add_filter('amp_post_template_analytics', array($this, 'parsely_add_amp_analytics'));
    }

    public function parsely_add_amp_analytics( $analytics ) {
        $options = $this->get_options();

        if ( empty( $options['apikey'] ) ) {
            return $analytics;
        }

        $analytics['parsely'] = array(
            'type' => 'parsely',
            'attributes' => array(),
            'config_data' => array(
                'vars' => array(
                    'apikey' => $options['apikey'],
                )
            ),
        );

        return $analytics;
    }

    public function parsely_is_user_logged_in() {
        // can't use $blog_id here because it futzes with the global $blog_id
        $current_blog_id = get_current_blog_id();
        $current_user_id = get_current_user_id();
        return is_user_member_of_blog($current_user_id, $current_blog_id);
    }
}


if ( class_exists('Parsely') ) {
    define('PARSELY_VERSION', Parsely::VERSION);
    $parsely = new Parsely();
}

include "recommended_widget.php";