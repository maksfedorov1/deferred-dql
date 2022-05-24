<?php declare(strict_types=1);
/**
 * This file is a part of the Project 3.0 verification system.
 *
 * @author Maksim Fedorov
 * @date   16.12.2019 19:37
 */

namespace Bank30\tests\unit\Service\Background\DeferredDql;

use Bank30\Service\Background\DeferredDql\CachedDeferredDqlProvider;
use Bank30\Service\Background\DeferredDql\Dto;
use Bank30\VerificationBundle\Service\Cache\LazyRedis;
use Codeception\Test\Unit;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query\Parameter;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * @group deferred-dql
 */
class CachedDqlProviderTest extends Unit
{
    /** @var MockObject|LazyRedis */
    private $lazyRedisMock;

    /** @var  MockObject|Cache */
    private $doctrineCacheMock;

    /** @var  MockObject|CachedDeferredDqlProvider */
    private $cachedQueryProvider;

    public function _before(): void
    {
        $this->lazyRedisMock     = $this
            ->getMockBuilder(LazyRedis::class)
            ->setMethods(['zIncrBy', 'zRevRange'])
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $this->doctrineCacheMock = $this
            ->getMockBuilder(Cache::class)
            ->setMethods(['save', 'fetch', 'contains', 'delete', 'getStats', 'getRedis'])
            ->getMock()
        ;
        $this->doctrineCacheMock->method('getRedis')->willReturn($this->lazyRedisMock);

        $this->cachedQueryProvider = new CachedDeferredDqlProvider(
            $this->doctrineCacheMock,
            $this->createMock(LoggerInterface::class)
        );

    }

    public function testCacheWithRange(): void
    {
        $setKey      = 'easyadmin_deferred_queries';
        $cacheKey    = '__CACHE_KEY__';
        $dqlCacheKey = 'provision___CACHE_KEY__';
        $deferredDql = Dto\DeferredDql::create(
            '__SELECT__',
            new ArrayCollection([new Parameter('__NAME__', '__VALUE__')]),
            ['hint1' => '__VALUE_1__'],
            $cacheKey,
            200
        );

        $this->lazyRedisMock
            ->expects($this->once())
            ->method('zIncrBy')
            ->with($setKey, 1, $cacheKey)
        ;
        $this->doctrineCacheMock
            ->expects($this->once())
            ->method('save')
            ->with($dqlCacheKey, $deferredDql, 1.1 * 86400)
        ;

        $this->cachedQueryProvider->cacheWithRange($deferredDql);
    }

    /**
     * @dataProvider dataProviderFindAll
     *
     * @param array $rangeData
     * @param array $fetchArgs
     * @param array $loadedObjects
     * @param int   $fetchInvokeCount
     * @param int   $expectedCached
     */
    public function testFindAll(array $rangeData, array $fetchArgs, array $loadedObjects, int $fetchInvokeCount, int $expectedCached): void
    {
        $setKey = 'easyadmin_deferred_queries';
        $this->lazyRedisMock
            ->expects($this->once())
            ->method('zRevRange')
            ->with($setKey, 0, -1, true)
            ->willReturn($rangeData)
        ;
        $this->doctrineCacheMock
            ->expects($this->exactly($fetchInvokeCount))
            ->method('fetch')
            ->withConsecutive(...$fetchArgs)
            ->willReturnOnConsecutiveCalls(...$loadedObjects)
        ;
        $result = [];
        foreach ($this->cachedQueryProvider->findAll() as $key => $resultCachedDql) {
            /** @var Dto\DeferredDql $expectedQuery */
            $expectedQuery = $loadedObjects[$key];
            $expectedScore = $rangeData[$resultCachedDql->getDeferredDql()->getTargetCacheKey()];

            $this->assertEquals($expectedQuery->getCacheTTL(), $resultCachedDql->getDeferredDql()->getCacheTTL());
            $this->assertEquals($expectedQuery->getDql(), $resultCachedDql->getDeferredDql()->getDql());
            $this->assertEquals($expectedQuery->getTargetCacheKey(), $resultCachedDql->getDeferredDql()->getTargetCacheKey());
            $this->assertEquals($expectedQuery->getParameters(), $resultCachedDql->getDeferredDql()->getParameters());
            $this->assertEquals($expectedScore, $resultCachedDql->getScore());

            $result[] = $resultCachedDql;
        }

        $this->assertCount($expectedCached, $result);
    }

    public function dataProviderFindAll(): array
    {
        return [
            'Loaded: 2 correct'                    => [
                'rangeData'        => [
                    '__CACHE_KEY__'   => 3,
                    '__CACHE_KEY_2__' => 14,
                    '__CACHE_KEY_3__' => 33,
                ],
                [
                    ['provision___CACHE_KEY__'],
                    ['provision___CACHE_KEY_2__'],
                    ['provision___CACHE_KEY_3__'],
                ],
                'loadedObjects'    => [
                    Dto\DeferredDql::create(
                        '__SELECT__',
                        new ArrayCollection([new Parameter('__NAME__', '__VALUE__')]),
                        ['hint' => '__VALUE__'],
                        '__CACHE_KEY__',
                        200
                    ),
                    Dto\DeferredDql::create(
                        '__SELECT__',
                        new ArrayCollection([new Parameter('__NAME__', '__VALUE__')]),
                        ['hint' => '__VALUE__'],
                        '__CACHE_KEY_2__',
                        200
                    ),
                    Dto\DeferredDql::create(
                        '__SELECT__',
                        new ArrayCollection([
                            new Parameter('bool_name', true),
                            new Parameter('int_name', 33),
                        ]),
                        ['hint' => '__VALUE__'],
                        '__CACHE_KEY_3__',
                        200
                    ),
                ],
                'fetchInvokeCount' => 3,
                'expectedCached'   => 3,
            ],
            'loaded: 1 correct, 1 invalid'         => [
                'rangeData'        => [
                    '__CACHE_KEY__'   => 3,
                    '__CACHE_KEY_2__' => 14,
                ],
                [
                    ['provision___CACHE_KEY__'],
                    ['provision___CACHE_KEY_2__'],
                ],
                'loadedObjects'    => [
                    Dto\DeferredDql::create(
                        '__SELECT__',
                        new ArrayCollection([new Parameter('__NAME__', '__VALUE__')]),
                        ['hint' => '__VALUE__'],
                        '__CACHE_KEY__',
                        200
                    ),
                    new \stdClass(),
                ],
                'fetchInvokeCount' => 2,
                'expectedCached'   => 1,
            ],
            'loaded: 2 invalid'                    => [
                'rangeData'        => [
                    '__CACHE_KEY__'   => 3,
                    '__CACHE_KEY_2__' => 14,
                ],
                [
                    ['provision___CACHE_KEY__'],
                    ['provision___CACHE_KEY_2__'],
                ],
                'loadedObjects'    => [
                    new \stdClass(),
                    new \stdClass(),
                ],
                'fetchInvokeCount' => 2,
                'expectedCached'   => 0,
            ],
            'loaded: 2 scalars'                    => [
                'rangeData'        => [
                    '__CACHE_KEY__'   => 3,
                    '__CACHE_KEY_2__' => 14,
                ],
                [
                    ['provision___CACHE_KEY__'],
                    ['provision___CACHE_KEY_2__'],
                ],
                'loadedObjects'    => [
                    [],
                    1111,
                ],
                'fetchInvokeCount' => 2,
                'expectedCached'   => 0,
            ],
            'Loaded: 2 correct, 1 with null score' => [
                'rangeData'        => [
                    '__CACHE_KEY__'   => 3,
                    '__CACHE_KEY_2__' => 0,
                ],
                [
                    ['provision___CACHE_KEY__'],
                    ['provision___CACHE_KEY_2__'],
                ],
                'loadedObjects'    => [
                    Dto\DeferredDql::create(
                        '__SELECT__',
                        new ArrayCollection([new Parameter('__NAME__', '__VALUE__')]),
                        ['hint' => '__VALUE__'],
                        '__CACHE_KEY__',
                        200
                    ),
                    Dto\DeferredDql::create(
                        '__SELECT__',
                        new ArrayCollection([new Parameter('__NAME__', '__VALUE__')]),
                        ['hint' => '__VALUE__'],
                        '__CACHE_KEY_2__',
                        200
                    ),
                ],
                'fetchInvokeCount' => 1,
                'expectedCached'   => 1,
            ],
        ];
    }
}
