<?php declare(strict_types=1);
/**
 * This file is a part of the Project 3.0 verification system.
 *
 * @author Maksim Fedorov
 * @date   19.12.2019 16:22
 */

namespace Bank30\Service\Background\DeferredDql;

use Bank30\Service\Background\DeferredDql\Dto;
use Bank30\Service\Background\DeferredDql\Enum\DqlExecuteStatus;
use Psr\Log\LoggerInterface;

class CachedDeferredDqlExecutor
{
    /** @var DeferredDqlExecutor */
    private $deferredDqlExecutor;

    /** @var CachedDeferredDqlProvider */
    private $cachedDqlProvider;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        DeferredDqlExecutor $deferredDqlExecutor,
        CachedDeferredDqlProvider $cachedDqlProvider,
        LoggerInterface $logger,
        int $defaultTimeout
    ) {
        $this->deferredDqlExecutor = $deferredDqlExecutor;
        $this->cachedDqlProvider   = $cachedDqlProvider;
        $this->logger              = $logger;
    }

    public function executeCached(): Dto\CachedDqlExecuteResult
    {
        $done = $failed = $skipped = 0;
        foreach ($this->cachedDqlProvider->findAll() as $cachedDeferredDql) {
            $result      = $this->deferredDqlExecutor->executeDeferredDql(
                $cachedDeferredDql->getDeferredDql(),
                $this->getTimeout($cachedDeferredDql),
                $cachedDeferredDql->getScore(),
                true
            );

            $this->cachedDqlProvider->deleteCachedWithDowngrade($cachedDeferredDql);

            try {
                $this->calculateResultStatus($result, $done, $skipped, $failed);
            } catch (\LogicException $e) {
                $this->logger->warning($e->getMessage());

                continue;
            }
        }

        return new Dto\CachedDqlExecuteResult($done, $skipped, $failed);
    }

    private function calculateResultStatus(Dto\DeferredDqlExecuteResultRow $resultRow, int &$done, int &$skipped, int &$failed): void
    {
        switch ($resultRow->getExecuteStatus()->getStatus()) {
            case DqlExecuteStatus::STATUS_DONE:
                ++$done;
                break;
            case DqlExecuteStatus::STATUS_SKIPPED:
                ++$skipped;
                break;
            case DqlExecuteStatus::STATUS_FAILED:
                ++$failed;
                break;
            default:
                throw new \LogicException(sprintf('Unsupported status with DqlExecuteStatus enum: %s', $resultRow->getExecuteStatus()->getStatus()));
        }
    }

    private function getTimeout(Dto\CachedDeferredDql $cachedDql): int
    {
        return max($cachedDql->getScore() * $this->defaultTimeout, $this->defaultTimeout);
    }
}
