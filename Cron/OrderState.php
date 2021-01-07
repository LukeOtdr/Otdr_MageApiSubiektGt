<?php
namespace Otdr\MageApiSubiektGt\Cron;

use Magento\Framework\App\Area;
use Otdr\MageApiSubiektGt\Helper\SubiektApi;
use Exception;

class OrderState extends CronObject
{

    /* @var \Magento\Framework\App\AreaList */
    protected $_areaList;

    public function __construct(\Otdr\MageApiSubiektGt\Helper\Config $config,\Psr\Log\LoggerInterface $logger, \Magento\Framework\App\State $appState, \Magento\Framework\App\AreaList $areaList ){
        parent::__construct($config,$logger,$appState);
        $this->_areaList = $areaList;
    }


    protected function getOrdersIds(){
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
        $query = 'SELECT id_order, gt_order_ref,gt_sell_doc_ref,gt_order_sent,gt_sell_doc_request,upd_date FROM '.$tableName.' WHERE gt_order_sent = 1 AND email_sell_doc_pdf_sent = 0 AND is_locked = 0';
        $result = $connection->fetchAll($query);
        return $result;
    }

    public function removeSellDoc($id_order){
        $this->deletePdf($id_order);
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
        $dml = "UPDATE {$tableName} SET gt_sell_doc_request = 0, gt_sell_doc_ref =  '', upd_date = NOW() WHERE id_order = '{$id_order}'";
        $connection->query($dml);
        $this->addErrorLog($id_order,'Paragon został usunięty');
    }

    public function updateSellDoc($id_order,$doc_reference){
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
        $dml = "UPDATE {$tableName} SET gt_sell_doc_request = 1,gt_sell_doc_ref =  '{$doc_reference}', gt_sell_doc_pdf_request = 0, doc_file_pdf_name = '',  upd_date = NOW() WHERE id_order = '{$id_order}'";
        $connection->query($dml);
        $this->addLog($id_order,'Aktualizacja nr paragonu: <b>'.$doc_reference.'</b>');

    }

    public function deleteOrder($id_order){
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
        $dml = "UPDATE {$tableName} SET gt_order_sent = 0, gt_order_ref =  '', upd_date = NOW() WHERE id_order = '{$id_order}'";
        $connection->query($dml);
        $this->addErrorLog($id_order,'Usunięcie zamówienia');
    }

    public function removeFromDb($id_order){
        $this->deletePdf($id_order);
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getTableName('otdr_mageapisubiektgt');
        $dml = "DELETE FROM {$tableName}  WHERE id_order = '{$id_order}'";
        $connection->query($dml);
        $this->addLog($id_order,'Całkowite usunięcie zamówienia z bazy');
    }


    public function makeShippment($orderObject){
        if ($orderObject->canShip()) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            // Initialize the order shipment object
            $convertOrder = $objectManager->create('Magento\Sales\Model\Convert\Order');
            $shipment = $convertOrder->toShipment($orderObject);

            // Loop through order items
            foreach ($orderObject->getAllItems() AS $orderItem) {
                // Check if order item has qty to ship or is virtual
                if (! $orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                    continue;
                }
                $qtyShipped = $orderItem->getQtyToShip();
                // Create shipment item with qty
                $shipmentItem = $convertOrder->itemToShipmentItem($orderItem)->setQty($qtyShipped);
                // Add shipment item to shipment
                $shipment->addItem($shipmentItem);
            }

            // Register shipment
            $shipment->register();
            $shipment->getOrder()->setIsInProcess(true);

            try {
                // Save created shipment and order
                $shipment->save();
                $shipment->getOrder()->save();

                // Send email                

                /* @var $shipmentNotifier \Magento\Shipping\Model\ShipmentNotifier */
                /*
                $shipmentNotifier = $objectManager->create('Magento\Shipping\Model\ShipmentNotifier');

                $this->_areaList->getArea(Area::AREA_FRONTEND)->load(Area::PART_TRANSLATE);
                
                $this->appState->emulateAreaCode(
                    Area::AREA_FRONTEND,
                    function () use ($shipmentNotifier, $shipment) {
                        $shipmentNotifier->notify($shipment);
                    },
                    [$shipmentNotifier, $shipment]
                );

                $shipment->save();
                */
            } catch (\Exception $e) {
                echo "Shipment Not Created". $e->getMessage();
                return false;
            }
        }
        return true;
    }

    public function execute(){

        parent::execute();

        $this->appState->setAreaCode('adminhtml');

        $subiektApi = new SubiektApi($this->api_key,$this->end_point);
        $orders_to_make_sale = $this->getOrdersIds();

        foreach($orders_to_make_sale as $order){
            $id_order = $order['id_order'];

            $this->ordersProcessed++;

            /* Locking order for processing */
            $this->lockOrder($id_order);
            print("Get status for order no \"{$order['id_order']}\": ");

            /* check order status */
            $order_data = $this->getOrderData($id_order);
            $status = $order_data->getStatus();

            if($status  == $this->subiekt_api_order_hold){
                $this->unlockOrder($id_order);
                print ("skipped ({$status}) \n");
                continue;
            }

            /*Request for sale document*/
            $resut = false;
            if($order['gt_order_sent'] == 1 && $order['gt_sell_doc_request'] == 1 ){
                $result = $subiektApi->call('document/getstate',array('doc_ref'=>$order['gt_sell_doc_ref']));
            }
            /*Request for order*/
            elseif($order['gt_order_sent'] == 1){
                $result = $subiektApi->call('order/getstate',array('order_ref'=>$order['gt_order_ref']));
            }

            if(!$result){
                $this->unlockOrder($id_order);
                $this->addErrorLog($id_order,'Can\'t connect to API check configuration!');
                return false;

            }
            if($result['state'] == 'fail'){
                $this->unlockOrder($id_order);
                if(!mb_ereg_match(".*Lock request time out period exceeded.",$result['message']))
                {
                    $this->addErrorLog($id_order,$result['message']);    
                }
                
                print("Error: {$result['message']}\n");
                continue;
            }

            /*getting order data*/
            //analize only subiektgt state
            $result = $result['data'];
            $continue = false;
            //checking by magento order status
            if($order['gt_order_sent'] == 1 && $order['gt_sell_doc_request'] == 1){
                switch($status){
                    //delete order/document from subiekt
                    case 'canceled':
                        if($result['fiscal_state'] == false){
                            if(false !== $subiektApi->call('document/delete',array('doc_ref'=>$order['gt_sell_doc_ref'])) &&  false !==  $subiektApi->call('order/delete',array('order_ref'=>$order['gt_order_ref']))){
                                $this->removeFromDb($id_order);
                                $continue  = true;
                            }
                        }
                        break;
                    case 'closed':
                        if($result['fiscal_state'] == false){
                            if(false !== $subiektApi->call('document/delete',array('doc_ref'=>$order['gt_sell_doc_ref'])) &&  false !==  $subiektApi->call('order/delete',array('order_ref'=>$order['gt_order_ref']))){
                                $this->removeFromDb($id_order);
                                $continue  = true;
                            }
                        }
                        break;
                    case 'complete':
                        if(!$order_data->hasShipments() && !empty($this->subiekt_api_complete_flag)){
                            if($result['flag_name'] ==  $this->subiekt_api_complete_flag){
                                $this->makeShippment($order_data);
                            }
                        }
                        $continue = true;
                        break;

                    //Make shippment
                    case $this->subiekt_api_sell_doc_status:

                        if(!$order_data->hasShipments() && !empty($this->subiekt_api_complete_flag)){
                            if($result['flag_name'] ==  $this->subiekt_api_complete_flag){
                                $this->makeShippment($order_data);
                            }
                        }

                        break;
                    default: break;
                }


            }elseif($order['gt_order_sent'] == 1){

                switch($status){
                    //delete order from subiekt
                    case 'canceled':
                        if(false !== $subiektApi->call('order/delete',array('order_ref'=>$order['gt_order_ref']))){
                            $this->removeFromDb($id_order);
                            $continue  = true;
                        }
                        break;
                    case 'closed':
                        if(false !== $subiektApi->call('order/delete',array('order_ref'=>$order['gt_order_ref']))){
                            $this->removeFromDb($id_order);
                            $continue  = true;
                        }
                        break;
                    //order processing ...
                    case $this->subiekt_api_order_processing :
                        if($result['state'] == 7 && $result['order_processing']==true){
                            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                            //check that
                            $o_products = $order_data->getAllItems();
                            $products_array  = array();
                            foreach($o_products as $op){                                
                                $products_array[] = array(
                                    'code'   =>  $op->getSku(),
                                    'id_store' => $this->subiekt_api_warehouse_id,
                                );
                            }
                            //var_dump($products_array);
                            $p_result = $subiektApi->call('product/getqtysbycode',array('products_qtys'=>$products_array));
                            if($p_result['state']=='success'){
                                $make_sale = true;
                                $r_products = $p_result['data'];
                                foreach($r_products as $rp){
                                    if(intval($rp['on_store_available'])<0){
                                        $make_sale = false;
                                        break;
                                    }
                                }
                                if($make_sale){
                                    //Products on store make sell doc
                                    $this->setStatus($id_order,"Wznowiono realizacje",$this->subiekt_api_order_status);
                                }
                            }

                        }
                        $continue  = true;

                        break;
                    //order registred but subiekt processing
                    case $this->subiekt_api_order_status :
                        if($result['state'] == 7 && $result['order_processing']==true){
                            $continue = true;
                        }
                        break;

                    case 'complete':
                        if($order['gt_sell_doc_request'] == 0){
                            if($result['state'] == 8 && !empty($result['sell_doc'])){
                                $this->updateSellDoc($id_order,$result['sell_doc']);
                            }
                        }


                        break;


                    default: break;
                }

            }
            if($continue){
                $this->unlockOrder($id_order);
                print("OK - Gets status!\n");
                continue;
            }
            //Check state by Subiekt GT information
            if($order['gt_order_sent'] == 1 && $order['gt_sell_doc_request'] == 1){
                if(false == $result['is_exists']){
                    $this->removeSellDoc($id_order);
                }

                //Amount collision
                if(true == $result['is_exists']  && $result['amount'] != $order_data->getGrandTotal()){
                    $this->setStatus($id_order,"Niezgodność kwoty zamówień: <b style=\"color:red;\">{$result['doc_ref']} : {$result['amount']}</b>");
                    //$this->addErrorLog($id_order,"Niezgodność kwoty zamówień: <b style=\"color:red;\">{$result['doc_ref']} : {$result['amount']}</b>");
                }

                //Document ref collision
                if($result['doc_ref'] != $order['gt_sell_doc_ref']){
                    $this->updateSellDoc($id_order,$result['doc_ref']);
                }

            }elseif($order['gt_order_sent'] == 1){
                //order deleteted from subiekt
                if($result['is_exists'] == false){
                    $this->deleteOrder($id_order);
                }
                //set Order processing
                elseif($result['is_exists'] == true && $result['order_processing'] == true && $status != $this->subiekt_api_order_processing){
                    $this->setStatus($id_order,'Zamówienie przetwarzane',$this->subiekt_api_order_processing);
                }elseif($result['amount'] != $order_data->getGrandTotal()){
                    $this->setStatus($id_order,"Niezgodność kwoty zamówień: <b style=\"color:red;\">{$result['order_ref']} : {$result['amount']}</b>");
                    //$this->addErrorLog($id_order,"Niezgodność kwoty zamówień: <b style=\"color:red;\">{$result['order_ref']} : {$result['amount']}</b>");
                }
            }

            /* unlocking order after processing */
            $this->unlockOrder($id_order);


            /* Is all Okey */
            print("OK - Gets status!\n");

        }


        return true;

    }
}
?>
