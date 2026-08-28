<?php

declare(strict_types=1);

namespace Calevans\StaticForgePopup\Tests\Unit;

use Calevans\StaticForgePopup\Services\PopupIdValidator;
use Calevans\StaticForgePopup\Tests\TestCase;

class PopupIdValidatorTest extends TestCase
{
    public function testAcceptsAListOfValidIds(): void
    {
        $this->logger->expects($this->never())->method('log');

        $ids = PopupIdValidator::normalize(['newsletter-signup', 'timer_only'], $this->logger, 'test');

        $this->assertSame(['newsletter-signup', 'timer_only'], $ids);
    }

    public function testAcceptsASingleStringValue(): void
    {
        $ids = PopupIdValidator::normalize('newsletter-signup', $this->logger, 'test');

        $this->assertSame(['newsletter-signup'], $ids);
    }

    public function testDropsEmptyAndNonStringEntriesSilently(): void
    {
        $this->logger->expects($this->never())->method('log');

        $ids = PopupIdValidator::normalize(['', null, 123, false, 'valid-id'], $this->logger, 'test');

        $this->assertSame(['valid-id'], $ids);
    }

    public function testRejectsAndLogsAnIdContainingADoubleQuoteAndPathTraversal(): void
    {
        $maliciousId = '../../evil" onload="alert(1)';

        $this->logger->expects($this->once())->method('log')->with(
            'ERROR',
            $this->logicalAnd(
                $this->stringContains("Invalid popup id '{$maliciousId}'"),
                $this->stringContains('some-context')
            )
        );

        $ids = PopupIdValidator::normalize([$maliciousId], $this->logger, 'some-context');

        $this->assertSame([], $ids);
    }

    public function testRejectsAndLogsAnIdWithATrailingNewline(): void
    {
        $this->logger->expects($this->once())->method('log')->with('ERROR', $this->anything());

        $ids = PopupIdValidator::normalize(["newsletter\n"], $this->logger, 'test');

        $this->assertSame([], $ids);
    }

    public function testAcceptsWhatItRejectsAreSkippedWhileValidSiblingsSurvive(): void
    {
        $this->logger->expects($this->once())->method('log')->with('ERROR', $this->anything());

        $ids = PopupIdValidator::normalize(['../evil', 'good-id'], $this->logger, 'test');

        $this->assertSame(['good-id'], $ids);
    }
}
