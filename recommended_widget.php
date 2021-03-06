<?php

class parsely_recommended_widget extends WP_Widget
{
    public function __construct()
    {
        $widget_options = array(
            'classname' => 'parsely_recommended_widget',
            'description' => 'Parsely recommendation widget',
        );
        parent::__construct('parsely_recommended_widget', 'Parsely Recommended Widget', $widget_options);
    }

    function get_user_id_by_display_name( $display_name ) {
        global $wpdb;

        if ( ! $user = $wpdb->get_row( $wpdb->prepare(
            "SELECT `ID` FROM $wpdb->users WHERE `display_name` = %s", $display_name
        ) ) )
            return false;

        return $user->ID;
    }

    public function widget( $args, $instance ) {
        $title = apply_filters( 'widget_title', $instance[ 'title' ] );
        $instance['display_options'] = !empty($instance['display_options']) ? $instance['display_options'] : array();
        echo $args['before_widget'] . $args['before_title'] . $title . $args['after_title']; ?>

        <?php
        // set up variables
        $options = get_option('parsely');
        if (array_key_exists('apikey', $options) && array_key_exists('api_secret', $options) && !empty($options['api_secret']))
        {
            $root_url = 'https://api.parsely.com/v2/related?apikey=' . $options['apikey'] . '&secret=' . $options['api_secret'];
            $pub_date_start = '&pub_date_start=' . $instance['published_within'] . 'd';
            $sort = '&sort=' . $instance['sort'];
            $boost = '&boost=' . $instance['boost'];
            $limit = '&limit=' . $instance['return_limit'];
            $url = '&url=' . get_permalink();
            $full_url = $root_url . $sort . $boost . $limit;
            if ($instance['personalize_results']) {
                // this gets pretty gross: we need to get the uuid from the javascript tracker, but it's not super easy
                // to transmit a variable back from JS to Wordpress. What we're going to do is set a POST variable in
                // the javascript, and try to read from it. This feels absolutely gross, but unless a better way is
                // presented it seems to be what we've got.
                    if(isset($_COOKIE['_parsely_visitor'])){
                        $parsely_visitor = json_decode(stripslashes($_COOKIE['_parsely_visitor']));
                        if ($parsely_visitor->id) {
                            $uuid_string = '&uuid=' . $parsely_visitor->id;
                            $full_url .= $uuid_string;
                        }
                        else {
                            $full_url .= $url;
                        }
                    }
                    else {
                        $full_url .= $url;
                    }

            }
            else {
                $full_url .= $url;
            }
            if ((int) $instance['published_within'] != 0) {
                $full_url .= $pub_date_start;
            }
            $response = wp_remote_get($full_url);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);
            if (!$data->success) {
                ?>
                <p>
                    looks like your API secret is incorrect- please double check your API secret in your Parsely wordpress settings
                    against the value in <a href="http://dash.parsely.com/<?php echo $options['apikey']?>/settings/api/">
                        http://dash.parsely.com/<?php echo $options['apikey']?>/settings/api/!
                    </a>
                </p>
                <?php
            }
            $data = $data->data;

            // TODO: if themes want to handle the raw data themselves, let them go for it
            ?>
            <div class="parsely-recommendation-widget">
                <ul class="parsely-recommended-widget">
                    <?php foreach ($data as $index=>$post) { ?>
                        <li class="parsely-recommended-widget-entry" id="parsely-recommended-widget-item-<?php echo $index?>">
                            <?php if (in_array('display_thumbnail', $instance['display_options'])) { ?>
                            <img src="<?php echo $post->thumb_url_medium;?>"/>
                            <?php } ?>
                            <div class="parsely-title-author-wrapper">
                                <a href="<?php echo $post->url;?>"><?php echo $post->title;?></a>
                                <?php
                                // Try to get a link to the author via their name. This doesn't always work- worst case, just
                                // link to the post.
                                if (in_array('display_author', $instance['display_options'])) {
                                    $author_id = self::get_user_id_by_display_name($post->author);
                                    if ($author_id) {
                                        $author_url = get_author_posts_url($author_id);
                                    }
                                    else {
                                        $author_url = $post->url;
                                    }
                                    ?>
                                    <a class="parsely-author" href="<?php echo $author_url;?>"><?php echo $post->author; ?></a>
                                <?php } ?>
                            </div>


                        </li>
                    <?php } ?>
                </ul>
            </div>
            <?php
        }
        else
        {
            ?>
            <p>
            you must set the Parsely API Secret for this widget to work!
            </p>
            <?php
        }


        ?>


        <?php echo $args['after_widget'];
    }

    public function form( $instance )
    {
        // editable fields: title
        $title = ! empty( $instance['title'] ) ? $instance['title'] : '';
        $return_limit = ! empty( $instance['return_limit'] ) ? $instance['return_limit'] : 5;
        $published_within = ! empty( $instance['published_within'] ) ? $instance['published_within'] : 0;
        $sort = ! empty( $instance['sort'] ) ? $instance['sort'] : 'score';
        $boost = ! empty( $instance['boost'] ) ? $instance['boost'] : 'views';
        $personalize_results = ! empty( $instance['personalize_results'] ) ? $instance['personalize_results'] : false;
        $instance['return_limit'] = $return_limit;
        $instance['published_within'] = $published_within;
        $instance['sort'] = $sort;
        $instance['boost'] = $boost;
        $instance['personalize_results'] = $personalize_results;
        $instance['display_options'] = !empty($instance['display_options']) ? $instance['display_options'] : array();
        $boost_params = array('views', 'mobile_views', 'tablet_views', 'desktop_views', 'visitors', 'visitors_new',
            'visitors_returning', 'engaged_minutes', 'avg_engaged', 'avg_engaged_new', 'avg_engaged_returning',
            'social_interactions', 'fb_interactions', 'tw_interactions', 'li_interactions', 'pi_interactions',
            'social_referrals', 'fb_referrals', 'tw_referrals', 'li_referrals', 'pi_referrals');
        ?>
        <p>
            <label for="<?php echo $this->get_field_id( 'title' ); ?>">Title:</label>
            <br>
            <input type="text" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" value="<?php echo esc_attr( $title ); ?>" />
        </p>
        <p>
            <label for="<?php echo $this->get_field_id( 'published_within' ); ?>">Published Within (0 for no limit):</label>
            <br>
            <input type="number" id="<?php echo $this->get_field_id('published_within'); ?>" name="<?php echo $this->get_field_name('published_within')?>" value="<?php echo (string) $instance['published_within'];?>" min="0" max="30"/>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id( 'return_limit' ); ?>">Number of entries to return (Max 20): </label>
            <br>
            <input type="number" id="<?php echo $this->get_field_id('return_limit'); ?>" name="<?php echo $this->get_field_name('return_limit'); ?>" value="<?php echo (string) $instance['return_limit'];?>" min="1" max="20"/>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id( 'sort' ); ?>">Sort By: </label>
            <br>
            <select id="<?php echo $this->get_field_id('sort'); ?>" name="<?php echo $this->get_field_name('sort'); ?>" class="widefat" style="width:33%;">
                <option <?php selected( $instance['sort'], 'score'); ?> value="score">score</option>
                <option <?php selected( $instance['sort'], 'pub_date'); ?> value="pub_date">pub_date</option>
            </select>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id( 'boost' ); ?>">Boost By: </label>
            <br>
            <select id="<?php echo $this->get_field_id('boost'); ?>" name="<?php echo $this->get_field_name('boost'); ?>" class="widefat" style="width:50%;">
                <?php foreach($boost_params as $boost_param) { ?>
                <option <?php selected( $instance['boost'], $boost_param); ?> value="<?php echo $boost_param;?>"><?php echo $boost_param;?></option>
            <?php } ?>
            </select>

        </p>
        <p>
            <label for="<?php echo $this->get_field_id( 'display_options' ); ?>">Display Options</label>
            <br>
            <select multiple="multiple" id="<?php echo $this->get_field_id('display_options'); ?>" name="<?php echo $this->get_field_name('display_options'); ?>[]" class="widefat" style="width:33%;">
                <option <?php if (in_array('display_author', $instance['display_options'])) { echo 'selected="selected"'; };?> value="display_author">Display Author</option>
                <option <?php if (in_array('display_thumbnail', $instance['display_options'])) { echo 'selected="selected"'; };?> value="display_thumbnail">Display Thumbnail</option>
            </select>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id( 'personalize_results' ); ?>">Personalize Recommended Results:</label>
            <br>
            <input type="checkbox" id="<?php echo $this->get_field_id('personalize_results'); ?>" name="<?php echo $this->get_field_name('personalize_results')?>" value="personalize_results" <?php checked($instance['personalize_results'], 'personalize_results') ?> />
        </p>



        <?php
    }

    public function update($new_instance, $old_instance)
    {
        $instance = $old_instance;
        $instance[ 'title' ] = strip_tags( $new_instance[ 'title' ] );
        $instance['published_within'] = (int) $new_instance['published_within'];
        $instance['return_limit'] = (int) $new_instance['return_limit'] <= 20 ? $new_instance['return_limit'] : '20';
        $instance['sort'] = $new_instance['sort'];
        $instance['boost'] = $new_instance['boost'];
        $instance['display_options'] = esc_sql($new_instance['display_options']);
        $instance['personalize_results'] = $new_instance['personalize_results'];
        return $instance;
    }
}

function parsely_recommended_widget_register()
{
    register_widget('parsely_recommended_widget');
}

add_action('widgets_init', 'parsely_recommended_widget_register');