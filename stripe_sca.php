<?php

/*
  $Id: $

  osCommerce, Open Source E-Commerce Solutions
  http://www.oscommerce.com

  Copyright (c) 2020 osCommerce

  Released under the GNU General Public License
 */

require_once DIR_FS_CATALOG . 'includes/modules/payment/stripe_sca/init.php';

class stripe_sca {

    var $code, $title, $description, $enabled, $intent;

    function __construct() {
        global $PHP_SELF, $order, $payment;

        $this->signature = 'stripe|stripe_sca|1.5.0|2.3';
        $this->api_version = '2022-11-15';

        $this->code = 'stripe_sca';
        $this->title = MODULE_PAYMENT_STRIPE_SCA_TEXT_TITLE;
        $this->public_title = MODULE_PAYMENT_STRIPE_SCA_TEXT_PUBLIC_TITLE;
        $this->description = MODULE_PAYMENT_STRIPE_SCA_TEXT_DESCRIPTION;
        $this->sort_order = defined('MODULE_PAYMENT_STRIPE_SCA_SORT_ORDER') ? MODULE_PAYMENT_STRIPE_SCA_SORT_ORDER : 0;
        $this->enabled = defined('MODULE_PAYMENT_STRIPE_SCA_STATUS') && (MODULE_PAYMENT_STRIPE_SCA_STATUS == 'True') ? true : false;
        $this->order_status = defined('MODULE_PAYMENT_STRIPE_SCA_PREPARE_ORDER_STATUS_ID') && ((int) MODULE_PAYMENT_STRIPE_SCA_PREPARE_ORDER_STATUS_ID > 0) ? (int) MODULE_PAYMENT_STRIPE_SCA_PREPARE_ORDER_STATUS_ID : 0;

        if (defined('MODULE_PAYMENT_STRIPE_SCA_STATUS')) {
            if (MODULE_PAYMENT_STRIPE_SCA_TRANSACTION_SERVER == 'Test') {
                $this->title .= ' [Test]';
                $this->public_title .= ' (Test)';
            }

            $this->description .= $this->getTestLinkInfo();
        }

        if (!function_exists('curl_init')) {
            $this->description = '<div class="secWarning">' . MODULE_PAYMENT_STRIPE_SCA_ERROR_ADMIN_CURL . '</div>' . $this->description;

            $this->enabled = false;
        }

        if ($this->enabled === true) {
            if ((MODULE_PAYMENT_STRIPE_SCA_TRANSACTION_SERVER == 'Live' && (!tep_not_null(MODULE_PAYMENT_STRIPE_SCA_LIVE_PUBLISHABLE_KEY) || !tep_not_null(MODULE_PAYMENT_STRIPE_SCA_LIVE_SECRET_KEY))) || (MODULE_PAYMENT_STRIPE_SCA_TRANSACTION_SERVER == 'Test' && (!tep_not_null(MODULE_PAYMENT_STRIPE_SCA_TEST_PUBLISHABLE_KEY) || !tep_not_null(MODULE_PAYMENT_STRIPE_SCA_TEST_SECRET_KEY)))) {
                $this->description = '<div class="secWarning">' . MODULE_PAYMENT_STRIPE_SCA_ERROR_ADMIN_CONFIGURATION . '</div>' . $this->description;

                $this->enabled = false;
            }
        }

        if ($this->enabled === true) {
            if (isset($order) && is_object($order)) {
                $this->update_status();
            }
        }

        if (($PHP_SELF == 'modules.php') && isset($_GET['action']) && ($_GET['action'] == 'install') && isset($_GET['subaction']) && ($_GET['subaction'] == 'conntest')) {
            echo $this->getTestConnectionResult();
            exit;
        }
    }

    function update_status() {
        global $order;

        if (($this->enabled == true) && ((int) MODULE_PAYMENT_STRIPE_SCA_ZONE > 0)) {
            $check_flag = false;
            $check_query = tep_db_query("select zone_id from zones_to_geo_zones where geo_zone_id = '" . MODULE_PAYMENT_STRIPE_SCA_ZONE . "' and zone_country_id = '" . $order->delivery['country']['id'] . "' order by zone_id");
            while ($check = tep_db_fetch_array($check_query)) {
                if ($check['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($check['zone_id'] == $order->delivery['zone_id']) {
                    $check_flag = true;
                    break;
                }
            }

            if ($check_flag == false) {
                $this->enabled = false;
            }
        }
    }

    function javascript_validation() {
        return false;
    }

    function selection() {
        global $customer_id, $payment;

        if ((MODULE_PAYMENT_STRIPE_SCA_TOKENS == 'True') && !tep_session_is_registered('payment')) {
            $tokens_query = tep_db_query("select 1 from customers_stripe_tokens where customers_id = '" . (int) $customer_id . "' limit 1");

            if (tep_db_num_rows($tokens_query)) {
                $payment = $this->code;
                tep_session_register('payment');
            }
        }

        return array('id' => $this->code,
            'module' => $this->public_title);
    }

    function pre_confirmation_check() {
        global $oscTemplate;

        if ($this->templateClassExists()) {
            $oscTemplate->addBlock($this->getSubmitCardDetailsJavascript(), 'footer_scripts');
        }
    }

    function confirmation() {
        global $oscTemplate, $cartID, $cart_Stripe_SCA_ID, $customer_id, $languages_id, $order, $currencies, $currency, $stripe_payment_intent_id, $order_total_modules, $shipping, $insert_id;

        if (tep_session_is_registered('cartID')) {
          if (tep_session_is_registered('cart_Stripe_SCA_ID')) {
            $order_id = substr($cart_Stripe_SCA_ID, strpos($cart_Stripe_SCA_ID, '-') + 1);

            $check_query = tep_db_query('select orders_id from orders where orders_id = "' . (int) $order_id . '" limit 1');

            if (tep_db_num_rows($check_query)) {
                tep_db_query('delete from orders where orders_id = "' . (int) $order_id . '"');
                tep_db_query('delete from orders_total where orders_id = "' . (int) $order_id . '"');
                tep_db_query('delete from orders_status_history where orders_id = "' . (int) $order_id . '"');
                tep_db_query('delete from orders_products where orders_id = "' . (int) $order_id . '"');
                tep_db_query('delete from orders_products_attributes where orders_id = "' . (int) $order_id . '"');
                tep_db_query('delete from orders_products_download where orders_id = "' . (int) $order_id . '"');
            }
          }

          if (isset($order->info['payment_method_raw'])) {
              $order->info['payment_method'] = $order->info['payment_method_raw'];
              unset($order->info['payment_method_raw']);
          }

          $sql_data_array = array('customers_id' => $customer_id,
              'customers_name' => $order->customer['firstname'] . ' ' . $order->customer['lastname'],
              'customers_company' => $order->customer['company'],
              'customers_street_address' => $order->customer['street_address'],
              'customers_suburb' => $order->customer['suburb'],
              'customers_city' => $order->customer['city'],
              'customers_postcode' => $order->customer['postcode'],
              'customers_state' => $order->customer['state'],
              'customers_country' => $order->customer['country']['title'],
              'customers_telephone' => $order->customer['telephone'],
              'customers_email_address' => $order->customer['email_address'],
              'customers_address_format_id' => $order->customer['format_id'],
              'delivery_name' => $order->delivery['firstname'] . ' ' . $order->delivery['lastname'],
              'delivery_company' => $order->delivery['company'],
              'delivery_street_address' => $order->delivery['street_address'],
              'delivery_suburb' => $order->delivery['suburb'],
              'delivery_city' => $order->delivery['city'],
              'delivery_postcode' => $order->delivery['postcode'],
              'delivery_state' => $order->delivery['state'],
              'delivery_country' => $order->delivery['country']['title'],
              'delivery_address_format_id' => $order->delivery['format_id'],
              'billing_name' => $order->billing['firstname'] . ' ' . $order->billing['lastname'],
              'billing_company' => $order->billing['company'],
              'billing_street_address' => $order->billing['street_address'],
              'billing_suburb' => $order->billing['suburb'],
              'billing_city' => $order->billing['city'],
              'billing_postcode' => $order->billing['postcode'],
              'billing_state' => $order->billing['state'],
              'billing_country' => $order->billing['country']['title'],
              'billing_address_format_id' => $order->billing['format_id'],
              'payment_method' => $order->info['payment_method'],
              'cc_type' => $order->info['cc_type'],
              'cc_owner' => $order->info['cc_owner'],
              'cc_number' => $order->info['cc_number'],
              'cc_expires' => $order->info['cc_expires'],
              'date_purchased' => 'now()',
              'last_modified' => 'now()',
              'orders_status' => $order->info['order_status'],
              'currency' => $order->info['currency'],
              'currency_value' => $order->info['currency_value']);

          tep_db_perform("orders", $sql_data_array);

          $insert_id = tep_db_insert_id();

          if (is_array($order_total_modules->modules)) {
              foreach ($order_total_modules->modules as $value) {
                  $class = substr($value, 0, strrpos($value, '.'));
                  if ($GLOBALS[$class]->enabled) {
                      $size = sizeof($GLOBALS[$class]->output);
                      for ($i = 0; $i < $size; $i++) {
                          $sql_data_array = array('orders_id' => $insert_id,
                              'title' => $GLOBALS[$class]->output[$i]['title'],
                              'text' => $GLOBALS[$class]->output[$i]['text'],
                              'value' => $GLOBALS[$class]->output[$i]['value'],
                              'class' => $GLOBALS[$class]->code,
                              'sort_order' => $GLOBALS[$class]->sort_order);

                          tep_db_perform("orders_total", $sql_data_array);
                      }
                  }
              }
          }

          $sql_data_array = array('orders_id' => $insert_id,
              'orders_status_id' => $order->info['order_status'],
              'date_added' => 'now()',
              'customer_notified' => '',
              'comments' => $order->info['comments']);
          tep_db_perform('orders_status_history', $sql_data_array);

          for ($i = 0, $n = sizeof($order->products); $i < $n; $i++) {
              $sql_data_array = array('orders_id' => $insert_id,
                  'products_id' => tep_get_prid($order->products[$i]['id']),
                  'products_model' => $order->products[$i]['model'],
                  'products_name' => $order->products[$i]['name'],
                  'products_price' => $order->products[$i]['price'],
                  'final_price' => $order->products[$i]['final_price'],
                  'products_tax' => $order->products[$i]['tax'],
                  'products_quantity' => $order->products[$i]['qty']);

              tep_db_perform("orders_products", $sql_data_array);

              $order_products_id = tep_db_insert_id();

              $attributes_exist = '0';
              if (isset($order->products[$i]['attributes'])) {
                  $attributes_exist = '1';
                  for ($j = 0, $n2 = sizeof($order->products[$i]['attributes']); $j < $n2; $j++) {
                      if (DOWNLOAD_ENABLED == 'true') {
                          $attributes_query = "select popt.products_options_name, poval.products_options_values_name, pa.options_values_price, pa.price_prefix, pad.products_attributes_maxdays, pad.products_attributes_maxcount , pad.products_attributes_filename
                                 from products_options popt, products_options_values poval, products_attributes pa
                                 left join products_attributes_download pad
                                 on pa.products_attributes_id=pad.products_attributes_id
                                 where pa.products_id = '" . (int) $order->products[$i]['id'] . "'
                                 and pa.options_id = '" . (int) $order->products[$i]['attributes'][$j]['option_id'] . "'
                                 and pa.options_id = popt.products_options_id
                                 and pa.options_values_id = '" . (int) $order->products[$i]['attributes'][$j]['value_id'] . "'
                                 and pa.options_values_id = poval.products_options_values_id
                                 and popt.language_id = '" . (int) $languages_id . "'
                                 and poval.language_id = '" . (int) $languages_id . "'";
                          $attributes = tep_db_query($attributes_query);
                      } else {
                          $attributes = tep_db_query("select popt.products_options_name, poval.products_options_values_name, pa.options_values_price, pa.price_prefix from products_options popt, products_options_values poval, products_attributes pa where pa.products_id = '" . (int) $order->products[$i]['id'] . "' and pa.options_id = '" . (int) $order->products[$i]['attributes'][$j]['option_id'] . "' and pa.options_id = popt.products_options_id and pa.options_values_id = '" . (int) $order->products[$i]['attributes'][$j]['value_id'] . "' and pa.options_values_id = poval.products_options_values_id and popt.language_id = '" . (int) $languages_id . "' and poval.language_id = '" . (int) $languages_id . "'");
                      }
                      $attributes_values = tep_db_fetch_array($attributes);

                      $sql_data_array = array('orders_id' => $insert_id,
                          'orders_products_id' => $order_products_id,
                          'products_options' => $attributes_values['products_options_name'],
                          'products_options_values' => $attributes_values['products_options_values_name'],
                          'options_values_price' => $attributes_values['options_values_price'],
                          'price_prefix' => $attributes_values['price_prefix']);

                      tep_db_perform("orders_products_attributes", $sql_data_array);

                      if ((DOWNLOAD_ENABLED == 'true') && isset($attributes_values['products_attributes_filename']) && tep_not_null($attributes_values['products_attributes_filename'])) {
                          $sql_data_array = array('orders_id' => $insert_id,
                              'orders_products_id' => $order_products_id,
                              'orders_products_filename' => $attributes_values['products_attributes_filename'],
                              'download_maxdays' => $attributes_values['products_attributes_maxdays'],
                              'download_count' => $attributes_values['products_attributes_maxcount']);

                          tep_db_perform("orders_products_download", $sql_data_array);
                      }
                  }
              }
          }

          $cart_Stripe_SCA_ID = $cartID . '-' . $insert_id;
          tep_session_register('cart_Stripe_SCA_ID');

          $order_id = $insert_id;
        }

        $secret_key = MODULE_PAYMENT_STRIPE_SCA_TRANSACTION_SERVER == 'Live' ? MODULE_PAYMENT_STRIPE_SCA_LIVE_SECRET_KEY : MODULE_PAYMENT_STRIPE_SCA_TEST_SECRET_KEY;
        \Stripe\Stripe::setApiKey($secret_key);
        \Stripe\Stripe::setApiVersion($this->apiVersion);

        $metadata = array("customer_id" => tep_output_string($customer_id),
            "order_id" => tep_output_string($order_id),
            "company" => tep_output_string($order->customer['company'])
        );

        $content = '';

        if (MODULE_PAYMENT_STRIPE_SCA_TOKENS == 'True') {
            $tokens_query = tep_db_query("select id, stripe_token, card_type, number_filtered, expiry_date from customers_stripe_tokens where customers_id = '" . (int) $customer_id . "' order by date_added");

            if (tep_db_num_rows($tokens_query) > 0) {
                $content .= '<table  border="0" width="100%" cellspacing="0" cellpadding="2">';

                while ($tokens = tep_db_fetch_array($tokens_query)) {
                    // default to charging first saved card, changed by client directly calling payment_intent.php hook as selection changed
                    $content .= '<tr class="moduleRow" id="stripe_card_' . (int) $tokens['id'] . '">' .
                            '  <td width="40" valign="top"><input type="radio" name="stripe_card" value="' . (int) $tokens['id'] . '" /></td>' .
                            '  <td valign="top"><strong>' . tep_output_string_protected($tokens['card_type']) . '</strong>&nbsp;&nbsp;****' . tep_output_string_protected($tokens['number_filtered']) . '&nbsp;&nbsp;' . tep_output_string_protected(substr($tokens['expiry_date'], 0, 2) . '/' . substr($tokens['expiry_date'], 2)) . '</td>' .
                            '</tr>';
                }

                $content .= '<tr class="moduleRow" id="stripe_card_0">' .
                        '  <td width="40" valign="top"><input type="radio" name="stripe_card" value="0" /></td>' .
                        '  <td valign="top">' . MODULE_PAYMENT_STRIPE_SCA_CREDITCARD_NEW . '</td>' .
                        '</tr>' .
                        '</table><div id="save-card-element"></div>';
            }
        }

        $content .= '<div id="stripe_table_new_card"><img width="500" align=right src="https://glamocani-laktasi.com/images/secure-stripe-payment-logo.png">' .
                      '<div class="form-group">
                      
                        <label for="cardholder-name" class="col-form-label col-sm-4 text-left text-sm-center">' . MODULE_PAYMENT_STRIPE_SCA_CREDITCARD_OWNER . '</label>' .
                      ' <div><input type="text" value="' . tep_output_string($order->billing['firstname'] . ' ' . $order->billing['lastname']) . '" required></text></div>
                       </div>
                       <div class="form-group">
                         <label for="card-number" class="col-form-label col-sm-4 text-left text-sm-center">' . MODULE_PAYMENT_STRIPE_SCA_CREDITCARD_NUMBER . '</label>' .
                      '  <div id="card-number" class="col-sm-8 card-details"></div>
                       </div>
                       <div class="form-group">
                         <label for="card-expiry" class="col-form-label col-sm-4 text-left text-sm-center">' . MODULE_PAYMENT_STRIPE_SCA_CREDITCARD_EXPIRY . '</label>' .
                      '  <div id="card-expiry" class="col-sm-8 card-details"></div>
                       </div>
                       <div class="form-group">
                         <label for="card-cvc" class="col-form-label col-sm-4 text-right text-sm-center">' . MODULE_PAYMENT_STRIPE_SCA_CREDITCARD_CVC . '</label>' .
                      '  <div id="card-cvc" class="col-sm-2 card-details"></div>
                       </div>';
                       
        if (MODULE_PAYMENT_STRIPE_SCA_TOKENS == 'True') {
          $content .= '<div class="form-group">
                       <div class="col-sm-8 offset-4 pl-5 custom-control custom-switch">' . tep_draw_checkbox_field('card-save', 1, false, 'class="custom-control-input" id="inputCardSave"') .
                      ' <label for="inputCardSave" class="custom-control-label text-muted">' . MODULE_PAYMENT_STRIPE_SCA_CREDITCARD_SAVE . '</label>
                        </div>
                      </div>';
        }
        $content .= '</div><div id="card-errors" role="alert" class="messageStackError payment-errors"></div>';

        $address = array('address_line1' => $order->billing['street_address'],
            'address_city' => tep_output_string($order->billing['city']),
            'address_zip' => tep_output_string($order->billing['postcode']),
            'address_state' => tep_output_string(tep_get_zone_name($order->billing['country_id'], $order->billing['zone_id'], $order->billing['state'])),
            'address_country' => tep_output_string($order->billing['country']['iso_code_2']));

        foreach ($address as $k => $v) {
            $content .= '<input type="hidden" id="' . tep_output_string($k) . '" value="' . tep_output_string($v) . '" />';
        }
        $content .= '<input type="hidden" id="email_address" value="' . tep_output_string($order->customer['email_address']) . '" />';
        $content .= '<input type="hidden" id="customer_id" value="' . tep_output_string($customer_id) . '" />';

        if (MODULE_PAYMENT_STRIPE_SCA_TRANSACTION_METHOD == "Capture") {
            $capture_method = 'automatic';
        } else {
            $capture_method = 'manual';
        }
        // have to create intent before loading the javascript because it needs the intent id
        if (isset($stripe_payment_intent_id)) {
            try {
                $this->intent = \Stripe\PaymentIntent::retrieve(["id" => $stripe_payment_intent_id]);
                $this->event_log($customer_id, "page retrieve intent", $stripe_payment_intent_id, $this->intent);
                $this->intent->amount = $this->format_raw($order->info['total']);
                $this->intent->currency = $currency;
                $this->intent->metadata = $metadata;
                $response = $this->intent->save();
            } catch (exception $err) {
                $this->event_log($customer_id, "page create intent", $stripe_payment_intent_id, $err->getMessage());
                // failed to save existing intent, so create new one
                unset($stripe_payment_intent_id);
                if (tep_session_is_registered('stripe_payment_intent_id')) {
                    tep_session_unregister('stripe_payment_intent_id');
                }
            }
        }
        if (!isset($stripe_payment_intent_id)) {
            $params = array('amount' => $this->format_raw($order->info['total']),
                'currency' => $currency,
                'setup_future_usage' => 'off_session',
                'capture_method' => $capture_method,
                'metadata' => $metadata);
            $this->intent = \Stripe\PaymentIntent::create($params);
            $this->event_log($customer_id, "page create intent", json_encode($params), $this->intent);
            $stripe_payment_intent_id = $this->intent->id;
            tep_session_register('stripe_payment_intent_id');
        }
        $content .= '<input type="hidden" id="intent_id" value="' . tep_output_string($stripe_payment_intent_id) . '" />' .
                '<input type="hidden" id="secret" value="' . tep_output_string($this->intent->client_secret) . '" />';

        if (!$this->templateClassExists()) {
            $content .= $this->getSubmitCardDetailsJavascript();
        }

        $confirmation = array('title' => $content);

        return $confirmation;
    }

    function process_button() {
        return false;
    }

    function before_process() {

        $this->after_process();
    }

    function after_process() {
        global $cart, $order, $order_totals, $currencies, $OSCOM_Hooks, $oscTemplate, $insert_id, $products_ordered, $cart_Stripe_SCA_ID;

        if (tep_session_is_registered('cart_Stripe_SCA_ID')) {
            $order_id = substr($cart_Stripe_SCA_ID, strpos($cart_Stripe_SCA_ID, '-') + 1);
            $insert_id = $order_id;

            if (DOWNLOAD_ENABLED == 'true') {
                for ($i = 0, $n = sizeof($order->products); $i < $n; $i++) {
                    $downloads_query = tep_db_query("select opd.orders_products_filename from orders o, orders_products op, orders_products_download opd where o.orders_id = '" . (int) $order_id . "' and o.customers_id = '" . (int) $customer_id . "' and o.orders_id = op.orders_id and op.orders_products_id = opd.orders_products_id and opd.orders_products_filename != ''");

                    if (tep_db_num_rows($downloads_query)) {
                        if ($order->content_type == 'physical') {
                            $order->content_type = 'mixed';

                            break;
                        } else {
                            $order->content_type = 'virtual';
                        }
                    } else {
                        if ($order->content_type == 'virtual') {
                            $order->content_type = 'mixed';

                            break;
                        } else {
                            $order->content_type = 'physical';
                        }
                    }
                }
            } else {
                $order->content_type = 'physical';
            }

// initialized for the email confirmation
            $products_ordered = '';

            for ($i = 0, $n = sizeof($order->products); $i < $n; $i++) {
                if (STOCK_LIMITED == 'true') {
                    $stock_query = tep_db_query("select products_quantity from products where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");
                    $stock_values = tep_db_fetch_array($stock_query);

                    $stock_left = $stock_values['products_quantity'] - $order->products[$i]['qty'];

                    if (DOWNLOAD_ENABLED == 'true') {
                        $downloads_query = tep_db_query("select opd.orders_products_filename from orders o, orders_products op, orders_products_download opd where o.orders_id = '" . (int) $order_id . "' and o.customers_id = '" . (int) $customer_id . "' and o.orders_id = op.orders_id and op.orders_products_id = opd.orders_products_id and opd.orders_products_filename != ''");
                        $downloads_values = tep_db_fetch_array($downloads_query);

                        if (tep_db_num_rows($downloads_query)) {
                            $stock_left = $stock_values['products_quantity'];
                        }
                    }

                    if ($stock_values['products_quantity'] != $stock_left) {
                        tep_db_query("update products set products_quantity = '" . (int) $stock_left . "' where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");

                        if (($stock_left < 1) && (STOCK_ALLOW_CHECKOUT == 'false')) {
                            tep_db_query("update products set products_status = '0' where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");
                        }
                    }
                }

// Update products_ordered (for bestsellers list)
                tep_db_query("update products set products_ordered = products_ordered + " . sprintf('%d', $order->products[$i]['qty']) . " where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");

                $products_ordered_attributes = null;
                if (isset($order->products[$i]['attributes'])) {
                    for ($j = 0, $n2 = sizeof($order->products[$i]['attributes']); $j < $n2; $j++) {
                        $products_ordered_attributes .= "\n\t" . $order->products[$i]['attributes'][$j]['option'] . ' ' . $order->products[$i]['attributes'][$j]['value'];
                    }
                }

//------insert customer choosen option eof ----
                $products_ordered .= $order->products[$i]['qty'] . ' x ' . $order->products[$i]['name'] . ' (' . $order->products[$i]['model'] . ') = ' . $currencies->display_price($order->products[$i]['final_price'], $order->products[$i]['tax'], $order->products[$i]['qty']) . $products_ordered_attributes . "\n";
            }

// lets start with the email confirmation
            $email_order = STORE_NAME . "\n" .
                    EMAIL_SEPARATOR . "\n" .
                    EMAIL_TEXT_ORDER_NUMBER . ' ' . $order_id . "\n" .
                    EMAIL_TEXT_INVOICE_URL . ' ' . tep_href_link('account_history_info.php', 'order_id=' . $order_id, 'SSL', false) . "\n" .
                    EMAIL_TEXT_DATE_ORDERED . ' ' . strftime(DATE_FORMAT_LONG) . "\n\n";
            if ($order->info['comments']) {
                $email_order .= tep_db_output($order->info['comments']) . "\n\n";
            }
            $email_order .= EMAIL_TEXT_PRODUCTS . "\n" .
                    EMAIL_SEPARATOR . "\n" .
                    $products_ordered .
                    EMAIL_SEPARATOR . "\n";

            for ($i = 0, $n = sizeof($order_totals); $i < $n; $i++) {
                $email_order .= strip_tags($order_totals[$i]['title']) . ' ' . strip_tags($order_totals[$i]['text']) . "\n";
            }

            if ($order->content_type != 'virtual') {
                $email_order .= "\n" . EMAIL_TEXT_DELIVERY_ADDRESS . "\n" .
                        EMAIL_SEPARATOR . "\n" .
                        tep_address_format($order->delivery['format_id'], $order->delivery, false, '', "\n") . "\n";
            }

            $email_order .= "\n" . EMAIL_TEXT_BILLING_ADDRESS . "\n" .
                    EMAIL_SEPARATOR . "\n" .
                    tep_address_format($order->billing['format_id'], $order->billing, false, '', "\n") . "\n\n";

            $email_order .= EMAIL_TEXT_PAYMENT_METHOD . "\n" .
                    EMAIL_SEPARATOR . "\n";
            $email_order .= $this->title . "\n\n";
            if ($this->email_footer) {
                $email_order .= $this->email_footer . "\n\n";
            }

            tep_mail($order->customer['name'], $order->customer['email_address'], EMAIL_TEXT_SUBJECT, $email_order, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);

// send emails to other people
            if (SEND_EXTRA_ORDER_EMAILS_TO != '') {
                tep_mail('', SEND_EXTRA_ORDER_EMAILS_TO, EMAIL_TEXT_SUBJECT, $email_order, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);
            }

            tep_db_query("delete from customers_basket where customers_id = '" . (int) $customer_id . "'");
            tep_db_query("delete from customers_basket_attributes where customers_id = '" . (int) $customer_id . "'");

            if (tep_session_is_registered('stripe_error')) {
                tep_session_unregister('stripe_error');
            }
            if (tep_session_is_registered('stripe_payment_intent_id')) {
                tep_session_unregister('stripe_payment_intent_id');
            }

            $cart->reset(true);

// unregister session variables used during checkout
            tep_session_unregister('sendto');
            tep_session_unregister('billto');
            tep_session_unregister('shipping');
            tep_session_unregister('payment');
            tep_session_unregister('comments');

            tep_session_unregister('cart_Stripe_SCA_ID');

            tep_redirect(tep_href_link('checkout_success.php', '', 'SSL'));
        }
    }

    function get_error() {
        global $stripe_error;

        $message = MODULE_PAYMENT_STRIPE_SCA_ERROR_GENERAL;

        if (tep_session_is_registered('stripe_error')) {
            $message = $stripe_error . ' ' . $message;

            tep_session_unregister('stripe_error');
        }

        if (isset($_GET['error']) && !empty($_GET['error'])) {
            switch ($_GET['error']) {
                case 'cardstored':
                    $message = MODULE_PAYMENT_STRIPE_SCA_ERROR_CARDSTORED;
                    break;
            }
        }

        $error = array('title' => MODULE_PAYMENT_STRIPE_SCA_ERROR_TITLE,
            'error' => $message);

        return $error;
    }

    function check() {
        if (!isset($this->_check)) {
            $check_query = tep_db_query("select configuration_value from configuration where configuration_key = 'MODULE_PAYMENT_STRIPE_SCA_STATUS'");
            $this->_check = tep_db_num_rows($check_query);
        }
        return $this->_check;
    }

    function install($parameter = null) {
        $params = $this->getParams();

        if (isset($parameter)) {
            if (isset($params[$parameter])) {
                $params = array($parameter => $params[$parameter]);
            } else {
                $params = array();
            }
        }

        foreach ($params as $key => $data) {
            $sql_data_array = array('configuration_title' => $data['title'],
                'configuration_key' => $key,
                'configuration_value' => (isset($data['value']) ? $data['value'] : ''),
                'configuration_description' => $data['desc'],
                'configuration_group_id' => '6',
                'sort_order' => '0',
                'date_added' => 'now()');

            if (isset($data['set_func'])) {
                $sql_data_array['set_function'] = $data['set_func'];
            }

            if (isset($data['use_func'])) {
                $sql_data_array['use_function'] = $data['use_func'];
            }

            tep_db_perform("configuration", $sql_data_array);
        }
    }

    function remove() {
        tep_db_query("delete from configuration where configuration_key in ('" . implode("', '", $this->keys()) . "')");
    }

    function keys() {
        $keys = array_keys($this->getParams());

        if ($this->check()) {
            foreach ($keys as $key) {
                if (!defined($key)) {
                    $this->install($key);
                }
            }
        }

        return $keys;
    }

    function event_log($customer_id, $action, $request, $response) {
        if (MODULE_PAYMENT_STRIPE_SCA_LOG == "True") {
            tep_db_query("insert into stripe_event_log (customer_id, action, request, response, date_added) values ('" . $customer_id . "', '" . $action . "', '" . tep_db_input($request) . "', '" . tep_db_input($response) . "', now())");
        }
    }

    function getParams() {
        if (tep_db_num_rows(tep_db_query("show tables like 'customers_stripe_tokens'")) != 1) {
            $sql = <<<EOD
CREATE TABLE customers_stripe_tokens (
  id int NOT NULL auto_increment,
  customers_id int NOT NULL,
  stripe_token varchar(255) NOT NULL,
  card_type varchar(32) NOT NULL,
  number_filtered varchar(20) NOT NULL,
  expiry_date char(6) NOT NULL,
  date_added datetime NOT NULL,
  PRIMARY KEY (id),
  KEY idx_cstripet_customers_id (customers_id),
  KEY idx_cstripet_token (stripe_token)
);
EOD;

            tep_db_query($sql);
        }
        if (tep_db_num_rows(tep_db_query("show tables like 'stripe_event_log'")) != 1) {
            $sql = <<<EOD
CREATE TABLE stripe_event_log (
  id int NOT NULL auto_increment,
  customer_id int NOT NULL,
  action varchar(255) NOT NULL,
  request varchar(255) NOT NULL,
  response varchar(255) NOT NULL,
  date_added datetime NOT NULL,
  PRIMARY KEY (id)
);
EOD;

            tep_db_query($sql);
        }

        if (!defined('MODULE_PAYMENT_STRIPE_SCA_PREPARE_ORDER_STATUS_ID')) {
            $check_query = tep_db_query("select orders_status_id from orders_status where orders_status_name = 'Preparing [Stripe SCA]' limit 1");

            if (tep_db_num_rows($check_query) < 1) {
                $status_query = tep_db_query("select max(orders_status_id) as status_id from orders_status");
                $status = tep_db_fetch_array($status_query);

                $status_id = $status['status_id'] + 1;

                $languages = tep_get_languages();

                foreach ($languages as $lang) {
                    tep_db_query("insert into orders_status (orders_status_id, language_id, orders_status_name) values ('" . $status_id . "', '" . $lang['id'] . "', 'Preparing [Stripe SCA]')");
                }

                $flags_query = tep_db_query("describe orders_status public_flag");
                if (tep_db_num_rows($flags_query) == 1) {
                    tep_db_query("update orders_status set public_flag = 0 and downloads_flag = 0 where orders_status_id = '" . $status_id . "'");
                }
            } else {
                $check = tep_db_fetch_array($check_query);

                $prepare_status_id = $check['orders_status_id'];
            }
        } else {
            $prepare_status_id = MODULE_PAYMENT_STRIPE_SCA_PREPARE_ORDER_STATUS_ID;
        }
        if (!defined('MODULE_PAYMENT_STRIPE_SCA_TRANSACTION_ORDER_STATUS_ID')) {
            $check_query = tep_db_query("select orders_status_id from orders_status where orders_status_name = 'Stripe SCA [Transactions]' limit 1");

            if (tep_db_num_rows($check_query) < 1) {
                $status_query = tep_db_query("select max(orders_status_id) as status_id from orders_status");
                $status = tep_db_fetch_array($status_query);

                $status_id = $status['status_id'] + 1;

                $languages = tep_get_languages();

                foreach ($languages as $lang) {
                    tep_db_query("insert into orders_status (orders_status_id, language_id, orders_status_name) values ('" . $status_id . "', '" . $lang['id'] . "', 'Stripe SCA [Transactions]')");
                }

                $flags_query = tep_db_query("describe orders_status public_flag");
                if (tep_db_num_rows($flags_query) == 1) {
                    tep_db_query("update orders_status set public_flag = 0 and downloads_flag = 0 where orders_status_id = '" . $status_id . "'");
                }
            } else {
                $check = tep_db_fetch_array($check_query);

                $status_id = $check['orders_status_id'];
            }
        } else {
            $status_id = MODULE_PAYMENT_STRIPE_SCA_TRANSACTION_ORDER_STATUS_ID;
        }


        $params = array('MODULE_PAYMENT_STRIPE_SCA_STATUS' => array('title' => 'Enable Stripe SCA Module',
                'desc' => 'Do you want to accept Stripe v3 payments?',
                'value' => 'True',
                'set_func' => 'tep_cfg_select_option(array(\'True\', \'False\'), '),
            'MODULE_PAYMENT_STRIPE_SCA_TRANSACTION_SERVER' => array('title' => 'Transaction Server',
                'desc' => 'Perform transactions on the production server or on the testing server.',
                'value' => 'Live',
                'set_func' => 'tep_cfg_select_option(array(\'Live\', \'Test\'), '),
            'MODULE_PAYMENT_STRIPE_SCA_LIVE_PUBLISHABLE_KEY' => array('title' => 'Live Publishable API Key',
                'desc' => 'The Stripe account publishable API key to use for production transactions.',
                'value' => ''),
            'MODULE_PAYMENT_STRIPE_SCA_LIVE_SECRET_KEY' => array('title' => 'Live Secret API Key',
                'desc' => 'The Stripe account secret API key to use with the live publishable key.',
                'value' => ''),
            'MODULE_PAYMENT_STRIPE_SCA_LIVE_WEBHOOK_SECRET' => array('title' => 'Live Webhook Signing Secret',
                'desc' => 'The Stripe account live webhook signing secret of the webhook you created to listen for payment_intent.succeeded events.',
                'value' => ''),
            'MODULE_PAYMENT_STRIPE_SCA_LIVE_WEBHOOK_SECRET__KUPOVATI' => array('title' => 'Live Webhook Signing Secret Kupovati.com',
                'desc' => 'The Stripe account live webhook signing secret of the webhook you created to listen for payment_intent.succeeded events.',
                'value' => ''),
            'MODULE_PAYMENT_STRIPE_SCA_LIVE_WEBHOOK_SECRET__GLAMOCANI' => array('title' => 'Live Webhook Signing Secret G-L.com',
                'desc' => 'The Stripe account live webhook signing secret of the webhook you created to listen for payment_intent.succeeded events.',
                'value' => ''),    
            'MODULE_PAYMENT_STRIPE_SCA_TEST_PUBLISHABLE_KEY' => array('title' => 'Test Publishable API Key',
                'desc' => 'The Stripe account publishable API key to use for testing.',
                'value' => ''),
            'MODULE_PAYMENT_STRIPE_SCA_TEST_SECRET_KEY' => array('title' => 'Test Secret API Key',
                'desc' => 'The Stripe account secret API key to use with the test publishable key.',
                'value' => ''),
            'MODULE_PAYMENT_STRIPE_SCA_TEST_WEBHOOK_SECRET' => array('title' => 'Test Webhook Signing Secret',
                'desc' => 'The Stripe account test webhook signing secret of the webhook you created to listen for payment_intent.succeeded events.',
                'value' => ''),
            'MODULE_PAYMENT_STRIPE_SCA_TEST_WEBHOOK_SECRET_KUPOVATI' => array('title' => 'Test Webhook Signing Secret Kupovati.com',
                'desc' => 'The Stripe account test webhook signing secret of the webhook you created to listen for payment_intent.succeeded events.',
                'value' => ''),
            'MODULE_PAYMENT_STRIPE_SCA_TEST_WEBHOOK_SECRET_GLAMOCANI' => array('title' => 'Test Webhook Signing Secret G-L.:com',
                'desc' => 'The Stripe account test webhook signing secret of the webhook you created to listen for payment_intent.succeeded events.',
                'value' => ''),    
            'MODULE_PAYMENT_STRIPE_SCA_TOKENS' => array('title' => 'Create Tokens',
                'desc' => 'Create and store tokens for card payments customers can use on their next purchase?',
                'value' => 'False',
                'set_func' => 'tep_cfg_select_option(array(\'True\', \'False\'), '),
            'MODULE_PAYMENT_STRIPE_SCA_LOG' => array('title' => 'Log Events',
                'desc' => 'Log calls to Sripe functions?',
                'value' => 'False',
                'set_func' => 'tep_cfg_select_option(array(\'True\', \'False\'), '),
            'MODULE_PAYMENT_STRIPE_SCA_TRANSACTION_METHOD' => array('title' => 'Transaction Method',
                'desc' => 'The processing method to use for each transaction.',
                'value' => 'Authorize',
                'set_func' => 'tep_cfg_select_option(array(\'Authorize\', \'Capture\'), '),
            'MODULE_PAYMENT_STRIPE_SCA_PREPARE_ORDER_STATUS_ID' => array('title' => 'Set New Order Status',
                'desc' => 'Set the status of orders created with this payment module to this value',
                'value' => $prepare_status_id,
                'use_func' => 'tep_get_order_status_name',
                'set_func' => 'tep_cfg_pull_down_order_statuses('),
            'MODULE_PAYMENT_STRIPE_SCA_ORDER_STATUS_ID' => array('title' => 'Set Order Processed Status',
                'desc' => 'Set the status of orders successfully processed with this payment module to this value',
                'value' => '0',
                'use_func' => 'tep_get_order_status_name',
                'set_func' => 'tep_cfg_pull_down_order_statuses('),
            'MODULE_PAYMENT_STRIPE_SCA_TRANSACTION_ORDER_STATUS_ID' => array('title' => 'Transaction Order Status',
                'desc' => 'Include transaction information in this order status level',
                'value' => $status_id,
                'set_func' => 'tep_cfg_pull_down_order_statuses(',
                'use_func' => 'tep_get_order_status_name'),
            'MODULE_PAYMENT_STRIPE_SCA_ZONE' => array('title' => 'Payment Zone',
                'desc' => 'If a zone is selected, only enable this payment method for that zone.',
                'value' => '0',
                'use_func' => 'tep_get_zone_class_title',
                'set_func' => 'tep_cfg_pull_down_zone_classes('),
            'MODULE_PAYMENT_STRIPE_SCA_VERIFY_SSL' => array('title' => 'Verify SSL Certificate',
                'desc' => 'Verify gateway server SSL certificate on connection?',
                'value' => 'True',
                'set_func' => 'tep_cfg_select_option(array(\'True\', \'False\'), '),
            'MODULE_PAYMENT_STRIPE_SCA_PROXY' => array('title' => 'Proxy Server',
                'desc' => 'Send API requests through this proxy server. (host:port, eg: 123.45.67.89:8080 or proxy.example.com:8080)'),
            'MODULE_PAYMENT_STRIPE_SCA_DEBUG_EMAIL' => array('title' => 'Debug E-Mail Address',
                'desc' => 'All parameters of an invalid transaction will be sent to this email address.'),
            'MODULE_PAYMENT_STRIPE_SCA_DAYS_DELETE' => array('title' => 'Days waiting to delete Preparing Stripe Orders.',
                'desc' => 'After how many days should unfinished Stripe orders be auto deleted? Leave empty to disable.',
                'value' => '2'),
            'MODULE_PAYMENT_STRIPE_SCA_SORT_ORDER' => array('title' => 'Sort order of display.',
                'desc' => 'Sort order of display. Lowest is displayed first.',
                'value' => '0'));

        return $params;
    }

    function sendTransactionToGateway($url, $parameters = null, $curl_opts = array()) {
        $server = parse_url($url);

        if (isset($server['port']) === false) {
            $server['port'] = ($server['scheme'] == 'https') ? 443 : 80;
        }

        if (isset($server['path']) === false) {
            $server['path'] = '/';
        }

        $header = array('Stripe-Version: ' . $this->api_version,
            'User-Agent: OSCOM ' . tep_get_version());

        if (is_array($parameters) && !empty($parameters)) {
            $post_string = '';

            foreach ($parameters as $key => $value) {
                $post_string .= $key . '=' . urlencode(utf8_encode(trim($value))) . '&';
            }

            $post_string = substr($post_string, 0, -1);

            $parameters = $post_string;
        }

        $curl = curl_init($server['scheme'] . '://' . $server['host'] . $server['path'] . (isset($server['query']) ? '?' . $server['query'] : ''));
        curl_setopt($curl, CURLOPT_PORT, $server['port']);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FORBID_REUSE, true);
        curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($curl, CURLOPT_USERPWD, MODULE_PAYMENT_STRIPE_SCA_TRANSACTION_SERVER == 'Live' ? MODULE_PAYMENT_STRIPE_SCA_LIVE_SECRET_KEY : MODULE_PAYMENT_STRIPE_SCA_TEST_SECRET_KEY . ':');
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

        if (!empty($parameters)) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $parameters);
        }

        if (MODULE_PAYMENT_STRIPE_SCA_VERIFY_SSL == 'True') {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

            if (file_exists(DIR_FS_CATALOG . 'ext/modules/payment/stripe/data/ca-certificates.crt')) {
                curl_setopt($curl, CURLOPT_CAINFO, DIR_FS_CATALOG . 'ext/modules/payment/stripe/data/ca-certificates.crt');
            } elseif (file_exists(DIR_FS_CATALOG . 'includes/cacert.pem')) {
                curl_setopt($curl, CURLOPT_CAINFO, DIR_FS_CATALOG . 'includes/cacert.pem');
            }
        } else {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        }

        if (tep_not_null(MODULE_PAYMENT_STRIPE_SCA_PROXY)) {
            curl_setopt($curl, CURLOPT_HTTPPROXYTUNNEL, true);
            curl_setopt($curl, CURLOPT_PROXY, MODULE_PAYMENT_STRIPE_SCA_PROXY);
        }

        if (!empty($curl_opts)) {
            foreach ($curl_opts as $key => $value) {
                curl_setopt($curl, $key, $value);
            }
        }

        $result = curl_exec($curl);

        curl_close($curl);

        return $result;
    }

    function getTestLinkInfo() {
        $dialog_title = MODULE_PAYMENT_STRIPE_SCA_DIALOG_CONNECTION_TITLE;
        $dialog_button_close = MODULE_PAYMENT_STRIPE_SCA_DIALOG_CONNECTION_BUTTON_CLOSE;
        $dialog_success = MODULE_PAYMENT_STRIPE_SCA_DIALOG_CONNECTION_SUCCESS;
        $dialog_failed = MODULE_PAYMENT_STRIPE_SCA_DIALOG_CONNECTION_FAILED;
        $dialog_error = MODULE_PAYMENT_STRIPE_SCA_DIALOG_CONNECTION_ERROR;
        $dialog_connection_time = MODULE_PAYMENT_STRIPE_SCA_DIALOG_CONNECTION_TIME;

        $test_url = tep_href_link('modules.php', 'set=payment&module=' . $this->code . '&action=install&subaction=conntest');

        $js = <<<EOD
<script>
$(function() {
  $('#tcdprogressbar').progressbar({
    value: false
  });
});

function openTestConnectionDialog() {
  var d = $('<div>').html($('#testConnectionDialog').html()).dialog({
    modal: true,
    title: '{$dialog_title}',
    buttons: {
      '{$dialog_button_close}': function () {
        $(this).dialog('destroy');
      }
    }
  });

  var timeStart = new Date().getTime();

  $.ajax({
    url: '{$test_url}'
  }).done(function(data) {
    if ( data == '1' ) {
      d.find('#testConnectionDialogProgress').html('<p style="font-weight: bold; color: green;">{$dialog_success}</p>');
    } else {
      d.find('#testConnectionDialogProgress').html('<p style="font-weight: bold; color: red;">{$dialog_failed}</p>');
    }
  }).fail(function() {
    d.find('#testConnectionDialogProgress').html('<p style="font-weight: bold; color: red;">{$dialog_error}</p>');
  }).always(function() {
    var timeEnd = new Date().getTime();
    var timeTook = new Date(0, 0, 0, 0, 0, 0, timeEnd-timeStart);

    d.find('#testConnectionDialogProgress').append('<p>{$dialog_connection_time} ' + timeTook.getSeconds() + '.' + timeTook.getMilliseconds() + 's</p>');
  });
}
</script>
EOD;

        $info = '<p><img src="images/icons/locked.gif" border="0">&nbsp;<a href="javascript:openTestConnectionDialog();" style="text-decoration: underline; font-weight: bold;">' . MODULE_PAYMENT_STRIPE_SCA_DIALOG_CONNECTION_LINK_TITLE . '</a></p>' .
                '<div id="testConnectionDialog" style="display: none;"><p>Server:<br />https://api.stripe.com/v3/</p><div id="testConnectionDialogProgress"><p>' . MODULE_PAYMENT_STRIPE_SCA_DIALOG_CONNECTION_GENERAL_TEXT . '</p><div id="tcdprogressbar"></div></div></div>' .
                $js;

        return $info;
    }

    function getTestConnectionResult() {
        $stripe_result = json_decode($this->sendTransactionToGateway('https://api.stripe.com/v3/charges/oscommerce_connection_test'), true);

        if (is_array($stripe_result) && !empty($stripe_result) && isset($stripe_result['error'])) {
            return 1;
        }

        return -1;
    }

    function format_raw($number, $currency_code = '', $currency_value = '') {
        global $currencies, $currency;

        if (empty($currency_code) || !$currencies->is_set($currency_code)) {
            $currency_code = $currency;
        }

        if (empty($currency_value) || !is_numeric($currency_value)) {
            $currency_value = $currencies->currencies[$currency_code]['value'];
        }

        return number_format(tep_round($number * $currency_value, $currencies->currencies[$currency_code]['decimal_places']), 2, '', '');
    }

    function templateClassExists() {
        return class_exists('oscTemplate') && isset($GLOBALS['oscTemplate']) && is_object($GLOBALS['oscTemplate']) && (get_class($GLOBALS['oscTemplate']) == 'oscTemplate');
    }

    function getSubmitCardDetailsJavascript($intent = null) {
        $stripe_publishable_key = MODULE_PAYMENT_STRIPE_SCA_TRANSACTION_SERVER == 'Live' ? MODULE_PAYMENT_STRIPE_SCA_LIVE_PUBLISHABLE_KEY : MODULE_PAYMENT_STRIPE_SCA_TEST_PUBLISHABLE_KEY;
        $intent_url = tep_href_link("ext/modules/payment/stripe_sca/payment_intent.php", '', 'SSL', false, false);
        $js = <<<EOD
<style>
#stripe_table_new_card .card-details {
  background-color: #fff;
  padding: 12px 12px;
  border: 1px solid #ccc;
  border-radius: 4px;
}
#stripe_table_new_card .cardholder {
  padding-left: 15px;
  padding-right: 20px;
}
</style>
<script src="https://js.stripe.com/v3/"></script>
<script>
$(function() {
    $('[name=checkout_confirmation]').attr('id','payment-form');

    var stripe = Stripe('{$stripe_publishable_key}');
    var elements = stripe.elements();

    // Create an instance of the card Element.
    var cardNumberElement = elements.create('cardNumber');
    var cardExpiryElement = elements.create('cardExpiry');
    var cardCvcElement = elements.create('cardCvc');

    // Add an instance of the card Element into the `card-element` <div>.
    cardNumberElement.mount('#card-number');
    cardExpiryElement.mount('#card-expiry');
    cardCvcElement.mount('#card-cvc');

    $('#payment-form').submit(function(event) {
        var \$form = $(this);

        // Disable the submit button to prevent repeated clicks
        \$form.find('button').prop('disabled', true);

        var selected = $("input[name='stripe_card']:checked").val();
        var cc_save = $('[name=card-save]').prop('checked');
        if (typeof selected === 'undefined') {
            selected = 0;
        }
        try {
            if ((selected != null && selected != '0') || cc_save) {
                // update intent to use saved card, then process payment if successful
                updatePaymentIntent(cc_save,selected);
            } else {
                // using new card details without save
                processNewCardPayment();
            }
        } catch ( error ) {
            \$form.find('.payment-errors').text(error);
        }

        // Prevent the form from submitting with the default action
        return false;
    });

    if ( $('#stripe_table').length > 0 ) {
        if ( typeof($('#stripe_table').parent().closest('table').attr('width')) == 'undefined' ) {
          $('#stripe_table').parent().closest('table').attr('width', '100%');
        }

        $('#stripe_table .moduleRowExtra').hide();

        $('#stripe_table_new_card').hide();
        $('#card-number').prop('id','new-card-number');
        $('#card-expiry').prop('id','new-card-expiry');
        $('#card-cvc').prop('id','new-card-cvc');
        $('#save-card-number').prop('id','card-number');
        $('#save-card-expiry').prop('id','card-expiry');
        $('#save-card-cvc').prop('id','card-cvc');

        $('form[name="checkout_confirmation"] input[name="stripe_card"]').change(function() {

            if ( $(this).val() == '0' ) {
                stripeShowNewCardFields();
            } else {
                if ($('#stripe_table_new_card').is(':visible')) {
                    $('#card-number').prop('id','new-card-number');
                    $('#card-expiry').prop('id','new-card-expiry');
                    $('#card-cvc').prop('id','new-card-cvc');
                    $('#save-card-number').prop('id','card-number');
                    $('#save-card-expiry').prop('id','card-expiry');
                    $('#save-card-cvc').prop('id','card-cvc');
                }
                $('#stripe_table_new_card').hide();

            }
            $('tr[id^="stripe_card_"]').removeClass('moduleRowSelected');
            $('#stripe_card_' + $(this).val()).addClass('moduleRowSelected');
            });

        $('form[name="checkout_confirmation"] input[name="stripe_card"]:first').prop('checked', true).trigger('change');

        $('#stripe_table .moduleRow').hover(function() {
            $(this).addClass('moduleRowOver');
        }, function() {
            $(this).removeClass('moduleRowOver');
        }).click(function(event) {
            var target = $(event.target);

            if ( !target.is('input:radio') ) {
                $(this).find('input:radio').each(function() {
                    if ( $(this).prop('checked') == false ) {
                        $(this).prop('checked', true).trigger('change');
                    }
                });
            }
            });
    } else {
        if ( typeof($('#stripe_table_new_card').parent().closest('table').attr('width')) == 'undefined' ) {
            $('#stripe_table_new_card').parent().closest('table').attr('width', '100%');
        }
    }
    function stripeShowNewCardFields() {

        $('#card-number').attr('id','save-card-number');
        $('#card-expiry').attr('id','save-card-expiry');
        $('#card-cvc').attr('id','save-card-cvc');
        $('#new-card-number').attr('id','card-number');
        $('#new-card-expiry').attr('id','card-expiry');
        $('#new-card-cvc').attr('id','card-cvc');
        $('#stripe_table_new_card').show();
    }
    function updatePaymentIntent(cc_save,token){
        // add card save option to payment intent, so card can be saved in webhook
        // or customer/payment method if using saved card
        $.getJSON( "{$intent_url}",{"id":$('#intent_id').val(),
                                    "token":token,
                                    "customer_id": $('#customer_id').val(),
                                    "cc_save": cc_save},
        function( data ) {
            if (data.status == 'ok') {
                var selected = $("input[name='stripe_card']:checked"). val();

                if (selected == null || selected == '0') {
                    processNewCardPayment();
                } else {
                    processSavedCardPayment(data.payment_method);
                }
            } else {
                var \$form = $('#payment-form');
                \$form.find('button').prop('disabled', false);
                $('#card-errors').text(data.error);
            }
        });
    }
    function processNewCardPayment() {
        stripe.handleCardPayment(
            $('#secret').val(), cardNumberElement, {
              payment_method_data: {
                billing_details: {
                    name: $('#cardholder-name').val(),
                    address: {
                        city: $('#address_city').val(),
                        line1: $('#address_line1').val(),
                        postal_code: $('#address_zip').val(),
                        state: $('#address_state').val(),
                        country: $('#address_country').val()
                    },
                    email: $('#email_address').val()
                }
              }
            }
        ).then(function(result) {
            stripeResponseHandler(result);
        });
    }
    function processSavedCardPayment(payment_method_id) {
        stripe.handleCardPayment(
            $('#secret').val(),
            {
              payment_method: payment_method_id
            }
        ).then(function(result) {
            stripeResponseHandler(result);
        });
    }
    function stripeResponseHandler(result) {
        var \$form = $('#payment-form');
        if (result.error) {
            $('#card-errors').text(result.error.message);
            \$form.find('button').prop('disabled', false);
        } else {
            $('#card-errors').text('Processing');

            // Insert the token into the form so it gets submitted to the server
            \$form.append($('<input type="hidden" name="stripeIntentId" />').val(result.paymentIntent.id));
            // and submit
            \$form.get(0).submit();
        }
    }
});
</script>
EOD;

        return $js;
    }

    function sendDebugEmail($response = array()) {
        if (tep_not_null(MODULE_PAYMENT_STRIPE_SCA_DEBUG_EMAIL)) {
            $email_body = '';

            if (!empty($response)) {
                $email_body .= 'RESPONSE:' . "\n\n" . print_r($response, true) . "\n\n";
            }

            if (!empty($_POST)) {
                $email_body .= '$_POST:' . "\n\n" . print_r($_POST, true) . "\n\n";
            }

            if (!empty($_GET)) {
                $email_body .= '$_GET:' . "\n\n" . print_r($_GET, true) . "\n\n";
            }

            if (!empty($email_body)) {
                tep_mail('', MODULE_PAYMENT_STRIPE_SCA_DEBUG_EMAIL, 'Stripe Debug E-Mail', trim($email_body), STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);
            }
        }
    }

    function deleteCard($card, $customer, $token_id) {
        global $customer_id;

        $secret_key = MODULE_PAYMENT_STRIPE_SCA_TRANSACTION_SERVER == 'Live' ? MODULE_PAYMENT_STRIPE_SCA_LIVE_SECRET_KEY : MODULE_PAYMENT_STRIPE_SCA_TEST_SECRET_KEY;
        \Stripe\Stripe::setApiKey($secret_key);
        \Stripe\Stripe::setApiVersion($this->apiVersion);
        $error = '';
        $payment_method = \Stripe\PaymentMethod::retrieve($card);
        try {
            $result = $payment_method->detach();
        } catch (exception $err) {
            // just log error, and continue to delete card from table
            $error = $err->getMessage();
        }

        $this->event_log($customer_id, "deleteCard", $payment_method, $error);

        if (!is_object($result) || !isset($result['object']) || ($result['object'] != 'payment_method')) {
            $this->sendDebugEmail($result . PHP_EOL . $error);
        }

        tep_db_query("delete from customers_stripe_tokens where id = '" . (int) $token_id . "' and customers_id = '" . (int) $customer_id . "' and stripe_token = '" . tep_db_input(tep_db_prepare_input($customer . ':|:' . $card)) . "'");

        return (tep_db_affected_rows() === 1);
    }

}
