<?php
class ModelExtensionPaymentPinePG extends Model {

  public function install() {

		 // Check if the column exists before attempting to add it
         $query = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "order` LIKE 'order_id_from_order_api'");
         if ($query->num_rows == 0) {
             $this->db->query("ALTER TABLE `" . DB_PREFIX . "order` ADD `order_id_from_order_api` VARCHAR(255) NULL");
             $this->log->write("Column `order_id_from_order_api` successfully added.");
         } else {
             //do nothingh
         }
	}


}