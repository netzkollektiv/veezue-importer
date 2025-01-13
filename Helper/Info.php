<?php
namespace Netzkollektiv\VeezueImporter\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;

class Info {

    const CONFIG_BASE_URL_PATH = 'veezue_imageimporter/general_settings/base_url';
    const CONFIG_KEY_PATH = 'veezue_imageimporter/general_settings/cnf';
    const CONFIG_SCENE_IDS_PATH = 'veezue_imageimporter/scene_settings/scene_ids';

    const CONFIG_BASE_IMAGE_SCENE_ID_PATH = 'veezue_imageimporter/scene_settings/base_image';
    const CONFIG_SMALL_IMAGE_SCENE_ID_PATH = 'veezue_imageimporter/scene_settings/small_image';
    const CONFIG_THUMBNAIL_SCENE_ID_PATH = 'veezue_imageimporter/scene_settings/thumbnail';


    public function __construct(
        private ScopeConfigInterface $scopeConfig,
        private Curl $curl
    ) {
    }

    public function getBaseUrl()
    {
        return $this->scopeConfig->getValue(
            self::CONFIG_BASE_URL_PATH,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    public function getConfigKey()
    {
        return $this->scopeConfig->getValue(
            self::CONFIG_KEY_PATH,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    public function getImageRoles()
    {
        return [
            'image' => $this->scopeConfig->getValue(self::CONFIG_BASE_IMAGE_SCENE_ID_PATH, \Magento\Store\Model\ScopeInterface::SCOPE_STORE),
            'small_image' => $this->scopeConfig->getValue(self::CONFIG_SMALL_IMAGE_SCENE_ID_PATH, \Magento\Store\Model\ScopeInterface::SCOPE_STORE),
            'thumbnail' => $this->scopeConfig->getValue(self::CONFIG_THUMBNAIL_SCENE_ID_PATH, \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
        ];
    }

    public function getSceneIds()
    {
        return explode(',',$this->scopeConfig->getValue(
            self::CONFIG_SCENE_IDS_PATH,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ));
    }

    public function getAvailableSceneIds () {
        return array_map(
            fn($scene) => $scene['id'],
            $this->getJson($this->buildUrl('get_datalist_scenes.php'))['scenes']
        );
    }

    public function buildUrl($endpoint, $params = [])
    {
        $baseUrl = $this->getBaseUrl();
        if ($baseUrl === null) {
            return null;
        }

        $params['cnf'] = $this->getConfigKey();

        $queryString = http_build_query($params);
        $queryDelimiter = strpos($baseUrl . $endpoint, '?') === false ? '?' : '&';
        return $baseUrl . $endpoint . $queryDelimiter . $queryString;
    }

    public function getJson($uri) {
        if (!$uri) {
            throw new \Exception('uri is not valid');
        }
        $scenes = $this->curl->get($uri);

        if ($this->curl->getStatus() !== 200) {
            throw new \Exception('could not get '.$uri.', HTTP Status '. $this->curl->getStatus());
        }
        $result = json_decode($this->curl->getBody(), true);
        return $result;
    }
}