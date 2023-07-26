<?php

namespace Fruitcake\CustomImageUrl\Model;

use Fruitcake\CustomImageUrl\Helper\Data;
use Fruitcake\CustomImageUrl\Model\Config\CustomConfig;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

class UrlConverter
{

    /** @var CustomConfig  */
    private $customConfig;

    
    /** @var StoreManagerInterface  */
    private $storeManager;

    /** @var Data */
    private $helper;

    /** @var DirectoryList */
    private $directoryList;

    public function __construct(
        CustomConfig $customConfig,
        StoreManagerInterface $storeManager,
        Data $helper,
        DirectoryList $directoryList
    )
    {
        $this->customConfig = $customConfig;
        $this->_storeManager = $storeManager;
        $this->helper = $helper;
        $this->_directoryList = $directoryList;
    }

    public function changeContent($content) {

        $customType = $this->helper->getCustomUrlType();

        // No action required, just use the default URL
        if ($customType === CustomConfig::TYPE_DEFAULT) {
            return $content;
        }

        $pattern = '/(http)?s?:?(\/\/[^"\']*\.(?:png|jpg|jpeg|gif))/';

        $content = preg_replace_callback($pattern, function ($matches) {

            $params = array();

            $mediaUrl = $matches[0];
            
            $storeId = $this->_storeManager->getStore()->getId();
            $baseMediaUrl = $this->helper->getBaseMediaUrl($storeId);

            if( str_contains( $mediaUrl, $baseMediaUrl ) ) {
                $url = parse_url($mediaUrl);
                $filePath = $this->_directoryList->getRoot().'/pub'.$url['path'];

                $imageSize = (file_exists($filePath))? getimagesize($filePath) : [ 0,  0 ];
                
                $params['file_path'] = $url['path'];
                $params['width'] = $imageSize[1];
                $params['height'] = $imageSize[0];
                

                $customType = $this->helper->getCustomUrlType();
                if ($customType === CustomConfig::TYPE_PATTERN) {
                    return $this->helper->getCustomUrlFromPattern($mediaUrl, $params);
                }

                if ($customType === CustomConfig::TYPE_IMGPROXY) {
                    return $this->getImgProxyUrl($mediaUrl, $params);
                }
            } 
            return $mediaUrl;
        }, $content);

        return $content;
    }
}