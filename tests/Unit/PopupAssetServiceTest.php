<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Tests\Unit;

use Calevans\StaticForgePopup\Services\PopupAssetService;
use Calevans\StaticForgePopup\Tests\TestCase;
use EICC\StaticForge\Core\AssetManager;

class PopupAssetServiceTest extends TestCase
{
    private PopupAssetService $service;
    private AssetManager $assetManager;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var AssetManager $assetManager */
        $assetManager = $this->container->get(AssetManager::class);
        $this->assetManager = $assetManager;
        $this->service = new PopupAssetService($this->logger, $this->assetManager);
    }

    public function testStylesheetUrlsReturnsOnlyTheBaseHandleWhenSourceDirIsUnset(): void
    {
        $urls = $this->service->stylesheetUrls($this->container, []);

        $this->assertSame(['popup-base' => '/assets/css/sf-popup.css'], $urls);
    }

    public function testStylesheetUrlsCascadesBaseThenUserThenPerPopup(): void
    {
        $sourceDir = $this->makeSourceDir();

        try {
            mkdir($sourceDir . '/assets/css', 0755, true);
            file_put_contents($sourceDir . '/assets/css/popup.css', '');
            file_put_contents($sourceDir . '/assets/css/news.css', '');
            $this->setContainerVariable('SOURCE_DIR', $sourceDir);

            $urls = $this->service->stylesheetUrls($this->container, ['news']);

            $this->assertSame(
                [
                    'popup-base' => '/assets/css/sf-popup.css',
                    'popup-user' => '/assets/css/popup.css',
                    'popup-news' => '/assets/css/news.css',
                ],
                $urls
            );
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    public function testPopupUserHandleIsAbsentWhenSiteWidePopupCssDoesNotExist(): void
    {
        $sourceDir = $this->makeSourceDir();

        try {
            $this->setContainerVariable('SOURCE_DIR', $sourceDir);

            $urls = $this->service->stylesheetUrls($this->container, []);

            $this->assertArrayNotHasKey('popup-user', $urls);
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    public function testPerPopupHandleIsAbsentWhenNoMatchingCssFileExists(): void
    {
        $sourceDir = $this->makeSourceDir();

        try {
            $this->setContainerVariable('SOURCE_DIR', $sourceDir);

            $urls = $this->service->stylesheetUrls($this->container, ['news']);

            $this->assertArrayNotHasKey('popup-news', $urls);
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    public function testRegisterAssetsRegistersStylesInCascadeOrderAndTheScriptHandle(): void
    {
        $sourceDir = $this->makeSourceDir();

        try {
            mkdir($sourceDir . '/assets/css', 0755, true);
            file_put_contents($sourceDir . '/assets/css/popup.css', '');
            $this->setContainerVariable('SOURCE_DIR', $sourceDir);

            $this->service->registerAssets($this->container, ['popup' => ['news']]);

            $styles = $this->assetManager->getStyles();
            $this->assertLessThan(
                strpos($styles, '/assets/css/popup.css'),
                strpos($styles, '/assets/css/sf-popup.css')
            );

            $scripts = $this->assetManager->getScripts(true);
            $this->assertStringContainsString('<script src="/assets/js/popup.js"></script>', $scripts);
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    public function testStylesheetUrlsIgnoresAPopupIdThatDoesNotMatchIdPatternEvenIfACallerForgotToValidateIt(): void
    {
        // Defense in depth: stylesheetUrls() is public, so it must stay safe
        // even if a future caller passes ids that were never run through
        // PopupIdValidator. Create a css file that WOULD match if the
        // traversal succeeded, to prove this isn't just a formatting nicety.
        $sourceDir = $this->makeSourceDir();

        try {
            mkdir($sourceDir . '/assets/css', 0755, true);
            file_put_contents($sourceDir . '/assets/css/evil.css', 'body{}');
            $this->setContainerVariable('SOURCE_DIR', $sourceDir);

            $urls = $this->service->stylesheetUrls($this->container, ['../evil']);

            $this->assertArrayNotHasKey('popup-../evil', $urls);
            $this->assertSame(['popup-base' => '/assets/css/sf-popup.css'], $urls);
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    public function testSiteConfigCssUrlOverrideIsHonored(): void
    {
        $this->setContainerVariable('site_config', [
            'popup' => ['css_url' => '/custom/popup.css'],
        ]);

        $urls = $this->service->stylesheetUrls($this->container, []);

        $this->assertSame(['popup-base' => '/custom/popup.css'], $urls);

        $this->service->registerAssets($this->container, []);
        $this->assertStringContainsString(
            '<link rel="stylesheet" href="/custom/popup.css">',
            $this->assetManager->getStyles()
        );
    }

    public function testSiteConfigJsUrlOverrideIsHonored(): void
    {
        $this->setContainerVariable('site_config', [
            'popup' => ['js_url' => '/custom/popup.js'],
        ]);

        $this->service->registerAssets($this->container, []);

        $this->assertStringContainsString(
            '<script src="/custom/popup.js"></script>',
            $this->assetManager->getScripts(true)
        );
    }

    public function testInsecureCssUrlOverrideIsRejectedAndFallsBackToTheDefault(): void
    {
        $this->setContainerVariable('site_config', [
            'popup' => ['css_url' => 'javascript:alert(1)'],
        ]);

        $this->logger->expects($this->once())->method('log')->with(
            'WARNING',
            $this->logicalAnd(
                $this->stringContains('popup.css_url'),
                $this->stringContains('javascript:alert(1)')
            )
        );

        $urls = $this->service->stylesheetUrls($this->container, []);

        $this->assertSame(['popup-base' => '/assets/css/sf-popup.css'], $urls);
    }

    public function testInsecureJsUrlOverrideIsRejectedAndFallsBackToTheDefault(): void
    {
        $this->setContainerVariable('site_config', [
            'popup' => ['js_url' => 'http://evil.example/popup.js'],
        ]);

        $this->logger->expects($this->once())->method('log')->with(
            'WARNING',
            $this->stringContains('popup.js_url')
        );

        $this->service->registerAssets($this->container, []);

        $this->assertStringContainsString(
            '<script src="/assets/js/popup.js"></script>',
            $this->assetManager->getScripts(true)
        );
    }

    public function testCopyAssetsCopiesBundledJsAndCssIntoOutputDir(): void
    {
        $outputDir = $this->makeOutputDir();

        try {
            $this->setContainerVariable('OUTPUT_DIR', $outputDir);

            $this->service->copyAssets($this->container);

            $this->assertFileExists($outputDir . '/assets/js/popup.js');
            $this->assertFileExists($outputDir . '/assets/css/sf-popup.css');
        } finally {
            $this->removeDirectory($outputDir);
        }
    }

    public function testMissingOutputDirLogsErrorAndDoesNotWriteAnything(): void
    {
        $this->logger->expects($this->once())->method('log')->with(
            'ERROR',
            $this->stringContains('OUTPUT_DIR')
        );

        $this->service->copyAssets($this->container);
    }

    public function testPublishAssetsFalseSkipsCopying(): void
    {
        $outputDir = $this->makeOutputDir();

        try {
            $this->setContainerVariable('OUTPUT_DIR', $outputDir);
            $this->setContainerVariable('site_config', ['popup' => ['publish_assets' => false]]);

            $this->service->copyAssets($this->container);

            $this->assertFileDoesNotExist($outputDir . '/assets/js/popup.js');
            $this->assertFileDoesNotExist($outputDir . '/assets/css/sf-popup.css');
        } finally {
            $this->removeDirectory($outputDir);
        }
    }

    private function makeSourceDir(): string
    {
        $sourceDir = sys_get_temp_dir() . '/staticforge_popup_assets_src_' . uniqid();
        mkdir($sourceDir, 0755, true);

        return (string) realpath($sourceDir);
    }

    private function makeOutputDir(): string
    {
        $outputDir = sys_get_temp_dir() . '/staticforge_popup_assets_out_' . uniqid();
        mkdir($outputDir, 0755, true);

        return (string) realpath($outputDir);
    }
}
