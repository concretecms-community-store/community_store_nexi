<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Src\CommunityStore\Payment\Methods\Nexi;

use Concrete\Package\CommunityStore\Src\CommunityStore\Payment\LogProvider as CSLogProvider;
use Concrete\Package\CommunityStore\Src\CommunityStore\Payment\LogEntry;
use Concrete\Package\CommunityStoreNexi\Entity;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

defined('C5_EXECUTE') or die('Access Denied.');

class LogProvider implements CSLogProvider
{
    private string $implementation;

    private EntityManagerInterface $em;

    public function __construct(string $implementation, EntityManagerInterface $em)
    {
        $this->implementation = $implementation;
        $this->em = $em;
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\LogProvider::getHandle()
     */
    public function getHandle(): string
    {
        switch ($this->implementation) {
            case 'xpay':
            case 'xpay_web':
                return "nexi_{$this->implementation}";
        }
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\LogProvider::getName()
     */
    public function getName(): string
    {
        switch ($this->implementation) {
            case 'xpay':
                return 'Nexi (X-Pay)';
            case 'xpay_web':
                return 'Nexi (X-Pay Web)';
        }
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\LogProvider::findByDate()
     */
    public function findByDate(DateTimeInterface $fromInclusive, DateTimeInterface $toExclusive): array
    {
        return $this->find(function (QueryBuilder $qb) use ($fromInclusive, $toExclusive): void {
            $dtFormat = $this->em->getConnection()->getDatabasePlatform()->getDateTimeFormatString();
            switch ($this->implementation) {
                case 'xpay':
                case 'xpay_web':
                    $qb
                        ->andWhere('xo.createdOn >= :from')
                        ->andWhere('xo.createdOn < :to')
                        ->setParameter('from', $fromInclusive->format($dtFormat))
                        ->setParameter('to', $toExclusive->format($dtFormat))
                    ;
                    break;
            }
        });
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\LogProvider::findByOrderID()
     */
    public function findByOrderID(int $orderID): array
    {
        return $this->find(function (QueryBuilder $qb) use ($orderID): void {
            switch ($this->implementation) {
                case 'xpay':
                case 'xpay_web':
                    $qb
                        ->andWhere('xo.associatedOrder = :orderID')
                        ->setParameter('orderID', $orderID)
                    ;
                    break;
            }
        });
    }

    /**
     * @return \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\LogEntry[]
     */
    private function find(callable $where): array
    {
        $qb = $this->em->createQueryBuilder();
        switch ($this->implementation) {
            case 'xpay':
                $qb
                    ->from(Entity\XPayOrder::class, 'xo')
                ;
                break;
            case 'xpay_web':
                $qb
                    ->from(Entity\XPayWebOrder::class, 'xo')
                ;
                break;
        }
        switch ($this->implementation) {
            case 'xpay':
            case 'xpay_web':
                $qb
                    ->select('xo, xoc, o')
                    ->leftJoin('xo.checks', 'xoc')
                    ->leftJoin('xo.associatedOrder', 'o')
                    ->addOrderBy('xo.createdOn')
                    ->addOrderBy('xo.id')
                    ->addOrderBy('xoc.createdOn')
                    ->addOrderBy('xoc.id')
                ;
                break;
        }
        $where($qb);
        $result = [];
        foreach ($qb->getQuery()->execute() as $xOrder) {
            $result = array_merge($result, $this->serializeXOrder($xOrder));
        }

        return $result;
    }

    /**
     * @param \Concrete\Package\CommunityStoreNexi\Entity\XPayOrder|\Concrete\Package\CommunityStoreNexi\Entity\XPayWebOrder $xOrder
     * @return array
     */
    private function serializeXOrder($xOrder): array
    {
        $data = '?';
        $error = '?';
        switch ($this->implementation) {
            case 'xpay':
                $data = $this->serializeXPayOrder($xOrder);
                $error = '';
                break;
            case 'xpay_web':
                $data = $this->serializeXPayWebOrder($xOrder);
                $error = $xOrder->getRequestError();
                break;
        }
        $result = [
            new LogEntry(
                $xOrder->getCreatedOn(),
                $this->getName() . ($xOrder->getEnvironment() === 'sandbox' ? (' (' . t('Test') . ')') : ''),
                t('Data sent to Nexi'),
                $xOrder->getAssociatedOrder(),
                $data,
                $error
            ),
        ];
        foreach ($xOrder->getChecks() as $xCheck) {
            /** @var \Concrete\Package\CommunityStoreNexi\Entity\XPayOrder\Check|\Concrete\Package\CommunityStoreNexi\Entity\XPayWebOrder\Check $xCheck */
            $type = '?';
            switch ($this->implementation) {
                case 'xpay':
                    switch($xCheck->getPlace()) {
                        case Entity\XPayOrder\Check::PLACE_CUSTOMER_CANCEL:
                            $type = t('Cancelled by customer');
                            break;
                        case Entity\XPayOrder\Check::PLACE_CUSTOMER_REDIRECT:
                            $type = t('Customer fulfilled form');
                            break;
                        case Entity\XPayOrder\Check::PLACE_SERVER:
                            $type = t('Server-to-server communication');
                            break;
                    }
                    $data = $this->serializeXPayCheck($xCheck);
                    break;
                case 'xpay_web':
                    switch($xCheck->getPlace()) {
                        case Entity\XPayWebOrder\Check::PLACE_CUSTOMER:
                            $type = t('Customer fulfilled form');
                            break;
                        case Entity\XPayWebOrder\Check::PLACE_SERVER:
                            $type = t('Server-to-server communication');
                            break;
                    }
                    $data = $this->serializeXPayWebCheck($xCheck);
                    break;
            }
            $result[] = new LogEntry(
                $xCheck->getCreatedOn(),
                $this->getName() . ($xOrder->getEnvironment() === 'sandbox' ? (' (' . t('Test Environment') . ')') : ''),
                $type,
                $xOrder->getAssociatedOrder(),
                $data,
                $xCheck->getError(),
            );
        }

        return $result;
    }

    /**
     * @return array|string|null
     */
    private function serializeXPayOrder(Entity\XPayOrder $xOrder)
    {
        return $this->formatJsonObject($xOrder->getRequest());
    }

    /**
     * @return array|string|null
     */
    private function serializeXPayCheck(Entity\XPayOrder\Check $xCheck)
    {
        return $this->formatJsonObjectString($xCheck->getReceivedJson());
    }

    /**
     * @return array|string|null
     */
    private function serializeXPayWebOrder(Entity\XPayWebOrder $xOrder)
    {
        $result = [
            t('Request'),
            ['', $this->formatJsonObject($xOrder->getRequest())],
        ];
        $response = $xOrder->getResponse();
        if ($response !== null) {
            $result = array_merge($result, [
                t('Response'),
                ['', $this->formatJsonObject($response)],
            ]);
        }

        return $result;
    }

    /**
     * @return array|string|null
     */
    private function serializeXPayWebCheck(Entity\XPayWebOrder\Check $xCheck)
    {
        return $this->formatJsonObjectString($xCheck->getReceivedJson());
    }

    private function formatJsonObjectString(?string $json): ?string
    {
        if ($json === null || $json === '') {
            return null;
        }
        $data = json_decode($json);
        if (!$data instanceof \stdClass) {
            return null;
        }
        return $this->formatJsonObject($data);
    }

    private function formatJsonObject(?object $data): ?string
    {
        return $data === null ? null : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
    }
}
