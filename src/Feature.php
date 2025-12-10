<?php

namespace Calevans\StaticForgePopup;

use EICC\StaticForge\Core\BaseFeature;
use EICC\StaticForge\Core\FeatureInterface;
use EICC\StaticForge\Core\EventManager;
use EICC\Utils\Container;
use EICC\StaticForge\Features\MarkdownRenderer\MarkdownProcessor;
use Calevans\StaticForgePopup\Services\PopupService;
use Calevans\StaticForgePopup\Services\PopupParser;

class Feature extends BaseFeature implements FeatureInterface
{
    protected string $name = 'Popup';
    private PopupService $service;

    protected array $eventListeners = [
        'PRE_LOOP' => ['method' => 'loadPopups', 'priority' => 100],
        'POST_RENDER' => ['method' => 'injectPopup', 'priority' => 100]
    ];

    public function register(EventManager $eventManager, Container $container): void
    {
        parent::register($eventManager, $container);

        // Ensure dependencies are present
        $this->requireFeatures(['MarkdownRenderer']);

        $logger = $container->get('logger');
        $twig = $container->get('twig');

        // Initialize services
        $parser = new PopupParser(new MarkdownProcessor());
        $this->service = new PopupService($parser, $logger, $twig);
    }

    public function loadPopups(Container $container, array $data): array
    {
        $this->service->loadPopups($container);
        return $data;
    }

    public function injectPopup(Container $container, array $data): array
    {
        $outputPath = $data['output_path'] ?? null;

        if (!$outputPath || pathinfo($outputPath, PATHINFO_EXTENSION) !== 'html') {
            return $data;
        }

        $metadata = $data['metadata'] ?? $data['file_metadata'] ?? [];

        $data['rendered_content'] = $this->service->injectPopups($data['rendered_content'], $metadata);

        return $data;
    }
}
