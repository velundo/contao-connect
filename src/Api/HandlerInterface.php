<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Api;

use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Http\Result;

/**
 * Ein Bereich der Schnittstelle (pages, content, …) führt die vom Router bestimmte Aktion aus.
 */
interface HandlerInterface
{
    public function handle(string $action, ApiRequest $request): Result;
}
