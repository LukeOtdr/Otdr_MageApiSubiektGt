<?php
namespace Otdr\MageApiSubiektGt\Cron;

use Otdr\MageApiSubiektGt\Helper\SubiektApi;
use Exception;

class OrderSend extends CronObject
{


   public function __construct(\Otdr\MageApiSubiektGt\Helper\Config $config,\Psr\Log\LoggerInterface $logger, \Magento\Framework\App\State $appState ){
      parent::__construct($config,$logger,$appState);
   }


   protected function getOrdersIds(){
         $connection = $this->resource->getConnection();
         $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
         $query = 'SELECT id_order FROM '.$tableName.' WHERE is_locked = 0 AND gt_order_sent = 0';
         $result = $connection->fetchAll($query);
         return $result;
   }

   protected function updateOrderStatus($id_order,$order_reference){
      $connection = $this->resource->getConnection();
      $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
      $dml = "UPDATE {$tableName} SET gt_order_sent = 1, gt_order_ref =  '{$order_reference}', upd_date = NOW() WHERE id_order = '{$id_order}'";
      $connection->query($dml);
      $this->setStatus($id_order,'Zamówienie przesłane nr <b>'.$order_reference."</b>",$this->subiekt_api_order_status);
   }


   public function removeFromDb($id_order){
      $this->deletePdf($id_order);
      $connection = $this->resource->getConnection();
      $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
      $dml = "DELETE FROM {$tableName}  WHERE id_order = '{$id_order}'";
      $connection->query($dml);
      $this->addLog($id_order,'Całkowite usunięcie zamówienia z bazy');
   }

   public function execute()
   {

      parent::execute();

      $subiektApi = new SubiektApi($this->api_key,$this->end_point);
      $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

      $orders_to_send = $this->getOrdersIds();
      foreach($orders_to_send as $order){
         $id_order = $order['id_order'];

         $this->ordersProcessed++;
         print("Sending order no \"{$order['id_order']}\": ");

         //checking is processed by another
         if(true == intval($this->getProcessingData($id_order,'gt_order_sent'))){
            print("skipped - processed\n");
            continue;
         }
         /* Locking order for processing */
         $this->lockOrder($id_order);

         /*getting order data*/
         $order_data = $this->getOrderData($id_order);

         /* check order status */
         //var_dump($order_data->getStatus());
         if($order_data->getStatus() == 'closed' || $order_data->getStatus() == 'canceled'){
             $this->removeFromDb($id_order);
             print("order closed - remove from processing\n");
             continue;
         }

         if($order_data->getStatus() != 'pending' && $order_data->getStatus() != 'pending_payment' && $order_data->getStatus() != 'processing'){
            $this->unlockOrder($id_order);
            print ("skipped\n");
            continue;
         }



         /* Bulding order array */
         $payment = $order_data->getPayment()->getData();
         $shipping = $order_data->getShippingDescription();
         $comments_list =  $order_data->getAllStatusHistory();
         $comments = array();
         foreach($comments_list as $comment){
               $comments[] = $comment->getData('comment');
         }
         //if($count)
         $comments = mb_substr(implode(" ",$comments),0,255);

         //TODO: setting
         $order_json[$id_order] = array(
                           'create_product_if_not_exists'    => $this->subiekt_api_newproducts,
                           'amount' =>$payment['amount_ordered'],
                           'reference' =>  trim($this->subiekt_api_prefix.' '. $id_order),
                           'pay_type' => 'transfer',
                           'comments' => trim('Doręczyciel: '.$shipping.(isset($payment['additional_information']['method_title'])?', płatność: '.strip_tags($payment['additional_information']['method_title']):'').". ".$comments)
                           );



         /* Bulding customer array */
         $customer = $order_data->getBillingAddress()->getData();
         $order_json[$id_order]['customer'] = array(
                                                      'firstname'    => $customer['firstname'],
                                                      'lastname'     => $customer['lastname'],
                                                      'email'        => $customer['email'],
                                                      'address'      => $customer['street'],
                                                      'address_no'   => '',
                                                      'city'         => $customer['city'],
                                                      'post_code'    => $customer['postcode'],
                                                      'phone'        => $customer['telephone'],
                                                      'ref_id'       => trim($this->subiekt_api_prefix.'CS '.$customer['entity_id']),
                                                      'is_company'   => strlen($customer['vat_id'])>0?true:false,
                                                      'company_name' => $customer['company'],
                                                      'tax_id'       => $customer['vat_id'],
                                             );


         /* Bulidnig ordered products array */
         $products = $order_data->getAllItems();

         $products_array = array();

         foreach($products as $product){

            if ($product->getParentItem()){continue;}

            $productObject = $objectManager->create('\Magento\Catalog\Model\Product')->load($product->getProductId());

            $code = $this->subiekt_api_ean_attrib!=""?$productObject->{"get{$this->subiekt_api_ean_attrib}"}():$product->getSku();
            if(empty($code)){
               $code = $this->subiekt_api_prefix.$product->getId();
            }

           //print_r($product->getProductType());
            $price = $product->getPriceInclTax();
            //if()
            $products_array[$code] =  array(
                                          'name'   =>                      (!is_null($productObject) && !empty($productObject->getName()))?$productObject->getName():$product->getName(),
                                          'price'  =>                      $price-$product->getDiscountAmount(),
                                          'qty'    =>                      intval($product->getQtyOrdered()),
                                          'price_before_discount' =>       $price,
                                          'code'   =>                      $code,
                                          'time_of_delivery'   =>          2,
                                          //'net_price'          => true,
                                          'supplier_code'    => $productObject->getSupplierReference(),
                                          'id_store' => $this->subiekt_api_warehouse_id
                                       );
         }
         //var_dump($products_array);
         $order_json[$id_order]['products'] = $products_array;

         /* Shippment information */
        if($order_data->getShippingAmount()>0){
            $a_sp = array(
                  'ean'=>$this->subiekt_api_trans_symbol,
                  'code'=>$this->subiekt_api_trans_symbol,
                  'qty'=> 1,
                  'price' => $order_data->getShippingAmount()+$order_data->getShippingTaxAmount()+$order_data->getPaymentSurchargeAmount()-$order_data->getShippingDiscountAmount(),
                  'price_before_discount' => $order_data->getShippingAmount()+$order_data->getShippingTaxAmount()+$order_data->getPaymentSurchargeAmount(),
                  'name' => 'Koszty wysyłki',
                  'id_store' => $this->subiekt_api_warehouse_id,
            );
            $order_json[$id_order]['products'][$this->subiekt_api_trans_symbol] = $a_sp;
         }

         //print_r($order_json[$id_order]);
         /* Sending order data to SubiektGt API */
         $result = $subiektApi->call('order/add',$order_json[$id_order]);
         if(!$result){
            $this->addErrorLog($id_order,'Can\'t connect to API check configuration!');
            $this->unlockOrder($id_order);
            print("Error: {$result}\n");
            return false;

         }
         if($result['state'] == 'fail'){
            $this->addErrorLog($id_order,$result['message']);
            $this->unlockOrder($id_order);
            print("Error: {$result['message']}\n");
            continue;
         }


         /* unlocking order after processing */
         $this->unlockOrder($id_order);

         /* Update order processing status */
         $this->updateOrderStatus($id_order,$result['data']['order_ref']);

         /* Update products qty on magento */
         $result = $subiektApi->call('product/getqtysbycode',array('products_qtys'=>$order_json[$id_order]['products']));
         if($result['state'] == 'success'){
            $product_log = "<br/>Status produktów:\n<br/>";
            foreach($result['data'] as $ean13 => $pd){
               if(is_array($pd)){
                  if($this->subiekt_api_trans_symbol != $ean13){
                     $product_log .= "{$ean13}:".($pd['available']>0?"<b style=\"color:green;\">{$pd['available']}</b>":"<b style=\"color:red;\">{$pd['available']}</b>")."\n<br/>";
                  }
               }
            }
            $this->addLog($id_order,$product_log);
         }
         print("OK\n");

      }

      return true;
   }

}
