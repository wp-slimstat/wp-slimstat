<?php
/** Provision public builder fixtures only in a disposable database. @license GPL-2.0-or-later */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! preg_match( '/^test_form_analytics_/', DB_NAME ) ) { throw new RuntimeException( 'Use a disposable Form Analytics site.' ); }
wp_set_current_user( 1 );
if ( ! \SlimStat\Forms\Store::setup() ) { throw new RuntimeException( 'Form Analytics schema setup failed.' ); }
$form = WPCF7_ContactForm::get_template();
$form->set_title( 'Ask about a project' );
$form->set_properties( array( 'form' => '<label>Your name [text* your-name autocomplete:name]</label><label>Your email [email* your-email autocomplete:email]</label><label>Your message [textarea your-message]</label>[submit "Send inquiry"]' ) );
$id = $form->save();
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Project inquiry', 'post_content' => '[contact-form-7 id="' . $id . '"]' ) );
$plain = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'No form', 'post_content' => 'A page without a form.' ) );
echo wp_json_encode( array( 'form' => $id, 'page' => $page, 'plain' => $plain ) );
