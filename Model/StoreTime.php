<?php

declare(strict_types=1);

namespace Yu\McpServer\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Converts between the store's local time and UTC. Magento stores timestamps in UTC, while
 * a merchant's "yesterday" or "2026-03-01" means their own calendar day — so every date a
 * tool accepts is read as store-local time and every timestamp it returns is store-local
 * too, keeping both sides of a tool call in the same clock.
 */
class StoreTime
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * The store's configured timezone identifier (e.g. "Europe/Berlin").
     */
    public function getTimezone(): string
    {
        return (string)$this->timezone->getConfigTimezone() ?: 'UTC';
    }

    /**
     * Converts a store-local "Y-m-d H:i:s" (or "Y-m-d") value into UTC for DB filtering.
     *
     * @param string $localDateTime Store-local date/time.
     */
    public function toUtc(string $localDateTime): string
    {
        $date = new \DateTimeImmutable($localDateTime, $this->storeZone());

        return $date->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT);
    }

    /**
     * Converts a UTC timestamp from the DB into store-local "Y-m-d H:i:s". Empty values are
     * returned unchanged.
     *
     * @param string|null $utcDateTime UTC date/time as stored by Magento.
     */
    public function toLocal(?string $utcDateTime): ?string
    {
        if ($utcDateTime === null || $utcDateTime === '') {
            return $utcDateTime;
        }

        $date = new \DateTimeImmutable($utcDateTime, new \DateTimeZone('UTC'));

        return $date->setTimezone($this->storeZone())->format(self::FORMAT);
    }

    /**
     * Store-local calendar date ("Y-m-d") of a Unix timestamp.
     *
     * @param int $timestamp Unix timestamp.
     */
    public function localDate(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->storeZone())->format('Y-m-d');
    }

    /**
     * The store's timezone as a DateTimeZone.
     */
    private function storeZone(): \DateTimeZone
    {
        return new \DateTimeZone($this->getTimezone());
    }
}
