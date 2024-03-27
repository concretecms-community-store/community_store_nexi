<?php
declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Nexi;

use MLocati\Nexi\Configuration as ConfigurationInterface;
use RuntimeException;

class Configuration implements ConfigurationInterface
{
    const ENVIRONMENT_SANDBOX = 'sandbox';

    const ENVIRONMENT_PRODUCTION = 'production';

    /**
     * @var string
     */
    private $environment;

    /**
     * @var string
     */
    private $baseURL;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var bool
     */
    private $allowUnsafeHttps;

    public function __construct(string $environment, string $baseURL, string $apiKey, bool $allowUnsafeHttps = false)
    {
        $this->environment = $environment;
        $this->baseURL = $baseURL;
        $this->apiKey = $apiKey;
        $this->allowUnsafeHttps = $allowUnsafeHttps;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * {@inheritdoc}
     *
     * @see \MLocati\Nexi\Configuration::getBaseUrl()
     */
    public function getBaseUrl(): string
    {
        if ($this->baseURL !== '') {
            return $this->baseURL;
        }

        return $this->environment === self::ENVIRONMENT_SANDBOX ? static::DEFAULT_BASEURL_TEST : static::DEFAULT_BASEURL_PRODUCTION;
    }

    /**
     * {@inheritdoc}
     *
     * @see \MLocati\Nexi\Configuration::getApiKey()
     */
    public function getApiKey(): string
    {
        if ($this->apiKey !== '') {
            return $this->apiKey;
        }
        if ($this->environment === self::ENVIRONMENT_SANDBOX) {
            return static::DEFAULT_APIKEY_TEST;
        }

        throw new RuntimeException(t('The Nexi API key for production is not set'));
    }

    /**
     * {@inheritdoc}
     *
     * @see \MLocati\Nexi\Configuration::allowUnsafeHttps()
     */
    public function allowUnsafeHttps(): bool
    {
        return $this->allowUnsafeHttps;
    }
}
