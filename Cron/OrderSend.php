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

   protected function getOrder($id_order)
   {
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
        $query = "SELECT id_order, gt_order_ref,gt_sell_doc_ref,gt_order_sent,gt_sell_doc_request,upd_date FROM {$tableName} WHERE id_order = '{$id_order}' AND is_locked = 0";
        $result = $connection->fetchAll($query);
        if(isset($result[0])){
            return $result[0];
        }
        return false;   
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

         $order = $this->getOrder($order['id_order']);
         if(!$order){
               $this->unlockOrder($id_order);
               print ("skipped - in progress \n");
               continue;  
         }
         

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
         if($order_data->getStatus() == 'canceled' || $order_data->getStatus() == 'closed'){
             $this->removeFromDb($id_order);
             print("order closed - remove from processing\n");
             continue;
         }

         $o_status = $order_data->getStatus();
         if($o_status != 'pending' && $o_status != 'pending_payment' && $o_status != 'processing'){
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
         if(count($comments)>0)
         {
            $comments = mb_substr($comments[0],0,255);
         }else{
            $comments = '';
         }

         //------START ---------------- Payments setting
         $pay_type = 'transfer';
         $pay_method = $payment['method'];

         $pay_point_id = 0;
         $to_send_flag = 0;

         
         if(false !== ($id = array_search($pay_method,$this->subiekt_api_payments_transfer)))
         {
            $pay_type = 'transfer';
            $pay_point_id = 0;
            $to_send_flag = 1;

         }elseif(false !== ($id = array_search($pay_method, $this->subiekt_api_payments_cart)))
         {
             $pay_type = 'cart';
             $pay_point_id = $this->subiekt_api_payments_cart_subiekt[$id];
             $to_send_flag = 1;             

         }elseif($pay_method == 'cashondelivery')
         {
            $pay_type = 'loan';

         }
         //------END---------------- Payments setting

         $order_json[$id_order] = array(
                           'create_product_if_not_exists'    => $this->subiekt_api_newproducts,
                           'amount' =>$payment['amount_ordered'],
                           'reference' =>  trim($this->subiekt_api_prefix.' '.$id_order),
                           'pay_type' => $pay_type,
                           'pay_point_id' => $pay_point_id,
                           'comments' => trim('Doręczyciel: '.$shipping.(isset($payment['additional_information']['method_title'])?', płatność: '.strip_tags($payment['additional_information']['method_title']):'').". ".$comments)
                           );



         /* Bulding customer array */
         $cs_id = $randNumber = date("YmdHis").rand(11111,99999);
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
                                                      'ref_id'       => $cs_id , //trim($this->subiekt_api_prefix.'CS '.hrtime(true)),
                                                      'is_company'   => preg_match("/([A-Z]{0,2})([^Aa-zA][0-9\- ]{9,14})/",$customer['vat_id'])==1?true:false,
                                                      'company_name' => $customer['company'],
                                                      'tax_id'       => preg_match("/([A-Z]{0,2})([^Aa-zA][0-9\- ]{9,14})/",$customer['vat_id'])==1?$customer['vat_id']:'',
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
                                          'price'  =>                      $price-round($product->getDiscountAmount()/$product->getQtyOrdered(),2),
                                          'qty'    =>                      intval($product->getQtyOrdered()),
                                          'price_before_discount' =>       $price,
                                          'code'   =>                      $code,
                                          'time_of_delivery'   =>          2,
                                          //'net_price'          => true,
                                          'supplier_code'    => (!is_null($productObject) && !empty($productObject->getSupplierReference()))?$productObject->getSupplierReference():'', 
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
         /* ustawnie flagi do wysyłki jesli automat */
          if(!empty($this->subiekt_api_send_flag) && $to_send_flag == 1){

                  $id_gr_flag = 8;
                  
                  $flag_result = $subiektApi->call('order/setflag',array('order_ref'=>$result['data']['order_ref'],
                                                                              'id_gr_flag' => $id_gr_flag, //Czy to wlasciwe ID ?
                                                                              'flag_name'=>$this->subiekt_api_send_flag
                                                                             ));
                  //print_r($flag_result);
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
