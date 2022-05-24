<?php declare(strict_types=1);
/**
 * This file is a part of the Project 3.0 verification system.
 *
 * @author Maksim Fedorov
 * @date   17.03.2021 10:43
 */

namespace Bank30\Tests\functional\Service\Background\DeferredDql;

use Bank30\DocumentBundle\Entity\Document;
use Bank30\DocumentBundle\Entity\DocumentConfig;
use Bank30\DocumentBundle\Entity\DocumentConstructor;
use Bank30\DocumentBundle\Entity\DocumentVerification;
use Bank30\DocumentBundle\Entity\Fields\ConstructorFields;
use Bank30\DocumentBundle\Entity\Meta\MetadataDocumentScansBag;
use Bank30\InstanceBundle\Entity\Instance;
use Bank30\JobBundle\Entity\JobParams;
use Bank30\Service\Background\DeferredDql\CachedDeferredDqlExecutor;
use Bank30\Tests\_support\FunctionalTester;
use Bank30\Tests\functional\DependenciesTrait;
use Bank30\UserBundle\Entity\User;
use Bank30\VerificationBundle\Entity\ExternalId;
use Bank30\VerificationBundle\Service\Background\Handler\BackgroundDqlCacheHandler;
use Bank30\VerificationBundle\Service\Cache\LazyRedis;
use Bank30\VerificationBundle\Service\Cache\LazyRedisCache;
use Bank30\VerificationBundle\Service\Document\Dto\NullProcessingFlags;
use Codeception\Example;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query\Parameter;

/**
 * @group deferred-dql
 * @noinspection PhpUnused
 */

class CachedDqlExecuteCest
{
    use DependenciesTrait;

    /** @var Cache */
    protected $cache;
    /** @var User */
    private $user;

    public function _beforeExtend(FunctionalTester $I): void
    {
        /** @var LazyRedisCache|LazyRedis $cache */
        $this->cache = $I->grabService('b3.cache');
        $this->cache->flushAll();
    }

    public function _after(FunctionalTester $I): void
    {
        $this->cache->flushAll();
    }

    /**
     * @dataProvider dataProviderExecute
     *
     * @param FunctionalTester $I
     * @param Example          $data
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function execute(FunctionalTester $I, Example $data): void
    {
        $cacheDqlRangeKey = 'easyadmin_deferred_queries';

        $this->loadEntities($data['entities']);

        /** @var BackgroundDqlCacheHandler $dqlHandler */
        $dqlHandler = $I->grabService('b3.background.dql_cache_handler');
        $dqlHandler->execute(...array_values($data['dql']));

        /** @var CachedDeferredDqlExecutor $queryExecutor */
        $queryExecutor = $I->grabService(CachedDeferredDqlExecutor::class);
        $queryExecutor->executeCached();

        $countInCache = $this->cache->fetch($data['dql']['targetCacheKey']);
        $I->assertEquals($data['expectedCountToCache'], $countInCache, 'Проверим в кеше наше искомое значение');

        $cachedDql = $this->cache->fetch('provision_' . $data['dql']['targetCacheKey']);
        $I->assertFalse($cachedDql, 'Проверим, что DQL в кеше нет');

        $cachedDqlRange   = $this->cache->getRedis()->zRange($cacheDqlRangeKey, 0, -1);
        $I->assertEquals([], $cachedDqlRange, 'DQL удален из рейтинга, потому сет с рейтингом пустой');
    }

    protected function dataProviderExecute(): array
    {
        return [
            // ==============================================
            'Результат найден и закеширован' => [
                'dql'                  => [
                    'dql'            => 'SELECT count(entity) FROM Bank30\\DocumentBundle\\Entity\\Document entity WHERE entity.createdAt >= :midnight',
                    'params'         => @serialize(new ArrayCollection([
                        new Parameter('midnight', date('Y-m-d'), 2),
                    ])),
                    'hints'          => [
                        'doctrine_paginator.distinct' => false,
                        'doctrine.customOutputWalker' => 'Bank30\\VerificationBundle\\Query\\WithoutDiscriminatorWalker',
                    ],
                    'targetCacheKey' => 'easyadmin_old_2019-12-17_SELECT count(d0_.id) AS sclr_0 FROM documents d0_d41d8cd98f00b204e9800998ecf8427e',
                    'ttl'            => 6400,
                ],
                'entities'             => [
                    $this->createDocumentConstructor(),
                    $this->createDocumentConstructor(),
                ],
                'expectedCountToCache' => [[1 => 2]],
            ],

            // ==============================================

            'Именнованный запрос с объектом в качестве параметра'    => [
                'dql'                  => [
                    'dql'            => 'SELECT count(entity) FROM Bank30\\DocumentBundle\\Entity\\DocumentVerification entity  WHERE entity.verifier = :user',
                    'params'         => @serialize(new ArrayCollection([
                        new Parameter('user', $this->createUser(), 2),
                    ])),
                    'hints'          => [
                        'doctrine_paginator.distinct' => false,
                        'doctrine.customOutputWalker' => 'Bank30\\VerificationBundle\\Query\\WithoutDiscriminatorWalker',
                    ],
                    'targetCacheKey' => 'maks_123',
                    'ttl'            => 6400,
                ],
                'entities'             => [
                    $this->createDocumentVerification(),
                    $this->createDocumentVerification(),
                ],
                'expectedCountToCache' => [[1 => 2]],
            ],

            // ==============================================
            'Результат: 0, закеширован'                              => [
                'dql'                  => [
                    'dql'            => 'SELECT count(entity) FROM Bank30\\DocumentBundle\\Entity\\Document entity WHERE entity.createdAt >= :midnight',
                    'params'         => @serialize(new ArrayCollection([
                        new Parameter('midnight', date('Y-m-d'), 2),
                    ])),
                    'hints'          => [
                        'doctrine_paginator.distinct' => false,
                        'doctrine.customOutputWalker' => 'Bank30\\VerificationBundle\\Query\\WithoutDiscriminatorWalker',
                    ],
                    'targetCacheKey' => 'easyadmin_old_2019-12-17_SELECT count(d0_.id) AS sclr_0 FROM documents d0_d41d8cd98f00b204e9800998ecf8427e',
                    'ttl'            => 6400,
                ],
                'entities'             => [],
                'expectedCountToCache' => [[1 => 0]],
            ],

            // ==============================================
            'Результат: null (из-за любого исключения), закеширован' => [
                'dql'                  => [
                    'dql'            => 'SELECT pg_sleep(100)',
                    'params'         => @serialize(new ArrayCollection([])),
                    'hints'          => [],
                    'targetCacheKey' => 'any_key',
                    'ttl'            => 6400,
                ],
                'entities'             => [],
                'expectedCountToCache' => null,
            ],
        ];
    }

    private function loadEntities($entities): void
    {
        foreach ($entities as $entity) {
            if (method_exists($entity, 'getDocument')) {
                $this->em->persist($entity->getDocument());
            }

            if (method_exists($entity, 'getVerifier')) {
                $this->em->persist($entity->getVerifier());
            }

            $this->em->persist($entity);
        }

        $this->em->flush();
    }

    private function createDocumentConstructor(): Document
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new Instance('a', 'b');
        }

        $doc = DocumentConstructor::create(
            new ExternalId(1, '1', $instance),
            1,
            new JobParams(2, 0),
            new MetadataDocumentScansBag(new ConstructorFields([], new DocumentConfig('first', '123', [], []))),
            new NullProcessingFlags()
        );
        $doc->clearHash();

        return $doc;
    }

    private function createDocumentVerification(): DocumentVerification
    {
        $doc = $this->createDocumentConstructor();

        $documentVerification = new DocumentVerification(
            $doc,
            $this->createUser(),
            false,
            20,
            new ConstructorFields([], new DocumentConfig('first', '123', [], [])),
            [],
            DocumentVerification::STATUS_UNDEFINED
        );

        return $documentVerification;
    }

    private function createUser(): User
    {
        static $user = null;
        if ($user === null) {
            $user = new User(555);
        }

        return $user;
    }
}
