<?php declare(strict_types=1);
/**
 * This file is a part of the Project 3.0 verification system.
 *
 * @author Maksim Fedorov
 * @date   20.12.2019 10:13
 */

namespace Bank30\Service\Background\DeferredDql\Dto;

class CachedDeferredDql
{
    /** @var DeferredDql */
    private $deferredDql;

    /** @var int */
    private $score;

    public function __construct(DeferredDql $deferredDql, int $score)
    {
        $this->deferredDql = $deferredDql;
        $this->score       = $score;
    }

    public function getDeferredDql(): DeferredDql
    {
        return $this->deferredDql;
    }

    public function getScore(): int
    {
        return $this->score;
    }
}
