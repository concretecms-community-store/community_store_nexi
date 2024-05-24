<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Nexi;

abstract class Configuration
{
    const ENVIRONMENT_SANDBOX = 'sandbox';

    const ENVIRONMENT_PRODUCTION = 'production';

    const IMPLEMENTATION_XPAY = 'xpay';
    
    const IMPLEMENTATION_XPAYWEB = 'xpay_web';

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

    protected function __construct(string $environment)
    {
        $this->environment = $environment;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }
}
