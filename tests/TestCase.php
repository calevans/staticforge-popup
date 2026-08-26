<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Tests;

use EICC\StaticForge\Core\AssetManager;
use EICC\StaticForge\Core\FeatureManager;
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
        $this->container->stuff('twig', new Environment(new ArrayLoader([])));
        $this->container->add(AssetManager::class, new AssetManager());

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
}
