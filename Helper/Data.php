<?php

namespace Fruitcake\CustomImageUrl\Helper;

use Magento\Catalog\Model\Config\CatalogMediaConfig;
use Fruitcake\CustomImageUrl\Model\Config\CustomConfig;
use Magento\Catalog\Model\View\Asset\Image;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;


class Data extends AbstractHelper
{

    /** @var CatalogMediaConfig  */
    private $catalogMediaConfig;

    /** @var CustomConfig  */
    private $customConfig;

    /** @var StoreManagerInterface  */
    private $storeManager;

    public function __construct(
        Context $context,
        CatalogMediaConfig $catalogMediaConfig,
        CustomConfig $customConfig,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);

        $this->catalogMediaConfig = $catalogMediaConfig;
        $this->customConfig = $customConfig;
        $this->storeManager = $storeManager;
    }

    public function replaceImageUrlWithCustom(Image $image, string $imageUrl)
    {
        $customType = $this->getCustomUrlType();

        // No action required, just use the default URL
        if ($customType === CustomConfig::TYPE_DEFAULT) {
            return $imageUrl;
        }

        $params = $image->getImageTransformationParameters();
        $params['file_path'] = $image->getFilePath();

        if ($customType === CustomConfig::TYPE_PATTERN) {
            return $this->getCustomUrlFromPattern($imageUrl, $params);
        }

        if ($customType === CustomConfig::TYPE_IMGPROXY) {
            return $this->getImgProxyUrl($imageUrl, $params);
        }

        throw new LocalizedException(
            __("The specified Custom Catalog media URL type '$customType' is not supported.")
        );
    }

    public function getCustomUrlFromPattern(string $imageUrl, array $params): string
    {
        $customUrl = $this->customConfig->getCustomPattern();

        $urlParts = parse_url($imageUrl);
        foreach ($urlParts as $part => $value) {
            $params['url_' . $part] = $value;
        }

        $params['path'] = ltrim($params['url_path'], '/');
        $params['base_url'] = $this->getBaseUrl();
        $params['base_url_media'] = $this->getMediaBaseUrl();
        $params['image_url'] = explode('?', $imageUrl)[0];

        foreach ($params as $k => $v) {
            $k = str_replace('-', '_', $k);
            if (strpos($customUrl, '{{'.$k) !== false) {
                $customUrl = str_replace('{{'.$k.'}}', $v, $customUrl);
                $customUrl = str_replace('{{'.$k.'|urlencode}}', urlencode($v), $customUrl);
                $customUrl = str_replace('{{'.$k.'|rawurlencode}}', rawurlencode($v), $customUrl);
                $customUrl = str_replace('{{'.$k.'|base64}}', base64_encode($v), $customUrl);
            }
        }

        return $customUrl;
    }

    /**
     * Based on https://github.com/imgproxy/imgproxy/blob/master/examples/signature.php
     * @param string $imageUrl
     * @param array $params
     * @return string
     */
    public function getImgProxyUrl(string $imageUrl, array $params)
    {
        $resize = $this->customConfig->getImgproxyResize();
        $width = $params['width'];
        $height = $params['height'];

        $urlParts = parse_url($imageUrl);
        $path = ltrim($urlParts['path'], '/');
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($this->customConfig->getImgproxySourceType() === CustomConfig::IMGPROXY_S3) {
            $prefix = trim($this->customConfig->getImgproxySourcePrefix(), '/');
            if (strlen($prefix) === 0) {
                throw new \RuntimeException('S3 Bucket cannot be empty');
            }
            $sourceUrl = 's3://' . $prefix . '/' . $path;
        } elseif ($this->customConfig->getImgproxySourceType() === CustomConfig::IMGPROXY_LOCAL) {
            $prefix = trim($this->customConfig->getImgproxySourcePrefix(), '/');
            if (strlen($prefix) > 0) {
                $path = $prefix . '/' . $path;
            }
            $sourceUrl = 'local:///' . $path;
        } elseif ($this->customConfig->getImgproxySourceType() === CustomConfig::IMGPROXY_CUSTOM_URL) {
            $proxyUrl = trim($this->customConfig->getImgproxySourceCustomUrl(), '/');
            $sourceUrl = $proxyUrl . '/' . $path;
        } else {
            $sourceUrl = $imageUrl;
        }

        $opts = [
            'proxy_url' => rtrim($this->customConfig->getImgproxyHost(), '/'),
            'gravity' => 'no',
            'extension' => 'webp',
            'key' => $this->customConfig->getImgproxyKey(),
            'salt' => $this->customConfig->getImgproxySalt(),
            'height' => ($params['width']) ? $params['width'] : 0,
            'width' => ($params['height']) ? $params['height'] : 0,
            'resize' => $this->customConfig->getImgproxyResize(),
            'enlarge' => '0',
        ];

        $encoded_url = base64_encode( $sourceUrl );
        $encoded_url = str_replace("=", "", $encoded_url); 
        $encoded_url = str_replace('/', '_', $encoded_url); 
        $encoded_url = str_replace('+', '-', $encoded_url); 

        if( $opts['height'] > 0 && $opts['width'] > 0 ) {
            $path = "/rs:" . $opts['resize'] . ":" . $opts['width'] . ":" . $opts['height'] . ":" .$opts['enlarge'].'/g:'. $opts['gravity'] . "/" . $encoded_url . "." . $opts['extension'];
        } else {
            $path = '/g:'. $opts['gravity'] . "/" . $encoded_url . "." . $opts['extension'];
        }

        // Sign the URL
        if (strlen($opts['key']) > 0 && strlen($opts['salt']) > 0) {
            $path = $this->signImgproxyPath($path, $opts);
        } else {
            $path = '/insecure' . $path;
        }
        return $opts['proxy_url'] .'/'. $path;
    }

    private function signImgproxyPath(string $path, array $opts): string
    {
        $key = hex2bin($opts['key']);
        if(empty($key)) {
            throw new \RuntimeException('Key expected to be hex-encoded string');
        }

        $salt = hex2bin($opts['salt']);
        if(empty($salt)) {
            throw new \RuntimeException('Salt expected to be hex-encoded string');
        }

        $hmac = hash_hmac('sha256', $salt . $path, $key, true);
        $hmac = base64_encode($hmac);

        $hmac = str_replace("=", "", $hmac);
        $hmac = str_replace('/', '_', $hmac);
        $hmac = str_replace('+', '-', $hmac);

        return $hmac . $path;        
    }

    /**
     * @param null|int $storeId
     *
     * @return string|null
     */
    public function getCustomUrlType($storeId = null)
    {
        // When Dynamic hashing is disabled, return false
        if ($this->catalogMediaConfig->getMediaUrlFormat(ScopeInterface::SCOPE_STORE, $storeId) !== CatalogMediaConfig::IMAGE_OPTIMIZATION_PARAMETERS) {
            return CustomConfig::TYPE_DEFAULT;
        }

        return $this->customConfig->getCustomType($storeId);
    }

    private function getBaseUrl($storeId = null)
    {
        return $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_LINK);
    }

    public function getMediaBaseUrl($storeId = null)
    {
        return $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
    }
}
