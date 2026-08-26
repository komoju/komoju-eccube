<?php

namespace Doctrine\DBAL\Exception;

/**
 * Stub for Doctrine's lock-wait-timeout exception. The plugin catches
 * this specifically in SessionReturnController::flushWithRetry; tests
 * throw an instance to exercise the retry path.
 */
class LockWaitTimeoutException extends \RuntimeException
{
}
