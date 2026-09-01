<?php

declare(strict_types=1);

namespace Uho\NovaposhtaCheckout\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Model\Order;
use Uho\NovaposhtaCheckout\ViewModel\Adminhtml\NovaposhtaOrderInfo as NovaposhtaOrderInfoViewModel;

/**
 * Renders the Nova Poshta city/warehouse as its own panel in the order-information area,
 * so the real fulfilment fact reads as primary rather than a footnote beneath the sentinel
 * postcode and the composed street (docs/novaposhta-checkout-architecture.md §9).
 */
class NovaposhtaInfo extends Template
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param NovaposhtaOrderInfoViewModel $viewModel
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        NovaposhtaOrderInfoViewModel $viewModel,
        array $data = []
    ) {
        $data['view_model'] = $viewModel;
        parent::__construct($context, $data);
    }

    public function getOrder(): ?Order
    {
        return $this->registry->registry('current_order');
    }
}
