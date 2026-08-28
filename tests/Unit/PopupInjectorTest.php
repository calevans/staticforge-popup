<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Tests\Unit;

use Calevans\StaticForgePopup\Models\PopupDefinition;
use Calevans\StaticForgePopup\Services\PopupInjector;
use Calevans\StaticForgePopup\Tests\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class PopupInjectorTest extends TestCase
{
    /**
     * @param array<string, mixed> $metadataExtra
     */
    private function makePopup(string $id = 'news', array $metadataExtra = []): PopupDefinition
    {
        return new PopupDefinition(
            id: $id,
            content: '<p>Hello.</p>',
            metadata: array_merge(['id' => $id], $metadataExtra),
            exitIntent: false,
            timerSeconds: 0,
            blockedDays: 30,
            urlOverride: null,
        );
    }

    private function makeInjector(): PopupInjector
    {
        /** @var Environment $twig */
        $twig = $this->container->get('twig');

        return new PopupInjector($this->logger, $twig, $this->container);
    }

    public function testPopupIsSplicedBeforeTheLastClosingBodyTagNotTheFirst(): void
    {
        $html = '<html><head></head><body>'
            . '<pre>&lt;/body&gt; inside a code sample</pre>'
            . '</body></html>';

        $injector = $this->makeInjector();
        $result = $injector->inject($html, [$this->makePopup()], []);

        // Only one real </body> exists in this document (the other is HTML-escaped
        // text), so the popup must land immediately before it.
        $this->assertStringContainsString('sf-popup-news', $result);
        $bodyPos = strrpos($result, '</body>');
        $popupPos = strpos($result, 'sf-popup-news');
        $this->assertNotFalse($bodyPos);
        $this->assertNotFalse($popupPos);
        $this->assertLessThan($bodyPos, $popupPos);
    }

    public function testPopupIsSplicedBeforeTheLastOfMultipleClosingBodyTags(): void
    {
        // Two literal </body> occurrences; only the last is the real close.
        $html = '<html><head></head><body>First </body> fragment, then <body>second</body></html>';

        $injector = $this->makeInjector();
        $result = $injector->inject($html, [$this->makePopup()], []);

        $lastBodyPos = strrpos($result, '</body>');
        $popupPos = strpos($result, 'sf-popup-news');
        $this->assertNotFalse($lastBodyPos);
        $this->assertNotFalse($popupPos);
        $this->assertLessThan($lastBodyPos, $popupPos);

        // Exactly one </body> should remain after injection (the original last
        // one, now preceded by the popup markup).
        $this->assertSame(2, substr_count($result, '</body>'));
    }

    public function testContentIsAppendedRatherThanDroppedWhenThereIsNoClosingBodyTag(): void
    {
        $html = 'no body or head tag here';

        $injector = $this->makeInjector();
        $result = $injector->inject($html, [$this->makePopup()], []);

        $this->assertStringContainsString('sf-popup-news', $result);
        $this->assertStringStartsWith($html, $result);
        $this->assertGreaterThan(
            (int) strpos($result, 'no body or head tag here'),
            (int) strpos($result, 'sf-popup-news')
        );
    }

    public function testStylesheetLinksAreInjectedBeforeClosingHeadInCascadeOrderEachOnlyOnce(): void
    {
        $html = '<html><head></head><body></body></html>';
        $stylesheetUrls = [
            'popup-base' => '/assets/css/sf-popup.css',
            'popup-user' => '/assets/css/popup.css',
        ];

        $injector = $this->makeInjector();
        $result = $injector->inject($html, [$this->makePopup()], $stylesheetUrls);

        $this->assertSame(1, substr_count($result, 'href="/assets/css/sf-popup.css"'));
        $this->assertSame(1, substr_count($result, 'href="/assets/css/popup.css"'));
        $this->assertLessThan(
            (int) strpos($result, 'href="/assets/css/popup.css"'),
            (int) strpos($result, 'href="/assets/css/sf-popup.css"')
        );
        $this->assertLessThan((int) strpos($result, '</head>'), (int) strpos($result, 'sf-popup.css'));
    }

    public function testStylesheetUrlAlreadyPresentInHtmlIsSkippedEntirely(): void
    {
        $html = '<html><head><link rel="stylesheet" href="/assets/css/sf-popup.css"></head><body></body></html>';
        $stylesheetUrls = ['popup-base' => '/assets/css/sf-popup.css'];

        $injector = $this->makeInjector();
        $result = $injector->inject($html, [$this->makePopup()], $stylesheetUrls);

        $this->assertSame(1, substr_count($result, 'href="/assets/css/sf-popup.css"'));
    }

    public function testNoClosingHeadTagLogsWarningAndSkipsStylesheetInjection(): void
    {
        // Suppress the (separately covered) jQuery injection warning so this
        // test's single-call expectation isolates the stylesheet-injection path.
        $this->setContainerVariable('site_config', ['popup' => ['load_jquery' => false]]);
        $html = '<html><body></body></html>';

        $this->logger->expects($this->once())->method('log')->with(
            'WARNING',
            $this->stringContains('Cannot inject popup stylesheets: no </head> tag found')
        );

        $injector = $this->makeInjector();
        $result = $injector->inject($html, [$this->makePopup()], ['popup-base' => '/assets/css/sf-popup.css']);

        $this->assertStringNotContainsString('sf-popup.css', $result);
    }

    public function testJqueryTagCarriesIntegrityAndCrossoriginByDefault(): void
    {
        $html = '<html><head></head><body></body></html>';

        $injector = $this->makeInjector();
        $result = $injector->inject($html, [$this->makePopup()], []);

        $this->assertMatchesRegularExpression(
            '#<script src="https://code\.jquery\.com/jquery-3\.7\.1\.min\.js"'
                . ' integrity="sha384-[^"]+" crossorigin="anonymous"></script>#',
            $result
        );
    }

    public function testJqueryIsNotInjectedWhenAlreadyPresentInTheDocument(): void
    {
        $html = '<html><head><script src="/vendor/jquery.js"></script></head><body></body></html>';

        $injector = $this->makeInjector();
        $result = $injector->inject($html, [$this->makePopup()], []);

        $this->assertSame(1, substr_count($result, 'jquery'));
    }

    public function testJqueryTagIsInsertedOnlyOnceEvenWhenBodyContainsALiteralClosingHeadTag(): void
    {
        // A page whose body legitimately contains the literal text "</head>"
        // (e.g. an HTML tutorial) must not get a second, mid-body jQuery tag.
        $html = '<html><head></head><body>Tutorial: to close head, write </head> like this.</body></html>';

        $injector = $this->makeInjector();
        $result = $injector->inject($html, [$this->makePopup()], []);

        $this->assertSame(1, substr_count($result, '<script src="https://code.jquery.com'));
        $this->assertStringContainsString('write </head> like this.', $result);
    }

    public function testEmptyJqueryIntegrityLogsAnUnpinnedWarningWhenUrlIsRemote(): void
    {
        // An explicit '' and a YAML typo (null/non-string) both resolve to the
        // same empty string; either way the operator should be told the script
        // is unpinned rather than silently shipping it with no integrity hash.
        $this->setContainerVariable('site_config', ['popup' => ['jquery_integrity' => '']]);
        $html = '<html><head></head><body></body></html>';

        $this->logger->expects($this->once())->method('log')->with(
            'WARNING',
            $this->logicalAnd(
                $this->stringContains('code.jquery.com'),
                $this->stringContains('unpinned')
            )
        );

        $injector = $this->makeInjector();
        $result = $injector->inject($html, [$this->makePopup()], []);

        $this->assertStringContainsString(
            '<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>',
            $result
        );
    }

    public function testRootRelativeJqueryUrlWithNoIntegrityDoesNotWarn(): void
    {
        // Root-relative assets are same-origin; there is nothing to pin.
        $this->setContainerVariable(
            'site_config',
            ['popup' => ['jquery_url' => '/vendor/jquery.js', 'jquery_integrity' => '']]
        );
        $html = '<html><head></head><body></body></html>';

        $this->logger->expects($this->never())->method('log');

        $injector = $this->makeInjector();
        $injector->inject($html, [$this->makePopup()], []);
    }

    public function testLoadJqueryFalseSuppressesTheJqueryTag(): void
    {
        $this->setContainerVariable('site_config', ['popup' => ['load_jquery' => false]]);
        $html = '<html><head></head><body></body></html>';

        $injector = $this->makeInjector();
        $result = $injector->inject($html, [$this->makePopup()], []);

        $this->assertStringNotContainsString('jquery', $result);
    }

    public function testPerPopupTemplateIsPreferredWhenTheLoaderHasIt(): void
    {
        $twig = new Environment(new ArrayLoader([
            'popup.html.twig' => '<div class="generic">{{ popup.content|raw }}</div>',
            'news.html.twig' => '<div class="specific-news">{{ popup.content|raw }}</div>',
        ]));
        $injector = new PopupInjector($this->logger, $twig, $this->container);

        $html = '<html><head></head><body></body></html>';
        $result = $injector->inject($html, [$this->makePopup('news')], []);

        $this->assertStringContainsString('specific-news', $result);
        $this->assertStringNotContainsString('generic', $result);
    }

    public function testFallsBackToGenericTemplateWhenNoPerPopupTemplateExists(): void
    {
        $twig = new Environment(new ArrayLoader([
            'popup.html.twig' => '<div class="generic">{{ popup.content|raw }}</div>',
        ]));
        $injector = new PopupInjector($this->logger, $twig, $this->container);

        $html = '<html><head></head><body></body></html>';
        $result = $injector->inject($html, [$this->makePopup('news')], []);

        $this->assertStringContainsString('generic', $result);
    }

    public function testTwigRenderErrorIsCaughtAndLoggedAsErrorWithoutKillingTheBuild(): void
    {
        $twig = new Environment(new ArrayLoader([
            'popup.html.twig' => '{{ popup.content|raw }} {{ this_function_does_not_exist() }}',
        ]));
        $injector = new PopupInjector($this->logger, $twig, $this->container);

        $this->logger->expects($this->once())->method('log')->with(
            'ERROR',
            $this->stringContains("Failed to render popup 'news'")
        );

        $html = '<html><head></head><body></body></html>';
        $result = $injector->inject($html, [$this->makePopup('news')], []);

        $this->assertSame($html, $result);
    }

    public function testJsonEncodeHardeningPreventsScriptTagFromBreakingOutOfTheScriptBlock(): void
    {
        // The injected sfPopups config is built from a fixed shape (id/exit_intent/
        // timer/blocked_days), not raw metadata, so we drive the dangerous string
        // through the id itself to make sure json_encode's HEX flags do their job.
        $dangerousPopup = new PopupDefinition(
            id: '</script><script>alert(1)</script>',
            content: '<p>Hello.</p>',
            metadata: ['id' => 'news'],
            exitIntent: false,
            timerSeconds: 0,
            blockedDays: 30,
            urlOverride: null,
        );

        $twig = new Environment(new ArrayLoader([
            'popup.html.twig' => '<div>{{ popup.content|raw }}</div>',
        ]));
        $injector = new PopupInjector($this->logger, $twig, $this->container);

        $html = '<html><head></head><body></body></html>';
        $result = $injector->inject($html, [$dangerousPopup], []);

        $scriptStart = strpos($result, '<script type="application/json" id="sf-popups">');
        $this->assertNotFalse($scriptStart);
        $payloadStart = $scriptStart + strlen('<script type="application/json" id="sf-popups">');
        $scriptEnd = strpos($result, '</script>', $payloadStart);
        $this->assertNotFalse($scriptEnd);
        $payload = substr($result, $payloadStart, $scriptEnd - $payloadStart);

        $this->assertStringNotContainsString('</script>', $payload);
    }

    public function testConfigIsEmittedAsAJsonScriptTagNotAnInlineAssignment(): void
    {
        $html = '<html><head></head><body></body></html>';

        $injector = $this->makeInjector();
        $result = $injector->inject($html, [$this->makePopup()], []);

        $this->assertStringContainsString('<script type="application/json" id="sf-popups">', $result);
        $this->assertStringNotContainsString('window.sfPopups =', $result);
    }
}
