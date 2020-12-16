<?php

namespace Otdr\MageApiSubiektGt\Observer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class DeleteInventoryReservations implements ObserverInterface
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    public function __construct(
        ResourceConnection $resourceConnection
    ) {
        $this->resourceConnection = $resourceConnection;
    }

    public function execute(Observer $observer)
    {
        $latestInventoryTimestamp = $observer->getData('timestamp');

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                ['ir' => $connection->getTableName('inventory_reservation')],
                ['ir.reservation_id']
            )
            ->joinInner(
                ['so' => $connection->getTableName('sales_order')],
                "JSON_UNQUOTE(JSON_EXTRACT(ir.metadata, '$.object_id')) = so.entity_id
                AND JSON_UNQUOTE(JSON_EXTRACT(ir.metadata, '$.object_type')) = 'order'",
                []
            )
            ->joinInner(
                ['otdr' => $connection->getTableName('otdr_mageapisubiektgt')],
                "so.increment_id = otdr.id_order
                AND otdr.gt_order_sent = 1
                AND otdr.gt_order_ref <> ''
                AND otdr.add_date < '{$latestInventoryTimestamp}'",
                []
            );

        $connection->query(
            $select->deleteFromSelect('ir')
        );
    }
}
