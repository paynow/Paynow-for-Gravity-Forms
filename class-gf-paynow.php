<?php
add_action('wp', array('GFPayNow', 'maybe_thankyou_page'), 5);

//Define Constants
define('ps_error', 'error');
define('ps_ok', 'ok');
define('ps_created_but_not_paid', 'created but not paid');
define('ps_cancelled', 'cancelled');
define('ps_failed', 'failed');
define('ps_paid', 'paid');
define('ps_awaiting_delivery', 'awaiting delivery');
define('ps_delivered', 'delivered');
define('ps_awaiting_redirect', 'awaiting redirect');

GFForms::include_payment_addon_framework();

class GFPayNow extends GFPaymentAddOn {

    protected $_version = GF_PAYNOW_VERSION;
    protected $_min_gravityforms_version = '1.9.3';
    protected $_slug = 'gravityformspaynow';
    protected $_path = 'gravityformspaynow/paynow.php';
    protected $_full_path = __FILE__;
    protected $_url = 'http://www.gravityforms.com';
    protected $_title = 'Gravity Forms PayNow Standard Add-On';
    protected $_short_title = 'Paynow';
    protected $_supports_callbacks = true;
    private $initiate_transaction_url = 'https://www.paynow.co.zw/Interface/InitiateTransaction';
    // Members plugin integration
    protected $_capabilities = array('gravityforms_paynow', 'gravityforms_paynow_uninstall');
    // Permissions
    protected $_capabilities_settings_page = 'gravityforms_paynow';
    protected $_capabilities_form_settings = 'gravityforms_paynow';
    protected $_capabilities_uninstall = 'gravityforms_paynow_uninstall';
    // Automatic upgrade enabled
    protected $_enable_rg_autoupgrade = true;
    private static $_instance = null;

    public static function get_instance() {
        if (self::$_instance == null) {
            self::$_instance = new GFPayNow();
        }

        return self::$_instance;
    }

    private function __clone() {
        
    }

    /* do nothing */

    public function init_frontend() {
        parent::init_frontend();

        add_filter('gform_disable_post_creation', array($this, 'delay_post'), 10, 3);
        add_filter('gform_disable_notification', array($this, 'delay_notification'), 10, 4);
    }

    //----- SETTINGS PAGES ----------//

    public function plugin_settings_fields() {
        $description = '
			<ul>
				<li>' . sprintf(esc_html__('Paynow works by sending the user to %sPaynow%s  to enter their payment information.', 'gravityformspaynow'), '<a href="https://www.paynow.co.zw/" target="_blank">', '</a>') . '</li>' .
                '</ul>
				<br/>';

        return array(
            array(
                'title' => '',
                'description' => $description,
                'fields' => array(
                     array(
                        'label' => esc_html__('Cancelled URL', 'gravityformspaynow'),
                        'type' => 'text',
                        'name' => 'cancelled_url',
                        'tooltip' => esc_html__('Cancelled URL', 'gravityformspaynow'),
                        'class' => 'medium',
                        'feedback_callback' => array($this, 'is_valid_setting'),
                    ),
                    array(
                        'label' => esc_html__('Paid URL', 'gravityformspaynow'),
                        'type' => 'text',
                        'name' => 'paid_url',
                        'tooltip' => esc_html__('Paid URL', 'gravityformspaynow'),
                        'class' => 'medium',
                        'feedback_callback' => array($this, 'is_valid_setting'),
                    ),
                    array(
                        'label' => esc_html__('Merchant ID', 'gravityformspaynow'),
                        'type' => 'text',
                        'name' => 'merchant_id',
                        'tooltip' => esc_html__('This is the merchant ID, received from Paynow.', 'gravityformspaynow'),
                        'class' => 'small',
                        'feedback_callback' => array($this, 'is_valid_setting'),
                    ),
                    array(
                        'label' => esc_html__('Merchant Key', 'gravityformspaynow'),
                        'type' => 'text',
                        'name' => 'merchant_key',
                        'tooltip' => esc_html__('This is the merchant key, received from Paynow.', 'gravityformspaynow'),
                        'class' => 'medium',
                        'feedback_callback' => array($this, 'is_valid_setting'),
                    ),
                    array(
                        'type' => 'save',
                        'messages' => array(
                            'success' => esc_html__('Settings have been updated.', 'gravityformspaynow')
                        ),
                    ),
                ),
            ),
        );
    }

    public function feed_list_no_item_message() {
        $settings = $this->get_plugin_settings();
        if (!rgar($settings, 'gf_paynow_enabled')) {
            return sprintf(esc_html__('To get started, let\'s go configure your %sPayNow Settings%s!', 'gravityformspaynow'), '<a href="' . admin_url('admin.php?page=gf_settings&subview=' . $this->_slug) . '">', '</a>');
        } else {
            return parent::feed_list_no_item_message();
        }
    }

    public function feed_settings_fields() {
        $default_settings = parent::feed_settings_fields();
        
        return $default_settings;
    }
    
    public function billing_info_fields() {

		$fields = array(
			array( 'name' => 'email', 'label' => esc_html__( 'Email', 'gravityforms' ), 'required' => true )
		);

		return $fields;
	}

    public function supported_billing_intervals() {

        $billing_cycles = array(
            'day' => array('label' => esc_html__('day(s)', 'gravityformspaynow'), 'min' => 1, 'max' => 90),
            'week' => array('label' => esc_html__('week(s)', 'gravityformspaynow'), 'min' => 1, 'max' => 52),
            'month' => array('label' => esc_html__('month(s)', 'gravityformspaynow'), 'min' => 1, 'max' => 24),
            'year' => array('label' => esc_html__('year(s)', 'gravityformspaynow'), 'min' => 1, 'max' => 5)
        );

        return $billing_cycles;
    }

    public function field_map_title() {
        return esc_html__('PayNow Field', 'gravityformspaynow');
    }

    public function settings_trial_period($field, $echo = true) {
        //use the parent billing cycle function to make the drop down for the number and type
        $html = parent::settings_billing_cycle($field);

        return $html;
    }

    public function set_trial_onchange($field) {
        //return the javascript for the onchange event
        return "
		if(jQuery(this).prop('checked')){
			jQuery('#{$field['name']}_product').show('slow');
			jQuery('#gaddon-setting-row-trialPeriod').show('slow');
			if (jQuery('#{$field['name']}_product').val() == 'enter_amount'){
				jQuery('#{$field['name']}_amount').show('slow');
			}
			else{
				jQuery('#{$field['name']}_amount').hide();
			}
		}
		else {
			jQuery('#{$field['name']}_product').hide('slow');
			jQuery('#{$field['name']}_amount').hide();
			jQuery('#gaddon-setting-row-trialPeriod').hide('slow');
		}";
    }

    public function settings_options($field, $echo = true) {
        $html = $this->settings_checkbox($field, false);

        //--------------------------------------------------------
        //For backwards compatibility.
        ob_start();
        do_action('gform_paynow_action_fields', $this->get_current_feed(), $this->get_current_form());
        $html .= ob_get_clean();
        //--------------------------------------------------------

        if ($echo) {
            echo $html;
        }

        return $html;
    }

    public function settings_custom($field, $echo = true) {

        ob_start();
        ?>
        <div id='gf_paynow_custom_settings'>
            <?php
            do_action('gform_paynow_add_option_group', $this->get_current_feed(), $this->get_current_form());
            ?>
        </div>

        <script type='text/javascript'>
            jQuery(document).ready(function () {
                jQuery('#gf_paynow_custom_settings label.left_header').css('margin-left', '-200px');
            });
        </script>

        <?php
        $html = ob_get_clean();

        if ($echo) {
            echo $html;
        }

        return $html;
    }

    public function settings_notifications($field, $echo = true) {
        $checkboxes = array(
            'name' => 'delay_notification',
            'type' => 'checkboxes',
            'onclick' => 'ToggleNotifications();',
            'choices' => array(
                array(
                    'label' => esc_html__("Send notifications for the 'Form is submitted' event only when payment is received.", 'gravityformspaynow'),
                    'name' => 'delayNotification',
                ),
            )
        );

        $html = $this->settings_checkbox($checkboxes, false);

        $html .= $this->settings_hidden(array('name' => 'selectedNotifications', 'id' => 'selectedNotifications'), false);

        $form = $this->get_current_form();
        $has_delayed_notifications = $this->get_setting('delayNotification');
        ob_start();
        ?>
        <ul id="gf_paynow_notification_container" style="padding-left:20px; margin-top:10px; <?php echo $has_delayed_notifications ? '' : 'display:none;' ?>">
            <?php
            if (!empty($form) && is_array($form['notifications'])) {
                $selected_notifications = $this->get_setting('selectedNotifications');
                if (!is_array($selected_notifications)) {
                    $selected_notifications = array();
                }

                //$selected_notifications = empty($selected_notifications) ? array() : json_decode($selected_notifications);

                $notifications = GFCommon::get_notifications('form_submission', $form);

                foreach ($notifications as $notification) {
                    ?>
                    <li class="gf_paynow_notification">
                        <input type="checkbox" class="notification_checkbox" value="<?php echo $notification['id'] ?>" onclick="SaveNotifications();" <?php checked(true, in_array($notification['id'], $selected_notifications)) ?> />
                        <label class="inline" for="gf_paynow_selected_notifications"><?php echo $notification['name']; ?></label>
                    </li>
                    <?php
                }
            }
            ?>
        </ul>
        <script type='text/javascript'>
            function SaveNotifications() {
                var notifications = [];
                jQuery('.notification_checkbox').each(function () {
                    if (jQuery(this).is(':checked')) {
                        notifications.push(jQuery(this).val());
                    }
                });
                jQuery('#selectedNotifications').val(jQuery.toJSON(notifications));
            }

            function ToggleNotifications() {

                var container = jQuery('#gf_paynow_notification_container');
                var isChecked = jQuery('#delaynotification').is(':checked');

                if (isChecked) {
                    container.slideDown();
                    jQuery('.gf_paynow_notification input').prop('checked', true);
                } else {
                    container.slideUp();
                    jQuery('.gf_paynow_notification input').prop('checked', false);
                }

                SaveNotifications();
            }
        </script>
        <?php
        $html .= ob_get_clean();

        if ($echo) {
            echo $html;
        }

        return $html;
    }

    public function checkbox_input_change_post_status($choice, $attributes, $value, $tooltip) {
        $markup = $this->checkbox_input($choice, $attributes, $value, $tooltip);

        $dropdown_field = array(
            'name' => 'update_post_action',
            'choices' => array(
                array('label' => ''),
                array('label' => esc_html__('Mark Post as Draft', 'gravityformspaynow'), 'value' => 'draft'),
                array('label' => esc_html__('Delete Post', 'gravityformspaynow'), 'value' => 'delete'),
            ),
            'onChange' => "var checked = jQuery(this).val() ? 'checked' : false; jQuery('#change_post_status').attr('checked', checked);",
        );
        $markup .= '&nbsp;&nbsp;' . $this->settings_select($dropdown_field, false);

        return $markup;
    }

    /**
     * Prevent the GFPaymentAddOn version of the options field being added to the feed settings.
     * 
     * @return bool
     */
    public function option_choices() {

        return false;
    }

    public function save_feed_settings($feed_id, $form_id, $settings) {

        //--------------------------------------------------------
        //For backwards compatibility
        $feed = $this->get_feed($feed_id);

        //Saving new fields into old field names to maintain backwards compatibility for delayed payments
        $settings['type'] = $settings['transactionType'];

        if (isset($settings['recurringAmount'])) {
            $settings['recurring_amount_field'] = $settings['recurringAmount'];
        }

        $feed['meta'] = $settings;
        $feed = apply_filters('gform_paynow_save_config', $feed);

        //call hook to validate custom settings/meta added using gform_paynow_action_fields or gform_paynow_add_option_group action hooks
        $is_validation_error = apply_filters('gform_paynow_config_validation', false, $feed);
        if ($is_validation_error) {
            //fail save
            return false;
        }

        $settings = $feed['meta'];

        //--------------------------------------------------------

        return parent::save_feed_settings($feed_id, $form_id, $settings);
    }

    //------ SENDING TO PAYNOW -----------//

    public function redirect_url($feed, $submission_data, $form, $entry) {
 
        
        //Don't process redirect url if request is a PayNow return
        if (!rgempty('gf_paynow_return', $_GET)) {
            return false;
        }

        //updating lead's payment_status to Processing
        GFAPI::update_entry_property($entry['id'], 'payment_status', 'Pending');

        // Check the request method is POST
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            return;
        }



        //get current order
        $order_id = $entry['id']; //this is the order id
        // Check payment
        if (!$order_id) {
            header("Location: $checkout_url");
            exit;
        } else {

            // Only send to Paynow if the pending payment is created successfully
            $listener_url = get_bloginfo('url') . '/?page=gf_paynow_ipn&action=return&order_id=' . $order_id;

            // Get the return url
            $return_url = $this->return_url = $this->return_url( $form['id'], $entry['id'] );

            // Setup Paynow arguments
            $MerchantId = $this->get_plugin_setting('merchant_id');
            $MerchantKey = $this->get_plugin_setting('merchant_key');
            $ConfirmUrl = $listener_url;
            $ReturnUrl = $return_url;
            $Reference = "Order Number: " . $order_id;
            $Amount = rgar($submission_data, 'payment_amount');

            $AdditionalInfo = "";
            $Status = "Message";
            $custEmail = rgar($submission_data, 'email');
            //var_dump($custEmail);exit;
            //set POST variables
            $values = array('resulturl' => $ConfirmUrl,
                'returnurl' => $ReturnUrl,
                'reference' => $Reference,
                'amount' => $Amount,
                'id' => $MerchantId,
                'additionalinfo' => $AdditionalInfo,
                'authemail' => $custEmail,
                'status' => $Status);

            $fields_string = $this->CreateMsg($values, $MerchantKey);

            //open connection
            $ch = curl_init();

            $url = $this->initiate_transaction_url;

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            //execute post
            $result = curl_exec($ch);

            if ($result) {
                //close connection
                $msg = $this->ParseMsg($result);

                //first check status, take appropriate action
                if (strtolower($msg["status"]) == strtolower(ps_error)) {
                    header("Location: $checkout_url");
                    exit;
                } else if (strtolower($msg["status"]) == strtolower(ps_ok)) {

                    //second, check hash
                    $validateHash = $this->CreateHash($msg, $MerchantKey);
                    if ($validateHash != $msg["hash"]) {
                        $error = "Paynow reply hashes do not match : " . $validateHash . " - " . $msg["hash"];
                    } else {

                        $theProcessUrl = $msg["browserurl"];



                        //update order data
                        $payment_meta = get_post_meta($order_id, '_wc_paynow_payment_meta', true);
                        $payment_meta['BrowserUrl'] = $msg["browserurl"];
                        $payment_meta['PollUrl'] = $msg["pollurl"];
                        $payment_meta['PaynowReference'] = $msg["paynowreference"];
                        $payment_meta['Amount'] = $msg["amount"];
                        $payment_meta['Status'] = "Sent to Paynow";
                        update_post_meta($order_id, '_wc_paynow_payment_meta', $payment_meta);
                    }
                } else {
                    //unknown status
                    $error = "Invalid status in from Paynow, cannot continue.";
                }
            }


            curl_close($ch);
        }
        return $theProcessUrl;
    }

    protected function payment_details_editing_disabled()
    {
        return true;
    }

    public function check_ipn_request()
    {
        return null;
    }

    public function get_customer_fields() {
        return array(
            array('name' => 'first_name', 'label' => 'First Name', 'meta_name' => 'billingInformation_firstName'),
            array('name' => 'last_name', 'label' => 'Last Name', 'meta_name' => 'billingInformation_lastName'),
            array('name' => 'email', 'label' => 'Email', 'meta_name' => 'billingInformation_email'),
            array('name' => 'address1', 'label' => 'Address', 'meta_name' => 'billingInformation_address'),
            array('name' => 'address2', 'label' => 'Address 2', 'meta_name' => 'billingInformation_address2'),
            array('name' => 'city', 'label' => 'City', 'meta_name' => 'billingInformation_city'),
            array('name' => 'state', 'label' => 'State', 'meta_name' => 'billingInformation_state'),
            array('name' => 'zip', 'label' => 'Zip', 'meta_name' => 'billingInformation_zip'),
            array('name' => 'country', 'label' => 'Country', 'meta_name' => 'billingInformation_country'),
        );
    }

    public function convert_interval($interval, $to_type) {
        //convert single character into long text for new feed settings or convert long text into single character for sending to paynow
        //$to_type: text (change character to long text), OR char (change long text to character)
        if (empty($interval)) {
            return '';
        }

        $new_interval = '';
        if ($to_type == 'text') {
            //convert single char to text
            switch (strtoupper($interval)) {
                case 'D' :
                    $new_interval = 'day';
                    break;
                case 'W' :
                    $new_interval = 'week';
                    break;
                case 'M' :
                    $new_interval = 'month';
                    break;
                case 'Y' :
                    $new_interval = 'year';
                    break;
                default :
                    $new_interval = $interval;
                    break;
            }
        } else {
            //convert text to single char
            switch (strtolower($interval)) {
                case 'day' :
                    $new_interval = 'D';
                    break;
                case 'week' :
                    $new_interval = 'W';
                    break;
                case 'month' :
                    $new_interval = 'M';
                    break;
                case 'year' :
                    $new_interval = 'Y';
                    break;
                default :
                    $new_interval = $interval;
                    break;
            }
        }

        return $new_interval;
    }

    public function delay_post($is_disabled, $form, $entry) {

        $feed = $this->get_payment_feed($entry);
        $submission_data = $this->get_submission_data($feed, $form, $entry);

        if (!$feed || empty($submission_data['payment_amount'])) {
            return $is_disabled;
        }

        return !rgempty('delayPost', $feed['meta']);
    }

    public function delay_notification($is_disabled, $notification, $form, $entry) {
        if (rgar($notification, 'event') != 'form_submission') {
            return $is_disabled;
        }

        $feed = $this->get_payment_feed($entry);
        $submission_data = $this->get_submission_data($feed, $form, $entry);

        if (!$feed || empty($submission_data['payment_amount'])) {
            return $is_disabled;
        }

        $selected_notifications = is_array(rgar($feed['meta'], 'selectedNotifications')) ? rgar($feed['meta'], 'selectedNotifications') : array();

        return isset($feed['meta']['delayNotification']) && in_array($notification['id'], $selected_notifications) ? true : $is_disabled;
    }

    //------- PROCESSING PAYNOW IPN (Callback) -----------//

    public function callback() {
        
        if (rgget('action') == 'return') {
              
              return $this->gf_paynow_process_paynow_notify();
               
             //return $this->maybe_thankyou_page();
        } else if (rgget('action') != 'notify') {
            $this->gf_paynow_process_paynow_notify();
            exit;
        }
    }

    public static function maybe_thankyou_page() {
		$instance = self::get_instance();

		if ( ! $instance->is_gravityforms_supported() ) {
			return;
		}

		if ( $str = rgget( 'gf_paynow_return' ) ) {
			$str = base64_decode( $str );

			parse_str( $str, $query );
			if ( wp_hash( 'ids=' . $query['ids'] ) == $query['hash'] ) {
				list( $form_id, $lead_id ) = explode( '|', $query['ids'] );

				$form = GFAPI::get_form( $form_id );
				$lead = GFAPI::get_entry( $lead_id );

				if ( ! class_exists( 'GFFormDisplay' ) ) {
					require_once( GFCommon::get_base_path() . '/form_display.php' );
				}

				$confirmation = GFFormDisplay::handle_confirmation( $form, $lead, false );

				if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
					header( "Location: {$confirmation['redirect']}" );
					exit;
				}

				GFFormDisplay::$submission[ $form_id ] = array( 'is_confirmation' => true, 'confirmation_message' => $confirmation, 'form' => $form, 'lead' => $lead );
                                GFPayNow::paynow_return($lead_id);
			}
		}
	}

    public function get_payment_feed($entry, $form = false) {

        $feed = parent::get_payment_feed($entry, $form);

        if (empty($feed) && !empty($entry['id'])) {
            //looking for feed created by legacy versions
            $feed = $this->get_paynow_feed_by_entry($entry['id']);
        }

        $feed = apply_filters('gform_paynow_get_payment_feed', $feed, $entry, $form ? $form : GFAPI::get_form($entry['form_id']) );

        return $feed;
    }

    private function get_paynow_feed_by_entry($entry_id) {

        $feed_id = gform_get_meta($entry_id, 'paynow_feed_id');
        $feed = $this->get_feed($feed_id);

        return !empty($feed) ? $feed : false;
    }

    public function post_callback($callback_action, $callback_result) {
        if (is_wp_error($callback_action) || !$callback_action) {
            return false;
        }
        
        if (rgar( $callback_action, 'type' )== "complete_payment")
            header("Location: {$this->get_plugin_setting('paid_url')}");
            else
               header("Location: {$this->get_plugin_setting('cancelled_url')}"); 
    }

    private function verify_paynow_ipn() {

        $req = 'cmd=_notify-validate';
        foreach ($_POST as $key => $value) {
            $value = urlencode(stripslashes($value));
            $req .= "&$key=$value";
        }

        $url = rgpost('test_ipn') ? $this->sandbox_url : apply_filters('gform_paynow_ipn_url', $this->production_url);

        $this->log_debug(__METHOD__ . "(): Sending IPN request to PayNow for validation. URL: $url - Data: $req");

        $url_info = parse_url($url);

        //Post back to PayNow system to validate
        $request = new WP_Http();
        $headers = array('Host' => $url_info['host']);
        $sslverify = (bool) get_option('gform_paynow_sslverify');

        /**
         * Allow sslverify be modified before sending requests
         *
         * @since 2.5.1
         *
         * @param bool $sslverify Whether to verify SSL for the request. Default true for new installations, false for legacy installations.
         */
        $sslverify = apply_filters('gform_paynow_sslverify', $sslverify);
        $this->log_debug(__METHOD__ . '(): sslverify: ' . $sslverify);
        $response = $request->post($url, array('httpversion' => '1.1', 'headers' => $headers, 'sslverify' => $sslverify, 'ssl' => true, 'body' => $req, 'timeout' => 20));
        $this->log_debug(__METHOD__ . '(): Response: ' . print_r($response, true));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = trim($response['body']);

        if (!in_array($body, array('VERIFIED', 'INVALID'))) {
            return new WP_Error('IPNVerificationError', 'Unexpected content in the response body.');
        }

        return $body == 'VERIFIED';
    }

    private function process_ipn($config, $entry, $status, $transaction_type, $transaction_id, $parent_transaction_id, $subscriber_id, $amount, $pending_reason, $reason, $recurring_amount) {
        $this->log_debug(__METHOD__ . "(): Payment status: {$status} - Transaction Type: {$transaction_type} - Transaction ID: {$transaction_id} - Parent Transaction: {$parent_transaction_id} - Subscriber ID: {$subscriber_id} - Amount: {$amount} - Pending reason: {$pending_reason} - Reason: {$reason}");

        $action = array();
        switch (strtolower($transaction_type)) {
            case 'subscr_payment' :
                //transaction created
                $action['id'] = $transaction_id;
                $action['transaction_id'] = $transaction_id;
                $action['type'] = 'add_subscription_payment';
                $action['subscription_id'] = $subscriber_id;
                $action['amount'] = $amount;
                $action['entry_id'] = $entry['id'];
                $action['payment_method'] = 'PayNow';
                return $action;
                break;

            case 'subscr_signup' :
                //no transaction created
                $action['id'] = $subscriber_id . '_' . $transaction_type;
                $action['type'] = 'create_subscription';
                $action['subscription_id'] = $subscriber_id;
                $action['amount'] = $recurring_amount;
                $action['entry_id'] = $entry['id'];
                $action['ready_to_fulfill'] = !$entry['is_fulfilled'] ? true : false;

                if (!$this->is_valid_initial_payment_amount($entry['id'], $recurring_amount)) {
                    //create note and transaction
                    $this->log_debug(__METHOD__ . '(): Payment amount does not match subscription amount. Subscription will not be activated.');
                    GFPaymentAddOn::add_note($entry['id'], sprintf(__('Payment amount (%s) does not match subscription amount. Subscription will not be activated. Transaction Id: %s', 'gravityformspaynow'), GFCommon::to_money($recurring_amount, $entry['currency']), $subscriber_id));
                    GFPaymentAddOn::insert_transaction($entry['id'], 'payment', $subscriber_id, $recurring_amount);

                    $action['abort_callback'] = true;
                }

                return $action;
                break;

            case 'subscr_cancel' :
                //no transaction created

                $action['id'] = $subscriber_id . '_' . $transaction_type;
                $action['type'] = 'cancel_subscription';
                $action['subscription_id'] = $subscriber_id;
                $action['entry_id'] = $entry['id'];

                return $action;
                break;

            case 'subscr_eot' :
                //no transaction created
                if (empty($transaction_id)) {
                    $action['id'] = $subscriber_id . '_' . $transaction_type;
                } else {
                    $action['id'] = $transaction_id;
                }
                $action['type'] = 'expire_subscription';
                $action['subscription_id'] = $subscriber_id;
                $action['entry_id'] = $entry['id'];

                return $action;
                break;

            case 'subscr_failed' :
                //no transaction created
                if (empty($transaction_id)) {
                    $action['id'] = $subscriber_id . '_' . $transaction_type;
                } else {
                    $action['id'] = $transaction_id;
                }
                $action['type'] = 'fail_subscription_payment';
                $action['subscription_id'] = $subscriber_id;
                $action['entry_id'] = $entry['id'];
                $action['amount'] = $amount;

                return $action;
                break;

            default:
                //handles products and donation
                switch (strtolower($status)) {
                    case 'completed' :
                        //creates transaction
                        $action['id'] = $transaction_id . '_' . $status;
                        $action['type'] = 'complete_payment';
                        $action['transaction_id'] = $transaction_id;
                        $action['amount'] = $amount;
                        $action['entry_id'] = $entry['id'];
                        $action['payment_date'] = gmdate('y-m-d H:i:s');
                        $action['payment_method'] = 'PayNow';
                        $action['ready_to_fulfill'] = !$entry['is_fulfilled'] ? true : false;

                        if (!$this->is_valid_initial_payment_amount($entry['id'], $amount)) {
                            //create note and transaction
                            $this->log_debug(__METHOD__ . '(): Payment amount does not match product price. Entry will not be marked as Approved.');
                            GFPaymentAddOn::add_note($entry['id'], sprintf(__('Payment amount (%s) does not match product price. Entry will not be marked as Approved. Transaction Id: %s', 'gravityformspaynow'), GFCommon::to_money($amount, $entry['currency']), $transaction_id));
                            GFPaymentAddOn::insert_transaction($entry['id'], 'payment', $transaction_id, $amount);

                            $action['abort_callback'] = true;
                        }

                        return $action;
                        break;

                    case 'reversed' :
                        //creates transaction
                        $this->log_debug(__METHOD__ . '(): Processing reversal.');
                        GFAPI::update_entry_property($entry['id'], 'payment_status', 'Refunded');
                        GFPaymentAddOn::add_note($entry['id'], sprintf(__('Payment has been reversed. Transaction Id: %s. Reason: %s', 'gravityformspaynow'), $transaction_id, $this->get_reason($reason)));
                        GFPaymentAddOn::insert_transaction($entry['id'], 'refund', $action['transaction_id'], $action['amount']);
                        break;

                    case 'canceled_reversal' :
                        //creates transaction
                        $this->log_debug(__METHOD__ . '(): Processing a reversal cancellation');
                        GFAPI::update_entry_property($entry['id'], 'payment_status', 'Paid');
                        GFPaymentAddOn::add_note($entry['id'], sprintf(__('Payment reversal has been canceled and the funds have been transferred to your account. Transaction Id: %s', 'gravityformspaynow'), $entry['transaction_id']));
                        GFPaymentAddOn::insert_transaction($entry['id'], 'payment', $action['transaction_id'], $action['amount']);
                        break;

                    case 'processed' :
                    case 'pending' :
                        $action['id'] = $transaction_id . '_' . $status;
                        $action['type'] = 'add_pending_payment';
                        $action['transaction_id'] = $transaction_id;
                        $action['entry_id'] = $entry['id'];
                        $action['amount'] = $amount;
                        $action['entry_id'] = $entry['id'];
                        $amount_formatted = GFCommon::to_money($action['amount'], $entry['currency']);
                        $action['note'] = sprintf(__('Payment is pending. Amount: %s. Transaction Id: %s. Reason: %s', 'gravityformspaynow'), $amount_formatted, $action['transaction_id'], $this->get_pending_reason($pending_reason));

                        return $action;
                        break;

                    case 'refunded' :
                        $action['id'] = $transaction_id . '_' . $status;
                        $action['type'] = 'refund_payment';
                        $action['transaction_id'] = $transaction_id;
                        $action['entry_id'] = $entry['id'];
                        $action['amount'] = $amount;

                        return $action;
                        break;

                    case 'voided' :
                        $action['id'] = $transaction_id . '_' . $status;
                        $action['type'] = 'void_authorization';
                        $action['transaction_id'] = $transaction_id;
                        $action['entry_id'] = $entry['id'];
                        $action['amount'] = $amount;

                        return $action;
                        break;

                    case 'denied' :
                    case 'failed' :
                        $action['id'] = $transaction_id . '_' . $status;
                        $action['type'] = 'fail_payment';
                        $action['transaction_id'] = $transaction_id;
                        $action['entry_id'] = $entry['id'];
                        $action['amount'] = $amount;

                        return $action;
                        break;
                }

                break;
        }
    }
    
    public function return_url( $form_id, $lead_id ) {
		$pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';

		$server_port = apply_filters( 'gform_paypal_return_url_port', $_SERVER['SERVER_PORT'] );

		if ( $server_port != '80' ) {
			$pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
		} else {
			$pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		}

		$ids_query = "ids={$form_id}|{$lead_id}";
		$ids_query .= '&hash=' . wp_hash( $ids_query );

		$url = add_query_arg( 'gf_paynow_return', base64_encode( $ids_query ), $pageURL );

		return $url;

	}

    public function get_entry($custom_field) {

        //Getting entry associated with this IPN message (entry id is sent in the 'custom' field)
        list( $entry_id, $hash ) = explode('|', $custom_field);
        $hash_matches = wp_hash($entry_id) == $hash;

        //allow the user to do some other kind of validation of the hash
        $hash_matches = apply_filters('gform_paynow_hash_matches', $hash_matches, $entry_id, $hash, $custom_field);

        //Validates that Entry Id wasn't tampered with
        if (!rgpost('test_ipn') && !$hash_matches) {
            $this->log_error(__METHOD__ . "(): Entry Id verification failed. Hash does not match. Custom field: {$custom_field}. Aborting.");

            return false;
        }

        $this->log_debug(__METHOD__ . "(): IPN message has a valid custom field: {$custom_field}");

        $entry = GFAPI::get_entry($entry_id);

        if (is_wp_error($entry)) {
            $this->log_error(__METHOD__ . '(): ' . $entry->get_error_message());

            return false;
        }

        return $entry;
    }

    public function is_callback_valid() {
        if (rgget('page') != 'gf_paynow_ipn') {
            return false;
        }

        return true;
    }

    public function init_ajax() {

        parent::init_ajax();

        add_action('wp_ajax_gf_dismiss_paynow_menu', array($this, 'ajax_dismiss_menu'));
    }

    //------- ADMIN FUNCTIONS/HOOKS -----------//

    public function init_admin() {

        parent::init_admin();

        //add actions to allow the payment status to be modified
        add_action('gform_payment_status', array($this, 'admin_edit_payment_status'), 3, 3);
        add_action('gform_payment_date', array($this, 'admin_edit_payment_date'), 3, 3);
        add_action('gform_payment_transaction_id', array($this, 'admin_edit_payment_transaction_id'), 3, 3);
        add_action('gform_payment_amount', array($this, 'admin_edit_payment_amount'), 3, 3);
        add_action('gform_after_update_entry', array($this, 'admin_update_payment'), 4, 2);

        add_filter('gform_addon_navigation', array($this, 'maybe_create_menu'));

        //checking if webserver is compatible with PayNow SSL certificate
        add_action('admin_notices', array($this, 'check_ipn_request'));
    }

    public function maybe_create_menu($menus) {
        $current_user = wp_get_current_user();
        $dismiss_paynow_menu = get_metadata('user', $current_user->ID, 'dismiss_paynow_menu', true);
        if ($dismiss_paynow_menu != '1') {
            $menus[] = array('name' => $this->_slug, 'label' => $this->get_short_title(), 'callback' => array($this, 'temporary_plugin_page'), 'permission' => $this->_capabilities_form_settings);
        }

        return $menus;
    }

    public function ajax_dismiss_menu() {

        $current_user = wp_get_current_user();
        update_metadata('user', $current_user->ID, 'dismiss_paynow_menu', '1');
    }

    public function temporary_plugin_page() {
        $current_user = wp_get_current_user();
        ?>
        <script type="text/javascript">
            function dismissMenu() {
                jQuery('#gf_spinner').show();
                jQuery.post(ajaxurl, {
                    action: "gf_dismiss_paynow_menu"
                },
                        function (response) {
                            document.location.href = '?page=gf_edit_forms';
                            jQuery('#gf_spinner').hide();
                        }
                );

            }
        </script>

        <div class="wrap about-wrap">
            <h1><?php _e('PayNow Add-On v1.0', 'gravityformspaynow') ?></h1>
            <div class="about-text"><?php esc_html_e('Zimbabwe\'s Leading Online Payments Platform', 'gravityformspaynow') ?></div>
            <div class="changelog">
            
                <div class="feature-section">
                    <img src="https://www.paynow.co.zw/Content/icons/paynow-logo-blue.png">
                </div>

      
            </div>
        </div>
        <?php
    }

    public function admin_edit_payment_status($payment_status, $form, $entry) {
        if ($this->payment_details_editing_disabled($entry)) {
            return $payment_status;
        }

        //create drop down for payment status
        $payment_string = gform_tooltip('paynow_edit_payment_status', '', true);
        $payment_string .= '<select id="payment_status" name="payment_status">';
        $payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status . '</option>';
        $payment_string .= '<option value="Paid">Paid</option>';
        $payment_string .= '</select>';

        return $payment_string;
    }

    public function admin_edit_payment_date($payment_date, $form, $entry) {
        if ($this->payment_details_editing_disabled($entry)) {
            return $payment_date;
        }

        $payment_date = $entry['payment_date'];
        if (empty($payment_date)) {
            $payment_date = gmdate('y-m-d H:i:s');
        }

        $input = '<input type="text" id="payment_date" name="payment_date" value="' . $payment_date . '">';

        return $input;
    }

    public function admin_edit_payment_transaction_id($transaction_id, $form, $entry) {
        if ($this->payment_details_editing_disabled($entry)) {
            return $transaction_id;
        }

        $input = '<input type="text" id="paynow_transaction_id" name="paynow_transaction_id" value="' . $transaction_id . '">';

        return $input;
    }

    public function admin_edit_payment_amount($payment_amount, $form, $entry) {
        if ($this->payment_details_editing_disabled($entry)) {
            return $payment_amount;
        }

        if (empty($payment_amount)) {
            $payment_amount = GFCommon::get_order_total($form, $entry);
        }

        $input = '<input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="' . $payment_amount . '">';

        return $input;
    }

    public function admin_update_payment($form, $entry_id) {
        check_admin_referer('gforms_save_entry', 'gforms_save_entry');

        //update payment information in admin, need to use this function so the lead data is updated before displayed in the sidebar info section
        $entry = GFFormsModel::get_lead($entry_id);

        if ($this->payment_details_editing_disabled($entry, 'update')) {
            return;
        }

        //get payment fields to update
        $payment_status = rgpost('payment_status');
        //when updating, payment status may not be editable, if no value in post, set to lead payment status
        if (empty($payment_status)) {
            $payment_status = $entry['payment_status'];
        }

        $payment_amount = GFCommon::to_number(rgpost('payment_amount'));
        $payment_transaction = rgpost('paynow_transaction_id');
        $payment_date = rgpost('payment_date');

        $status_unchanged = $entry['payment_status'] == $payment_status;
        $amount_unchanged = $entry['payment_amount'] == $payment_amount;
        $id_unchanged = $entry['transaction_id'] == $payment_transaction;
        $date_unchanged = $entry['payment_date'] == $payment_date;

        if ($status_unchanged && $amount_unchanged && $id_unchanged && $date_unchanged) {
            return;
        }

        if (empty($payment_date)) {
            $payment_date = gmdate('y-m-d H:i:s');
        } else {
            //format date entered by user
            $payment_date = date('Y-m-d H:i:s', strtotime($payment_date));
        }

        global $current_user;
        $user_id = 0;
        $user_name = 'System';
        if ($current_user && $user_data = get_userdata($current_user->ID)) {
            $user_id = $current_user->ID;
            $user_name = $user_data->display_name;
        }

        $entry['payment_status'] = $payment_status;
        $entry['payment_amount'] = $payment_amount;
        $entry['payment_date'] = $payment_date;
        $entry['transaction_id'] = $payment_transaction;

        // if payment status does not equal approved/paid or the lead has already been fulfilled, do not continue with fulfillment
        if (( $payment_status == 'Approved' || $payment_status == 'Paid' ) && !$entry['is_fulfilled']) {
            $action['id'] = $payment_transaction;
            $action['type'] = 'complete_payment';
            $action['transaction_id'] = $payment_transaction;
            $action['amount'] = $payment_amount;
            $action['entry_id'] = $entry['id'];

            $this->complete_payment($entry, $action);
            $this->fulfill_order($entry, $payment_transaction, $payment_amount);
        }
        //update lead, add a note
        GFAPI::update_entry($entry);
        GFFormsModel::add_note($entry['id'], $user_id, $user_name, sprintf(esc_html__('Payment information was manually updated. Status: %s. Amount: %s. Transaction Id: %s. Date: %s', 'gravityformspaynow'), $entry['payment_status'], GFCommon::to_money($entry['payment_amount'], $entry['currency']), $payment_transaction, $entry['payment_date']));
    }

    /**
     * Activate sslverify by default for new installations.
     *
     * Transform data when upgrading from legacy paynow.
     *
     * @param $previous_version
     */
    public function upgrade($previous_version) {

        if (empty($previous_version)) {
            $previous_version = get_option('gf_paynow_version');
        }

        if (empty($previous_version)) {
            update_option('gform_paynow_sslverify', true);
        }

        $previous_is_pre_addon_framework = !empty($previous_version) && version_compare($previous_version, '2.0.dev1', '<');

        if ($previous_is_pre_addon_framework) {

            //copy plugin settings
            $this->copy_settings();

            //copy existing feeds to new table
            $this->copy_feeds();

            //copy existing paynow transactions to new table
            $this->copy_transactions();

            //updating payment_gateway entry meta to 'gravityformspaynow' from 'paynow'
            $this->update_payment_gateway();

            //updating entry status from 'Approved' to 'Paid'
            $this->update_lead();
        }
    }

    public function uninstall() {
        parent::uninstall();
        delete_option('gform_paynow_sslverify');
    }

    //------ FOR BACKWARDS COMPATIBILITY ----------------------//

    public function update_feed_id($old_feed_id, $new_feed_id) {
        global $wpdb;
        $sql = $wpdb->prepare("UPDATE {$wpdb->prefix}rg_lead_meta SET meta_value=%s WHERE meta_key='paynow_feed_id' AND meta_value=%s", $new_feed_id, $old_feed_id);
        $wpdb->query($sql);
    }

    public function add_legacy_meta($new_meta, $old_feed) {

        $known_meta_keys = array(
            'email', 'mode', 'type', 'style', 'continue_text', 'cancel_url', 'disable_note', 'disable_shipping', 'recurring_amount_field', 'recurring_times',
            'recurring_retry', 'billing_cycle_number', 'billing_cycle_type', 'trial_period_enabled', 'trial_amount', 'trial_period_number', 'trial_period_type', 'delay_post',
            'update_post_action', 'delay_notifications', 'selected_notifications', 'paynow_conditional_enabled', 'paynow_conditional_field_id',
            'paynow_conditional_operator', 'paynow_conditional_value', 'customer_fields',
        );

        foreach ($old_feed['meta'] as $key => $value) {
            if (!in_array($key, $known_meta_keys)) {
                $new_meta[$key] = $value;
            }
        }

        return $new_meta;
    }

    public function update_payment_gateway() {
        global $wpdb;
        $sql = $wpdb->prepare("UPDATE {$wpdb->prefix}rg_lead_meta SET meta_value=%s WHERE meta_key='payment_gateway' AND meta_value='paynow'", $this->_slug);
        $wpdb->query($sql);
    }

    public function update_lead() {
        global $wpdb;
        $sql = $wpdb->prepare(
                "UPDATE {$wpdb->prefix}rg_lead
			 SET payment_status='Paid', payment_method='PayNow'
		     WHERE payment_status='Approved'
		     		AND ID IN (
					  	SELECT lead_id FROM {$wpdb->prefix}rg_lead_meta WHERE meta_key='payment_gateway' AND meta_value=%s
				   	)", $this->_slug);

        $wpdb->query($sql);
    }

    public function copy_settings() {
        //copy plugin settings
        $old_settings = get_option('gf_paynow_configured');
        $new_settings = array('gf_paynow_configured' => $old_settings);
        $this->update_plugin_settings($new_settings);
    }

    public function copy_feeds() {
        //get feeds
        $old_feeds = $this->get_old_feeds();

        if ($old_feeds) {

            $counter = 1;
            foreach ($old_feeds as $old_feed) {
                $feed_name = 'Feed ' . $counter;
                $form_id = $old_feed['form_id'];
                $is_active = $old_feed['is_active'];
                $customer_fields = $old_feed['meta']['customer_fields'];

                $new_meta = array(
                    'feedName' => $feed_name,
                    'paynowEmail' => rgar($old_feed['meta'], 'email'),
                    'mode' => rgar($old_feed['meta'], 'mode'),
                    'transactionType' => rgar($old_feed['meta'], 'type'),
                    'type' => rgar($old_feed['meta'], 'type'), //For backwards compatibility of the delayed payment feature
                    'pageStyle' => rgar($old_feed['meta'], 'style'),
                    'continueText' => rgar($old_feed['meta'], 'continue_text'),
                    'cancelUrl' => rgar($old_feed['meta'], 'cancel_url'),
                    'disableNote' => rgar($old_feed['meta'], 'disable_note'),
                    'disableShipping' => rgar($old_feed['meta'], 'disable_shipping'),
                    'recurringAmount' => rgar($old_feed['meta'], 'recurring_amount_field') == 'all' ? 'form_total' : rgar($old_feed['meta'], 'recurring_amount_field'),
                    'recurring_amount_field' => rgar($old_feed['meta'], 'recurring_amount_field'), //For backwards compatibility of the delayed payment feature
                    'recurringTimes' => rgar($old_feed['meta'], 'recurring_times'),
                    'recurringRetry' => rgar($old_feed['meta'], 'recurring_retry'),
                    'paymentAmount' => 'form_total',
                    'billingCycle_length' => rgar($old_feed['meta'], 'billing_cycle_number'),
                    'billingCycle_unit' => $this->convert_interval(rgar($old_feed['meta'], 'billing_cycle_type'), 'text'),
                    'trial_enabled' => rgar($old_feed['meta'], 'trial_period_enabled'),
                    'trial_product' => 'enter_amount',
                    'trial_amount' => rgar($old_feed['meta'], 'trial_amount'),
                    'trialPeriod_length' => rgar($old_feed['meta'], 'trial_period_number'),
                    'trialPeriod_unit' => $this->convert_interval(rgar($old_feed['meta'], 'trial_period_type'), 'text'),
                    'delayPost' => rgar($old_feed['meta'], 'delay_post'),
                    'change_post_status' => rgar($old_feed['meta'], 'update_post_action') ? '1' : '0',
                    'update_post_action' => rgar($old_feed['meta'], 'update_post_action'),
                    'delayNotification' => rgar($old_feed['meta'], 'delay_notifications'),
                    'selectedNotifications' => rgar($old_feed['meta'], 'selected_notifications'),
                    'billingInformation_firstName' => rgar($customer_fields, 'first_name'),
                    'billingInformation_lastName' => rgar($customer_fields, 'last_name'),
                    'billingInformation_email' => rgar($customer_fields, 'email'),
                    'billingInformation_address' => rgar($customer_fields, 'address1'),
                    'billingInformation_address2' => rgar($customer_fields, 'address2'),
                    'billingInformation_city' => rgar($customer_fields, 'city'),
                    'billingInformation_state' => rgar($customer_fields, 'state'),
                    'billingInformation_zip' => rgar($customer_fields, 'zip'),
                    'billingInformation_country' => rgar($customer_fields, 'country'),
                );

                $new_meta = $this->add_legacy_meta($new_meta, $old_feed);

                //add conditional logic
                $conditional_enabled = rgar($old_feed['meta'], 'paynow_conditional_enabled');
                if ($conditional_enabled) {
                    $new_meta['feed_condition_conditional_logic'] = 1;
                    $new_meta['feed_condition_conditional_logic_object'] = array(
                        'conditionalLogic' =>
                        array(
                            'actionType' => 'show',
                            'logicType' => 'all',
                            'rules' => array(
                                array(
                                    'fieldId' => rgar($old_feed['meta'], 'paynow_conditional_field_id'),
                                    'operator' => rgar($old_feed['meta'], 'paynow_conditional_operator'),
                                    'value' => rgar($old_feed['meta'], 'paynow_conditional_value')
                                ),
                            )
                        )
                    );
                } else {
                    $new_meta['feed_condition_conditional_logic'] = 0;
                }


                $new_feed_id = $this->insert_feed($form_id, $is_active, $new_meta);
                $this->update_feed_id($old_feed['id'], $new_feed_id);

                $counter ++;
            }
        }
    }

    public function copy_transactions() {
        //copy transactions from the paynow transaction table to the add payment transaction table
        global $wpdb;
        $old_table_name = $this->get_old_transaction_table_name();
        if (!$this->table_exists($old_table_name)) {
            return false;
        }
        $this->log_debug(__METHOD__ . '(): Copying old PayNow transactions into new table structure.');

        $new_table_name = $this->get_new_transaction_table_name();

        $sql = "INSERT INTO {$new_table_name} (lead_id, transaction_type, transaction_id, is_recurring, amount, date_created)
					SELECT entry_id, transaction_type, transaction_id, is_renewal, amount, date_created FROM {$old_table_name}";

        $wpdb->query($sql);

        $this->log_debug(__METHOD__ . "(): transactions: {$wpdb->rows_affected} rows were added.");
    }

    public function get_old_transaction_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'rg_paynow_transaction';
    }

    public function get_new_transaction_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'gf_addon_payment_transaction';
    }

    public function get_old_feeds() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rg_paynow';

        if (!$this->table_exists($table_name)) {
            return false;
        }

        $form_table_name = GFFormsModel::get_form_table_name();
        $sql = "SELECT s.id, s.is_active, s.form_id, s.meta, f.title as form_title
					FROM {$table_name} s
					INNER JOIN {$form_table_name} f ON s.form_id = f.id";

        $this->log_debug(__METHOD__ . "(): getting old feeds: {$sql}");

        $results = $wpdb->get_results($sql, ARRAY_A);

        $this->log_debug(__METHOD__ . "(): error?: {$wpdb->last_error}");

        $count = sizeof($results);

        $this->log_debug(__METHOD__ . "(): count: {$count}");

        for ($i = 0; $i < $count; $i ++) {
            $results[$i]['meta'] = maybe_unserialize($results[$i]['meta']);
        }

        return $results;
    }

    //This function kept static for backwards compatibility
    public static function get_config_by_entry($entry) {

        $paynow = GFPayNow::get_instance();

        $feed = $paynow->get_payment_feed($entry);

        if (empty($feed)) {
            return false;
        }

        return $feed['addon_slug'] == $paynow->_slug ? $feed : false;
    }

    //This function kept static for backwards compatibility
    //This needs to be here until all add-ons are on the framework, otherwise they look for this function
    public static function get_config($form_id) {

        $paynow = GFPayNow::get_instance();
        $feed = $paynow->get_feeds($form_id);

        //Ignore IPN messages from forms that are no longer configured with the PayNow add-on
        if (!$feed) {
            return false;
        }

        return $feed[0]; //only one feed per form is supported (left for backwards compatibility)
    }

    //------------------------------------------------------

    function ParseMsg($msg) {
        //convert to array data
        $parts = explode("&", $msg);
        $result = array();
        foreach ($parts as $i => $value) {
            $bits = explode("=", $value, 2);
            $result[$bits[0]] = urldecode($bits[1]);
        }

        return $result;
    }

    function UrlIfy($fields) {
        //url-ify the data for the POST
        $delim = "";
        $fields_string = "";
        foreach ($fields as $key => $value) {
            $fields_string .= $delim . $key . '=' . $value;
            $delim = "&";
        }

        return $fields_string;
    }

    function CreateHash($values, $MerchantKey) {
        $string = "";
        foreach ($values as $key => $value) {
            if (strtoupper($key) != "HASH") {
                $string .= $value;
            }
        }
        $string .= $MerchantKey;
        //echo $string."<br/><br/>";
        $hash = hash("sha512", $string);
        return strtoupper($hash);
    }

    function CreateMsg($values, $MerchantKey) {
        $fields = array();
        foreach ($values as $key => $value) {
            $fields[$key] = urlencode($value);
        }

        $fields["hash"] = urlencode($this->CreateHash($values, $MerchantKey));

        $fields_string = $this->UrlIfy($fields);
        return $fields_string;
    }

    function gf_paynow_process_paynow_notify() {

        // Check the request method is POST
        //if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] != 'POST' ) {
        //	return;
        //}

        $order_id = $_GET['order_id'];


        $payment_meta = get_post_meta($order_id, '_wc_paynow_payment_meta', true);

        if ($payment_meta) {
            //open connection
            $ch = curl_init();

            $url = $payment_meta["PollUrl"];

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 0);
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            //execute post
            $result = curl_exec($ch);

            if ($result) {
                //close connection
                $msg = $this->ParseMsg($result);

                $MerchantKey = $this->get_plugin_setting('merchant_key');
                ;
                $validateHash = $this->CreateHash($msg, $MerchantKey);
                echo "hash = " . $validateHash != $msg["hash"];
                if ($validateHash != $msg["hash"]) {
                    echo $result;
                } else {
                    $payment_meta = get_post_meta($order_id, '_wc_paynow_payment_meta', true);
                    $payment_meta['PollUrl'] = $msg["pollurl"];
                    $payment_meta['PaynowReference'] = $msg["paynowreference"];
                    $payment_meta['Amount'] = $msg["amount"];
                    $payment_meta['Status'] = $msg["status"];
                    update_post_meta($order_id, '_wc_paynow_payment_meta', $payment_meta);
                    //$order->payment_complete();
                    $action = array();
                    
                    if (trim(strtolower($msg["status"])) == ps_cancelled) {
                        GFAPI::update_entry_property($order_id, 'payment_status', 'Cancelled');
                        $action['id'] = $msg["paynowreference"];
                        $action['type'] = 'fail_payment';
                        $action['transaction_id'] = $msg["paynowreference"];;
                        $action['amount'] =$msg["amount"];
                        $action['entry_id'] = $order_id;
                        $action['payment_date'] = gmdate('y-m-d H:i:s');
                        $action['payment_method'] = 'Paynow';
                        return $action;
                    } else if (trim(strtolower($msg["status"])) == ps_failed) {
                        GFAPI::update_entry_property($order_id, 'payment_status', 'Failed');
                        $action['id'] = $msg["paynowreference"];
                        $action['type'] = 'fail_payment';
                        $action['transaction_id'] = $msg["paynowreference"];;
                        $action['amount'] =$msg["amount"];
                        $action['entry_id'] = $order_id;
                        $action['payment_date'] = gmdate('y-m-d H:i:s');
                        $action['payment_method'] = 'Paynow';
                        return $action;
                    } else if (trim(strtolower($msg["status"])) == ps_paid || trim(strtolower($msg["status"])) == ps_awaiting_delivery || trim(strtolower($msg["status"])) == ps_delivered) {
                        //file_put_contents('phperrorlog.txt', 'Post made LAST: '.print_r($msg, true), FILE_APPEND | LOCK_EX);
                        GFAPI::update_entry_property($order_id, 'payment_status', 'Paid');
                        $action['id'] = $msg["paynowreference"];
                        $action['type'] = 'complete_payment';
                        $action['transaction_id'] = $msg["paynowreference"];;
                        $action['amount'] =$msg["amount"];
                        $action['entry_id'] = $order_id;
                        $action['payment_date'] = gmdate('y-m-d H:i:s');
                        $action['payment_method'] = 'Paynow';
                        
                        return $action;
                    } else {
                        //keep current state
                    }
                }
            }
        }
    }
    
    
    public static function paynow_return($order_id) {

        // Check the request method is POST
        //if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] != 'POST' ) {
        //	return;
        //}

      
        $entry = GFAPI::get_entry( $order_id );
        $paynow = GFPayNow::get_instance();


        $payment_meta = get_post_meta($order_id, '_wc_paynow_payment_meta', true);

        if ($payment_meta) {
            //open connection
            $ch = curl_init();

            $url = $payment_meta["PollUrl"];

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 0);
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            //execute post
            $result = curl_exec($ch);

            if ($result) {
                //close connection
                $msg = $paynow->ParseMsg($result);

                $MerchantKey = $paynow->get_plugin_setting('merchant_key');
                ;
                $validateHash = $paynow->CreateHash($msg, $MerchantKey);
                echo "hash = " . $validateHash != $msg["hash"];
                if ($validateHash != $msg["hash"]) {
                    echo $result;
                } else {
                    $payment_meta = get_post_meta($order_id, '_wc_paynow_payment_meta', true);
                    $payment_meta['PollUrl'] = $msg["pollurl"];
                    $payment_meta['PaynowReference'] = $msg["paynowreference"];
                    $payment_meta['Amount'] = $msg["amount"];
                    $payment_meta['Status'] = $msg["status"];
                    update_post_meta($order_id, '_wc_paynow_payment_meta', $payment_meta);
                    //$order->payment_complete();
                    $action = array();
                    
                    if (trim(strtolower($msg["status"])) == ps_cancelled) {
                        GFAPI::update_entry_property($order_id, 'payment_status', 'Cancelled');
                        $action['id'] = $msg["paynowreference"];
                        $action['type'] = 'fail_payment';
                        $action['transaction_id'] = $msg["paynowreference"];;
                        $action['amount'] =$msg["amount"];
                        $action['entry_id'] = $order_id;
                        $action['payment_date'] = gmdate('y-m-d H:i:s');
                        $action['payment_method'] = 'Paynow';
                        $paynow->fail_payment( $entry, $action );
                        return $action;
                    } else if (trim(strtolower($msg["status"])) == ps_failed) {
                        GFAPI::update_entry_property($order_id, 'payment_status', 'Failed');
                        $action['id'] = $msg["paynowreference"];
                        $action['type'] = 'fail_payment';
                        $action['transaction_id'] = $msg["paynowreference"];;
                        $action['amount'] =$msg["amount"];
                        $action['entry_id'] = $order_id;
                        $action['payment_date'] = gmdate('y-m-d H:i:s');
                        $action['payment_method'] = 'Paynow';
                        $paynow->fail_payment( $entry, $action );
                        return;
                    } else if (trim(strtolower($msg["status"])) == ps_paid || trim(strtolower($msg["status"])) == ps_awaiting_delivery || trim(strtolower($msg["status"])) == ps_delivered) {
                        //file_put_contents('phperrorlog.txt', 'Post made LAST: '.print_r($msg, true), FILE_APPEND | LOCK_EX);
                        GFAPI::update_entry_property($order_id, 'payment_status', 'Paid');
                        $action['id'] = $msg["paynowreference"];
                        $action['type'] = 'complete_payment';
                        $action['transaction_id'] = $msg["paynowreference"];;
                        $action['amount'] =$msg["amount"];
                        $action['entry_id'] = $order_id;
                        $action['payment_date'] = gmdate('y-m-d H:i:s');
                        $action['payment_method'] = 'Paynow';
                        $paynow->complete_payment( $entry, $action );
                        return;
                    } else {
                        //keep current state
                    }
                }
            }
        }
    }


// End wc_paynow_process_paynow_notify()
}
