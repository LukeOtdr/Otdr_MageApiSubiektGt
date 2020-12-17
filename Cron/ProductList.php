<?php
namespace Otdr\MageApiSubiektGt\Cron;

use Otdr\MageApiSubiektGt\Helper\SubiektApi;
use Exception;

class ProductList extends CronObject
{

   protected $products_cnt = 0;
   protected $store_info = array('code'=>'OUTDOORZY',
   								 	  'name' => "Outdoorzy - magazyn wewnętrzny",
   								 	  'city' => "Bielsko-Biała",
   								 	  'postcode' => '43-300',
   								 	  'delivery_time' => 24,
   								 	  'priority' => 1
   								);
   							
 
   public function __construct(\Otdr\MageApiSubiektGt\Helper\Config $config,\Psr\Log\LoggerInterface $logger, \Magento\Framework\App\State $appState ){
      parent::__construct($config,$logger,$appState);
   }
 
	public function execute(){
		parent::execute();
		
		date_default_timezone_set('Europe/Warsaw');
		$subiektApi = new SubiektApi($this->api_key,$this->end_point); 
		$result = $subiektApi->call('product/getlistbystore',array('id_store'=>$this->subiekt_api_warehouse_id));   
		if($result['state']=='success'){
			$products = $result['data'];

			$suppliers = dirname(__FILE__).'/../Controller/Product/suppliers.data';
			$file_name_full = dirname(__FILE__).'/../Controller/Product/fullstoreproducts.data';

			if(file_exists($file_name_full)){
				$data = file_get_contents($file_name_full);
				$full_json_array = unserialize($data);
			}else{
				$full_json_array = array('timestamp'=>date('Y-m-d H:i:s'));
			}
	
			if(file_exists($suppliers)){
				$supplier_data = file_get_contents($suppliers);
				$supplier_json_array = unserialize($supplier_data);
			}


			$file_name_onstore = dirname(__FILE__).'/../Controller/Product/onstoreproducts.data';
			if(file_exists($file_name_onstore)){
				$data = file_get_contents($file_name_onstore);
				$onstore_json_array = unserialize($data);
			}else{
				$onstore_json_array = array('timestamp'=>date('Y-m-d H:i:s'));
			}
			$cnt = 0;
			$onstore_json_array[$this->store_info['code']]['products'] = array();
			foreach($products as $p){
/*
				if($p['available'] == 0 && isset($full_json_array['products'][$p['code']]) && $full_json_array['products'][$p['code']]['available']>0){
					continue;
				}

*/				
				$code = trim($p['code']);
				$full_json_array[$this->store_info['code']]['products'][$code] = intval($p['available']);
				if(intval($p['available'])>0){
					$onstore_json_array[$this->store_info['code']]['products'][$code] = intval($p['available']);					
				}
				
				$cnt++; 				
			}
			$full_json_array[$this->store_info['code']]['total'] = count($full_json_array[$this->store_info['code']]['products']);
			$full_json_array['timestamp'] = date('Y-m-d H:i:s'); 
			$onstore_json_array[$this->store_info['code']]['total'] = count($onstore_json_array[$this->store_info['code']]['products']);
			$onstore_json_array['timestamp'] = date('Y-m-d H:i:s');
			//print_r($json_array);
			

			$supplier_json_array[$this->store_info['code']] =  $this->store_info;
			file_put_contents($suppliers, serialize($supplier_json_array));
		 	file_put_contents($file_name_full,serialize($full_json_array));			
		 	file_put_contents($file_name_onstore,serialize($onstore_json_array));
		 	$this->products_cnt = "$cnt/".count($products)."/".$full_json_array[$this->store_info['code']]['total'];

			return true;
		}
		return false;
	}

	public function count(){
		return $this->products_cnt;
	}

 }


  