<?php 

function champion_customer_resolve_invite_parent( $invite_raw ) {
    $invite_raw = trim( (string) $invite_raw );
    if ( $invite_raw === '' ) {
        return 0;
    }

    // If pure number, assume user ID
    if ( ctype_digit( $invite_raw ) ) {
        $maybe_id = (int) $invite_raw;
        if ( $maybe_id > 0 && get_user_by( 'id', $maybe_id ) ) {
            return $maybe_id;
        }
    }

    // Otherwise try champion_ref_code
    $users = get_users( [
        'meta_key'   => 'champion_customer_ref_code',
        'meta_value' => $invite_raw,
        'number'     => 1,
        'fields'     => 'ID',
    ] );

    if ( ! empty( $users ) ) {
        return (int) $users[0];
    }

    return 0;
}

add_action('elementor_pro/forms/new_record', function($record, $handler) {

    $form_name = $record->get_form_settings('form_name');
    // Match this name with your Elementor form name
    if ('New Form' !== $form_name) {
        return;
    }

    $raw_fields = $record->get('fields');
    $fields = [];
    foreach ($raw_fields as $key => $field) {
        $fields[$key] = $field['value'];
    }

    // Get email & password from form fields
    $email    = sanitize_email($fields['email']);
    $password = sanitize_text_field($fields['field_f56b157']); // your password field ID

    /*----- 01-01-2026 START -----*/
    $invite_raw = isset( $fields['champion_ref_id'] ) ? sanitize_text_field( wp_unslash( $fields['champion_ref_id'] ) ) : '';
    $parent_id  = champion_customer_resolve_invite_parent( $invite_raw );
    /*----- 01-01-2026 START -----*/

    // Create username from email prefix
    $username = sanitize_user(current(explode('@', $email)));

    // Ensure username uniqueness
    $base_username = $username;
    $count = 1;
    while (username_exists($username)) {
        $username = $base_username . $count;
        $count++;
    }

    // Check for existing email
    if (email_exists($email)) {
        $handler->add_error_message('This email is already registered. Please log in instead.');
        return;
    }

    // Create the new user
    $user_id = wp_create_user($username, $password, $email);

    if (is_wp_error($user_id)) {
        $handler->add_error_message('Something went wrong while creating your account. Please try again.');
        return;
    }

    // Assign WooCommerce "customer" role
    wp_update_user(['ID' => $user_id, 'role' => 'customer']);

    /*----- 01-01-2026 START -----*/
    // Mark as ambassador (for helpers/options)
    update_user_meta( $user_id, 'is_customer', 1 );

    // Generate ref code
    $ref_code = 'CUS' . $user_id;

    // Store in usermeta (dashboard uses champion_ref_code)
    update_user_meta( $user_id, 'champion_customer_ref_code', $ref_code );
    // Backward compat if kahin aur use ho raha ho:
    update_user_meta( $user_id, 'customer_ref_code', $ref_code );

    // Parent mapping for tree / milestones
    if ( $parent_id > 0 ) {
        // Child side: who is my parent ambassador
        update_user_meta( $user_id, 'champion_parent_customer', $parent_id );

        // Parent side: list of referred ambassadors
        $referred = get_user_meta( $parent_id, 'champion_referred_customers', true );
        if ( ! is_array( $referred ) ) {
            $referred = [];
        }

        if ( ! in_array( $user_id, $referred, true ) ) {
            $referred[] = $user_id;
            update_user_meta( $parent_id, 'champion_referred_customers', $referred );
        }
    }

    // Extra meta (optional)
    update_user_meta( $user_id, 'invited_by', $parent_id );
    update_user_meta( $user_id, 'total_points', 0 );
    /*----- 01-01-2026 END -----*/
    
    // Auto login
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id);

    
    $handler->add_success_message('Account created successfully! Redirecting...');

}, 10, 2);


add_action('elementor_pro/forms/validation', function($record, $ajax_handler) {
    $form_name = $record->get_form_settings('form_name');
    if ('New Form' === $form_name) {
        $ajax_handler->add_response_data('redirect_url', home_url('/my-account/'));
    }
}, 10, 2);


// Custom shortcode: [custom-lostpassword]
function custom_lostpassword_shortcode() {

    
    
    // Only show if user is NOT logged in
    if ( is_user_logged_in() ) {
        return ''; // Don't show anything
    }
    ob_start();

    ?>
    <p class="woocommerce-LostPassword lost_password">
        <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">
            <?php esc_html_e( 'Lost your password?', 'woocommerce' ); ?>
        </a>
    </p>
    <?php
    return ob_get_clean();
}
add_shortcode('custom-lostpassword', 'custom_lostpassword_shortcode');


add_action('template_redirect', function() {

    // Get page objects by slug
    $signup_page = get_page_by_path('sign-up');
    $signin_page = get_page_by_path('sign-in');

    // Get current page ID
    $current_id = get_queried_object_id();

    // WooCommerce My Account page
    $myaccount_id  = get_option('woocommerce_myaccount_page_id');
    $myaccount_url = wc_get_page_permalink('myaccount');

    // Get current URL path (to detect subpages like /lost-password/)
    $current_path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

    // --- CASE 1: Logged-in users visiting sign-up or sign-in ---
    if ( is_user_logged_in() ) {
        if (
            ($signup_page && $current_id === $signup_page->ID) ||
            ($signin_page && $current_id === $signin_page->ID)
        ) {
            wp_safe_redirect($myaccount_url);
            exit;
        }
    }

    // --- CASE 2: Not logged-in users visiting My Account ---
    else {
        // Allow lost-password and reset-password subpages
        $is_lost_password = str_contains($current_path, 'lost-password') || str_contains($current_path, 'reset-password');

        if ( $current_id === (int) $myaccount_id && ! $is_lost_password ) {
            $signin_url = $signin_page ? get_permalink($signin_page->ID) : home_url('/sign-in/');
            wp_safe_redirect($signin_url);
            exit;
        }
    }
});