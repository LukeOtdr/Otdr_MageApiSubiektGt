<?php
namespace Otdr\MageApiSubiektGt\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{

	const XML_PATH = 'mageapisubiektgt/';
	protected $scopeConfig = false;

	public function __construct(ScopeConfigInterface $scopeConfig){
		$this->scopeConfig = $scopeConfig;		
	}

	public function getConfigValue($field, $storeId = null)
	{		
		return $this->scopeConfig->getValue(
			$field, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId
		);
	}

	public function getGen($code)
	{

		return $this->getConfigValue(self::XML_PATH .'general/'. $code);
	}

	public function getStatus($code){
		return $this->getConfigValue(self::XML_PATH .'statuses/'. $code);
	}


	public function getInternal($code){
		return $this->getConfigValue(self::XML_PATH .'internal/'. $code);

	}

	public function getPayments($code){
		return $this->getConfigValue(self::XML_PATH .'payments/'. $code);

	}

	public function get($code){		
		return $this->getConfigValue(self::XML_PATH . $code);
	}

	public function getNonCached($code){
		$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
		$resource = $objectManager->get('Magento\Framework\App\ResourceConnection');	

		$connection = $resource->getConnection();
        $tableName = $resource->getTableName('core_config_data');
        $query = 	"select value from {$tableName} where path like '%{$code}%'";
        $result = $connection->fetchAll($query);
    	if(isset($result[0]['value'])){
        	return $result[0]['value'];
    	}
    	return NULL;
	}

	public function save($varname,$value){
		$objectManager = \Magento\Framework\App\ObjectManager::getInstance(); 
		$configWriter = $objectManager->get('\Magento\Framework\App\Config\Storage\WriterInterface');
		$configWriter->save(self::XML_PATH.$varname,$value);		
	}

}