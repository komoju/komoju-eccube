<?php

namespace Tests\Komoju42\Entity;

use Plugin\Komoju42\Entity\KomojuConfig;
use PHPUnit\Framework\TestCase;

class KomojuConfigTest extends TestCase
{
    public function testCaptureOnDefault()
    {
        $config = new KomojuConfig();
        $this->assertFalse($config->isCaptureOn());
    }

    public function testCaptureOnTrue()
    {
        $config = new KomojuConfig();
        $config->setCaptureOn(true);
        $this->assertTrue($config->isCaptureOn());
    }

    public function testLoggingEnabledDefault()
    {
        $config = new KomojuConfig();
        $this->assertTrue($config->isLoggingEnabled());
    }

    public function testLoggingDisabled()
    {
        $config = new KomojuConfig();
        $config->setLoggingEnabled(false);
        $this->assertFalse($config->isLoggingEnabled());
    }
}
