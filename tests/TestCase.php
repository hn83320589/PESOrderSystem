<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 模擬由本站 SPA 發出的請求，API 才會走 Sanctum 的 session 認證
        $this->withHeader('Referer', config('app.url'));
    }
}
