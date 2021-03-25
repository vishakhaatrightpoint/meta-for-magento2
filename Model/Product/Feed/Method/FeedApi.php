<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 */

namespace Facebook\BusinessExtension\Model\Product\Feed\Method;

use Exception;
use Facebook\BusinessExtension\Helper\FBEHelper;
use Facebook\BusinessExtension\Helper\GraphAPIAdapter;
use Facebook\BusinessExtension\Model\Product\Feed\Builder;
use Facebook\BusinessExtension\Model\Product\Feed\ProductRetrieverInterface;
use Facebook\BusinessExtension\Model\Product\Feed\ProductRetriever\Simple as SimpleProductRetriever;
use Facebook\BusinessExtension\Model\Product\Feed\ProductRetriever\Configurable as ConfigurableProductRetriever;
use Facebook\BusinessExtension\Model\System\Config as SystemConfig;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\File\WriteInterface;
use Psr\Log\LoggerInterface;

class FeedApi
{
    const FEED_FILE_NAME = 'facebook_products.csv';
    const FB_FEED_NAME = 'Magento Autogenerated Feed';

    /**
     * @var SystemConfig
     */
    protected $systemConfig;

    /**
     * @var GraphAPIAdapter
     */
    protected $graphApiAdapter;

    /**
     * @var ProductCollection
     */
    protected $productCollection;

    /**
     * @var CategoryCollection
     */
    protected $categoryCollection;

    /**
     * @var Filesystem
     */
    protected $fileSystem;

    /**
     * @var FBEHelper
     */
    protected $_fbeHelper;

    /**
     * @var ProductRetrieverInterface[]
     */
    protected $productRetrievers;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Builder
     */
    protected $builder;

    /**
     * @param SystemConfig $systemConfig
     * @param GraphAPIAdapter $graphApiAdapter
     * @param Filesystem $filesystem
     * @param ProductCollection $productCollection
     * @param CategoryCollection $categoryCollection
     * @param FBEHelper $fbeHelper
     * @param SimpleProductRetriever $simpleProductRetriever
     * @param ConfigurableProductRetriever $configurableProductRetriever
     * @param Builder $builder
     * @param LoggerInterface $logger
     */
    public function __construct(
        SystemConfig $systemConfig,
        GraphAPIAdapter $graphApiAdapter,
        Filesystem $filesystem,
        ProductCollection $productCollection,
        CategoryCollection $categoryCollection,
        FBEHelper $fbeHelper,
        SimpleProductRetriever $simpleProductRetriever,
        ConfigurableProductRetriever $configurableProductRetriever,
        Builder $builder,
        LoggerInterface $logger
    )
    {
        $this->systemConfig = $systemConfig;
        $this->graphApiAdapter = $graphApiAdapter;
        $this->fileSystem = $filesystem;
        $this->productCollection = $productCollection;
        $this->categoryCollection = $categoryCollection;
        $this->_fbeHelper = $fbeHelper;
        $this->productRetrievers = [
            $simpleProductRetriever,
            $configurableProductRetriever
        ];
        $this->builder = $builder;
        $this->logger = $logger;
    }

    /**
     * @return string|false
     */
    protected function getFbFeedId()
    {
        $feedId = $this->systemConfig->getFeedId();
        $feedName = self::FB_FEED_NAME;

        if (!$feedId) {
            $catalogFeeds = $this->graphApiAdapter->getCatalogFeeds();
            $magentoFeeds = array_filter($catalogFeeds, function ($a) use ($feedName) {
                return $a['name'] === $feedName;
            });
            if (!empty($magentoFeeds)) {
                $feedId = $magentoFeeds[0]['id'];
            }
        }

        if (!$feedId) {
            $feedId = $this->graphApiAdapter->createEmptyFeed($feedName);

            $maxAttempts = 5;
            $attempts = 0;
            do {
                $feedData = $this->graphApiAdapter->getFeed($feedId);
                if ($feedData !== false) {
                    break;
                }
                $attempts++;
                sleep(2);
            } while ($attempts < $maxAttempts);
        }

        if (!$this->systemConfig->getFeedId() && $feedId) {
            $this->systemConfig->saveConfig(SystemConfig::XML_PATH_FACEBOOK_BUSINESS_EXTENSION_FEED_ID, $feedId)
                ->cleanCache();
        }
        return $feedId;
    }

    /**
     * @param WriteInterface $fileStream
     * @throws FileSystemException
     * @throws Exception
     */
    protected function writeFile(WriteInterface $fileStream)
    {
        $fileStream->writeCsv($this->builder->getHeaderFields());

        $total = 0;
        foreach ($this->productRetrievers as $productRetriever) {
            $offset = 0;
            $limit = $productRetriever->getLimit();
            do {
                $products = $productRetriever->retrieve($offset);
                $offset += $limit;
                if (empty($products)) {
                    break;
                }
                foreach ($products as $product) {
                    $entry = array_values($this->builder->buildProductEntry($product));
                    $fileStream->writeCsv($entry);
                    $total++;
                }
            } while (true);
        }

        $this->logger->debug(sprintf('Generated feed with %d products.', $total));
    }

    /**
     * @return string
     * @throws FileSystemException
     */
    protected function generateProductFeed()
    {
        $file = 'export/' . self::FEED_FILE_NAME;
        $directory = $this->fileSystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $directory->create('export');

        //return $directory->getAbsolutePath($file);

        $stream = $directory->openFile($file, 'w+');
        $stream->lock();
        $this->writeFile($stream);
        $stream->unlock();

        return $directory->getAbsolutePath($file);
    }

    public function execute()
    {
        try {
            $feedId = $this->getFbFeedId();
            if (!$feedId) {
                throw new LocalizedException(__('Cannot fetch feed ID'));
            }
            $feed = $this->generateProductFeed();
            return $this->graphApiAdapter->pushProductFeed($feedId, $feed);
        } catch (Exception $e) {
            $this->_fbeHelper->logException($e);
            return false;
        }
    }
}
