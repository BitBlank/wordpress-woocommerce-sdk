<?php

/**
 * Plugin Name: WooCommerce B.app Gateway
 * Plugin URI: https://b.app/
 * Description: B.app操作簡單，掃一掃即可完成支付，免礦工費，支持大額支付
 * Version: 1.0.0
 * Author: B.app
 * Author URI: https://b.app/
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'init_woocommerce_gateway_bapp');

function init_woocommerce_gateway_bapp()
{

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class Bapp_WC_Payment_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'bapp';
            $this->icon = 'https://cdn.fwtqo.cn/static/img/20190613_48.png';
            $this->has_fields = true;
            $this->method_title = 'B.app支付';
            $this->method_description = 'B.app操作簡單，掃一掃即可完成支付，免礦工費，支持大額支付';
            $this->supports = array(
                'products'
            );

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');

            if (is_admin()) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            }
            // http://site_url/?wc-api=bapp_wc_payment_gateway
            add_action('woocommerce_api_' . strtolower(get_class($this)), array(&$this, 'handle_callback'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'app_key' => array(
                    'title' => 'App Key',
                    'type' => 'text',
                    'default' => '',
                    'description' => 'AppKey可到B.app商戶後台(https://mch.b.app)的「商戶信息」中查看',
                    'desc_tip' => true,
                ),
                'app_secret' => array(
                    'title' => 'App Secret',
                    'type' => 'text',
                    'default' => '',
                    'description' => 'AppSecret可到B.app商戶後台(https://mch.b.app)的「商戶信息」中查看',
                    'desc_tip' => true,
                )
            );
        }

        public function payment_fields()
        {
            echo '<p>使用比特幣付款</p>';
        }

        public function get_client_ip()
        {
            if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            } else {
                $ip = $_SERVER['REMOTE_ADDR'];
            }
            return $ip;
        }

        public function get_sign($appSecret, $orderParam)
        {
            $signOriginStr = '';
            ksort($orderParam);
            foreach ($orderParam as $key => $value) {
                if (empty($key) || $key == 'sign') {
                    continue;
                }
                $signOriginStr = $signOriginStr . $key . "=" . $value . "&";
            }
            return strtolower(md5($signOriginStr . "app_secret=" . $appSecret));
        }

        public function http_request($url, $method = 'GET', $params = array())
        {
            $curl = curl_init();
            if ($method == 'POST') {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_HEADER, false);
                curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
                $jsonStr = json_encode($params);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonStr);
            } else if ($method == 'GET') {
                $url = $url . "?" . http_build_query($params, '', '&');
            }
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_TIMEOUT, 60);
            $output = curl_exec($curl);
            if (curl_errno($curl) > 0) {
                return array();
            }
            curl_close($curl);
            $json = json_decode($output, true);
            return $json;
        }

        public function process_payment($order_id)
        {
            global $woocommerce;

            $order = new WC_Order($order_id);
            $notify_url = get_site_url() . '/?wc-api=' . strtolower(get_class($this));
            $reqParam = array(
                'order_id' => $order_id,
                'amount' => (int)($order->order_total * 100),
                'body' => 'WP-' . $order_id,
                'notify_url' => $notify_url,
                'return_url' => $this->get_return_url($order),
                'extra' => '',
                'order_ip' => $this->get_client_ip(),
                'amount_type' => get_woocommerce_currency(),
                'time' => time() * 1000,
                'app_key' => $this->get_option('app_key')
            );
            $sign = $this->get_sign($this->get_option('app_secret'), $reqParam);
            $reqParam['sign'] = $sign;

            $res = $this->http_request('https://bapi.app/api/v2/pay', 'POST', $reqParam);
            if ($res && $res['code'] == 200) {
                return array(
                    'result' => 'success',
                    'redirect' => $res['data']['pay_url'],
                );
            }

            $errMsg = '[Bapp-err]:Unknown network error';
            if ($res) {
                $errMsg = '[Bapp-err-' . $res['code'] . ']:' . $res['msg'];
            }
            wc_add_notice($errMsg, 'error');
            return array(
                'result' => 'failure',
                'redirect' => ''
            );

        }

        public function handle_callback()
        {
            $jsonStr = file_get_contents('php://input');
            $notifyData = (array)json_decode($jsonStr);
            $calcSign = $this->get_sign($this->get_option('app_secret'), $notifyData);
            if ($calcSign != $notifyData['sign']) {
                echo 'SIGN ERROR';
                die();
            }
            if ($notifyData['order_state'] != 1) {
                echo 'ORDER STATE ERROR';
                die();
            }
            $order = wc_get_order($notifyData['order_id']);
            $order->payment_complete();
            echo 'SUCCESS';
            die();
        }

    }

    function add_gateway_class($gateways)
    {
        $gateways[] = 'Bapp_WC_Payment_Gateway';
        return $gateways;
    }

    add_filter('woocommerce_payment_gateways', 'add_gateway_class');

}
