<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Symfony gives every test request `Accept-Language: en-us`, which would
     * show visitor pages in English (SupportedLocales::resolve()). Send the
     * app's default language instead; a test can still override the header.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Accept-Language', 'ja');
    }
}
