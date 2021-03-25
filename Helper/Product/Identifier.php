<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 */

namespace Facebook\BusinessExtension\Helper\Product;

use Facebook\BusinessExtension\Model\Config\Source\Product\Identifier as IdentifierConfig;
use Facebook\BusinessExtension\Model\System\Config as SystemConfig;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class Identifier
{
    /**
     * @var ProductRepository
     */
    private $productRepository;

    /**
     * @var string
     */
    private $identifierAttr;

    /**
     * @param SystemConfig $systemConfig
     * @param ProductRepository $productRepository
     */
    public function __construct(SystemConfig $systemConfig, ProductRepository $productRepository)
    {
        $this->identifierAttr = $systemConfig->getProductIdentifierAttr();
        $this->productRepository = $productRepository;
    }

    /**
     * @param Product $product
     * @return int|string|bool
     */
    public function getMagentoProductRetailerId(Product $product)
    {
        if ($this->identifierAttr === IdentifierConfig::PRODUCT_IDENTIFIER_SKU) {
            return $product->getSku();
        } else if ($this->identifierAttr === IdentifierConfig::PRODUCT_IDENTIFIER_ID) {
            return $product->getId();
        }
        return false;
    }

    /**
     * @param $retailerId
     * @return Product|bool
     * @throws LocalizedException
     */
    public function getProductByFacebookRetailerId($retailerId)
    {
        try {
            if ($this->identifierAttr === IdentifierConfig::PRODUCT_IDENTIFIER_SKU) {
                return $this->productRepository->get($retailerId);
            } else if ($this->identifierAttr === IdentifierConfig::PRODUCT_IDENTIFIER_ID) {
                return $this->productRepository->getById($retailerId);
            }
            throw new LocalizedException(__('Invalid FB product identifier configuration'));
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__(sprintf('Product with %s %s does not exist in Magento catalog',
                strtoupper($this->identifierAttr), $retailerId)));
        }
    }
}
