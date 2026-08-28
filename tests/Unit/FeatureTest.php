<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Tests\Unit;

use Calevans\StaticForgePopup\Feature;
use Calevans\StaticForgePopup\Tests\TestCase;
use EICC\StaticForge\Core\AssetManager;
use EICC\StaticForge\Core\Events\Event;
use EICC\StaticForge\Core\Events\RenderEvent;
use EICC\StaticForge\Core\EventManager;
use EICC\StaticForge\Core\FeatureFactory;
use EICC\StaticForge\Core\FeatureManager;
use EICC\Utils\Container;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class FeatureTest extends TestCase
{
    private Feature $feature;
    private EventManager $eventManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventManager = new EventManager();

        $feature = (new FeatureFactory($this->container))->make(Feature::class);
        $this->assertInstanceOf(Feature::class, $feature);
        $feature->setContainer($this->container);
        $this->feature = $feature;
        $this->feature->register($this->eventManager);
    }

    public function testRegisterRegistersAllFourListeners(): void
    {
        $this->assertNotEmpty($this->eventManager->getListeners('PRE_LOOP'));
        $this->assertNotEmpty($this->eventManager->getListeners('PRE_RENDER'));
        $this->assertNotEmpty($this->eventManager->getListeners('POST_RENDER'));
        $this->assertNotEmpty($this->eventManager->getListeners('POST_LOOP'));
    }

    public function testRegisterSkipsWhenMarkdownRendererDisabled(): void
    {
        $container = new Container();
        $container->stuff('logger', $this->logger);
        $container->stuff('twig', new Environment(new ArrayLoader([])));
        $container->add(AssetManager::class, new AssetManager());

        $featureManager = $this->createMock(FeatureManager::class);
        $featureManager->method('isFeatureEnabled')->willReturn(false);
        $container->stuff(FeatureManager::class, $featureManager);

        $eventManager = new EventManager();
        $feature = (new FeatureFactory($container))->make(Feature::class);
        $this->assertInstanceOf(Feature::class, $feature);
        $feature->setContainer($container);
        $feature->register($eventManager);

        $this->assertEmpty($eventManager->getListeners('PRE_LOOP'));
    }

    public function testRegisterAssetsDoesNothingWithoutPopupMetadata(): void
    {
        $event = new RenderEvent(
            name: 'PRE_RENDER',
            filePath: 'content/page.md',
            fileUrl: '',
            metadata: ['title' => 'Regular Page'],
        );

        // Should not throw even though no popup is requested
        $this->feature->registerAssets($event);
        $this->assertSame(['title' => 'Regular Page'], $event->metadata);
    }

    public function testInjectPopupSkipsNonHtmlOutput(): void
    {
        $event = new RenderEvent(
            name: 'POST_RENDER',
            filePath: 'content/style.css',
            fileUrl: '',
            metadata: [],
            renderedContent: 'body { color: red; }',
            outputPath: '/output/style.css',
        );

        $this->feature->injectPopup($event);

        $this->assertSame('body { color: red; }', $event->renderedContent);
    }

    public function testInjectPopupSkipsWhenNoOutputPath(): void
    {
        $event = new RenderEvent(
            name: 'POST_RENDER',
            filePath: 'content/page.md',
            fileUrl: '',
            metadata: [],
            renderedContent: '<html></html>',
        );

        $this->feature->injectPopup($event);

        $this->assertSame('<html></html>', $event->renderedContent);
    }

    public function testInjectPopupLeavesContentUnchangedWhenNoPopupRequested(): void
    {
        $event = new RenderEvent(
            name: 'POST_RENDER',
            filePath: 'content/page.md',
            fileUrl: '',
            metadata: [],
            renderedContent: '<html><body>Hello</body></html>',
            outputPath: '/output/page.html',
        );

        $this->feature->injectPopup($event);

        $this->assertSame('<html><body>Hello</body></html>', $event->renderedContent);
    }

    public function testRegisterAssetsRejectsAMaliciousPopupIdAndNeverRegistersAStyleHandleForIt(): void
    {
        $sourceDir = sys_get_temp_dir() . '/staticforge_popup_malicious_src_' . uniqid();
        mkdir($sourceDir . '/assets/css', 0755, true);

        // A file that WOULD be picked up by stylesheetUrls() if the malicious id
        // reached the filesystem check verbatim, proving this is a genuine
        // path-injection risk and not merely a missing-escaping cosmetic issue.
        $maliciousId = '../evil" onload="alert(1)';
        file_put_contents($sourceDir . '/assets/css/' . $maliciousId . '.css', 'body{}');

        try {
            $this->setContainerVariable('SOURCE_DIR', (string) realpath($sourceDir));

            $this->logger->expects($this->once())->method('log')->with(
                'ERROR',
                $this->stringContains("Invalid popup id '{$maliciousId}'")
            );

            $event = new RenderEvent(
                name: 'PRE_RENDER',
                filePath: 'content/page.md',
                fileUrl: '',
                metadata: ['popup' => [$maliciousId]],
            );

            $this->feature->registerAssets($event);

            /** @var AssetManager $assetManager */
            $assetManager = $this->container->get(AssetManager::class);
            $styles = $assetManager->getStyles();
            $this->assertStringNotContainsString('onload', $styles);
            $this->assertStringNotContainsString('alert', $styles);
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    public function testInjectPopupRejectsAMaliciousPopupIdAndLeavesRenderedHtmlUntouched(): void
    {
        $maliciousId = '../../evil"><script>alert(1)</script>';
        $originalHtml = '<html><head></head><body></body></html>';

        $this->logger->expects($this->once())->method('log')->with(
            'ERROR',
            $this->stringContains("Invalid popup id '{$maliciousId}'")
        );

        $event = new RenderEvent(
            name: 'POST_RENDER',
            filePath: 'content/page.md',
            fileUrl: '',
            metadata: ['popup' => [$maliciousId]],
            renderedContent: $originalHtml,
            outputPath: '/output/page.html',
        );

        $this->feature->injectPopup($event);

        $this->assertSame($originalHtml, $event->renderedContent);
    }

    public function testLoadPopupsDoesNotThrowWithoutSourceDir(): void
    {
        // SOURCE_DIR is unset in the test container; PopupRepository::load()
        // should log a warning and no-op rather than error.
        $this->logger->expects($this->once())->method('log')->with(
            'WARNING',
            $this->stringContains('SOURCE_DIR')
        );

        $this->feature->loadPopups(new Event('PRE_LOOP'));
    }

    public function testCopyAssetsCreatesAssetDirsUnderOutputDir(): void
    {
        $outputDir = sys_get_temp_dir() . '/staticforge_popup_test_' . uniqid();
        mkdir($outputDir, 0755, true);
        $this->setContainerVariable('OUTPUT_DIR', $outputDir);

        try {
            $this->feature->copyAssets(new Event('POST_LOOP'));

            $this->assertDirectoryExists($outputDir . '/assets/js');
            $this->assertDirectoryExists($outputDir . '/assets/css');
        } finally {
            $this->removeDirectory($outputDir);
        }
    }

    public function testInjectPopupAddsStylesheetLinksInCascadeOrder(): void
    {
        $sourceDir = $this->makeSourceDir();

        try {
            $event = $this->renderPage($sourceDir, '<html><head>' .
                '<link rel="stylesheet" href="/assets/css/main.css"></head><body></body></html>');
            $html = (string) $event->renderedContent;

            $this->assertSame(1, substr_count($html, '<link rel="stylesheet" href="/assets/css/sf-popup.css">'));
            $this->assertSame(1, substr_count($html, '<link rel="stylesheet" href="/assets/css/popup.css">'));
            $this->assertLessThan(
                strpos($html, 'href="/assets/css/popup.css"'),
                strpos($html, 'href="/assets/css/sf-popup.css"'),
            );
            $this->assertLessThan(strpos($html, '</head>'), strpos($html, 'href="/assets/css/sf-popup.css"'));
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    public function testInjectPopupDoesNotDuplicateStylesheetLinksTheTemplateAlreadyEmitted(): void
    {
        $sourceDir = $this->makeSourceDir();

        try {
            $event = $this->renderPage($sourceDir, '<html><head>' .
                '<link rel="stylesheet" href="/assets/css/sf-popup.css">' .
                '<link rel="stylesheet" href="/assets/css/popup.css"></head><body></body></html>');
            $html = (string) $event->renderedContent;

            $this->assertSame(1, substr_count($html, 'href="/assets/css/sf-popup.css"'));
            $this->assertSame(1, substr_count($html, 'href="/assets/css/popup.css"'));
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    public function testExpandedFormIsNotWrappedInAParagraph(): void
    {
        $sourceDir = $this->makeSourceDir();

        try {
            $event = $this->renderPage($sourceDir, '<html><head></head><body></body></html>');
            $html = (string) $event->renderedContent;

            $this->assertStringContainsString('<form action="https://forms.example.com/subscribe', $html);
            $this->assertStringNotContainsString('<p><form', $html);
            $this->assertStringNotContainsString('</form>' . PHP_EOL . '</p>', $html);
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    /**
     * A SOURCE_DIR holding one popup whose body is a standalone form
     * shortcode, plus the optional site-level popup.css.
     */
    private function makeSourceDir(): string
    {
        $sourceDir = sys_get_temp_dir() . '/staticforge_popup_src_' . uniqid();
        mkdir($sourceDir . '/assets/css', 0755, true);
        file_put_contents($sourceDir . '/assets/css/popup.css', '');
        file_put_contents(
            $sourceDir . '/news.popup',
            "---\npopup_enabled: true\nid: news\n---\n\nHello.\n\n{{ form('newsletter') }}\n"
        );

        return (string) realpath($sourceDir);
    }

    private function renderPage(string $sourceDir, string $renderedContent): RenderEvent
    {
        $this->setContainerVariable('SOURCE_DIR', $sourceDir);
        $this->setContainerVariable('site_config', [
            'forms' => [
                'newsletter' => [
                    'provider_url' => 'https://forms.example.com/subscribe',
                    'fields' => [['name' => 'email', 'type' => 'email', 'label' => 'Email']],
                ],
            ],
        ]);

        $this->feature->loadPopups(new Event('PRE_LOOP'));

        $event = new RenderEvent(
            name: 'POST_RENDER',
            filePath: 'content/page.md',
            fileUrl: '',
            metadata: ['popup' => ['news']],
            renderedContent: $renderedContent,
            outputPath: '/output/page.html',
        );

        $this->feature->injectPopup($event);

        return $event;
    }
}
