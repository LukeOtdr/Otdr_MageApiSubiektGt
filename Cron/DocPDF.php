<?php
namespace Otdr\MageApiSubiektGt\Cron;

use Otdr\MageApiSubiektGt\Helper\SubiektApi;
use Exception;

class DocPDF extends CronObject
{
    

   public function __construct(\Otdr\MageApiSubiektGt\Helper\Config $config,\Psr\Log\LoggerInterface $logger, \Magento\Framework\App\State $appState ){
      parent::__construct($config,$logger,$appState);
   }


   protected function getOrdersIds(){
         $connection = $this->resource->getConnection();
         $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
         $query = 'SELECT id_order,gt_sell_doc_ref FROM '.$tableName.' WHERE is_locked = 0 AND gt_sell_doc_pdf_request = 0 AND gt_sell_doc_request = 1';
         $result = $connection->fetchAll($query);
         return $result;
   }


   public function execute(){

      parent::execute();
      $subiektApi = new SubiektApi($this->api_key,$this->end_point);
      $orders_to_make_sale = $this->getOrdersIds();
      
      
      foreach($orders_to_make_sale as $order){
         $id_order = $order['id_order'];     
      
         $this->ordersProcessed++;
         print("Getting PDF for order no \"{$id_order}\": ");


         
         //checking is processed by another
         if(true == intval($this->getProcessingData($id_order,'gt_sell_doc_pdf_request'))){
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
         if($st != $this->subiekt_api_sell_doc_status && $st != 'processing' && $st != 'complete'){
            $this->unlockOrder($id_order);
            print ("skipped\n");
            continue;
         }


         $order_json[$id_order] = array('doc_ref'=>$order['gt_sell_doc_ref']);

         
         $result = $subiektApi->call('document/getpdf',$order_json[$id_order]);  
         
         if(!$result){             
            $this->unlockOrder($id_order);
            $this->addLog($id_order,'Can\'t connect to API check configuration!');
            continue;

         }
         if($result['state'] == 'fail'){                        
            $this->unlockOrder($id_order);
            $this->addErrorLog($id_order,$result['message']);  
            print("Error: {$result['message']}\n");
            continue;          
         } 

         if($result['state'] == 'success'){
            $data = $result['data'];
            if(!empty($data['pdf_file'])){
               if($this->savePdf($id_order,$data['pdf_file'])){
                  print ("OK\n");
               }
            }
         }
         $this->unlockOrder($id_order);
      }

      return true;
   }
}
?>