<?php

namespace Mundipagg\Core\Test\Recurrence\Aggregates;

use MundiAPILib\Models\CreatePlanRequest;
use Mundipagg\Core\Recurrence\Aggregates\Plan;
use Mundipagg\Core\Recurrence\Aggregates\SubProduct;
use Mundipagg\Core\Recurrence\ValueObjects\PlanId;
use PHPUnit\Framework\TestCase;
use Mundipagg\Core\Recurrence\ValueObjects\IntervalValueObject;

class PlanTests extends TestCase
{
    private $plan;

    protected function setUp()
    {
        $this->plan = new Plan();
    }

    public function testJsonSerializeShouldReturnAnInstanceOfStdClass()
    {
        $this->assertInstanceOf(\stdClass::class, $this->plan->jsonSerialize());
    }

    public function testJsonSerializeShouldSetAllProperties()
    {
        $id = '1';
        $name = "Product Name";
        $description = "Product Description";
        $interval = IntervalValueObject::month(2);
        $planId =  new PlanId('plan_45asDadb8Xd95451');
        $productId = '4123';
        $creditCard = true;
        $boleto = false;
        $items = [
            new SubProduct(),
            new SubProduct()
        ];
        $status = 'ACTIVE';
        $billingType = 'PREPAID';
        $allowInstallments = true;
        $createdAt = new \Datetime();
        $updatedAt = new \Datetime();

        $this->plan->setId($id);
        $this->assertEquals($this->plan->getId(), $id);

        $this->plan->setName($name);
        $this->assertEquals($this->plan->getName(), $name);

        $this->plan->setDescription($description);
        $this->assertEquals($this->plan->getDescription(), $description);

        $this->plan->setItems($items);
        $this->assertEquals($this->plan->getItems(), $items);

        $this->plan->setInterval($interval);
        $this->assertEquals($this->plan->getInterval(), $interval);

        $this->plan->setMundipaggId($planId);
        $this->assertEquals($this->plan->getMundipaggId(), $planId);

        $this->plan->setProductId($productId);
        $this->assertEquals($this->plan->getProductId(), $productId);

        $this->plan->setCreditCard($creditCard);
        $this->assertEquals($this->plan->getCreditCard(), $creditCard);

        $this->plan->setBoleto($boleto);
        $this->assertEquals($this->plan->getBoleto(), $boleto);

        $this->plan->setStatus($status);
        $this->assertEquals($this->plan->getStatus(), $status);

        $this->plan->setBillingType($billingType);
        $this->assertEquals($this->plan->getBillingType(), $billingType);

        $this->plan->setAllowInstallments($allowInstallments);
        $this->assertEquals($this->plan->getAllowInstallments(), $allowInstallments);

        $this->plan->setCreatedAt($createdAt);
        $this->assertInternalType('string', $this->plan->getCreatedAt());

        $this->plan->setUpdatedAt($updatedAt);
        $this->assertInternalType('string', $this->plan->getUpdatedAt());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage  Product id should be an integer! Passed value:
     */
    public function testShouldNotAddAnEmptyProductId()
    {
        $this->plan->setProductId("");
    }

    public function testShouldSetCorrectProductId()
    {
        $this->plan->setProductId("23");
        $this->assertEquals("23", $this->plan->getProductId());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage  Billing type should not be empty! Passed value:
     */
    public function testShouldNotAddAnEmptyBillingType()
    {
        $this->plan->setBillingType("");
    }

    public function testShouldSetCorrectBillingType()
    {
        $this->plan->setBillingType("PREPAID");
        $this->assertEquals("PREPAID", $this->plan->getBillingType());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage  Status should not be empty! Passed value:
     */
    public function testShouldNotAddAnEmptyStatus()
    {
        $this->plan->setStatus("");
    }

    public function testShouldSetCorrectStatus()
    {
        $this->plan->setStatus("active");
        $this->assertEquals("active", $this->plan->getStatus());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage  Trial period days should be an integer! Passed value:
     */
    public function testShouldNotAddAnNotIntegerTrialPeriodDays()
    {
        $this->plan->setTrialPeriodDays("");
    }

    public function testShouldSetCorrectTrialPeriodDays()
    {
        $this->plan->setTrialPeriodDays(10);
        $this->assertEquals(10, $this->plan->getTrialPeriodDays());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage  Boleto should be 1 or 0 Passed value: wrong
     */
    public function testShouldNotAddAnWrongValueToBoleto()
    {
        $this->plan->setBoleto("wrong");
    }

    public function testShouldSetCorrectValueToBoleto()
    {
        $this->plan->setBoleto("1");
        $this->assertEquals("1", $this->plan->getBoleto());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage  Credit card should be 1 or 0! Passed value:
     */
    public function testShouldNotAddAnWrongValueToCreditCard()
    {
        $this->plan->setCreditCard("wrong");
    }

    public function testShouldSetCorrectValueToCreditCard()
    {
        $this->plan->setCreditCard("1");
        $this->assertEquals("1", $this->plan->getCreditCard());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage  Allow installments should be 1 or 0! Passed value:
     */
    public function testShouldNotAddAnWrongValueToAllowInstallments()
    {
        $this->plan->setAllowInstallments("wrong");
    }

    public function testShouldSetCorrectValueToAllowInstallments()
    {
        $this->plan->setAllowInstallments("1");
        $this->assertEquals("1", $this->plan->getAllowInstallments());
    }

    public function testShouldReturnIntervalCountSetted()
    {
        $interval = IntervalValueObject::month(2);
        $this->plan->setInterval($interval);

        $this->assertEquals("month", $this->plan->getIntervalType());
        $this->assertEquals("2", $this->plan->getIntervalCount());
    }

    public function testAPlanAggregateShouldBeAPlanRecurrenceType()
    {
        $this->assertEquals("plan", $this->plan->getRecurrenceType());
    }

    public function testShouldReturnACreatePlanRequestObject()
    {
        $this->assertInstanceOf(CreatePlanRequest::class, $this->plan->convertToSdkRequest());
    }

    public function testShouldReturnACreatePlanRequestObjectWithPaymentMethods()
    {
        $this->plan->setCreditCard(true);
        $this->plan->setBoleto(true);
        $sdkObject = $this->plan->convertToSdkRequest();

        $this->assertInstanceOf(CreatePlanRequest::class, $sdkObject);
        $this->assertCount(2, $sdkObject->paymentMethods);
    }

    public function testShouldReturnACreatePlanRequestObjectWithItems()
    {
        $items = [
            new SubProduct(),
            new SubProduct()
        ];

        $this->plan->setItems($items);

        $sdkObject = $this->plan->convertToSdkRequest();

        $this->assertInstanceOf(CreatePlanRequest::class, $sdkObject);
        $this->assertCount(2, $sdkObject->items);
    }
}
