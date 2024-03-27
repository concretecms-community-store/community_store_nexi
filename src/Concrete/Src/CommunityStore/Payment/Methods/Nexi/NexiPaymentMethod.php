<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi\Src\CommunityStore\Payment\Methods\Nexi;

use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Url\Resolver\Manager\ResolverManagerInterface;
use Concrete\Package\CommunityStore\Src\CommunityStore;
use Concrete\Package\CommunityStoreNexi;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Throwable;
use Concrete\Package\CommunityStoreNexi\Nexi;
use Concrete\Package\CommunityStoreNexi\Entity;
use Concrete\Package\CommunityStoreNexi\Nexi\Configuration;
use MLocati\Nexi\Dictionary\Currency;
use MLocati\Nexi\Client;
use MLocati\Nexi\Entity\PaymentMethod;
use MLocati\Nexi\Exception as NexiException;
use stdClass;
use MLocati\Nexi\Dictionary\TestCard;
use MLocati\Nexi\Entity as NexiEntity;
use Concrete\Package\CommunityStoreNexi\Nexi\LanguageService;

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
        $defaultBaseURLs = [];
        $baseURLs = [];
        $defaultApiKeys = [];
        $apiKeys = [];
        foreach (array_keys($environments) as $env) {
            $defaultBaseURLs[$env] = $env === Configuration::ENVIRONMENT_SANDBOX ? Configuration::DEFAULT_BASEURL_TEST : Configuration::DEFAULT_BASEURL_PRODUCTION;
            $baseURLs[$env] = (string) $config->get("community_store_nexi::options.environments.{$env}.baseURL");
            $defaultApiKeys[$env] = $env === Configuration::ENVIRONMENT_SANDBOX ? Configuration::DEFAULT_APIKEY_TEST : '';
            $apiKeys[$env] = (string) $config->get("community_store_nexi::options.environments.{$env}.apiKey");
        }
        $this->set('environment', $environment);
        $this->set('environments', $environments);
        $this->set('defaultBaseURLs', $defaultBaseURLs);
        $this->set('baseURLs', $baseURLs);
        $this->set('defaultApiKeys', $defaultApiKeys);
        $this->set('apiKeys', $apiKeys);
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
            'environment' => null,
        ];
        $currency = $args['currency'] ?? $config->get('community_store.currency');
        $supportedCurrencies = (new Currency())->getAvailableIDs();
        if (!in_array($currency, $supportedCurrencies, true)) {
            $e->add($currency ? t('The currency %s is not supported by Nexi', $currency) : t('The currency must be configured in order to use Nexi'));
        }
        $environment = $args['nexiEnvironment'];
        if (!is_string($environment) || !isset($environments[$environment])) {
            $e->add(t('Please specify which environment should be used for Nexi', 'nexiEnvironment'));
        }
        foreach ($environments as $env => $envName) {
            if ($env !== $environment) {
                continue;
            }
            $args += [
                "nexiApiKey_{$env}" => null,
                "nexiBaseURL_{$env}" => null,
            ];
            $defaultApiKey = $env === Configuration::ENVIRONMENT_SANDBOX ? Configuration::DEFAULT_APIKEY_TEST : '';
            $apiKey = is_string($args["nexiApiKey_{$env}"]) ? trim($args["nexiApiKey_{$env}"]) : '';
            if ($apiKey === '' && $defaultApiKey === '') {
                $e->add(t('Please specify the API Key for the %s environment of Nexi', $envName), "nexiApiKey_{$env}");
            }
            $defaultBaseURL = $env === Configuration::ENVIRONMENT_SANDBOX ? Configuration::DEFAULT_APIKEY_TEST : '';
            $baseURL = is_string($args["nexiBaseURL_{$env}"]) ? trim($args["nexiBaseURL_{$env}"]) : '';
            if ($baseURL === '') {
                if ($defaultBaseURL === '') {
                    $e->add(t('Please specify the base URL for the %s environment of Nexi', $envName), 'nexiBaseURL_' . $env);
                }
            } elseif (!filter_var($baseURL, FILTER_VALIDATE_URL)) {
                $e->add(t('The base URL for the %s environment of Nexi is wrong', $envName), 'bccPaywayServicesURL_' . $env);
            }
            if (!$e->has()) {
                $client = new Client(
                    new Configuration($environment, $baseURL, $apiKey),
                    $app->make(Nexi\HttpClient::class)
                );
                try {
                    $client->listSupportedPaymentMethods();
                } catch (NexiException $x) {
                    if ($x instanceof NexiException\ErrorResponse && $x->getCode() === 401) {
                        $e->add(t('It seems like the Nexi API Key is wrong'));
                    } else {
                        $e->add(t('It seems like the Nexi API Key and/or the Nexi base URL are wrong'));
                    }
                }
            }
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
        $config->set('community_store_nexi::options.environment', $environment);
        foreach (['sandbox', 'production'] as $env) {
            $baseURL = is_string($data["nexiBaseURL_{$env}"] ?? null) ? trim($data["nexiBaseURL_{$env}"]) : '';
            $apiKey = is_string($data["nexiApiKey_{$env}"] ?? null) ? trim($data["nexiApiKey_{$env}"]) : '';
            $config->set("community_store_nexi::options.environments.{$env}.baseURL", $baseURL);
            $config->set("community_store_nexi::options.environments.{$env}.apiKey", $apiKey);
            if ($isEnabled && $env === $environment) {
                $client = new Client(
                    $app->make(Configuration\Factory::class)->createConfiguration(),
                    $app->make(Nexi\HttpClient::class)
                );
                $paymentMethods = json_encode($client->listSupportedPaymentMethods()->getPaymentMethods());
                $config->set('community_store_nexi::options.paymentMethods', $paymentMethods);
                $config->save('community_store_nexi::options.paymentMethods', $paymentMethods); 
            }
            $config->save("community_store_nexi::options.environments.{$env}.baseURL", $baseURL);
            $config->save("community_store_nexi::options.environments.{$env}.apiKey", $apiKey);
        }
        $config->save('community_store_nexi::options.environment', $environment);
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
        $paymentMethods = [];
        $json = $config->get('community_store_nexi::options.paymentMethods');
        if ($json) {
            $data = json_decode($json);
            if (is_array($data)) {
                foreach ($data as $item) {
                    if ($item instanceof stdClass) {
                        $paymentMethod = new PaymentMethod($item);
                        if ($paymentMethod->getImageLink()) {
                            $paymentMethods[] = $paymentMethod;
                        }
                    }
                }
            }
        }
        $this->set('paymentMethods', $paymentMethods);
        $this->set('testCard', new TestCard());
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method::isExternalActionGET()
     */
    public function isExternalActionGET()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method::getAction()
     */
    public function getAction()
    {
        $app = Application::getFacadeApplication();
        $session = $app->make('session');
        $orderID = (int) $session->get('orderID');
        $order = CommunityStore\Order\Order::getByID($orderID);
        if (!$order) {
            throw new UserMessageException(t('There is currently no order.'));
        }
        $em = $app->make(EntityManagerInterface::class);
        $config = $app->make(Repository::class);
        $request = $this->getCreateHostedOrderRequest($order);
        $entity = new Entity\HostedOrder(
            (string) $config->get('community_store_nexi::options.environment'),
            $order,
            $request
        );
        $em->persist($entity);
        $em->flush();
        try {
            $client = new Client(
                $app->make(Configuration\Factory::class)->createConfiguration(),
                $app->make(Nexi\HttpClient::class)
            );
            $response = $client->createOrderForHostedPayment($request);
            $entity->setResponse($response);
            $session->set('storeNexiHostedOrderID', $entity->getID());
        } catch (Throwable $x) {
            $entity->setRequestError((string) $x);
        } finally {
            $em->flush();
        }

        return $response->getHostedPage();
    }

    private function getCreateHostedOrderRequest(CommunityStore\Order\Order $order): NexiEntity\CreateOrderForHostedPayment\Request
    {
        $app = Application::getFacadeApplication();
        $config = $app->make(Repository::class);
        $urlResolver = $app->make(ResolverManagerInterface::class);
        $siteName = tc('SiteName', $app->make('site')->getSite()->getSiteName());
        $customer = new CommunityStore\Customer\Customer();
        $orderIDWithYear = ((string) $order->getOrderID()) . '-' . date('y');
        $nexiLanguage = $app->make(LanguageService::class)->getNexiCodeByCurrentLocale();
        $currency = $config->get('community_store.currency');
        $currencyService = new Currency();
        if (!in_array($currency, $currencyService->getAvailableIDs(), true)) {
            throw new RuntimeException($currency ? t('The currency %s is not supported by Nexi', $currency) : t('The currency must be configured in order to use Nexi'));
        }
        $nexiAmount = $currencyService->formatDecimals($order->getTotal(), $currency);

        $result = new NexiEntity\CreateOrderForHostedPayment\Request();
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
            ->setResultUrl((string) $urlResolver->resolve([CommunityStoreNexi\Controller::PATH_CALLBACK_CUSTOMER]))
            ->setCancelUrl((string) $urlResolver->resolve(['/checkout']))
            ->setNotificationUrl((string) $urlResolver->resolve([CommunityStoreNexi\Controller::PATH_CALLBACK_SERVER2SERVER]))
        ;

        return $result;
    }
}
