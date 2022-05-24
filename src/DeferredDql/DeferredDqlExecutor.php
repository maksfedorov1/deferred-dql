<?php declare(strict_types=1);
/**
 * This file is a part of the Project 3.0 verification system.
 *
 * @author Maksim Fedorov
 * @date   16.12.2019 19:37
 */

namespace Bank30\Service\Background\DeferredDql;

use Bank30\Service\Background\DeferredDql\Enum\DqlExecuteStatus;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\ORM\Query;
use Psr\Log\LoggerInterface;

class DeferredDqlExecutor
{
    /** @var Connection */
    private $connection;

    /** @var CachedDeferredDqlProvider */
    private $cachedDqlProvider;

    /** @var LoggerInterface */
    private $logger;

    /** @var DeferredDqlTransformer */
    private $transformer;

    public function __construct(
        Connection $connection,
        CachedDeferredDqlProvider $cachedQueryProvider,
        DeferredDqlTransformer $transformer,
        LoggerInterface $logger
    ) {
        $this->cachedDqlProvider = $cachedQueryProvider;
        $this->logger            = $logger;
        $this->connection        = $connection;
        $this->transformer       = $transformer;
    }

    public function executeDeferredDql(Dto\DeferredDql $deferredDql, int $timeoutLimit, int $score = 0, bool $forceMode = false): Dto\DeferredDqlExecuteResultRow
    {
        // Пропускаем, если:
        //  - есть результат
        //  - или не соблюдается условие "сохранен NULL, но принудительный рехжим"
        if ($this->isSkipExecution($deferredDql, $forceMode)) {
            return new Dto\DeferredDqlExecuteResultRow(
                $deferredDql->getDql(),
                DqlExecuteStatus::createSkipped()
            );
        }

        $timeStart = \microtime(true);
        $data      = $this->executeQuery($deferredDql, $timeoutLimit);
        $timeEnd   = \microtime(true);

        $this->cachedDqlProvider->cacheExecuteResult($deferredDql, $data);

        if ($data === null) {
            return new Dto\DeferredDqlExecuteResultRow(
                $deferredDql->getDql(),
                DqlExecuteStatus::createFailed()
            );
        }

        $this->logger->info('The Query is done.', [
            'query'       => $deferredDql->getDql(),
            'params'      => $deferredDql->getStringParameters(),
            'result'      => $data,
            'executeTime' => ($timeEnd - $timeStart) . ' сек',
            'score'       => $score,
        ]);

        return new Dto\DeferredDqlExecuteResultRow(
            $deferredDql->getDql(),
            DqlExecuteStatus::createDone()
        );
    }

    private function isSkipExecution(Dto\DeferredDql $deferredDql, bool $forceMode): bool
    {
        $cachedResult = $this->cachedDqlProvider->checkCachedExecuteResult($deferredDql);

        // Нет результата в кеше, значит не пропусаем и выполняем
        if ($cachedResult === false) {
            return false;
        }

        // Если сохранен NULL, но форс-режим, то не пропускаем выполнение и выполняем
        // форс-режим — это значит мы игнорируем NULL, тк он мог туда записаться без реального выполнения запроса
        if ($cachedResult === null && $forceMode) {
            $this->logger->info('Updating the query result with cached NULL value.');

            return false;
        }

        $this->logger->info('Skipped execution: the query result already exists.');

        return true;
    }

    /**
     * @param Query $query
     *
     * @param int   $timeoutLimit
     *
     * @return mixed
     */
    private function executeQuery(Dto\DeferredDql $deferredDql, int $timeoutLimit)
    {
        $query = $this->transformer->transform($deferredDql);
        $this->connection->executeQuery(sprintf('SET statement_timeout TO %d;', $timeoutLimit));

        $data = null;
        try {
            $data = $query->execute();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed query execute.', [
                'dql'       => $query->getDql(),
                'params'    => var_export($query->getParameters(), true),
                'exception' => $e,
            ]);
        } finally {
            $this->connection->executeQuery('SET statement_timeout TO 0;');
        }

        return $data;
    }
}
