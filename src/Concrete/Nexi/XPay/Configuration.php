<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Nexi\XPay;

use Concrete\Package\CommunityStoreNexi\Nexi\Configuration as BaseConfiguration;
use MLocati\Nexi\XPay\Configuration as ConfigurationInterface;
use RuntimeException;

class Configuration extends BaseConfiguration implements ConfigurationInterface
{
    /**
     * @var string
     */
    private $baseURL;

    /**
     * @var string
     */
    private $alias;

    /**
     * @var string
     */
    private $macKey;

    public function __construct(string $environment, string $baseURL, string $alias, string $macKey)
    {
        parent::__construct($environment);
        $this->baseURL = $baseURL;
        $this->alias = $alias;
        $this->macKey = $macKey;
    }

    /**
     * {@inheritdoc}
     *
     * @see \MLocati\Nexi\XPay\Configuration::getBaseUrl()
     */
    public function getBaseUrl(): string
    {
        if ($this->baseURL !== '') {
            return $this->baseURL;
        }

        return $this->getEnvironment() === self::ENVIRONMENT_SANDBOX ? static::DEFAULT_BASEURL_TEST : static::DEFAULT_BASEURL_PRODUCTION;
    }

    /**
     * {@inheritdoc}
     *
     * @see \MLocati\Nexi\XPay\Configuration::getAlias()
     */
    public function getAlias(): string
    {
        if ($this->alias === '') {
            throw new RuntimeException(t('The Nexi merchant Alias for environment %s is not set', $this->getEnvironment()));
        }

        return $this->alias;
    }

    /**
     * {@inheritdoc}
     *
     * @see \MLocati\Nexi\XPay\Configuration::getMacKey()
     */
    public function getMacKey(): string
    {
        if ($this->macKey === '') {
            throw new RuntimeException(t('The Nexi MAC Key for environment %s is not set', $this->getEnvironment()));
        }

        return $this->macKey;
    }
}
