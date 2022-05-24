<?php declare(strict_types=1);
/**
 * This file is a part of the Project 3.0 verification system.
 *
 * @author Maksim Fedorov
 * @date   19.12.2019 18:39
 */

namespace Bank30\Tests\unit\Service\Background\DeferredDql;

use Bank30\Tests\_support\UnitTestCaseTrait;
use Bank30\Service\Background\DeferredDql\CachedDeferredDqlExecutor;
use Bank30\Service\Background\DeferredDql\CachedDeferredDqlProvider;
use Bank30\Service\Background\DeferredDql\DeferredDqlExecutor;
use Bank30\Service\Background\DeferredDql\Enum\DqlExecuteStatus;
use Codeception\Test\Unit;
use Bank30\Service\Background\DeferredDql\Dto;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query\Parameter;
use Psr\Log\LoggerInterface;

/**
 * @group deferred-dql
 */
class CachedDeferredDqlExecutorTest extends Unit
{
    use UnitTestCaseTrait;

    /**
     * @var DeferredDqlExecutor|\PHPUnit\Framework\MockObject\MockObject
     */
    private $deferredExecutorMock;
    /**
     * @var CachedDeferredDqlProvider|\PHPUnit\Framework\MockObject\MockObject
     */
    private $cachedDeferredDqlProviderMock;
    /**
     * @var CachedDeferredDqlExecutor
     */
    private $cachedDeferredExecutor;

    public function _before(): void
    {
        $this->deferredExecutorMock          = $this
            ->getMockBuilder(DeferredDqlExecutor::class)
            ->setMethods(['executeDeferredDql'])
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $this->cachedDeferredDqlProviderMock = $this
            ->getMockBuilder(CachedDeferredDqlProvider::class)
            ->setMethods(['findAll', 'deleteCachedWithDowngrade'])
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $this->cachedDeferredExecutor        = new CachedDeferredDqlExecutor(
            $this->deferredExecutorMock,
            $this->cachedDeferredDqlProviderMock,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @dataProvider dataProviderExecuteCached
     *
     * @param array                      $cachedDqlObjs
     * @param array                      $executeDqlsArgs
     * @param array                      $deleteCachedDqlsArgs
     * @param array                      $executeDqlsResults
     * @param Dto\CachedDqlExecuteResult $expectedResult
     */
    public function testExecuteCached(array $cachedDqlObjs, array $executeDqlsArgs, array $deleteCachedDqlsArgs, array $executeDqlsResults, Dto\CachedDqlExecuteResult $expectedResult): void
    {
        $this->cachedDeferredDqlProviderMock
            ->expects($this->once())
            ->method('findAll')
            ->willReturnCallback(static function () use ($cachedDqlObjs) {
                foreach ($cachedDqlObjs as $dqlObj) {
                    yield $dqlObj;
                }
            })
        ;
        $this->cachedDeferredDqlProviderMock
            ->expects($this->exactly(2))
            ->method('deleteCachedWithDowngrade')
            ->withConsecutive(...$deleteCachedDqlsArgs)
        ;
        $this->deferredExecutorMock
            ->expects($this->exactly(2))
            ->method('executeDeferredDql')
            ->withConsecutive(...$executeDqlsArgs)
            ->willReturnOnConsecutiveCalls(...$executeDqlsResults)
        ;

        $result = $this->cachedDeferredExecutor->executeCached();

        $this->assertEquals($expectedResult->getDone(), $result->getDone());
        $this->assertEquals($expectedResult->getFailed(), $result->getFailed());
        $this->assertEquals($expectedResult->getSkipped(), $result->getSkipped());
    }

    public function dataProviderExecuteCached(): array
    {
        /** @var Dto\CachedDeferredDql[] $cachedDeferredDqls */
        $cachedDeferredDqls   = [
            new Dto\CachedDeferredDql(Dto\DeferredDql::create(
                '__SELECT_1__',
                new ArrayCollection([new Parameter('__NAME__', '__VALUE__')]),
                ['hint1' => '__VALUE_1__'],
                '__CACHE_KEY_1__',
                86400
            ), 30),
            new Dto\CachedDeferredDql(Dto\DeferredDql::create(
                '__SELECT_2__',
                new ArrayCollection([new Parameter('__NAME__', '__VALUE__')]),
                ['hint1' => '__VALUE_1__'],
                '__CACHE_KEY_2__',
                86400
            ), 40),
        ];
        $executeDqlsArgs      = [
            [$cachedDeferredDqls[0]->getDeferredDql(), 30 * 5 * 60 * 1000, 30],
            [$cachedDeferredDqls[1]->getDeferredDql(), 40 * 5 * 60 * 1000, 40],
        ];
        $deleteCachedDqlsArgs = [
            [$cachedDeferredDqls[0]],
            [$cachedDeferredDqls[1]],
        ];

        return [
            [
                $cachedDeferredDqls,
                $executeDqlsArgs,
                $deleteCachedDqlsArgs,
                [
                    new Dto\DeferredDqlExecuteResultRow('__SELECT_1__',  DqlExecuteStatus::createDone()),
                    new Dto\DeferredDqlExecuteResultRow('__SELECT_2__',  DqlExecuteStatus::createDone()),
                ],
                new Dto\CachedDqlExecuteResult(2, 0, 0),
            ],
            [
                $cachedDeferredDqls,
                $executeDqlsArgs,
                $deleteCachedDqlsArgs,
                [
                    new Dto\DeferredDqlExecuteResultRow('__SELECT_1__',  DqlExecuteStatus::createDone()),
                    new Dto\DeferredDqlExecuteResultRow('__SELECT_2__',  DqlExecuteStatus::createSkipped()),
                ],
                new Dto\CachedDqlExecuteResult(1, 1, 0),
            ],
            [
                $cachedDeferredDqls,
                $executeDqlsArgs,
                $deleteCachedDqlsArgs,
                [
                    new Dto\DeferredDqlExecuteResultRow('__SELECT_1__',  DqlExecuteStatus::createDone()),
                    new Dto\DeferredDqlExecuteResultRow('__SELECT_2__',  DqlExecuteStatus::createFailed()),
                ],
                new Dto\CachedDqlExecuteResult(1, 0, 1),
            ],
            [
                $cachedDeferredDqls,
                $executeDqlsArgs,
                $deleteCachedDqlsArgs,
                [
                    new Dto\DeferredDqlExecuteResultRow('__SELECT_1__', DqlExecuteStatus::createFailed()),
                    new Dto\DeferredDqlExecuteResultRow('__SELECT_2__', DqlExecuteStatus::createFailed()),
                ],
                new Dto\CachedDqlExecuteResult(0, 0, 2),
            ],
            [
                $cachedDeferredDqls,
                $executeDqlsArgs,
                $deleteCachedDqlsArgs,
                [
                    new Dto\DeferredDqlExecuteResultRow('__SELECT_1__', DqlExecuteStatus::createSkipped()),
                    new Dto\DeferredDqlExecuteResultRow('__SELECT_2__', DqlExecuteStatus::createSkipped()),
                ],
                new Dto\CachedDqlExecuteResult(0, 2, 0),
            ],
        ];

    }

    /**
     * @dataProvider dataProviderCalculateStatus
     *
     * @param string $status
     * @param array  $results
     * @param bool   $exception
     */
    public function testCalculateResultStatus(string $status, array $results, bool $exception): void
    {
        $done           = $failed = $skipped = 0;
        $expectedStatus = $this->createMock(DqlExecuteStatus::class);
        $expectedStatus->method('getStatus')->willReturn($status);
        $deferredDqlExecuteResult = $this->createMock(Dto\DeferredDqlExecuteResultRow::class);
        $deferredDqlExecuteResult
            ->method('getExecuteStatus')
            ->willReturn($expectedStatus)
        ;

        if ($exception) {
            $this->expectException(\LogicException::class);
        }

        $calculateResultStatusMethod = $this->getPrivateMethod(CachedDeferredDqlExecutor::class, 'calculateResultStatus');
        /** @var Dto\CachedDqlExecuteResult $result */
        $calculateResultStatusMethod->invokeArgs($this->cachedDeferredExecutor, [$deferredDqlExecuteResult, &$done, &$failed, &$skipped]);

        $this->assertEquals([$done, $failed, $skipped], $results);
    }

    public function dataProviderCalculateStatus(): array
    {
        return [
            [
                'status'    => 'done',
                'results'   => [1, 0, 0],
                'exception' => false,
            ],
            [
                'status'    => 'skipped',
                'results'   => [0, 1, 0],
                'exception' => false,
            ],
            [
                'status'    => 'failed',
                'results'   => [0, 0, 1],
                'exception' => false,
            ],
            [
                'status'    => '__FAIL__',
                'results'   => [0, 0, 0],
                'exception' => true,
            ],
        ];
    }
}
