<?php declare(strict_types=1);
/**
 * This file is a part of the Project 3.0 verification system.
 *
 * @author Maksim Fedorov
 * @date   19.12.2019 16:07
 */

namespace Bank30\Service\Background\DeferredDql\Enum;

class DqlExecuteStatus
{
    public const STATUS_DONE    = 'done';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED  = 'failed';

    /** @var string */
    private $status;

    private function __construct(string $status)
    {
        $this->status = $status;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function __toString(): string
    {
        return $this->status;
    }

    public static function createDone(): self
    {
        return new self(self::STATUS_DONE);
    }

    public static function createSkipped(): self
    {

        return new self(self::STATUS_SKIPPED);
    }

    public static function createFailed(): self
    {

        return new self(self::STATUS_FAILED);
    }
}
