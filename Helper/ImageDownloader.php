<?php
namespace Netzkollektiv\VeezueImporter\Helper;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Psr\Log\LoggerInterface;

class ImageDownloader
{
    private $mediaDirectory;

    //protected $sceneIds = [ 8434, 8444, 8454, 8464 ];

    private $availableSkus = null;

    public function __construct (
        Filesystem $filesystem,
        private Curl $curl,
	private Info $info,
        private LoggerInterface $logger
    ) {
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    private function getExistingSku ($sku) {
        if ($this->availableSkus == null) {
            $this->availableSkus = array_map(fn ($item) => $item['article_nr'], $this->info->getJson($this->info->buildUrl('get_datalist_materials.php', [
                'cols' => 'article_nr'
            ]))['resultlist']);
            print_r($this->availableSkus);
        }

        $index = array_search($sku, $this->availableSkus);
        if ($index !== false) {
            return $this->availableSkus[$index];
        }
        return false;
    }

    public function getImageUrls($sku)
    {
        $imgUrls = [];
        
        $designerSku = $this->getExistingSku($sku);

        if (!$designerSku) {
            return $imgUrls;
        }

        try {
            // Fetch scenes
            $scenes = $this->info->getSceneIds();

            foreach ($scenes as $sceneId) {
                $sceneId = (int)$sceneId;

                $imgUrl = $this->info->buildUrl('render_img_scene.php',[
                    'sid' => $sceneId,
                    'artnr' => $designerSku,
                    'w' => 1920,
                    'h' => 1080
                ]);

                $imgUrls[$sceneId] = [
                    'fileName' => "{$sku}_{$sceneId}.jpg",
                    'url' => $imgUrl
                ];
            }

            // Fetch material images
            foreach ([0, 1] as $show3d) {
                $suffix = $show3d ? '_3d' : '';
                
                $sceneId = 'material'.$suffix;

                $imgUrl = $this->info->buildUrl('get_img_material.php', [
                    'artnr' => $designerSku,
                    'w' => 1920,
                    'h' => 1080,
                    'show3d' => $show3d
                ]);
  
                $imgUrls[$sceneId] = [
                    'fileName' => "{$sku}_material$suffix.jpg",
                    'url' => $imgUrl
                ];
            }

            $pos = 0;
            foreach ($imgUrls as $sceneId =>  &$imgUrl) {
                $pos += 1;
                $imgUrl['position'] = $pos;
                
                if (!isset($imgUrls['types'])) {
                    $imgUrl['types'] = [];
                }

                foreach ($this->info->getImageRoles() as $role => $roleSceneId) {
                    if ((string)$sceneId !== (string)$roleSceneId) {
                        continue;
                    }
                    $imgUrl['types'][] = $role;
                }
            }
            return $imgUrls;
        } catch (\Exception $e) {
            throw new \Exception('Error downloading images: ' . $e->getMessage());
        }
    }

    public function downloadImage($url, $fileName)
    {
        $filePath = $this->mediaDirectory->getAbsolutePath('import/' . $fileName);

        try {
            $this->curl->get($url);
            if ($this->curl->getStatus() !== 200) {
                throw new \Exception('could not download image, HTTP Status '. $this->curl->getStatus());
            }
            file_put_contents($filePath, $this->curl->getBody());
            return $filePath;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return false;
        }
    }
}
