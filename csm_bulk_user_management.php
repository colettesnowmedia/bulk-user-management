<?php
/*
Plugin Name: Bulk User Management via CSV
Plugin URI: https://colettesnow.com/plugins/csm_bulk_user_management
Description: CSV user management addon
Version: 0.1
Author: Colette Snow
Author URI: https://colettesnow.com/
Text Domain: csm_membership
Domain Path: /languages
*/

class CSM_Bulk_User_Management {

    function __construct() {
        register_activation_hook( __FILE__, array( $this, 'db_install' ));

        add_action( "init", array( $this, "send_csv_download" ) );
        add_action( "admin_menu", array( $this, "add_menu" ) );
        add_action( "admin_init", array( $this, "add_settings" ) );
    }

    function add_menu() {
        global $menu;

        add_users_page( "Bulk User Management", "Bulk User Management", "publish_posts", "csm_bulk_user_management", array( $this, "admin_import" ) );
        add_options_page( "User Options", "User Options", "manage_options", "csm_users_opts", array( $this, "user_settings_page" ) );
        add_submenu_page( "css_members_admin", __("Bulk User Management", "csm_membership"), __( "Bulk User Management", "csm_membership" ), "publish_posts", "csm_membership_bulk", array( $this, "admin_import" ) );
    }

    function add_settings() {
        $welcome_msg = $this->get_default_welcome_msg();

        add_settings_section( "csm_users", "Bulk User Import User Options", function() { echo "<p>Customise the message sent to new users added by the CSV import.</p>"; }, "csm_users_opts" );
        add_settings_field( "csm_users_welcome", "Welcome Message", function() { wp_editor( get_option( "csm_users_welcome", $welcome_msg ), "csm_users_welcome" ); }, "csm_users_opts", "csm_users" );

        register_setting( "csm_users", "csm_users_welcome" );
    }

    function get_default_welcome_msg() {
        $default = "Hello (username),\n\n";
        $default .= "Thank you for joining <a href='(siteurl)'>(sitename)</a>. Your account details are as follows:\n\n";
        $default .= "Username: (username)\n";
        $default .= "Password: (password)\n\n";
        $default .= "Regards,\n";
        $default .= "(sitename)";

        return $default;
    }

    function user_settings_page() {
        ?>
            <div class="wrap">
            <div id="icon-options-general" class="icon32"></div>
            <h1>User Options</h1>
            <form method="post" action="options.php">
                <?php
                
                    //add_settings_section callback is displayed here. For every new section we need to call settings_fields.
                    settings_fields( "csm_users" );
                    
                    // all the add_settings_field callbacks is displayed here
                    do_settings_sections( "csm_users_opts" );
                
                    // Add the submit button to serialize the options
                    submit_button(); 
                    
                ?>          
            </form>
        </div>
        <?php
    }

    function admin_import() {
        global $wpdb;

        if ( $_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['send_emails']))
        {
            ob_start();

            $emails = $wpdb->get_results( 'SELECT * FROM '.$wpdb->prefix.'csm_bulk_emails LIMIT 0,'.absint( $_GET['send_emails'] ) );
            foreach ( $emails as $email ) {
                if (wp_mail($email->email_address, "Welcome to ".get_bloginfo( "name" ), $email->email_content, array('Content-Type: text/html; charset=UTF-8'))) {
                    $wpdb->delete($wpdb->prefix.'csm_bulk_emails', array('ID' => $email->ID));
                    echo "<p>Email to $email->email_address sent successfully.</p>";
                } else {
                    echo "<p>Emailing failed to $email->email_address.</p>";
                }
            }

            $email_count = $wpdb->get_row('SELECT COUNT(ID) as the_count FROM '.$wpdb->prefix.'csm_bulk_emails LIMIT 1');
            if ($email_count->the_count >= 1) {
                echo "<script type='text/javascript'>location.reload();</script>";
                echo "<p>If this page did not automatically refresh, please click <a href='admin.php?page=csm_bulk_user_management&amp;send_emails=".absint($_GET['send_emails'])."'>here</a>.</p>";
            } else {
                echo '<p>All welcome emails have been sent.</p>';
            }

            exit();
            return;
        }

        echo "<div class='wrap'>\n";
        echo "\t\t<h1 class='wp-heading-inline'>".__("Bulk User Management", "csm_membership")."</h1>\n\n";

        if ( $_SERVER["REQUEST_METHOD"] == "POST" ) {
            if ( isset( $_POST["action"] ) && $_POST["action"] == "Import Users" ) {

                if ( !isset( $_FILES["csv_file"] ) ) {
                    
                    echo "<div class='notice error is-dismissable'><p>You must upload a CSV file.</p></div>";

                } else {

                    $users = file( $_FILES["csv_file"]["tmp_name"] );

                    $skip_exsiting = false;
                    $send_welcome = false;

                    $new_count = 0;
                    $update_count = 0;

                    if ( isset( $_POST["has_headers"] ) && $_POST["has_headers"] == 1 ) {
                        unset( $users[0] );
                    }

                    if ( isset($_POST["skip_exsiting"]) && $_POST["skip_exsiting"] == 1 ) {
                        $skip_exsiting = true;
                    }

                    if ( isset($_POST["send_welcome"]) && $_POST["send_welcome"] == 1 ) {
                        $send_welcome = true;
                    }

                    $column_username = ( isset( $_POST["column_username"] ) && $_POST["column_username"] != 1 && $_POST["column_email"] != 0 ) ? intval( $_POST["column_username"] ) - 1 : 0;
                    $column_email = ( isset( $_POST["column_email"] ) && $_POST["column_email"] != 1 && $_POST["column_email"] != 0 ) ? intval( $_POST["column_email"] ) - 1 : 1;
                    $column_firstname = ( isset( $_POST["column_firstname"] ) && $_POST["column_firstname"] != 0 ) ? intval( $_POST["column_firstname"] ) - 1 : 0;
                    $column_lastname = ( isset( $_POST["column_lastname"] ) && $_POST["column_lastname"] != 0 ) ? intval( $_POST["column_lastname"] ) - 1 : 0;
                    $column_website = ( isset( $_POST["column_website"] ) && $_POST["column_website"] != 0 ) ? intval( $_POST["column_website"] ) - 1 : 0;
                    $column_plan = ( isset( $_POST["column_plan"] ) && $_POST["column_plan"] != 0 ) ? intval( $_POST["column_plan"] ) - 1 : 0;

                    $default_plan = ( isset( $_POST["role"] ) && get_role( $_POST["role"] ) != null ) ? $_POST["role"] : get_option( "default_role" );

                    $welcome_msg = get_option( "csm_users_welcome", $this->get_default_welcome_msg() );

                    $user_id = 0;
                    foreach ($users as $line) {
                        $user = explode( ",", $line );
                        $user = array_map( "trim", $user );

                        $existing_username = username_exists( $user[ $column_username ] );
                        $existing_email = email_exists( $user[ $column_email ] );

                        if ( $existing_username != false || $existing_email != false ) {
                            if ( $existing_username != false ) {
                                $user_id = $existing_username;
                            }

                            if ( $existing_email != false ) {
                                $user_id = $existing_email;
                            }

                            $cur_user = get_userdata( $user_id );

                            if ( $skip_existing == false ) {
                                // this user already exists, update existing

                                $user_data = array();
                                $user_data["ID"] = $user_id;

                                if ( $user[$column_email] != $cur_user->user_email ) {
                                    $update_user_required = true;
                                    $user_data["user_email"] = $user[ $column_email ];
                                }

                                if ( $column_firstname != 0 ) {
                                    if ( $cur_user->first_name != $user[ $column_firstname ] ) {
                                        $update_user_required = true;
                                        $user_data["first_name"] = $user[ $column_firstname ];
                                    }
                                }

                                if ( $column_lastname != 0 ) {
                                    if ( $cur_user->last_name != $user[ $column_lastname ] ) {
                                        $update_user_required = true;
                                        $user_data["last_name"] = $user[ $column_lastname ];
                                    }
                                }

                                if ( $column_website != 0 ) {
                                    if ( $cur_user->user_url != $user[ $column_website ] ) {
                                        $update_user_required = true;
                                        $user_data["user_url"] = $user[ $column_website ];
                                    }
                                }

                                $role = null;
                                if ( $column_plan != 0 ) {
                                    $role = get_role( strtolower( $user[ $column_plan ] ) );
                                }

                                if ( $role == null ) {
                                    if ( !in_array( $default_plan, $cur_user->roles ) ) {
                                        $update_user_required = true;
                                        $user_data["role"] = $default_plan;
                                    }
                                } else {
                                    if ( !in_array( strtolower( $user[ $column_plan ] ), $cur_user->roles ) ) {
                                        $update_user_required = true;
                                        $user_data["role"] = strtolower( $user[ $column_plan ] );
                                    }
                                }

                            }

                            if ( $update_user_required == true ) {
                                ++$update_count;
                                wp_update_user( $user_data );
                            }

                            unset( $user_data );
                            unset( $update_user_required );
                            unset( $user_id );
                            unset( $cur_user );

                        } else {

                            // new user, create account

                            // an email is the absolute minimum
                            if ( trim( $user[ $column_email ] ) != "" ) {

                                // determine a username if username is blank
                                if ( trim( $user[ $column_username ] ) == "" ) {

                                    // let's decide on a username from the data we have

                                    if ( $column_firstname != 0 && $user[ $column_firstname ] != "" ) {
                                        $user[ $column_username ] = $user[ $column_firstname ];
                                    }

                                    if ( $column_lastname != 0 && $user[ $column_lastname ] != "" ) {
                                        if ( $column_firstname != 0 && $user[ $column_firstname ] != "" ) {
                                            $user[ $column_username ] .= "_";
                                        }
                                        $user[ $column_username ] .= $user[ $column_lastname ];
                                    }

                                    // last resort, use the email address to determine the username
                                    if ( trim( $user[ $column_username ] ) == "" ) {

                                        $email_split = explode( "@", $user[ $column_username ] );

                                        $user[ $column_username ] = $email_split[0];

                                    }

                                    // if the generated username exists, add the current year
                                    if ( username_exists( $user[ $column_username ] ) ) {
                                        $user[$column_username] = $user[ $column_username ].date( "Y" );
                                    }
                                
                                }

                                $random_password = wp_generate_password( $length=12, $include_standard_special_chars=false );
                                $user_id = wp_create_user( $user[ $column_username ], $random_password, $user[ $column_email ] );

                                ++$new_count;

                                $user_data = array();
                                $user_data["ID"] = $user_id;

                                if ( $column_firstname != 0 ) {
                                    if ( $cur_user->first_name != $user[ $column_firstname ] ) {
                                        $user_data["first_name"] = $user[ $column_firstname ];
                                    }
                                }

                                if ( $column_lastname != 0 ) {
                                    if ( $cur_user->last_name != $user[ $column_lastname ] ) {
                                        $user_data["last_name"] = $user[ $column_lastname ];
                                    }
                                }

                                if ( $column_website != 0 ) {
                                    if ( $cur_user->user_url != $user[ $column_website ] ) {
                                        $user_data["user_url"] = $user[ $column_website ];
                                    }
                                }

                                if ( $column_plan != 0 ) {
                                    $role = get_role( strtolower( $user[ $column_plan ] ) );
                                } else {
                                    $role = null;
                                }

                                if ( $role == null ) {
                                    $user_data["role"] = $default_plan;
                                } else {
                                    $user_data["role"] = strtolower( $user[ $column_plan ] );
                                }

                                wp_update_user( $user_data );

                                if ( $send_welcome == true ) {
                                    $to = $user[ $column_email ];

                                    $welcome_email = str_replace( "(sitename)", get_bloginfo( "name" ), $welcome_msg );
                                    $welcome_email = str_replace( "(siteurl)", get_bloginfo( "url" ), $welcome_email );
                                    $welcome_email = str_replace( "(password)", $random_password, $welcome_email );
                                    $welcome_email = str_replace( "(username)", $user[ $column_username ], $welcome_email );
                                    $welcome_email = str_replace( "(email)", $user[ $column_email ], $welcome_email );
                                    $welcome_email = str_replace( "(firstname)", $user[ $column_firstname ], $welcome_email );
                                    $welcome_email = str_replace( "(lastname)", $user[ $column_lastname ], $welcome_email );
                                    $welcome_email = wpautop( $welcome_email, true);

                                    $wpdb->insert($wpdb->prefix.'csm_bulk_emails', array('user_id' => $user_id, 'email_address' => $user[ $column_email ], 'email_content' => $welcome_email), array('%d', '%s', '%s'));
                                }

                                unset( $user_id );
                                unset( $user_data );

                            }
                        }

                    }

                    if ( $send_welcome == true ) {

                        echo "<p>We are currently sending welcome emails to the users that were just imported. Please don't leave this page until the below indicates the emailing is complete. This may take a long time.</p>";
                        echo '<iframe src="admin.php?page='.esc_attr( $_GET['page'] ).'&amp;send_emails='.absint($_POST['max_send']).'" title="Sending emails" id="sending_emails" width="400" height="300">Frames are not supported in this browser. Please click <a href="" target="_blank">here</a> to send welcome emails.</iframe>';

                        return;

                    } else {

                        echo "<div class='notice notice-success is-dismissible'><p>The users have been imported successfully. ".$new_count." users were created and ".$update_count." users were updated.</p></div>";

                    }
                }

            }

            if ( isset( $_POST["action"] ) && $_POST["action"] == "Delete Users" ) {
                if ( isset( $_FILES["csv_file"] ) ) {

                    $column_username = ( isset( $_POST["column_username"] ) && $_POST["column_username"] != 1 && $_POST["column_email"] != 0) ? intval( $_POST["column_username"] ) - 1 : 0;
                    $column_email = ( isset( $_POST["column_email"] ) && $_POST["column_email"] != 1 && $_POST["column_email"] != 0) ? intval( $_POST["column_email"] ) - 1 : 1;
                    
                    $file = file( $_FILES["csv_file"]["tmp_name"] );

                    echo "<form method='post' action='admin.php?page=".esc_attr( $_GET["page"] )."'>";
                    echo "<div class='card'>";
                    echo "<h2 class='title'>Confirm User Deletion</h2>";

                    
                    if ( isset( $_POST["has_headers"] ) && $_POST["has_headers"] == 1 ) {
                        unset( $file[0] );
                    }

                    if ( count( $file ) >= 1 ) {

                        echo "<p>Are you sure you want to delete the following users? If you have changed your mind for any of these users, uncheck the box:</p>";
                        echo "<ul>";

                        foreach ( $file as $row ) {
                            $user = explode( ",", $row );

                            $cur_user = get_user_by( "email", $user[ $column_email ] );

                            if ( $cur_user != false ) {
                                echo "<li><label><input type='checkbox' name='user[]' value='".$cur_user->ID."' checked='checked'> ID #".$cur_user->ID.": ".$cur_user->user_login." &lt;".$cur_user->user_email."&gt;</label></li>";
                            }
                        }

                        echo "</ul>";

                        echo "<p><input type='submit' name='action' value='Confirm Delete Users' class='button button-primary'></p>";

                        echo "</div>";

                        echo "</form>";
    
                        return;

                    } else {

                        echo "<div class='notice notice-error is-dismissible'><p>The provided CSV has no valid users to delete.</p></div>";

                    }


                } else {

                    echo "<div class='notice notice-error is-dismissible'><p>You must upload a file containing users to delete.</p></div>";
                
                }
            }

            if ( isset( $_POST["action"] ) && $_POST["action"] == "Confirm Delete Users" ) {

                $delete_count = 0;
                
                if ( isset( $_POST["user"] ) && count( $_POST["user"] ) >= 1 ) {

                    foreach ( $_POST["user"] as $user_id ) {
                        $delete = wp_delete_user( $user_id );
                        if ( $delete == true ) {
                            ++$delete_count;
                        }
                    }

                    echo "<div class='notice notice-success is-dismissible'><p>".$delete_count." users have been deleted.</p></div>";

                } else {

                    echo "<div class='notice notice-error is-dismissible'><p>No users were specified to delete.</p></div>";

                }

            }

        }

        echo "\t\t<div class='card'>\n";
        echo "\t\t\t<form method='post' action='admin.php?page=".esc_attr( $_GET["page"] )."' enctype='multipart/form-data'>\n";
        echo "\t\t\t\t<h2 class='title'>".__("Import Users")."</h2>\n";
        echo "\t\t\t\t<p><label for='csv_file'>Please select the <abbr title='Comma Separated Value'>CSV</abbr> file containing your new users using the file picker below. Existing users will be updated unless disabled below in which case they will be skipped.</label></p>\n";
        echo "\t\t\t\t<p><input type='file' name='csv_file' id='csv_file' accept='.csv, .txt, text/csv, text/plain' required='required' /></p>\n";
        echo "\t\t\t\t<p><label><input type='number' size='1' value='".( isset( $_POST["column_username"]) ? intval( $_POST["column_username"]) : 1 )."' min='1' name='column_username' required='required' style='width: 60px;'> Column number containing Username</label></p>\n";
        echo "\t\t\t\t<p><label><input type='number' size='1' value='".( isset( $_POST["column_email"]) ? intval( $_POST["column_email"]) : 2 )."' min='1' name='column_email' required='required' style='width: 60px;'> Column number containing Email address</label></p>\n";
        echo "\t\t\t\t<p><label><input type='checkbox' name='has_headers' value='1' ".checked( 1, ( isset( $_POST["has_headers"] ) ? $_POST["has_headers"] : 1 ), false )." /> My CSV file has headers (skips the first row)</label></p>\n";
        echo "\t\t\t\t<p>The following fields are optional. Leave blank or 0 fields that your CSV does not contain.</p>\n";
        echo "\t\t\t\t<p><label><input type='checkbox' name='skip_existing' value='1' ".checked( 1, ( isset( $_POST["skip_existing"] ) ? $_POST["skip_existing"] : 0 ), false )." /> Disable existing user update (skips existing users)</label></p>\n";
        echo "\t\t\t\t<p><label><input type='checkbox' name='send_welcome' value='1' ".checked( 1, ( isset( $_POST["send_welcome"] ) ? $_POST["send_welcome"] : 0 ), false )." /> Send WordPress new user welcome email with password</label></p>\n";
        echo "\t\t\t\t<p><label><input type='number' size='1' min='0' name='column_firstname' value='".( isset($_POST["column_firstname"] ) ? intval( $_POST["column_firstname"] ) : 0 )."' style='width: 60px;'> Column number containing First Name</label></p>\n";
        echo "\t\t\t\t<p><label><input type='number' size='1' min='0' name='column_lastname' value='".( isset($_POST["column_lastname"] ) ? intval( $_POST["column_lastname"] ) : 0 )."' style='width: 60px;'> Column number containing Last Name</label></p>\n";
        echo "\t\t\t\t<p><label><input type='number' size='1' min='0' name='column_website' value='".( isset($_POST["column_website"] ) ? intval( $_POST["column_website"] ) : 0 )."' style='width: 60px;'> Column number containing Website</label></p>\n";
        echo "\t\t\t\t<p><label><input type='number' size='1' min='0' name='column_plan' value='".( isset($_POST["column_plan"]) ? intval( $_POST["column_plan"] ) : 0 )."' style='width: 60px;'> Column number containing Role</label></p>\n";
        echo "\t\t\t\t<p><label><select name='role'>";
        wp_dropdown_roles( ( isset($_POST["role"]) ? $_POST["role"] : get_option( "default_role" ) ) );
        echo "</select> If plan not specified by above field or invalid, assign this role.</label></p>\n";
        echo "\t\t\t\t<p><label><input type='number' size='1' value='25' min='0' name='max_send' value='".( isset($_POST["max_send"]) ? intval( $_POST["max_send"] ) : 20 )."' style='width: 60px;'> Maximum number of welcome emails to send at once</label></p>\n";
        echo "\t\t\t\t<p><input type='submit' name='action' value='Import Users' class='button button-primary' /></p>\n";
        echo "\t\t\t</form>\n";
        echo "\t\t</div>\n";
        echo "\t\t<form method='post' action='admin.php?page=".esc_attr( $_GET["page"] )."'>\n";
        echo "\t\t\t<div class='card'>\n";
        echo "\t\t\t<h2 class='title'>Export Users</h2>\n";
        echo "\t\t\t<p><label><select name='role'><option value='-1'>All Users</option>";
        wp_dropdown_roles();
        echo "</select></label></p>\n";
        echo "\t\t\t<p><input type='submit' name='action' value='Download Users (CSV)' class='button button-primary' /></p>\n";
        echo "\t\t\t</div>\n";
        echo "\t\t</form>\n";
        echo "\t\t<div class='card'>\n";
        echo "\t\t\t<form method='post' action='admin.php?page=".esc_attr( $_GET["page"] )."' enctype='multipart/form-data'>\n";
        echo "\t\t\t\t<h2 class='title'>Delete Users</h2>\n";
        echo "\t\t\t\t<p><label for='csv_file'>Please select the <abbr title='Comma Separated Value'>CSV</abbr> file containing the users to delete using the file picker below.</label></p>\n";
        echo "\t\t\t\t<p><input type='file' name='csv_file' id='csv_file' accept='.csv, .txt, text/csv, text/plain' required='required' /></p>\n";
        echo "\t\t\t\t<p><label><input type='number' size='1' value='".( isset( $_POST["column_username"] ) ? intval( $_POST["column_username"] ) : 1 )."' min='1' name='column_username' required='required' style='width: 60px;'> Column number containing Username</label></p>\n";
        echo "\t\t\t\t<p><label><input type='number' size='1' value='".( isset( $_POST["column_email"] ) ? intval( $_POST["column_email"] ) : 2 )."' min='1' name='column_email' required='required' style='width: 60px;'> Column number containing Email address</label></p>\n";
        echo "\t\t\t\t<p><label><input type='checkbox' name='has_headers' value='1' ".checked( 1, ( isset( $_POST["has_headers"] ) ? $_POST["has_headers"] : 1 ), false )." /> My CSV file has headers (skips the first row)</label></p>\n";
        echo "\t\t\t\t<p><input type='submit' name='action' value='Delete Users' class='button button-primary' /></p>\n";
        echo "\t\t\t</form>\n";
        echo "\t\t</div>\n";
        echo "\t</div>\n\n";

    }

    function send_csv_download() {
        if ( isset( $_POST["action"] ) && $_POST["action"] == "Download Users (CSV)" ) {

            $args = array();
            $args["fields"] = "all_with_meta";

            if ( isset( $_POST["role"] ) && $_POST["role"] != -1 ) {
                $args["role__in"] = array( $_POST["role"] );
            }

            $users = get_users( $args );

            $fields = array();
            $fields["user_name"] = "Username";
            $fields["user_email"] = "Email Address";
            $fields["first_name"] = "First Name";
            $fields["last_name"] = "Last Name";
            $fields["display_name"] = "Display Name";
            $fields["user_url"] = "Website";
            $fields["role"] = "Role";
            $fields = apply_filters( "csm_user_export_fields", $fields );

            foreach ($users as $user) {
                $rows[ $user->ID ]["user_name"] = $user->user_login;
                $rows[ $user->ID ]["user_email"] = $user->user_email;
                $rows[ $user->ID ]["first_name"] = $user->first_name;
                $rows[ $user->ID ]["last_name"] = $user->last_name;
                $rows[ $user->ID ]["display_name"] = $user->user_nicename;
                $rows[ $user->ID ]["user_url"] = $user->user_url;
                $rows[ $user->ID ]["role"] = implode( "|", $user->roles );
                $rows[ $user->ID ] = apply_filters( "csm_user_export_data", $rows[ $user->ID ], $user->ID );
            }

            $file = implode( ",", $fields )."\n";
            foreach ( $rows as $row ) {
                $file .= implode( ",", $row )."\n";
            }

            ob_start();
            header( 'Content-Type: text/csv' );
        
            //Use Content-Disposition: attachment to specify the filename
            header( 'Content-Disposition: attachment; filename=users.csv' );
        
            //No cache
            header( 'Expires: 0' );
            header( 'Cache-Control: must-revalidate' );
            header( 'Pragma: public' );

            ob_clean();
            flush();

            echo $file;

            exit();
        }
    }

    function db_install () {
        global $wpdb;
     
        $table_name = $wpdb->prefix . "csm_bulk_email"; 

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
        ID int(11) NOT NULL AUTO_INCREMENT,
        user_id int(11) NOT NULL,
        email_address varchar(254) NOT NULL,
        email_content text NOT NULL,
        PRIMARY KEY  (ID)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        add_option( "csm_bulk_db_version", "0.1" );
     }

}

$csm_bulk = new CSM_Bulk_User_Management;