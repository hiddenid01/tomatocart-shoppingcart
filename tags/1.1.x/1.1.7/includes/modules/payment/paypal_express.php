<?php
/*
  $Id: paypal_express.php $
  TomatoCart Open Source Shopping Cart Solutions
  http://www.tomatocart.com

  Copyright (c) 2009 Wuxi Elootec Technology Co., Ltd

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License v2 (1991)
  as published by the Free Software Foundation.
*/

  class osC_Payment_paypal_express extends osC_Payment {
    var $_title,
        $_code = 'paypal_express',
        $_status = false,
        $_sort_order,
        $_order_id;
        
    function osC_Payment_paypal_express() {
      global $osC_Database, $osC_Language, $osC_ShoppingCart;
      
      $osC_Language->load('modules-payment');
      
      $this->_title = $osC_Language->get('payment_paypal_express_title');
      $this->_method_title = $osC_Language->get('payment_paypal_express_method_title');
      $this->_status = (MODULE_PAYMENT_PAYPAL_EXPRESS_STATUS == '1') ? true : false;
      $this->_sort_order = MODULE_PAYMENT_PAYPAL_EXPRESS_SORT_ORDER;
      
      $this->api_version = '60.0';

      switch (MODULE_PAYMENT_PAYPAL_EXPRESS_TRANSACTION_SERVER) {
        case 'Live':
          $this->api_url = 'https://api-3t.paypal.com/nvp';
          $this->paypal_url = 'https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout';
          break;

        default:
          $this->api_url = 'https://api-3t.sandbox.paypal.com/nvp';
          $this->paypal_url = 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_express-checkout';
          break;
      }

      if ($this->_status === true) {
        if ((int)MODULE_PAYMENT_PAYPAL_EXPRESS_ORDER_STATUS_ID > 0) {
          $this->order_status = MODULE_PAYMENT_PAYPAL_EXPRESS_ORDER_STATUS_ID;
        }

        if ((int)MODULE_PAYMENT_PAYPAL_EXPRESS_ZONE > 0) {
          $check_flag = false;

          $Qcheck = $osC_Database->query('select zone_id from :table_zones_to_geo_zones where geo_zone_id = :geo_zone_id and zone_country_id = :zone_country_id order by zone_id');
          $Qcheck->bindTable(':table_zones_to_geo_zones', TABLE_ZONES_TO_GEO_ZONES);
          $Qcheck->bindInt(':geo_zone_id', MODULE_PAYMENT_PAYPAL_EXPRESS_ZONE);
          $Qcheck->bindInt(':zone_country_id', $osC_ShoppingCart->getBillingAddress('country_id'));
          $Qcheck->execute();

          while ($Qcheck->next()) {
            if ($Qcheck->valueInt('zone_id') < 1) {
              $check_flag = true;
              break;
            } elseif ($Qcheck->valueInt('zone_id') == $osC_ShoppingCart->getBillingAddress('zone_id')) {
              $check_flag = true;
              break;
            }
          }

          if ($check_flag == false) {
            $this->_status = false;
          }
        }
      }
    }
      
    function checkout_initialization_method() {
      global $osC_ShoppingCart, $osC_Currencies, $osC_Language;
      
      if (MODULE_PAYMENT_PAYPAL_EXPRESS_CHECKOUT_IMAGE == 'Dynamic') {
        if (MODULE_PAYMENT_PAYPAL_EXPRESS_TRANSACTION_SERVER == 'Live') {
          $image_button = 'https://fpdbs.paypal.com/dynamicimageweb?cmd=_dynamic-image';
        } else {
          $image_button = 'https://fpdbs.sandbox.paypal.com/dynamicimageweb?cmd=_dynamic-image';
        }

        $params = array('locale=' . $osC_Language->get('payment_paypal_express_language_locale'));

        if (osc_not_null(MODULE_PAYMENT_PAYPAL_EXPRESS_API_USERNAME)) {
          $response_array = $this->getPalDetails();

          if (isset($response_array['PAL'])) {
            $params[] = 'pal=' . $response_array['PAL'];
            $params[] = 'ordertotal=' . $osC_Currencies->formatRaw($osC_ShoppingCart->getTotal());
          }
        }

        if (!empty($params)) {
          $image_button .= '&' . implode('&', $params);
        }
      } else {
        $image_button = $osC_Language->get('payment_paypal_express_button');
      }

      $string = '<a href="' . osc_href_link(FILENAME_CHECKOUT, 'callback&module=paypal_express',  'NOSSL', false) . '"><img src="' . $image_button . '" border="0" alt="' . osc_output_string_protected($osC_Language->get('payment_paypal_express_text_button')) . '" title="' . osc_output_string_protected($osC_Language->get('payment_paypal_express_text_button')) . '" /></a>';

      return $string;
    }
    
    function selection() {
      return array('id' => $this->_code,
                   'module' => $this->_method_title);
    }
    
    function process() {
      global $osC_ShoppingCart, $osC_Currencies, $messageStack, $osC_Language, $osC_Database;
      
      if (!isset($_SESSION['ppe_token'])) {
        osc_redirect(osc_href_link(FILENAME_CHECKOUT, 'callback&module=paypal_express', 'NOSSL', false));
      }
      
      $params = array('TOKEN' => $_SESSION['ppe_token'],
                      'PAYERID' => $_SESSION['ppe_payerid'],
                      'AMT' => $osC_Currencies->formatRaw($osC_ShoppingCart->getTotal()),
                      'CURRENCYCODE' => $osC_Currencies->getCode());
      
      if ($osC_ShoppingCart->hasShippingAddress()) {
        $params['SHIPTONAME'] = $osC_ShoppingCart->getShippingAddress('firstname') . ' ' . $osC_ShoppingCart->getShippingAddress('lastname');
        $params['SHIPTOSTREET'] = $osC_ShoppingCart->getShippingAddress('street_address');
        $params['SHIPTOCITY'] = $osC_ShoppingCart->getShippingAddress('city');
        $params['SHIPTOSTATE'] = $osC_ShoppingCart->getShippingAddress('zone_code');
        $params['SHIPTOZIP'] = $osC_ShoppingCart->getShippingAddress('postcode');
        $params['SHIPTOCOUNTRYCODE'] = $osC_ShoppingCart->getShippingAddress('country_iso_code_2');
      }
      
      $response_array = $this->doExpressCheckoutPayment($params);
      
      if (($response_array['ACK'] != 'Success') && ($response_array['ACK'] != 'SuccessWithWarning')) {
        $messageStack->add_session('shopping_cart', $osC_Language->get('payment_paypal_express_error_title') . ' <strong>' . stripslashes($response_array['L_LONGMESSAGE0']) . '</strong>');
        
        osc_redirect(osc_href_link(FILENAME_CHECKOUT, '', 'SSL'));
      }else {
        $orders_id = osC_Order::insert();
        
        osC_Order::process($orders_id, $this->order_status);
        
        $pp_result = 'Payer Status: ' . osc_output_string_protected($ppe_payerstatus) . "\n" .
                     'Address Status: ' . osc_output_string_protected($ppe_addressstatus) . "\n\n" .
                     'Payment Status: ' . osc_output_string_protected($response_array['PAYMENTSTATUS']) . "\n" .
                     'Payment Type: ' . osc_output_string_protected($response_array['PAYMENTTYPE']) . "\n" .
                     'Pending Reason: ' . osc_output_string_protected($response_array['PENDINGREASON']) . "\n" .
                     'Reversal Code: ' . osc_output_string_protected($response_array['REASONCODE']);
        
        $Qstatus = $osC_Database->query('insert into :table_orders_status_history (orders_id, orders_status_id, date_added, customer_notified, comments) values (:orders_id, :orders_status_id, now(), :customer_notified, :comments)');
        $Qstatus->bindTable(':table_orders_status_history', TABLE_ORDERS_STATUS_HISTORY);
        $Qstatus->bindInt(':orders_id', $insert_id);
        $Qstatus->bindInt(':orders_status_id', MODULE_PAYMENT_PAYPAL_EXPRESS_TRANSACTIONS_ORDER_STATUS_ID);
        $Qstatus->bindInt(':customer_notified', '0');
        $Qstatus->bindValue(':comments', $pp_result);
        $Qstatus->execute();
        
        $Qstatus->freeResult();
      }
      
      unset($_SESSION['ppe_token']);
      unset($_SESSION['ppe_payerid']);
      unset($_SESSION['ppe_payerstatus']);
      unset($_SESSION['ppe_addressstatus']);
    }
    
    function callback() {
      global $osC_Database, $osC_ShoppingCart, $osC_Currencies;
      
      if (!$osC_ShoppingCart->hasContents()) {
        osc_redirect(osc_href_link(FILENAME_CHECKOUT, '', 'SSL'));
      }
      
      $params = array('VERSION' => $this->api_version);
      
      if (osc_not_null(MODULE_PAYMENT_PAYPAL_EXPRESS_API_USERNAME)) {
        $params['USER'] = MODULE_PAYMENT_PAYPAL_EXPRESS_API_USERNAME;
        $params['PWD'] = MODULE_PAYMENT_PAYPAL_EXPRESS_API_PASSWORD;
        $params['SIGNATURE'] = MODULE_PAYMENT_PAYPAL_EXPRESS_API_SIGNATURE;
      }else {
        $params['SUBJECT'] = MODULE_PAYMENT_PAYPAL_EXPRESS_SELLER_ACCOUNT;
      }
      
      switch($_GET['express_action']) {
        case 'cancel':
          if (isset($_SESSION['ppe_token'])) {
            unset($_SESSION['ppe_token']);
          }

          osc_redirect(osc_href_link(FILENAME_CHECKOUT, '', 'SSL'));
          
          break;
          
        case 'retrieve':
          self::_get_express_checkout_details($params);
          
          break;
          
        default:
          self::_set_express_checkout($params);
          
          break;
      }
      
      exit;
    }
    
    function doExpressCheckoutPayment($params) {
      $params['VERSION'] = $this->api_version;
      $params['METHOD'] = 'DoExpressCheckoutPayment';
      $params['PAYMENTACTION'] = ((MODULE_PAYMENT_PAYPAL_EXPRESS_TRANSACTION_METHOD == 'Sale') || (!osc_not_null(MODULE_PAYMENT_PAYPAL_EXPRESS_API_USERNAME))) ? 'Sale' : 'Authorization';
      $params['BUTTONSOURCE'] = 'TomatoCart1.1.7_Default_EC';
      
      if (osc_not_null(MODULE_PAYMENT_PAYPAL_EXPRESS_API_USERNAME)) {
        $params['USER'] = MODULE_PAYMENT_PAYPAL_EXPRESS_API_USERNAME;
        $params['PWD'] = MODULE_PAYMENT_PAYPAL_EXPRESS_API_PASSWORD;
        $params['SIGNATURE'] = MODULE_PAYMENT_PAYPAL_EXPRESS_API_SIGNATURE;
      }else {
        $params['SUBJECT'] = MODULE_PAYMENT_PAYPAL_EXPRESS_SELLER_ACCOUNT;
      }
      
      $post_string = '';
      
      foreach ($params as $key => $value) {
        $post_string .= $key . '=' . urlencode(utf8_encode(trim($value))) . '&';
      }
      
      $post_string = substr($post_string, 0, -1);
      
      $response = $this->sendTransactionToGateway($this->api_url, $post_string);
      $response_array = array();
      parse_str($response, $response_array);
      
      return $response_array;
    }
    
    function _get_express_checkout_details($params) {
      global $osC_ShoppingCart, $osC_Currencies, $osC_Language, $osC_Database, $osC_Tax, $messageStack, $osC_Customer, $osC_Session;
      
      // if there is nothing in the customers cart, redirect them to the shopping cart page
      if (!$osC_ShoppingCart->hasContents()) {
        osc_redirect(osc_href_link(FILENAME_CHECKOUT, '', 'SSL', true, true, true));
      }
      
      $params['VERSION'] = $this->api_version;
      $params['METHOD'] = 'GetExpressCheckoutDetails';
      $params['TOKEN'] = $_GET['token'];
      
      $post_string = '';
      foreach ($params as $key => $value) {
        $post_string .= $key . '=' . urlencode(utf8_encode(trim($value))) . '&';
      }
      $post_string = substr($post_string, 0, -1);
      
      $response = $this->sendTransactionToGateway($this->api_url, $post_string);
     
      $response_array = array();
      parse_str($response, $response_array);
      
      if (($response_array['ACK'] == 'Success') || ($response_array['ACK'] == 'SuccessWithWarning')) {
        $force_login = false;
        
        // Begin: check if e-mail address exists in database and login or create customer account
        if ($osC_Customer->isLoggedOn() == false) {
          $force_login = true;
          
          if (class_exists('osC_Account') == false) {
            require_once('includes/classes/account.php');
          }
          
          $email_address = $response_array['EMAIL'];
          
          $Qcheck = $osC_Database->query('select * from :table_customers where customers_email_address = :email_address limit 1');
          $Qcheck->bindTable(':table_customers', TABLE_CUSTOMERS);
          $Qcheck->bindValue(':email_address', $email_address);
          $Qcheck->execute();
          
          if ($Qcheck->numberOfRows() > 0) {
            $check = $Qcheck->toArray();
            
            $customer_id = $check['customers_id'];
            $osC_Customer->setCustomerData($customer_id);
          }else {
            $data = array('firstname' => $response_array['FIRSTNAME'], 
                          'lastname' => $response_array['LASTNAME'], 
                          'email_address' => $email_address, 
                          'password' => osc_rand(ACCOUNT_PASSWORD, max(ACCOUNT_PASSWORD, 8)));
            
            osC_Account::createEntry($data);
          }
          $Qcheck->freeResult();
          
          if (SERVICE_SESSION_REGENERATE_ID == '1') {
            $osC_Session->recreate();
          }
        }
        // End: check if e-mail address exists in database and login or create customer account
        
        // Begin: Add shipping and billing address from paypal to the shopping cart
        if ($force_login == true) {
          $country_query = $osC_Database->query('select countries_id, countries_name, countries_iso_code_2, countries_iso_code_3, address_format from :table_countries where countries_iso_code_2 = :country_iso_code_2');
          $country_query->bindTable(':table_countries', TABLE_COUNTRIES);
          $country_query->bindValue(':country_iso_code_2', $response_array['SHIPTOCOUNTRYCODE']);
          $country_query->execute();
          
          $country = $country_query->toArray();
          
          $zone_name = $response_array['SHIPTOSTATE'];
          $zone_id = 0;
          
          $zone_query = $osC_Database->query('select zone_id, zone_name from :table_zones where zone_country_id = :zone_country_id and zone_code = :zone_code');
          $zone_query->bindTable(':table_zones', TABLE_ZONES);
          $zone_query->bindInt(':zone_country_id', $country['countries_id']);
          $zone_query->bindValue(':zone_code', $response_array['SHIPTOSTATE']);
          $zone_query->execute();
          
          if ($zone_query->numberOfRows()) {
            $zone = $zone_query->toArray();
            $zone_name = $zone['zone_name'];
            $zone_id = $zone['zone_id'];
          }
          
          $sendto = array('firstname' => substr($response_array['SHIPTONAME'], 0, strpos($response_array['SHIPTONAME'], ' ')),
                          'lastname' => substr($response_array['SHIPTONAME'], strpos($response_array['SHIPTONAME'], ' ')+1),
                          'company' => '',
                          'street_address' => $response_array['SHIPTOSTREET'],
                          'suburb' => '',
                          'email_address' => $response_array['EMAIL'],
                          'postcode' => $response_array['SHIPTOZIP'],
                          'city' => $response_array['SHIPTOCITY'],
                          'zone_id' => $zone_id,
                          'zone_name' => $zone_name,
                          'country_id' => $country['countries_id'],
                          'country_name' => $country['countries_name'],
                          'country_iso_code_2' => $country['countries_iso_code_2'],
                          'country_iso_code_3' => $country['countries_iso_code_3'],
                          'address_format_id' => ($country['address_format_id'] > 0 ? $country['address_format_id'] : '1'));
          
          $osC_ShoppingCart->setRawShippingAddress($sendto);
          $osC_ShoppingCart->setRawBillingAddress($sendto);
          $osC_ShoppingCart->setBillingMethod(array('id' => $this->getCode(), 'title' => $this->getMethodTitle()));
        }
        // End: Add shipping and billing address from paypal to the shopping cart
        
        //Begin: Add the shipping 
        if ($osC_ShoppingCart->getContentType() != 'virtual') {
          if ($osC_ShoppingCart->hasShippingMethod() === false) {
            if (class_exists('osC_Shipping') === false) {
              include_once('includes/classes/shipping.php');
            }
            $osC_Shipping = new osC_Shipping();
          
            if ($osC_Shipping->hasQuotes()) {
              $shipping_set = false;
              // get all available shipping quotes
              $quotes = $osC_Shipping->getQuotes();
            
              if (isset($response_array['SHIPPINGOPTIONNAME']) && isset($response_array['SHIPPINGOPTIONAMOUNT'])) {
                foreach($quotes as $quote) {
                  if (!isset($quote['error'])) {
                    foreach($quote['methods'] as $rate) {
                      if ($response_array['SHIPPINGOPTIONNAME'] == $quote['module'] . ' (' . $rate['title'] . ')') {
                        if ($response_array['SHIPPINGOPTIONAMOUNT'] == $osC_Currencies->formatRaw($rate['cost'] + $osC_Currencies->addTaxRateToPrice($rate['cost'], $quote['tax']))) {
                          $shipping = $quote['id'] . '_' . $rate['id'];
                          $module = 'osC_Shipping_' . $quote['module'];
                        
                          if (is_object($GLOBALS[$module]) && $GLOBALS[$module]->isEnabled()) {
                            $quote = $osC_Shipping->getQuote($shipping);
                          
                            if (isset($quote['error'])) {
                              $osC_ShoppingCart->resetShippingMethod();
                            
                              $errors[] = $quote['error'];
                            } else {
                              $osC_ShoppingCart->setShippingMethod($quote);
                            
                              $shipping_set = true;
                            }
                          }else {
                            $osC_ShoppingCart->resetShippingMethod();
                          }
                          break 2;
                        }
                      }
                    }
                  }
                }
              }
            
              if ($shipping_set == false) {
                // select cheapest shipping method
                $osC_ShoppingCart->setShippingMethod($osC_Shipping->getCheapestQuote());
              }
            }
          }
        
          if (!isset($_SESSION['ppe_token'])) {
            $_SESSION['ppe_token'] = $response_array['TOKEN'];
          }
        
          if (!isset($_SESSION['ppe_payerid'])) {
            $_SESSION['ppe_payerid'] = $response_array['PAYERID'];
          }
        
          if (!isset($_SESSION['ppe_payerstatus'])) {
            $_SESSION['ppe_payerstatus'] = $response_array['PAYERSTATUS'];
          }
        
          if (!isset($_SESSION['ppe_addressstatus'])) {
            $_SESSION['ppe_addressstatus'] = $response_array['ADDRESSSTATUS'];
          }
        
          osc_redirect(osc_href_link(FILENAME_CHECKOUT, 'process', 'SSL'));
        }
      }else {
        $messageStack->add_session('shopping_cart', $osC_Language->get('payment_paypal_express_error_title') . ' <strong>' . stripslashes($response_array['L_LONGMESSAGE0']) . '</strong>');
        
        osc_redirect(osc_href_link(FILENAME_CHECKOUT, '', 'SSL'));
      }
    }
    
    function _set_express_checkout($params) {
      global $osC_ShoppingCart, $osC_Currencies, $osC_Language, $osC_Tax, $messageStack;
      
      // if there is nothing in the customers cart, redirect them to the shopping cart page
      if (!$osC_ShoppingCart->hasContents()) {
        osc_redirect(osc_href_link(FILENAME_CHECKOUT, '', 'NONSSL', true, true, true));
      }
      
      $params['METHOD'] = 'SetExpressCheckout';
      $params['PAYMENTACTION'] = ((MODULE_PAYMENT_PAYPAL_EXPRESS_TRANSACTION_METHOD == 'Sale') || (!osc_not_null(MODULE_PAYMENT_PAYPAL_EXPRESS_API_USERNAME))) ? 'Sale' : 'Authorization';
      $params['RETURNURL'] = HTTPS_SERVER . DIR_WS_HTTPS_CATALOG . FILENAME_CHECKOUT . '?callback&module=paypal_express&express_action=retrieve';
      $params['CANCELURL'] = HTTPS_SERVER . DIR_WS_HTTPS_CATALOG . FILENAME_CHECKOUT . '?callback&module=paypal_express&express_action=cancel';
      $params['CURRENCYCODE'] = $osC_Currencies->getCode();
      
      $line_item_no = 0;
      $items_total = 0;
      $tax_total = 0;
      
      if ($osC_ShoppingCart->hasContents()) {
        foreach($osC_ShoppingCart->getProducts() as $product) {
          $product_name = $product['name'];
          
        //gift certificate
          if ($product['type'] == PRODUCT_TYPE_GIFT_CERTIFICATE) {
            $product_name .= "\n" . ' - ' . $osC_Language->get('senders_name') . ': ' . $product['gc_data']['senders_name'];

            if ($product['gc_data']['type'] == GIFT_CERTIFICATE_TYPE_EMAIL) {
              $product_name .= "\n" . ' - ' . $osC_Language->get('senders_email')  . ': ' . $product['gc_data']['senders_email'];
            }

            $product_name .= "\n" . ' - ' . $osC_Language->get('recipients_name') . ': ' . $product['gc_data']['recipients_name'];

            if ($product['gc_data']['type'] == GIFT_CERTIFICATE_TYPE_EMAIL) {
              $product_name .= "\n" . ' - ' . $osC_Language->get('recipients_email')  . ': ' . $product['gc_data']['recipients_email'];
            }

            $product_name .= "\n" . ' - ' . $osC_Language->get('message')  . ': ' . $product['gc_data']['message'];
          }

          if ($osC_ShoppingCart->hasVariants($product['id'])) {
            foreach ($osC_ShoppingCart->getVariants($product['id']) as $variant) {
              $product_name .= ' - ' . $variant['groups_name'] . ': ' . $variant['values_name'];
            }
          }
          
          $product_tax = $osC_Tax->getTaxRate($product['tax_class_id'], $osC_ShoppingCart->getTaxingAddress('country_id'), $osC_ShoppingCart->getTaxingAddress('zone_id'));
          
          $params['L_NAME' . $line_item_no] = $product_name;
          $params['L_AMT' . $line_item_no] = $osC_Currencies->formatRaw($product['final_price']);
          $params['L_NUMBER' . $line_item_no] = $product['id'];
          $params['L_QTY' . $line_item_no] = $product['quantity'];
          $params['L_TAXAMT' . $line_item_no] = $osC_Currencies->formatRaw($product_tax);
          
          $tax_total += $osC_Currencies->formatRaw($product_tax) * $product['quantity'];
          $items_total += $osC_Currencies->formatRaw($product['final_price']) * $product['quantity'];
          
          $line_item_no++;
        }
      }
      
      $params['ITEMAMT'] = $items_total;
      $params['TAXAMT'] = $tax_total;

      if ($osC_ShoppingCart->hasShippingAddress()) {
        $params['ADDROVERRIDE'] = '1';
        $params['SHIPTONAME'] = $osC_ShoppingCart->getShippingAddress('firstname') . ' ' . $osC_ShoppingCart->getShippingAddress('lastname');
        $params['SHIPTOSTREET'] = $osC_ShoppingCart->getShippingAddress('street_address');
        $params['SHIPTOCITY'] = $osC_ShoppingCart->getShippingAddress('city');
        $params['SHIPTOSTATE'] = $osC_ShoppingCart->getShippingAddress('zone_code');
        $params['SHIPTOCOUNTRYCODE'] = $osC_ShoppingCart->getShippingAddress('country_iso_code_2');
        $params['SHIPTOZIP'] = $osC_ShoppingCart->getShippingAddress('postcode');
      }
      
      //Begin: handle the shipping
      $quotes_array = array();
      
      if ($osC_ShoppingCart->getContentType() != 'virtual') {
        if (class_exists('osC_Shipping') === false) {
          include_once('includes/classes/shipping.php');
        }
      
        $osC_Shipping = new osC_Shipping();
      
        if ($osC_Shipping->hasQuotes()) {
          // get all available shipping quotes
          $quotes = $osC_Shipping->getQuotes();
          
          foreach($quotes as $quote) {
            if (!isset($quote['error'])) {
              foreach($quote['methods'] as $rate) {
                $quotes_array[] = array('id' => $quote['id'] . '_' . $rate['id'],
                                        'name' => $quote['module'],
                                        'label' => $rate['title'],
                                        'cost' => $rate['cost'],
                                        'tax' => $quote['tax_class_id']);
              }
            }
          }
        }
      }
     
      $counter = 0;
      $cheapest_rate = null;
      $expensive_rate = 0;
      $cheapest_counter = $counter;
      $default_shipping = null;
      
      foreach($quotes_array as $quote) {
        $shipping_rate = $osC_Currencies->formatRaw($quote['cost'] + $osC_Currencies->addTaxRateToPrice($quote['cost'], $quote['tax']));
        
        $shipping_tax = $quote['cost'] * ($osC_Tax->getTaxRate($quote['tax'], $osC_ShoppingCart->getTaxingAddress('country_id'), $osC_ShoppingCart->getTaxingAddress('zone_id')) / 100);

        if (DISPLAY_PRICE_WITH_TAX == '1') {
          $shipping_rate = $quote['cost'];
        } else {
          $shipping_rate = $quote['cost'] + $shipping_tax;
        }
        
        $params['L_SHIPPINGOPTIONNAME' . $counter] = $quote['name'] . ' (' . $quote['label'] . ')';
        $params['L_SHIPINGPOPTIONLABEL' . $counter] = $quote['name'] . ' (' . $quote['label'] . ')';
        $params['L_SHIPPINGOPTIONAMOUNT' . $counter] = $shipping_rate;
        $params['L_SHIPPINGOPTIONISDEFAULT' . $counter] = 'false';
        
        if (is_null($cheapest_rate) || ($shipping_rate < $cheapest_rate)) {
          $cheapest_rate = $shipping_rate;
          $cheapest_counter = $counter;
        }
        
        if ($shipping_rate > $expensive_rate) {
          $expensive_rate = $shipping_rate;
        }
        
        if ($osC_ShoppingCart->getShippingMethod('id') == $quote['id']) {
          $default_shipping = $counter;
        }
        
        $counter++;
      }
      
      if (!is_null($default_shipping)) {
        $cheapest_rate = $params['L_SHIPPINGOPTIONAMOUNT' . $default_shipping];
        $cheapest_counter = $default_shipping;
      } 
      
      if (!is_null($cheapest_rate)) {
        $params['INSURANCEOPTIONSOFFERED'] = 'false';
        $params['L_SHIPPINGOPTIONISDEFAULT' . $cheapest_counter] = 'true';
      }
      //End: handle shipping
      
      $params['SHIPPINGAMT'] = $osC_Currencies->formatRaw($cheapest_rate, '', 1);
      $params['AMT'] = $osC_Currencies->formatRaw($params['ITEMAMT'] + $params['TAXAMT'] + $params['SHIPPINGAMT'], '', 1);
      $params['MAXAMT'] = $osC_Currencies->formatRaw($params['AMT'] + $expensive_rate + 100, '', 1);// safely pad higher for dynamic shipping rates (eg, USPS express)
      
      //call the setExpressCheckout api     
      $post_string = '';
      foreach ($params as $key => $value) {
        $post_string .= $key . '=' . urlencode(utf8_encode(trim($value))) . '&';
      }
      $post_string = substr($post_string, 0, -1);
      
      $response = $this->sendTransactionToGateway($this->api_url, $post_string);
      
      $response_array = array();
      parse_str($response, $response_array);
      
      if (($response_array['ACK'] == 'Success') || ($response_array['ACK'] == 'SuccessWithWarning')) {
        osc_redirect($this->paypal_url . '&token=' . $response_array['TOKEN'] . '&useraction=commit');
      } else {
        $messageStack->add_session('checkout', $osC_Language->get('payment_paypal_express_error_title') . ' <strong>' . stripslashes($response_array['L_LONGMESSAGE0']) . '</strong>');
        
        osc_redirect(osc_href_link(FILENAME_CHECKOUT, 'checkout', 'SSL'));
      }
    }
    
    function getPalDetails() {
      $params = array('VERSION' => $this->api_version,
                      'METHOD' => 'GetPalDetails',
                      'USER' => MODULE_PAYMENT_PAYPAL_EXPRESS_API_USERNAME,
                      'PWD' => MODULE_PAYMENT_PAYPAL_EXPRESS_API_PASSWORD,
                      'SIGNATURE' => MODULE_PAYMENT_PAYPAL_EXPRESS_API_SIGNATURE);
      
      $post_string = '';

      foreach ($params as $key => $value) {
        $post_string .= $key . '=' . urlencode(utf8_encode(trim($value))) . '&';
      }

      $post_string = substr($post_string, 0, -1);
      
      $response = $this->sendTransactionToGateway($this->api_url, $post_string);
      $response_array = array();
      parse_str($response, $response_array);
      
      return $response_array;
    }
  }
?>