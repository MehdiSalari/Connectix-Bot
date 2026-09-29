<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LogRedaction;
use PHPUnit\Framework\TestCase;

/**
 * The contract of the one place that decides what a log line may contain.
 *
 * Two live leaks were found in the audit: the bot token inside a Guzzle
 * transport message, and query bindings rendering plaintext client passwords
 * into storage/logs. Everything below is a shape those logs actually take.
 */
class LogRedactionTest extends TestCase
{
    public function test_the_bot_token_in_a_request_url_is_masked(): void
    {
        $line = LogRedaction::mask(
            'cURL error 28: Failed to connect to api.telegram.org port 443 after 30 ms: '.
            'https://api.telegram.org/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw1/sendMessage'
        );

        $this->assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw1', $line);
        $this->assertStringContainsString('/bot***', $line);
    }

    public function test_a_bare_bot_token_is_masked(): void
    {
        $line = LogRedaction::mask('Token rejected: 123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw1');

        $this->assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw1', $line);
        $this->assertStringContainsString('***', $line);
    }

    public function test_password_and_token_pairs_are_masked(): void
    {
        $this->assertSame(
            'failed password=*** for user',
            LogRedaction::mask('failed password=hunter2 for user'),
        );

        $this->assertStringNotContainsString('seller-secret', LogRedaction::mask('header token: seller-secret'));
        $this->assertStringNotContainsString('sk-abc123', LogRedaction::mask('api_key="sk-abc123" gone'));
    }

    public function test_a_bearer_token_is_masked_even_without_an_equals_sign(): void
    {
        $line = LogRedaction::mask('401 from the panel: Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload');

        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $line);

        // The pair rule then folds the scheme itself into the same mask, so
        // the line reads "Authorization: *** ***" - what matters is that no
        // part of the token survives.
        $this->assertStringContainsString('***', $line);
    }

    public function test_a_bearer_token_in_isolation_keeps_its_shape(): void
    {
        $line = LogRedaction::mask('upstream said: Bearer sk-live-abc123 was rejected');

        $this->assertStringNotContainsString('sk-live-abc123', $line);
        $this->assertStringContainsString('Bearer ***', $line);
    }

    public function test_long_digit_runs_keep_only_their_last_four_digits(): void
    {
        // A card number and an Iranian mobile number: 16 and 11 digits.
        $line = LogRedaction::mask('card 6219861912345678 phone 09123456789 in one line');

        $this->assertStringNotContainsString('6219861912345678', $line);
        $this->assertStringContainsString('***5678', $line);
        // Eleven digits is below the threshold - chat ids stay readable.
        $this->assertStringContainsString('09123456789', $line);
    }

    public function test_the_result_is_a_single_short_line(): void
    {
        $line = LogRedaction::mask(str_repeat('some detail ', 60)."\nsecond line\r\nthird");

        $this->assertStringNotContainsString("\n", $line);
        $this->assertLessThanOrEqual(300, strlen($line));
        $this->assertStringEndsWith('.', $line);
    }

    public function test_an_empty_message_stays_empty(): void
    {
        $this->assertSame('', LogRedaction::mask(null));
        $this->assertSame('', LogRedaction::mask(''));
    }
}
