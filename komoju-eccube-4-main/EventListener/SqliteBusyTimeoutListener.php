<?php

namespace Plugin\Komoju\EventListener;

use Doctrine\DBAL\Event\ConnectionEventArgs;
use Doctrine\DBAL\Platforms\SqlitePlatform;

class SqliteBusyTimeoutListener
{
    public function postConnect(ConnectionEventArgs $event){
        $conn = $event->getConnection();
        if ($conn->getDatabasePlatform() instanceof SqlitePlatform) {
            $conn->executeStatement('PRAGMA busy_timeout = 5000');
        }
    }
}
