<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Tests\Unit;

use Calevans\StaticForgePopup\Services\PopupFormRenderer;
use Calevans\StaticForgePopup\Tests\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class PopupFormRendererTest extends TestCase
{
    private PopupFormRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Environment $twig */
        $twig = $this->container->get('twig');
        $this->renderer = new PopupFormRenderer($this->logger, $twig, $this->container);
    }

    public function testExpandsAKnownFormShortcodeIntoRenderedHtml(): void
    {
        $this->setForms([
            'newsletter' => [
                'provider_url' => 'https://forms.example.com/subscribe',
                'submit_text' => 'Sign up',
            ],
        ]);

        $result = $this->renderer->expand("Before.\n\n{{ form('newsletter') }}\n\nAfter.", null);

        $this->assertStringContainsString('<form action="https://forms.example.com/subscribe" method="POST">', $result);
        $this->assertStringContainsString('Sign up', $result);
        $this->assertStringContainsString('Before.', $result);
        $this->assertStringContainsString('After.', $result);
    }

    public function testUnknownFormNameLogsWarningAndLeavesContentUnchanged(): void
    {
        $this->setForms([]);

        $this->logger->expects($this->once())->method('log')->with(
            'WARNING',
            $this->stringContains("Form 'missing' not found")
        );

        $content = "{{ form('missing') }}";
        $result = $this->renderer->expand($content, null);

        $this->assertSame($content, $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonMatchingShortcodeProvider(): array
    {
        return [
            'name with slash' => ["{{ form('foo/bar') }}"],
            'name with space' => ["{{ form('foo bar') }}"],
            'name with dot' => ["{{ form('foo.bar') }}"],
            'unquoted name' => ['{{ form(foo) }}'],
            'mismatched quotes' => ['{{ form(\'foo") }}'],
        ];
    }

    /**
     * @dataProvider nonMatchingShortcodeProvider
     */
    public function testShortcodesWithDisallowedCharactersAreNotMatched(string $content): void
    {
        $this->setForms(['foo' => ['provider_url' => 'https://forms.example.com/x']]);

        $result = $this->renderer->expand($content, null);

        $this->assertSame($content, $result);
    }

    public function testShortcodeMatchesWithNoWhitespaceAroundOuterBraces(): void
    {
        $this->setForms(['newsletter' => ['provider_url' => 'https://forms.example.com/subscribe']]);

        $result = $this->renderer->expand("{{form('newsletter')}}", null);

        $this->assertStringContainsString('<form action="https://forms.example.com/subscribe"', $result);
    }

    public function testShortcodeMatchesWithExtraWhitespaceAroundOuterBraces(): void
    {
        $this->setForms(['newsletter' => ['provider_url' => 'https://forms.example.com/subscribe']]);

        $result = $this->renderer->expand("{{   form('newsletter')   }}", null);

        $this->assertStringContainsString('<form action="https://forms.example.com/subscribe"', $result);
    }

    public function testShortcodeIsNotMatchedWhenThereIsWhitespaceInsideTheParentheses(): void
    {
        $this->setForms(['newsletter' => ['provider_url' => 'https://forms.example.com/subscribe']]);
        $content = "{{ form( 'newsletter' ) }}";

        $result = $this->renderer->expand($content, null);

        $this->assertSame($content, $result);
    }

    public function testShortcodeMatchesWithDoubleQuotes(): void
    {
        $this->setForms(['newsletter' => ['provider_url' => 'https://forms.example.com/subscribe']]);

        $result = $this->renderer->expand('{{ form("newsletter") }}', null);

        $this->assertStringContainsString('<form action="https://forms.example.com/subscribe"', $result);
    }

    public function testMultipleDifferentShortcodesAreEachExpanded(): void
    {
        $this->setForms([
            'newsletter' => ['provider_url' => 'https://forms.example.com/newsletter'],
            'contact' => ['provider_url' => 'https://forms.example.com/contact'],
        ]);

        $result = $this->renderer->expand("{{ form('newsletter') }}\n\n{{ form('contact') }}", null);

        $this->assertStringContainsString('https://forms.example.com/newsletter', $result);
        $this->assertStringContainsString('https://forms.example.com/contact', $result);
    }

    public function testTheSameShortcodeUsedTwiceIsExpandedBothTimes(): void
    {
        $this->setForms(['newsletter' => ['provider_url' => 'https://forms.example.com/subscribe']]);

        $result = $this->renderer->expand("{{ form('newsletter') }}\n\n{{ form('newsletter') }}", null);

        $this->assertSame(2, substr_count($result, '<form action="https://forms.example.com/subscribe"'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function insecureUrlProvider(): array
    {
        return [
            'plain http' => ['http://forms.example.com/subscribe'],
            'javascript scheme' => ["javascript:alert('x')"],
            'protocol-relative' => ['//evil.com/subscribe'],
            'relative path' => ['/subscribe'],
        ];
    }

    /**
     * @dataProvider insecureUrlProvider
     */
    public function testNonHttpsProviderUrlIsBlankedWithAWarning(string $url): void
    {
        $this->setForms(['newsletter' => ['provider_url' => $url]]);

        $this->logger->expects($this->once())->method('log')->with(
            'WARNING',
            $this->logicalAnd(
                $this->stringContains('provider_url'),
                $this->stringContains('must use https:')
            )
        );

        $result = $this->renderer->expand("{{ form('newsletter') }}", null);

        $this->assertStringContainsString('<form action="" method="POST">', $result);
    }

    /**
     * @dataProvider insecureUrlProvider
     */
    public function testNonHttpsChallengeUrlIsBlankedWithAWarning(string $url): void
    {
        $this->setForms([
            'newsletter' => [
                'provider_url' => 'https://forms.example.com/subscribe',
                'challenge_url' => $url,
            ],
        ]);

        $this->logger->expects($this->once())->method('log')->with(
            'WARNING',
            $this->logicalAnd(
                $this->stringContains('challenge_url'),
                $this->stringContains('must use https:')
            )
        );

        $this->renderer->expand("{{ form('newsletter') }}", null);
    }

    /**
     * @dataProvider insecureUrlProvider
     */
    public function testNonHttpsFrontmatterUrlOverrideIsBlankedWithAWarning(string $url): void
    {
        $this->setForms(['newsletter' => ['provider_url' => 'https://forms.example.com/subscribe']]);

        $this->logger->expects($this->once())->method('log')->with(
            'WARNING',
            $this->logicalAnd(
                $this->stringContains('url override'),
                $this->stringContains('must use https:')
            )
        );

        $result = $this->renderer->expand("{{ form('newsletter') }}", $url);

        // The override was rejected, so the (valid https) provider_url is used instead.
        $this->assertStringContainsString('<form action="https://forms.example.com/subscribe"', $result);
    }

    public function testHttpsProviderUrlPassesThrough(): void
    {
        $this->setForms(['newsletter' => ['provider_url' => 'https://forms.example.com/subscribe']]);

        $this->logger->expects($this->never())->method('log');

        $result = $this->renderer->expand("{{ form('newsletter') }}", null);

        $this->assertStringContainsString('<form action="https://forms.example.com/subscribe"', $result);
    }

    public function testFrontmatterUrlOverrideWinsOverProviderUrl(): void
    {
        $this->setForms(['newsletter' => ['provider_url' => 'https://forms.example.com/subscribe']]);

        $result = $this->renderer->expand(
            "{{ form('newsletter') }}",
            'https://override.example.com/take-priority'
        );

        $this->assertStringContainsString('<form action="https://override.example.com/take-priority"', $result);
        $this->assertStringNotContainsString('forms.example.com', $result);
    }

    public function testFormIdIsAppendedWithQuestionMarkWhenProviderUrlHasNoQueryString(): void
    {
        $this->setForms([
            'newsletter' => [
                'provider_url' => 'https://forms.example.com/subscribe',
                'form_id' => 'abc123',
            ],
        ]);

        $result = $this->renderer->expand("{{ form('newsletter') }}", null);

        $this->assertStringContainsString(
            '<form action="https://forms.example.com/subscribe?FORMID=abc123"',
            $result
        );
    }

    public function testFormIdIsAppendedWithAmpersandWhenProviderUrlAlreadyHasAQueryString(): void
    {
        $this->setForms([
            'newsletter' => [
                'provider_url' => 'https://forms.example.com/subscribe?ref=site',
                'form_id' => 'abc123',
            ],
        ]);

        $result = $this->renderer->expand("{{ form('newsletter') }}", null);

        // Twig auto-escapes the endpoint attribute value, so '&' renders as '&amp;'.
        $this->assertStringContainsString(
            '<form action="https://forms.example.com/subscribe?ref=site&amp;FORMID=abc123"',
            $result
        );
    }

    public function testFormIdIsNotAppendedWhenAppendFormIdIsFalse(): void
    {
        $this->setForms([
            'newsletter' => [
                'provider_url' => 'https://forms.example.com/subscribe',
                'form_id' => 'abc123',
                'append_form_id' => false,
            ],
        ]);

        $result = $this->renderer->expand("{{ form('newsletter') }}", null);

        $this->assertStringContainsString('<form action="https://forms.example.com/subscribe"', $result);
        $this->assertStringNotContainsString('FORMID', $result);
    }

    public function testFormIdIsNotAppendedWhenFormIdIsEmpty(): void
    {
        // Deliberate divergence from core's FormsService (see PopupFormRenderer's
        // class docblock): with no form_id set, ?FORMID= must never be appended.
        $this->setForms(['newsletter' => ['provider_url' => 'https://forms.example.com/subscribe']]);

        $result = $this->renderer->expand("{{ form('newsletter') }}", null);

        $this->assertStringContainsString('<form action="https://forms.example.com/subscribe"', $result);
        $this->assertStringNotContainsString('FORMID', $result);
    }

    public function testAssumeSuccessFlagDefaultsToFalseAndRendersNoAttribute(): void
    {
        $this->setForms(['newsletter' => ['provider_url' => 'https://forms.example.com/subscribe']]);

        $this->logger->expects($this->never())->method('log');

        $result = $this->renderer->expand("{{ form('newsletter') }}", null);

        $this->assertStringNotContainsString('data-assume-success', $result);
    }

    public function testAssumeSuccessFlagReachesTwigContextAndRendersTheAttributeWhenTemplateSupportsIt(): void
    {
        $this->setForms([
            'newsletter' => [
                'provider_url' => 'https://forms.example.com/subscribe',
                'assume_success_on_opaque_response' => true,
            ],
        ]);

        $this->logger->expects($this->never())->method('log');

        $result = $this->renderer->expand("{{ form('newsletter') }}", null);

        $this->assertStringContainsString('data-assume-success="1"', $result);
    }

    public function testWarningLogsWhenFlagIsSetButRenderedTemplateLacksTheAttribute(): void
    {
        $twig = new Environment(new ArrayLoader([
            // A stand-in for a site's un-updated _popup_form.html.twig: it has
            // no data-assume-success attribute and no {% if assume_success %}.
            '_popup_form.html.twig' => '<form action="{{ endpoint }}" method="POST">'
                . '<button type="submit">{{ submit_text }}</button></form>',
        ]));
        $renderer = new PopupFormRenderer($this->logger, $twig, $this->container);

        $this->setForms([
            'newsletter' => [
                'provider_url' => 'https://forms.example.com/subscribe',
                'assume_success_on_opaque_response' => true,
            ],
        ]);

        $this->logger->expects($this->once())->method('log')->with(
            'WARNING',
            $this->logicalAnd(
                $this->stringContains("'newsletter'"),
                $this->stringContains('data-assume-success')
            )
        );

        $renderer->expand("{{ form('newsletter') }}", null);
    }

    /**
     * @param array<string, array<string, mixed>> $forms
     */
    private function setForms(array $forms): void
    {
        $this->setContainerVariable('site_config', ['forms' => $forms]);
    }
}
