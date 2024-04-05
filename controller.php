<?php

declare(strict_types=1);

namespace Concrete\Package\CommunityStoreNexi;

use Concrete\Core\Database\EntityManager\Provider\ProviderAggregateInterface;
use Concrete\Core\Database\EntityManager\Provider\StandardPackageProvider;
use Concrete\Core\Package\Package;
use Concrete\Core\Routing\Router;
use Concrete\Package\CommunityStore\Src\CommunityStore;

defined('C5_EXECUTE') or die('Access Denied');

class Controller extends Package implements ProviderAggregateInterface
{
    const PAYMENTMETHOD_HANDLE = 'nexi';

    const PATH_CALLBACK_CUSTOMER = '/ccm/community_store/nexi/callback/customer';

    const PATH_CALLBACK_SERVER2SERVER = '/ccm/community_store/nexi/callback/server2server';

    protected $pkgHandle = 'community_store_nexi';

    protected $pkgVersion = '1.0.0';

    /**
     * {@inheritdoc}
     *
     * @see \Concrete\Core\Package\Package::$appVersionRequired
     */
    protected $appVersionRequired = '8';

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
        $router->get(static::PATH_CALLBACK_CUSTOMER, [Callback\Customer::class, '__invoke']);
        $router->post(static::PATH_CALLBACK_SERVER2SERVER, [Callback\Server::class, '__invoke']);
    }
}
