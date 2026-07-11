<?php

declare(strict_types=1);

namespace Yu\McpServer\Test\Unit\Model\Tools\System;

use Magento\Cron\Model\ResourceModel\Schedule\Collection as ScheduleCollection;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as ScheduleCollectionFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Indexer\Model\Indexer;
use Magento\Indexer\Model\Indexer\Collection as IndexerCollection;
use Magento\Indexer\Model\Indexer\CollectionFactory as IndexerCollectionFactory;
use PHPUnit\Framework\TestCase;
use Yu\McpServer\Model\Tools\System\GetSystemHealth;

class GetSystemHealthTest extends TestCase
{
    /**
     * Indexer/cron/cache state is system administration data — admin-only ACL.
     */
    public function testRequiresSystemAclResource(): void
    {
        $tool = new GetSystemHealth(
            $this->createMock(IndexerCollectionFactory::class),
            $this->createMock(ScheduleCollectionFactory::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(DateTime::class)
        );

        $this->assertSame('Magento_Backend::system', $tool->getRequiredAclResource());
        $this->assertSame('system_health', $tool->getName());
    }

    /**
     * The snapshot flags invalid indexers, counts cron statuses, samples failed jobs,
     * detects stuck running jobs and reports disabled/invalidated cache types.
     */
    public function testReportsIndexersCronAndCache(): void
    {
        $now = strtotime('2026-07-09 12:00:00');

        $validIndexer = $this->createMock(Indexer::class);
        $validIndexer->method('getId')->willReturn('catalog_product_price');
        $validIndexer->method('getTitle')->willReturn('Product Price');
        $validIndexer->method('getStatus')->willReturn('valid');
        $invalidIndexer = $this->createMock(Indexer::class);
        $invalidIndexer->method('getId')->willReturn('catalogsearch_fulltext');
        $invalidIndexer->method('getTitle')->willReturn('Catalog Search');
        $invalidIndexer->method('getStatus')->willReturn('invalid');

        $indexerCollection = $this->createMock(IndexerCollection::class);
        $indexerCollection->method('getItems')->willReturn([$validIndexer, $invalidIndexer]);
        $indexerFactory = $this->createMock(IndexerCollectionFactory::class);
        $indexerFactory->method('create')->willReturn($indexerCollection);

        $schedules = [
            new DataObject(['status' => 'success', 'job_code' => 'indexer_update_all_views']),
            new DataObject([
                'status' => 'error',
                'job_code' => 'sales_send_order_emails',
                'executed_at' => '2026-07-09 11:00:00',
                'messages' => 'SMTP connection refused',
            ]),
            new DataObject([
                'status' => 'running',
                'job_code' => 'catalog_index_refresh_price',
                'executed_at' => '2026-07-09 10:00:00',
            ]),
            new DataObject([
                'status' => 'running',
                'job_code' => 'fresh_job',
                'executed_at' => '2026-07-09 11:50:00',
            ]),
        ];
        $scheduleCollection = $this->createMock(ScheduleCollection::class);
        $scheduleCollection->method('getIterator')->willReturn(new \ArrayIterator($schedules));
        $scheduleFactory = $this->createMock(ScheduleCollectionFactory::class);
        $scheduleFactory->method('create')->willReturn($scheduleCollection);

        $cacheTypeList = $this->createMock(TypeListInterface::class);
        $cacheTypeList->method('getTypes')->willReturn([
            new DataObject(['id' => 'config', 'status' => 1]),
            new DataObject(['id' => 'full_page', 'status' => 0]),
        ]);
        $cacheTypeList->method('getInvalidated')->willReturn([
            new DataObject(['id' => 'block_html', 'status' => 1]),
        ]);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn($now);

        $tool = new GetSystemHealth($indexerFactory, $scheduleFactory, $cacheTypeList, $dateTime);

        $result = $tool->execute([]);

        $this->assertSame(2, $result['indexers']['total']);
        $this->assertSame(1, $result['indexers']['not_valid_count']);
        $this->assertSame('invalid', $result['indexers']['items'][1]['status']);

        $this->assertSame(1, $result['cron_last_24h']['by_status']['success']);
        $this->assertSame(1, $result['cron_last_24h']['by_status']['error']);
        $this->assertSame(2, $result['cron_last_24h']['by_status']['running']);
        $this->assertSame('sales_send_order_emails', $result['cron_last_24h']['errors'][0]['job_code']);
        $this->assertSame('SMTP connection refused', $result['cron_last_24h']['errors'][0]['message']);
        // Running since 10:00 with "now" at 12:00 is stuck; running since 11:50 is not.
        $this->assertCount(1, $result['cron_last_24h']['stuck_running']);
        $this->assertSame('catalog_index_refresh_price', $result['cron_last_24h']['stuck_running'][0]['job_code']);

        $this->assertSame(2, $result['cache']['total_types']);
        $this->assertSame(['full_page'], $result['cache']['disabled']);
        $this->assertSame(['block_html'], $result['cache']['invalidated']);
    }

    /**
     * A quiet system produces empty problem lists, not errors.
     */
    public function testHealthySystem(): void
    {
        $indexerCollection = $this->createMock(IndexerCollection::class);
        $indexerCollection->method('getItems')->willReturn([]);
        $indexerFactory = $this->createMock(IndexerCollectionFactory::class);
        $indexerFactory->method('create')->willReturn($indexerCollection);

        $scheduleCollection = $this->createMock(ScheduleCollection::class);
        $scheduleCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $scheduleFactory = $this->createMock(ScheduleCollectionFactory::class);
        $scheduleFactory->method('create')->willReturn($scheduleCollection);

        $cacheTypeList = $this->createMock(TypeListInterface::class);
        $cacheTypeList->method('getTypes')->willReturn([]);
        $cacheTypeList->method('getInvalidated')->willReturn([]);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(time());

        $tool = new GetSystemHealth($indexerFactory, $scheduleFactory, $cacheTypeList, $dateTime);

        $result = $tool->execute([]);

        $this->assertSame(0, $result['indexers']['not_valid_count']);
        $this->assertSame([], $result['cron_last_24h']['errors']);
        $this->assertSame([], $result['cron_last_24h']['stuck_running']);
        $this->assertSame([], $result['cache']['disabled']);
    }
}
