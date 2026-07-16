<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\System;

use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as ScheduleCollectionFactory;
use Magento\Cron\Model\Schedule;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Indexer\Model\Indexer\CollectionFactory as IndexerCollectionFactory;
use Yu\McpServer\Model\ToolInterface;

/**
 * Operational health snapshot: indexer statuses, cron activity over the last 24 hours
 * (including failed and stuck jobs) and cache types that are disabled or invalidated.
 * Read-only — fixing anything (reindex, cache flush, killing a stuck job) stays
 * CLI/admin-panel work by module philosophy.
 */
class GetSystemHealth implements ToolInterface
{
    private const CRON_WINDOW_SECONDS = 86400;
    private const STUCK_RUNNING_SECONDS = 1800;
    private const CRON_SCAN_LIMIT = 5000;
    private const MAX_ERROR_SAMPLES = 10;
    private const ERROR_MESSAGE_MAX_LENGTH = 200;

    public function __construct(
        private readonly IndexerCollectionFactory $indexerCollectionFactory,
        private readonly ScheduleCollectionFactory $scheduleCollectionFactory,
        private readonly TypeListInterface $cacheTypeList,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'system_health';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Store operational health snapshot: indexer statuses (invalid ones need a '
            . 'reindex), cron job activity for the last 24 hours with failed and stuck jobs, '
            . 'and cache types that are disabled or invalidated. Read-only — it reports '
            . 'problems but never fixes them (reindexing and cache flushes stay admin work). '
            . 'Typical use: "is the store healthy?", as part of a morning check.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    /**
     * Indexer/cron/cache state is system administration data.
     */
    public function getRequiredAclResource(): ?string
    {
        return 'Magento_Backend::system';
    }

    /**
     * @param array $arguments No arguments.
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        return [
            'indexers' => $this->indexerReport(),
            'cron_last_24h' => $this->cronReport(),
            'cache' => $this->cacheReport(),
        ];
    }

    /**
     * Every indexer with its status; "invalid" means the index is stale until a reindex.
     *
     * @return array<string, mixed>
     */
    private function indexerReport(): array
    {
        $items = [];
        $invalidCount = 0;
        foreach ($this->indexerCollectionFactory->create()->getItems() as $indexer) {
            $status = (string)$indexer->getStatus();
            if ($status !== 'valid') {
                $invalidCount++;
            }
            $items[] = [
                'id' => (string)$indexer->getId(),
                'title' => (string)$indexer->getTitle(),
                'status' => $status,
            ];
        }

        return [
            'total' => count($items),
            'not_valid_count' => $invalidCount,
            'items' => $items,
        ];
    }

    /**
     * Cron schedule rows of the last 24 hours: counts per status, the latest failed jobs
     * and jobs stuck in "running" for over 30 minutes.
     *
     * @return array<string, mixed>
     */
    private function cronReport(): array
    {
        $now = $this->dateTime->gmtTimestamp();
        $since = date('Y-m-d H:i:s', $now - self::CRON_WINDOW_SECONDS);
        $stuckBefore = date('Y-m-d H:i:s', $now - self::STUCK_RUNNING_SECONDS);

        $collection = $this->scheduleCollectionFactory->create();
        $collection->addFieldToFilter('scheduled_at', ['gteq' => $since]);
        $collection->setPageSize(self::CRON_SCAN_LIMIT);
        $collection->setCurPage(1);

        $byStatus = [
            Schedule::STATUS_PENDING => 0,
            Schedule::STATUS_RUNNING => 0,
            Schedule::STATUS_SUCCESS => 0,
            Schedule::STATUS_ERROR => 0,
            Schedule::STATUS_MISSED => 0,
        ];
        $errors = [];
        $stuck = [];
        foreach ($collection as $schedule) {
            $status = (string)$schedule->getData('status');
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            if ($status === Schedule::STATUS_ERROR && count($errors) < self::MAX_ERROR_SAMPLES) {
                $errors[] = [
                    'job_code' => (string)$schedule->getData('job_code'),
                    'executed_at' => (string)$schedule->getData('executed_at'),
                    'message' => mb_substr(
                        (string)$schedule->getData('messages'),
                        0,
                        self::ERROR_MESSAGE_MAX_LENGTH
                    ),
                ];
            }
            $executedAt = (string)$schedule->getData('executed_at');
            if ($status === Schedule::STATUS_RUNNING && $executedAt !== '' && $executedAt < $stuckBefore) {
                $stuck[] = [
                    'job_code' => (string)$schedule->getData('job_code'),
                    'executed_at' => $executedAt,
                ];
            }
        }

        return [
            'by_status' => $byStatus,
            'errors' => $errors,
            'stuck_running' => $stuck,
        ];
    }

    /**
     * Cache types that are switched off entirely and types flagged invalidated (stale
     * until the next flush).
     *
     * @return array<string, mixed>
     */
    private function cacheReport(): array
    {
        $disabled = [];
        $types = $this->cacheTypeList->getTypes();
        foreach ($types as $type) {
            if (!(bool)$type->getData('status')) {
                $disabled[] = (string)$type->getData('id');
            }
        }

        $invalidated = [];
        foreach ($this->cacheTypeList->getInvalidated() as $type) {
            $invalidated[] = (string)$type->getData('id');
        }

        return [
            'total_types' => count($types),
            'disabled' => $disabled,
            'invalidated' => $invalidated,
        ];
    }
}
