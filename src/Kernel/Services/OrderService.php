<?php

namespace Mundipagg\Core\Kernel\Services;

use Mundipagg\Core\Kernel\Abstractions\AbstractDataService;
use Mundipagg\Core\Kernel\Aggregates\Order;
use Mundipagg\Core\Kernel\Abstractions\AbstractModuleCoreSetup as MPSetup;
use Mundipagg\Core\Kernel\Exceptions\InvalidParamException;
use Mundipagg\Core\Kernel\Interfaces\PlatformOrderInterface;
use Mundipagg\Core\Kernel\Repositories\OrderRepository;
use Mundipagg\Core\Kernel\ValueObjects\Id\OrderId;
use Mundipagg\Core\Kernel\ValueObjects\OrderState;
use Mundipagg\Core\Kernel\ValueObjects\OrderStatus;
use Mundipagg\Core\Payment\Aggregates\Customer;
use Mundipagg\Core\Payment\Interfaces\ResponseHandlerInterface;
use Mundipagg\Core\Payment\Services\ResponseHandlers\ErrorExceptionHandler;
use Mundipagg\Core\Payment\ValueObjects\CustomerType;
use Mundipagg\Core\Kernel\Factories\OrderFactory;
use Mundipagg\Core\Kernel\Factories\ChargeFactory;
use Mundipagg\Core\Payment\Aggregates\Order as PaymentOrder;
use Exception;
use Mundipagg\Core\Kernel\ValueObjects\ChargeStatus;

final class OrderService
{
    private $logService;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    public function __construct()
    {
        $this->logService = new OrderLogService();
        $this->orderRepository = new OrderRepository();
    }

    /**
     *
     * @param Order $order
     * @param bool $changeStatus
     */
    public function syncPlatformWith(Order $order, $changeStatus = true)
    {
        $moneyService = new MoneyService();

        $paidAmount = 0;
        $canceledAmount = 0;
        $refundedAmount = 0;
        foreach ($order->getCharges() as $charge) {
            $paidAmount += $charge->getPaidAmount();
            $canceledAmount += $charge->getCanceledAmount();
            $refundedAmount += $charge->getRefundedAmount();
        }

        $paidAmount = $moneyService->centsToFloat($paidAmount);
        $canceledAmount = $moneyService->centsToFloat($canceledAmount);
        $refundedAmount = $moneyService->centsToFloat($refundedAmount);

        $platformOrder = $order->getPlatformOrder();

        $platformOrder->setTotalPaid($paidAmount);
        $platformOrder->setBaseTotalPaid($paidAmount);
        $platformOrder->setTotalCanceled($canceledAmount);
        $platformOrder->setBaseTotalCanceled($canceledAmount);
        $platformOrder->setTotalRefunded($refundedAmount);
        $platformOrder->setBaseTotalRefunded($refundedAmount);

        if ($changeStatus) {
            $this->changeOrderStatus($order);
        }

        $platformOrder->save();
    }

    public function changeOrderStatus(Order $order)
    {
        $platformOrder = $order->getPlatformOrder();
        $orderStatus = $order->getStatus();
        if ($orderStatus->equals(OrderStatus::paid())) {
            $orderStatus = OrderStatus::processing();
        }

        //@todo In the future create a core status machine with the platform
        if (!$order->getPlatformOrder()->getState()->equals(OrderState::closed())) {
            $platformOrder->setStatus($orderStatus);
        }
    }
    public function updateAcquirerData(Order $order)
    {
        $dataServiceClass =
            MPSetup::get(MPSetup::CONCRETE_DATA_SERVICE);

        /**
         *
         * @var AbstractDataService $dataService
         */
        $dataService = new $dataServiceClass();

        $dataService->updateAcquirerData($order);
    }

    private function chargeAlreadyCanceled($charge)
    {
        return
            $charge->getStatus()->equals(ChargeStatus::canceled()) ||
            $charge->getStatus()->equals(ChargeStatus::failed());
    }

    private function addReceivedChargeMessages($messages, $charge, $result)
    {
        if (!is_null($result)) {
            $messages[$charge->getMundipaggId()->getValue()] = $result;
        }

        return $messages;
    }

    private function updateChargeInOrder($order, $charge)
    {
        if (!empty($order)) {
            $order->updateCharge($charge);
        }
    }

    public function cancelChargesAtMundipagg(array $charges, Order $order = null)
    {
        $messages = [];
        $APIService = new APIService();

        foreach ($charges as $charge) {
            if ($this->chargeAlreadyCanceled($charge)) {
                continue;
            }

            $result = $APIService->cancelCharge($charge);

            $messages = $this->addReceivedChargeMessages($messages, $charge, $result);

            $this->updateChargeInOrder($order, $charge);
        }

        return $messages;
    }

    public function cancelAtMundipagg(Order $order)
    {
        $orderRepository = new OrderRepository();
        $savedOrder = $orderRepository->findByMundipaggId($order->getMundipaggId());
        if ($savedOrder !== null) {
            $order = $savedOrder;
        }

        if ($order->getStatus()->equals(OrderStatus::canceled())) {
            return;
        }

        $results = $this->cancelChargesAtMundipagg($order->getCharges(), $order);

        if (empty($results)) {
            $i18n = new LocalizationService();
            $order->setStatus(OrderStatus::canceled());
            $order->getPlatformOrder()->setStatus(OrderStatus::canceled());

            $orderRepository->save($order);
            $order->getPlatformOrder()->save();

            $statusOrderLabel = $order->getPlatformOrder()->getStatusLabel(
                $order->getStatus()
            );

            $messageComplementEmail = $i18n->getDashboard(
                'New order status: %s',
                $statusOrderLabel
            );

            $sender = $order->getPlatformOrder()->sendEmail($messageComplementEmail);

            $order->getPlatformOrder()->addHistoryComment(
                $i18n->getDashboard(
                    "Order '%s' canceled at Mundipagg",
                    $order->getMundipaggId()->getValue()
                ),
                $sender
            );

            return;
        }

        $this->addMessagesToPlatformHistory($results, $order);
    }

    public function addMessagesToPlatformHistory($results, $order)
    {
        $i18n = new LocalizationService();
        $history = $i18n->getDashboard("Some charges couldn't be canceled at Mundipagg. Reasons:");
        $history .= "<br /><ul>";
        foreach ($results as $chargeId => $reason) {
            $history .= "<li>$chargeId : $reason</li>";
        }
        $history .= '</ul>';
        $order->getPlatformOrder()->addHistoryComment($history);
        $order->getPlatformOrder()->save();
    }

    public function addChargeMessagesToLog($platformOrder, $orderInfo, $errorMessages)
    {

        if (!empty($errorMessages)) {
            return;
        }

        foreach ($errorMessages as $chargeId => $reason) {
            $this->logService->orderInfo(
                $platformOrder->getCode(),
                "Charge $chargeId couldn't be canceled at Mundipagg. Reason: $reason",
                $orderInfo
            );
        }
    }

    public function cancelAtMundipaggByPlatformOrder(PlatformOrderInterface $platformOrder)
    {
        $orderId = $platformOrder->getMundipaggId();
        if (empty($orderId)) {
            return;
        }

        $APIService = new APIService();

        $order = $APIService->getOrder($orderId);
        if (is_a($order, Order::class)) {
            $this->cancelAtMundipagg($order);
        }
    }

    /**
     * @param PlatformOrderInterface $platformOrder
     * @return array
     * @throws \Exception
     */
    public function createOrderAtMundipagg(PlatformOrderInterface $platformOrder)
    {
        try {
            $orderInfo = $this->getOrderInfo($platformOrder);

            $this->logService->orderInfo(
                $platformOrder->getCode(),
                'Creating order.',
                $orderInfo
            );

            //set pending
            $platformOrder->setState(OrderState::stateNew());
            $platformOrder->setStatus(OrderStatus::pending());

            //build PaymentOrder based on platformOrder
            $paymentOrder =  $this->extractPaymentOrderFromPlatformOrder($platformOrder);

            $i18n = new LocalizationService();

            //Send through the APIService to mundipagg
            $apiService = new APIService();
            $response = $apiService->createOrder($paymentOrder);

            $forceCreateOrder = MPSetup::getModuleConfiguration()->isCreateOrderEnabled();

            if (!$forceCreateOrder && !$this->wasOrderChargedSuccessfully($response)) {
                $this->logService->orderInfo(
                    $platformOrder->getCode(),
                    "Can't create order. - Force Create Order: {$forceCreateOrder} | Order or charge status failed",
                    $orderInfo
                );

                $charges = $this->createChargesFromResponse($response);
                $errorMessages = $this->cancelChargesAtMundipagg($charges);

                $this->addChargeMessagesToLog($platformOrder, $orderInfo, $errorMessages);

                $this->persistListChargeFailed($response);

                $message = $i18n->getDashboard(
                    "Can't create payment. " .
                        "Please review the information and try again."
                );
                throw new \Exception($message, 400);
            }

            $platformOrder->save();

            $orderFactory = new OrderFactory();
            $order = $orderFactory->createFromPostData($response);
            $order->setPlatformOrder($platformOrder);

            $handler = $this->getResponseHandler($order);
            $handler->handle($order, $paymentOrder);

            $platformOrder->save();

            if (!$this->wasOrderChargedSuccessfully($response)) {
                $this->logService->orderInfo(
                    $platformOrder->getCode(),
                    "Can't create order. - Force Create Order: {$forceCreateOrder} | Order or charge status failed",
                    $orderInfo
                );
                $message = $i18n->getDashboard(
                    "Can't create payment. " .
                        "Please review the information and try again."
                );
                throw new \Exception($message, 400);
            }

            return [$order];
        } catch (\Exception $e) {
            $this->logService->orderInfo(
                $platformOrder->getCode(),
                $e->getMessage(),
                $orderInfo
            );
            $exceptionHandler = new ErrorExceptionHandler();
            $paymentOrder = new PaymentOrder();
            $paymentOrder->setCode($platformOrder->getcode());
            $frontMessage = $exceptionHandler->handle($e, $paymentOrder);

            throw new \Exception($frontMessage, 400);
        }
    }

    /** @return ResponseHandlerInterface */
    private function getResponseHandler($response)
    {
        $responseClass = get_class($response);
        $responseClass = explode('\\', $responseClass);

        $responseClass =
            'Mundipagg\\Core\\Payment\\Services\\ResponseHandlers\\' .
            end($responseClass) . 'Handler';

        return new $responseClass;
    }

    public function extractPaymentOrderFromPlatformOrder(
        PlatformOrderInterface $platformOrder
    ) {
        $i18n = new LocalizationService();

        $moduleConfig = MPSetup::getModuleConfiguration();

        $moneyService = new MoneyService();

        $user = new Customer();
        $user->setType(CustomerType::individual());

        $order = new PaymentOrder();

        $order->setAmount(
            $moneyService->floatToCents(
                $platformOrder->getGrandTotal()
            )
        );
        $order->setCustomer($platformOrder->getCustomer());
        $order->setAntifraudEnabled($moduleConfig->isAntifraudEnabled());
        $order->setPaymentMethod($platformOrder->getPaymentMethod());

        $payments = $platformOrder->getPaymentMethodCollection();
        foreach ($payments as $payment) {
            $order->addPayment($payment);
        }
        $orderInfo = $this->getOrderInfo($platformOrder);
        if (!$order->isPaymentSumCorrect()) {
            $message = $i18n->getDashboard(
                "The sum of payments is different than the order amount! " .
                    "Review the information and try again."
            );
            $this->logService->orderInfo(
                $platformOrder->getCode(),
                $message,
                $orderInfo
            );
            throw new \Exception($message, 400);
        }

        $items = $platformOrder->getItemCollection();
        foreach ($items as $item) {
            $order->addItem($item);
        }

        $order->setCode($platformOrder->getCode());

        $shipping = $platformOrder->getShipping();
        if ($shipping !== null) {
            $order->setShipping($shipping);
        }

        return $order;
    }

    /**
     * @param PlatformOrderInterface $platformOrder
     * @return \stdClass
     */
    public function getOrderInfo(PlatformOrderInterface $platformOrder)
    {
        $orderInfo = new \stdClass();
        $orderInfo->grandTotal = $platformOrder->getGrandTotal();
        return $orderInfo;
    }

    private function responseHasChargesAndFailed($response)
    {
        return !isset($response['status']) ||
            !isset($response['charges']) ||
            $response['status'] == 'failed';
    }

    /**
     * @param $response
     * @return boolean
     */
    private function wasOrderChargedSuccessfully($response)
    {

        if ($this->responseHasChargesAndFailed($response)) {
            return false;
        }

        foreach ($response['charges'] as $charge) {
            if (isset($charge['status']) && $charge['status'] == 'failed') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param $response
     * @throws InvalidParamException
     * @throws Exception
     */
    private function persistListChargeFailed($response)
    {
        if (empty($response['charges'])) {
            return [];
        }

        $charges = $this->createChargesFromResponse($response);
        $chargeService = new ChargeService();

        foreach ($charges as $charge) {
            $chargeService->save($charge);
        }
    }

    private function createChargesFromResponse($response)
    {
        if (empty($response['charges'])) {
            return;
        }

        $charges = [];
        $chargeFactory = new ChargeFactory();

        foreach ($response['charges'] as $chargeResponse) {
            $order = ['order' => ['id' => $response['id']]];
            $charge = $chargeFactory->createFromPostData(
                array_merge($chargeResponse, $order)
            );

            $charges[] = $charge;
        }

        return $charges;
    }

    /**
     * @return Order|null
     * @throws InvalidParamException
     */
    public function getOrderByMundiPaggId(OrderId $orderId)
    {
        return $this->orderRepository->findByMundipaggId($orderId);
    }

    /**
     * @param string $platformOrderID
     * @return Order|null
     */
    public function getOrderByPlatformId($platformOrderID)
    {
        return $this->orderRepository->findByPlatformId($platformOrderID);
    }
}
