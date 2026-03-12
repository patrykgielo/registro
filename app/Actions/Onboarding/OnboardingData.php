<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

readonly class OnboardingData
{
    public function __construct(
        public string $orgName,
        public string $slug,
        public string $bookingType,
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $password,
    ) {}
}
