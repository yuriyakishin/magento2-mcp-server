<?php

declare(strict_types=1);

namespace Yu\McpServer\Model\Tools\Sales;

/**
 * Shared date-argument validation for the sales tools. Pure functions over the raw
 * arguments array — no state, no dependencies — so the tools stay individually testable
 * without duplicating the same regex four times.
 */
class DateRange
{
    /**
     * Validates a required date argument: "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS".
     */
    public static function requiredDate(array $arguments, string $key): string
    {
        $value = self::optionalDate($arguments, $key);
        if ($value === null) {
            throw new \InvalidArgumentException(sprintf('Argument "%s" is required.', $key));
        }

        return $value;
    }

    /**
     * Validates an optional date argument. A date-only upper bound ("*_to" keys) is
     * expanded to the end of that day so "whole day included" semantics match
     * sales_summary.
     */
    public static function optionalDate(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)
            || !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value)
            || strtotime($value) === false
        ) {
            throw new \InvalidArgumentException(
                sprintf('Argument "%s" must be a valid "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS" date.', $key)
            );
        }
        if (strlen($value) === 10 && str_ends_with($key, '_to')) {
            $value .= ' 23:59:59';
        }

        return $value;
    }
}
