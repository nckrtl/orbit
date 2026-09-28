<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Extensions;

final class DisableExtensionRequest extends SetExtensionRequest
{
    protected function action(): string
    {
        return 'disable';
    }
}
