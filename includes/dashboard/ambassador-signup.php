<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Optional: helper to detect ambassadors table.
 * Supports:
 *  A) Custom table wp_ambassadors  (if exists)
 *  B) Only usermeta                (if table not present)
 */
function champion_has_ambassador_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'ambassadors';

    $found = $wpdb->get_var(
        $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table
        )
    );

    return ( $found === $table );
}

/**
 * Invite code -> parent user ID resolve
 * Supports:
 *  - /ambassador-register/?invite=2      (user ID)
 *  - /ambassador-register/?invite=AMB2  (champion_ref_code)
 */
function champion_resolve_invite_parent( $invite_raw ) {
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
        'meta_key'   => 'champion_ref_code',
        'meta_value' => $invite_raw,
        'number'     => 1,
        'fields'     => 'ID',
    ] );

    if ( ! empty( $users ) ) {
        return (int) $users[0];
    }

    return 0;
}

/**
 * Ambassador Register Shortcode
 * Usage: [ambassador_register]
 */
function champion_render_ambassador_register() {

    if ( is_user_logged_in() ) {
        wp_safe_redirect( site_url( '/my-account/champion-dashboard/' ) );
        exit;
    }

    $error      = '';
    $invite_raw = isset( $_GET['invite'] ) ? sanitize_text_field( wp_unslash( $_GET['invite'] ) ) : '';
    $parent_id  = champion_resolve_invite_parent( $invite_raw );

    // Handle form submit
    if (
        isset( $_POST['amb_register_submit'] ) &&
        isset( $_POST['amb_reg_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['amb_reg_nonce'] ) ), 'amb_reg' )
    ) {
        $email    = isset( $_POST['amb_email'] ) ? sanitize_email( wp_unslash( $_POST['amb_email'] ) ) : '';
        $password = isset( $_POST['amb_password'] ) ? (string) $_POST['amb_password'] : '';
        $name     = isset( $_POST['amb_name'] ) ? sanitize_text_field( wp_unslash( $_POST['amb_name'] ) ) : '';

        // Re-resolve parent from hidden field if present (more reliable)
        if ( isset( $_POST['amb_parent_id'] ) ) {
            $parent_id = intval( $_POST['amb_parent_id'] );
        }

        if ( empty( $email ) || empty( $password ) ) {
            $error = 'Email and password required.';
        } elseif ( ! is_email( $email ) ) {
            $error = 'Invalid email address.';
        } elseif ( email_exists( $email ) ) {
            $error = 'Email already registered.';
        } else {
            // Username from email
            $base_username = sanitize_user( current( explode( '@', $email ) ) );
            $username      = $base_username;

            // Ensure username unique
            $i = 1;
            while ( username_exists( $username ) ) {
                $username = $base_username . '_' . $i;
                $i++;
            }

            // Create user
            $user_id = wp_create_user( $username, $password, $email );

            if ( is_wp_error( $user_id ) ) {
                $error = 'Registration failed.';
            } else {
                // Set display name
                if ( $name ) {
                    wp_update_user(
                        [
                            'ID'           => $user_id,
                            'display_name' => $name,
                        ]
                    );
                }

                // Set role (role is created via add_role in main plugin)
                $user = new WP_User( $user_id );
                $user->set_role( 'ambassador' );

                // Mark as ambassador (for helpers/options)
                update_user_meta( $user_id, 'is_ambassador', 1 );

                // Generate ref code
                $ref_code = 'AMB' . $user_id;

                // Store in usermeta (dashboard uses champion_ref_code)
                update_user_meta( $user_id, 'champion_ref_code', $ref_code );
                // Backward compat if kahin aur use ho raha ho:
                update_user_meta( $user_id, 'ref_code', $ref_code );

                // Parent mapping for tree / milestones
                if ( $parent_id > 0 ) {
                    // Child side: who is my parent ambassador
                    update_user_meta( $user_id, 'champion_parent_ambassador', $parent_id );

                    // Parent side: list of referred ambassadors
                    $referred = get_user_meta( $parent_id, 'champion_referred_ambassadors', true );
                    if ( ! is_array( $referred ) ) {
                        $referred = [];
                    }

                    if ( ! in_array( $user_id, $referred, true ) ) {
                        $referred[] = $user_id;
                        update_user_meta( $parent_id, 'champion_referred_ambassadors', $referred );
                    }
                }

                // Extra meta (optional)
                update_user_meta( $user_id, 'invited_by', $parent_id );
                update_user_meta( $user_id, 'total_points', 0 );

                // If custom ambassadors table exists, also insert there
                if ( champion_has_ambassador_table() ) {
                    global $wpdb;
                    $table = $wpdb->prefix . 'ambassadors';

                    $wpdb->insert(
                        $table,
                        [
                            'user_id'      => $user_id,
                            'ref_code'     => $ref_code,
                            'invited_by'   => $parent_id,
                            'total_points' => 0,
                            'created_at'   => current_time( 'mysql' ),
                        ],
                        [ '%d', '%s', '%d', '%d', '%s' ]
                    );
                }

                // Auto-login
                wp_set_current_user( $user_id );
                wp_set_auth_cookie( $user_id );

                // Redirect to dashboard (matches current endpoint)
                wp_safe_redirect( site_url( '/my-account/champion-dashboard/' ) );
                exit;
            }
        }
    }

    ob_start();
    ?>
    <div class="champion-amb-register">
        <h2>Ambassador Signup</h2>

        <?php if ( $invite_raw !== '' ) : ?>
            <p>
                Invited via code:
                <strong><?php echo esc_html( $invite_raw ); ?></strong>
                <?php if ( $parent_id > 0 ) : ?>
                    (Parent ID: <?php echo intval( $parent_id ); ?>)
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if ( $error ) : ?>
            <div class="champion-error" style="color:red;margin-bottom:10px;">
                <?php echo esc_html( $error ); ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field( 'amb_reg', 'amb_reg_nonce' ); ?>

            <input type="hidden" name="amb_parent_id" value="<?php echo intval( $parent_id ); ?>" />

            <p>
                <label for="amb_name">Name</label><br>
                <input type="text" name="amb_name" id="amb_name" />
            </p>

            <p>
                <label for="amb_email">Email</label><br>
                <input type="email" name="amb_email" id="amb_email" required />
            </p>

            <p>
                <label for="amb_password">Password</label><br>
                <input type="password" name="amb_password" id="amb_password" required />
            </p>

            <p>
                <button type="submit" name="amb_register_submit" class="button">
                    Create Ambassador Account
                </button>
            </p>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode( 'ambassador_register', 'champion_render_ambassador_register' );
