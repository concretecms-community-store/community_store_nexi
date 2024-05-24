<?php

namespace Concrete\Package\CommunityStoreNexi\Entity\XPayOrder;

use Concrete\Package\CommunityStoreNexi\Entity\XPayOrder;
use DateTime;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @Doctrine\ORM\Mapping\Entity
 * @Doctrine\ORM\Mapping\Table(
 *     name="CommunityStoreNexiXPayOrderChecks",
 *     options={"comment": "Verify requests for Nexi XPay payment method"}
 * )
 */
class Check
{
    const PLACE_CUSTOMER_CANCEL = 'cancel';
    
    const PLACE_CUSTOMER_REDIRECT = 'redirect';
    
    const PLACE_SERVER = 'server';

    /**
     * The record ID (null if not yet persisted).
     *
     * @Doctrine\ORM\Mapping\Id
     * @Doctrine\ORM\Mapping\Column(type="integer", options={"unsigned":true, "comment": "Init ID"})
     * @Doctrine\ORM\Mapping\GeneratedValue(strategy="AUTO")
     */
    protected ?int $id;

    /**
     * The record creation date/time.
     *
     * @Doctrine\ORM\Mapping\Column(type="datetime", nullable=false, options={"comment": "Record creation date/time"})
     */
    protected DateTime $createdOn;

    /**
     * The place where the verification occurred (server for server2server communications, cancel for customer canceled requests, redirect for processed customer requests).
     *
     * @Doctrine\ORM\Mapping\Column(type="string", length="20", nullable=false, options={"comment": "Place where the verification occurred (server for server2server communications, cancel for customer canceled requests, redirect for processed customer requests)"})
     */
    protected string $place;

    /**
     * The received data (in JSON format).
     *
     * @Doctrine\ORM\Mapping\Column(type="text", nullable=false, options={"comment": "Received data (in JSON format)"})
     */
    protected string $receivedJson;

    /**
     * The XPayOrder associated to this request.
     *
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="Concrete\Package\CommunityStoreNexi\Entity\XPayOrder", inversedBy="checks")
     * @Doctrine\ORM\Mapping\JoinColumn(name="xPayOrder", referencedColumnName="id", nullable=true, onDelete="CASCADE")
     */
    protected ?XPayOrder $xPayOrder;

    /**
     * The processing error.
     *
     * @Doctrine\ORM\Mapping\Column(type="text", nullable=false, options={"comment": "Processing error"})
     */
    protected string $error;

    /**
     * @param string $place where the verification occurred (server for server2server communications, customer for customer requests)
     */
    public function __construct(string $place)
    {
        $this->id = null;
        $this->createdOn = new DateTime();
        $this->place = $place;
        $this->receivedJson = '';
        $this->xPayOrder = null;
        $this->error = '';
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
     * Get the place where the verification occurred (server for server2server communications, customer for customer requests).
     */
    public function getPlace(): string
    {
        return $this->place;
    }

    /**
     * Get the received data (in JSON format).
     */
    public function getReceivedJson(): string
    {
        return $this->receivedJson;
    }

    /**
     * Set the received data (in JSON format).
     *
     * @return $this
     */
    public function setReceivedJson(string $value): self
    {
        $this->receivedJson = $value;

        return $this;
    }

    /**
     * Get the XPayOrder associated to this request.
     */
    public function getXPayOrder(): ?XPayOrder
    {
        return $this->xPayOrder;
    }

    /**
     * Get the XPayOrder associated to this request.
     */
    public function setXPayOrder(?XPayOrder $value): self
    {
        $this->xPayOrder = $value;

        return $this;
    }

    /**
     * Get the processing error.
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * Set the processing error.
     *
     * @return $this
     */
    public function setError(string $value): self
    {
        $this->error = $value;

        return $this;
    }
}
