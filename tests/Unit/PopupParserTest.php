<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Tests\Unit;

use Calevans\StaticForgePopup\Models\PopupDefinition;
use Calevans\StaticForgePopup\Services\PopupFormRenderer;
use Calevans\StaticForgePopup\Services\PopupParser;
use Calevans\StaticForgePopup\Tests\TestCase;
use EICC\StaticForge\Features\MarkdownRenderer\MarkdownProcessor;
use Twig\Environment;

class PopupParserTest extends TestCase
{
    private const DEFAULT_BLOCKED_DAYS = 30;

    private PopupParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Environment $twig */
        $twig = $this->container->get('twig');
        /** @var MarkdownProcessor $markdownProcessor */
        $markdownProcessor = $this->container->get(MarkdownProcessor::class);

        $formRenderer = new PopupFormRenderer($this->logger, $twig, $this->container);
        $this->parser = new PopupParser($this->logger, $markdownProcessor, $formRenderer);
    }

    public function testParsesValidFrontmatterAndBody(): void
    {
        $content = "---\npopup_enabled: true\nid: welcome\nexit_intent: true\ntimer: 5\n---\n\n# Hello\n\nBody text.\n";

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertInstanceOf(PopupDefinition::class, $popup);
        $this->assertSame('welcome', $popup->id);
        $this->assertTrue($popup->exitIntent);
        $this->assertSame(5, $popup->timerSeconds);
        $this->assertStringContainsString('<h1>Hello', $popup->content);
        $this->assertStringContainsString('<p>Body text.</p>', $popup->content);
    }

    public function testReturnsNullWhenFrontmatterDelimitersAreAbsent(): void
    {
        $content = "popup_enabled: true\nid: welcome\n\nBody text with no delimiters.\n";

        $this->logger->expects($this->once())->method('log')->with(
            'WARNING',
            $this->logicalAnd(
                $this->stringContains("'filename-id'"),
                $this->stringContains('no --- frontmatter fence')
            )
        );

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertNull($popup);
    }

    public function testReturnsNullWhenOnlyOpeningDelimiterIsPresent(): void
    {
        $content = "---\npopup_enabled: true\n\nNo closing delimiter.\n";

        $this->logger->expects($this->once())->method('log')->with(
            'WARNING',
            $this->stringContains('no --- frontmatter fence')
        );

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertNull($popup);
    }

    public function testReturnsNullWhenPopupEnabledIsFalse(): void
    {
        $content = "---\npopup_enabled: false\nid: welcome\n---\n\nBody.\n";

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertNull($popup);
    }

    public function testReturnsNullWhenPopupEnabledIsAbsent(): void
    {
        $content = "---\nid: welcome\n---\n\nBody.\n";

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertNull($popup);
    }

    public function testIdDefaultsToFilenameIdWhenFrontmatterOmitsIt(): void
    {
        $content = "---\npopup_enabled: true\n---\n\nBody.\n";

        $popup = $this->parser->parse($content, 'from-filename', self::DEFAULT_BLOCKED_DAYS);

        $this->assertInstanceOf(PopupDefinition::class, $popup);
        $this->assertSame('from-filename', $popup->id);
    }

    public function testRejectsIdContainingPathTraversal(): void
    {
        $content = "---\npopup_enabled: true\nid: ../../evil\n---\n\nBody.\n";

        $this->logger->expects($this->once())->method('log')->with(
            'ERROR',
            $this->stringContains("Popup id '../../evil'")
        );

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertNull($popup);
    }

    public function testRejectsIdContainingSlash(): void
    {
        $content = "---\npopup_enabled: true\nid: foo/bar\n---\n\nBody.\n";

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertNull($popup);
    }

    public function testRejectsIdContainingDot(): void
    {
        $content = "---\npopup_enabled: true\nid: foo.bar\n---\n\nBody.\n";

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertNull($popup);
    }

    public function testRejectsIdContainingSpace(): void
    {
        $content = "---\npopup_enabled: true\nid: \"foo bar\"\n---\n\nBody.\n";

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertNull($popup);
    }

    public function testRejectsEmptyId(): void
    {
        $content = "---\npopup_enabled: true\nid: \"\"\n---\n\nBody.\n";

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertNull($popup);
    }

    public function testRejectsIdWithATrailingNewlineSmuggledThroughADoubleQuotedYamlScalar(): void
    {
        // Symfony YAML unescapes "\n" inside a double-quoted scalar into a real
        // newline. Without the `\z`+`D` fix, PCRE's `$` matches before a final
        // "\n", so this id would otherwise pass ID_PATTERN.
        $content = "---\npopup_enabled: true\nid: \"newsletter\\n\"\n---\n\nBody.\n";

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertNull($popup);
    }

    public function testAcceptsIdAtExactlySixtyFourCharacters(): void
    {
        $id = str_repeat('a', 64);
        $content = "---\npopup_enabled: true\nid: {$id}\n---\n\nBody.\n";

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertInstanceOf(PopupDefinition::class, $popup);
        $this->assertSame($id, $popup->id);
    }

    public function testRejectsIdAtSixtyFiveCharacters(): void
    {
        $id = str_repeat('a', 65);
        $content = "---\npopup_enabled: true\nid: {$id}\n---\n\nBody.\n";

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertNull($popup);
    }

    public function testMalformedYamlLogsErrorAndReturnsNull(): void
    {
        // Unterminated quoted scalar: not valid YAML.
        $content = "---\npopup_enabled: \"unterminated\ntitle: broken\n---\n\nBody.\n";

        $this->logger->expects($this->once())->method('log')->with(
            'ERROR',
            $this->stringContains('Failed to parse popup frontmatter')
        );

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertNull($popup);
    }

    public function testFrontmatterThatDoesNotParseToAMapReturnsNullAndLogsError(): void
    {
        $content = "---\njust_a_scalar_string\n---\n\nBody.\n";

        $this->logger->expects($this->once())->method('log')->with(
            'ERROR',
            $this->stringContains('did not parse to a map')
        );

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertNull($popup);
    }

    public function testStripReservedRemovesReservedAndCredentialShapedKeysButKeepsCustomKeys(): void
    {
        $content = "---\n"
            . "popup_enabled: true\n"
            . "id: welcome\n"
            . "content: should-not-survive\n"
            . "OUTPUT_DIR: /should/not/survive\n"
            . "API_KEY: should-not-survive\n"
            . "custom_field: keep-me\n"
            . "---\n\nBody.\n";

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertInstanceOf(PopupDefinition::class, $popup);
        $this->assertArrayNotHasKey('content', $popup->metadata);
        $this->assertArrayNotHasKey('OUTPUT_DIR', $popup->metadata);
        $this->assertArrayNotHasKey('API_KEY', $popup->metadata);
        $this->assertArrayHasKey('custom_field', $popup->metadata);
        $this->assertSame('keep-me', $popup->metadata['custom_field']);
    }

    public function testExitIntentTimerAndBlockedForAreCoercedToTheirDeclaredTypes(): void
    {
        $content = "---\n"
            . "popup_enabled: true\n"
            . "id: welcome\n"
            . "exit_intent: \"1\"\n"
            . "timer: \"45\"\n"
            . "popup_blocked_for: \"7\"\n"
            . "---\n\nBody.\n";

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertInstanceOf(PopupDefinition::class, $popup);
        $this->assertTrue($popup->exitIntent);
        $this->assertSame(45, $popup->timerSeconds);
        $this->assertSame(7, $popup->blockedDays);
    }

    public function testDefaultBlockedDaysIsUsedWhenPopupBlockedForIsAbsent(): void
    {
        $content = "---\npopup_enabled: true\nid: welcome\n---\n\nBody.\n";

        $popup = $this->parser->parse($content, 'filename-id', 45);

        $this->assertInstanceOf(PopupDefinition::class, $popup);
        $this->assertSame(45, $popup->blockedDays);
    }

    public function testFormExpansionHappensBeforeMarkdownConversionSoTheFormIsNotWrappedInAParagraph(): void
    {
        $this->setContainerVariable('site_config', [
            'forms' => [
                'newsletter' => [
                    'provider_url' => 'https://forms.example.com/subscribe',
                    'fields' => [['name' => 'email', 'type' => 'email', 'label' => 'Email']],
                ],
            ],
        ]);

        $content = "---\npopup_enabled: true\nid: welcome\n---\n\n{{ form('newsletter') }}\n";

        $popup = $this->parser->parse($content, 'filename-id', self::DEFAULT_BLOCKED_DAYS);

        $this->assertInstanceOf(PopupDefinition::class, $popup);
        $this->assertStringContainsString('<form action="https://forms.example.com/subscribe"', $popup->content);
        $this->assertStringNotContainsString('<p><form', $popup->content);
        $this->assertStringNotContainsString('</form>' . PHP_EOL . '</p>', $popup->content);
    }
}
