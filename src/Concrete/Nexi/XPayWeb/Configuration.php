<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Nexi\XPayWeb;

use Concrete\Package\CommunityStoreNexi\Nexi\Configuration as BaseConfiguration;
use MLocati\Nexi\XPayWeb\Configuration as ConfigurationInterface;
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
    private $apiKey;

    public function __construct(string $environment, string $baseURL, string $apiKey)
    {
        parent::__construct($environment);
        $this->baseURL = $baseURL;
        $this->apiKey = $apiKey;
    }

    /**
     * {@inheritdoc}
     *
     * @see \MLocati\Nexi\XPayWeb\Configuration::getBaseUrl()
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
     * @see \MLocati\Nexi\XPayWeb\Configuration::getApiKey()
     */
    public function getApiKey(): string
    {
        if ($this->apiKey !== '') {
            return $this->apiKey;
        }
        if ($this->getEnvironment() === self::ENVIRONMENT_SANDBOX) {
            return static::DEFAULT_APIKEY_TEST;
        }

        throw new RuntimeException(t('The Nexi API key for production is not set'));
    }
}
