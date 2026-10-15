<?php

namespace App\Domains\LeadSources\Contracts;

/** Drivers that run on the user's own key (BYOK) and can check it for free. */
interface VerifiesCredentials
{
    /** @throws \RuntimeException when the provider rejects the key */
    public function verify(): void;
}
