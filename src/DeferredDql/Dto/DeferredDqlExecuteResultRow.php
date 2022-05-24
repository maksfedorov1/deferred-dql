<?php declare(strict_types=1);
/**
 * This file is a part of the Project 3.0 verification system.
 *
 * @author Maksim Fedorov
 * @date   19.12.2019 15:56
 */

namespace Bank30\Service\Background\DeferredDql\Dto;

use Bank30\Service\Background\DeferredDql\Enum\DqlExecuteStatus;

class DeferredDqlExecuteResultRow
{
    /** @var string */
    private $dql;

    /** @var DqlExecuteStatus */
    private $executeStatus;

    public function __construct(string $dql, DqlExecuteStatus $executeStatus)
    {
        $this->dql           = $dql;
        $this->executeStatus = $executeStatus;
    }

    public function getDql(): string
    {
        return $this->dql;
    }

    public function getExecuteStatus(): DqlExecuteStatus
    {
        return $this->executeStatus;
    }
}
