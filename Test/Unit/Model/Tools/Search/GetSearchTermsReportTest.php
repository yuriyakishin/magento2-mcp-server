<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\Search;

use Magento\Framework\DataObject;
use Magento\Search\Model\ResourceModel\Query\Collection as QueryCollection;
use Magento\Search\Model\ResourceModel\Query\CollectionFactory as QueryCollectionFactory;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\Search\GetSearchTermsReport;

class GetSearchTermsReportTest extends TestCase
{
    /**
     * Search terms are back-office analytics — the tool must require the admin report ACL.
     */
    public function testRequiresReportSearchAclResource(): void
    {
        $tool = new GetSearchTermsReport($this->createMock(QueryCollectionFactory::class));

        $this->assertSame('Magento_Reports::report_search', $tool->getRequiredAclResource());
        $this->assertSame('search_terms_report', $tool->getName());
    }

    /**
     * A malformed limit must fail validation before any collection is built.
     */
    public function testThrowsOnInvalidLimit(): void
    {
        $factory = $this->createMock(QueryCollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $tool = new GetSearchTermsReport($factory);

        $this->expectException(\InvalidArgumentException::class);
        $tool->execute(['limit' => 0]);
    }

    /**
     * Two lists come back: all terms by popularity, and the zero-result subset (the
     * second collection gets the num_results = 0 filter).
     */
    public function testReturnsPopularAndZeroResultLists(): void
    {
        $popular = [
            new DataObject(['query_text' => 'shirt', 'popularity' => 42, 'num_results' => 15]),
            new DataObject(['query_text' => 'hoodie', 'popularity' => 30, 'num_results' => 0]),
        ];
        $zero = [
            new DataObject(['query_text' => 'hoodie', 'popularity' => 30, 'num_results' => 0]),
        ];

        $popularCollection = $this->collectionMock($popular);
        $popularCollection->expects($this->never())->method('addFieldToFilter');
        $zeroCollection = $this->collectionMock($zero);
        $zeroCollection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('num_results', 0);

        $factory = $this->createMock(QueryCollectionFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls($popularCollection, $zeroCollection);

        $result = (new GetSearchTermsReport($factory))->execute([]);

        $this->assertSame(
            [
                ['term' => 'shirt', 'hits' => 42, 'results' => 15],
                ['term' => 'hoodie', 'hits' => 30, 'results' => 0],
            ],
            $result['popular']
        );
        $this->assertSame([['term' => 'hoodie', 'hits' => 30, 'results' => 0]], $result['zero_results']);
    }

    /**
     * Empty search log means two empty lists, not an error.
     */
    public function testEmptySearchLog(): void
    {
        $factory = $this->createMock(QueryCollectionFactory::class);
        $factory->method('create')->willReturnCallback(fn () => $this->collectionMock([]));

        $result = (new GetSearchTermsReport($factory))->execute([]);

        $this->assertSame([], $result['popular']);
        $this->assertSame([], $result['zero_results']);
    }

    /**
     * Builds a query collection mock iterating the given rows.
     *
     * @param DataObject[] $rows
     */
    private function collectionMock(array $rows): QueryCollection
    {
        $collection = $this->createMock(QueryCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));

        return $collection;
    }
}
