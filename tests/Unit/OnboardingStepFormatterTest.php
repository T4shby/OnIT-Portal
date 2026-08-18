<?php

namespace Tests\Unit;

use App\Support\OnboardingStepFormatter;
use Tests\TestCase;

class OnboardingStepFormatterTest extends TestCase
{
    public function test_formats_menu_paths_with_bold_segments(): void
    {
        $html = OnboardingStepFormatter::rich('Integrations → Microsoft Entra ID → Generate Tokens');

        $this->assertStringContainsString('onboarding-manual__path', $html);
        $this->assertStringContainsString('onboarding-manual__arrow', $html);
        $this->assertStringContainsString('Generate Tokens', $html);
    }

    public function test_formats_leading_label_when_no_menu_path(): void
    {
        $html = OnboardingStepFormatter::rich('Authentication method: Bearer authentication (leave selected - do not change).');

        $this->assertStringContainsString('onboarding-manual__term', $html);
        $this->assertStringContainsString('Bearer authentication', $html);
    }

    public function test_escapes_html_in_plain_text(): void
    {
        $html = OnboardingStepFormatter::rich('<script>alert(1)</script> and more');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
