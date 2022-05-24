<?php declare(strict_types=1);
/**
 * This file is a part of the Project 3.0 verification system.
 *
 * @author Maksim Fedorov
 * @date   19.12.2019 19:39
 */

namespace Bank30\Tests\unit\Service\Background\DeferredDql;

use Bank30\Tests\_support\UnitTestCaseTrait;
use Bank30\Tests\_support\UopzTrait;
use Bank30\Service\Background\DeferredDql\CachedDeferredDqlExecutor;
use Bank30\Service\Background\DeferredDql\CachedDeferredDqlProvider;
use Bank30\Service\Background\DeferredDql\DeferredDqlExecutor;
use Bank30\Service\Background\DeferredDql\DeferredDqlTransformer;
use Bank30\Service\Background\DeferredDql\Dto;
use Bank30\Service\Background\DeferredDql\Enum\DqlExecuteStatus;
use Codeception\Test\Unit;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Parameter;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * @group deferred-dql
 */
class DeferredDqlExecutorTest extends Unit
{
    use UnitTestCaseTrait, UopzTrait;

    /** @var Connection|MockObject */
    private $connectionMock;

    /** @var CachedDeferredDqlProvider|MockObject */
    private $cachedDeferredDqlProviderMock;

    /** @var CachedDeferredDqlExecutor */
    private $cachedDeferredExecutor;

    /** @var DeferredDqlTransformer|MockObject */
    private $cachedDqlTransformerMock;

    /** @var DeferredDqlExecutor */
    private $deferredExecutor;

    public function _before(): void
    {
        $this->connectionMock                = $this
            ->getMockBuilder(Connection::class)
            ->setMethods(['executeQuery'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass()
        ;
        $this->cachedDeferredDqlProviderMock = $this
            ->getMockBuilder(CachedDeferredDqlProvider::class)
            ->setMethods(['checkCachedExecuteResult', 'cacheExecuteResult'])
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $this->cachedDqlTransformerMock      = $this
            ->getMockBuilder(DeferredDqlTransformer::class)
            ->setMethods(['transform'])
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $this->deferredExecutor              = new DeferredDqlExecutor(
            $this->connectionMock,
            $this->cachedDeferredDqlProviderMock,
            $this->cachedDqlTransformerMock,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @dataProvider dataProviderExecuteDeferredDQl
     *
     * @param Dto\DeferredDql $dql
     * @param                 $checkResult
     * @param int             $expectsExecuteQuery
     * @param                 $expectedResult
     * @param                 $isExecuteException
     * @param                 $forceMode
     */
    public function testExecuteDeferredDql(Dto\DeferredDql $dql, $checkResult, $expectsExecuteQuery, $expectedResult, $isExecuteException, $forceMode): void
    {
        $query = $isExecuteException ? $this->createQuery(null, new \Exception()) : $this->createQuery(50);
        $this->cachedDeferredDqlProviderMock
            ->expects($this->once())
            ->method('checkCachedExecuteResult')
            ->with($dql)
            ->willReturn($checkResult)
        ;
        $this->cachedDqlTransformerMock
            ->expects($expectsExecuteQuery)
            ->method('transform')
            ->with($dql)
            ->willReturn($query)
        ;

        $result = $this->deferredExecutor->executeDeferredDql($dql, 30, 0, $forceMode);

        $this->assertEquals($expectedResult, $result);
    }

    public function dataProviderExecuteDeferredDQl(): array
    {
        return [
            'Нет значения в кеше, успешно выполнено'                               => [
                'dql'                       => Dto\DeferredDql::create(
                    '__SELECT__',
                    new ArrayCollection([new Parameter('__NAME__', '__VALUE__')]),
                    ['hint1' => '__VALUE_1__'],
                    '__CACHE_KEY_1__',
                    86400
                ),
                'checkResult'               => false,
                'expectsExecuteQuery'       => $this->once(),
                'expectedResult'            => new Dto\DeferredDqlExecuteResultRow(
                    '__SELECT__',
                    DqlExecuteStatus::createDone()
                ),
                'executeQueryWithException' => false,
                'forceMode'                 => false,
            ],
            'Есть закешированное значение (не NULL), пропускаем выполнение'        => [
                'dql'                       => Dto\DeferredDql::create(
                    '__SELECT__',
                    new ArrayCollection([new Parameter('__NAME__', '__VALUE__')]),
                    ['hint1' => '__VALUE_1__'],
                    '__CACHE_KEY_1__',
                    86400
                ),
                'checkResult'               => true,
                'expectsExecuteQuery'       => $this->never(),
                'expectedResult'            => new Dto\DeferredDqlExecuteResultRow(
                    '__SELECT__',
                    DqlExecuteStatus::createSkipped()
                ),
                'executeQueryWithException' => false,
                'forceMode'                 => false,
            ],
            'Есть закешированное значение (NULL), force режим, успешно выполнено'  => [
                'dql'                       => Dto\DeferredDql::create(
                    '__SELECT__',
                    new ArrayCollection([new Parameter('__NAME__', '__VALUE__')]),
                    ['hint1' => '__VALUE_1__'],
                    '__CACHE_KEY_1__',
                    86400
                ),
                'checkResult'               => null,
                'expectsExecuteQuery'       => $this->once(),
                'expectedResult'            => new Dto\DeferredDqlExecuteResultRow(
                    '__SELECT__',
                    DqlExecuteStatus::createDone()
                ),
                'executeQueryWithException' => false,
                'forceMode'                 => true,
            ],
            'Есть закешированное значение (NULL), НЕ force, пропускаем выполнение' => [
                'dql'                       => Dto\DeferredDql::create(
                    '__SELECT__',
                    new ArrayCollection([new Parameter('__NAME__', '__VALUE__')]),
                    ['hint1' => '__VALUE_1__'],
                    '__CACHE_KEY_1__',
                    86400
                ),
                'checkResult'               => null,
                'expectsExecuteQuery'       => $this->never(),
                'expectedResult'            => new Dto\DeferredDqlExecuteResultRow(
                    '__SELECT__',
                    DqlExecuteStatus::createSkipped()
                ),
                'executeQueryWithException' => false,
                'forceMode'                 => false,
            ],
            'Не выполнено по разным причинам'                                      => [
                'dql'                       => Dto\DeferredDql::create(
                    '__SELECT__',
                    new ArrayCollection([new Parameter('__NAME__', '__VALUE__')]),
                    ['hint1' => '__VALUE_1__'],
                    '__CACHE_KEY_1__',
                    86400
                ),
                'checkResult'               => false,
                'expectsExecuteQuery'       => $this->once(),
                'expectedResult'            => new Dto\DeferredDqlExecuteResultRow(
                    '__SELECT__',
                    DqlExecuteStatus::createFailed()
                ),
                'executeQueryWithException' => true,
                'forceMode'                 => true,
            ],
        ];
    }

    private function createQuery($result, ?\Throwable $exceptionClass = null): MockObject
    {
        /** @var MockObject $queryMock */
        $queryMock = $this->getEmptyMock(Query::class);

        if ($result) {
            $queryMock->method('execute')->willReturn($result);

            return $queryMock;
        }

        if ($exceptionClass) {
            $queryMock->method('execute')->willThrowException($exceptionClass);
        }

        return $queryMock;
    }
}
