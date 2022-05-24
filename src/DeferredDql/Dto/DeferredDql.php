<?php declare(strict_types=1);
/**
 * This file is a part of the Project 3.0 verification system.
 *
 * @author Maksim Fedorov
 * @date   16.12.2019 19:37
 */

namespace Bank30\Service\Background\DeferredDql\Dto;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query\Parameter;

class DeferredDql
{
    /** @var string */
    private $targetCacheKey;

    /** @var string */
    private $dql;

    /** @var DeferredDqlParameter[] */
    private $parameters;

    /** @var array */
    private $hints;

    /** @var int */
    private $cacheTTL;

    private function __construct(
        string $cacheKey,
        string $dql,
        array $parameters,
        array $hints = [],
        int $cacheTTL = 0
    ) {
        $this->targetCacheKey = $cacheKey;
        $this->dql            = $dql;
        $this->cacheTTL       = $cacheTTL;
        $this->hints          = $hints;
        $this->parameters     = $parameters;
    }

    public static function create(string $dql, ArrayCollection $inputParams, array $hints, string $cacheKey, int $cacheTTL = 0): self
    {
        $params  = array_map(static function (Parameter $param): DeferredDqlParameter {
            return new DeferredDqlParameter(
                $param->getName(),
                $param->getValue(),
                $param->getType()
            );
        }, $inputParams->toArray());

        return new self(
            $cacheKey,
            $dql,
            $params,
            $hints,
            $cacheTTL
        );
    }

    public function getTargetCacheKey(): string
    {
        return $this->targetCacheKey;
    }

    public function getDql(): string
    {
        return $this->dql;
    }

    /**
     * @return DeferredDqlParameter[]
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getStringParameters(): string
    {
        return implode(';', $this->parameters);
    }

    public function getHints(): array
    {
        return $this->hints;
    }

    public function getCacheTTL(): int
    {
        return $this->cacheTTL;
    }
}
