<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi;

use Concrete\Core\Database\EntityManager\Provider\ProviderAggregateInterface;
use Concrete\Core\Database\EntityManager\Provider\StandardPackageProvider;
use Concrete\Core\Package\Package;
use Concrete\Core\Routing\Router;
use Concrete\Package\CommunityStore\Src\CommunityStore;
use Concrete\Package\CommunityStoreNexi\Src\CommunityStore\Payment\Methods\Nexi\LogProvider;

defined('C5_EXECUTE') or die('Access Denied');

class Controller extends Package implements ProviderAggregateInterface
{
    const PAYMENTMETHOD_HANDLE = 'nexi';

    const PATH_XPAYCALLBACK_CUSTOMER_CANCEL = '/ccm/community_store/nexi/xpay/callback/customer/cancel';
    
    const PATH_XPAYCALLBACK_CUSTOMER_REDIRECT = '/ccm/community_store/nexi/xpay/callback/customer/redirect';

    const PATH_XPAYCALLBACK_SERVER2SERVER = '/ccm/community_store/nexi/xpay/callback/server2server';
    
    const PATH_XPAYWEBCALLBACK_CUSTOMER = '/ccm/community_store/nexi/xpayweb/callback/customer';

    const PATH_XPAYWEBCALLBACK_SERVER2SERVER = '/ccm/community_store/nexi/xpayweb/callback/server2server';

    protected $pkgHandle = 'community_store_nexi';

    protected $pkgVersion = '2.0.1';

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Core\Package\Package::$appVersionRequired
     */
    protected $appVersionRequired = '8.5';

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Core\Package\Package::$packageDependencies
     */
    protected $packageDependencies = ['community_store' => '2.5'];

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Core\Package\Package::$pkgAutoloaderRegistries
     */
    protected $pkgAutoloaderRegistries = [];

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Core\Package\Package::$phpVersionRequired
     */
    protected $phpVersionRequired = '7.2';

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Core\Package\Package::getPackageName()
     */
    public function getPackageName()
    {
        return t('Nexi Payment Method');
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Core\Package\Package::getPackageDescription()
     */
    public function getPackageDescription()
    {
        return t('Nexi Payment Method for Community Store');
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Core\Database\EntityManager\Provider\ProviderAggregateInterface::getEntityManagerProvider()
     */
    public function getEntityManagerProvider()
    {
        return new StandardPackageProvider($this->app, $this, [
            'src/Entity' => 'Concrete\\Package\\CommunityStoreNexi\\Entity',
        ]);
    }

    public function on_start()
    {
        $this->registerAutoload();
        $this->app->extend(CommunityStore\Payment\LogProviderFactory::class, function(CommunityStore\Payment\LogProviderFactory $factory) {
            return $factory
                ->registerProvider($this->app->make(LogProvider::class, ['implementation' => 'xpay']))
                ->registerProvider($this->app->make(LogProvider::class, ['implementation' => 'xpay_web']))
            ;
        });
        if (!$this->app->isRunThroughCommandLineInterface()) {
            $this->registerRoutes();
        }
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Core\Package\Package::install()
     */
    public function install()
    {
        $this->registerAutoload();
        $pkg = parent::install();
        CommunityStore\Payment\Method::add(self::PAYMENTMETHOD_HANDLE, 'Nexi', $pkg);
    }

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Core\Package\Package::uninstall()
     */
    public function uninstall()
    {
        $pm = CommunityStore\Payment\Method::getByHandle(self::PAYMENTMETHOD_HANDLE);
        if ($pm) {
            $pm->delete();
        }
        parent::uninstall();
    }

    private function registerAutoload(): void
    {
        $file = $this->getPackagePath() . '/vendor/autoload.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }

    private function registerRoutes(): void
    {
        $router = $this->app->make(Router::class);
        $router->get(static::PATH_XPAYCALLBACK_CUSTOMER_CANCEL, [Nexi\XPay\Callback::class, 'customerCancel']);
        $router->get(static::PATH_XPAYCALLBACK_CUSTOMER_REDIRECT, [Nexi\XPay\Callback::class, 'customerRedirect']);
        $router->post(static::PATH_XPAYCALLBACK_SERVER2SERVER, [Nexi\XPay\Callback::class, 'server2Server']);
        $router->get(static::PATH_XPAYWEBCALLBACK_CUSTOMER, [Nexi\XPayWeb\Callback\Customer::class, '__invoke']);
        $router->post(static::PATH_XPAYWEBCALLBACK_SERVER2SERVER, [Nexi\XPayWeb\Callback\Server::class, '__invoke']);
    }
}
