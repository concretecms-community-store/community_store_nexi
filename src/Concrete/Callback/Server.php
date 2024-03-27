<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Callback;

use Concrete\Core\Http\Request;
use Concrete\Core\Http\Response;
use Concrete\Core\Http\ResponseFactoryInterface;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order;
use Doctrine\ORM\EntityManagerInterface;
use Concrete\Package\CommunityStoreNexi\Entity\HostedOrder;
use Concrete\Core\Error\UserMessageException;
use MLocati\Nexi\Entity\Webhook\Request as WebhookRequest;
use stdClass;

defined('C5_EXECUTE') or die('Access Denied');

class Server
{
    /**
     * @var \Concrete\Core\Http\Request
     */
    private $request;

    /**
     * @var \Concrete\Core\Http\ResponseFactoryInterface
     */
    private $responseFactory;

    /**
     * @var \Doctrine\ORM\EntityManagerInterface
     */
    private $em;
    
    /**
     * @var \Concrete\Package\CommunityStoreNexi\Callback\Service
     */
    private $service;
    
    public function __construct(
        Request $request,
        ResponseFactoryInterface $responseFactory,
        EntityManagerInterface $em,
        Service $service
    ) {
        $this->request = $request;
        $this->responseFactory = $responseFactory;
        $this->em = $em;
        $this->service = $service;
    }

    public function __invoke(): Response
    {
        $json = $this->request->getContent();
        $check = new HostedOrder\Check(HostedOrder\Check::PLACE_SERVER);
        $check->setReceivedJson($json);
        $this->em->persist($check);
        try {
            $webhookRequest = $this->getNotificationBody($json);
            $hostedOrder = $this->getHostedOrder($webhookRequest);
            $check->setHostedOrder($hostedOrder);
            $this->service->acquireMutex();
            try {
                if ($this->verifyWebhookRequest($webhookRequest, $hostedOrder)) {
                    $order = $hostedOrder->getAssociatedOrder();
                    if (!$order->getPaid()) {
                        $order->completeOrder($webhookRequest->getOperation()->getOperationId());
                        $order->updateStatus(Order\OrderStatus\OrderStatus::getStartingStatus()->getHandle());
                    }
                }
            } finally {
                $this->service->releaseMutex();
            }
        } catch (UserMessageException $x) {
            $check->setError($x->getMessage());
        } finally {
            $this->em->flush();
        }

        return $this->responseFactory->create(
            '',
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain']
        );
    }

    private function getNotificationBody(string $json): WebhookRequest
    {
        $data = $json === '' ? null : json_decode($json);
        if (!$data instanceof stdClass) {
            throw new UserMessageException(t('Invalid JSON received'));
        }
        $webhookRequest = new WebhookRequest($data);
        if ((string) $webhookRequest->getSecurityToken() === '') {
            throw new UserMessageException(t('Missing field in request: %s', 'securityToken'));
        }

        return $webhookRequest;
    }

    private function getHostedOrder(WebhookRequest $webhookRequest): HostedOrder
    {
        $nexiOrderID = $webhookRequest->getOperation() === null ? '' : (string) $webhookRequest->getOperation()->getOrderId();
        if ($nexiOrderID === '') {
            throw new UserMessageException(t('Missing field in request: %s', 'operation.orderId'));
        }
        $repo = $this->em->getRepository(HostedOrder::class);
        $hostedOrder = $repo->findOneBy(['nexiOrderID' => $nexiOrderID]);
        if ($hostedOrder === null) {
            throw new UserMessageException(t('Unable to find the hosted order with Nexi ID %s', $nexiOrderID));
        }

        return $hostedOrder;
    }

    /**
     * @throws \Concrete\Core\Error\UserMessageException
     *
     * @return bool TRUE if paid, FALSE if canceled by customer
     *
     * @see https://developer.nexi.it/en/api/get-orders-orderId
     */
    private function verifyWebhookRequest(WebhookRequest $webhookRequest, HostedOrder $hostedOrder): bool
    {
        if ($hostedOrder->getResponse() === null || $hostedOrder->getResponse()->getSecurityToken() !== $webhookRequest->getSecurityToken()) {
            throw new UserMessageException(t('Wrong security token'));
        }
        $operation = $webhookRequest->getOperation();
        if ($operation === null) {
            throw new UserMessageException(t('Missing operation'));
        }
        if ($this->service->checkOperationResult($operation) === false) {
            return false;
        }
        $error = $this->service->checkOperationData($hostedOrder, $operation);
        if ($error !== '') {
            throw new UserMessageException($error);
        }

        return true;
    }
}
