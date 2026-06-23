<?php

namespace Doctrine\DBAL;

/**
 * Test stub for Doctrine\DBAL\LockMode. The real class ships with
 * doctrine/dbal, which isn't installed in the plugin's test vendor dir.
 */
class LockMode
{
    public const NONE = 0;
    public const OPTIMISTIC = 1;
    public const PESSIMISTIC_READ = 2;
    public const PESSIMISTIC_WRITE = 4;
}
