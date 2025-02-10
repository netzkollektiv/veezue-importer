<?php
namespace Netzkollektiv\VeezueImporter\Console\Command;

use Magento\Framework\Console\Cli;
use Magento\Framework\App\State;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\MediaStorage\Model\File\UploaderFactory;

use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Netzkollektiv\VeezueImporter\Helper\ImageDownloader;

class ImportImages extends Command
{
    public function __construct(
        private ProductFactory $productFactory,
        private Curl $curl,
        private ProductRepositoryInterface $productRepository,
        private UploaderFactory $uploaderFactory,
        private State $appState,
        private ImageDownloader $imageDownloader,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('veezue:import-images')
             ->setDescription('Imports images for products with the raumdesigner_sku attribute.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->appState->setAreaCode('adminhtml');
        
        $output->writeln("<info>Starting image import process...</info>");
        
        try {
            $this->importImagesForProducts($output);
            $output->writeln("<info>Images successfully imported!</info>");
        } catch (\Exception $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * Import images for products with the raumdesigner_sku attribute set.
     */
    public function importImagesForProducts($output)
    {
        // Get products with the raumdesigner_sku attribute
        $products = $this->getProductsWithRaumdesignerSku();

        foreach ($products as $product) {
            $sku = $product->getData('raumdesigner_sku');
            if (!$sku) {
                $output->writeln("<comment>raumdesigner_sku is not set for product {$product->getSku()}, skipping</comment>");
            }
            
            $images = $this->imageDownloader->getImageUrls($sku);
            
            if (!$images) {
                $output->writeln("<comment>No material available for product {$product->getSku()}, skipping</comment>");
                continue;
            }
            
            $cnt = count($images);
            $output->writeln("Adding {$cnt} images to product {$product->getSku()}...");

            $this->importImages($output, $product, $images);
        }
    }

    /**
     * Import images into Magento for the given product.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param array $images
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function importImages($output, $product, $images)
    {
        $product = $this->productRepository->getById($product->getId());

        $toBeAdded = [];
        $existingImages = [];

        $mediaGalleryEntries = $product->getMediaGalleryEntries();
        $mediaGalleryEntriesBefore = $this->cloneGallery($mediaGalleryEntries);

        foreach ($images as $image) {
            if ($entry = $this->getExistingImage($image, $mediaGalleryEntries)) {
                $output->writeln("<info>Skipping image {$image['fileName']}, already present ({$entry->getFile()})</info>");
                $entry->setTypes($image['types'] ?? []);
                $entry->setPosition((string)$image['position'] ?? '1');

                $existingImages[] = $entry->getFile();
            } else {
                $toBeAdded[] = $image;
            }
        }

        // delete all other images not managed by us
        foreach ($mediaGalleryEntries as $key => $entry) {
            if (!in_array($entry->getFile(), $existingImages)) {
                $output->writeln("<info>Removing image {$entry->getFile()} from product {$product->getSku()}</info>");
                unset($mediaGalleryEntries[$key]);
            }
        }

        $product->setMediaGalleryEntries($mediaGalleryEntries);

        foreach ($toBeAdded as $image) {
            $output->writeln("<info>Adding image {$image['fileName']} ...</info>");
            $this->addImage($product, $image);
        }

        if (count($toBeAdded) > 0 || $this->entriesChanged($mediaGalleryEntriesBefore, $mediaGalleryEntries)) {
            $output->writeln("<info>Saving product {$product->getSku()}</info>");
            $this->productRepository->save($product);
        }
    }

    private function cloneGallery ($mediaGalleryEntries) {
        $cloned = [];
        foreach ($mediaGalleryEntries as $index => $entry) {
            $cloned[$index] = clone $entry;
        }
        return $cloned;
    } 

    private function entriesChanged($originalEntries, $updatedEntries)
    {
        // Serialize entries for comparison
        $originalData = array_map([$this, 'serializeEntry'], $originalEntries);
        $updatedData = array_map([$this, 'serializeEntry'], $updatedEntries);

        return $originalData !== $updatedData;
    }

    private function serializeEntry($entry)
    {
        return [
            'file' => $entry->getFile(),
            'types' => $entry->getTypes(),
            'position' => $entry->getPosition(),
            'label' => $entry->getLabel(),
        ];
    }

    protected function getExistingImage($image, $mediaGalleryEntries) {
        foreach ($mediaGalleryEntries as $key => $entry) {
            $entryBasenameWithoutExtension = explode('.', basename($entry->getFile()))[0];
            $imageFileNameWithoutExtension = explode('.', $image['fileName'])[0];
            if (str_starts_with($entryBasenameWithoutExtension, $imageFileNameWithoutExtension)) {
                return $entry;
            }
        }
        return false;
    }

    /**
     * Upload image to the product in Magento.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param string $filePath
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function addImage($product, $image)
    {
        try {
            $filePath = $this->imageDownloader->downloadImage($image['url'], $image['fileName']);
            $product->addImageToMediaGallery($filePath, $image['types'] ?? null, false, false);
            $this->productRepository->save($product);
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Error uploading image: ' . $e->getMessage()));
        }
    }

    /**
     * Get products with the raumdesigner_sku attribute set.
     *
     * @return \Magento\Catalog\Model\Product[]
     */
    protected function getProductsWithRaumdesignerSku()
    {
        // Replace with your custom logic to fetch the products with raumdesigner_sku set
        $productCollection = $this->productFactory->create()->getCollection()
            ->addFieldToFilter('raumdesigner_sku', ['notnull' => true])
            //->addFieldToFilter('sku', ['eq' => '00052467B'])
            ;

        return $productCollection;
    }
}
