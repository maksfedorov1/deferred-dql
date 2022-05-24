<?php declare(strict_types=1);
/**
 * This file is a part of the Project 3.0 verification system.
 *
 * @author Maksim Fedorov
 * @date   17.03.2021 19:00
 */

namespace Bank30\Service\Background\DeferredDql;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;

class DeferredDqlTransformer
{
    /** @var EntityManager */
    private $em;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    public function transform(Dto\DeferredDql $deferredDql): Query
    {
        $query = $this->em->createQuery($deferredDql->getDql());

        foreach ($deferredDql->getParameters() as $parameter) {
            $query->setParameter(
                $parameter->getName(),
                \is_object($parameter->getValue()) ? $parameter->getValue()->getId() : $parameter->getValue(),
                $parameter->getType()
            );
        }

        foreach ($deferredDql->getHints() as $hintName => $hintValue) {
            $query->setHint($hintName, $hintValue);
        }

        return $query;
    }
}
