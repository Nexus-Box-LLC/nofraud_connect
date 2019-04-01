<?php

namespace NoFraud\Connect\Observer;

class SalesOrderPaymentPlaceEnd implements \Magento\Framework\Event\ObserverInterface
{
    protected $invoiceService;
    protected $creditmemoFactory;
    protected $creditmemoService;

    public function __construct(
        \NoFraud\Connect\Helper\Config $configHelper,
        \NoFraud\Connect\Api\RequestHandler $requestHandler,
        \NoFraud\Connect\Api\ResponseHandler $responseHandler,
        \NoFraud\Connect\Logger\Logger $logger,
        \NoFraud\Connect\Api\ApiUrl $apiUrl,
        \NoFraud\Connect\Order\Processor $orderProcessor,
        \Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory $orderStatusCollection,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService
    ) {
        $this->configHelper = $configHelper;
        $this->requestHandler = $requestHandler;
        $this->responseHandler = $responseHandler;
        $this->logger = $logger;
        $this->apiUrl = $apiUrl;
        $this->orderProcessor = $orderProcessor;
        $this->orderStatusCollection = $orderStatusCollection;
        $this->invoiceService = $invoiceService;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        // If module is disabled from Admin Config, do nothing.
        //
        if ($this->configHelper->noFraudIsDisabled()) {
            return;
        }

        // get \Magento\Sales\Model\Order\Payment
        //
        $payment = $observer->getEvent()->getPayment();

        // If the Payment method is blacklisted in the Admin Config, then do nothing.
        //
        if ( $this->configHelper->paymentMethodIsIgnored($payment->getMethod()) ) {
            return;
        }

        // get \Magento\Sales\Model\Order
        //
        $order = $payment->getOrder();

        // If Orders with the current Order's Status are ignored, then do nothing.
        //
        if ($this->configHelper->orderStatusIsIgnored($order)){
            return;
        }

        // If the payment has NOT been processed by a payment processor, AND
        // is NOT an offline payment method, then do nothing.
        //
        // Some payment processors like Authorize.net may cause this Event to fire
        // multiple times, but the logic below this point should not be executed
        // unless the Payment has a `last_trans_id` attribute.
        //
        if (!$payment->getLastTransId() && !$payment->getMethodInstance()->isOffline()){
            return;
        }

        // get NoFraud Api Token
        //
        $apiToken = $this->configHelper->getApiToken();

        // Use the NoFraud Sandbox URL if Sandbox Mode is enabled in Admin Config:
        //
        $apiUrl = $this->apiUrl->whichEnvironmentUrl();

        // Build the NoFraud API request JSON from the Payment and Order objects:
        //
        $request = $this->requestHandler->build(
            $payment,
            $order,
            $apiToken
        );

        // Send the request to the NoFraud API and get the response:
        $resultMap = $this->requestHandler->send($request, $apiUrl);

        // Log request results with associated invoice number:
        //
        $this->logger->logTransactionResults($order, $payment, $resultMap); //LOGGING

        try {

            // For all API responses (official results from NoFraud, client errors, etc.),
            // add an informative comment to the Order in Magento Admin:
            //
            $comment = $this->responseHandler->buildComment($resultMap);
            if (!empty($comment)){
                $order->addStatusHistoryComment($comment);
            }

            // For official results from from NoFraud, update the order status
            // according to Admin Config preferences:
            //
            if (isset($resultMap['http']['response']['body'])){
                $newStatus = $this->orderProcessor->getCustomOrderStatus($resultMap['http']['response']);
            }

            // Update state and status. Run function for holded status.
            $this->orderProcessor->updateOrderStateFromNoFraudResult($newStatus, $order);

            // Order has been screened
            $order->setNofraudScreened(true);

            // Finally, save the Order:
            //
            $order->save();

            if ( $this->configHelper->getAutoCancel() && isset( $resultMap['http']['response']['body'] ) ) {
                $this->orderProcessor->handleAutoCancel( $resultMap['http']['response']['body'], $order );
            }

        } catch ( \Exception $exception ) {
            $this->logger->logFailure($order, $exception); //LOGGING
        }

    }

    protected function orderStatusFromConfig( $responseBody )
    {
        if ( isset($responseBody['decision']) ){
            $key = $responseBody['decision'];
        }

        if ( isset($responseBody['Errors']) ){
            $key = 'error';
        }

        if ( isset($key) ){
            $statusCode = $this->configHelper->getCustomStatusConfig($key);
            return $statusCode;
        }
    }

    protected function stateFromStatus( $state )
    {
        $statuses = $this->orderStatusCollection->create()->joinStates();
        $stateIndex = [];

        foreach ($statuses as $status) {
            $stateIndex[$status->getStatus()] = $status->getState();
        }

        return $stateIndex[$state] ?? null;
    }
}
