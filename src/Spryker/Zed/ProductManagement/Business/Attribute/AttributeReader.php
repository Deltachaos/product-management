<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\ProductManagement\Business\Attribute;

use Generated\Shared\Transfer\ProductManagementAttributeTransfer;
use Generated\Shared\Transfer\ProductManagementAttributeValueTranslationTransfer;
use Orm\Zed\ProductManagement\Persistence\Map\SpyProductManagementAttributeValueTableMap;
use Orm\Zed\ProductManagement\Persistence\Map\SpyProductManagementAttributeValueTranslationTableMap;
use Orm\Zed\ProductManagement\Persistence\SpyProductManagementAttributeValueQuery;
use Spryker\Zed\ProductManagement\Dependency\Facade\ProductManagementToGlossaryInterface;
use Spryker\Zed\ProductManagement\Dependency\Facade\ProductManagementToProductInterface;
use Spryker\Zed\ProductManagement\Persistence\ProductManagementQueryContainerInterface;

class AttributeReader implements AttributeReaderInterface
{

    /**
     * @var \Spryker\Zed\ProductManagement\Persistence\ProductManagementQueryContainerInterface
     */
    protected $productManagementQueryContainer;

    /**
     * @var \Spryker\Zed\ProductManagement\Dependency\Facade\ProductManagementToProductInterface
     */
    protected $productFacade;

    /**
     * @var \Spryker\Zed\ProductManagement\Dependency\Facade\ProductManagementToGlossaryInterface
     */
    protected $glossaryFacade;

    /**
     * @param \Spryker\Zed\ProductManagement\Persistence\ProductManagementQueryContainerInterface $productManagementQueryContainer
     * @param \Spryker\Zed\ProductManagement\Dependency\Facade\ProductManagementToProductInterface $productFacade
     * @param \Spryker\Zed\ProductManagement\Dependency\Facade\ProductManagementToGlossaryInterface $glossaryFacade
     */
    public function __construct(
        ProductManagementQueryContainerInterface $productManagementQueryContainer,
        ProductManagementToProductInterface $productFacade,
        ProductManagementToGlossaryInterface $glossaryFacade
    ) {
        $this->productManagementQueryContainer = $productManagementQueryContainer;
        $this->productFacade = $productFacade;
        $this->glossaryFacade = $glossaryFacade;
    }

    /**
     * @param int $idProductManagementAttribute
     * @param int $idLocale
     * @param string $searchText
     * @param int $offset
     * @param int $limit
     *
     * @return \Generated\Shared\Transfer\ProductManagementAttributeValueTranslationTransfer[]
     */
    public function getAttributeValueSuggestions($idProductManagementAttribute, $idLocale, $searchText = '', $offset = 0, $limit = 10)
    {
        $query = $this->productManagementQueryContainer
            ->queryProductManagementAttributeValueWithTranslation($idProductManagementAttribute, $idLocale);

        $this->updateQuerySearchTextConditions($searchText, $query);

        $results = [];
        foreach ($query->find() as $attributeValueTranslation) {
            $results[] = (new ProductManagementAttributeValueTranslationTransfer())
                ->fromArray($attributeValueTranslation->toArray(), true);
        }

        return $results;
    }


    /**
     * @param int $idProductManagementAttribute
     * @param int $idLocale
     * @param string $searchText
     *
     * @return \Generated\Shared\Transfer\ProductManagementAttributeValueTranslationTransfer[]
     */
    public function getAttributeValueSuggestionsCount($idProductManagementAttribute, $idLocale, $searchText = '')
    {
        $query = $this->productManagementQueryContainer
            ->queryProductManagementAttributeValueWithTranslation($idProductManagementAttribute, $idLocale);

        $this->updateQuerySearchTextConditions($searchText, $query);

        return $query->count();
    }

    /**
     * @param string $searchText
     * @param \Orm\Zed\ProductManagement\Persistence\SpyProductManagementAttributeValueQuery $query
     *
     * @return void
     */
    protected function updateQuerySearchTextConditions($searchText, SpyProductManagementAttributeValueQuery $query)
    {
        //TODO double check for injections; if propel is binding values or just appending strings
        $searchText = trim($searchText);
        if ($searchText !== '') {
            $term = '%' . mb_strtoupper($searchText) . '%';

            $query
                ->where('UPPER(' . SpyProductManagementAttributeValueTableMap::COL_VALUE . ') LIKE ?', $term, \PDO::PARAM_STR)
                ->_or()
                ->where('UPPER(' . SpyProductManagementAttributeValueTranslationTableMap::COL_TRANSLATION . ') LIKE ?', $term, \PDO::PARAM_STR);
        }
    }

    /**
     * @param int $idAttribute
     *
     * @return \Generated\Shared\Transfer\ProductManagementAttributeTransfer
     */
    public function getAttributeById($idAttribute)
    {
        $attributeEntity = $this->getAttributeEntity($idAttribute);
        $attributeTransfer = new ProductManagementAttributeTransfer();

        $attributeTransfer->fromArray($attributeEntity->toArray(), true);
    }

    /**
     * @param int $idProductManagementAttribute
     *
     * @return \Orm\Zed\ProductManagement\Persistence\SpyProductManagementAttribute|null
     */
    protected function getAttributeEntity($idProductManagementAttribute)
    {
        return $this->productManagementQueryContainer
            ->queryProductManagementAttribute()
            ->findOneByIdProductManagementAttribute($idProductManagementAttribute);
    }

}
