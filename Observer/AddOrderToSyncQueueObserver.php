<?php
/**
 * Shippit Pty Ltd
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the terms
 * that is available through the world-wide-web at this URL:
 * http://www.shippit.com/terms
 *
 * @category   Shippit
 * @copyright  Copyright (c) 2016 by Shippit Pty Ltd (http://www.shippit.com)
 * @author     Matthew Muscat <matthew@mamis.com.au>
 * @license    http://www.shippit.com/terms
 */

namespace Shippit\Shipping\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Shippit\Shipping\Model\Config\Source\Shippit\Sync\Order\Mode;
use Shippit\Shipping\Model\Config\Source\Shippit\Sync\Order\SendAllOrders;

use Magento\Sales\Model\Order;
use Shippit\Shipping\Model\Sync\Order as SyncOrder;

class AddOrderToSyncQueueObserver implements ObserverInterface
{
    protected $_helper;
    protected $_syncOrder;
    protected $_requestSyncOrder;
    protected $_apiOrder;
    protected $_logger;

    protected $_hasAttemptedSync = false;
 
    public function __construct (
        \Shippit\Shipping\Helper\Sync\Order $helper,
        \Shippit\Shipping\Api\Data\SyncOrderInterface $syncOrder,
        \Shippit\Shipping\Api\Request\SyncOrderInterface $requestSyncOrder,
        \Shippit\Shipping\Model\Api\Order $apiOrder,
        \Shippit\Shipping\Logger\Logger $logger
    ) {
        $this->_helper = $helper;
        $this->_syncOrder = $syncOrder;
        $this->_requestSyncOrder = $requestSyncOrder;
        $this->_apiOrder = $apiOrder;
        $this->_logger = $logger;
    }
 
    public function execute(Observer $observer)
    {
        // Ensure the module is active
        if (!$this->_helper->isActive()) {
            return $this;
        }

        $order = $observer->getEvent()->getOrder();

        // Ensure we have an order
        if (!$order && $order->getId()) {
            return $this;
        }

        $shippingMethod = $order->getShippingMethod();
        $shippitShippingMethod = $this->_helper->getShippitShippingMethod($shippingMethod);

        $shippingCountry = $order->getShippingAddress()->getCountryId();

        // If send all orders,
        // or shippit shipping class present
        if ($this->_helper->getSendAllOrders() == SendAllOrders::ALL
            || ($this->_helper->getSendAllOrders() == SendAllOrders::ALL_AU && $shippingCountry == 'AU')
            || $shippitShippingMethod !== FALSE) {
            try {
                $request = $this->_requestSyncOrder
                    ->setOrder($order)
                    ->setItems()
                    ->setShippingMethod($shippitShippingMethod);

                // create an order sync item and save to the DB
                $syncOrder = $this->_syncOrder
                    ->addSyncOrderRequest($request)
                    ->save();

                // If the sync mode is realtime,
                // attempt realtime sync now
                if ($this->_helper->getMode() == Mode::REALTIME) {
                    $this->_syncOrder($syncOrder);
                }
            }
            catch (\Magento\Framework\Exception\LocalizedException $e) {
                $this->_logger->addError($e->getMessage());
            }
            catch (\RuntimeException $e) {
                $this->_logger->addError($e->getMessage());
            }
            catch (\Exception $e) {
                $this->_logger->addError($e->getMessage());
            }
        }

        return $this;
    }

    private function _syncOrder($syncOrder)
    {
        $order = $syncOrder->getOrder();

        if (!$this->_hasAttemptedSync
            // ensure the order is in the processing state
            && $order->getState() == Order::STATE_PROCESSING
            // ensure the sync order is in the pending state
            && $syncOrder->getStatus() == SyncOrder::STATUS_PENDING) {
            $this->_hasAttemptedSync = true;
            
            // attempt the sync
            $syncResult = $this->_apiOrder->sync($syncOrder);

            return $syncResult;
        }
        else {
            return false;
        }
    }
}