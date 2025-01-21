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

	$body = json_encode([
		'merchant_order_reference' => $order_info['order_id'] . '_' . date('ymdHis'),
		'order_amount' => [
			'value' => $orderamount,
			'currency' => 'INR',
		],
		'callback_url' => $callback_url,
		'purchase_details' => [
			'customer' => [
				'email_id' => $order_info['email'],
				'first_name' => $order_info['firstname'],
				'last_name' => $order_info['lastname'],
				//'customer_id' => $order_info['customer_id'],
				'mobile_number' => $order_info['telephone'],
			],
		],
	]);

	$merchant_id=$this->config->get('payment_pinepg_merchantid');

	$headers = [
		'Merchant-ID: ' . $merchant_id,
		'Authorization: Bearer ' . $access_token,
		'Content-Type: application/json',
	];

	$response = $this->sendPostRequest($url, $body, $headers);
	return json_decode($response, true);
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
		$this->logger->write('Callback() called');

		$signature=$_POST['signature'];
		$order_id_from_api=$_POST['order_id'];
		$status=$_POST['status'];

		if($status=='PROCESSED' && !empty($order_id_from_api)){

			$this->logger->write("Received callback with order_id_from_api: $order_id_from_api, status: $status");

			 // Query for the OpenCart order ID using order_id_from_order_api
			 $opencart_order_id = $this->model_extension_payment_pinepg->getOpenCartOrderId($order_id_from_api);

			 $this->load->model('checkout/order');
			 $Order_Status='2';
			 $comment='Payment Transation is successful. Pinelabs Payment ID: '.$order_id_from_api;
			 $this->model_checkout_order->addOrderHistory($opencart_order_id, $Order_Status,$comment,true,false);

			 $amount = $this->model_extension_payment_pinepg->getOpenCartAmount($opencart_order_id);


			            $this->session->data['ppc_Amount']=$amount*100;
						$this->session->data['Order_No']= $opencart_order_id;
						$this->session->data['ppc_PinePGTransactionID']=$order_id_from_api;
						$this->session->data['ppc_Is_BrandEMITransaction']=null;
						$this->session->data['ppc_IssuerName']=null;
						$this->session->data['ppc_EMIInterestRatePercent']=null;
						$this->session->data['ppc_EMIAmountPayableEachMonth']=null; 
						$this->session->data['ppc_EMITotalDiscCashBackPercent']=null;
						$this->session->data['ppc_EMITotalDiscCashBackAmt']=null;
						$this->session->data['ppc_EMITenureMonth']=null;
					    $this->session->data['ppc_EMICashBackType']=null;
						$this->session->data['ppc_EMIAdditionalCashBack']=null;
						$this->session->data['ppc_UniqueMerchantTxnID']=$order_id_from_api;

						

			 $this->response->redirect($this->url->link('extension/payment/pinepgsuccess', 'path=59'));

		}else{


			if(!empty($opencart_order_id)){
			$opencart_order_id = $this->model_extension_payment_pinepg->getOpenCartOrderId($order_id_from_api);
			$this->load->model('checkout/order');
			$Order_Status='10';
			$comment='Payment Failed. Pinelabs Payment ID: '.$order_id_from_api;
			$this->model_checkout_order->addOrderHistory($opencart_order_id, $Order_Status,$comment,true,false);

			}
			

			$this->response->redirect($this->url->link('checkout/failure', 'path=59'));

			
		}




  }
   
  
}
?>