<?php
namespace Otdr\MageApiSubiektGt\Cron;

use Otdr\MageApiSubiektGt\Helper\SubiektApi;
use Exception;

abstract class CronObject {
   
   protected $logger;
   protected $config;
   protected $ordersProcessed = 0;
   protected $end_point = '';
   protected $api_key = '';
   protected $enable = false;
   protected $subiekt_api_ean_attrib = '';
   protected $subiekt_api_newproducts = false;
   protected $subiekt_api_prefix = '';
   protected $subiekt_api_warehouse_id = 1;
   protected $subiekt_api_trans_symbol;
   protected $subiekt_api_pdfs_path = '';


    /*Status*/
   protected $subiekt_api_order_status = '';
   protected $subiekt_api_sell_doc_status = ''; 
   protected $subiekt_api_order_processing = '';
   protected $subiekt_api_order_hold = '';

   /*Flags*/
   protected $subiekt_api_wrapping_flag = 0;
   protected $subiekt_api_complete_flag = 0;
   protected $subiekt_api_send_flag = '';

   /*payments for order*/
   protected $subiekt_api_payments_transfer = [];
   protected $subiekt_api_payments_cart = [];
   protected $subiekt_api_payments_cart_subiekt = [];
   protected $subiekt_api_payments_credit_subiekt = [];
   

   
   protected $resource = false;
   protected $logArray = array();
   public $appState = false;


   public function __construct(\Otdr\MageApiSubiektGt\Helper\Config $config ,\Psr\Log\LoggerInterface $logger, \Magento\Framework\App\State $appState){

      $objectManager = \Magento\Framework\App\ObjectManager::getInstance(); 
      $this->appState = $appState;
      $this->config = $config;    
      $this->logger = $logger;  
      

      $this->resource = $objectManager->get('Magento\Framework\App\ResourceConnection');

      /* Load module configuration */
      $this->enable = $this->config->getGen('enable');  
      $this->end_point = $this->config->getGen('subiekt_api_gateway');
      $this->api_key = $this->config->getGen('subiekt_api_key');      
      $this->subiekt_api_ean_attrib = $this->config->getGen('subiekt_api_ean_attrib'); 
      $this->subiekt_api_newproducts = $this->config->getGen('subiekt_api_newproducts');
      $this->subiekt_api_prefix  =   $this->config->getGen('subiekt_api_prefix');
      $this->subiekt_api_warehouse_id = $this->config->getGen('subiekt_api_warehouse_id');
      $this->subiekt_api_trans_symbol = $this->config->getGen('subiekt_api_trans_symbol');
      $this->subiekt_api_pdfs_path = $this->config->getGen('subiekt_api_pdfs_path');

      /*Statuses*/
      $this->subiekt_api_order_status = $this->config->getStatus('subiekt_api_order_status');
      $this->subiekt_api_sell_doc_status = $this->config->getStatus('subiekt_api_sell_doc_status');
      $this->subiekt_api_order_processing = $this->config->getStatus('subiekt_api_order_processing');
      $this->subiekt_api_order_hold = $this->config->getStatus('subiekt_api_order_hold');
      
      /*Flags*/      
      $this->subiekt_api_wrapping_flag = $this->config->getStatus('subiekt_api_wrapping_flag');
      $this->subiekt_api_complete_flag = $this->config->getStatus('subiekt_api_complete_flag');
      $this->subiekt_api_send_flag = $this->config->getStatus('subiekt_api_send_flag');

      /*payments for order*/
      $this->subiekt_api_payments_transfer = explode(",",$this->config->getStatus('subiekt_api_payments_transfer'));
      $this->subiekt_api_payments_cart = explode(",",$this->config->getStatus('subiekt_api_payments_cart'));
      $this->subiekt_api_payments_cart_subiekt = explode(",",$this->config->getStatus('subiekt_api_payments_cart_subiekt'));
      $this->subiekt_api_payments_credit_subiekt = explode(",",$this->config->getStatus('subiekt_api_payments_credit_subiekt'));
      
   }


  public function execute (){
    if($this->enable == false){
      print("Module is disabled - enable in admin panel!\n");   
      exit();
    }
  }

   public function getLog(){
      return $this->logArray;
   }

   protected function productQtyUpdate($code,$qty){
      $objectManager = \Magento\Framework\App\ObjectManager::getInstance(); 
      $stockRegistry = $objectManager->get('\Magento\CatalogInventory\Api\StockRegistryInterface');
      $productObject = $objectManager->get('\Magento\Catalog\Model\Product');

      if(!empty($this->subiekt_api_ean_attrib)){     
                             
            $product = $productObject->loadByAttribute($this->subiekt_api_ean_attrib, $code);
            if($product){
               $stockItem = $stockRegistry->getStockItem($product->getId());
               $stockItem->setData('qty',$qty);            
               $stockItem->save();
               return true;
            }           
      }else{            
            $id_product = $productObject->getIdBySku($code);
            if(intval($id_product)>0){                                                     
               $stockItem = $stockRegistry->getStockItem($id_product);
               $stockItem->setQty($qty);            
               $stockItem->save();
               return true;
            }            
      }
      return false;
   }

   
   protected function lockOrder($id_order){
      $connection = $this->resource->getConnection();
      $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
      $dml = 'UPDATE '.$tableName.' SET is_locked = 1, upd_date = NOW() WHERE id_order = \''.$id_order.'\'';
      $connection->query($dml);
   }

   protected function unlockOrder($id_order){
      $connection = $this->resource->getConnection();
      $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
		$dml = 'UPDATE '.$tableName.' SET is_locked = 0, upd_date = NOW() WHERE id_order = \''.$id_order.'\'';
      $connection->query($dml);
   }

   protected function getOrderData($id_order){
      $objectManager = \Magento\Framework\App\ObjectManager::getInstance();      
      $order = $objectManager->create('\Magento\Sales\Api\Data\OrderInterface')->loadByIncrementId($id_order);
      return $order;
   }

   protected function setStatus($id_order,$comment_txt,$status = 'processing'){
      $comment_txt = 'Subiekt GT info: '.$comment_txt;
      $objectManager = \Magento\Framework\App\ObjectManager::getInstance(); 
      $order = $objectManager->create('\Magento\Sales\Api\Data\OrderInterface')->loadByIncrementId($id_order); 
      $order->addStatusToHistory($status, $comment_txt, false);
      $order->save(); 
   }

   protected function addLog($id_order,$comment_txt){
      /*Add comment log from subiekt*/ 
      $comment_txt = 'Subiekt GT info: '.$comment_txt;
      $objectManager = \Magento\Framework\App\ObjectManager::getInstance(); 
      $order = $objectManager->create('\Magento\Sales\Api\Data\OrderInterface')->loadByIncrementId($id_order);       
      $order->addStatusHistoryComment($comment_txt);
      $order->save();            
   }

   /**
   * Save pdf of selling document
   */
   protected function savePdf($id_order,$pdf_data, $file_name = ''){    
      if($file_name==''){        
        $file_name = sha1($pdf_data);
      }
      if(file_put_contents($this->getDirForPDF($this->subiekt_api_pdfs_path,$file_name,true).'/'.$file_name.'.pdf', base64_decode($pdf_data))){
          $connection = $this->resource->getConnection();
          $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
          $dml = "UPDATE {$tableName} SET gt_sell_doc_pdf_request = 1, doc_file_pdf_name = '{$file_name}', upd_date = NOW() WHERE id_order = '{$id_order}'";
          $connection->query($dml);


          //$this->appState->setAreaCode('adminhtml');
          $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
          $storeManager = $objectManager->get('\Magento\Store\Model\StoreManagerInterface');
          $baseUrl = $storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
          $file_url  = $baseUrl.'otdrsgt/pdf/view/file/'.$file_name;
          //var_dump($file_url);
          $comment_txt = 'Subiekt GT info: Dokument sprzedaży do zamówienia <strong>'.$id_order.'</strong>: <a  href="'.$file_url.'" target="_blank">PDF do pobrania</a>';
                 
          $order = $objectManager->create('\Magento\Sales\Api\Data\OrderInterface')->loadByIncrementId($id_order);       
          $order->addStatusHistoryComment($comment_txt)->setIsCustomerNotified(true);
          $order->save();       
      }else{
        return false;
      }
      return $file_name;
   }

   protected function getProcessingData($id_order,$field_name = null){
      $objectManager = \Magento\Framework\App\ObjectManager::getInstance(); // Instance of object manager
      $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
      $connection = $resource->getConnection();
      $tableName = $resource->getTableName('otdr_mageapisubiektgt');
      $query = "SELECT * FROM {$tableName} WHERE id_order='{$id_order}'";
      $result = $connection->fetchAll($query);
      if(count($result)==1){
        if(is_null($field_name)){
          return $result[0];
        }elseif(isset($result[0][$field_name])){
          return $result[0][$field_name];
        }
      }
      return false;
   }

   protected function createOrder($id_order){
      $objectManager = \Magento\Framework\App\ObjectManager::getInstance(); // Instance of object manager
      $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
      $connection = $resource->getConnection();
      $tableName = $resource->getTableName('otdr_mageapisubiektgt');

      $dml = 'INSERT INTO '.$tableName.' VALUES(0,\''.$id_order.'\',0,0,0,0,\'\',\'\',\'\',NOW(),NOW(),0)';
      $connection->query($dml);
   }


   static public function getDirForPDF($global_dir, $sha1_file_name,$make_dir = false){
      $dirs = substr($sha1_file_name, 0,6);
      $file_dir = $global_dir;
      
      for($i=0;$i<strlen($dirs);$i++){
        $file_dir .= '/'.$dirs[$i]; 
      }
      if(!file_exists($file_dir)){
          if($make_dir){
            mkdir($file_dir,0755,true);
          }
      }
      return $file_dir;
   }

   /**
   * Getting of pdf selling document
   */
   
   protected function getPdf($id_order,$in_base64 = false){
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
        $query = "SELECT doc_file_pdf_name FROM {$tableName} WHERE id_order = '{$id_order}'";
        $result = $connection->fetchAll($query);        
        if(isset($result[0]['doc_file_pdf_name']) && !empty($result[0]['doc_file_pdf_name'])){
          $pdf_content = @file_get_contents($this->getDirForPDF($this->subiekt_api_pdfs_path,$result[0]['doc_file_pdf_name']).'/'.$result[0]['doc_file_pdf_name'].'.pdf');
          if(false !== $pdf_content && $in_base64){
            $pdf_content = base64_encode($pdf_content);
          }          
          return $pdf_content;
        
        }  
        return false;
   }

   protected function deletePdf($id_order){
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
        $query = "SELECT doc_file_pdf_name FROM {$tableName} WHERE id_order = '{$id_order}'";
        $result = $connection->fetchAll($query);        
        if(isset($result[0]['doc_file_pdf_name']) && !empty($result[0]['doc_file_pdf_name'])){
          return @unlink($this->getDirForPDF($this->subiekt_api_pdfs_path,$result[0]['doc_file_pdf_name']).'/'.$result[0]['doc_file_pdf_name'].'.pdf');
        }
        return false;
   }

   protected function addErrorLog($id_order,$comment_txt){
      $this->setStatus($id_order,$comment_txt,$this->subiekt_api_order_hold);
   }

   public function getOrdersProcessed(){
      return $this->ordersProcessed;
   }

}

?>
