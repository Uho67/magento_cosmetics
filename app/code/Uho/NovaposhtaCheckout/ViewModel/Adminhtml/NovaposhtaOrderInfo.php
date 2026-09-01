<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\ViewModel\Adminhtml;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Model\Order;

class NovaposhtaOrderInfo implements ArgumentInterface
{
    /**
     * Returns the shipping address only when it carries a composed Nova Poshta warehouse.
     */
    public function getNovaposhtaAddress(Order $order): ?OrderAddressInterface
    {
        if ($order->getIsVirtual()) {
            return null;
        }

        $address = $order->getShippingAddress();
        if ($address === null || (string)$address->getData('uho_np_warehouse_ref') === '') {
            return null;
        }

        return $address;
    }

    public function getCityName(OrderAddressInterface $address): string
    {
        return (string)$address->getData('uho_np_city_name');
    }

    public function getWarehouseName(OrderAddressInterface $address): string
    {
        return (string)$address->getData('uho_np_warehouse_name');
    }

    public function getWarehouseSiteKey(OrderAddressInterface $address): string
    {
        return (string)$address->getData('uho_np_warehouse_site_key');
    }
}
