<?php

use App\Libraries\EmailService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class EmailTemplateTest extends CIUnitTestCase
{
    public function testBodyGoesIntoTheTranslatedTemplate(): void
    {
        $html = (new EmailService())->render('Hi <you>', "Hello <b>Ann</b>,\n\nOpen this:\nhttps://otternova.com/a?b=1&c=2\n\nBye");

        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringContainsString('<title>Hi &lt;you&gt;</title>', $html);
        $this->assertStringContainsString('<p style="margin: 0 0 16px;">Hello &lt;b&gt;Ann&lt;/b&gt;,</p>', $html);
        $this->assertStringContainsString('<a href="https://otternova.com/a?b=1&amp;c=2"', $html);
        $this->assertStringContainsString('Best regards,', $html);
        $this->assertStringContainsString('Privacy Policy', $html);
        $this->assertStringContainsString('© 2025-' . date('Y'), $html);
    }

    public function testLanguageTagsMapOntoSupportedLocales(): void
    {
        $mailer = new EmailService();

        $this->assertSame('th', $mailer->supportedLocale('th'));
        $this->assertSame('th', $mailer->supportedLocale('th-TH'));
        $this->assertSame('en-US', $mailer->supportedLocale('en_us'));
        $this->assertSame('en-US', $mailer->supportedLocale('en-GB'));
        $this->assertNull($mailer->supportedLocale('fr-FR'));
        $this->assertNull($mailer->supportedLocale(' '));
        $this->assertNull($mailer->supportedLocale(null));
    }

    public function testTemplateIsTranslatedIntoTheGivenLocale(): void
    {
        $html = (new EmailService())->render('S', 'B', 'th');

        $this->assertStringContainsString('<html lang="th">', $html);
        $this->assertStringContainsString('ติดต่อฝ่ายสนับสนุน', $html);
        $this->assertStringNotContainsString('Contact Support', $html);
        $this->assertStringNotContainsString('Email.template', $html);
    }
}
