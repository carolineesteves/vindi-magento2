<?php

namespace Vindi\Payment\Helper\WebHookHandlers;

use Vindi\Payment\Api\OrderCreationQueueRepositoryInterface;
use Vindi\Payment\Model\OrderCreationQueueFactory;
use Magento\Sales\Model\OrderRepository;
use Vindi\Payment\Helper\EmailSender;

/**
 * Class BillCreated
 */
class BillCreated
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var OrderCreator
     */
    private $orderCreator;

    /**
     * @var OrderCreationQueueRepositoryInterface
     */
    private $orderCreationQueueRepository;

    /**
     * @var OrderCreationQueueFactory
     */
    private $orderCreationQueueFactory;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var EmailSender
     */
    private $emailSender;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private $dbAdapter;

    /**
     * Constructor for initializing class dependencies.
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        OrderCreator $orderCreator,
        OrderCreationQueueRepositoryInterface $orderCreationQueueRepository,
        OrderCreationQueueFactory $orderCreationQueueFactory,
        OrderRepository $orderRepository,
        EmailSender $emailSender,
        \Magento\Framework\App\ResourceConnection $resourceConnection
    ) {
        $this->logger = $logger;
        $this->orderCreator = $orderCreator;
        $this->orderCreationQueueRepository = $orderCreationQueueRepository;
        $this->orderCreationQueueFactory = $orderCreationQueueFactory;
        $this->orderRepository = $orderRepository;
        $this->emailSender = $emailSender;
        $this->dbAdapter = $resourceConnection->getConnection();
    }

    /**
     * Handle 'bill_created' event.
     * The bill can be related to a subscription or a single payment.
     *
     * @param array $data
     *
     * @return bool
     */
    public function billCreated($data)
    {
        $bill = $data['bill'];

        if (!$bill) {
            $this->logger->error(__('Error while interpreting webhook "bill_created"'));
            return false;
        }

        if (!isset($bill['subscription']) || $bill['subscription'] === null || !isset($bill['subscription']['id'])) {
            $this->logger->info(__('Ignoring the event "bill_created" for single sell'));
            return false;
        }

        $subscriptionId = $bill['subscription']['id'];

        $lockName = 'vindi_subscription_' . $subscriptionId;
        if (!$this->dbAdapter->query("SELECT GET_LOCK(?, 10)", [$lockName])->fetchColumn()) {
            $this->logger->error(__('Could not acquire lock for subscription ID: %1', $subscriptionId));
            return false;
        }

        try {
            $originalOrder = $this->orderCreator->getOrderFromSubscriptionId($subscriptionId);
            if ($originalOrder && $originalOrder->getData('vindi_subscription_can_create_new_order') == true) {
                $originalOrder->setData('vindi_subscription_can_create_new_order', false);
                $originalOrder->setData('vindi_bill_id', $bill['id']);
                $this->orderRepository->save($originalOrder);
                $this->logger->info(__('Vindi bill ID set for the order.'));

                $this->emailSender->sendQrCodeAvailableEmail($originalOrder);
                return true;
            }

            $orders = $this->orderCreator->getOrdersBySubscriptionId($subscriptionId);
            foreach ($orders as $order) {
                if ($order->getData('vindi_subscription_can_create_new_order') == true) {
                    $this->logger->info(__('Not all orders for subscription ID: %1 have vindi_bill_id set', $subscriptionId));
                    return false;
                }
            }

            $queueItem = $this->orderCreationQueueFactory->create();
            $queueItem->setData([
                'bill_data' => json_encode($data),
                'status'    => 'pending'
            ]);
            $this->orderCreationQueueRepository->save($queueItem);

            return true;
        } finally {
            $this->dbAdapter->query("SELECT RELEASE_LOCK(?)", [$lockName]);
        }
    }
}
