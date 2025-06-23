<?php
class Controllerextensionpaymentpinepg extends Controller {
	
  
  public function index() {
    $this->load->language('extension/payment/pinepg');
	$this->load->model('extension/payment/pinepg');
	$this->logger = new Log('pinepg_'. date("Y-m-d").'.log');
    $this->load->model('checkout/order');
	$this->load->model('catalog/product');
	$Order_Id=$this->session->data['order_id'];
    $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);


	// Fetch product details
    $products = $this->cart->getProducts();
    $product_details = [];

    foreach ($products as $product) {
        $product_info = $this->model_catalog_product->getProduct($product['product_id']);
        $product_details[] = [
            'product_id'    => $product['product_id'],
            'name'          => $product['name'],
            'quantity'      => $product['quantity'],
            'price'         => $this->currency->format($product['price'], $order_info['currency_code'], $order_info['currency_value'], false),
            'total'         => $this->currency->format($product['total'], $order_info['currency_code'], $order_info['currency_value'], false),
            'model'         => $product_info['model'] ?? '',
            'sku'           => $product_info['sku'] ?? '',
        ];
    }

    $order_info['products'] = $product_details;
	// Fetch product details

// Only add coupon discount if it exists	
$coupon_discount = 0;
if (isset($this->session->data['coupon'])) {
    $this->load->model('extension/total/coupon');
    $coupon_info = $this->model_extension_total_coupon->getCoupon($this->session->data['coupon']);
    
    if ($coupon_info && !empty($coupon_info['discount'])) {
        $coupon_discount = abs($coupon_info['discount']); // Ensure it's positive
    }
}


if ($coupon_discount > 0) {
    $order_info['cart_coupon_discount_amount'] = $coupon_discount;
}

// Only add coupon discount if it exists

    
    $this->logger->write('[Order ID]:' . $Order_Id.'  Order Info: ' . serialize($order_info));
    
    if ($order_info) {
		
		
		$payment_response = $this->initiatePayment($order_info);

		$order_id_from_order_api=$payment_response['order_id'];

		if(!empty($order_id_from_order_api)){

			$this->model_extension_payment_pinepg->saveOrderMetadata($Order_Id, $order_id_from_order_api);

		}
		


		$this->logger->write('Redirect url is'. $payment_response['redirect_url']);

		if (!empty($payment_response['redirect_url'])) {
            $data['action'] =$payment_response['redirect_url'];

			$parsed_url = parse_url($payment_response['redirect_url']);
			parse_str($parsed_url['query'], $query_params);
            $token = isset($query_params['token']) ? $query_params['token'] : null;
			$data['token'] =$token;

        } else {
            $this->session->data['error'] = 'Payment initiation failed: ' . $payment_response['message'];
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }


		$data['button_confirm'] = $this->language->get('button_confirm');

	

		if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . 'extension/payment/pinepg')){
			return $this->load->view($this->config->get('config_template') . 'extension/payment/pinepg', $data);
		} else {
			return $this->load->view('extension/payment/pinepg', $data);
		}
}
  }



  private function initiatePayment($order_info) {

	$this->logger->write('Inside Initiate Payment method');
	
		$callback_url = $this->getCallbackUrl();
	
			$PinePgMode=$this->config->get('payment_pinepg_mode');
			if($PinePgMode == "live")
			{
				$url ='https://api.pluralpay.in/api/checkout/v1/orders';
			}else{
				$url ='https://pluraluat.v2.pinepg.in/api/checkout/v1/orders';
			}
	
		$access_token = $this->getAccessToken();
		if (!$access_token) {
			return ['response_code' => 500, 'message' => 'Access token retrieval failed'];
		}
	
		$orderamount= $order_info['total'] * 100;
	
		if (is_numeric($orderamount) && floor($orderamount) != $orderamount) {
			$orderamount = ceil($orderamount);
		}
	
	
		
	
		// Get ordered products and replicate as per quantity
		$products = [];
		$productPriceTotal=0;
		foreach ($order_info['products'] as $product) {
			
			$productPrice=intval($product['price'] * 100);
			$quantity=$product['quantity'];
			
	
			for ($j = 0; $j < $quantity; $j++) {
				if(!empty($product['sku'])){
				$productData = [
					'product_code' => $product['sku'],
					'product_amount' => [
						'value' => $productPrice,
						'currency' => 'INR',
					],
				];
			
				$productPriceTotal=$productPriceTotal+$productPrice;
				$products[] = $productData;
			}
			
			}
		}
	
		if($orderamount>$productPriceTotal){
	
			$additional_charge=$orderamount-$productPriceTotal;
	
			$productData = [
				'product_code' => 'additional_charge',
				'product_amount' => [
					'value' => $additional_charge,
					'currency' => 'INR',
				],
			];
			$products[] = $productData;
		}
	
		if($productPriceTotal>$orderamount){
	
			$orderamount=$productPriceTotal;
		}
	
		$billing_address=$this->truncateAddress($order_info['payment_address_1']);
		$shipping_address=$this->truncateAddress($order_info['shipping_address_1']);
	
		$body = [
			'merchant_order_reference' => $order_info['order_id'] . '_' . date('ymdHis'),
			'order_amount' => [
				'value' => $orderamount,
				'currency' => 'INR',
			],
			'callback_url' => $callback_url,
			'pre_auth' => false,
			  'integration_mode'=> "REDIRECT",
			  "plugin_data"=> [
					"plugin_type" => "Opencart",
					"plugin_version" => "V3"
			  ],
			'purchase_details' => [
				'customer' => [
					'email_id' => $order_info['email'],
					'first_name' => $order_info['firstname'],
					'last_name' => $order_info['lastname'],
					//'customer_id' => $order_info['customer_id'],
					'mobile_number' => $order_info['telephone'],
				],
				'billing_address' => [
					'address1' => $billing_address,
					'pincode' => $order_info['payment_postcode'],
					'city' => $order_info['payment_city'],
					'state' => $order_info['payment_zone'],
					'country' => $order_info['payment_iso_code_2'],
				],
				'shipping_address' => [
					'address1' => $shipping_address,
					'pincode' => $order_info['shipping_postcode'],
					'city' => $order_info['shipping_city'],
					'state' => $order_info['shipping_zone'],
					'country' => $order_info['shipping_iso_code_2'],
				],
				'products' => $products
			],
		];
	
	
		// Add coupon discount only if it exists and is greater than zero
	if (!empty($order_info['cart_coupon_discount_amount']) && $order_info['cart_coupon_discount_amount'] > 0) {
		$body['cart_coupon_discount_amount'] = [
			'value' => $order_info['cart_coupon_discount_amount']*100,
			'currency' => 'INR',
		];
	}
	
	$body=json_encode($body);
	
		$merchant_id=$this->config->get('payment_pinepg_merchantid');
	
	
		$this->logger->write('V3 request log'.$body);
	
		$headers = [
			'Merchant-ID: ' . $merchant_id,
			'Authorization: Bearer ' . $access_token,
			'Content-Type: application/json',
		];
	
		$response = $this->sendPostRequest($url, $body, $headers);
		return json_decode($response, true);
	}


	
public function truncateAddress($address) {
    return (strlen($address) > 100) ? substr($address, 0, 100) : $address;
}


public function getCallbackUrl() {
    // Check if running locally
    if (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) {
        // Local environment
        return 'http://localhost/opencart/index.php?route=extension/payment/pinepg/callback';
    }

    // Production or staging environment
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $domain = $protocol . $_SERVER['HTTP_HOST'];
    
    return $domain . '/index.php?route=extension/payment/pinepg/callback';
}



private function getAccessToken() {

	$PinePgMode=$this->config->get('payment_pinepg_mode');
	if($PinePgMode == "live")
	{
		$url ='https://api.pluralpay.in/api/auth/v1/token';
	}else{
		$url ='https://pluraluat.v2.pinepg.in/api/auth/v1/token';
	}

    $access_code= $this->config->get('payment_pinepg_access_code');
	$secret_key= $this->config->get('payment_pinepg_secure_secret');


	$body = json_encode([
		'client_id' => $access_code,
		'client_secret' => $secret_key,
		'grant_type' => 'client_credentials'
	]);

	$response = $this->sendPostRequest($url, $body, ['Content-Type: application/json','Accept: application/json']);
	$data = json_decode($response, true);

	

	return $data['access_token'] ?? null;
}


public function sendPostRequest($url, $body, $headers) {
    $curl = curl_init($url);

    // Configure cURL options
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $body); // JSON-encode the body
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); // Set headers
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Expect response
	// Disable SSL certificate verification
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);

    // Execute and capture the response
    $response = curl_exec($curl);

    // Check for cURL errors
    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new Exception("cURL error: " . $error);
    }

    // Get HTTP response code
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    // Close cURL session
    curl_close($curl);

    

    // Check for HTTP errors
    if ($httpCode >= 400) {
        //throw new Exception("HTTP error $httpCode: " . $response);
    }

    return $response;
}


public function onOrderHistoryAdd($route, $args, $output) {

    $order_id=$args[0];
    $this->load->model('checkout/order');
	$this->load->model('catalog/product');
    
	$order_info = $this->model_checkout_order->getOrder($order_id);

	$this->logger = new Log('pinepg_'. date("Y-m-d").'.log');
    

    
    $this->logger->write('Order Data'. serialize($order_info));
	if($order_info['order_status']=='Refunded'){
      $payment_response = $this->process_refund($order_info);
	}
	
	
}



public function process_refund($order_info) {
	

	// Retrieve the Edge order ID from custom field or metadata
	$this->load->model('extension/payment/pinepg');
	$refund_order_id = $this->model_extension_payment_pinepg->getCheckoutOrderId($order_info['order_id']);

	// Prepare API request URL
	$PinePgMode=$this->config->get('payment_pinepg_mode');
	if($PinePgMode == "live")
	{
		$url ='https://api.pluralpay.in/api/pay/v1/refunds/' . $refund_order_id;
	}else{
		$url ='https://pluraluat.v2.pinepg.in/api/pay/v1/refunds/' . $refund_order_id;
	}



	$orderamount= $order_info['total'] * 100;

	if (is_numeric($orderamount) && floor($orderamount) != $orderamount) {
		$orderamount = ceil($orderamount);
	}

	// Prepare the request payload
	$body = json_encode([
		'merchant_order_reference' => uniqid(),
		'refund_amount' => ['value' => $orderamount, 'currency' => 'INR'],
		'merchant_metadata' => ['key1' => 'DD', 'key2' => 'XOF'],
		'refund_reason' => 'Initiated by merchant',
	]);

	$merchant_id=$this->config->get('payment_pinepg_merchantid');

	// Set up headers
	$access_token = $this->getAccessToken();

	$headers = [
		'Merchant-ID: ' . $merchant_id,
		'Authorization: Bearer ' . $access_token,
		'Content-Type: application/json',
	];

	// Make the API call
	$response = $this->sendPostRequest($url, $body, $headers);

$data = json_decode($response);
$status = $data->data->status ?? null;
$order_id = $data->data->order_id ?? null;
$parent_order_id = $data->data->parent_order_id ?? null;

	// Handle the response
	if ($status === 'PROCESSED') {
		// Add order history note for successful refund
		    $this->load->model('checkout/order');
			$Order_Status='11';
			$comment='Refund successfull for order id : '.$order_info['order_id'].' ,Pinelabs Payment ID for this order is : '.$parent_order_id.' and Pinelabs Refund ID for this order is : '.$order_id;
		    $this->model_checkout_order->addOrderHistory($order_info['order_id'], $Order_Status,$comment,false,false);
			$this->logger = new Log('pinepg_'. date("Y-m-d").'.log');
		    $this->logger->write('Refund Data Success'. serialize($response));

		//return 'Refund successful';
	} else {
		//return 'Refund fail';

		$this->logger = new Log('pinepg_'. date("Y-m-d").'.log');
		$this->logger->write('Refund Data Fail'. serialize($response));
		
	}
}
  
  public function Hex2String($hex){
            $string='';
            for ($i=0; $i < strlen($hex)-1; $i+=2){
                $string .= chr(hexdec($hex[$i].$hex[$i+1]));
            }
            return $string;
        }
		

		public function callback() 
{
    $this->load->model('extension/payment/pinepg');
    $this->logger = new Log('pinepg_'. date("Y-m-d").'.log');
    $this->logger->write('Callback() called with POST data: ' . print_r($_POST, true));

    $signature = $_POST['signature'];
    $order_id_from_api = $_POST['order_id'];
    $status = $_POST['status'];

    // Get OpenCart order ID from our metadata table
    $opencart_order_id = $this->model_extension_payment_pinepg->getOpenCartOrderId($order_id_from_api);
    
    if (!$opencart_order_id) {
        $this->logger->write("Error: No OpenCart order found for PinePG order: $order_id_from_api");
        $this->response->redirect($this->url->link('checkout/failure'));
        return;
    }

    // Verify payment status with retries (3 attempts, 20 seconds apart)
    $verified_status = $this->verifyPaymentStatusWithRetry($order_id_from_api);
    
    if ($verified_status === 'PROCESSED') {
        $this->handleSuccessfulPayment($opencart_order_id, $order_id_from_api);
    } else {
        $this->handleFailedPayment($opencart_order_id, $order_id_from_api, $verified_status);
    }
}

private function verifyPaymentStatusWithRetry($pinepg_order_id)
{
    $max_retries = 3;
    $retry_delay = 20; // seconds
    
    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        $this->logger->write("Enquiry API attempt $attempt for order: $pinepg_order_id");
        
        $response = $this->callEnquiryApi($pinepg_order_id);
        
        if ($response && isset($response['data']['status'])) {
            $status = $response['data']['status'];
            $this->logger->write("Enquiry API response status: $status");
            
            if ($status === 'PROCESSED') {
                return $status;
            }
        }
        
        // If not final attempt, wait before retrying
        if ($attempt < $max_retries) {
            sleep($retry_delay);
        }
    }
    
    return $status ?? 'UNKNOWN';
}

private function callEnquiryApi($pinepg_order_id)
{
    $PinePgMode = $this->config->get('payment_pinepg_mode');
    $url = ($PinePgMode == "live") 
        ? 'https://api.pluralpay.in/api/pay/v1/orders/' . $pinepg_order_id
        : 'https://pluraluat.v2.pinepg.in/api/pay/v1/orders/' . $pinepg_order_id;

    $access_token = $this->getAccessToken();
    if (!$access_token) {
        $this->logger->write("Error: Failed to get access token");
        return false;
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
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $this->logger->write("CURL Error: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    
    if ($http_code == 200) {
        return json_decode($response, true);
    }
    
    $this->logger->write("Enquiry API failed. HTTP Code: $http_code, Response: $response");
    return false;
}

private function handleSuccessfulPayment($opencart_order_id, $pinepg_order_id)
{
    $this->load->model('checkout/order');
    
    // Update order status
    $Order_Status = '2'; // Processing
    $comment = 'Payment verified and successful. PinePG ID: ' . $pinepg_order_id;
    $this->model_checkout_order->addOrderHistory($opencart_order_id, $Order_Status, $comment, true, false);

    // Set only the essential session data for success page
    $amount = $this->model_extension_payment_pinepg->getOpenCartAmount($opencart_order_id);
    
    

	$this->session->data = array_merge($this->session->data, [
        'ppc_Amount' => $amount * 100,
        'Order_No' => $opencart_order_id,
        'ppc_PinePGTransactionID' => $pinepg_order_id,
        'ppc_UniqueMerchantTxnID' => $pinepg_order_id,
        
        // Initialize all EMI-related variables with empty values
        'ppc_Is_BrandEMITransaction' => '',
        'ppc_IssuerName' => '',
        'ppc_EMIInterestRatePercent' => '',
        'ppc_EMIAmountPayableEachMonth' => '',
        'ppc_EMITotalDiscCashBackPercent' => '',
        'ppc_EMITotalDiscCashBackAmt' => '',
        'ppc_EMITenureMonth' => '',
        'ppc_EMICashBackType' => '',
        'ppc_EMIAdditionalCashBack' => ''
    ]);

    $this->logger->write("Successfully processed order $opencart_order_id with PinePG ID $pinepg_order_id");
    $this->response->redirect($this->url->link('extension/payment/pinepgsuccess'));
}

private function handleFailedPayment($opencart_order_id, $pinepg_order_id, $status)
{
    $this->load->model('checkout/order');
    
    $Order_Status = '10'; // Failed
    $comment = "Payment failed. Status: $status. PinePG ID: $pinepg_order_id";
    $this->model_checkout_order->addOrderHistory($opencart_order_id, $Order_Status, $comment, true, false);

    $this->logger->write("Payment failed for order $opencart_order_id. Status: $status");
    $this->response->redirect($this->url->link('checkout/failure'));
}
		
}
?>