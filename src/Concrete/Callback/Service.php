<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Callback;

use Concrete\Core\Error\UserMessageException;
use Concrete\Core\System\Mutex\MutexBusyException;
use Concrete\Core\System\Mutex\MutexInterface;
use Concrete\Package\CommunityStoreNexi\Entity\HostedOrder;
use MLocati\Nexi\Entity\Operation as NexiOperation;

defined('C5_EXECUTE') or die('Access Denied');

class Service
{
    const MUTEX_KEY = 'cstore_nexi_callback';

    /**
     * @var \Concrete\Core\System\Mutex\MutexInterface
     */
    private $mutex;

    public function __construct(
        MutexInterface $mutex
    ) {
        $this->mutex = $mutex;
    }

    /**
     * @throws \Concrete\Core\System\Mutex\MutexBusyException
     */
    public function acquireMutex(int $maxSeconds = 7): void
    {
        $startTime = time();
        for (;;) {
            try {
                $this->mutex->acquire(static::MUTEX_KEY);

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

    public function releaseMutex(): void
    {
        $this->mutex->release(static::MUTEX_KEY);
    }

    /**
     * @throws \Concrete\Core\Error\UserMessageException
     *
     * @return bool TRUE if paid, FALSE if canceled by customer
     *
     * @see https://developer.nexi.it/en/api/get-orders-orderId
     */
    public function checkOperationResult(NexiOperation $operation): bool
    {
        $operationResult = (string) $operation->getOperationResult();
        switch ($operationResult) {
            case 'AUTHORIZED':
                // Payment authorized
            case 'EXECUTED':
                // Payment confirmed, verification successfully executed
                return true;
            case 'CANCELED':
                // Canceled by the cardholder
                return false;
            case 'THREEDS_FAILED':
                // Cancellation or authentication failure during 3DS
                return false;
            case 'DECLINED':
                throw new UserMessageException(t('The payment has been declined by the issuer during the authorization phase.'));
            case 'DENIED_BY_RISK':
                throw new UserMessageException(t('The payment has been declined because of negative outcome of the transaction risk analysis.'));
            case 'THREEDS_VALIDATED':
                throw new UserMessageException(t('The payment has been declined because of 3DS authentication OK or 3DS skipped (non-secure payment).'));
            case 'PENDING':
                throw new UserMessageException(t('The payment is ongoing'));
            case 'VOIDED':
                throw new UserMessageException(t('Online reversal of the full authorized amount'));
            case 'REFUNDED':
                throw new UserMessageException(t('Full or partial amount refunded'));
            case 'FAILED':
                throw new UserMessageException(t('Payment failed due to technical reasons'));
            default:
                throw new UserMessageException(t('Unknown operation result (%s)', $operationResult));
        }
    }

    /**
     * @return string empty string if ok, error description otherwise
     */
    public function checkOperationData(HostedOrder $hostedOrder, NexiOperation $operation): string
    {
        $requestOrder = $hostedOrder->getRequest()->getOrder();
        $expectedCurrency = $requestOrder->getCurrency();
        $actualCurrency = $operation->getOperationCurrency() ?? '';
        if ($expectedCurrency !== $actualCurrency) {
            return t('Wrong currency: expected %1$s, received %2$s', $expectedCurrency, $actualCurrency);
        }
        $expectedAmount = $requestOrder->getAmount();
        $actualAmount = $operation->getOperationAmount();
        if ((string) $expectedAmount !== (string) $actualAmount) {
            return t('Wrong amount: expected %1$s, received %2$s', $expectedAmount, $actualAmount);
        }

        return '';
    }
}
