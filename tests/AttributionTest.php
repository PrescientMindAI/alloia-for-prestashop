<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/AlloiaAttribution.php';

final class PrestaShopAlloiaAttributionTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_GET['utm_source'], $_SERVER['HTTP_REFERER']);
        parent::tearDown();
    }

    public function testDetectsUtmSource(): void
    {
        $_GET['utm_source'] = 'alloia_ai';
        $this->assertTrue(AlloiaAttribution::isAiAttributedRequest());
    }

    public function testDetectsAiReferrer(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://perplexity.ai/search?q=test';
        $this->assertTrue(AlloiaAttribution::isAiAttributedRequest());
    }

    public function testRejectsUnrelatedTraffic(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://example.com/';
        $this->assertFalse(AlloiaAttribution::isAiAttributedRequest());
    }

    public function testAppendUtm(): void
    {
        $url = AlloiaAttribution::appendUtm('https://shop.test/cart');
        $this->assertStringContainsString('utm_source=alloia_ai', $url);
    }
}
