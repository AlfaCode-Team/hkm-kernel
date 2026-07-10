<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\Application\Mailer;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Priority;
use Plugins\Mail\Infrastructure\Mime\MimeBuilder;
use Plugins\Mail\Infrastructure\Transport\ArrayTransport;

#[CoversClass(Mailer::class)]
#[CoversClass(MimeBuilder::class)]
final class MailerTest extends TestCase
{
    private ArrayTransport $transport;
    private Mailer $mailer;

    protected function setUp(): void
    {
        $this->transport = new ArrayTransport();
        $this->mailer = new Mailer(
            $this->transport,
            new MimeBuilder(),
            fromEmail: 'no-reply@shop.test',
            fromName: 'Shop',
        );
    }

    public function test_builds_nested_multipart_with_attachment_and_inline_image(): void
    {
        $message = $this->mailer->message()
            ->to('customer@example.com', 'Cust')
            ->subject('Welcome')
            ->html('<h1>Hi</h1><img src="cid:logo">')
            ->embedData('PNG', 'logo', 'logo.png', 'image/png')
            ->attachData("a,b\n1,2", 'report.csv', 'text/csv')
            ->priority(Priority::High);

        $this->mailer->dispatch($message);
        $mime = $this->transport->last()['mime'];

        $this->assertStringContainsString('multipart/mixed', $mime);
        $this->assertStringContainsString('multipart/related', $mime);
        $this->assertStringContainsString('multipart/alternative', $mime);
        $this->assertStringContainsString('Content-ID: <logo>', $mime);
        $this->assertStringContainsString('filename="report.csv"', $mime);
        $this->assertStringContainsString('X-Priority: 1', $mime);
    }

    public function test_bcc_recipients_are_delivered_but_never_appear_in_headers(): void
    {
        $this->mailer->dispatch(
            $this->mailer->message()->to('a@example.com')->bcc('secret@example.com')->subject('x')->text('y'),
        );

        $sent = $this->transport->last();
        $this->assertContains('secret@example.com', $sent['recipients']);
        $this->assertStringNotContainsStringIgnoringCase('Bcc:', $sent['mime']);
    }

    public function test_non_ascii_subject_is_mime_encoded(): void
    {
        $this->mailer->dispatch($this->mailer->message()->to('a@example.com')->subject('Wëlcome ☕')->text('hi'));

        $this->assertStringContainsString('Subject: =?UTF-8?B?', $this->transport->last()['mime']);
    }

    public function test_html_only_gets_an_auto_generated_plain_text_alternative(): void
    {
        $this->mailer->dispatch($this->mailer->message()->to('a@example.com')->subject('x')->html('<p>Hi <b>there</b></p>'));
        $mime = $this->transport->last()['mime'];

        $this->assertStringContainsString('text/plain', $mime);
        $this->assertStringContainsString('text/html', $mime);
    }

    public function test_crlf_in_address_is_rejected_header_injection_guard(): void
    {
        $this->expectException(MailException::class);
        $this->mailer->message()->to("victim@example.com\r\nBcc: attacker@evil.com");
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->expectException(MailException::class);
        $this->mailer->message()->to('not-an-email');
    }

    public function test_mailport_view_path_treats_view_as_raw_html_without_a_renderer(): void
    {
        $this->mailer->send('a@example.com', 'Hi', '<p>raw</p>');
        $mime = $this->transport->last()['mime'];

        $this->assertStringContainsString('To: a@example.com', $mime);
        $this->assertStringContainsString('Subject: Hi', $mime);
        $this->assertStringContainsString('<p>raw</p>', quoted_printable_decode($this->bodyOf($mime)));
    }

    public function test_message_without_recipient_throws(): void
    {
        $this->expectException(MailException::class);
        $this->mailer->dispatch($this->mailer->message()->subject('x')->text('y'));
    }

    public function test_no_header_line_exceeds_the_rfc_hard_limit(): void
    {
        $message = $this->mailer->message()->subject('x')->text('y');
        for ($i = 0; $i < 40; $i++) {                 // a long recipient list
            $message->to("user{$i}.longlocalpart@example-domain.test");
        }
        $this->mailer->dispatch($message);

        $headerBlock = explode("\r\n\r\n", $this->transport->last()['mime'], 2)[0];
        foreach (explode("\r\n", $headerBlock) as $line) {
            $this->assertLessThanOrEqual(998, strlen($line), 'Header line exceeds RFC 5322 limit.');
        }
        // Folded continuation lines begin with whitespace.
        $this->assertStringContainsString("\r\n ", $headerBlock);
    }

    public function test_long_non_ascii_subject_is_chunked_into_multiple_encoded_words(): void
    {
        $this->mailer->dispatch(
            $this->mailer->message()->to('a@example.com')->subject(str_repeat('café ', 40))->text('y'),
        );
        $headerBlock = explode("\r\n\r\n", $this->transport->last()['mime'], 2)[0];

        foreach (explode("\r\n", $headerBlock) as $line) {
            $this->assertLessThanOrEqual(998, strlen($line));
        }
        $this->assertGreaterThan(1, substr_count($headerBlock, '=?UTF-8?B?'), 'Subject should split into multiple encoded-words.');
    }

    private function bodyOf(string $mime): string
    {
        return explode("\r\n\r\n", $mime, 2)[1] ?? '';
    }
}
