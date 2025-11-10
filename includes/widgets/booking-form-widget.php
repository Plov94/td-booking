<?php
defined('ABSPATH') || exit;

class TD_Booking_Form_Widget extends WP_Widget {
    function __construct() {
        parent::__construct('td_booking_form_widget', __('TD Booking Form', 'td-booking'));
    }
    function widget($args, $instance) {
        echo $args['before_widget'];
        echo do_shortcode('[td_booking_form]');
        echo $args['after_widget'];
    }
}
add_action('widgets_init', function() {
    register_widget('TD_Booking_Form_Widget');
});
