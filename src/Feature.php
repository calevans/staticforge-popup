<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup;

use EICC\StaticForge\Core\BaseFeature;
use EICC\StaticForge\Core\FeatureInterface;
use EICC\StaticForge\Core\ConfigurableFeatureInterface;
use EICC\StaticForge\Core\Events\Event;
use EICC\StaticForge\Core\Events\EventListener;
use EICC\StaticForge\Core\Events\RenderEvent;
use EICC\StaticForge\Core\EventManager;
use EICC\StaticForge\Core\AssetManager;
use EICC\StaticForge\Features\MarkdownRenderer\MarkdownProcessor;
use Calevans\StaticForgePopup\Services\PopupService;
use Calevans\StaticForgePopup\Services\PopupParser;

class Feature extends BaseFeature implements FeatureInterface, ConfigurableFeatureInterface
{
    protected string $name = 'Popup';
    private PopupService $service;

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

    #[EventListener('PRE_LOOP', priority: 100)]
    public function loadPopups(Event $event): void
    {
        $this->service->loadPopups($this->container);
    }

    #[EventListener('PRE_RENDER', priority: 100)]
    public function registerAssets(RenderEvent $event): void
    {
        // Check if popups are requested
        if (!empty($event->metadata['popup'])) {
            $this->service->registerAssets($this->container, $event->metadata);
        }
    }

    #[EventListener('POST_RENDER', priority: 100)]
    public function injectPopup(RenderEvent $event): void
    {
        $outputPath = $event->outputPath;

        if ($outputPath === null || pathinfo($outputPath, PATHINFO_EXTENSION) !== 'html') {
            return;
        }

        $event->renderedContent = $this->service->injectPopups(
            $event->renderedContent ?? '',
            $event->metadata,
            $this->container
        );
    }

    #[EventListener('POST_LOOP', priority: 100)]
    public function copyAssets(Event $event): void
    {
        $this->service->copyAssets($this->container);
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
