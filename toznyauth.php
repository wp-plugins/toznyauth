<?php
/*
Plugin Name: Tozny
Description: Add Tozny as an authentication option to your WordPress blog.
Version: 	 1.0.3
Author:      TOZNY, LLC
Author URI:  http://www.tozny.com
Plugin URI:  http://www.tozny.com#wordpress
License:     GPLv2
Text Domain: toznyauth
*/

/*  Copyright 2014 - 2014 SEQRD, LLC  (email: info@tozny.com)
 */

/**
 * Stop direct calls to this page
 */
if(preg_match('#' . basename(__FILE__) . '#', $_SERVER['PHP_SELF'])) die('Sorry, you don&#39;t have direct access to this page.');

//=====================================================================
require_once 'ToznyRemoteUserAPI.php';
require_once 'ToznyRemoteRealmAPI.php';
//=====================================================================


//=====================================================================
// Wordpress hook callback functions.
//=====================================================================
add_action('login_enqueue_scripts','add_tozny_login_css');
add_action('login_head','add_tozny_lib');
add_action('login_form','add_tozny_script');
add_action('admin_menu', 'tozny_create_menu');
add_action('load-toplevel_page_toznyauth/toznyauth','test_realm_key');

# user editing their own profile page.
add_action('personal_options_update', 'update_extra_profile_fields');
add_action('profile_personal_options', 'extra_profile_fields' );

//=====================================================================
function update_extra_profile_fields($user_id) {
    if ( current_user_can('edit_user',$user_id) ) {
        if (get_user_meta($user_id, 'tozny_activate', true) != 'on' && $_POST['tozny_activate'] == 'on') {
            update_user_meta($user_id, 'tozny_create_user', true);
        }
        else {
            update_user_meta($user_id, 'tozny_create_user', false);
        }
        update_user_meta($user_id, 'tozny_activate', $_POST['tozny_activate']);
    }
}
function extra_profile_fields($user) {
    if (current_user_can('edit_user',$user->ID) && ('on' == get_option('tozny_allow_users_to_add_devices')) ) {
?>
        <h3>Tozny</h3>
        <table class="form-table">
            <tr>
                <th><label for="tozny_activate">Use Tozny for this account?</label></th>
                <td>
                    <input type="checkbox" name="tozny_activate" id="tozny_activate" <?php if ( 'on' == get_user_meta($user->ID, 'tozny_activate', true) ) echo 'checked="checked"'; ?>/>
                    <span id="tozny_activate_description" class="description">Use Tozny to log into this account.<strong></strong></span>
                    <?php
                    if (get_user_meta($user->ID, 'tozny_create_user', true)) {

                        $API_URL = get_option('tozny_api_url');
                        $REALM_KEY_ID = get_option('tozny_realm_key_id');
                        $REALM_KEY_SECRET = get_option('tozny_realm_key_secret');
                        $realm_api = new Tozny_Remote_Realm_API($REALM_KEY_ID,$REALM_KEY_SECRET,$API_URL);
                        $tozny_user = null;
                        try {
                            # 1.  Get the email address from wprdpress
                            # 2.  lookup email address on tozny, to see if the users exists already, and we need to add a new device.
                            $tozny_user = $realm_api->userGetEmail($user->user_email);
                        } catch (Exception $e) {
                            $error_message = $e->getMessage();
                            ?> <div id="message" class="error"><p><strong><?= $error_message ?></strong></p></div><?php
                        }

                        if (!is_null($tozny_user)) {
                            # 3a. if the user does not exist, call real.user_add, paint the QR_url
                            if ($tozny_user) {
                                $new_device = $realm_api->realmUserDeviceAdd($tozny_user['user_id']);
                                if ($new_device['return'] === 'ok') {
                                    ?>
                                    <div style="margin-top: 10px;">
                                    <a href="<?= $new_device['secret_enrollment_url'] ?>">
                                        <img src="<?= $new_device['secret_enrollment_qr_url'] ?>" id="qr" class="center-block" style="height: 200px; width: 200px;">
                                    </a>
                                    </div>
                                    <?php
                                }
                                else {
                                    $error = array_shift($new_device['errors']);
                                    ?> <div id="message" class="error"><p><strong><?= $error['error_message'] ?></strong></p></div><?php
                                }
                            }

                            # 3b. if the user does exists, add a new user
                            else {
                                try {
                                    $realm_fields = $realm_api->fieldsGet();
                                    if ($realm_fields['return'] !== 'ok') {
                                        $error = array_shift($realm_fields['errors']);
                                        throw new Exception($error['error_message']);
                                    }
                                    $user_meta = array();
                                    foreach ($realm_fields['results'] as $field) {
                                        // this will set like "tozny_email" and stuff like that
                                        if (!empty($field['maps_to'])) {
                                            switch ($field['maps_to']) {
                                                case "tozny_email":
                                                    $user_meta[$field['field']] = $user->user_email;
                                                    break;
                                                case "tozny_username":
                                                    $user_meta[$field['field']] = $user->user_login;
                                                    break;
                                            }
                                        }
                                    }
                                    $tozny_user = $realm_api->userAdd('true', $user_meta);
                                    if ($tozny_user['return'] !== 'ok') {
                                        $error = array_shift($tozny_user['errors']);
                                        throw new Exception($error['error_message']);
                                    }

                                    ?>
                                    <div style="margin-top: 10px;">
                                    <a href="<?= $tozny_user['secret_enrollment_url'] ?>">
                                        <img src="<?= $tozny_user['secret_enrollment_qr_url'] ?>" id="qr" class="center-block" style="height: 200px; width: 200px;">
                                    </a>
                                    </div>
                                    <?php
                                }
                                catch (Exception $e) {
                                    $error_message = $e->getMessage();
                                    ?> <div id="message" class="error"><p><strong><?= $error_message ?></strong></p></div><?php
                                }

                            }
                        }
                        update_user_meta($user->ID, 'tozny_create_user', false);
                    }
                    ?>

                    <script type="text/javascript">

                        jQuery(document).ready(function() {
                            jQuery('#tozny_activate').on('click', function () {
                                if (jQuery(this).attr('checked') === 'checked') {
                                    jQuery('#tozny_activate_description strong').empty().append("<p>Your Tozny account key will be displayed once you click the 'Update Profile' button below.</p>");
                                } else {
                                    jQuery('#tozny_activate_description strong').empty();
                                }
                            });
                        });

                    </script>
                </td>
            </tr>
        </table>
<?php
    }
}
function test_realm_key() {
    if(isset($_GET['settings-updated']) && $_GET['settings-updated'])
    {
        global $REALM_KEY_TEST_SUCCESS;
        global $REALM_KEY_TEST_MESSAGE;

        $API_URL = get_option('tozny_api_url');
        $REALM_KEY_ID = get_option('tozny_realm_key_id');
        $REALM_KEY_SECRET = get_option('tozny_realm_key_secret');
        $realm_api = new Tozny_Remote_Realm_API($REALM_KEY_ID,$REALM_KEY_SECRET,$API_URL);
        try {
            $resp = $realm_api->realmKeysGet();
            if (array_key_exists('return', $resp) && $resp['return'] === 'ok') {
                $REALM_KEY_TEST_SUCCESS = true;
                $REALM_KEY_TEST_MESSAGE = 'Realm key credentials look good!';
            }
            else {
                $e = array_shift($resp['errors']);
                $REALM_KEY_TEST_SUCCESS = false;
                $REALM_KEY_TEST_MESSAGE = "Error while testing realm key credentials with Tozny. More info: ".$e['error_message'];
            }
        }
        catch (Exception $e) {
            $REALM_KEY_TEST_SUCCESS = false;
            $REALM_KEY_TEST_MESSAGE = "Error while testing realm key credentials with Tozny. More info: ".$e->getMessage();
        }
    }
}

function add_tozny_login_css() {
    ?>
    <link href="https://s3-us-west-2.amazonaws.com/tozny/production/interface/javascript/v2/tozny.css" rel="stylesheet" type="text/css" />
    <style type="text/css">
        .toz-button {
            margin: 0 auto;
            padding-bottom: 20px;
        }
    </style>
    <?php
}

function add_tozny_lib() {

    global $error;

    $API_URL = get_option('tozny_api_url');
    $REALM_KEY_ID = get_option('tozny_realm_key_id');
    $REALM_KEY_SECRET = get_option('tozny_realm_key_secret');
    $ALLOW_USERS_TO_ADD_DEVICES = get_option('tozny_allow_users_to_add_devices');

    if (!empty($_POST['tozny_action'])) {
        $tozny_signature   = $_POST['tozny_signature'];
        $tozny_signed_data = $_POST['tozny_signed_data'];
        $redirect_to = (array_key_exists('redirect_to', $_POST) && !empty($_POST['redirect_to'])) ? $_POST['redirect_to'] : '/';
        $realm_api = new Tozny_Remote_Realm_API($REALM_KEY_ID,$REALM_KEY_SECRET,$API_URL);
        if ($realm_api->verifyLogin($tozny_signed_data,$tozny_signature)) {
            $fields = null;
            $data   = null;
            $user   = null;

            try {
                $rawCall = $realm_api->fieldsGet();
                if (array_key_exists('return', $rawCall) && $rawCall['return'] === 'ok') {
                    $fields = $rawCall['results'];
                } else {
                    $more_info = (array_key_exists('return', $rawCall) && $rawCall['return'] === 'error') ? print_r($rawCall['errors'], true) : "";
                    $error = $error = "Error while retrieving fields from Tozny.".$more_info;
                }
            }
            catch (Exception $e) {
                $error = "Error while retrieving fields from Tozny. More info: ".$e->getMessage();
            }

            try { $data = $realm_api->decodeSignedData($tozny_signed_data); }
            catch (Exception $e) {
                $error = "Error while decoding signed data from Tozny. More info: ".$e->getMessage();
            }

            try { $user = $realm_api->userGet($data['user_id']); }
            catch (Exception $e) {
                $error = "Error while retrieving user data from Tozny. More info: ".$e->getMessage();
            }

            // Dude, where's your monad?
            if ( !empty($fields) && !empty($data) && !empty($user) && empty($error)) {
                $wp_user = null;
                $distinguished_fields = distinguished($fields);
                foreach ($distinguished_fields as $distinguished_name => $fields) {
                    foreach ($fields as $field_name => $field) {
                        if (array_key_exists($field_name, $user['meta'])) {
                            switch ($distinguished_name) {
                                case 'tozny_username':
                                    $wp_user = get_user_by('login', $user['meta'][$field_name]);
                                    if ($wp_user) break 3;
                                    break;
                                case 'tozny_email':
                                    $wp_user = get_user_by('email', $user['meta'][$field_name]);
                                    if ($wp_user) break 3;
                                    break;
                            }
                        }
                    }
                }
                // We found a corresponding WordPress user
                if ($wp_user) {
                    wp_set_auth_cookie($wp_user->ID);
                    wp_set_current_user($wp_user->ID);
                    wp_redirect($redirect_to);
                }
                // We did not found a corresponding WordPress user
                else {
                    $error = "Could not find a Wordpress user with a matching username or email address. Please contact your administrator.";
                }

            }

        } else {
            $error = 'Session verification failed. Please contact your administrator.';
        }
    }
    displayToznyJavaScript($API_URL);
} // add_tozny_lib


function add_tozny_script() {

    $API_URL = get_option('tozny_api_url');
    $REALM_KEY_ID = get_option('tozny_realm_key_id');
    $MODAL_ON_LOAD = get_option('tozny_modal_on_load');

?>
        <div id="qr_code_login" style="margin: 0 auto; text-align: center;"></div>

        <input type="hidden" name="realm_key_id" value="<?= htmlspecialchars($REALM_KEY_ID) ?>">

        <script type="text/javascript">
            $(document).ready(function() {
                $('#qr_code_login').tozny({
                    'type'              : 'login',
                    'style'             : '<?= ($MODAL_ON_LOAD) ? 'modal' : 'button' ?>',
                    'realm_key_id'      : '<?= $REALM_KEY_ID ?>',
                    'api_url'           : '<?= $API_URL . 'index.php' ?>',
                    'loading_image'     : '<?= $API_URL ?>interface/javascript/images/loading.gif',
                    'login_button_image': '<?= $API_URL ?>interface/javascript/images/click-to-login-black.jpg',
                    'form_type'         : 'custom',
                    'form_id'           : 'loginform',
                    'login_button_hide' : true,
                    'debug'             : false
                });

            });
        </script>

<?php
}

function tozny_create_menu() {
    add_menu_page('Tozny Plugin Settings', 'Tozny', 'administrator', __FILE__, 'tozny_settings_page',plugins_url('/images/icon.png', __FILE__));

    add_action( 'admin_init', 'register_tozny_settings' );
}


function register_tozny_settings() {
    register_setting( 'tozny-settings-group', 'tozny_realm_key_id' );
    register_setting( 'tozny-settings-group', 'tozny_realm_key_secret' );
    register_setting( 'tozny-settings-group', 'tozny_api_url' );
    register_setting( 'tozny-settings-group', 'tozny_allow_users_to_add_devices' );
    register_setting( 'tozny-settings-group', 'tozny_modal_on_load' );
}
//=====================================================================

/**
 * @param $fields
 * @return array An Array containing the given fields, keyed first by their tozny distinguished field name, then by the individual field names.
 */
function distinguished($fields) {
    $dist = array(
        'tozny_username' => array(),
        'tozny_email'    => array()
    );

    foreach ($fields as $field) {
        switch($field['maps_to']) {
            case "tozny_username":
                if ($field['uniq'] === 'yes')
                    $dist['tozny_username'][$field['field']] = $field;
                break;
            case "tozny_email":
                if ($field['uniq'] === 'yes')
                    $dist['tozny_email'][$field['field']] = $field;
                break;
        }
    }

    return $dist;
}

//=====================================================================
// HTML display functions.
//=====================================================================
function displayToznyJavaScript ($api_url) {
?>
    <script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
    <!--script src="<?= $api_url . 'interface/jquery.tozny.js' ?>"></script-->
    <script src="https://s3-us-west-2.amazonaws.com/tozny/production/interface/javascript/v2/jquery.tozny.js"></script>
<?php
}




function tozny_settings_page() {
    global $REALM_KEY_TEST_SUCCESS;
    global $REALM_KEY_TEST_MESSAGE;
    ?>
    <div class="wrap">
        <h2>Tozny</h2>

        <form method="post" action="options.php">
            <?php settings_fields( 'tozny-settings-group' ); ?>
            <?php do_settings_sections( 'tozny-settings-group' ); ?>
            <?php if (isset($REALM_KEY_TEST_MESSAGE) && isset($REALM_KEY_TEST_SUCCESS)): ?>
            <div id="message" class="<?= ($REALM_KEY_TEST_SUCCESS) ? "updated" : "error" ?>">
                    <p><strong><?= $REALM_KEY_TEST_MESSAGE ?></strong></p>
            </div>
            <?php endif; ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">API URL</th>
                    <td><input type="text" name="tozny_api_url" value="<?php $api_url = get_option('tozny_api_url'); echo empty($api_url) ? 'https://api.tozny.com/' : $api_url; ?>" /></td>
                </tr>

                <tr valign="top">
                    <th scope="row">Realm Key ID</th>
                    <td><input type="text" name="tozny_realm_key_id" value="<?= get_option('tozny_realm_key_id') ?>" /></td>
                </tr>

                <tr valign="top">
                    <th scope="row">Realm Key Secret</th>
                    <td><input type="text" name="tozny_realm_key_secret" value="<?= get_option('tozny_realm_key_secret') ?>" /></td>
                </tr>

                <tr valign="top">
                    <th scope="row">Allow users to add devices?</th>
                    <td><input type="checkbox" name="tozny_allow_users_to_add_devices" <?php if ( 'on' == get_option('tozny_allow_users_to_add_devices') ) echo 'checked="checked"'; ?> /></td>
                </tr>

                <tr valign="top">
                    <th scope="row">Show modal on login-page load?</th>
                    <td><input type="checkbox" name="tozny_modal_on_load" <?php if ( 'on' == get_option('tozny_modal_on_load') ) echo 'checked="checked"'; ?> /></td>
                </tr>
            </table>

            <?php submit_button(); ?>

        </form>
    </div>
<?php }
//=====================================================================
