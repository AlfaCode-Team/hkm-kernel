<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Validation;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\I18n\Translator;
use Plugins\Validation\Validator;

#[CoversClass(Validator::class)]
final class ValidatorTest extends TestCase
{
    public function test_valid_data_passes_and_returns_validated_fields(): void
    {
        $validated = Validator::make(
            ['email' => 'a@b.com', 'age' => '21', 'role' => 'admin', 'extra' => 'ignored'],
            ['email' => 'required|email', 'age' => 'required|integer|min:18', 'role' => 'required|in:admin,user'],
        )->validate();

        $this->assertSame(['email' => 'a@b.com', 'age' => '21', 'role' => 'admin'], $validated);
    }

    public function test_collects_errors_per_field(): void
    {
        $errors = Validator::make(
            ['email' => 'nope', 'age' => '5'],
            ['email' => 'required|email', 'age' => 'integer|min:18'],
        )->errors();

        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('age', $errors);
    }

    public function test_validate_throws_validation_exception_with_field_errors(): void
    {
        $validator = Validator::make(['email' => 'nope'], ['email' => 'required|email']);

        try {
            $validator->validate();
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors);
        }
    }

    public function test_confirmed_rule_passes_when_matching(): void
    {
        $validator = Validator::make(
            ['password' => 'secret12', 'password_confirmation' => 'secret12'],
            ['password' => 'required|min:8|confirmed'],
        );

        $this->assertTrue($validator->passes());
    }

    public function test_confirmed_rule_fails_when_mismatched(): void
    {
        $validator = Validator::make(
            ['password' => 'secret12', 'password_confirmation' => 'different'],
            ['password' => 'required|min:8|confirmed'],
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('password', $validator->errors());
    }

    public function test_nullable_skips_rules_when_absent(): void
    {
        $validator = Validator::make([], ['nickname' => 'nullable|string|min:3']);

        $this->assertTrue($validator->passes());
    }

    public function test_between_message_interpolates_bounds(): void
    {
        $errors = Validator::make(['score' => '50'], ['score' => 'between:1,10'])->errors();

        $this->assertStringContainsString('between 1 and 10', $errors['score'][0]);
    }

    public function test_uses_translator_when_provided(): void
    {
        $translator = new Translator(\dirname(__DIR__, 4) . '/plugins/I18n/lang', 'en', 'en');
        $errors = Validator::make(['age' => '5'], ['age' => 'integer|min:18'], [], $translator)->errors();

        $this->assertSame('The age field must be at least 18.', $errors['age'][0]);
    }

    public function test_custom_message_override_wins(): void
    {
        $errors = Validator::make(
            ['email' => 'nope'],
            ['email' => 'email'],
            ['email.email' => 'Bad address.'],
        )->errors();

        $this->assertSame('Bad address.', $errors['email'][0]);
    }

    protected function tearDown(): void
    {
        Validator::flushExtensions();
    }

    public function test_builtin_http_url_and_timezone_rules(): void
    {
        $ok = Validator::make(
            ['site' => 'https://ex.com', 'tz' => 'Africa/Kampala'],
            ['site' => 'http_url', 'tz' => 'timezone'],
        );
        $this->assertTrue($ok->passes());

        $bad = Validator::make(
            ['site' => 'ftp://ex.com', 'tz' => 'Mars/Olympus'],
            ['site' => 'http_url', 'tz' => 'timezone'],
        )->errors();
        $this->assertArrayHasKey('site', $bad);
        $this->assertArrayHasKey('tz', $bad);
    }

    public function test_extend_registers_a_closure_rule(): void
    {
        Validator::extend('even', static fn($v): bool => (int) $v % 2 === 0, 'The :field must be even.');

        $errors = Validator::make(['n' => 3], ['n' => 'even'])->errors();
        $this->assertSame('The n must be even.', $errors['n'][0]);
        $this->assertTrue(Validator::make(['n' => 4], ['n' => 'even'])->passes());
    }

    public function test_extend_with_class_ruleset_ci_style(): void
    {
        Validator::extendWith(new class {
            public function slug(mixed $v, ?string $p, array $d): bool
            {
                return is_string($v) && preg_match('/^[a-z0-9-]+$/', $v) === 1;
            }

            /** @return array<string,string> */
            public function messages(): array
            {
                return ['slug' => 'The :field must be kebab-case.'];
            }
        });

        $errors = Validator::make(['s' => 'Bad Slug'], ['s' => 'slug'])->errors();
        $this->assertSame('The s must be kebab-case.', $errors['s'][0]);
    }

    public function test_named_rule_group_ci_style(): void
    {
        Validator::defineGroup('login', ['email' => 'required|email'], ['email.required' => 'Need email.']);

        $errors = Validator::group('login', [])->errors();
        $this->assertSame('Need email.', $errors['email'][0]);
    }

    public function test_unknown_group_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::group('nope', []);
    }

    public function test_common_rules_pack_registers_and_validates(): void
    {
        Validator::extendWith(\Plugins\Validation\Rules\CommonRules::class);

        $ok = Validator::make(
            ['id' => '550e8400-e29b-41d4-a716-446655440000', 'qty' => '12', 'ip' => '192.168.0.1'],
            ['id' => 'uuid', 'qty' => 'is_natural_no_zero', 'ip' => 'ipv4'],
        );
        $this->assertTrue($ok->passes());

        $bad = Validator::make(
            ['id' => 'nope', 'qty' => '0', 'when' => '2020-13-40'],
            ['id' => 'uuid', 'qty' => 'is_natural_no_zero', 'when' => 'date_format:Y-m-d'],
        )->errors();
        $this->assertArrayHasKey('id', $bad);
        $this->assertArrayHasKey('qty', $bad);
        $this->assertArrayHasKey('when', $bad);
    }

    public function test_financial_rules_pack(): void
    {
        Validator::extendWith(\Plugins\Validation\Rules\FinancialRules::class);

        $ok = Validator::make(
            ['card' => '4111111111111111', 'iban' => 'GB82WEST12345698765432', 'cvv' => '123'],
            ['card' => 'credit_card', 'iban' => 'iban', 'cvv' => 'cvv'],
        );
        $this->assertTrue($ok->passes());

        $bad = Validator::make(
            ['card' => '4111111111111112', 'iban' => 'GB00WEST12345698765432'],
            ['card' => 'credit_card', 'iban' => 'iban'],
        )->errors();
        $this->assertArrayHasKey('card', $bad);
        $this->assertArrayHasKey('iban', $bad);
    }
}
