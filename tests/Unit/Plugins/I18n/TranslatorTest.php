<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\I18n\Translator;

#[CoversClass(Translator::class)]
final class TranslatorTest extends TestCase
{
    private function translator(): Translator
    {
        // Locate the catalogue from the Translator class itself rather than a
        // hardcoded plugins/ path. Plugins now ship as composer packages, so
        // their files live under vendor/ (a symlink into the plugins workspace)
        // — a repo-relative path would silently break the moment the plugin is
        // consumed as a dependency rather than a sibling directory.
        $pluginRoot = \dirname((new \ReflectionClass(Translator::class))->getFileName());

        return new Translator($pluginRoot . '/lang', 'en', 'en');
    }

    public function test_resolves_a_key_with_interpolation(): void
    {
        $this->assertSame(
            'The email field is required.',
            $this->translator()->get('validation.required', ['field' => 'email']),
        );
    }

    public function test_interpolates_rule_params(): void
    {
        $this->assertSame(
            'The age field must be at least 18.',
            $this->translator()->get('validation.min', ['field' => 'age', 'min' => 18]),
        );
    }

    public function test_missing_key_falls_back_to_the_key_itself(): void
    {
        $this->assertSame('does.not.exist', $this->translator()->get('does.not.exist'));
    }

    public function test_has_reports_presence(): void
    {
        $t = $this->translator();

        $this->assertTrue($t->has('validation.email'));
        $this->assertFalse($t->has('validation.nope'));
    }

    public function test_path_traversal_segments_are_ignored(): void
    {
        $this->assertSame('../etc.passwd', $this->translator()->get('../etc.passwd'));
    }
}
