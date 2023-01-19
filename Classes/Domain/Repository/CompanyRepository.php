<?php

declare(strict_types=1);

/*
 * This file is part of the package jweiland/yellowpages2.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace JWeiland\Yellowpages2\Domain\Repository;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use JWeiland\Glossary2\Service\GlossaryService;
use JWeiland\Yellowpages2\Domain\Model\Company;
use JWeiland\Yellowpages2\Event\ModifyQueryToFindCompanyByLetterEvent;
use JWeiland\Yellowpages2\Event\ModifyQueryToSearchForCompaniesEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Query;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Repository to retrieve company records
 *
 * @method Company|null findByIdentifier(int $companyUid)
 * @method QueryResultInterface findByFeUser(int $frontendUserUid)
 */
class CompanyRepository extends Repository implements HiddenRepositoryInterface
{
    /**
     * @var array
     */
    protected $defaultOrderings = [
        'company' => QueryInterface::ORDER_ASCENDING,
    ];

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    public function injectEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function findHiddenObject($value, string $property = 'uid'): ?object
    {
        $query = $this->createQuery();
        $query->getQuerySettings()->setIgnoreEnableFields(true);
        $query->getQuerySettings()->setEnableFieldsToBeIgnored(['disabled']);
        $query->getQuerySettings()->setRespectStoragePage(false);

        return $query->matching($query->equals($property, $value))->execute()->getFirst();
    }

    public function findByLetter(string $letter, array $settings = []): QueryResultInterface
    {
        /** @var Query $query */
        $query = $this->createQuery();
        $queryBuilder = $this->getQueryBuilderForCompany($query);

        if ($letter) {
            $glossaryService = GeneralUtility::makeInstance(GlossaryService::class);
            $queryBuilder
                ->where(
                    $glossaryService->getLetterConstraintForDoctrineQuery(
                        $queryBuilder,
                        'c.company',
                        $letter
                    )
                );
        }

        if ($settings['presetTrade']) {
            $this->addConstraintForTrades($queryBuilder, (int)$settings['presetTrade']);
        }

        if ($settings['district']) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'c.district',
                    $queryBuilder->createNamedParameter($settings['district'], \PDO::PARAM_INT)
                )
            );
        }

        $this->eventDispatcher->dispatch(new ModifyQueryToFindCompanyByLetterEvent($queryBuilder, $settings));

        return $query->statement($queryBuilder)->execute();
    }

    /**
     * search records
     *
     * @param string $search
     * @param int $categoryUid
     * @param array $settings
     * @return QueryResultInterface
     */
    public function searchCompanies(string $search, int $categoryUid, array $settings): QueryResultInterface
    {
        /** @var Query $query */
        $query = $this->createQuery();
        $queryBuilder = $this->getQueryBuilderForCompany($query);
        $longStreetSearch = $smallStreetSearch = trim($search);

        // unify street search
        if (strtolower(mb_substr($search, -6)) === 'straße') {
            $smallStreetSearch = str_ireplace('straße', 'str', $search);
        }
        if (strtolower(mb_substr($search, -4)) === 'str.') {
            $longStreetSearch = str_ireplace('str.', 'straße', $search);
            $smallStreetSearch = str_ireplace('str.', 'str', $search);
        }
        if (strtolower(mb_substr($search, -3)) === 'str') {
            $longStreetSearch = str_ireplace('str', 'straße', $search);
        }

        if (!empty($longStreetSearch)) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->like(
                        'c.company',
                        $queryBuilder->createNamedParameter('%' . $search . '%')
                    ),
                    $queryBuilder->expr()->like(
                        'c.street',
                        $queryBuilder->createNamedParameter('%' . $smallStreetSearch . '%')
                    ),
                    $queryBuilder->expr()->like(
                        'c.street',
                        $queryBuilder->createNamedParameter('%' . $longStreetSearch . '%')
                    )
                )
            );
        }

        if (!empty($categoryUid)) {
            $this->addConstraintForTrades($queryBuilder, $categoryUid);
        }

        $this->eventDispatcher->dispatch(
            new ModifyQueryToSearchForCompaniesEvent($queryBuilder, $search, $categoryUid, $settings)
        );

        return $query->statement($queryBuilder)->execute();
    }

    protected function getQueryBuilderForCompany(QueryInterface $extbaseQuery): QueryBuilder
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_yellowpages2_domain_model_company');
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        return $queryBuilder
            ->select(...$this->getColumnsForCompanyTable())
            ->from('tx_yellowpages2_domain_model_company', 'c')
            ->where(
                $queryBuilder->expr()->in(
                    'c.pid',
                    $queryBuilder->createNamedParameter(
                        $extbaseQuery->getQuerySettings()->getStoragePageIds(),
                        Connection::PARAM_INT_ARRAY
                    )
                )
            )
            ->orderBy('c.company', 'ASC');
    }

    /**
     * ->select() and ->groupBy() has to be the same in DB configuration
     * where only_full_group_by is activated.
     */
    protected function getColumnsForCompanyTable(): array
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_yellowpages2_domain_model_company');
        if ($connection->getSchemaManager() instanceof AbstractSchemaManager) {
            return array_map(
                static function ($column) {
                    return 'c.' . $column;
                },
                array_keys(
                    $connection->getSchemaManager()->listTableColumns('tx_yellowpages2_domain_model_company') ?? []
                )
            );
        }

        return [];
    }

    protected function addConstraintForTrades(QueryBuilder $queryBuilder, int $categoryUid): void
    {
        $queryBuilder->leftJoin(
            'c',
            'sys_category_record_mm',
            'category_mm',
            (string)$queryBuilder->expr()->andX(
                $queryBuilder->expr()->eq(
                    'c.uid',
                    $queryBuilder->quoteIdentifier('category_mm.uid_foreign')
                ),
                $queryBuilder->expr()->eq(
                    'category_mm.tablenames',
                    $queryBuilder->createNamedParameter('tx_yellowpages2_domain_model_company')
                )
            )
        );

        $queryBuilder->andWhere(
            $queryBuilder->expr()->orX(
                $queryBuilder->expr()->eq(
                    'category_mm.fieldname',
                    $queryBuilder->createNamedParameter('main_trade')
                ),
                $queryBuilder->expr()->eq(
                    'category_mm.fieldname',
                    $queryBuilder->createNamedParameter('trades')
                )
            ),
            $queryBuilder->expr()->eq(
                'category_mm.uid_local',
                $queryBuilder->createNamedParameter($categoryUid, \PDO::PARAM_INT)
            )
        );
    }

    /**
     * Collect all translated categories used by main_trade and trades
     */
    public function getTranslatedCategories(): array
    {
        $query = $this->createQuery();
        $queryBuilder = $this->getQueryBuilderForCompany($query);
        $queryBuilder
            ->select('*')
            ->leftJoin(
                'c',
                'sys_category_record_mm',
                'category_mm',
                (string)$queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        'c.uid',
                        $queryBuilder->quoteIdentifier('category_mm.uid_foreign')
                    ),
                    $queryBuilder->expr()->eq(
                        'category_mm.tablenames',
                        $queryBuilder->createNamedParameter('tx_yellowpages2_domain_model_company')
                    ),
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->eq(
                            'category_mm.fieldname',
                            $queryBuilder->createNamedParameter('main_trade')
                        ),
                        $queryBuilder->expr()->eq(
                            'category_mm.fieldname',
                            $queryBuilder->createNamedParameter('trades')
                        )
                    )
                )
            )
            ->leftJoin(
                'category_mm',
                'sys_category',
                'sc',
                $queryBuilder->expr()->eq(
                    'sc.uid',
                    $queryBuilder->quoteIdentifier('category_mm.uid_local')
                )
            )
            ->groupBy('sc.uid')
            ->orderBy('sc.title', 'ASC');

        $results = $query->statement($queryBuilder)->execute(true);

        $translatedCategories = [];
        $translatedCategories[] = LocalizationUtility::translate('allBranches', 'yellowpages2');
        foreach ($results as $result) {
            $translatedCategories[$result['uid']] = $result['title'];
        }

        return $translatedCategories;
    }

    /**
     * Find all records which are older than given days
     * Hint: Needed by scheduler
     */
    public function findOlderThan(int $days): QueryResultInterface
    {
        $today = date('U');
        $history = $today - ($days * 60 * 60 * 24);
        $query = $this->createQuery();
        return $query->matching($query->lessThan('tstamp', $history))->execute();
    }

    public function getQueryBuilderToFindAllEntries(): QueryBuilder
    {
        return $this->getQueryBuilderForCompany($this->createQuery());
    }

    protected function getConnectionPool(): ConnectionPool
    {
        return GeneralUtility::makeInstance(ConnectionPool::class);
    }
}
