<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Entity;

use Concrete\Package\CommunityStore\Src\CommunityStore;
use DateTime;
use Doctrine\Common\Collections;
use MLocati\Nexi\XPay\Entity\SimplePay\Request as NexiOrderRequest;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @Doctrine\ORM\Mapping\Entity
 * @Doctrine\ORM\Mapping\Table(
 *     name="CommunityStoreNexiXPayOrders",
 *     options={"comment": "SimplePay orders for Nexi XPay payment method"}
 * )
 */
class XPayOrder
{
    /**
     * The record ID (null if not yet persisted).
     *
     * @Doctrine\ORM\Mapping\Id
     * @Doctrine\ORM\Mapping\Column(type="integer", options={"unsigned":true, "comment": "Init ID"})
     * @Doctrine\ORM\Mapping\GeneratedValue(strategy="AUTO")
     *
     * * @var int|null
     */
    protected ?int $id;

    /**
     * The record creation date/time.
     *
     * @Doctrine\ORM\Mapping\Column(type="datetime", nullable=false, options={"comment": "Record creation date/time"})
     *
     * @var \DateTime
     */
    protected DateTime $createdOn;

    /**
     * The environment (sandbox or production).
     *
     * @Doctrine\ORM\Mapping\Column(type="string", length=32, nullable=false, options={"comment": "Environment (sandbox or production)"})
     *
     * @var string
     */
    protected $environment;

    /**
     * The order associated to this request.
     *
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order")
     * @Doctrine\ORM\Mapping\JoinColumn(name="associatedOrder", referencedColumnName="oID", nullable=false, onDelete="CASCADE")
     *
     * @var \Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order
     */
    protected $associatedOrder;

    /**
     * The request (in JSON format).
     *
     * @Doctrine\ORM\Mapping\Column(type="text", nullable=false, options={"comment": "Request (in JSON format)"})
     *
     * @var string
     */
    protected $requestJson;

    /**
     * The codTrans as sent to Nexi.
     *
     * @Doctrine\ORM\Mapping\Column(type="string", length=30, nullable=false, unique=true, options={"comment": "codTrans as sent to Nexi"})
     *
     * @var string
     */
    protected $nexiCodTrans;

    /**
     * The XPayOrder\Check instances associated to this XPayOrder.
     *
     * @Doctrine\ORM\Mapping\OneToMany(targetEntity="Concrete\Package\CommunityStoreNexi\Entity\XPayOrder\Check", mappedBy="xPayOrder")
     * @Doctrine\ORM\Mapping\OrderBy({"createdOn"="ASC", "id"="ASC"})
     *
     * @var \Doctrine\Common\Collections\Collection
     */
    protected $checks;

    public function __construct(
        string $environment,
        CommunityStore\Order\Order $associatedOrder,
        NexiOrderRequest $request
    ) {
        $this->id = null;
        $this->createdOn = new DateTime();
        $this->environment = $environment;
        $this->associatedOrder = $associatedOrder;
        $this->requestJson = json_encode($request, JSON_UNESCAPED_SLASHES);
        $this->nexiCodTrans = $request->getCodTrans();
        $this->checks = new Collections\ArrayCollection();
    }

    /**
     * Get the record ID (null if not yet persisted).
     */
    public function getID(): ?int
    {
        return $this->id;
    }

    /**
     * Get the record creation date/time.
     */
    public function getCreatedOn(): DateTime
    {
        return $this->createdOn;
    }

    /**
     * Get the environment (sandbox or production).
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * Get the order associated to this request.
     */
    public function getAssociatedOrder(): CommunityStore\Order\Order
    {
        return $this->associatedOrder;
    }

    /**
     * Get the request.
     */
    public function getRequest(): NexiOrderRequest
    {
        $data = json_decode($this->getRequestJson());

        return new NexiOrderRequest($data);
    }

    /**
     * Get the XPayOrder\Check instances associated to this XPayOrder.
     */
    public function getChecks(): Collections\Collection
    {
        return $this->checks;
    }

    /**
     * Get the request (in JSON format).
     */
    protected function getRequestJson(): string
    {
        return $this->requestJson;
    }
}
