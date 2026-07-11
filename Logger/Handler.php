<?php

declare(strict_types=1);

namespace Yu\McpServer\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    protected $fileName = '/var/log/mcp.log';

    /**
     * Kept at DEBUG so debug-level records (full request/response bodies, gated by
     * Yu\McpServer\Model\Config::isFullRequestLoggingEnabled()) are not dropped by the
     * handler itself — whether they're actually emitted is decided at the call site, not here.
     */
    protected $loggerType = Logger::DEBUG;
}
