<?php

namespace Fruitcake\CustomImageUrl\Observer;

use Fruitcake\CustomImageUrl\Model\UrlConverter;
use Fruitcake\CustomImageUrl\Model\Config\CustomConfig;
use Magento\Store\Model\StoreManagerInterface;

class BlockToHTML implements \Magento\Framework\Event\ObserverInterface
{
    private $urlConverter;

    /** @var CustomConfig  */
    private $customConfig;

    /** @var StoreManagerInterface  */
    private $storeManager;



    public function __construct(
        UrlConverter $urlConverter,
        CustomConfig $customConfig,
        StoreManagerInterface $storeManager
    )
    {
        $this->UrlConverter  = $urlConverter;
        $this->customConfig = $customConfig;
        $this->_storeManager = $storeManager;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $storeId = $this->_storeManager->getStore()->getId();

        if(!$this->customConfig->getAllMedia($storeId)) {
            return;
        }

        $content = $observer->getTransport()->getHtml();
        
        $content = $this->UrlConverter->changeContent($content);

        $observer->getTransport()->setHtml( $content );
    }
}