<?php

namespace Oro\Bundle\SearchBundle\EventListener;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\UnitOfWork;

use Symfony\Component\PropertyAccess\PropertyAccessor;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\PlatformBundle\EventListener\OptionalListenerInterface;
use Oro\Bundle\SearchBundle\Engine\IndexerInterface;
use Oro\Bundle\SearchBundle\Provider\SearchMappingProvider;

class IndexListener implements OptionalListenerInterface
{
    /**
     * @var DoctrineHelper
     */
    protected $doctrineHelper;

    /**
     * @var IndexerInterface
     */
    protected $searchIndexer;

    /**
     * @var array
     * @deprecated since 1.8 Please use mappingProvider for mapping config
     */
    protected $entitiesConfig = [];

    /**
     * @var SearchMappingProvider
     */
    protected $mappingProvider;

    /**
     * @var array
     */
    protected $savedEntities = [];

    /**
     * @var array
     */
    protected $deletedEntities = [];

    /**
     * @var bool
     */
    protected $enabled = true;

    /**
     * @var PropertyAccessor
     */
    protected $propertyAccessor;

    /**
     * @param DoctrineHelper $doctrineHelper
     * @param IndexerInterface $searchIndexer
     * @param PropertyAccessor $propertyAccessor
     */
    public function __construct(
        DoctrineHelper $doctrineHelper,
        IndexerInterface $searchIndexer,
        PropertyAccessor $propertyAccessor
    ) {
        $this->doctrineHelper   = $doctrineHelper;
        $this->searchIndexer    = $searchIndexer;
        $this->propertyAccessor = $propertyAccessor;
    }

    /**
     * {@inheritdoc}
     */
    public function setEnabled($enabled = true)
    {
        $this->enabled = $enabled;
    }

    /**
     * @param array $entities
     * @deprecated since 1.8 Please use mappingProvider for mapping config
     */
    public function setEntitiesConfig(array $entities)
    {
        $this->entitiesConfig = $entities;
    }

    /**
     * @param SearchMappingProvider $mappingProvider
     */
    public function setMappingProvider(SearchMappingProvider $mappingProvider)
    {
        $this->mappingProvider = $mappingProvider;
    }

    /**
     * @param OnFlushEventArgs $args
     */
    public function onFlush(OnFlushEventArgs $args)
    {
        if (!$this->enabled) {
            return;
        }

        $entityManager = $args->getEntityManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        // schedule saved entities
        // inserted and updated entities should be processed as is
        $inserts = $unitOfWork->getScheduledEntityInsertions();
        $updates = $unitOfWork->getScheduledEntityUpdates();
        $savedEntities = array_merge(
            $inserts,
            $this->getEntitiesWithUpdatedIndexedFields($unitOfWork),
            $this->getAssociatedEntitiesToReindex($entityManager, $inserts),
            $this->getAssociatedEntitiesToReindex($entityManager, $updates)
        );
        foreach ($savedEntities as $hash => $entity) {
            if (empty($this->savedEntities[$hash]) && $this->isSupported($entity)) {
                $this->savedEntities[$hash] = $entity;
            }
        }

        // schedule deleted entities
        // deleted entities should be processed as references because on postFlush they are already deleted
        $deletedEntities = $unitOfWork->getScheduledEntityDeletions();
        foreach ($deletedEntities as $hash => $entity) {
            if (empty($this->deletedEntities[$hash]) && $this->isSupported($entity)) {
                $this->deletedEntities[$hash] = $entityManager->getReference(
                    $this->doctrineHelper->getEntityClass($entity),
                    $this->doctrineHelper->getSingleEntityIdentifier($entity)
                );
            }
        }
    }

    /**
     * @param UnitOfWork $uow
     *
     * @return object[]
     */
    protected function getEntitiesWithUpdatedIndexedFields(UnitOfWork $uow)
    {
        $entitiesToReindex = [];

        foreach ($uow->getScheduledEntityUpdates() as $hash => $entity) {
            $className = ClassUtils::getClass($entity);
            if (!$this->mappingProvider->hasFieldsMapping($className)) {
                continue;
            }

            $entityConfig = $this->mappingProvider->getEntityConfig($className);

            $indexedFields = [];
            foreach ($entityConfig['fields'] as $fieldConfig) {
                $indexedFields[] = $fieldConfig['name'];
            }

            $changeSet = $uow->getEntityChangeSet($entity);
            $fieldsToReindex = array_intersect($indexedFields, array_keys($changeSet));
            if ($fieldsToReindex) {
                $entitiesToReindex[$hash] = $entity;
            }
        }

        return $entitiesToReindex;
    }

    /**
     * @param EntityManager $entityManager
     * @param array $entities
     *
     * @return array
     */
    protected function getAssociatedEntitiesToReindex(EntityManager $entityManager, $entities)
    {
        $entitiesToReindex = [];

        foreach ($entities as $entity) {
            $className = ClassUtils::getClass($entity);
            $meta = $entityManager->getClassMetadata($className);

            foreach ($meta->getAssociationMappings() as $association) {
                if (!empty($association['inversedBy'])) {
                    $targetClass = $association['targetEntity'];

                    if (!$this->mappingProvider->hasFieldsMapping($targetClass)) {
                        continue;
                    }

                    if ($association['type'] == ClassMetadataInfo::MANY_TO_ONE) {
                        $targetEntity = $this->propertyAccessor->getValue($entity, $association['fieldName']);
                        if (null != $targetEntity) {
                            $targetHash = spl_object_hash($targetEntity);
                            $entitiesToReindex[$targetHash] = $targetEntity;
                        }
                    }
                }
            }
        }

        return $entitiesToReindex;
    }

    /**
     * @param PostFlushEventArgs $args
     */
    public function postFlush(PostFlushEventArgs $args)
    {
        if (!$this->enabled) {
            return;
        }

        if ($this->hasEntitiesToIndex()) {
            $this->indexEntities();
        }
    }

    /**
     * Clear object storage when error was occurred during UOW#Commit
     *
     * @param OnClearEventArgs $args
     */
    public function onClear(OnClearEventArgs $args)
    {
        if (!($this->enabled && $this->hasEntitiesToIndex())) {
            return;
        }

        $this->savedEntities = $this->deletedEntities = [];
    }

    /**
     * Synchronise all changed entities with search index
     */
    protected function indexEntities()
    {
        if ($this->savedEntities) {
            $this->searchIndexer->save($this->savedEntities);

            $this->savedEntities = [];
        }

        if ($this->deletedEntities) {
            $this->searchIndexer->delete($this->deletedEntities);

            $this->deletedEntities = [];
        }
    }

    /**
     * @param object $entity
     * @return bool
     */
    protected function isSupported($entity)
    {
        return $this->mappingProvider->isClassSupported(ClassUtils::getClass($entity));
    }

    /**
     * @return bool
     */
    protected function hasEntitiesToIndex()
    {
        return !empty($this->savedEntities) || !empty($this->deletedEntities);
    }
}
