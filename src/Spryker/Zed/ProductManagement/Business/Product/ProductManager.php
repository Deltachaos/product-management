<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\ProductManagement\Business\Product;

use Generated\Shared\Transfer\LocaleTransfer;
use Generated\Shared\Transfer\ProductAbstractTransfer;
use Generated\Shared\Transfer\ZedProductConcreteTransfer;
use Orm\Zed\Product\Persistence\SpyProduct;
use Orm\Zed\Product\Persistence\SpyProductAbstract;
use Orm\Zed\Product\Persistence\SpyProductAbstractLocalizedAttributes;
use Orm\Zed\Product\Persistence\SpyProductLocalizedAttributes;
use Spryker\Shared\Library\Json;
use Spryker\Zed\ProductManagement\Business\Transfer\ProductTransferGenerator;
use Spryker\Zed\ProductManagement\Dependency\Facade\ProductManagementToLocaleInterface;
use Spryker\Zed\ProductManagement\Dependency\Facade\ProductManagementToTouchInterface;
use Spryker\Zed\ProductManagement\Dependency\Facade\ProductManagementToUrlInterface;
use Spryker\Zed\Product\Business\Attribute\AttributeManagerInterface;
use Spryker\Zed\Product\Business\Exception\MissingProductException;
use Spryker\Zed\Product\Business\Exception\ProductAbstractAttributesExistException;
use Spryker\Zed\Product\Business\Exception\ProductAbstractExistsException;
use Spryker\Zed\Product\Business\Exception\ProductConcreteAttributesExistException;
use Spryker\Zed\Product\Business\Exception\ProductConcreteExistsException;
use Spryker\Zed\Product\Persistence\ProductQueryContainerInterface;

class ProductManager implements ProductManagerInterface
{

    const COL_ID_PRODUCT_CONCRETE = 'SpyProduct.IdProduct';

    const COL_ABSTRACT_SKU = 'SpyProductAbstract.Sku';

    const COL_ID_PRODUCT_ABSTRACT = 'SpyProductAbstract.IdProductAbstract';

    const COL_NAME = 'SpyProductLocalizedAttributes.Name';

    /**
     * @var \Spryker\Zed\Product\Business\Attribute\AttributeManagerInterface
     */
    protected $attributeManager;

    /**
     * @var \Spryker\Zed\Product\Persistence\ProductQueryContainerInterface
     */
    protected $productQueryContainer;

    /**
     * @var \Spryker\Zed\ProductManagement\Dependency\Facade\ProductManagementToTouchInterface
     */
    protected $touchFacade;

    /**
     * @var \Spryker\Zed\ProductManagement\Dependency\Facade\ProductManagementToUrlInterface
     */
    protected $urlFacade;

    /**
     * @var \Spryker\Zed\ProductManagement\Dependency\Facade\ProductManagementToLocaleInterface
     */
    protected $localeFacade;

    /**
     * @var \Orm\Zed\Product\Persistence\SpyProductAbstract[]
     */
    protected $productAbstractCollectionBySkuCache = [];

    /**
     * @var \Orm\Zed\Product\Persistence\SpyProduct[]
     */
    protected $productConcreteCollectionBySkuCache = [];

    /**
     * @var array
     */
    protected $productAbstractsBySkuCache;

    public function __construct(
        AttributeManagerInterface $attributeManager,
        ProductQueryContainerInterface $productQueryContainer,
        ProductManagementToTouchInterface $touchFacade,
        ProductManagementToUrlInterface $urlFacade,
        ProductManagementToLocaleInterface $localeFacade
    ) {
        $this->productQueryContainer = $productQueryContainer;
        $this->touchFacade = $touchFacade;
        $this->urlFacade = $urlFacade;
        $this->localeFacade = $localeFacade;
        $this->attributeManager = $attributeManager;
    }

    /**
     * @param string $sku
     *
     * @return bool
     */
    public function hasProductAbstract($sku)
    {
        $productAbstractQuery = $this->productQueryContainer->queryProductAbstractBySku($sku);

        return $productAbstractQuery->count() > 0;
    }

    /**
     * @param \Generated\Shared\Transfer\ProductAbstractTransfer $productAbstractTransfer
     *
     * @throws \Spryker\Zed\Product\Business\Exception\ProductAbstractExistsException
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return int
     */
    public function createProductAbstract(ProductAbstractTransfer $productAbstractTransfer)
    {
        $sku = $productAbstractTransfer->getSku();
        $this->checkProductAbstractDoesNotExist($sku);

        $jsonAttributes = $this->encodeAttributes($productAbstractTransfer->getAttributes());

        $productAbstract = new SpyProductAbstract();
        $productAbstract
            ->setAttributes($jsonAttributes)
            ->setSku($sku);

        $productAbstract->save();

        $idProductAbstract = $productAbstract->getPrimaryKey();
        $productAbstractTransfer->setIdProductAbstract($idProductAbstract);
        $this->createProductAbstractLocalizedAttributes($productAbstractTransfer);

        return $idProductAbstract;
    }

    /**
     * @param \Generated\Shared\Transfer\ProductAbstractTransfer $productAbstractTransfer
     *
     * @throws \Spryker\Zed\Product\Business\Exception\MissingProductException
     * @throws \Spryker\Zed\Product\Business\Exception\ProductAbstractExistsException
     * @throws \Propel\Runtime\Exception\PropelException
     * @return int
     */
    public function saveProductAbstract(ProductAbstractTransfer $productAbstractTransfer)
    {
        $sku = $productAbstractTransfer->getSku();
        $idProductAbstract = $productAbstractTransfer->requireIdProductAbstract()->getIdProductAbstract();

        $productAbstract = $this->productQueryContainer
            ->queryProductAbstract()
            ->filterByIdProductAbstract($idProductAbstract)
            ->findOne();

        if (!$productAbstract) {
            throw new MissingProductException(sprintf(
                'Tried to retrieve an product abstract with id %s, but it does not exist.',
                $idProductAbstract
            ));
        }

        $existingAbstractSku = $this->productQueryContainer
            ->queryProductAbstractBySku($sku)
            ->findOne();

        if ($existingAbstractSku) {
            if ($idProductAbstract !== (int)$existingAbstractSku->getIdProductAbstract()) {
                throw new ProductAbstractExistsException(sprintf(
                    'Tried to create an product abstract with sku %s that already exists',
                    $sku
                ));
            }
        }

        $jsonAttributes = $this->encodeAttributes($productAbstractTransfer->getAttributes());

        $productAbstract
            ->setAttributes($jsonAttributes)
            ->setSku($sku);

        $productAbstract->save();

        $idProductAbstract = $productAbstract->getPrimaryKey();
        $productAbstractTransfer->setIdProductAbstract($idProductAbstract);
        $this->saveProductAbstractLocalizedAttributes($productAbstractTransfer);

        return $idProductAbstract;
    }

    /**
     * @param string $sku
     *
     * @throws \Spryker\Zed\Product\Business\Exception\MissingProductException
     *
     * @return int
     */
    public function getProductAbstractIdBySku($sku)
    {
        if (!isset($this->productAbstractsBySkuCache[$sku])) {
            $productAbstract = $this->productQueryContainer->queryProductAbstractBySku($sku)->findOne();

            if (!$productAbstract) {
                throw new MissingProductException(sprintf(
                    'Tried to retrieve an product abstract with sku %s, but it does not exist.',
                    $sku
                ));
            }

            $this->productAbstractsBySkuCache[$sku] = $productAbstract;
        }

        return $this->productAbstractsBySkuCache[$sku]->getPrimaryKey();
    }

    /**
     * @param string $sku
     *
     * @throws \Spryker\Zed\Product\Business\Exception\ProductAbstractExistsException
     *
     * @return void
     */
    protected function checkProductAbstractDoesNotExist($sku)
    {
        if ($this->hasProductAbstract($sku)) {
            throw new ProductAbstractExistsException(sprintf(
                'Tried to create an product abstract with sku %s that already exists',
                $sku
            ));
        }
    }

    /**
     * TODO move to AttributeManager
     *
     * @param \Generated\Shared\Transfer\ProductAbstractTransfer $productAbstractTransfer
     *
     * @throws \Spryker\Zed\Product\Business\Exception\ProductAbstractAttributesExistException
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return void
     */
    protected function createProductAbstractLocalizedAttributes(ProductAbstractTransfer $productAbstractTransfer)
    {
        $idProductAbstract = $productAbstractTransfer->getIdProductAbstract();

        foreach ($productAbstractTransfer->getLocalizedAttributes() as $localizedAttributes) {
            $locale = $localizedAttributes->getLocale();
            if ($this->hasProductAbstractAttributes($idProductAbstract, $locale)) {
                continue;
            }

            $encodedAttributes = $this->encodeAttributes($localizedAttributes->getAttributes());

            $productAbstractAttributesEntity = new SpyProductAbstractLocalizedAttributes();
            $productAbstractAttributesEntity
                ->setFkProductAbstract($idProductAbstract)
                ->setFkLocale($locale->getIdLocale())
                ->setName($localizedAttributes->getName())
                ->setAttributes($encodedAttributes);

            $productAbstractAttributesEntity->save();
        }
    }

    /**
     * TODO move to AttributeManager
     *
     * @param \Generated\Shared\Transfer\ProductAbstractTransfer $productAbstractTransfer
     *
     * @throws \Spryker\Zed\Product\Business\Exception\ProductAbstractAttributesExistException
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return void
     */
    protected function saveProductAbstractLocalizedAttributes(ProductAbstractTransfer $productAbstractTransfer)
    {
        $idProductAbstract = $productAbstractTransfer->getIdProductAbstract();

        foreach ($productAbstractTransfer->getLocalizedAttributes() as $localizedAttributes) {
            $locale = $localizedAttributes->getLocale();
            $jsonAttributes = $this->encodeAttributes($localizedAttributes->getAttributes());

            $localizedProductAttributesEntity = $this->productQueryContainer
                ->queryProductAbstractAttributeCollection($idProductAbstract, $locale->getIdLocale())
                ->findOneOrCreate();

            $localizedProductAttributesEntity
                ->setFkProductAbstract($idProductAbstract)
                ->setFkLocale($locale->getIdLocale())
                ->setName($localizedAttributes->getName())
                ->setAttributes($jsonAttributes);

            $localizedProductAttributesEntity->save();
        }
    }

    /**
     * TODO move to AttributeManager
     *
     * @param int $idProductAbstract
     * @param \Generated\Shared\Transfer\LocaleTransfer $locale
     *
     * @deprecated Use hasProductAbstractAttributes() instead.
     *
     * @throws \Spryker\Zed\Product\Business\Exception\ProductAbstractAttributesExistException
     *
     * @return void
     */
    protected function checkProductAbstractAttributesDoNotExist($idProductAbstract, $locale)
    {
        if ($this->hasProductAbstractAttributes($idProductAbstract, $locale)) {
            throw new ProductAbstractAttributesExistException(sprintf(
                'Tried to create abstract attributes for product abstract %s, locale id %s, but it already exists',
                $idProductAbstract,
                $locale->getIdLocale()
            ));
        }
    }

    /**
     * TODO move to AttributeManager
     *
     * @param int $idProductAbstract
     * @param \Generated\Shared\Transfer\LocaleTransfer $locale
     *
     * @return bool
     */
    protected function hasProductAbstractAttributes($idProductAbstract, LocaleTransfer $locale)
    {
        $query = $this->productQueryContainer->queryProductAbstractAttributeCollection(
            $idProductAbstract,
            $locale->getIdLocale()
        );

        return $query->count() > 0;
    }

    /**
     * @param \Generated\Shared\Transfer\ZedProductConcreteTransfer $productConcreteTransfer
     * @param int $idProductAbstract
     *
     * @throws \Spryker\Zed\Product\Business\Exception\ProductConcreteExistsException
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return int
     */
    public function createProductConcrete(ZedProductConcreteTransfer $productConcreteTransfer, $idProductAbstract)
    {
        $sku = $productConcreteTransfer->getSku();
        $this->checkProductConcreteDoesNotExist($sku);

        $encodedAttributes = $this->encodeAttributes($productConcreteTransfer->getAttributes());

        $productConcreteEntity = new SpyProduct();
        $productConcreteEntity
            ->setSku($sku)
            ->setFkProductAbstract($idProductAbstract)
            ->setAttributes($encodedAttributes)
            ->setIsActive($productConcreteTransfer->getIsActive());

        $productConcreteEntity->save();

        $idProductConcrete = $productConcreteEntity->getPrimaryKey();
        $productConcreteTransfer->setIdProductConcrete($idProductConcrete);

        $this->createProductConcreteLocalizedAttributes($productConcreteTransfer);
        $this->loadTaxRate($productConcreteTransfer);

        return $idProductConcrete;
    }

    /**
     * @param \Generated\Shared\Transfer\ZedProductConcreteTransfer $productConcreteTransfer
     *
     * @throws \Spryker\Zed\Product\Business\Exception\MissingProductException
     * @throws \Spryker\Zed\Product\Business\Exception\ProductAbstractExistsException
     * @throws \Propel\Runtime\Exception\PropelException
     * @return int
     */
    public function saveProductConcrete(ZedProductConcreteTransfer $productConcreteTransfer)
    {
        $sku = $productConcreteTransfer->requireSku()->getSku();
        $idProduct = (int)$productConcreteTransfer->requireIdProductConcrete()->getIdProductConcrete();
        $idProductAbstract = (int)$productConcreteTransfer->requireFkProductAbstract()->getFkProductAbstract();

        $productConcreteEntity = $this->productQueryContainer
            ->queryProduct()
            ->filterByIdProduct($idProduct)
            ->findOne();

        if (!$productConcreteEntity) {
            throw new MissingProductException(sprintf(
                'Tried to retrieve an product concrete with id %s, but it does not exist.',
                $idProduct
            ));
        }

        $existingSku = $this->productQueryContainer
            ->queryProduct()
            ->filterBySku($sku)
            ->findOne();

        if ($existingSku) {
            if ($idProduct !== (int)$existingSku->getIdProduct()) {
                throw new ProductAbstractExistsException(sprintf(
                    'Tried to create an product concrete with sku %s that already exists',
                    $sku
                ));
            }
        }

        $jsonAttributes = $this->encodeAttributes($productConcreteTransfer->getAttributes());

        $productConcreteEntity
            ->setSku($sku)
            ->setFkProductAbstract($idProductAbstract)
            ->setAttributes($jsonAttributes)
            ->setIsActive($productConcreteTransfer->getIsActive());

        $productConcreteEntity->save();

        $idProductConcrete = $productConcreteEntity->getPrimaryKey();
        $productConcreteTransfer->setIdProductConcrete($idProductConcrete);

        $this->saveProductConcreteLocalizedAttributes($productConcreteTransfer);

        return $idProductConcrete;
    }

    /**
     * @param string $sku
     *
     * @throws \Spryker\Zed\Product\Business\Exception\ProductConcreteExistsException
     *
     * @return void
     */
    protected function checkProductConcreteDoesNotExist($sku)
    {
        if ($this->hasProductConcrete($sku)) {
            throw new ProductConcreteExistsException(sprintf(
                'Tried to create a product concrete with sku %s, but it already exists',
                $sku
            ));
        }
    }

    /**
     * @param string $sku
     *
     * @return bool
     */
    public function hasProductConcrete($sku)
    {
        return $this->productQueryContainer->queryProductConcreteBySku($sku)->count() > 0;
    }

    /**
     * @param string $sku
     *
     * @throws \Spryker\Zed\Product\Business\Exception\MissingProductException
     *
     * @return int
     */
    public function getProductConcreteIdBySku($sku)
    {
        if (!isset($this->productConcreteCollectionBySkuCache[$sku])) {
            $productConcrete = $this->productQueryContainer
                ->queryProductConcreteBySku($sku)
                ->findOne();

            if (!$productConcrete) {
                throw new MissingProductException(sprintf(
                    'Tried to retrieve a product concrete with sku %s, but it does not exist',
                    $sku
                ));
            }

            $this->productConcreteCollectionBySkuCache[$sku] = $productConcrete;
        }

        return $this->productConcreteCollectionBySkuCache[$sku]->getPrimaryKey();
    }

    /**
     * TODO move to AttributeManager
     *
     * @param \Generated\Shared\Transfer\ZedProductConcreteTransfer $productConcreteTransfer
     *
     * @throws \Spryker\Zed\Product\Business\Exception\ProductConcreteAttributesExistException
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return void
     */
    protected function createProductConcreteLocalizedAttributes(ZedProductConcreteTransfer $productConcreteTransfer)
    {
        $idProductConcrete = $productConcreteTransfer->getIdProductConcrete();

        foreach ($productConcreteTransfer->getLocalizedAttributes() as $localizedAttributes) {
            $locale = $localizedAttributes->getLocale();
            $this->checkProductConcreteAttributesDoNotExist($idProductConcrete, $locale);

            $jsonAttributes = $this->encodeAttributes($localizedAttributes->getAttributes());

            $productAttributeEntity = new SpyProductLocalizedAttributes();
            $productAttributeEntity
                ->setFkProduct($idProductConcrete)
                ->setFkLocale($locale->getIdLocale())
                ->setName($localizedAttributes->getName())
                ->setAttributes($jsonAttributes);

            $productAttributeEntity->save();
        }
    }

    /**
     * TODO move to AttributeManager
     *
     * @param \Generated\Shared\Transfer\ZedProductConcreteTransfer $productConcreteTransfer
     *
     * @throws \Spryker\Zed\Product\Business\Exception\ProductConcreteAttributesExistException
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return void
     */
    protected function saveProductConcreteLocalizedAttributes(ZedProductConcreteTransfer $productConcreteTransfer)
    {
        $idProductConcrete = $productConcreteTransfer->requireIdProductConcrete()->getIdProductConcrete();

        foreach ($productConcreteTransfer->getLocalizedAttributes() as $localizedAttributes) {
            $locale = $localizedAttributes->getLocale();
            $jsonAttributes = $this->encodeAttributes($localizedAttributes->getAttributes());

            $localizedProductAttributesEntity = $this->productQueryContainer
                ->queryProductConcreteAttributeCollection($idProductConcrete, $locale->getIdLocale())
                ->findOneOrCreate();

            $localizedProductAttributesEntity
                ->setFkProduct($idProductConcrete)
                ->setFkLocale($locale->getIdLocale())
                ->setName($localizedAttributes->getName())
                ->setAttributes($jsonAttributes);

            $localizedProductAttributesEntity->save();
        }
    }

    /**
     * TODO move to AttributeManager
     *
     * @param int $idProductConcrete
     * @param \Generated\Shared\Transfer\LocaleTransfer $locale
     *
     * @throws \Spryker\Zed\Product\Business\Exception\ProductConcreteAttributesExistException
     *
     * @return void
     */
    protected function checkProductConcreteAttributesDoNotExist($idProductConcrete, LocaleTransfer $locale)
    {
        if ($this->hasProductConcreteAttributes($idProductConcrete, $locale)) {
            throw new ProductConcreteAttributesExistException(sprintf(
                'Tried to create product concrete attributes for product id %s, locale id %s, but they exist',
                $idProductConcrete,
                $locale->getIdLocale()
            ));
        }
    }

    /**
     * TODO move to AttributeManager
     *
     * @param int $idProductConcrete
     * @param \Generated\Shared\Transfer\LocaleTransfer $locale
     *
     * @return bool
     */
    protected function hasProductConcreteAttributes($idProductConcrete, LocaleTransfer $locale)
    {
        $query = $this->productQueryContainer->queryProductConcreteAttributeCollection(
            $idProductConcrete,
            $locale->getIdLocale()
        );

        return $query->count() > 0;
    }

    /**
     * @param int $idProductAbstract
     *
     * @return void
     */
    public function touchProductActive($idProductAbstract)
    {
        $this->touchFacade->touchActive('product_abstract', $idProductAbstract);
    }

    /**
     * @param string $sku
     * @param string $url
     * @param \Generated\Shared\Transfer\LocaleTransfer $locale
     *
     * @throws \Propel\Runtime\Exception\PropelException
     * @throws \Spryker\Zed\Url\Business\Exception\UrlExistsException
     * @throws \Spryker\Zed\Product\Business\Exception\MissingProductException
     *
     * @return \Generated\Shared\Transfer\UrlTransfer
     */
    public function createProductUrl($sku, $url, LocaleTransfer $locale)
    {
        $idProductAbstract = $this->getProductAbstractIdBySku($sku);

        return $this->createProductUrlByIdProduct($idProductAbstract, $url, $locale);
    }

    /**
     * @param int $idProductAbstract
     * @param string $url
     * @param \Generated\Shared\Transfer\LocaleTransfer $locale
     *
     * @throws \Propel\Runtime\Exception\PropelException
     * @throws \Spryker\Zed\Url\Business\Exception\UrlExistsException
     * @throws \Spryker\Zed\Product\Business\Exception\MissingProductException
     *
     * @return \Generated\Shared\Transfer\UrlTransfer
     */
    public function createProductUrlByIdProduct($idProductAbstract, $url, LocaleTransfer $locale)
    {
        return $this->urlFacade->createUrl($url, $locale, 'product_abstract', $idProductAbstract);
    }

    /**
     * @param string $sku
     * @param string $url
     * @param \Generated\Shared\Transfer\LocaleTransfer $locale
     *
     * @throws \Propel\Runtime\Exception\PropelException
     * @throws \Spryker\Zed\Url\Business\Exception\UrlExistsException
     * @throws \Spryker\Zed\Product\Business\Exception\MissingProductException
     *
     * @return \Generated\Shared\Transfer\UrlTransfer
     */
    public function createAndTouchProductUrl($sku, $url, LocaleTransfer $locale)
    {
        $url = $this->createProductUrl($sku, $url, $locale);
        $this->urlFacade->touchUrlActive($url->getIdUrl());

        return $url;
    }

    /**
     * @param int $idProductAbstract
     * @param string $url
     * @param \Generated\Shared\Transfer\LocaleTransfer $locale
     *
     * @return \Generated\Shared\Transfer\UrlTransfer
     */
    public function createAndTouchProductUrlByIdProduct($idProductAbstract, $url, LocaleTransfer $locale)
    {
        $urlTransfer = $this->createProductUrlByIdProduct($idProductAbstract, $url, $locale);
        $this->urlFacade->touchUrlActive($urlTransfer->getIdUrl());

        return $urlTransfer;
    }

    /**
     * TODO Move to TaxManager
     *
     * @param string $sku
     *
     * @throws \Spryker\Zed\Product\Business\Exception\MissingProductException
     *
     * @return float
     */
    public function getEffectiveTaxRateForProductConcrete($sku)
    {
        $productConcrete = $this->productQueryContainer
            ->queryProductConcreteBySku($sku)
            ->findOne();

        if (!$productConcrete) {
            throw new MissingProductException(sprintf(
                'Tried to retrieve a product concrete with sku %s, but it does not exist.',
                $sku
            ));
        }

        $productAbstract = $productConcrete->getSpyProductAbstract();

        $taxSetEntity = $productAbstract->getSpyTaxSet();
        if ($taxSetEntity === null) {
            return 0;
        }

        $effectiveTaxRate = $this->getEffectiveTaxRate($taxSetEntity->getSpyTaxRates());

        return $effectiveTaxRate;
    }

    /**
     * @param \Generated\Shared\Transfer\ZedProductConcreteTransfer $productConcreteTransfer
     *
     * @return \Generated\Shared\Transfer\ZedProductConcreteTransfer
     */
    protected function loadTaxRate(ZedProductConcreteTransfer $productConcreteTransfer)
    {
        $taxSetEntity = $this->productQueryContainer
            ->queryTaxSetForProductAbstract($productConcreteTransfer->getFkProductAbstract())
            ->findOne();

        if ($taxSetEntity === null) {
            return $productConcreteTransfer;
        }

        $effectiveTaxRate = $this->getEffectiveTaxRate($taxSetEntity->getSpyTaxRates());
        $productConcreteTransfer->setTaxRate($effectiveTaxRate);

        return $productConcreteTransfer;
    }

    /**
     * @param string $sku
     *
     * @throws \Spryker\Zed\Product\Business\Exception\MissingProductException
     *
     * @return int
     */
    public function getProductAbstractIdByConcreteSku($sku)
    {
        $productConcrete = $this->productQueryContainer
            ->queryProductConcreteBySku($sku)
            ->findOne();

        if (!$productConcrete) {
            throw new MissingProductException(sprintf(
                'Tried to retrieve a product concrete with sku %s, but it does not exist.',
                $sku
            ));
        }

        return $productConcrete->getFkProductAbstract();
    }

    /**
     * @param int $idProductAbstract
     *
     * @throws \Spryker\Zed\Product\Business\Exception\MissingProductException
     *
     * @return \Generated\Shared\Transfer\ProductAbstractTransfer|null
     */
    public function getProductAbstractById($idProductAbstract)
    {
        $productAbstractEntity = $this->productQueryContainer
            ->queryProductAbstract()
            ->filterByIdProductAbstract($idProductAbstract)
            ->findOne();

        if (!$productAbstractEntity) {
            return null;
        }

        $transferGenerator = new ProductTransferGenerator();  //TODO inject
        $productAbstractTransfer = $transferGenerator->convertProductAbstract($productAbstractEntity);

        $productAbstractTransfer = $this->loadProductAbstractLocalizedAttributes($productAbstractTransfer);

        return $productAbstractTransfer;
    }

    /**
     * TODO move to AttributeManager
     *
     * @param \Generated\Shared\Transfer\ProductAbstractTransfer $productAbstractTransfer
     *
     * @return \Generated\Shared\Transfer\ProductAbstractTransfer
     */
    protected function loadProductAbstractLocalizedAttributes(ProductAbstractTransfer $productAbstractTransfer)
    {
        $productAttributeCollection = $this->productQueryContainer
            ->queryProductAbstractLocalizedAttributes($productAbstractTransfer->getIdProductAbstract())
            ->find();

        foreach ($productAttributeCollection as $attributeEntity) {
            $localeTransfer = $this->localeFacade->getLocaleById($attributeEntity->getFkLocale());

            $localizedAttributesTransfer = $this->attributeManager->createLocalizedAttributesTransfer(
                $attributeEntity->getName(),
                $attributeEntity->getAttributes(),
                $localeTransfer
            );

            $productAbstractTransfer->addLocalizedAttributes($localizedAttributesTransfer);
        }

        return $productAbstractTransfer;
    }

    /**
     * @param int $idProduct
     *
     * @throws \Spryker\Zed\Product\Business\Exception\MissingProductException
     *
     * @return \Generated\Shared\Transfer\ProductAbstractTransfer|null
     */
    public function getProductById($idProduct)
    {
        $productEntity = $this->productQueryContainer
            ->queryProduct()
            ->filterByIdProduct($idProduct)
            ->findOne();

        if (!$productEntity) {
            return null;
        }

        $transferGenerator = new ProductTransferGenerator();
        $productTransfer = $transferGenerator->convertProduct($productEntity);

        $productTransfer = $this->loadProductConcreteLocalizedAttributes($productTransfer);
        $this->loadTaxRate($productTransfer);

        return $productTransfer;
    }

    /**
     * TODO move to AttributeManager
     *
     * @param \Generated\Shared\Transfer\ZedProductConcreteTransfer $productTransfer
     *
     * @return \Generated\Shared\Transfer\ZedProductConcreteTransfer
     */
    protected function loadProductConcreteLocalizedAttributes(ZedProductConcreteTransfer $productTransfer)
    {
        $productAttributeCollection = $this->productQueryContainer
            ->queryProductLocalizedAttributes($productTransfer->getIdProductConcrete())
            ->find();

        foreach ($productAttributeCollection as $attributeEntity) {
            $localeTransfer = $this->localeFacade->getLocaleById($attributeEntity->getFkLocale());

            $localizedAttributesTransfer = $this->attributeManager->createLocalizedAttributesTransfer(
                $attributeEntity->getName(),
                $attributeEntity->getAttributes(),
                $localeTransfer
            );

            $productTransfer->addLocalizedAttributes($localizedAttributesTransfer);
        }

        return $productTransfer;
    }

    /**
     * @param string $sku
     *
     * @throws \Spryker\Zed\Product\Business\Exception\MissingProductException
     *
     * @return string
     */
    public function getAbstractSkuFromProductConcrete($sku)
    {
        $productConcrete = $this->productQueryContainer
            ->queryProductConcreteBySku($sku)
            ->findOne();

        if (!$productConcrete) {
            throw new MissingProductException(sprintf(
                'Tried to retrieve a product concrete with sku %s, but it does not exist.',
                $sku
            ));
        }

        return $productConcrete->getSpyProductAbstract()->getSku();
    }

    /**
     * TODO move to AttributeManager
     *
     * @param array $attributes
     *
     * @return string
     */
    protected function encodeAttributes(array $attributes)
    {
        return Json::encode($attributes);
    }

    /**
     * @param \Orm\Zed\Tax\Persistence\SpyTaxRate[] $taxRates
     *
     * @return int
     */
    protected function getEffectiveTaxRate($taxRates)
    {
        $taxRate = 0;
        foreach ($taxRates as $taxRateEntity) {
            $taxRate += $taxRateEntity->getRate();
        }

        return $taxRate;
    }

    /**
     * @param \Generated\Shared\Transfer\ProductAbstractTransfer $productAbstractTransfer
     * @param array $productConcreteCollection
     *
     * @throws \Exception
     * @return int
     */
    public function addProduct(ProductAbstractTransfer $productAbstractTransfer, array $productConcreteCollection)
    {
        $this->productQueryContainer->getConnection()->beginTransaction();

        try {
            $idProductAbstract = $this->createProductAbstract($productAbstractTransfer);
            $productAbstractTransfer->setIdProductAbstract($idProductAbstract);

            foreach ($productConcreteCollection as $productConcrete) {
                $this->createProductConcrete($productConcrete, $idProductAbstract);
            }

            $this->productQueryContainer->getConnection()->commit();

            return $idProductAbstract;

        } catch (\Exception $e) {
            $this->productQueryContainer->getConnection()->rollBack();
            throw $e;
        }
    }

    /**
     * @param \Generated\Shared\Transfer\ProductAbstractTransfer $productAbstractTransfer
     * @param array|\Generated\Shared\Transfer\ZedProductConcreteTransfer[] $productConcreteCollection
     *
     * @throws \Exception
     * @return int
     */
    public function saveProduct(ProductAbstractTransfer $productAbstractTransfer, array $productConcreteCollection)
    {
        $this->productQueryContainer->getConnection()->beginTransaction();

        try {
            $idProductAbstract = $this->saveProductAbstract($productAbstractTransfer);

            foreach ($productConcreteCollection as $productConcreteTransfer) {
                $productConcreteTransfer->setFkProductAbstract($idProductAbstract);

                $productConcreteEntity = $this->findProductConcreteByAttributes($productAbstractTransfer, $productConcreteTransfer);
                if ($productConcreteEntity) {
                    $productConcreteTransfer->setIdProductConcrete($productConcreteEntity->getIdProduct());
                    $this->saveProductConcrete($productConcreteTransfer);
                } else {
                    $this->createProductConcrete($productConcreteTransfer, $idProductAbstract);
                }
            }

            $this->productQueryContainer->getConnection()->commit();

            return $idProductAbstract;

        } catch (\Exception $e) {
            $this->productQueryContainer->getConnection()->rollBack();
            throw $e;
        }
    }

    /**
     * TODO move to AttributeManager
     *
     * @param \Generated\Shared\Transfer\ProductAbstractTransfer $productAbstractTransfer
     * @param \Generated\Shared\Transfer\ZedProductConcreteTransfer $productConcreteTransfer
     *
     * @return \Orm\Zed\Product\Persistence\SpyProduct
     */
    protected function findProductConcreteByAttributes(ProductAbstractTransfer $productAbstractTransfer, ZedProductConcreteTransfer $productConcreteTransfer)
    {
        $jsonAttributes = $this->encodeAttributes($productConcreteTransfer->getAttributes());

        return $this->productQueryContainer
            ->queryProduct()
            ->filterByFkProductAbstract($productAbstractTransfer->getIdProductAbstract())
            ->filterByAttributes($jsonAttributes)
            ->findOne();
    }

    /**
     * @param int $idProductAbstract
     *
     * @return \Generated\Shared\Transfer\ZedProductConcreteTransfer[]
     */
    public function getConcreteProductsByAbstractProductId($idProductAbstract)
    {
        $entityCollection = $this->productQueryContainer
            ->queryProduct()
            ->filterByFkProductAbstract($idProductAbstract)
            ->joinSpyProductAbstract()
            ->find();

        $transferGenerator = new ProductTransferGenerator(); //TODO inject
        $transferCollection = $transferGenerator->convertProductCollection($entityCollection);

        for ($a=0; $a<count($transferCollection); $a++) {
            $transferCollection[$a] = $this->loadProductConcreteLocalizedAttributes($transferCollection[$a]);
            $transferCollection[$a] = $this->loadTaxRate($transferCollection[$a]);
        }

        return $transferCollection;
    }

    /**
     * @param int $idProductAbstract
     *
     * @return array
     */
    public function getProductAttributesByAbstractProductId($idProductAbstract)
    {
        $attributeCollection = $this->getProductAttributeCollection();
        $concreteProductCollection = $this->getConcreteProductsByAbstractProductId($idProductAbstract);

        $attributes = [];
        foreach ($concreteProductCollection as $productTransfer) {
            $productAttributes = $productTransfer->getAttributes();
            foreach ($productAttributes as $name => $value) {
                $attributes[$name][$value] = $value;
            }
        }

        $result = [];
        foreach ($attributes as $type => $valueSet) {
            foreach ($valueSet as $name => $value) {
                $result[$type][$name] = $attributeCollection[$type][$name];
            }
        }

        return $result;
    }

    /**
     * @return array
     */
    public function getProductAttributeCollection()
    {
        $attr = $this->productQueryContainer
            ->queryAttributesMetadata()
            ->find();

        return [
            'size' => [
                '40' => '40',
                '41' => '41',
                '42' => '42',
                '43' => '43',
            ],
            'color' => [
                'blue' => 'Blue',
                'red' => 'Red',
                'white' => 'White',
            ],
            'flavour' => [
                'spicy' => 'Mexican Food',
                'sweet' => 'Cakes'
            ]
        ];
    }

}