<?php

declare(strict_types=1);

namespace Typesense\Bundle\Bridge\Symfony;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What the indexer does with a failure to index, in a Symfony application:
 * a warning in the current session's flash bag ("Typesense 503: ..."), as
 * the bundle always did. Without a request or a session, nothing.
 */
final class IndexingWarning
{
    public function __construct(private readonly ?RequestStack $requestStack = null)
    {
    }

    public function __invoke(\Throwable $e): void
    {
        $request = $this->requestStack?->getCurrentRequest();
        $session = $request?->hasSession() ? $request->getSession() : null;
        if ($session && method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add('warning', 'Typesense ' . $e->getCode() . ': ' . $e->getMessage());
        }
    }
}
