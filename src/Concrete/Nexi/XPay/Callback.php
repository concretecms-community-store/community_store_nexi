<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Nexi\XPay;

use Concrete\Core\Application\Service\UserInterface;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Http\Request;
use Concrete\Core\Http\ResponseFactoryInterface;
use Concrete\Core\Session\SessionValidator;
use Concrete\Core\System\Mutex\MutexBusyException;
use Concrete\Core\System\Mutex\MutexInterface;
use Concrete\Core\Url\Resolver\Manager\ResolverManagerInterface;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderStatus\OrderStatus;
use Concrete\Package\CommunityStoreNexi\Entity\XPayOrder;
use Concrete\Package\CommunityStoreNexi\Nexi\XPay\Configuration\Factory as ConfigurationFactory;
use Doctrine\ORM\EntityManagerInterface;
use MLocati\Nexi\XPay\Entity\SimplePay\Callback\Customer\Cancel;
use MLocati\Nexi\XPay\Entity\SimplePay\Callback\Data;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

defined('C5_EXECUTE') or die('Access Denied');

class Callback
{
    const MUTEX_KEY = 'cstore_nexi_xpay_callback';
    
    /**
     * @var \Concrete\Core\Http\Request
     */
    protected $request;

    /**
     * @var \Concrete\Core\Session\SessionValidator
     */
    protected $sessionValidator;

    /**
     * @var \Doctrine\ORM\EntityManagerInterface
     */
    protected $em;

    /**
     * @var \Concrete\Core\Http\ResponseFactoryInterface
     */
    protected $responseFactory;

    /**
     * @var \Concrete\Core\Url\Resolver\Manager\ResolverManagerInterface
     */
    protected $urlResolver;

    /**
     * @var \Concrete\Package\CommunityStoreNexi\Nexi\XPay\Configuration\Factory
     */
    protected $configurationFactory;
    /**
     * @var \Concrete\Package\CommunityStoreNexi\Nexi\XPay\HttpClient
     */
    protected $httpClient;

    /**
     * @var \Concrete\Core\Application\Service\UserInterface
     */
    protected $userInterface;

    /**
     * @var \Concrete\Core\System\Mutex\MutexInterface
     */
    protected $mutex;

    public function __construct(
        Request $request,
        SessionValidator $sessionValidator,
        EntityManagerInterface $em,
        ResponseFactoryInterface $responseFactory,
        ResolverManagerInterface $urlResolver,
        ConfigurationFactory $configurationFactory,
        HttpClient $httpClient,
        UserInterface $userInterface,
        MutexInterface $mutex
    ) {
        $this->request = $request;
        $this->sessionValidator = $sessionValidator;
        $this->em = $em;
        $this->responseFactory = $responseFactory;
        $this->urlResolver = $urlResolver;
        $this->configurationFactory = $configurationFactory;
        $this->httpClient = $httpClient;
        $this->userInterface = $userInterface;
        $this->mutex = $mutex;
    }

    public function customerCancel(): Response
    {
        $xPayOrder = $this->getXPayOrderFromSession();
        if ($xPayOrder === null) {
            return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['/checkout']));
        }
        $data = Cancel::fromCustomerRequest($this->request->query->all());
        $this->acquireMutex();
        try {
            $check = new XPayOrder\Check(XPayOrder\Check::PLACE_CUSTOMER_CANCEL);
            $this->em->persist($check);
            try {
                $check->setXPayOrder($xPayOrder);
                $check->setReceivedJson(json_encode($data, JSON_UNESCAPED_SLASHES));
                $data->checkRequiredFields();
                $order = $xPayOrder->getAssociatedOrder();
                if ($order->getPaid()) {
                    return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['checkout/complete']));
                }
                return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['checkout']));
            } catch (Throwable $x) {
                if ($check->getError() === '') {
                    $check->setError((string) $x);
                }
                throw $x;
            } finally {
                $this->em->flush();
            }
        } catch (UserMessageException $x) {
            return $this->userInterface->buildErrorResponse(
                t('Payment failed'),
                implode('<br />', [
                    h($x->getMessage()),
                    '',
                    '<a href="' . h((string) $this->urlResolver->resolve(['/checkout'])) . '">' . t('Click here to return to the checkout page.') . '</a>',
                ])
            );
        } finally {
            $this->releaseMutex();
        }
    }

    public function customerRedirect(): Response
    {
        $xPayOrder = $this->getXPayOrderFromSession();
        if ($xPayOrder === null) {
            return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['/checkout']));
        }
        $data = Data::fromCustomerRequest($this->request->query->all());
        $this->acquireMutex();
        try {
            $check = new XPayOrder\Check(XPayOrder\Check::PLACE_CUSTOMER_REDIRECT);
            $this->em->persist($check);
            try {
                $check->setXPayOrder($xPayOrder);
                $check->setReceivedJson(json_encode($data, JSON_UNESCAPED_SLASHES));
                $data->checkRequiredFields();
                $configuration = $this->configurationFactory->createConfiguration($xPayOrder->getEnvironment());
                $data->checkMac($configuration);
                $order = $xPayOrder->getAssociatedOrder();
                $this->processOrder($data, $order);
                return $this->responseFactory->redirect((string) $this->urlResolver->resolve(['checkout/complete']));
            } catch (Throwable $x) {
                if ($check->getError() === '') {
                    $check->setError((string) $x);
                }
                throw $x;
            } finally {
                $this->em->flush();
            }
        } catch (UserMessageException $x) {
            return $this->userInterface->buildErrorResponse(
                t('Payment failed'),
                implode('<br />', [
                    h($x->getMessage()),
                    '',
                    '<a href="' . h((string) $this->urlResolver->resolve(['/checkout'])) . '">' . t('Click here to return to the checkout page.') . '</a>',
                ])
            );
        } finally {
            $this->releaseMutex();
        }
    }

    public function server2Server(): Response
    {
        $postedData = $this->request->request->all();
        if ($postedData === []) {
            return $this->responseFactory->notFound('');
        }
        $data = Data::fromServer2ServerRequest($postedData);
        $this->acquireMutex();
        try {
            $check = new XPayOrder\Check(XPayOrder\Check::PLACE_SERVER);
            $this->em->persist($check);
            try {
                $check->setReceivedJson(json_encode($data, JSON_UNESCAPED_SLASHES));
                $data->checkRequiredFields();
                $xPayOrder = $this->getXPayOrderFromData($data);
                if ($xPayOrder === null) {
                    throw new RuntimeException(t('Unable to find the Nexi order associated to the server2server request data'));
                }
                $check->setXPayOrder($xPayOrder);
                $configuration = $this->configurationFactory->createConfiguration($xPayOrder->getEnvironment());
                $data->checkMac($configuration);
                $order = $xPayOrder->getAssociatedOrder();
                $this->processOrder($data, $order);
                return $this->responseFactory->create(200);
            } catch (Throwable $x) {
                if ($check->getError() === '') {
                    $check->setError((string) $x);
                }
                throw $x;
            } finally {
                $this->em->flush();
            }
        } catch (UserMessageException $x) {
            return $this->responseFactory->create(200);
        } finally {
            $this->releaseMutex();
        }
    }

    /**
     * @throws \Concrete\Core\System\Mutex\MutexBusyException
     */
    private function acquireMutex(int $maxSeconds = 7): void
    {
        $startTime = time();
        for (;;) {
            try {
                $this->mutex->acquire(self::MUTEX_KEY);
                
                return;
            } catch (MutexBusyException $x) {
                $elapsedTime = time() - $startTime;
                if ($elapsedTime > $maxSeconds) {
                    throw $x;
                }
                usleep(100000); // 0.1 seconds
            }
        }
    }

    private function releaseMutex(): void
    {
        $this->mutex->release(static::MUTEX_KEY);
    }
    
    private function getXPayOrderFromSession(): ?XPayOrder
    {
        $session = $this->sessionValidator->getActiveSession();
        if ($session === null) {
            return null;
        }
        $xPayOrderID = $session->get('storeNexiXPayOrderID');
        if (!is_numeric($xPayOrderID)) {
            return null;
        }
        
        return $this->em->find(XPayOrder::class, (int) $xPayOrderID);
    }

    private function getXPayOrderFromData(Data $data): ?XPayOrder
    {
        $codTrans = $data->getCodTrans();
        if ($codTrans === '') {
            return null;
        }
        return $this->em->getRepository(XPayOrder::class)->findOneBy(['nexiCodTrans' => $codTrans]);
    }

    private function processOrder(Data $data, Order $order): void
    {
        if ($order->getPaid()) {
            return;
        }
        switch ($data->getEsito()) {
            case \MLocati\Nexi\XPay\Entity\Response::ESITO_OK:
                $expectedAmount = (string) $order->getTotal();
                $actualAmount = $data->getImportoAsDecimal();
                if ($expectedAmount !== $actualAmount) {
                    throw new RuntimeException(t('Wrong amount: expected %1$s, received %2$s', $expectedAmount, $actualAmount));
                }
                $order->completeOrder($data->getCodAut() ?: $data->getIdTransazioneBPay());
                $order->updateStatus(OrderStatus::getStartingStatus()->getHandle());
                return;
            case \MLocati\Nexi\XPay\Entity\Response::ESITO_ERROR:
            case \MLocati\Nexi\XPay\Entity\Response::ESITO_KO:
                switch ($data->getCodiceEsito()) {
                    case 20:
                        throw new UserMessageException(t('Order not present'));
                    case 101:
                        throw new UserMessageException(t('Incorrect or missing parameters'));
                    case 102:
                        throw new UserMessageException(t('The specified PAN cannot perform further authorizations'));
                    case 108:
                        throw new UserMessageException(t('Order already registered'));
                    case 109:
                        throw new UserMessageException(t('Technical error'));
                    case 110:
                        throw new UserMessageException(t('Contract number already present'));
                    case 112:
                        throw new UserMessageException(t('Transaction denied due to VBV/SC authentication failed or not possible'));
                    case 113:
                        throw new UserMessageException(t('Contract number not present in the archive'));
                    case 114:
                        throw new UserMessageException(t('Merchant not enabled for multiple payments on the group'));
                    case 115:
                        throw new UserMessageException(t('Group code not present'));
                    case 116:
                        throw new UserMessageException(t('3D Secure canceled by user'));
                    case 117:
                        throw new UserMessageException(t('Unauthorized card due to application of BIN Table rules'));
                    case 118:
                        throw new UserMessageException(t('The card\'s PAN is already associated with another tax code'));
                    case 119:
                        throw new UserMessageException(t('Merchant not authorized to operate in this mode'));
                    case 120:
                        throw new UserMessageException(t('Circuit not accepted'));
                    case 121:
                        throw new UserMessageException(t('Transaction closed due to timeout'));
                    case 122:
                        throw new UserMessageException(t('Too many retry attempts'));
                    case 129:
                        throw new UserMessageException(t('Card not valid for charging (expired or blocked)'));
                    case 400:
                        throw new UserMessageException(t('Authorization denied: please check the data entered, or ask your bank/issuer for clarification'));
                    case 401:
                        throw new UserMessageException(t('Expired card: please check the data entered before trying again'));
                    case 402:
                        throw new UserMessageException(t('Restricted or invalid card, or your account is closed'));
                    case 403:
                        throw new UserMessageException(t('Invalid merchant: please contact our contact support'));
                    case 404:
                        throw new UserMessageException(t('Transaction not permitted by the card issuer: please to use another payment instrument'));
                    case 405:
                        throw new UserMessageException(t('Insufficient funds: please retry after restoring the availability on the card or use another payment method'));
                    case 406:
                        throw new UserMessageException(t('Technical problems with the authorization systems: please retry or contact support'));
                    case 407:
                        throw new UserMessageException(t('Unable to contact the issuing bank: please retry or use another payment method'));
                    case 408:
                        throw new UserMessageException(t('Transaction not permitted by card issuer'));
                    case 409:
                        throw new UserMessageException(t('The card issuer suspects a fraudulent payment'));
                    case 410:
                        throw new UserMessageException(t('You specified an incorrect PIN/authentication more times than allowed by the issuing bank: please retry or use another payment method'));
                    case 411:
                        throw new UserMessageException(t('Transaction denied: please contact your bank for clarification, or use another payment method'));
                    case 412:
                        throw new UserMessageException(t('The card may be lost, blocked or counterfeit: please use another new payment method'));
                    case 413:
                        throw new UserMessageException(t('You attempted to re-submit the same transaction which has previously undergone a decline: please contact your bank or use a new payment instrument'));
                    case 414:
                        throw new UserMessageException(t('Card daily spending limits exceeded'));
                }
                throw new UserMessageException($data->getMessaggio() ?: t('Transaction denied'));
        }
        throw new RuntimeException(t('Invalid/unrecognized response status: %s', $data->getEsito()));
    }
}
