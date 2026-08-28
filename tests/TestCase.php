<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Tests;

use EICC\StaticForge\Core\AssetManager;
use EICC\StaticForge\Core\FeatureManager;
use EICC\StaticForge\Features\MarkdownRenderer\MarkdownProcessor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase as BaseTestCase;
use EICC\Utils\Container;
use EICC\Utils\Log;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class TestCase extends BaseTestCase
{
    protected Container $container;
    protected Log&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
        $this->logger = $this->createMock(Log::class);
        $this->container->stuff('logger', $this->logger);
        $this->container->stuff('twig', new Environment(new ArrayLoader([
            'popup.html.twig' => '<div id="sf-popup-{{ popup.metadata.id }}">{{ popup.content | raw }}</div>',
            // The blank line is deliberate: it is what CommonMark would treat
            // as the end of the form's HTML block if the form were spliced
            // into markdown without normalisation.
            '_popup_form.html.twig' => "<form action=\"{{ endpoint }}\" method=\"POST\""
                . "{% if assume_success %} data-assume-success=\"1\"{% endif %}>\n\n"
                . "    <button type=\"submit\">{{ submit_text }}</button>\n</form>\n",
        ])));
        $this->container->add(AssetManager::class, new AssetManager());
        $this->container->stuff(MarkdownProcessor::class, new MarkdownProcessor());

        // requireFeatures() needs a FeatureManager that reports every feature enabled
        $featureManager = $this->createMock(FeatureManager::class);
        $featureManager->method('isFeatureEnabled')->willReturn(true);
        $this->container->stuff(FeatureManager::class, $featureManager);
    }

    protected function setContainerVariable(string $key, mixed $value): void
    {
        if ($this->container->hasVariable($key)) {
            $this->container->updateVariable($key, $value);
        } else {
            $this->container->setVariable($key, $value);
        }
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff((array) scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
