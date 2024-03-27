<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Callback;

use Concrete\Core\Application\Service\UserInterface;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Http\ResponseFactoryInterface;
use Concrete\Core\Session\SessionValidator;
use Concrete\Core\Url\Resolver\Manager\ResolverManagerInterface;
use Concrete\Package\CommunityStoreNexi\Entity\HostedOrder;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order;
use Doctrine\ORM\EntityManagerInterface;
use MLocati\Nexi\Client;
use MLocati\Nexi\Entity\FindOrderById\Response as NexiFoundOrder;
use Concrete\Package\CommunityStoreNexi\Nexi\HttpClient;
use MLocati\Nexi\Entity\Operation as NexiOperation;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Concrete\Package\CommunityStoreNexi\Nexi\Configuration\Factory as ConfigurationFactory;

defined('C5_EXECUTE') or die('Access Denied');

class Customer
{
    /**
     * @var \Concrete\Core\Session\SessionValidator
     */
    private $sessionValidator;

    /**
     * @var \Doctrine\ORM\EntityManagerInterface
     */
    private $em;

    /**
     * @var \Concrete\Core\Http\ResponseFactoryInterface
     */
    private $responseFactory;

    /**
     * @var \Concrete\Core\Url\Resolver\Manager\ResolverManagerInterface
     */
    private $urlResolver;

    /**
     * @var \Concrete\Package\CommunityStoreNexi\Nexi\Configuration\Factory
     */
    private $configurationFactory;

    /**
     * @var \Concrete\Package\CommunityStoreNexi\Nexi\HttpClient
     */
    private $httpClient;
    
    /**
     * @var \Concrete\Core\Application\Service\UserInterface
     */
    private $userInterface;

    /**
     * @var \Concrete\Package\CommunityStoreNexi\Callback\Service
     */
    private $service;

    public function __construct(
        SessionValidator $sessionValidator,
        EntityManagerInterface $em,
        ResponseFactoryInterface $responseFactory,
        ResolverManagerInterface $urlResolver,
        ConfigurationFactory $configurationFactory,
        HttpClient $httpClient,
        UserInterface $userInterface,
        Service $service
    )
    {
        $this->sessionValidator = $sessionValidator;
        $this->em = $em;
        $this->responseFactory = $responseFactory;
        $this->urlResolver = $urlResolver;
        $this->configurationFactory = $configurationFactory;
        $this->httpClient = $httpClient;
        $this->userInterface = $userInterface;
        $this->service = $service;
    }

    public function __invoke(): Response
    {
        $hostedOrder = $this->getHostedOrder();
        if ($hostedOrder === null) {
            return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['/checkout']));
        }
        $this->service->acquireMutex();
        try {
            $order = $hostedOrder->getAssociatedOrder();
            if ($order->getPaid()) {
                return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['checkout/complete']));
            }
            $check = new HostedOrder\Check(HostedOrder\Check::PLACE_CUSTOMER);
            $check->setHostedOrder($hostedOrder);
            $this->em->persist($check);
            try {
                $nexiOrderID = $hostedOrder->getRequest()->getOrder()->getOrderId();
                $nexiOrder = $this->createNexiClient()->findOrderById($nexiOrderID);
                if ($nexiOrder === null) {
                    throw new RuntimeException(t('Unable to find the Nexi order with ID %s', $nexiOrderID));
                }
                $check->setReceivedJson(json_encode($nexiOrder, JSON_UNESCAPED_SLASHES));
                $operation = $this->getOperation($nexiOrder);
                if ($operation === null) {
                    $check->setError(t('Missing operation'));
                    return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['/checkout']));
                }
                try {
                    if ($this->service->checkOperationResult($operation) !== true) {
                        return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['/checkout']));
                    }
                } catch (UserMessageException $x) {
                    $check->setError($x->getMessage());
                    return $this->userInterface->buildErrorResponse(
                        t('Payment failed'),
                        implode('<br />', [
                            h($x->getMessage()),
                            '',
                            '<a href="' . h((string) $this->urlResolver->resolve(['/checkout'])) . '">' . t('Click here to return to the checkout page.') . '</a>',
                        ])
                    );
                }
                $error = $this->service->checkOperationData($hostedOrder, $operation);
                if ($error !== '') {
                    $check->setError($error);
                    throw new RuntimeException($error);
                }
                $order->completeOrder($operation->getOperationId());
                $order->updateStatus(Order\OrderStatus\OrderStatus::getStartingStatus()->getHandle());
            } catch (Throwable $x) {
                if ($check->getError() === '') {
                    $check->setError((string) $x);
                }
                throw $x;
            } finally {
                $this->em->flush();
            }
        } finally {
            $this->service->releaseMutex();
        }

        return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['checkout/complete']));
    }

    private function getHostedOrder(): ?HostedOrder
    {
        $session = $this->sessionValidator->getActiveSession();
        if ($session === null) {
            return null;
        }
        $hostedOrderID = $session->get('storeNexiHostedOrderID');
        if (!is_numeric($hostedOrderID)) {
            return null;
        }

        return $this->em->find(HostedOrder::class, (int) $hostedOrderID);
    }

    private function getOperation(NexiFoundOrder $order): ?NexiOperation
    {
        $operations = $order->getOperations();
        if ($operations !== null) {
            foreach ($operations as $operation) {
                if ($operation->getOperationType() === 'AUTHORIZATION') {
                    return $operation;
                }
            }
        }
        return null;
    }

    private function createNexiClient(): Client
    {
        return new Client(
            $this->configurationFactory->createConfiguration(),
            $this->httpClient
        );
    }
}
