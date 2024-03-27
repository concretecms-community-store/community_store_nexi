<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Entity;

use Concrete\Package\CommunityStore\Src\CommunityStore;
use DateTime;
use Doctrine\Common\Collections;
use MLocati\Nexi\Entity\CreateOrderForHostedPayment\Response as NexiOrderResponse;
use MLocati\Nexi\Entity\CreateOrderForHostedPayment\Request as NexiOrderRequest;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @Doctrine\ORM\Mapping\Entity
 * @Doctrine\ORM\Mapping\Table(
 *     name="CommunityStoreNexiHostedOrder",
 *     options={"comment": "Hosted orders for Nexi payment method"}
 * )
 */
class HostedOrder
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
     * The order ID as sent to Nexi.
     *
     * @Doctrine\ORM\Mapping\Column(type="string", length=18, nullable=false, unique=true, options={"comment": "Order ID as sent to Nexi"})
     *
     * @var string
     */
    protected $nexiOrderID;

    /**
     * The response, if available (in JSON format).
     *
     * @Doctrine\ORM\Mapping\Column(type="text", nullable=false, options={"comment": "Response (in JSON format)"})
     *
     * @var string
     */
    protected $responseJson;

    /**
     * The error occurred while performing the hosted payment request.
     *
     * @Doctrine\ORM\Mapping\Column(type="text", nullable=false, options={"comment": "Error occurred while performing the hosted payment request"})
     *
     * @var string
     */
    protected $requestError;

    /**
     * The HostedOrder\Check instances associated to this HostedOrder.
     *
     * @Doctrine\ORM\Mapping\OneToMany(targetEntity="Concrete\Package\CommunityStoreNexi\Entity\HostedOrder\Check", mappedBy="hostedOrder")
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
        $this->nexiOrderID = $request->getOrder()->getOrderId();
        $this->responseJson = '';
        $this->requestError = '';
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
     * Get the request (in JSON format).
     */
    protected function getRequestJson(): string
    {
        return $this->requestJson;
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
     * Get the response, if available (in JSON format).
     */
    protected function getResponseJson(): string
    {
        return $this->responseJson;
    }

    /**
     * Get the response, if available.
     */
    public function getResponse(): ?NexiOrderResponse
    {
        $json = $this->getResponseJson();
        if ($json === '') {
            return null;
        }
        $data = json_decode($json);

        return new NexiOrderResponse($data);
    }

    /**
     * Set the response, if available (in JSON format).
     *
     * @return $this
     */
    protected function setResponseJson(string $value): self
    {
        $this->responseJson = $value;

        return $this;
    }

    /**
     * Set the response, if available.
     *
     * @return $this
     */
    public function setResponse(?NexiOrderResponse $value): self
    {
        return $this->setResponseJson($value === null ? '' : json_encode($value, JSON_UNESCAPED_SLASHES));
    }

    public function getRequestError(): string
    {
        return $this->requestError;
    }

    /**
     * @param string $value
     */
    public function setRequestError(string $value): self
    {
        $this->requestError = $value;

        return $this;
    }

    /**
     * Get the HostedOrder\Check instances associated to this HostedOrder.
     */
    public function getChecks(): Collections\Collection
    {
        return $this->checks;
    }
}
