<?php

namespace App\Domains\Onboarding;

final readonly class OnboardingStep
{
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public string $action,
        public string $url,
        public bool $done,
        public bool $required = true,
    ) {}
}
