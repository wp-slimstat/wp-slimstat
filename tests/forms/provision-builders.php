<?php
/** Real builder fixtures, confined to the disposable compatibility site. @license GPL-2.0-or-later */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! preg_match( '/^test_form_analytics_/', DB_NAME ) ) {
	throw new RuntimeException( 'Use a disposable Form Analytics site.' );
}
wp_set_current_user( 1 );
$fixtures = get_option( 'slimstat_forms_qa_builders', array() );
$publish = static function ( $key, $id, $content ) use ( &$fixtures ) {
	if ( is_wp_error( $id ) || ! $id ) { throw new RuntimeException( 'Could not provision ' . $key ); }
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'QA ' . $key, 'post_content' => $content ), true );
	if ( is_wp_error( $page ) ) { throw new RuntimeException( 'Could not publish ' . $key ); }
	$fixtures[ $key ] = array( 'form' => (int) $id, 'page' => $page );
	update_option( 'slimstat_forms_qa_builders', $fixtures, false );
};
if ( ! isset( $fixtures['wpforms'] ) ) {
	$data = array(
		'fields' => array(
			1 => array( 'id' => '1', 'type' => 'text', 'label' => 'Name', 'required' => '1', 'size' => 'medium' ),
			2 => array( 'id' => '2', 'type' => 'email', 'label' => 'Email', 'required' => '1', 'size' => 'medium' ),
			3 => array( 'id' => '3', 'type' => 'textarea', 'label' => 'Message', 'size' => 'medium' ),
		),
		'field_id' => 4,
		'settings' => array( 'form_title' => 'QA WPForms', 'submit_text' => 'Send inquiry', 'ajax_submit' => '1', 'antispam_v3' => '1', 'notification_enable' => '0', 'confirmations' => array( 1 => array( 'type' => 'message', 'message' => 'QA submission accepted.', 'message_scroll' => '1' ) ) ),
	);
	$id = wpforms()->obj( 'form' )->add( 'QA WPForms', array( 'post_content' => wpforms_encode( $data ) ) );
	$data['id'] = $id;
	wpforms()->obj( 'form' )->update( $id, $data );
	$publish( 'wpforms', $id, '[wpforms id="' . $id . '"]' );
}
if ( ! isset( $fixtures['fluent'] ) ) {
	$form = ( new \FluentForm\App\Services\Form\FormService() )->store( array( 'predefined' => 'basic_contact_form' ) );
	$publish( 'fluent', $form->id, '[fluentform id="' . $form->id . '"]' );
}
if ( ! isset( $fixtures['forminator'] ) ) {
	$template = new Forminator_Template_Contact_Form();
	$id = Forminator_API::add_form( 'QA Forminator', $template->fields(), $template->settings() );
	$publish( 'forminator', $id, '[forminator_form id="' . $id . '"]' );
}
if ( ! isset( $fixtures['ninja'] ) ) {
	$data = json_decode( file_get_contents( WP_PLUGIN_DIR . '/ninja-forms/includes/Templates/formtemplate-contactform.nff' ), true );
	$data['settings']['title'] = 'QA Ninja Forms';
	$id = NF_Database_Models_Form::import( $data );
	$publish( 'ninja', $id, '[ninja_form id="' . $id . '"]' );
}
if ( ! isset( $fixtures['formidable'] ) ) {
	// Its normal installer runs through a loopback request, disabled in this offline QA site.
	FrmAppController::api_install();
	$id = FrmForm::create( array( 'form_key' => 'qa-project', 'name' => 'QA Formidable', 'description' => '', 'options' => array( 'success_msg' => 'QA submission accepted.', 'ajax_submit' => 1 ) ) );
	foreach ( array( 'text' => 'Name', 'email' => 'Email', 'textarea' => 'Message' ) as $type => $label ) {
		FrmField::create( array( 'form_id' => $id, 'field_key' => 'qa-' . $type, 'type' => $type, 'name' => $label, 'required' => 'textarea' !== $type, 'field_order' => 'text' === $type ? 1 : ( 'email' === $type ? 2 : 3 ) ) );
	}
	$publish( 'formidable', $id, '[formidable id="' . $id . '"]' );
}
if ( ! isset( $fixtures['snow'] ) ) {
	$content = '<!-- wp:snow-monkey-forms/form--input {} --><div class="smf-form">';
	foreach ( array( 'text' => 'Name', 'email' => 'Email' ) as $type => $label ) {
		$content .= '<label>' . $label . '<!-- wp:snow-monkey-forms/control-' . $type . ' ' . wp_json_encode( array( 'name' => strtolower( $label ), 'validations' => '{"required":true}' ) ) . ' /--></label>';
	}
	$content .= '</div><!-- /wp:snow-monkey-forms/form--input --><!-- wp:snow-monkey-forms/form--complete --><div class="smf-complete-content"><p>QA submission accepted.</p></div><!-- /wp:snow-monkey-forms/form--complete -->';
	$id = wp_insert_post( array( 'post_type' => 'snow-monkey-forms', 'post_status' => 'publish', 'post_title' => 'QA Snow Monkey', 'post_content' => wp_slash( $content ) ) );
	foreach ( array( 'administrator_email_to' => 'qa@example.test', 'administrator_email_subject' => 'QA inquiry', 'administrator_email_body' => 'QA inquiry', 'use_confirm_page' => '1' ) as $key => $value ) { update_post_meta( $id, $key, $value ); }
	$publish( 'snow', $id, '<!-- wp:snow-monkey-forms/snow-monkey-form {"formId":' . $id . '} /-->' );
}
if ( ! isset( $fixtures['mw'] ) ) {
	$id = wp_insert_post( array( 'post_type' => 'mw-wp-form', 'post_status' => 'publish', 'post_title' => 'QA MW WP Form', 'post_content' => '<label>Name [mwform_text name="qa_name"]</label><label>Email [mwform_email name="qa_email"]</label>[mwform_backButton value="Back"][mwform_submitButton name="send" confirm_value="Review inquiry" submit_value="Send inquiry"]' ) );
	$setting = new MW_WP_Form_Setting( $id );
	$setting->sets( array( 'mail_to' => 'qa@example.test', 'admin_mail_subject' => 'QA inquiry', 'admin_mail_content' => 'QA inquiry', 'complete_message' => 'QA submission accepted.', 'validation' => array( array( 'target' => 'qa_name', 'noempty' => true ), array( 'target' => 'qa_email', 'noempty' => true, 'mail' => true ) ) ) );
	$setting->save();
	$publish( 'mw', $id, '[mwform_formkey key="' . $id . '"]' );
}
echo wp_json_encode( $fixtures );
