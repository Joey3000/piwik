<?php

use Interop\Container\ContainerInterface;
use Interop\Container\Exception\NotFoundException;
use Piwik\Cache\Eager;
use Piwik\Common;
use Piwik\SettingsServer;

return array(

    'path.root' => PIWIK_USER_PATH,

    'path.tmp' => function (ContainerInterface $c) {
        $root = $c->get('path.root');

        // TODO remove that special case and instead have plugins override 'path.tmp' to add the instance id
        if ($c->has('ini.General.instance_id')) {
            $instanceId = $c->get('ini.General.instance_id');
            $instanceId = $instanceId ? '/' . $instanceId : '';
        } else {
            $instanceId = '';
        }

        return $root . '/tmp' . $instanceId;
    },

    'path.cache' => DI\string('{path.tmp}/cache/tracker/'),

    // cache backend definitions
    'cache.backend.null' => DI\object('Piwik\Cache\Backend\NullCache'),
    'cache.backend.array' => DI\object('Piwik\Cache\Backend\ArrayCache'),
    'cache.backend.file' => DI\object('Piwik\Cache\Backend\File')->constructor(DI\get('path.cache')),
    'cache.backend.apc' => function (ContainerInterface $c) {
        /** @var \Piwik\Application\Kernel\StaticCacheFactory $cacheFactory */
        $cacheFactory = $c->get('Piwik\Application\Kernel\StaticCacheFactory');
        return $cacheFactory->make('apc');
    },
    // TODO: if the Redis cache class took the connection args via constructor, this could just be a definition instead of closure. Then it would be cached in the DI cache.
    'cache.backend.redis' => function (ContainerInterface $c) {
        /** @var \Piwik\Application\Kernel\StaticCacheFactory $cacheFactory */
        $cacheFactory = $c->get('Piwik\Application\Kernel\StaticCacheFactory');
        return $cacheFactory->make('redis');
    },
    'cache.backend.chained' => function (ContainerInterface $c) {
        $chainedBackendsNames = $c->get('ini.ChainedCache.backends');

        $backends = array();
        foreach ($chainedBackendsNames as $name) {
            $backends[] = $c->get('cache.backend.' . $name);
        }
        return new \Piwik\Cache\Backend\Chained($backends);
    },

    'Piwik\Cache\Eager' => function (ContainerInterface $c) {
        $backend = $c->get('Piwik\Cache\Backend');
        $cacheId = $c->get('cache.eager.cache_id');

        if (SettingsServer::isTrackerApiRequest()) {
            $eventToPersist = 'Tracker.end';
            $cacheId .= 'tracker';
        } else {
            $eventToPersist = 'Request.dispatch.end';
            $cacheId .= 'ui';
        }

        $cache = new Eager($backend, $cacheId);
        \Piwik\Piwik::addAction($eventToPersist, function () use ($cache) {
            $cache->persistCacheIfNeeded(43200);
        });

        return $cache;
    },
    'Piwik\Cache\Backend' => function (ContainerInterface $c) {
        try {
            $backend = $c->get('ini.Cache.backend');
        } catch (NotFoundException $ex) {
            $backend = 'chained'; // happens if global.ini.php is not available
        }

        return $c->get('cache.backend.' . $backend);
    },
    'cache.eager.cache_id' => function () {
        return 'eagercache-' . str_replace(array('.', '-'), '', \Piwik\Version::VERSION) . '-';
    },

    'Psr\Log\LoggerInterface' => DI\object('Psr\Log\NullLogger'),

    'Piwik\Translation\Loader\LoaderInterface' => DI\object('Piwik\Translation\Loader\LoaderCache')
        ->constructor(DI\get('Piwik\Translation\Loader\JsonFileLoader')),

    'observers.global' => array(),

    'Piwik\EventDispatcher' => DI\object()->constructorParameter('observers', DI\get('observers.global')),

);
