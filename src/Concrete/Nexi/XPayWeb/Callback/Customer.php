<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Nexi\XPayWeb\Callback;

use Concrete\Core\Application\Service\UserInterface;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Http\ResponseFactoryInterface;
use Concrete\Core\Session\SessionValidator;
use Concrete\Core\Url\Resolver\Manager\ResolverManagerInterface;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order;
use Concrete\Package\CommunityStoreNexi\Entity\XPayWebOrder;
use Concrete\Package\CommunityStoreNexi\Nexi\XPayWeb\Configuration\Factory as ConfigurationFactory;
use Concrete\Package\CommunityStoreNexi\Nexi\XPayWeb\HttpClient;
use Doctrine\ORM\EntityManagerInterface;
use MLocati\Nexi\XPayWeb\Client;
use MLocati\Nexi\XPayWeb\Entity\FindOrderById\Response as NexiFoundOrder;
use MLocati\Nexi\XPayWeb\Entity\Operation as NexiOperation;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
     * @var \Concrete\Package\CommunityStoreNexi\Nexi\XPayWeb\Configuration\Factory
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
     * @var \Concrete\Package\CommunityStoreNexi\Nexi\XPayWeb\Callback\Service
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
    ) {
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
        $xPayWebOrder = $this->getXPayWebOrder();
        if ($xPayWebOrder === null) {
            return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['/checkout']));
        }
        $this->service->acquireMutex();
        try {
            $order = $xPayWebOrder->getAssociatedOrder();
            $check = new XPayWebOrder\Check(XPayWebOrder\Check::PLACE_CUSTOMER);
            $check->setXPayWebOrder($xPayWebOrder);
            $this->em->persist($check);
            try {
                $nexiOrderID = $xPayWebOrder->getRequest()->getOrder()->getOrderId();
                $nexiOrder = $this->createNexiClient()->findOrderById($nexiOrderID);
                if ($nexiOrder === null) {
                    throw new RuntimeException(t('Unable to find the Nexi order with ID %s', $nexiOrderID));
                }
                $check->setReceivedJson(json_encode($nexiOrder, JSON_UNESCAPED_SLASHES));
                $operation = $this->getOperation($nexiOrder);
                if ($operation === null) {
                    $check->setError(t('Missing operation'));
                    if ($order->getPaid()) {
                        return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['checkout/complete']));
                    }
                    return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['/checkout']));
                }
                try {
                    if ($this->service->checkOperationResult($operation) !== true) {
                        if ($order->getPaid()) {
                            return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['checkout/complete']));
                        }
                        return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['/checkout']));
                    }
                } catch (UserMessageException $x) {
                    $check->setError($x->getMessage());
                    if ($order->getPaid()) {
                        return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['checkout/complete']));
                    }

                    return $this->userInterface->buildErrorResponse(
                        t('Payment failed'),
                        implode('<br />', [
                            h($x->getMessage()),
                            '',
                            '<a href="' . h((string) $this->urlResolver->resolve(['/checkout'])) . '">' . t('Click here to return to the checkout page.') . '</a>',
                        ])
                    );
                }
                $error = $this->service->checkOperationData($xPayWebOrder, $operation);
                $check->setError($error);
                if (!$order->getPaid()) {
                    if ($error !== '') {
                        throw new RuntimeException($error);
                    }
                    $order->completeOrder($operation->getOperationId());
                    $order->updateStatus(Order\OrderStatus\OrderStatus::getStartingStatus()->getHandle());
                }
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

    private function getXPayWebOrder(): ?XPayWebOrder
    {
        $session = $this->sessionValidator->getActiveSession();
        if ($session === null) {
            return null;
        }
        $xPayWebOrderID = $session->get('storeNexiXPayWebOrderID');
        if (!is_numeric($xPayWebOrderID)) {
            return null;
        }

        return $this->em->find(XPayWebOrder::class, (int) $xPayWebOrderID);
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
