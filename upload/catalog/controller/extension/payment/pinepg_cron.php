<?php
class ControllerExtensionPaymentPinepgCron extends Controller {
    private $logger;
    
    public function __construct($registry) {
        parent::__construct($registry);
        $this->logger = new Log('pinepg_cron_' . date("Y-m-d") . '.log');
    }
    
    public function index() {
        // Security check - only allow cron execution
        // TEMPORARY: Disable security check for local testing
    // if (php_sapi_name() !== 'cli' && (empty($this->request->get['cron_key']) || 
    //     $this->request->get['cron_key'] !== $this->config->get('payment_pinepg_cron_key'))) {
    //     die('Unauthorized access');
    // }

    if (php_sapi_name() !== 'cli') {
        $this->logger->write('Unauthorized web access attempt');
        die('Cron can only be executed via command line');
    }

        $this->load->model('extension/payment/pinepg');
        $this->load->model('checkout/order');
        
        // Get pending orders from today
        $pending_orders = $this->model_extension_payment_pinepg->getPendingOrdersToday();
        
        $this->logger->write('Starting PinePG cron job. Found ' . count($pending_orders) . ' pending orders');
        
        foreach ($pending_orders as $order) {
            try {
                $this->processOrder($order);
            } catch (Exception $e) {
                $this->logger->write('Error processing order ' . $order['order_id'] . ': ' . $e->getMessage());
            }
        }
        
        $this->logger->write('Cron job completed');
    }
    
    private function processOrder($order) {
        $this->logger->write('Processing order ID: ' . $order['order_id'] . 
                           ', PinePG ID: ' . $order['order_id_from_order_api']);
        
        // Call enquiry API
        $response = $this->callEnquiryApi($order['order_id_from_order_api']);
        
        if (!$response || !isset($response['data']['status'])) {
            $this->logger->write('Invalid API response for order ' . $order['order_id']);
            return;
        }
        
        $status = $response['data']['status'];
        $this->logger->write('Order ' . $order['order_id'] . ' status: ' . $status);
        
        if ($status === 'PROCESSED') {
            // Update order status to processing
            $this->model_checkout_order->addOrderHistory(
                $order['order_id'],
                2, // Processing status
                'Payment verified via cron job. PinePG ID: ' . $order['order_id_from_order_api'],
                true
            );
            
            $this->logger->write('Successfully updated order ' . $order['order_id'] . ' to PROCESSED');
        }
    }
    
    private function callEnquiryApi($pinepg_order_id) {
        $PinePgMode = $this->config->get('payment_pinepg_mode');
        $url = ($PinePgMode == "live") 
            ? 'https://api.pluralpay.in/api/pay/v1/orders/' . $pinepg_order_id
            : 'https://pluraluat.v2.pinepg.in/api/pay/v1/orders/' . $pinepg_order_id;

        $access_token = $this->getAccessToken();
        if (!$access_token) {
            throw new Exception('Failed to get access token');
        }

        $headers = [
            'Authorization: Bearer ' . $access_token,
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('CURL Error: ' . curl_error($ch));
        }
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            throw new Exception('API request failed with HTTP code: ' . $http_code);
        }
        
        return json_decode($response, true);
    }
    
    private function getAccessToken() {
        $PinePgMode = $this->config->get('payment_pinepg_mode');
        $url = ($PinePgMode == "live") 
            ? 'https://api.pluralpay.in/api/auth/v1/token'
            : 'https://pluraluat.v2.pinepg.in/api/auth/v1/token';

        $body = json_encode([
            'client_id' => $this->config->get('payment_pinepg_access_code'),
            'client_secret' => $this->config->get('payment_pinepg_secure_secret'),
            'grant_type' => 'client_credentials'
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        $data = json_decode($response, true);
        
        return $data['access_token'] ?? false;
    }
}