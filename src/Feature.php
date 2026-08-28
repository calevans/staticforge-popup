<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup;

use Calevans\StaticForgePopup\Services\PopupAssetService;
use Calevans\StaticForgePopup\Services\PopupIdValidator;
use Calevans\StaticForgePopup\Services\PopupInjector;
use Calevans\StaticForgePopup\Services\PopupRepository;
use EICC\StaticForge\Core\BaseFeature;
use EICC\StaticForge\Core\ConfigurableFeatureInterface;
use EICC\StaticForge\Core\Events\Event;
use EICC\StaticForge\Core\Events\EventListener;
use EICC\StaticForge\Core\Events\RenderEvent;
use EICC\StaticForge\Core\EventManager;
use EICC\StaticForge\Core\FeatureInterface;
use EICC\Utils\Log;

class Feature extends BaseFeature implements FeatureInterface, ConfigurableFeatureInterface
{
    protected string $name = 'Popup';

    public function __construct(
        private readonly PopupRepository $repository,
        private readonly PopupAssetService $assets,
        private readonly PopupInjector $injector,
        private readonly Log $logger,
    ) {
    }

    public function register(EventManager $eventManager): void
    {
        // Ensure dependencies are present
        if (!$this->requireFeatures($this->container, ['MarkdownRenderer'])) {
            return;
        }

        parent::register($eventManager);
    }

    #[EventListener('PRE_LOOP', priority: 100)]
    public function loadPopups(Event $event): void
    {
        $this->repository->load($this->container);
    }

    #[EventListener('PRE_RENDER', priority: 100)]
    public function registerAssets(RenderEvent $event): void
    {
        // Check if popups are requested
        if (!empty($event->metadata['popup'])) {
            $this->assets->registerAssets($this->container, $event->metadata);
        }
    }

    #[EventListener('POST_RENDER', priority: 100)]
    public function injectPopup(RenderEvent $event): void
    {
        $outputPath = $event->outputPath;

        if ($outputPath === null || pathinfo($outputPath, PATHINFO_EXTENSION) !== 'html') {
            return;
        }

        $requestedIds = PopupIdValidator::normalize(
            $event->metadata['popup'] ?? [],
            $this->logger,
            "page 'popup:' frontmatter"
        );
        if ($requestedIds === []) {
            return;
        }

        $popups = [];
        foreach ($requestedIds as $id) {
            $popup = $this->repository->get($id);
            if ($popup === null) {
                $this->logger->log('WARNING', "Popup '{$id}' requested but not found.");
                continue;
            }
            $popups[] = $popup;
        }

        // The stylesheet URLs are recomputed rather than carried over from
        // registerAssets(); PopupAssetService::stylesheetUrls() is pure, so
        // the two events cannot drift apart.
        $event->renderedContent = $this->injector->inject(
            $event->renderedContent ?? '',
            $popups,
            $this->assets->stylesheetUrls($this->container, $requestedIds),
        );
    }

    #[EventListener('POST_LOOP', priority: 100)]
    public function copyAssets(Event $event): void
    {
        $this->assets->copyAssets($this->container);
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
