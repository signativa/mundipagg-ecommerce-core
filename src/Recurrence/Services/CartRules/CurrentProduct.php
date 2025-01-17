<?php

namespace Mundipagg\Core\Recurrence\Services\CartRules;

use Mundipagg\Core\Recurrence\Interfaces\ProductPlanInterface;
use Mundipagg\Core\Recurrence\Interfaces\ProductSubscriptionInterface;
use Mundipagg\Core\Recurrence\Interfaces\RepetitionInterface;

class CurrentProduct
{
    protected $isNormalProduct = false;
    protected $repetitionSelected;
    protected $productSubscriptionSelected;
    protected $productPlanSelected;

    /**
     * @return RepetitionInterface
     */
    public function getRepetitionSelected()
    {
        return $this->repetitionSelected;
    }

    /**
     * @param RepetitionInterface $repetitionSelected
     */
    public function setRepetitionSelected(RepetitionInterface $repetitionSelected)
    {
        $this->repetitionSelected = $repetitionSelected;
    }

    /**
     * @return ProductSubscriptionInterface
     */
    public function getProductSubscriptionSelected()
    {
        return $this->productSubscriptionSelected;
    }

    /**
     * @param ProductSubscriptionInterface $productSubscriptionSelected
     */
    public function setProductSubscriptionSelected(ProductSubscriptionInterface $productSubscriptionSelected)
    {
        $this->productSubscriptionSelected = $productSubscriptionSelected;
    }

    public function getProductPlanSelected()
    {
        return $this->productPlanSelected;
    }

    /**
     * @param ProductPlanInterface $productPlanSelected
     */
    public function setProductPlanSelected(ProductPlanInterface $productPlanSelected)
    {
        $this->productPlanSelected = $productPlanSelected;
    }

    /**
     * @param int $quantity
     */
    public function setQuantity($quantity)
    {
        $this->quantity = $quantity;
    }

    /**
     * @param $quantity
     * @return int
     */
    public function getQuantity()
    {
        return $this->quantity;
    }

    /**
     * @return bool
     */
    public function isNormalProduct()
    {
        return $this->isNormalProduct;
    }

    /**
     * @param bool $isNormalProduct
     */
    public function setIsNormalProduct($isNormalProduct)
    {
        $this->isNormalProduct = $isNormalProduct;
    }
}
