<?php  if( !defined('WPINC') ) die;
/**
 * Leyka_Rbk_Gateway class
 */

require_once LEYKA_PLUGIN_DIR.'gateways/rbk/includes/Leyka_Rbk_Gateway_Webhook_Verification.php';
require_once LEYKA_PLUGIN_DIR.'gateways/rbk/includes/Leyka_Rbk_Gateway_Helper.php';

class Leyka_Rbk_Gateway extends Leyka_Gateway {

    protected static $_instance;

    const RBK_API_HOST = 'https://api.rbk.money';
    const RBK_API_PATH = '/v2/processing/invoices';

    protected $_rbk_response;
    protected $_rbk_log = array();

    protected function _set_attributes() {

        $this->_id = 'rbk';
        $this->_title = __('RBK Money', 'leyka');

        $this->_description = apply_filters(
            'leyka_gateway_description',
            __('RBK Money allows a simple and safe way to pay for goods and services with bank cards and other means through internet. You will have to fill a payment form, and then you will be redirected to the <a href="https://rbkmoney.ru/">RBK Money</a> secure payment page to enter your bank card data and to confirm your payment.', 'leyka'),
            $this->_id
        );

        $this->_docs_link = '//leyka.te-st.ru/docs/podklyuchenie-rbk/';
        $this->_registration_link = '//auth.rbk.money/auth/realms/external/login-actions/registration?client_id=koffing';

        $this->_min_commission = 2.9;
        $this->_receiver_types = array('legal');
        $this->_may_support_recurring = true;

    }

    protected function _set_options_defaults() {

        if($this->_options) {
            return;
        }

        $this->_options = array(
            'rbk_shop_id' => array(
                'type' => 'text',
                'title' => __('RBK Money shopID', 'leyka'),
                'comment' => __('Please, enter your shopID value here. It can be found in your contract with RBK Money or in your control panel there.', 'leyka'),
                'required' => true,
                'placeholder' => sprintf(__('E.g., %s', 'leyka'), '1234'),
            ),
            'rbk_api_key' => array(
                'type' => 'textarea',
                'title' => __('RBK Money apiKey', 'leyka'),
                'comment' => __('Please, enter your apiKey value here. It can be found in your RBK Money control panel.', 'leyka'),
                'required' => true,
                'placeholder' => sprintf(__('E.g., %s', 'leyka'), 'RU123456789'),
            ),
            'rbk_api_web_hook_key' => array(
                'type' => 'textarea',
                'title' => __('RBK Money webhook public key', 'leyka'),
                'comment' => __('Please, enter your webhook public key value here.', 'leyka'),
                'required' => true,
                'placeholder' => __('-----BEGIN PUBLIC KEY----- ...', 'leyka'),
            ),
            'rbk_keep_payment_logs' => array(
                'type' => 'checkbox',
                'default' => false,
                'title' => __('Keep detailed logs of all gateway service operations', 'leyka'),
                'comment' => __('Check if you want to keep detailed logs of all gateway service operations for each incoming donation.', 'leyka'),
                'short_format' => true,
            ),
        );

    }

    protected function _initialize_pm_list() {
        if(empty($this->_payment_methods['bankcard'])) {
            $this->_payment_methods['bankcard'] = Leyka_Rbk_Card::get_instance();
        }
    }

    public function enqueue_gateway_scripts() {

        if(Leyka_Rbk_Card::get_instance()->active) {

            wp_enqueue_script(
                'leyka-rbk-checkout',
                'https://checkout.rbk.money/checkout.js',
                array(),
                false,
                true
            );
            wp_enqueue_script(
                'leyka-rbk',
                LEYKA_PLUGIN_BASE_URL.'gateways/'.Leyka_Rbk_Gateway::get_instance()->id.'/js/leyka.rbk.js',
                array('jquery', 'leyka-rbk-checkout',),
                LEYKA_VERSION,
                true
            );

        }

    }

    public function get_donation_by_invoice_id($invoice_id) {

        global $wpdb;
        return $wpdb->get_var(
            "SELECT `post_id` FROM 
			{$wpdb->postmeta}
			WHERE `meta_key` = '_leyka_rbk_invoice_id'
			AND `meta_value`  = '$invoice_id'
			LIMIT 1"
        );

    }

    public function process_form($gateway_id, $pm_id, $donation_id, $form_data) {

        $donation = new Leyka_Donation($donation_id);

        if( !empty($form_data['leyka_recurring']) ) {
            $donation->payment_type = 'rebill';
        }

        // 1. Create an invoice:
        $api_request_url = self::RBK_API_HOST.self::RBK_API_PATH;
        $args = array(
            'timeout' => 30,
            'redirection' => 10,
            'blocking' => true,
            'httpversion' => '1.1',
            'headers' => array(
                'X-Request-ID' => uniqid(),
                'Authorization' => 'Bearer '.leyka_options()->opt('leyka_rbk_api_key'),
                'Content-type' => 'application/json; charset=utf-8',
                'Accept' => 'application/json',
            ),
            'body' => json_encode(array(
                'shopID' => leyka_options()->opt('leyka_rbk_shop_id'),
                'amount' => 100 * (int)$donation->amount, // Amount in minor currency units (like cent or kopeyka). Must be int
                'currency' => 'RUB',
                'product' => sprintf(__('%s - recurring donation'), $donation->payment_title),
                'dueDate' => date( 'Y-m-d\TH:i:s\Z', strtotime('+2 minute', current_time('timestamp', 1)) ),
                'metadata' => array('donation_id' => $donation_id,),
            ))
        );

        if(leyka_options()->opt('rbk_keep_payment_logs')) {
            $this->_rbk_log['RBK_Request'] = array('url' => $api_request_url, 'params' => $args,);
        }

        $this->_rbk_response = json_decode(wp_remote_retrieve_body(wp_remote_post($api_request_url, $args)), true);

        // 2. Create a payment for the invoice - will be done on the frontend, by the RBK Checkout widget

    }

    public function submission_redirect_url($current_url, $pm_id) {
        return '';
    }

    public function submission_form_data($form_data, $pm_id, $donation_id) {

        if( !array_key_exists($pm_id, $this->_payment_methods) ) {
            return $form_data; // It's not our PM
        }

        if(is_wp_error($donation_id)) { /** @var WP_Error $donation_id */
            return array('status' => 1, 'message' => $donation_id->get_error_message());
        } else if( !$donation_id ) {
            return array('status' => 1, 'message' => __('The donation was not created due to error.', 'leyka'));
        }

        $donation = new Leyka_Donation($donation_id);
        $campaign = new Leyka_Campaign($donation->campaign_id);

        $invoice_id = $this->_rbk_response['invoice']['id'];
        $invoice_access_token = $this->_rbk_response['invoiceAccessToken']['payload'];
        $donation->rbk_invoice_id = $invoice_id;

        if(leyka_options()->opt('rbk_keep_payment_logs')) {

            $this->_rbk_log['RBK_Response'] = (array)$this->_rbk_response;
            $donation->add_gateway_response($this->_rbk_log);

        } else {
            $donation->add_gateway_response((array)$this->_rbk_response);
        }

        return array(
            'invoice_id' => $invoice_id,
            'invoice_access_token' => $invoice_access_token,
            'is_recurring' => !empty($form_data['leyka_recurring']),
            'amount' => $donation->amount, // For GA EEC, "eec.add" event
            'name' => sprintf(__('Donation #%s', 'leyka'), $donation_id),
            'description' => esc_attr($campaign->payment_title),
            'donor_email' => $donation->donor_email,
            'default_pm' => 'bankCard',
            'success_page' => leyka_get_success_page_url(),
            'pre_submit_step' => '<div class="leyka-rbk-final-submit-buttons">
                <button class="rbk-final-submit-button">'.sprintf(__('Donate %s', 'leyka'), $donation->amount.' '.$donation->currency_label).'</button>
                <button class="rbk-final-cancel-button">'.__('Cancel', 'leyka').'</button>
            </div>'
        );

    }

    public function _handle_service_calls($call_type = '') {
        // Callback URLs are: some-website.org/leyka/service/rbk/process/
        // Request content should contain "eventType" field.
        // Possible field values: InvoicePaid, PaymentRefunded, PaymentProcessed, PaymentFailed, InvoiceCancelled, PaymentCancelled

        $data = file_get_contents('php://input');
        $check = Leyka_Rbk_Gateway_Webhook_Verification::verify_header_signature($data);
        $data = json_decode($data, true);

        if(is_wp_error($check)) {
            wp_die($check->get_error_message());
        } else if(empty($data['eventType']) || !is_string($data['eventType'])) {
            wp_die(__('Webhook error: eventType field is not found or have incorrect value', 'leyka'));
        }

        switch($data['eventType']) {
            case 'InvoicePaid':
            case 'PaymentRefunded':
            case 'PaymentFailed':
            case 'InvoiceCancelled':
            case 'PaymentCancelled':
                $this->_handle_webhook_donation_status_change($data);
                break;
            case 'PaymentProcessed':
                $this->_handle_payment_processed($data);
                break;
            default:
        }

    }

    protected function _handle_webhook_donation_status_change($data) {

        if( !is_array($data) || empty($data['invoice']['id']) || empty($data['eventType']) ) {
            return false; // Mb, return WP_Error?
        }

        $map_status = array(
            'InvoicePaid' => 'funded',
            'PaymentRefunded' => 'refunded',
            'PaymentFailed' => 'failed',
            'InvoiceCancelled' => 'failed',
            'PaymentCancelled' => 'failed',
        );
        $donation_id = $this->get_donation_by_invoice_id($data['invoice']['id']);
        $donation_status = empty($map_status[ $data['eventType'] ]) ? false : $map_status[ $data['eventType'] ];

        if( !$donation_status ) {
            return false; // Mb, return WP_Error?
        }

        $donation = new Leyka_Donation($donation_id);
        $donation->status = $map_status[ $data['eventType'] ];

        // Log webhook response:
        $data_to_log = $data;
        if(leyka_options()->opt('rbk_keep_payment_logs')) {

            $data_to_log = $donation->gateway_response;
            $data_to_log['RBK_Hook_data'] = $data;

        }

        return $donation->add_gateway_response($data_to_log);

    }

    protected function _handle_payment_processed($data) {

        // Log the webhook request content:
        $donation_id = self::get_donation_by_invoice_id($data['invoice']['id']);
        $donation = new Leyka_Donation($donation_id);

        $data_to_log = $data;
        if(leyka_options()->opt('rbk_keep_payment_logs')) {

            $data_to_log = $donation->gateway_response;
            $data_to_log['RBK_Hook_processed_data'] = $data;

        }

        $donation->add_gateway_response($data_to_log);

        $donation->rbk_payment_id = $data['payment']['id']; // ATM the invoice ID already saved in the donation

        // Capture the invoice:
        return wp_remote_post(
            self::RBK_API_HOST.self::RBK_API_PATH."/{$data['invoice']['id']}/payments/{$data['payment']['id']}/capture",
            array(
                'timeout' => 30,
                'redirection' => 10,
                'blocking' => true,
                'httpversion' => '1.1',
                'headers' => array(
                    'X-Request-ID' => uniqid(),
                    'Authorization' => 'Bearer '.leyka_options()->opt('leyka_rbk_api_key'),
                    'Content-type' => 'application/json; charset=utf-8',
                    'Accept' => 'application/json'
                ),
                'body' => json_encode(array('reason' => 'Donation auto capture',))
            )
        );

    }

    public function get_gateway_response_formatted(Leyka_Donation $donation) {

        if( !$donation->gateway_response ) {
            return array();
        }

        $vars = $donation->gateway_response;
        if( !$vars || !is_array($vars) ) {
            return array();
        }

        $vars = $vars[array_key_last($vars)];

        return array(
            __('Invoice ID:', 'leyka') => $vars['id'],
            __('Operation date:', 'leyka') => date('d.m.Y, H:i:s', strtotime($vars['createdAt'])),
            __('Operation status:', 'leyka') => $vars['status'],
            __('Full donation amount:', 'leyka') => $vars['amount'] / 100,
            __('Donation currency:', 'leyka') => $vars['currency'],
            __('Shop Account:', 'leyka') => $vars['shopID'],
        );

    }

    public function get_recurring_subscription_cancelling_link($link_text, Leyka_Donation $donation) {

        $init_recurring_donation = Leyka_Donation::get_init_recurring_donation($donation);
        $cancelling_url = (get_option('permalink_structure') ?
                home_url("leyka/service/cancel_recurring/{$donation->id}") :
                home_url("?page=leyka/service/cancel_recurring/{$donation->id}"))
            .'/'.md5($donation->id.'_'.$init_recurring_donation->id.'_leyka_cancel_recurring_subscription');

        return sprintf(__('<a href="%s" target="_blank" rel="noopener noreferrer">click here</a>', 'leyka'), $cancelling_url);

    }

    public function cancel_recurring_subscription(Leyka_Donation $donation) {

        if($donation->type !== 'rebill') {
            return new WP_Error(
                'wrong_recurring_donation_to_cancel',
                __('Wrong donation given to cancel a recurring subscription.', 'leyka')
            );
        }

        $init_recurring_donation = Leyka_Donation::get_init_recurring_donation($donation);
        if($init_recurring_donation) {

            $init_recurring_donation->recurring_is_active = false;

            return true;

        } else {
            return false;
        }

    }

    public function cancel_recurring_subscription_by_link(Leyka_Donation $donation) {

        if($donation->type !== 'rebill') {
            die();
        }

        header('Content-type: text/html; charset=utf-8');

        $recurring_cancelling_result = $this->cancel_recurring_subscription($donation);

        if($recurring_cancelling_result === true) {
            die(__('Recurring subscription cancelled successfully.', 'leyka'));
        } else if(is_wp_error($recurring_cancelling_result)) {
            die($recurring_cancelling_result->get_error_message());
        } else {
            die( sprintf(__('Error while trying to cancel the recurring subscription.<br><br>Please, email abount this to the <a href="%s" target="_blank">website tech. support</a>.<br><br>We are very sorry for inconvenience.', 'leyka'), leyka_get_website_tech_support_email()) );
        }

    }

    public function do_recurring_donation(Leyka_Donation $init_recurring_donation) {

        if( !$init_recurring_donation->rbk_invoice_id || !$init_recurring_donation->rbk_payment_id ) {
            return false;
        }

        $new_recurring_donation = Leyka_Donation::add_clone(
            $init_recurring_donation,
            array(
                'status' => 'submitted',
                'payment_type' => 'rebill',
                'init_recurring_donation' => $init_recurring_donation->id,
                'rbk_invoice_id' => false,
                'rbk_payment_id' => false,
            ),
            array('recalculate_total_amount' => true,)
        );

        if(is_wp_error($new_recurring_donation)) {
            return false;
        }

        // 1. Create a new invoice:
        $api_request_url = self::RBK_API_HOST.self::RBK_API_PATH;
        $args = array(
            'timeout' => 30,
            'redirection' => 10,
            'blocking' => true,
            'httpversion' => '1.1',
            'headers' => array(
                'Authorization' => 'Bearer '.leyka_options()->opt('leyka_rbk_api_key'),
                'Cache-Control' => 'no-cache',
                'Content-type' => 'application/json; charset=utf-8',
                'X-Request-ID' => uniqid(),
                'Accept' => 'application/json',
            ),
            'body' => json_encode(array(
                'shopID' => leyka_options()->opt('leyka_rbk_shop_id'),
                'dueDate' => date( 'Y-m-d\TH:i:s\Z', strtotime('+2 minute', current_time('timestamp', 1)) ),
                'amount' => 100 * (int)$new_recurring_donation->amount, // Amount in minor currency units. Must be int
                'currency' => 'RUB',
                'product' => sprintf(__('%s - non-initial recurring donation'), $new_recurring_donation->payment_title),
                'metadata' => array('donation_id' => $new_recurring_donation->id,),
            ))
        );

        if(leyka_options()->opt('rbk_keep_payment_logs')) {
            $this->_rbk_log['RBK_Request'] = array('url' => $api_request_url, 'params' => $args,);
        }

        $this->_rbk_response = json_decode(wp_remote_retrieve_body(wp_remote_post($api_request_url, $args)), true);

        if(empty($this->_rbk_response['invoice']['id']) || empty($this->_rbk_response['invoiceAccessToken']['payload'])) {

            $new_recurring_donation->add_gateway_response($this->_rbk_response);
            return false;

        }

        $new_recurring_donation->rbk_invoice_id = $this->_rbk_response['invoice']['id'];

        // 2. Create a payment for the invoice:
        $api_request_url = self::RBK_API_HOST.self::RBK_API_PATH."/{$this->_rbk_response['invoice']['id']}/payments";
        $args = array(
            'timeout' => 30,
            'redirection' => 10,
            'blocking' => true,
            'httpversion' => '1.1',
            'headers' => array(
                'Authorization' => 'Bearer '.$this->_rbk_response['invoiceAccessToken']['payload'],
                'Cache-Control' => 'no-cache',
                'Content-type' => 'application/json; charset=utf-8',
                'X-Request-ID' => uniqid(),
                'Accept' => 'application/json',
            ),
            'body' => json_encode(array(
                'flow' => array('type' => 'PaymentFlowInstant',),
                'payer' => array(
                    'payerType' => 'RecurrentPayer',
                    'recurrentParentPayment' => array(
                        'invoiceID' => $init_recurring_donation->rbk_invoice_id,
                        'paymentID' => $init_recurring_donation->rbk_payment_id,
                    ),
                    'contactInfo' => array('email' => $new_recurring_donation->donor_email,),
                )
            ))
        );

        if(leyka_options()->opt('rbk_keep_payment_logs')) {
            $this->_rbk_log['RBK_Request'] = array('url' => $api_request_url, 'params' => $args,);
        }

        $this->_rbk_response = json_decode(wp_remote_retrieve_body(wp_remote_post($api_request_url, $args)), true);

        if(leyka_options()->opt('rbk_keep_payment_logs')) {
            $this->_rbk_log['RBK_Request_processed_data'] = $this->_rbk_response;
        }

        // Save the gateway response finally:
        if(leyka_options()->opt('rbk_keep_payment_logs')) {

            $gateway_response = $new_recurring_donation->gateway_response;
            $new_recurring_donation->add_gateway_response(array_merge(
                $gateway_response ? (array)$gateway_response : array(), $this->_rbk_log
            ));

        } else {
            $new_recurring_donation->add_gateway_response($this->_rbk_response);
        }

        return $new_recurring_donation;

    }

    public function display_donation_specific_data_fields($donation = false) {

        if($donation) { // Edit donation page displayed

            $donation = leyka_get_validated_donation($donation);?>

            <label><?php _e('RBK Money invoice ID', 'leyka');?>:</label>
            <div class="leyka-ddata-field">
            <?php if($donation->type === 'correction') {?>
                <input type="text" id="rbk-invoice-id" name="rbk-invoice-id" placeholder="<?php _e('Enter RBK Money invoice ID', 'leyka');?>" value="<?php echo $donation->rbk_invoice_id;?>">
            <?php } else {?>
                <span class="fake-input"><?php echo $donation->rbk_invoice_id;?></span>
            <?php }?>
            </div>

            <label><?php _e('RBK Money payment ID', 'leyka');?>:</label>
            <div class="leyka-ddata-field">
            <?php if($donation->type === 'correction') {?>
                <input type="text" id="rbk-payment-id" name="rbk-payment-id" placeholder="<?php _e('Enter RBK Money payment ID', 'leyka');?>" value="<?php echo $donation->rbk_payment_id;?>">
            <?php } else {?>
                <span class="fake-input"><?php echo $donation->rbk_payment_id;?></span>
            <?php }?>
            </div>

            <?php if($donation->type === 'rebill') {

                $init_recurring_donation = $donation->init_recurring_donation; ?>

                <div class="recurring-is-active-field">
                    <label for="rbk-recurring-is-active"><?php _e('Recurring subscription is active', 'leyka'); ?>:</label>
                    <div class="leyka-ddata-field">
                        <input type="checkbox" id="rbk-recurring-is-active" name="rbk-recurring-is-active"
                               value="1" <?php echo $init_recurring_donation->recurring_is_active ? 'checked="checked"' : ''; ?>>
                    </div>
                </div>

                <?php if( !$donation->is_init_recurring_donation) {?>

                <label><?php _e('Initial recurring invoice ID', 'leyka');?>:</label>
                <div class="leyka-ddata-field">
                    <?php if($donation->type === 'correction') { ?>
                    <input type="text" id="rbk-init-invoice-id" name="rbk-init-invoice-id"
                           placeholder="<?php _e('Enter RBK Money initial recurring invoice ID', 'leyka'); ?>"
                           value="<?php echo $init_recurring_donation->rbk_invoice_id; ?>">
                    <?php } else {?>
                    <span class="fake-input"><?php echo $init_recurring_donation->rbk_invoice_id; ?></span>
                    <?php }?>
                </div>

                <br>

                <label><?php _e('Initial recurring payment ID', 'leyka');?>:</label>
                <div class="leyka-ddata-field">
                    <?php if($donation->type === 'correction') {?>
                        <input type="text" id="rbk-init-payment-id" name="rbk-init-payment-id"
                               placeholder="<?php _e('Enter RBK Money initial recurring payment ID', 'leyka'); ?>"
                               value="<?php echo $init_recurring_donation->rbk_payment_id; ?>">
                    <?php } else {?>
                    <span class="fake-input"><?php echo $init_recurring_donation->rbk_payment_id; ?></span>
                    <?php }?>
                </div>

            <?php }

            }

        } else { // New donation page displayed ?>

        <label for="rbk-invoice-id"><?php _e('RBK Money invoice ID', 'leyka');?>:</label>
        <div class="leyka-ddata-field">
            <input type="text" id="rbk-invoice-id" name="rbk-invoice-id" placeholder="<?php _e('Enter RBK Money invoice ID', 'leyka');?>" value="">
        </div>

        <label for="rbk-payment-id"><?php _e('RBK Money payment ID', 'leyka');?>:</label>
        <div class="leyka-ddata-field">
            <input type="text" id="rbk-payment-id" name="rbk-payment-id" placeholder="<?php _e('Enter RBK Money payment ID', 'leyka');?>" value="">
        </div>

        <?php }

    }

    public function get_specific_data_value($value, $field_name, Leyka_Donation $donation) {
        switch($field_name) {
            case 'invoice_id':
            case 'rbk_invoice_id':
                return get_post_meta($donation->id, '_leyka_rbk_invoice_id', true);
            case 'payment_id':
            case 'rbk_payment_id':
                return get_post_meta($donation->id, '_leyka_rbk_payment_id', true);
            default:
                return $value;
        }
    }

    public function set_specific_data_value($field_name, $value, Leyka_Donation $donation) {
        switch($field_name) {
            case 'invoice_id':
            case 'rbk_invoice_id':
                return update_post_meta($donation->id, '_leyka_rbk_invoice_id', $value);
            case 'payment_id':
            case 'rbk_payment_id':
                return update_post_meta($donation->id, '_leyka_rbk_payment_id', $value);
            default: return false;
        }
    }

    public function save_donation_specific_data(Leyka_Donation $donation) {

        if(isset($_POST['rbk-invoice-id']) && $donation->rbk_invoice_id != $_POST['rbk-invoice-id']) {
            $donation->rbk_invoice_id = $_POST['rbk-invoice-id'];
        }
        if(isset($_POST['rbk-payment-id']) && $donation->rbk_payment_id != $_POST['rbk-payment-id']) {
            $donation->rbk_payment_id = $_POST['rbk-payment-id'];
        }

        $donation->recurring_is_active = !empty($_POST['rbk-recurring-is-active']);

    }

    public function add_donation_specific_data($donation_id, array $donation_params) {

        if( !empty($donation_params['rbk_invoice_id']) ) {
            update_post_meta($donation_id, '_leyka_rbk_invoice_id', $donation_params['rbk_invoice_id']);
        }
        if( !empty($donation_params['rbk_payment_id']) ) {
            update_post_meta($donation_id, '_leyka_rbk_payment_id', $donation_params['rbk_payment_id']);
        }

    }

}


class Leyka_Rbk_Card extends Leyka_Payment_Method {

    protected static $_instance;

    public function _set_attributes() {

        $this->_id = 'bankcard';
        $this->_gateway_id = 'rbk';
        $this->_category = 'bank_cards';

        $this->_description = apply_filters(
            'leyka_pm_description',
            __('RBK Money allows a simple and safe way to pay for goods and services with bank cards and other means through internet. You will have to fill a payment form, and then you will be redirected to the <a href="https://rbkmoney.ru/">RBK Money</a> secure payment page to enter your bank card data and to confirm your payment.', 'leyka'),
            $this->_id,
            $this->_gateway_id,
            $this->_category
        );

        $this->_label_backend = __('Bank card (RBK Money)', 'leyka');
        $this->_label = __('Bank card', 'leyka');

        $this->_icons = apply_filters('leyka_icons_'.$this->_gateway_id.'_' . $this->_id, array(
            LEYKA_PLUGIN_BASE_URL.'img/pm-icons/card-visa.svg',
            LEYKA_PLUGIN_BASE_URL.'img/pm-icons/card-mastercard.svg',
            LEYKA_PLUGIN_BASE_URL.'img/pm-icons/card-maestro.svg',
            LEYKA_PLUGIN_BASE_URL.'img/pm-icons/card-mir.svg',
        ));

        $this->_supported_currencies[] = 'rur';
        $this->_default_currency = 'rur';

        $this->_processing_type = 'custom-process-submit-event';

    }

    public function has_recurring_support() {
        return true;
    }

}

function leyka_add_gateway_rbk() {
    leyka_add_gateway(Leyka_Rbk_Gateway::get_instance());
}

add_action('leyka_init_actions', 'leyka_add_gateway_rbk');