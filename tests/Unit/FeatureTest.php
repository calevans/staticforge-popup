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

    public function testLoadPopupsDoesNotThrowWithoutSourceDir(): void
    {
        // getcwd()/content almost certainly doesn't exist in the test runner;
        // PopupService::loadPopups() should just no-op rather than error.
        $this->logger->expects($this->never())->method('log');

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

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
