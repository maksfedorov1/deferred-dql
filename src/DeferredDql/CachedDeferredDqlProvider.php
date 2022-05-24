<?php declare(strict_types=1);
/**
 * This file is a part of the Project 3.0 verification system.
 *
 * @author Maksim Fedorov
 * @date   16.12.2019 19:37
 */

namespace Bank30\Service\Background\DeferredDql;

use Bank30\Service\Background\DeferredDql\Dto\CachedDeferredDql;
use Doctrine\Common\Cache\Cache;
use Psr\Log\LoggerInterface;
use Bank30\Helper\TypeHelper;

class CachedDeferredDqlProvider
{
    /**
     * Название sorted list, в котором храним ключи от отложенных DQL, поле score используем для приоритезации
     * Само значение ключа равно ключу, по которому будет храниться результат выполнения, тк уникальное
     */
    private const CACHED_DEFERRED_DQL_RANGE_KEY = 'easyadmin_deferred_queries';

    /**
     * Префикс служит для того, чтобы хранить отложенный DQL со всеми данными
     * Подставляется к ключу, по которому будет храниться результат выполнения DQL
     */
    private const PROVISION_CACHE_KEY_PREFIX = 'provision_';

    /**
     * Значение, на которое увеличиваем score при повторном кешировании DQL в sorted list отложенных DQL
     */
    private const INCREMENT_VALUE = 1;

    /**
     * Минимальное значение ранжированного
     */
    private const MIN_AVAILABLE_RANGE_VALUE = 1;

    /**
     * Значение, сколько храним отложенные запросы
     * Должно быть больше периода между выполнениями запросов крона
     * (крон выполняется на текущий момент раз в сутки: 86400)
     */
    private const DQL_CACHE_LIFETIME = 1.1 * 86400;

    /** var Cache */
    private $cache;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(Cache $cache, LoggerInterface $logger)
    {
        $this->cache  = $cache;
        $this->logger = $logger;
    }

    /**
     * @return Dto\CachedDeferredDql[]|\Generator
     */
    public function findAll(): \Generator
    {
        /** @var \Redis $redis */
        $redis = $this->cache->getRedis();

        $cachedKeys = $redis->zRevRange(self::CACHED_DEFERRED_DQL_RANGE_KEY, 0, -1, true);
        foreach ($cachedKeys as $cachedKey => $score) {
            if ($score <= 0) {
                continue;
            }

            $deferredDql = $this->loadCachedData($cachedKey);
            if ($deferredDql === null) {
                continue;
            }

            yield new CachedDeferredDql($deferredDql, (int)$score);
        }
    }

    /**
     * @param Dto\DeferredDql $deferredDql
     */
    public function cacheWithRange(Dto\DeferredDql $deferredDql): void
    {
        /** @var \Redis $redis */
        $redis = $this->cache->getRedis();
        $redis->zIncrBy(self::CACHED_DEFERRED_DQL_RANGE_KEY, self::INCREMENT_VALUE, $deferredDql->getTargetCacheKey());

        $this->cacheDeferredDql($deferredDql);
    }

    /**
     * @param Dto\CachedDeferredDql $cachedDeferredDql
     */
    public function deleteCachedWithDowngrade(Dto\CachedDeferredDql $cachedDeferredDql): void
    {
        /** @var \Redis $redis */
        $redis       = $this->cache->getRedis();
        $deferredDql = $cachedDeferredDql->getDeferredDql();

        $cachedCount = $redis->zIncrBy(
            self::CACHED_DEFERRED_DQL_RANGE_KEY,
            -1 * $cachedDeferredDql->getScore(), // делаем декермент со значением, которое было на момент прочтения
            $deferredDql->getTargetCacheKey()
        );

        if ($cachedCount < self::MIN_AVAILABLE_RANGE_VALUE) {
            $this->deleteCachedDql($deferredDql);
            $this->deleteCachedDqlScore($deferredDql);
        } else {
            // Продлим хранение для оставшихся записей
            $this->cacheDeferredDql($deferredDql);
        }
    }

    /**
     * @param Dto\DeferredDql $cachedDql
     *
     * @return bool
     */
    public function checkCachedExecuteResult(Dto\DeferredDql $cachedDql): ?bool
    {
        if ($this->cache->fetch($cachedDql->getTargetCacheKey()) === null) {
            return null;
        }

        return $this->cache->fetch($cachedDql->getTargetCacheKey()) !== false;
    }

    /**
     * @param Dto\DeferredDql $cachedDql
     * @param                 $data
     */
    public function cacheExecuteResult(Dto\DeferredDql $cachedDql, $data): void
    {
        $this->cache->save($cachedDql->getTargetCacheKey(), $data, $cachedDql->getCacheTTL());
    }

    /**
     * @param string $cachedKey
     *
     * @return Dto\DeferredDql|null
     * @internal
     */
    private function loadCachedData(string $cachedKey): ?Dto\DeferredDql
    {
        $cachedDql = $this->cache->fetch(self::PROVISION_CACHE_KEY_PREFIX . $cachedKey);
        if ($cachedDql === false) {
            $this->logger->warning('Called not existent key', ['key' => $cachedKey]);

            return null;
        }

        if (!$this->checkCachedDqlType($cachedDql)) {
            return null;
        }

        return $cachedDql;
    }

    private function checkCachedDqlType($cachedDql): bool
    {
        if (!\is_object($cachedDql) || !($cachedDql instanceof Dto\DeferredDql)) {
            $this->logger->warning('Received undefined dql type', [
                    'type' => TypeHelper::typeToString($cachedDql),
                ]
            );

            return false;
        }

        return true;
    }

    private function cacheDeferredDql(Dto\DeferredDql $deferredDql): void
    {
        $this->cache->save(
            self::PROVISION_CACHE_KEY_PREFIX . $deferredDql->getTargetCacheKey(),
            $deferredDql,
            self::DQL_CACHE_LIFETIME
        );
    }

    private function deleteCachedDql(Dto\DeferredDql $deferredDql): void
    {
        $this->cache->delete(self::PROVISION_CACHE_KEY_PREFIX . $deferredDql->getTargetCacheKey());
    }

    private function deleteCachedDqlScore(Dto\DeferredDql $deferredDql): void
    {
        /** @var \Redis $redis */
        $redis = $this->cache->getRedis();

        // Удалим  DQL в sorted list
        $redis->zRem(self::CACHED_DEFERRED_DQL_RANGE_KEY, $deferredDql->getTargetCacheKey());
    }
}
