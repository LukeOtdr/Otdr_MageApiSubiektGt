<?php
namespace Otdr\MageApiSubiektGt\Cron;

use Otdr\MageApiSubiektGt\Helper\SubiektApi;
use Exception;

class MakeSale extends CronObject
{

   public function __construct(\Otdr\MageApiSubiektGt\Helper\Config $config,\Psr\Log\LoggerInterface $logger, \Magento\Framework\App\State $appState ){
      parent::__construct($config,$logger,$appState);
      $this->appState->setAreaCode('adminhtml');
   }


   protected function getOrdersIds(){
         $connection = $this->resource->getConnection();
         $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
         $query = 'SELECT id_order,gt_order_ref FROM '.$tableName.' WHERE is_locked = 0 AND gt_order_sent = 1 AND gt_sell_doc_request = 0';
         $result = $connection->fetchAll($query);
         return $result;
   }

   protected function updateOrderStatus($id_order,$order_reference){
      $connection = $this->resource->getConnection();
      $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
      $dml = "UPDATE {$tableName} SET gt_sell_doc_request = 1, gt_sell_doc_ref =  '{$order_reference['doc_ref']}', upd_date = NOW() WHERE id_order = {$id_order}";
      $connection->query($dml);
      $this->setStatus($id_order,'Wygenerowano paragon/fakturę nr <b>'.$order_reference['doc_ref'].'</b> kwota:'.$order_reference['doc_amount'],$this->subiekt_api_sell_doc_status);
   }

	public function execute(){
      
      parent::execute();
          	
      $subiektApi = new SubiektApi($this->api_key,$this->end_point);
      $orders_to_make_sale = $this->getOrdersIds();
      
      
      foreach($orders_to_make_sale as $order){
         $id_order = $order['id_order'];     
      
         $this->ordersProcessed++;
         print("Making doc for order no \"{$order['id_order']}\": ");


         //checking is processed by another
         if(true == intval($this->getProcessingData($id_order,'gt_sell_doc_request'))){
            print("skipped - processed\n");
            continue;
         }

         /* Locking order for processing */
         $this->lockOrder($id_order);
         /*getting order data*/
         
         $order_data = $this->getOrderData($id_order);
         
         /* check order status */
         //var_dump($order_data->getStatus());
         $st = $order_data->getStatus();
         if($st != $this->subiekt_api_order_status 
               && $st!=$this->subiekt_api_order_processing
               && $st != 'processing'){
            $this->unlockOrder($id_order);
            print ("skipped\n");
            continue;
         }
         
         //Magento order have shipping or invoice status ?
         if(!$order_data->hasInvoices() && !$order_data->hasShipments()){
             $this->unlockOrder($id_order);
             print ("skipped no invoice or shippment\n");
             continue;
         }
         

         $order_json[$id_order] = array('order_ref'=>$order['gt_order_ref'],'pdf_request'=>true);

         
         $result = $subiektApi->call('order/makesaledoc',$order_json[$id_order]);  
         
         if(!$result){             
            $this->unlockOrder($id_order);
            $this->addErrorLog($id_order,'Can\'t connect to API check configuration!');
            return false;

         }
         if($result['state'] == 'fail'){                        
            $this->unlockOrder($id_order);
            $this->addErrorLog($id_order,$result['message']);  
            print("Error: {$result['message']}\n");
            continue;          
         }        

         /* unlocking order after processing */
         $this->unlockOrder($id_order);
         
         $doc_state = $result['data']['doc_state'];
         $state_code = $result['data']['doc_state_code'];
         $doc_ref =  $result['data']['doc_ref'];
         $doc_amount = isset($result['data']['doc_amount'])?$result['data']['doc_amount']:0;

         switch($doc_state){
            case 'warning': 
                  
                if($state_code==2 &&  ($st == $this->subiekt_api_order_status || $st == 'processing')){
                  $this->setStatus($id_order,$result['data']['message'],$this->subiekt_api_order_processing);
                  print("Warning: {$result['data']['message']}\n");
                }elseif($state_code==1 &&  $st == $this->subiekt_api_order_status ){
                     //TODO:DELETE order reference and try again send order ?                                    
                     $this->addErrorLog($id_order,$result['data']['message']);
                     print("Warning: {$result['data']['message']}\n");

                } 
            break;
            

            case 'ok':  
                  
                  //If status OK
                  $this->updateOrderStatus($id_order,$result['data']);                  
                  //TODO: ustawić flage Do wysyłki na subiekcie.      
                  //var_dump($this->subiekt_api_wrapping_flag);  
                  if(!empty($this->subiekt_api_wrapping_flag)){
                    $flag_result = $subiektApi->call('document/setflag',array('doc_ref'=>$doc_ref,
                                                                              'id_gr_flag' => 6,
                                                                              'flag_name'=>$this->subiekt_api_wrapping_flag
                                                                             ));   
                  }

                  print("OK - Send!");

                  if($doc_amount != $order_data->getGrandTotal()){                  
                     $this->addErrorLog($id_order,"Niezgodność kwoty zamówień: <b style=\"color:red;\">{$result['data']['order_ref']} : {$result['data']['doc_amount']}</b>"); 
                     print(" Warning: amount collision");
                  }elseif(isset($result['data']['doc_pdf'])){
                     //If responsed PDF document save it
                     $result['data']['doc_pdf_filename'] = $this->savePdf($id_order,$result['data']['doc_pdf']);
                  }
                  print("\n");

            break;
         }
            
      }

      return true;

	}

}
?>