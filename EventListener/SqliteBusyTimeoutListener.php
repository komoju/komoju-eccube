<?php

namespace Plugin\Komoju42\EventListener;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Platforms\SqlitePlatform;

/**
 * Sets SQLite's busy_timeout immediately after every new connection so that
 * concurrent writes wait up to 30 seconds instead of failing immediately.
 *
 * Implemented as a DBAL middleware (doctrine.middleware tag in services.yaml)
 * rather than the deprecated postConnect event (DBAL 3.x logs a deprecation;
 * DBAL 4.0 removes the event entirely).
 */
class SqliteBusyTimeoutListener implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): DriverConnection
            {
                $conn = parent::connect($params);
                if ($this->getDatabasePlatform() instanceof SqlitePlatform) {
                    $conn->exec('PRAGMA busy_timeout = 30000');
                }
                return $conn;
            }
        };
    }
}
