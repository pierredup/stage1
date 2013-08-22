<?php

namespace App\CoreBundle\Entity;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\ResultSetMappingBuilder;

use Exception;

class BuildRepository extends EntityRepository
{
    public function countPendingBuildsByProject(Project $project)
    {
        $query = $this->createQueryBuilder('b')
           ->select('count(b.id)')
            ->where('b.project = ?1')
            ->andWhere('b.status IN (?2)')
            ->setParameters([
                1 => $project->getId(),
                2 => [Build::STATUS_BUILDING, Build::STATUS_SCHEDULED]
            ])
            ->getQuery();

        return (int) $query->getSingleScalarResult();
    }

    public function findLastByRefs()
    {
        $query = 'SELECT b.* FROM (SELECT * FROM build ORDER BY created_at DESC) b GROUP BY b.ref';

        $rsm = new ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata('App\\CoreBundle\\Entity\\Build', 'b');
        return $this->getEntityManager()->createNativeQuery($query, $rsm)->execute();
    }

    public function findPreviousBuild(Build $build)
    {
        try {
            return $this->createQueryBuilder('b')
                ->select()
                ->where('b.project = ?1')
                ->andWhere('b.ref = ?2')
                ->andWhere('b.status = ?3')
                ->setParameters([
                    1 => $build->getProject()->getId(),
                    2 => $build->getRef(),
                    3 => Build::STATUS_RUNNING,
                ])
                ->getQuery()
                ->getSingleResult();
        } catch (Exception $e) {
            return null;
        }
    }
}