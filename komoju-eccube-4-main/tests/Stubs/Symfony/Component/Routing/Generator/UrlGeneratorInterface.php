<?php

namespace Symfony\Component\Routing\Generator;

interface UrlGeneratorInterface
{
    const ABSOLUTE_URL = 0;
    const ABSOLUTE_PATH = 1;

    public function generate($name, $parameters = [], $referenceType = self::ABSOLUTE_PATH);
}
