<?php

use App\Libraries\InitialsAvatar;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class InitialsAvatarTest extends CIUnitTestCase
{
    public function testInitialsAreTheFirstAndLastWords(): void
    {
        $this->assertSame('M', InitialsAvatar::initials('Mary'));
        $this->assertSame('MW', InitialsAvatar::initials('Mary Jane Watson'));
        $this->assertSame('MW', InitialsAvatar::initials('  molly   weasley '));
        $this->assertSame('ÉZ', InitialsAvatar::initials('émile zola'));
        $this->assertSame('', InitialsAvatar::initials(''));
        $this->assertSame('', InitialsAvatar::initials('   '));
    }

    public function testThaiInitialsSkipLeadingVowels(): void
    {
        $this->assertSame('สจ', InitialsAvatar::initials('สมชาย ใจดี'));
        $this->assertSame('ม', InitialsAvatar::initials('ไม้'));
        $this->assertSame('ดว', InitialsAvatar::initials('เด่น แวววาว'));
        $this->assertSame('สW', InitialsAvatar::initials('สมชาย Watson'));
    }

    public function testWordsThatDoNotStartWithALetterGiveNoInitial(): void
    {
        $this->assertSame('O', InitialsAvatar::initials('Otter ../'));
        $this->assertSame('', InitialsAvatar::initials('../ /etc'));
        $this->assertSame('', InitialsAvatar::initials('<script>'));
    }

    public function testColorsAreAPairFixedByTheInitials(): void
    {
        $colors = InitialsAvatar::colors('MW');
        $this->assertCount(2, $colors);
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $colors[0]);
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $colors[1]);
        $this->assertSame($colors, InitialsAvatar::colors('MW'));
    }
}
