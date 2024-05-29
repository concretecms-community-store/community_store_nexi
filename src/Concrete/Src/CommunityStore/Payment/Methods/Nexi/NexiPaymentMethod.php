<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Src\CommunityStore\Payment\Methods\Nexi;

use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Url\Resolver\Manager\ResolverManagerInterface;
use Concrete\Package\CommunityStore\Src\CommunityStore;
use Concrete\Package\CommunityStoreNexi;
use Concrete\Package\CommunityStoreNexi\Entity;
use Concrete\Package\CommunityStoreNexi\Nexi\Configuration;
use Concrete\Package\CommunityStoreNexi\Nexi\XPay\Configuration as XPayConfiguration;
use Concrete\Package\CommunityStoreNexi\Nexi\XPay\HttpClient as XPayHttpClient;
use Concrete\Package\CommunityStoreNexi\Nexi\XPay\LanguageService as XPayLanguageService;
use Concrete\Package\CommunityStoreNexi\Nexi\XPayWeb\Configuration as XPayWebConfiguration;
use Concrete\Package\CommunityStoreNexi\Nexi\XPayWeb\HttpClient as XPayWebHttpClient;
use Concrete\Package\CommunityStoreNexi\Nexi\XPayWeb\LanguageService as XPayWebLanguageService;
use Doctrine\ORM\EntityManagerInterface;
use MLocati\Nexi\XPay\Client as XPayClient;
use MLocati\Nexi\XPay\Dictionary as XPayDictionary;
use MLocati\Nexi\XPay\Entity as XPayEntity;
use MLocati\Nexi\XPay\Exception as XPayException;
use MLocati\Nexi\XPayWeb\Client as XPayWebClient;
use MLocati\Nexi\XPayWeb\Dictionary as XPayWebDictionary;
use MLocati\Nexi\XPayWeb\Entity as XPayWebEntity;
use MLocati\Nexi\XPayWeb\Exception as XPayWebException;
use RuntimeException;
use stdClass;
use Throwable;

defined('C5_EXECUTE') or die('Access Denied');

class NexiPaymentMethod extends CommunityStore\Payment\Method
{
    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method::getName()
     */
    public function getName()
    {
        return t('Checkout with Nexi');
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method::getPaymentMinimum()
     */
    public function getPaymentMinimum()
    {
        return 0.01;
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method::isExternal()
     */
    public function isExternal()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method::isExternalActionGET()
     */
    public function isExternalActionGET()
    {
        $config = app(Repository::class);
        switch ($config->get('community_store_nexi::options.implementation')) {
            case Configuration::IMPLEMENTATION_XPAY:
                return false;
            case Configuration::IMPLEMENTATION_XPAYWEB:
                return true;
            default:
                return false;
        }
    }

    public function dashboardForm()
    {
        $app = Application::getFacadeApplication();
        $config = $app->make(Repository::class);
        $this->set('form', $app->make('helper/form'));
        $environments = [
            Configuration::ENVIRONMENT_SANDBOX => t('Test'),
            Configuration::ENVIRONMENT_PRODUCTION => t('Production'),
        ];
        $environment = (string) $config->get('community_store_nexi::options.environment');
        if (!isset($environments[$environment])) {
            $environment = Configuration::ENVIRONMENT_SANDBOX;
        }
        $this->set('environment', $environment);
        $this->set('environments', $environments);

        $implementations = [
            Configuration::IMPLEMENTATION_XPAY => t('X-Pay'),
            Configuration::IMPLEMENTATION_XPAYWEB => t('X-Pay Web'),
        ];
        $implementation = (string) $config->get('community_store_nexi::options.implementation');
        if (!isset($implementations[$implementation])) {
            $implementation = '';
        }
        $this->set('implementation', $implementation);
        $this->set('implementations', $implementations);

        $xPayDefaultBaseURLs = [];
        $xPayBaseURLs = [];
        $xPayAliases = [];
        $xPayMacKeys = [];

        $xPayWebDefaultBaseURLs = [];
        $xPayWebBaseURLs = [];
        $xPayWebDefaultApiKeys = [];
        $xPayWebApiKeys = [];
        foreach (array_keys($environments) as $env) {
            $xPayDefaultBaseURLs[$env] = $env === Configuration::ENVIRONMENT_SANDBOX ? XPayConfiguration::DEFAULT_BASEURL_TEST : XPayConfiguration::DEFAULT_BASEURL_PRODUCTION;
            $xPayBaseURLs[$env] = (string) $config->get("community_store_nexi::xpay.environments.{$env}.baseURL");
            $xPayAliases[$env] = (string) $config->get("community_store_nexi::xpay.environments.{$env}.alias");
            $xPayMacKeys[$env] = (string) $config->get("community_store_nexi::xpay.environments.{$env}.macKey");

            $xPayWebDefaultBaseURLs[$env] = $env === Configuration::ENVIRONMENT_SANDBOX ? XPayWebConfiguration::DEFAULT_BASEURL_TEST : XPayWebConfiguration::DEFAULT_BASEURL_PRODUCTION;
            $xPayWebBaseURLs[$env] = (string) $config->get("community_store_nexi::xpay_web.environments.{$env}.baseURL");
            $xPayWebDefaultApiKeys[$env] = $env === Configuration::ENVIRONMENT_SANDBOX ? XPayWebConfiguration::DEFAULT_APIKEY_TEST : '';
            $xPayWebApiKeys[$env] = (string) $config->get("community_store_nexi::xpay_web.environments.{$env}.apiKey");
        }

        $this->set('xPayDefaultBaseURLs', $xPayDefaultBaseURLs);
        $this->set('xPayBaseURLs', $xPayBaseURLs);
        $this->set('xPayAliases', $xPayAliases);
        $this->set('xPayMacKeys', $xPayMacKeys);

        $this->set('xPayWebDefaultBaseURLs', $xPayWebDefaultBaseURLs);
        $this->set('xPayWebBaseURLs', $xPayWebBaseURLs);
        $this->set('xPayWebDefaultApiKeys', $xPayWebDefaultApiKeys);
        $this->set('xPayWebApiKeys', $xPayWebApiKeys);
    }

    /**
     * @param array|mixed $args
     * @param \Concrete\Core\Error\ErrorList\ErrorList $e
     *
     * @return \Concrete\Core\Error\ErrorList\ErrorList
     */
    public function validate($args, $e)
    {
        $myIndex = is_array($args['paymentMethodHandle']) ? array_search(CommunityStoreNexi\Controller::PAYMENTMETHOD_HANDLE, $args['paymentMethodHandle'], true) : false;
        $isEnabled = $myIndex !== false && is_array($args['paymentMethodEnabled']) && !empty($args['paymentMethodEnabled'][$myIndex]);
        if (!$isEnabled) {
            return $e;
        }
        $app = Application::getFacadeApplication();
        $config = $app->make(Repository::class);
        $environments = [
            Configuration::ENVIRONMENT_SANDBOX => t('Test'),
            Configuration::ENVIRONMENT_PRODUCTION => t('Production'),
        ];
        $args = (is_array($args) ? $args : []) + [
            'paymentMethodHandle' => null,
            'paymentMethodEnabled' => null,
            'nexiEnvironment' => '',
            'nexiImplementation' => '',
        ];
        $environment = $args['nexiEnvironment'];
        if (!is_string($environment) || !isset($environments[$environment])) {
            $e->add(t('Please specify which environment should be used for Nexi', 'nexiEnvironment'));
        }
        switch ($args['nexiImplementation']) {
            case Configuration::IMPLEMENTATION_XPAY:
                $currency = $args['currency'] ?? $config->get('community_store.currency');
                $supportedCurrencies = (new XPayDictionary\Currency())->getAvailableIDs();
                if (!in_array($currency, $supportedCurrencies, true)) {
                    $e->add($currency ? t('The currency %s is not supported by Nexi', $currency) : t('The currency must be configured in order to use Nexi'));
                }
                foreach ($environments as $env => $envName) {
                    if ($env !== $environment) {
                        continue;
                    }
                    $args += [
                        "nexiXPayBaseURL_{$env}" => null,
                        "nexiXPayAlias_{$env}" => null,
                        "nexiXPayMacKey_{$env}" => null,
                    ];
                    $defaultBaseURL = $env === Configuration::ENVIRONMENT_SANDBOX ? XPayConfiguration::DEFAULT_BASEURL_TEST : XPayConfiguration::DEFAULT_BASEURL_PRODUCTION;
                    $baseURL = is_string($args["nexiXPayBaseURL_{$env}"]) ? trim($args["nexiXPayBaseURL_{$env}"]) : '';
                    if ($baseURL === '') {
                        if ($defaultBaseURL === '') {
                            $e->add(t('Please specify the base URL for the %s environment of Nexi', $envName), 'nexiXPayBaseURL_' . $env);
                        }
                    } elseif (!filter_var($baseURL, FILTER_VALIDATE_URL)) {
                        $e->add(t('The base URL for the %s environment of Nexi is wrong', $envName), 'nexiXPayBaseURL_' . $env);
                    }
                    $alias = is_string($args["nexiXPayAlias_{$env}"]) ? trim($args["nexiXPayAlias_{$env}"]) : '';
                    if ($alias === '') {
                        $e->add(t('Please specify the merchant Alias for the %s environment of Nexi', $envName), 'nexiXPayAlias_' . $env);
                    }
                    $macKey = is_string($args["nexiXPayMacKey_{$env}"]) ? trim($args["nexiXPayMacKey_{$env}"]) : '';
                    if ($macKey === '') {
                        $e->add(t('Please specify the MAC Key for the %s environment of Nexi', $envName), 'nexiXPayAlias_' . $env);
                    }
                }
                if (!$e->has()) {
                    $package = $this->app->make(PackageService::class)->getByHandle('community_store_nexi');
                    $request = new XPayEntity\PaymentMethods\Request();
                    $request
                        ->setPlatform('ConcreteCMS')
                        ->setPlatformVers($config->get('concrete.version'))
                        ->setPluginVers($package->getPackageVersion())
                        ->setTimeStamp(time() * 1000)
                    ;
                    $client = new XPayClient(
                        new XPayConfiguration($environment, $baseURL, $alias, $macKey),
                        $app->make(XPayHttpClient::class)
                    );
                    try {
                        $client->listSupportedPaymentMethods($request);
                    } catch (XPayException $x) {
                        if ($x instanceof XPayException\ErrorResponse && in_array($x->getCode(), [50, 3], true)) {
                            $e->add(t('It seems like the Nexi Alias or MAC Key are wrong'));
                        } else {
                            $e->add($x->getMessage());
                        }
                    }
                }
                break;
            case Configuration::IMPLEMENTATION_XPAYWEB:
                $currency = $args['currency'] ?? $config->get('community_store.currency');
                $supportedCurrencies = (new XPayWebDictionary\Currency())->getAvailableIDs();
                if (!in_array($currency, $supportedCurrencies, true)) {
                    $e->add($currency ? t('The currency %s is not supported by Nexi', $currency) : t('The currency must be configured in order to use Nexi'));
                }
                foreach ($environments as $env => $envName) {
                    if ($env !== $environment) {
                        continue;
                    }
                    $args += [
                        "nexiXPayWebBaseURL_{$env}" => null,
                        "nexiXPayWebApiKey_{$env}" => null,
                    ];
                    $defaultBaseURL = $env === Configuration::ENVIRONMENT_SANDBOX ? XPayWebConfiguration::DEFAULT_BASEURL_TEST : XPayWebConfiguration::DEFAULT_BASEURL_PRODUCTION;
                    $baseURL = is_string($args["nexiXPayWebBaseURL_{$env}"]) ? trim($args["nexiXPayWebBaseURL_{$env}"]) : '';
                    if ($baseURL === '') {
                        if ($defaultBaseURL === '') {
                            $e->add(t('Please specify the base URL for the %s environment of Nexi', $envName), 'nexiXPayWebBaseURL_' . $env);
                        }
                    } elseif (!filter_var($baseURL, FILTER_VALIDATE_URL)) {
                        $e->add(t('The base URL for the %s environment of Nexi is wrong', $envName), 'nexiXPayWebBaseURL_' . $env);
                    }
                    $defaultApiKey = $env === Configuration::ENVIRONMENT_SANDBOX ? XPayWebConfiguration::DEFAULT_APIKEY_TEST : '';
                    $apiKey = is_string($args["nexiXPayWebApiKey_{$env}"]) ? trim($args["nexiXPayWebApiKey_{$env}"]) : '';
                    if ($apiKey === '' && $defaultApiKey === '') {
                        $e->add(t('Please specify the API Key for the %s environment of Nexi', $envName), "nexiXPayWebApiKey_{$env}");
                    }
                    if (!$e->has()) {
                        $client = new XPayWebClient(
                            new XPayWebConfiguration($environment, $baseURL, $apiKey),
                            $app->make(XPayWebHttpClient::class)
                        );
                        try {
                            $client->listSupportedPaymentMethods();
                        } catch (XPayWebException $x) {
                            if ($x instanceof XPayWebException\ErrorResponse && $x->getCode() === 401) {
                                $e->add(t('It seems like the Nexi API Key is wrong'));
                            } else {
                                $e->add(t('It seems like the Nexi API Key and/or the Nexi base URL are wrong'));
                            }
                        }
                    }
                }
                break;
            default:
                $e->add(t('Please select the Nexi implementation to be used'));
                break;
        }

        return $e;
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method::save()
     */
    public function save(array $data = [])
    {
        $myIndex = is_array($data['paymentMethodHandle'] ?? null) ? array_search(CommunityStoreNexi\Controller::PAYMENTMETHOD_HANDLE, $data['paymentMethodHandle'], true) : false;
        $isEnabled = $myIndex !== false && is_array($data['paymentMethodEnabled'] ?? null) && !empty($data['paymentMethodEnabled'][$myIndex]);
        $app = Application::getFacadeApplication();
        $config = $app->make(Repository::class);
        $environment = is_string($data['nexiEnvironment'] ?? null) ? trim($data['nexiEnvironment']) : '';
        $implementation = is_string($data['nexiImplementation'] ?? null) ? trim($data['nexiImplementation']) : '';
        $config->set('community_store_nexi::options.environment', $environment);
        $config->set('community_store_nexi::options.implementation', $implementation);
        foreach (['sandbox', 'production'] as $env) {
            switch ($implementation) {
                case Configuration::IMPLEMENTATION_XPAY:
                    $baseURL = is_string($data["nexiXPayBaseURL_{$env}"] ?? null) ? trim($data["nexiXPayBaseURL_{$env}"]) : '';
                    $alias = is_string($data["nexiXPayAlias_{$env}"] ?? null) ? trim($data["nexiXPayAlias_{$env}"]) : '';
                    $macKey = is_string($data["nexiXPayMacKey_{$env}"] ?? null) ? trim($data["nexiXPayMacKey_{$env}"]) : '';
                    $config->set("community_store_nexi::xpay.environments.{$env}.baseURL", $baseURL);
                    $config->set("community_store_nexi::xpay.environments.{$env}.alias", $alias);
                    $config->set("community_store_nexi::xpay.environments.{$env}.macKey", $macKey);
                    if ($isEnabled && $env === $environment) {
                        $package = $this->app->make(PackageService::class)->getByHandle('community_store_nexi');
                        $request = new XPayEntity\PaymentMethods\Request();
                            $request
                            ->setPlatform('ConcreteCMS')
                            ->setPlatformVers($config->get('concrete.version'))
                            ->setPluginVers($package->getPackageVersion())
                            ->setTimeStamp(time() * 1000)
                        ;
                        $client = new XPayClient(
                            $app->make(XPayConfiguration\Factory::class)->createConfiguration(),
                            $app->make(XPayHttpClient::class)
                        );
                        $paymentMethods = json_encode($client->listSupportedPaymentMethods($request)->getAvailableMethods());
                        $config->set('community_store_nexi::xpay.paymentMethods', $paymentMethods);
                        $config->save('community_store_nexi::xpay.paymentMethods', $paymentMethods);
                    }
                    $config->save("community_store_nexi::xpay.environments.{$env}.baseURL", $baseURL);
                    $config->save("community_store_nexi::xpay.environments.{$env}.alias", $alias);
                    $config->save("community_store_nexi::xpay.environments.{$env}.macKey", $macKey);
                    break;
                case Configuration::IMPLEMENTATION_XPAYWEB:
                    $baseURL = is_string($data["nexiXPayWebBaseURL_{$env}"] ?? null) ? trim($data["nexiXPayWebBaseURL_{$env}"]) : '';
                    $apiKey = is_string($data["nexiXPayWebApiKey_{$env}"] ?? null) ? trim($data["nexiXPayWebApiKey_{$env}"]) : '';
                    $config->set("community_store_nexi::xpay_web.environments.{$env}.baseURL", $baseURL);
                    $config->set("community_store_nexi::xpay_web.environments.{$env}.apiKey", $apiKey);
                    if ($isEnabled && $env === $environment) {
                        $client = new XPayWebClient(
                            $app->make(XPayWebConfiguration\Factory::class)->createConfiguration(),
                            $app->make(XPayWebHttpClient::class)
                        );
                        $paymentMethods = json_encode($client->listSupportedPaymentMethods()->getPaymentMethods());
                        $config->set('community_store_nexi::xpay_web.paymentMethods', $paymentMethods);
                        $config->save('community_store_nexi::xpay_web.paymentMethods', $paymentMethods);
                    }
                    $config->save("community_store_nexi::xpay_web.environments.{$env}.baseURL", $baseURL);
                    $config->save("community_store_nexi::xpay_web.environments.{$env}.apiKey", $apiKey);
                    break;
                default:
                    $implementation = '';
                    break;
            }
        }
        $config->save('community_store_nexi::options.environment', $environment);
        $config->save('community_store_nexi::options.implementation', $implementation);
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method::checkoutForm()
     */
    public function checkoutForm()
    {
        $app = Application::getFacadeApplication();
        $config = $app->make(Repository::class);
        $this->set('environment', (string) $config->get('community_store_nexi::options.environment'));
        $implementation = $config->get('community_store_nexi::options.implementation');
        switch ($implementation) {
            case Configuration::IMPLEMENTATION_XPAY:
                $paymentMethods = [];
                $json = $config->get('community_store_nexi::xpay.paymentMethods');
                if ($json) {
                    $data = json_decode($json);
                    if (is_array($data)) {
                        foreach ($data as $item) {
                            if ($item instanceof stdClass) {
                                $paymentMethod = new XPayEntity\PaymentMethods\Response\Method($item);
                                if ($paymentMethod->getImage()) {
                                    $paymentMethods[] = $paymentMethod;
                                }
                            }
                        }
                    }
                }
                $this->set('paymentMethods', $paymentMethods);
                $this->set('testCard', new XPayDictionary\TestCard());
                break;
            case Configuration::IMPLEMENTATION_XPAYWEB:
                $paymentMethods = [];
                $json = $config->get('community_store_nexi::xpay_web.paymentMethods');
                if ($json) {
                    $data = json_decode($json);
                    if (is_array($data)) {
                        foreach ($data as $item) {
                            if ($item instanceof stdClass) {
                                $paymentMethod = new XPayWebEntity\PaymentMethod($item);
                                if ($paymentMethod->getImageLink()) {
                                    $paymentMethods[] = $paymentMethod;
                                }
                            }
                        }
                    }
                }
                $this->set('paymentMethods', $paymentMethods);
                $this->set('testCard', new XPayWebDictionary\TestCard());
                break;
            default:
                $implementation = '';
                break;
        }
        $this->set('implementation', $implementation);
    }

    public function redirectForm()
    {
        $app = Application::getFacadeApplication();
        $config = $app->make(Repository::class);
        switch ($config->get('community_store_nexi::options.implementation')) {
            case Configuration::IMPLEMENTATION_XPAY:
                $session = $app->make('session');
                $orderID = (int) $session->get('orderID');
                $order = CommunityStore\Order\Order::getByID($orderID);
                if (!$order) {
                    throw new UserMessageException(t('There is currently no order.'));
                }
                $em = $app->make(EntityManagerInterface::class);
                $config = $app->make(Repository::class);
                $configuration = $app->make(XPayConfiguration\Factory::class)->createConfiguration();
                $request = $this->createXPayOrderRequest($order);
                $signedRequest = $request->sign($configuration);
                $entity = new Entity\XPayOrder(
                    (string) $config->get('community_store_nexi::options.environment'),
                    $order,
                    $request
                );
                $em->persist($entity);
                $em->flush();
                $session->set('storeNexiXPayOrderID', $entity->getID());
                $this->set('request', $signedRequest);
                break;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method::getAction()
     */
    public function getAction()
    {
        $app = Application::getFacadeApplication();
        $config = $app->make(Repository::class);
        switch ($config->get('community_store_nexi::options.implementation')) {
            case Configuration::IMPLEMENTATION_XPAY:
                $client = new XPayClient(
                    $app->make(XPayConfiguration\Factory::class)->createConfiguration(),
                    $app->make(XPayHttpClient::class)
                );
                return $client->getSimplePaySubmitUrl();
            case Configuration::IMPLEMENTATION_XPAYWEB:
                $session = $app->make('session');
                $orderID = (int) $session->get('orderID');
                $order = CommunityStore\Order\Order::getByID($orderID);
                if (!$order) {
                    throw new UserMessageException(t('There is currently no order.'));
                }
                $em = $app->make(EntityManagerInterface::class);
                $config = $app->make(Repository::class);
                $request = $this->createXPayWebOrderRequest($order);
                $entity = new Entity\XPayWebOrder(
                    (string) $config->get('community_store_nexi::options.environment'),
                    $order,
                    $request
                );
                $em->persist($entity);
                $em->flush();
                try {
                    $client = new XPayWebClient(
                        $app->make(XPayWebConfiguration\Factory::class)->createConfiguration(),
                        $app->make(XPayWebHttpClient::class)
                        );
                    $response = $client->createOrderForHostedPayment($request);
                    $entity->setResponse($response);
                    $session->set('storeNexiXPayWebOrderID', $entity->getID());
                } catch (Throwable $x) {
                    $entity->setRequestError((string) $x);
                } finally {
                    $em->flush();
                }

                return $response->getHostedPage();
            default:
                throw new UserMessageException(t('This payment method is not configured.'));
        }
    }

    private function createXPayOrderRequest(CommunityStore\Order\Order $order): XPayEntity\SimplePay\Request
    {
        $app = Application::getFacadeApplication();
        $urlResolver = $app->make(ResolverManagerInterface::class);
        $package = $this->app->make(PackageService::class)->getByHandle('community_store_nexi');
        $config = $app->make(Repository::class);
        $siteName = tc('SiteName', $app->make('site')->getSite()->getSiteName());
        $description = t('Order %1$s on %2$s', $order->getOrderID(), $siteName);
        $description = preg_replace('/\s+/', ' ', $description);
        if (class_exists('Transliterator')) {
            try {
                $transliterator = \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;', \Transliterator::FORWARD);
                $normalized = $transliterator->transliterate($description);
                if ($normalized) {
                    $description = $normalized;
                }
            } catch (Throwable $_) {
            }
        }
        $description = preg_replace('/[^A-Z a-z0-9\/\-:().,+]+/', '', $description);
        $description = trim(mb_substr($description, 0, 127));
        $currency = $config->get('community_store.currency');
        $currencyService = new XPayDictionary\Currency();
        if (!in_array($currency, $currencyService->getAvailableIDs(), true)) {
            throw new RuntimeException($currency ? t('The currency %s is not supported by Nexi', $currency) : t('The currency must be configured in order to use Nexi'));
        }
        $orderIDWithYear = ((string) $order->getOrderID()) . '/' . date('Y');
        $request = new XPayEntity\SimplePay\Request();
        $request
            ->setImportoAsDecimal($order->getTotal())
            ->setDivisa($currency)
            ->setCodTrans($orderIDWithYear)
            ->setUrl((string) $urlResolver->resolve([CommunityStoreNexi\Controller::PATH_XPAYCALLBACK_CUSTOMER_REDIRECT]))
            ->setUrl_back((string) $urlResolver->resolve([CommunityStoreNexi\Controller::PATH_XPAYCALLBACK_CUSTOMER_CANCEL]))
            ->setUrlpost((string) $urlResolver->resolve([CommunityStoreNexi\Controller::PATH_XPAYCALLBACK_SERVER2SERVER]))
            ->setMail((string) $order->getAttribute('email'))
            ->setLanguageId($app->make(XPayLanguageService::class)->getNexiCodeByCurrentLocale())
            ->setDescrizione($description)
            ->setNote1('ConcreteCMS')
            ->setNote2($config->get('concrete.version'))
            ->setNote3($package->getPackageVersion())
            ->setNome(trim(mb_substr((string) $order->getAttribute('billing_first_name'), 0, 150)))
            ->setCognome(trim(mb_substr((string) $order->getAttribute('billing_last_name'), 0, 150)))
        ;
        $request->checkRequiredFields();

        return $request;
    }

    private function createXPayWebOrderRequest(CommunityStore\Order\Order $order): XPayWebEntity\CreateOrderForHostedPayment\Request
    {
        $app = Application::getFacadeApplication();
        $config = $app->make(Repository::class);
        $urlResolver = $app->make(ResolverManagerInterface::class);
        $siteName = tc('SiteName', $app->make('site')->getSite()->getSiteName());
        $customer = new CommunityStore\Customer\Customer();
        $orderIDWithYear = ((string) $order->getOrderID()) . '-' . date('y');
        $nexiLanguage = $app->make(XPayWebLanguageService::class)->getNexiCodeByCurrentLocale();
        $currency = $config->get('community_store.currency');
        $currencyService = new XPayWebDictionary\Currency();
        if (!in_array($currency, $currencyService->getAvailableIDs(), true)) {
            throw new RuntimeException($currency ? t('The currency %s is not supported by Nexi', $currency) : t('The currency must be configured in order to use Nexi'));
        }
        $nexiAmount = $currencyService->formatDecimals($order->getTotal(), $currency);

        $result = new XPayWebEntity\CreateOrderForHostedPayment\Request();
        $nexiOrder = $result->getOrCreateOrder();
        $nexiOrder
            ->setOrderId($orderIDWithYear)
            ->setAmount((string) $nexiAmount)
            ->setCurrency($currency)
            ->setDescription(t('Order %1$s on %2$s', $orderIDWithYear, $siteName))
        ;
        $nexiCustomer = $nexiOrder->getOrCreateCustomerInfo();
        $nexiCustomer
            ->setCardHolderEmail($customer->getEmail())
        ;
        $nexiPaymentSession = $result->getOrCreatePaymentSession();
        $nexiPaymentSession
            ->setActionType('PAY')
            ->setAmount((string) $nexiAmount)
            ->setLanguage($nexiLanguage)
            ->setResultUrl((string) $urlResolver->resolve([CommunityStoreNexi\Controller::PATH_XPAYWEBCALLBACK_CUSTOMER]))
            ->setCancelUrl((string) $urlResolver->resolve(['/checkout']))
            ->setNotificationUrl((string) $urlResolver->resolve([CommunityStoreNexi\Controller::PATH_XPAYWEBCALLBACK_SERVER2SERVER]))
        ;

        return $result;
    }
}
