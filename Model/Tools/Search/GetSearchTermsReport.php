<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Search;

use Magento\Search\Model\ResourceModel\Query\CollectionFactory as QueryCollectionFactory;
use Yu\McpServer\Model\ToolInterface;

/**
 * Storefront search-terms report: what visitors type into the search box. The two lists —
 * most popular terms and terms that returned zero results — are the raw material for
 * "what should we stock next" and "where do we need synonyms/redirects" decisions.
 */
class GetSearchTermsReport implements ToolInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly QueryCollectionFactory $queryCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'search_terms_report';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Report on storefront search terms: the most popular queries (with hit counts '
            . 'and how many results each returns) and the queries that returned ZERO results. '
            . 'Zero-result terms show demand the catalog does not cover — candidates for new '
            . 'products, synonyms or redirects. Typical use: "what do visitors search for?", '
            . '"which searches find nothing?".';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many terms to return in each list (default 20, max 100).',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Search terms are back-office analytics — same permission as the admin Search Terms report.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Reports::report_search';
    }

    /**
     * @param array $arguments See getInputSchema().
     * @return array{popular: array<int, array<string, mixed>>, zero_results: array<int, array<string, mixed>>}
     */
    public function execute(array $arguments): array
    {
        $limit = $this->limitArgument($arguments);

        return [
            'popular' => $this->fetchTerms($limit, false),
            'zero_results' => $this->fetchTerms($limit, true),
        ];
    }

    /**
     * Fetches one list of search terms ordered by popularity (total times searched).
     *
     * @return array<int, array{term: string, hits: int, results: int}>
     */
    private function fetchTerms(int $limit, bool $zeroResultsOnly): array
    {
        $collection = $this->queryCollectionFactory->create();
        if ($zeroResultsOnly) {
            $collection->addFieldToFilter('num_results', 0);
        }
        $collection->setOrder('popularity', 'DESC');
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        $terms = [];
        foreach ($collection as $query) {
            $terms[] = [
                'term' => (string)$query->getData('query_text'),
                'hits' => (int)$query->getData('popularity'),
                'results' => (int)$query->getData('num_results'),
            ];
        }

        return $terms;
    }

    /**
     * Validates the optional "limit" argument.
     */
    private function limitArgument(array $arguments): int
    {
        if (!isset($arguments['limit'])) {
            return self::DEFAULT_LIMIT;
        }
        if (!is_numeric($arguments['limit']) || (int)$arguments['limit'] < 1) {
            throw new \InvalidArgumentException('Argument "limit" must be an integer >= 1.');
        }

        return min((int)$arguments['limit'], self::MAX_LIMIT);
    }
}
