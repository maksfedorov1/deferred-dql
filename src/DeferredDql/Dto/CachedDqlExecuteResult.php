<?php declare(strict_types=1);
/**
 * This file is a part of the Project 3.0 verification system.
 *
 * @author Maksim Fedorov
 * @date   18.12.2019 12:20
 */

namespace Bank30\Service\Background\DeferredDql\Dto;

class CachedDqlExecuteResult
{
    /** @var int */
    private $done;

    /** @var int */
    private $skipped;

    /** @var int */
    private $failed;

    public function __construct(int $done, int $skipped, int $failed)
    {
        $this->done    = $done;
        $this->skipped = $skipped;
        $this->failed  = $failed;
    }

    public function getDone(): int
    {
        return $this->done;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }
}
