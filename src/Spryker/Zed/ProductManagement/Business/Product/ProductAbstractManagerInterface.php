<?php
/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */
namespace Spryker\Zed\ProductManagement\Business\Product;

use Generated\Shared\Transfer\ProductAbstractTransfer;

interface ProductAbstractManagerInterface
{

    /**
     * @param \Generated\Shared\Transfer\ProductAbstractTransfer $productAbstractTransfer
     *
     * @throws \Exception
     *
     * @return int
     */
    public function createProductAbstract(ProductAbstractTransfer $productAbstractTransfer);

    /**
     * @param \Generated\Shared\Transfer\ProductAbstractTransfer $productAbstractTransfer
     *
     * @throws \Exception
     *
     * @return int
     */
    public function saveProductAbstract(ProductAbstractTransfer $productAbstractTransfer);

    /**
     * @param string $sku
     *
     * @return int|null
     */
    public function getProductAbstractIdBySku($sku);

    /**
     * @param int $idProductAbstract
     *
     * @return \Generated\Shared\Transfer\ProductAbstractTransfer|null
     */
    public function getProductAbstractById($idProductAbstract);

    /**
     * @param int $idProductAbstract
     *
     * @return \Spryker\Zed\ProductManagement\Business\Attribute\AttributeProcessorInterface
     */
    public function getProductAttributesByAbstractProductId($idProductAbstract);

    /**
     * @param string $sku
     *
     * @return bool
     */
    public function hasProductAbstract($sku);

}
