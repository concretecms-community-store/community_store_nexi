<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Nexi\XPay\Configuration;

use Concrete\Core\Config\Repository\Repository;
use Concrete\Package\CommunityStoreNexi\Nexi\XPay\Configuration;

class Factory
{
    /**
     * @var \Concrete\Core\Config\Repository\Repository
     */
    private $config;

    public function __construct(Repository $config)
    {
        $this->config = $config;
    }

    public function createConfiguration(string $environment = ''): Configuration
    {
        if ($environment === '') {
            $environment = (string) $this->config->get('community_store_nexi::options.environment');
        }

        return new Configuration(
            $environment,
            (string) $this->config->get("community_store_nexi::xpay.environments.{$environment}.basURL"),
            (string) $this->config->get("community_store_nexi::xpay.environments.{$environment}.alias"),
            (string) $this->config->get("community_store_nexi::xpay.environments.{$environment}.macKey")
        );
    }
}
