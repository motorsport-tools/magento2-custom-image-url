<?php

namespace Fruitcake\CustomImageUrl\Observer;

use Fruitcake\CustomImageUrl\Model\UrlConverter;

class BlockToHTML implements \Magento\Framework\Event\ObserverInterface
{
    private $urlConverter;

    public function __construct(UrlConverter $urlConverter)
    {
        $this->UrlConverter  = $urlConverter;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        
        $content = $observer->getTransport()->getHtml();
        
        $content = $this->UrlConverter->changeContent($content);

        $observer->getTransport()->setHtml( $content );
    }
}
