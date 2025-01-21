<?php
class ModelExtensionPaymentPinePG extends Model {

  
  public function getMethod($address, $total) {
    $this->load->language('extension/payment/pinepg');
  
    $method_data = array(
      'code'     => 'pinepg',
      'title'    => $this->language->get('text_title'),
      'sort_order' => $this->config->get('custom_sort_order'),
	  'terms'=>''
    );
  
    return $method_data;
  }


  public function saveOrderMetadata($order_id, $order_id_from_api) {
    $this->db->query("UPDATE `" . DB_PREFIX . "order` SET `order_id_from_order_api` = '" . $this->db->escape($order_id_from_api) . "' WHERE `order_id` = '" . (int)$order_id . "'");
}


public function getOpenCartOrderId($order_id_from_api) {
  $query = $this->db->query("SELECT `order_id` FROM `" . DB_PREFIX . "order` WHERE `order_id_from_order_api` = '" . $this->db->escape($order_id_from_api) . "'");
  if ($query->num_rows > 0) {
      return $query->row['order_id'];
  }
  return null; // Return null if no matching record is found
}

public function getCheckoutOrderId($order_id) {
  $query = $this->db->query("SELECT `order_id_from_order_api` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . $this->db->escape($order_id) . "'");
  if ($query->num_rows > 0) {
      return $query->row['order_id_from_order_api'];
  }
  return null; // Return null if no matching record is found
}





public function getOpenCartAmount($order_id_from_api) {
  $query = $this->db->query("SELECT `total` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . $this->db->escape($order_id_from_api) . "'");
  if ($query->num_rows > 0) {
      return $query->row['total'];
  }
  return null; // Return null if no matching record is found
}


}