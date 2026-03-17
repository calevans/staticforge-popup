<?php

namespace Calevans\StaticForgePopup;

use EICC\StaticForge\Core\BaseFeature;
use EICC\StaticForge\Core\FeatureInterface;
use EICC\StaticForge\Core\ConfigurableFeatureInterface;
use EICC\StaticForge\Core\EventManager;
use EICC\StaticForge\Core\AssetManager;
use EICC\Utils\Container;
use EICC\StaticForge\Features\MarkdownRenderer\MarkdownProcessor;
use Calevans\StaticForgePopup\Services\PopupService;
use Calevans\StaticForgePopup\Services\PopupParser;

class Feature extends BaseFeature implements FeatureInterface, ConfigurableFeatureInterface
{
    protected string $name = 'Popup';
    private PopupService $service;

    protected array $eventListeners = [
        'PRE_LOOP' => ['method' => 'loadPopups', 'priority' => 100],
        'PRE_RENDER' => ['method' => 'registerAssets', 'priority' => 100],
        'POST_RENDER' => ['method' => 'injectPopup', 'priority' => 100],
        'POST_LOOP' => ['method' => 'copyAssets', 'priority' => 100]
    ];

    public function register(EventManager $eventManager): void
    {
        // Ensure dependencies are present
        if (!$this->requireFeatures($this->container, ['MarkdownRenderer'])) {
            return;
        }

        parent::register($eventManager);

        $logger = $this->container->get('logger');
        $twig = $this->container->get('twig');
        $assetManager = $this->container->get(AssetManager::class);

        // Initialize services
        $parser = new PopupParser(new MarkdownProcessor());
        $this->service = new PopupService($parser, $logger, $twig, $assetManager);
    }

    public function loadPopups(Container $container, array $data): array
    {
        $this->service->loadPopups($container);
        return $data;
    }

    public function registerAssets(Container $container, array $data): array
    {
        $metadata = $data['metadata'] ?? [];
        // Check if popups are requested
        if (!empty($metadata['popup'])) {
            $this->service->registerAssets($container, $metadata);
        }
        return $data;
    }

    public function injectPopup(Container $container, array $data): array
    {
        $outputPath = $data['output_path'] ?? null;

        if (!$outputPath || pathinfo($outputPath, PATHINFO_EXTENSION) !== 'html') {
            return $data;
        }

        $metadata = $data['metadata'] ?? $data['file_metadata'] ?? [];

        $data['rendered_content'] = $this->service->injectPopups($data['rendered_content'], $metadata, $container);

        return $data;
    }

    public function copyAssets(Container $container, array $data): array
    {
        $this->service->copyAssets($container);
        return $data;
    }

    public function getRequiredConfig(): array
    {
        return [];
    }

    public function getRequiredEnv(): array
    {
        return [];
    }
}
