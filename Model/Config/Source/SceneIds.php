<?php

namespace  Netzkollektiv\VeezueImporter\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

use Magento\Framework\HTTP\Client\Curl;
use Netzkollektiv\VeezueImporter\Helper\Info;

class SceneIds implements OptionSourceInterface
{
    public function __construct(
        private Info $info,
        private Curl $curl
    ) {
    }

    /**
     * Retrieve scene IDs from the web service.
     *
     * @return array
     */
    public function toOptionArray()
    {
        $options = [];
        try {
            $sceneIds = $this->info->getAvailableSceneIds();
            asort($sceneIds);
            foreach ($sceneIds as $id) {
                $options[] = ['value' => $id, 'label' => $id];
            }
        } catch (\Exception $e) {
            $options[] = ['value' => '', 'label' => 'Error retrieving scene IDs: ' . $e->getMessage()];
        }

        return $options;
    }
}