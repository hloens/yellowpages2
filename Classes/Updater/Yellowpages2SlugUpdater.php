<?php

declare(strict_types=1);

/*
 * This file is part of the package jweiland/yellowpages2.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace JWeiland\Yellowpages2\Updater;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Updater to fill empty slug columns of company records
 */
class Yellowpages2SlugUpdater implements UpgradeWizardInterface
{
    /**
     * @var string
     */
    protected $tableName = 'tx_yellowpages2_domain_model_company';

    /**
     * @var string
     */
    protected $fieldName = 'path_segment';

    /**
     * @var SlugHelper
     */
    protected $slugHelper;

    public function __construct(SlugHelper $slugHelper = null)
    {
        if ($slugHelper === null) {
            $slugHelper = GeneralUtility::makeInstance(
                SlugHelper::class,
                $this->tableName,
                $this->fieldName,
                $GLOBALS['TCA'][$this->tableName]['columns']['path_segment']['config']
            );
        }
        $this->slugHelper = $slugHelper;
    }

    /**
     * Return the identifier for this wizard
     * This should be the same string as used in the ext_localconf class registration
     *
     * @return string
     */
    public function getIdentifier(): string
    {
        return 'yellowpages2UpdateSlug';
    }

    public function getTitle(): string
    {
        return '[yellowpages2] Update Slug of company records';
    }

    public function getDescription(): string
    {
        return 'Update empty slug column "path_segment" of company records with an URI compatible version of the company name';
    }

    public function updateNecessary(): bool
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($this->tableName);
        $amountOfRecordsWithEmptySlug = $queryBuilder
            ->count('*')
            ->from($this->tableName)
            ->where(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->eq(
                        $this->fieldName,
                        $queryBuilder->createNamedParameter('', Connection::PARAM_STR)
                    ),
                    $queryBuilder->expr()->isNull(
                        $this->fieldName
                    )
                )
            )
            ->execute()
            ->fetchColumn(0);

        return (bool)$amountOfRecordsWithEmptySlug;
    }

    /**
     * Performs the accordant updates.
     *
     * @return bool Whether everything went smoothly or not
     */
    public function executeUpdate(): bool
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($this->tableName);
        $recordsToUpdate = $queryBuilder
            ->select('uid', 'pid', 'company', 'path_segment')
            ->from($this->tableName)
            ->where(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->eq(
                        $this->fieldName,
                        $queryBuilder->createNamedParameter('', Connection::PARAM_STR)
                    ),
                    $queryBuilder->expr()->isNull(
                        $this->fieldName
                    )
                )
            )
            ->execute()
            ->fetchAll();

        if ($recordsToUpdate === false) {
            $recordsToUpdate = [];
        }

        $connection = $this->getConnectionPool()->getConnectionForTable($this->tableName);
        foreach ($recordsToUpdate as $recordToUpdate) {
            if ((string)$recordToUpdate['company'] !== '') {
                $slug = $this->slugHelper->generate($recordToUpdate, $recordToUpdate['pid']);
                $connection->update(
                    $this->tableName,
                    [
                        $this->fieldName => $this->getUniqueValue(
                            (int)$recordToUpdate['uid'],
                            $slug
                        )
                    ],
                    [
                        'uid' => (int)$recordToUpdate['uid']
                    ]
                );
            }
        }

        return true;
    }

    /**
     * @param int $uid
     * @param string $slug
     * @return string
     */
    protected function getUniqueValue(int $uid, string $slug): string
    {
        $statement = $this->getUniqueCountStatement($uid, $slug);
        if ($statement->fetchColumn(0)) {
            for ($counter = 1; $counter <= 100; $counter++) {
                $newSlug = $slug . '-' . $counter;
                $statement->bindValue(1, $newSlug);
                $statement->execute();
                if (!$statement->fetchColumn()) {
                    break;
                }
            }
        }

        return $newSlug ?? $slug;
    }

    protected function getUniqueCountStatement(int $uid, string $slug)
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($this->tableName);
        $queryBuilder
            ->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder
            ->count('uid')
            ->from($this->tableName)
            ->where(
                $queryBuilder->expr()->eq(
                    $this->fieldName,
                    $queryBuilder->createPositionalParameter($slug, Connection::PARAM_STR)
                ),
                $queryBuilder->expr()->neq(
                    'uid',
                    $queryBuilder->createPositionalParameter($uid, Connection::PARAM_INT)
                )
            )
            ->execute();
    }

    /**
     * @return string[]
     */
    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class
        ];
    }

    protected function getConnectionPool(): ConnectionPool
    {
        return GeneralUtility::makeInstance(ConnectionPool::class);
    }
}
