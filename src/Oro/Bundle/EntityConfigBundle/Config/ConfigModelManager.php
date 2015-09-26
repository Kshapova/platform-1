<?php

namespace Oro\Bundle\EntityConfigBundle\Config;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\UnitOfWork;

use Oro\Component\DependencyInjection\ServiceLink;
use Oro\Bundle\EntityConfigBundle\Entity\ConfigModel;
use Oro\Bundle\EntityConfigBundle\Entity\EntityConfigModel;
use Oro\Bundle\EntityConfigBundle\Entity\FieldConfigModel;
use Oro\Bundle\EntityConfigBundle\Exception\RuntimeException;
use Oro\Bundle\EntityConfigBundle\Tools\ConfigHelper;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class ConfigModelManager
{
    /** @deprecated since 1.9. Use ConfigModel::MODE_DEFAULT instead */
    const MODE_DEFAULT = ConfigModel::MODE_DEFAULT;
    /** @deprecated since 1.9. Use ConfigModel::MODE_HIDDEN instead */
    const MODE_HIDDEN = ConfigModel::MODE_HIDDEN;
    /** @deprecated since 1.9. Use ConfigModel::MODE_READONLY instead */
    const MODE_READONLY = ConfigModel::MODE_READONLY;

    /** @var EntityConfigModel[] [{class name} => EntityConfigModel, ...] */
    private $entities;

    /** @var array [{class name} => [{field name} => FieldConfigModel, ...], ...] */
    private $fields = [];

    /** @var bool */
    private $dbCheck;

    /** @var ServiceLink */
    protected $emLink;

    private $requiredTables = [
        'oro_entity_config',
        'oro_entity_config_field',
        'oro_entity_config_index_value',
    ];

    /**
     * @param ServiceLink $emLink A link to EntityManager
     */
    public function __construct(ServiceLink $emLink)
    {
        $this->emLink = $emLink;
    }

    /**
     * @return EntityManager
     */
    public function getEntityManager()
    {
        return $this->emLink->getService();
    }

    /**
     * @return bool
     */
    public function checkDatabase()
    {
        if ($this->dbCheck === null) {
            $this->dbCheck = false;
            try {
                $conn = $this->getEntityManager()->getConnection();
                $conn->connect();
                $this->dbCheck = $conn->getSchemaManager()->tablesExist($this->requiredTables);
            } catch (\PDOException $e) {
            }
        }

        return $this->dbCheck;
    }

    public function clearCheckDatabase()
    {
        $this->dbCheck = null;
    }

    /**
     * Finds a model for an entity
     *
     * @param string $className
     *
     * @return EntityConfigModel|null An instance of EntityConfigModel or null if a model was not found
     */
    public function findEntityModel($className)
    {
        if (empty($className) || ConfigHelper::isConfigModelEntity($className)) {
            return null;
        }

        $this->ensureEntityCacheWarmed();

        $result = null;

        // check if a model exists in the local cache
        if (isset($this->entities[$className]) || array_key_exists($className, $this->entities)) {
            $result = $this->entities[$className];
            if ($result && $this->isEntityDetached($result)) {
                if ($this->areAllEntitiesDetached()) {
                    // reload all models because all of them are detached
                    $this->clearCache();
                    $result = $this->findEntityModel($className);
                } else {
                    // the detached model must be reloaded
                    $result = false;

                    $this->entities[$className] = null;
                    unset($this->fields[$className]);
                }
            }
        }

        // load a model if it was not found in the local cache
        if ($result === false) {
            $result = $this->loadEntityModel($className);
        }

        return $result;
    }

    /**
     * Finds a model for an entity field
     *
     * @param string $className
     * @param string $fieldName
     *
     * @return FieldConfigModel|null An instance of FieldConfigModel or null if a model was not found
     */
    public function findFieldModel($className, $fieldName)
    {
        if (empty($className) || empty($fieldName) || ConfigHelper::isConfigModelEntity($className)) {
            return null;
        }

        $this->ensureFieldCacheWarmed($className);

        $result = null;

        // check if a model exists in the local cache
        if (isset($this->fields[$className][$fieldName])
            || (
                isset($this->fields[$className])
                && array_key_exists($fieldName, $this->fields[$className])
            )
        ) {
            $result = $this->fields[$className][$fieldName];
            if ($result && $this->isEntityDetached($result)) {
                // the detached model must be reloaded
                $this->entities[$className] = false;
                unset($this->fields[$className]);

                $result = $this->findFieldModel($className, $fieldName);
            }
        }

        return $result;
    }

    /**
     * @param string $className
     *
     * @return EntityConfigModel
     *
     * @throws \InvalidArgumentException if $className is empty
     * @throws RuntimeException if a model was not found
     */
    public function getEntityModel($className)
    {
        if (empty($className)) {
            throw new \InvalidArgumentException('$className must not be empty');
        }

        $model = $this->findEntityModel($className);
        if (!$model) {
            throw new RuntimeException(
                sprintf('A model for "%s" was not found', $className)
            );
        }

        return $model;
    }

    /**
     * @param string $className
     * @param string $fieldName
     *
     * @return FieldConfigModel
     *
     * @throws \InvalidArgumentException if $className or $fieldName is empty
     * @throws RuntimeException if a model was not found
     */
    public function getFieldModel($className, $fieldName)
    {
        if (empty($className)) {
            throw new \InvalidArgumentException('$className must not be empty');
        }
        if (empty($fieldName)) {
            throw new \InvalidArgumentException('$fieldName must not be empty');
        }

        $model = $this->findFieldModel($className, $fieldName);
        if (!$model) {
            throw new RuntimeException(
                sprintf('A model for "%s::%s" was not found', $className, $fieldName)
            );
        }

        return $model;
    }

    /**
     * Renames a field
     * Important: this method do not save changes in a database. To do this you need to call entityManager->flush
     *
     * @param string $className
     * @param string $fieldName
     * @param string $newFieldName
     *
     * @return bool TRUE if the name was changed; otherwise, FALSE
     *
     * @throws \InvalidArgumentException if $className, $fieldName or $newFieldName is empty
     */
    public function changeFieldName($className, $fieldName, $newFieldName)
    {
        if (empty($className)) {
            throw new \InvalidArgumentException('$className must not be empty');
        }
        if (empty($fieldName)) {
            throw new \InvalidArgumentException('$fieldName must not be empty');
        }
        if (empty($newFieldName)) {
            throw new \InvalidArgumentException('$newFieldName must not be empty');
        }

        $result     = false;
        $fieldModel = $this->findFieldModel($className, $fieldName);
        if ($fieldModel && $fieldModel->getFieldName() !== $newFieldName) {
            $fieldModel->setFieldName($newFieldName);
            $this->getEntityManager()->persist($fieldModel);
            unset($this->fields[$className][$fieldName]);

            $this->fields[$className][$newFieldName] = $fieldModel;
            $result                                  = true;
        }

        return $result;
    }

    /**
     * Changes a type of a field
     * Important: this method do not save changes in a database. To do this you need to call entityManager->flush
     *
     * @param string $className
     * @param string $fieldName
     * @param string $fieldType
     *
     * @return bool TRUE if the type was changed; otherwise, FALSE
     *
     * @throws \InvalidArgumentException if $className, $fieldName or $fieldType is empty
     */
    public function changeFieldType($className, $fieldName, $fieldType)
    {
        if (empty($className)) {
            throw new \InvalidArgumentException('$className must not be empty');
        }
        if (empty($fieldName)) {
            throw new \InvalidArgumentException('$fieldName must not be empty');
        }
        if (empty($fieldType)) {
            throw new \InvalidArgumentException('$fieldType must not be empty');
        }

        $result     = false;
        $fieldModel = $this->findFieldModel($className, $fieldName);
        if ($fieldModel && $fieldModel->getType() !== $fieldType) {
            $fieldModel->setType($fieldType);
            $this->getEntityManager()->persist($fieldModel);

            $this->fields[$className][$fieldName] = $fieldModel;
            $result                               = true;
        }

        return $result;
    }

    /**
     * Changes a mode of a field
     * Important: this method do not save changes in a database. To do this you need to call entityManager->flush
     *
     * @param string $className
     * @param string $fieldName
     * @param string $mode Can be the value of one of ConfigModel::MODE_* constants
     *
     * @return bool TRUE if the mode was changed; otherwise, FALSE
     *
     * @throws \InvalidArgumentException if $className, $fieldName or $mode is empty
     */
    public function changeFieldMode($className, $fieldName, $mode)
    {
        if (empty($className)) {
            throw new \InvalidArgumentException('$className must not be empty');
        }
        if (empty($fieldName)) {
            throw new \InvalidArgumentException('$fieldName must not be empty');
        }
        if (empty($mode)) {
            throw new \InvalidArgumentException('$mode must not be empty');
        }

        $result     = false;
        $fieldModel = $this->findFieldModel($className, $fieldName);
        if ($fieldModel && $fieldModel->getMode() !== $mode) {
            $fieldModel->setMode($mode);
            $this->getEntityManager()->persist($fieldModel);

            $this->fields[$className][$fieldName] = $fieldModel;
            $result                               = true;
        }

        return $result;
    }

    /**
     * Changes a mode of an entity
     * Important: this method do not save changes in a database. To do this you need to call entityManager->flush
     *
     * @param string $className
     * @param string $mode Can be the value of one of ConfigModel::MODE_* constants
     *
     * @return bool TRUE if the type was changed; otherwise, FALSE
     *
     * @throws \InvalidArgumentException if $className or $mode is empty
     */
    public function changeEntityMode($className, $mode)
    {
        if (empty($className)) {
            throw new \InvalidArgumentException('$className must not be empty');
        }
        if (empty($mode)) {
            throw new \InvalidArgumentException('$mode must not be empty');
        }

        $result      = false;
        $entityModel = $this->findEntityModel($className);
        if ($entityModel && $entityModel->getMode() !== $mode) {
            $entityModel->setMode($mode);
            $this->getEntityManager()->persist($entityModel);

            $this->entities[$className] = $entityModel;
            $result                     = true;
        }

        return $result;
    }

    /**
     * @param string|null $className
     *
     * @return ConfigModel[]
     */
    public function getModels($className = null)
    {
        $result = [];

        if ($className) {
            $this->ensureFieldCacheWarmed($className);
            foreach ($this->fields[$className] as $model) {
                if ($model) {
                    $result[] = $model;
                }
            }
        } else {
            $this->ensureEntityCacheWarmed();
            foreach ($this->entities as $model) {
                if ($model) {
                    $result[] = $model;
                }
            }
        }

        return $result;
    }

    /**
     * @param string|null $className
     * @param string|null $mode
     *
     * @return EntityConfigModel
     *
     * @throws \InvalidArgumentException
     */
    public function createEntityModel($className = null, $mode = ConfigModel::MODE_DEFAULT)
    {
        if (!$this->isValidMode($mode)) {
            throw new \InvalidArgumentException(sprintf('Invalid $mode: "%s"', $mode));
        }

        $entityModel = new EntityConfigModel($className);
        $entityModel->setMode($mode);

        if (!empty($className)) {
            $this->ensureEntityCacheWarmed();
            $this->entities[$className] = $entityModel;
        }

        return $entityModel;
    }

    /**
     * @param string $className
     * @param string $fieldName
     * @param string $fieldType
     * @param string $mode
     *
     * @return FieldConfigModel
     *
     * @throws \InvalidArgumentException
     */
    public function createFieldModel($className, $fieldName, $fieldType, $mode = ConfigModel::MODE_DEFAULT)
    {
        if (empty($className)) {
            throw new \InvalidArgumentException('$className must not be empty');
        }
        if (!$this->isValidMode($mode)) {
            throw new \InvalidArgumentException(sprintf('Invalid $mode: "%s"', $mode));
        }

        $entityModel = $this->getEntityModel($className);

        $fieldModel = new FieldConfigModel($fieldName, $fieldType);
        $fieldModel->setMode($mode);
        $entityModel->addField($fieldModel);

        if (!empty($fieldName)) {
            $this->ensureFieldCacheWarmed($className);
            $this->fields[$className][$fieldName] = $fieldModel;
        }

        return $fieldModel;
    }

    /**
     * Removes all cached data
     */
    public function clearCache()
    {
        $this->entities = null;
        $this->fields   = [];

        $em = $this->getEntityManager();
        $em->clear('Oro\Bundle\EntityConfigBundle\Entity\FieldConfigModel');
        $em->clear('Oro\Bundle\EntityConfigBundle\Entity\EntityConfigModel');
    }

    /**
     * Checks $this->entities and if it is empty loads all entity models at once
     */
    protected function ensureEntityCacheWarmed()
    {
        if (null === $this->entities) {
            $this->entities = [];

            /** @var EntityConfigModel[] $models */
            $models = $this->getEntityManager()
                ->getRepository('Oro\Bundle\EntityConfigBundle\Entity\EntityConfigModel')
                ->findAll();
            foreach ($models as $model) {
                $this->entities[$model->getClassName()] = $model;
            }
        }
    }

    /**
     * Checks $this->fields[$className] and if it is empty loads all fields models at once
     *
     * @param string $className
     */
    protected function ensureFieldCacheWarmed($className)
    {
        if (!isset($this->fields[$className])) {
            $this->fields[$className] = [];

            $entityModel = $this->findEntityModel($className);
            if ($entityModel) {
                $fields = $entityModel->getFields();
                foreach ($fields as $model) {
                    $this->fields[$className][$model->getFieldName()] = $model;
                }
            }
        }
    }

    /**
     * @param string $className
     *
     * @return EntityConfigModel|null
     */
    protected function loadEntityModel($className)
    {
        $result = $this->getEntityManager()
            ->getRepository('Oro\Bundle\EntityConfigBundle\Entity\EntityConfigModel')
            ->findOneBy(['className' => $className]);

        $this->entities[$className] = $result;

        return $result;
    }

    /**
     * @param string $mode
     *
     * @return bool
     */
    protected function isValidMode($mode)
    {
        return in_array(
            $mode,
            [ConfigModel::MODE_DEFAULT, ConfigModel::MODE_HIDDEN, ConfigModel::MODE_READONLY],
            true
        );
    }

    /**
     * Determines whether all entities in local cache are detached from an entity manager or not
     */
    protected function areAllEntitiesDetached()
    {
        $result = false;
        if (!empty($this->entities)) {
            $result = true;
            foreach ($this->entities as $model) {
                if ($model && !$this->isEntityDetached($model)) {
                    $result = false;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Determines whether the given entity is managed by an entity manager or not
     *
     * @param object $entity
     *
     * @return bool
     */
    protected function isEntityDetached($entity)
    {
        $entityState = $this->getEntityManager()
            ->getUnitOfWork()
            ->getEntityState($entity);

        return $entityState === UnitOfWork::STATE_DETACHED;
    }
}
