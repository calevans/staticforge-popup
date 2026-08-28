<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Tests\Unit;

use Calevans\StaticForgePopup\Models\PopupDefinition;
use Calevans\StaticForgePopup\Services\PopupFormRenderer;
use Calevans\StaticForgePopup\Services\PopupParser;
use Calevans\StaticForgePopup\Services\PopupRepository;
use Calevans\StaticForgePopup\Tests\TestCase;
use EICC\StaticForge\Features\MarkdownRenderer\MarkdownProcessor;
use EICC\Utils\Container;
use Twig\Environment;

class PopupRepositoryTest extends TestCase
{
    private PopupRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Environment $twig */
        $twig = $this->container->get('twig');
        /** @var MarkdownProcessor $markdownProcessor */
        $markdownProcessor = $this->container->get(MarkdownProcessor::class);

        $formRenderer = new PopupFormRenderer($this->logger, $twig, $this->container);
        $parser = new PopupParser($this->logger, $markdownProcessor, $formRenderer);
        $this->repository = new PopupRepository($this->logger, $parser);
    }

    public function testFindsPopupFilesInNestedSubdirectories(): void
    {
        $sourceDir = $this->makeSourceDir();

        try {
            mkdir($sourceDir . '/nested/deep', 0755, true);
            file_put_contents(
                $sourceDir . '/nested/deep/inside.popup',
                "---\npopup_enabled: true\nid: nested-popup\n---\n\nNested body.\n"
            );

            $this->repository->load($this->containerWithSourceDir($sourceDir));

            $this->assertTrue($this->repository->has('nested-popup'));
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    public function testIgnoresFilesWithoutThePopupExtension(): void
    {
        $sourceDir = $this->makeSourceDir();

        try {
            file_put_contents($sourceDir . '/notes.txt', 'not a popup');
            file_put_contents($sourceDir . '/readme.md', '# not a popup either');

            $this->repository->load($this->containerWithSourceDir($sourceDir));

            $this->assertSame([], $this->repository->all());
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    public function testMissingSourceDirLogsWarningAndDoesNotThrow(): void
    {
        $this->logger->expects($this->once())->method('log')->with(
            'WARNING',
            $this->stringContains('SOURCE_DIR')
        );

        $this->repository->load($this->container);

        $this->assertSame([], $this->repository->all());
    }

    public function testNonDirectorySourceDirLogsWarningAndDoesNotThrow(): void
    {
        $sourceDir = $this->makeSourceDir();
        $filePath = $sourceDir . '/not-a-directory.txt';
        file_put_contents($filePath, 'i am a file, not a directory');

        try {
            $this->logger->expects($this->once())->method('log')->with(
                'WARNING',
                $this->stringContains('SOURCE_DIR')
            );

            $this->repository->load($this->containerWithSourceDir($filePath));

            $this->assertSame([], $this->repository->all());
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    public function testFileThatFailsToParseIsSkippedWithoutAbortingTheScan(): void
    {
        $sourceDir = $this->makeSourceDir();

        try {
            file_put_contents(
                $sourceDir . '/disabled.popup',
                "---\npopup_enabled: false\nid: disabled-popup\n---\n\nBody.\n"
            );
            file_put_contents(
                $sourceDir . '/valid.popup',
                "---\npopup_enabled: true\nid: valid-popup\n---\n\nBody.\n"
            );

            $this->repository->load($this->containerWithSourceDir($sourceDir));

            $this->assertFalse($this->repository->has('disabled-popup'));
            $this->assertTrue($this->repository->has('valid-popup'));
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    /**
     * Matching pathnames are sorted lexicographically before loading (see
     * PopupRepository::load()), so "first one loaded" is now a genuinely
     * deterministic, reproducible rule rather than a readdir()-order
     * accident. 'first.popup' sorts before 'second.popup', so it must win.
     */
    public function testDuplicateIdKeepsExactlyOneEntryAndLogsAWarning(): void
    {
        $sourceDir = $this->makeSourceDir();

        try {
            file_put_contents(
                $sourceDir . '/first.popup',
                "---\npopup_enabled: true\nid: dupe\n---\n\nFirst body.\n"
            );
            file_put_contents(
                $sourceDir . '/second.popup',
                "---\npopup_enabled: true\nid: dupe\n---\n\nSecond body.\n"
            );

            $this->logger->expects($this->once())->method('log')->with(
                'WARNING',
                $this->stringContains("Duplicate popup id 'dupe'")
            );

            $this->repository->load($this->containerWithSourceDir($sourceDir));

            $this->assertCount(1, $this->repository->all());
            $this->assertTrue($this->repository->has('dupe'));

            $winner = $this->repository->get('dupe');
            $this->assertInstanceOf(PopupDefinition::class, $winner);
            $this->assertSame("<p>First body.</p>\n", $winner->content);
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    /**
     * Proves the determinism claim with two files whose *directories* sort
     * differently than they'd be visited by an unsorted directory walk;
     * lexicographic path order, not directory-creation order or readdir()
     * order, must decide the winner.
     */
    public function testDuplicateIdResolutionIsDeterministicByLexicographicPathOrder(): void
    {
        $sourceDir = $this->makeSourceDir();

        try {
            mkdir($sourceDir . '/z-dir', 0755, true);
            mkdir($sourceDir . '/a-dir', 0755, true);
            file_put_contents(
                $sourceDir . '/z-dir/dupe.popup',
                "---\npopup_enabled: true\nid: dupe\n---\n\nFrom z-dir.\n"
            );
            file_put_contents(
                $sourceDir . '/a-dir/dupe.popup',
                "---\npopup_enabled: true\nid: dupe\n---\n\nFrom a-dir.\n"
            );

            $this->repository->load($this->containerWithSourceDir($sourceDir));

            $winner = $this->repository->get('dupe');
            $this->assertInstanceOf(PopupDefinition::class, $winner);
            $this->assertStringContainsString('From a-dir.', $winner->content);
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    /**
     * A symlinked *file* keeps a pathname inside SOURCE_DIR (so PathGuard's
     * pure string check passes) but its realpath() target does not; that
     * target must never be read.
     */
    public function testSymlinkedPopupFileEscapingSourceDirIsSkippedWithAWarning(): void
    {
        $sourceDir = $this->makeSourceDir();
        $outsideDir = sys_get_temp_dir() . '/staticforge_popup_outside_' . uniqid();
        mkdir($outsideDir, 0755, true);
        $secretFile = $outsideDir . '/secret.popup';
        file_put_contents(
            $secretFile,
            "---\npopup_enabled: true\nid: secret\n---\n\nShould never load.\n"
        );

        try {
            symlink($secretFile, $sourceDir . '/link.popup');

            $this->logger->expects($this->once())->method('log')->with(
                'WARNING',
                $this->stringContains('escapes SOURCE_DIR')
            );

            $this->repository->load($this->containerWithSourceDir($sourceDir));

            $this->assertFalse($this->repository->has('secret'));
            $this->assertSame([], $this->repository->all());
        } finally {
            $this->removeDirectory($sourceDir);
            $this->removeDirectory($outsideDir);
        }
    }

    public function testUnreadableSubdirectoryIsSkippedWithoutAbortingTheWholeScan(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Running as root bypasses directory permission checks.');
        }

        $sourceDir = $this->makeSourceDir();
        $lockedDir = $sourceDir . '/locked';
        mkdir($lockedDir, 0755, true);
        file_put_contents(
            $lockedDir . '/inside.popup',
            "---\npopup_enabled: true\nid: locked-inside\n---\n\nBody.\n"
        );
        file_put_contents(
            $sourceDir . '/valid.popup',
            "---\npopup_enabled: true\nid: valid-popup\n---\n\nBody.\n"
        );

        try {
            chmod($lockedDir, 0000);

            $this->repository->load($this->containerWithSourceDir($sourceDir));

            $this->assertTrue($this->repository->has('valid-popup'));
            $this->assertFalse($this->repository->has('locked-inside'));
        } finally {
            chmod($lockedDir, 0755);
            $this->removeDirectory($sourceDir);
        }
    }

    public function testGetReturnsNullForAnUnknownId(): void
    {
        $this->assertNull($this->repository->get('does-not-exist'));
        $this->assertFalse($this->repository->has('does-not-exist'));
    }

    public function testGetHasAndAllReflectLoadedPopups(): void
    {
        $sourceDir = $this->makeSourceDir();

        try {
            file_put_contents(
                $sourceDir . '/one.popup',
                "---\npopup_enabled: true\nid: one\n---\n\nOne.\n"
            );
            file_put_contents(
                $sourceDir . '/two.popup',
                "---\npopup_enabled: true\nid: two\n---\n\nTwo.\n"
            );

            $this->repository->load($this->containerWithSourceDir($sourceDir));

            $this->assertTrue($this->repository->has('one'));
            $this->assertTrue($this->repository->has('two'));
            $this->assertInstanceOf(PopupDefinition::class, $this->repository->get('one'));
            $this->assertCount(2, $this->repository->all());
            $this->assertArrayHasKey('one', $this->repository->all());
            $this->assertArrayHasKey('two', $this->repository->all());
        } finally {
            $this->removeDirectory($sourceDir);
        }
    }

    public function testUnreadableFileIsSkippedWithoutAFatalError(): void
    {
        $sourceDir = $this->makeSourceDir();
        $unreadable = $sourceDir . '/locked.popup';

        try {
            file_put_contents(
                $unreadable,
                "---\npopup_enabled: true\nid: locked\n---\n\nBody.\n"
            );
            chmod($unreadable, 0000);

            $this->logger->expects($this->once())->method('log')->with(
                'WARNING',
                $this->stringContains('not readable')
            );

            $this->repository->load($this->containerWithSourceDir($sourceDir));

            $this->assertFalse($this->repository->has('locked'));
        } finally {
            chmod($unreadable, 0644);
            $this->removeDirectory($sourceDir);
        }
    }

    private function makeSourceDir(): string
    {
        $sourceDir = sys_get_temp_dir() . '/staticforge_popup_repo_' . uniqid();
        mkdir($sourceDir, 0755, true);

        return (string) realpath($sourceDir);
    }

    private function containerWithSourceDir(string $sourceDir): Container
    {
        $this->setContainerVariable('SOURCE_DIR', $sourceDir);

        return $this->container;
    }
}
