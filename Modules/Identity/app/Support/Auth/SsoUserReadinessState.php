<?php

namespace Modules\Identity\Support\Auth;

/**
 * WAVE-5 readiness labels for applicable target Users.
 */
final class SsoUserReadinessState
{
    public const READY = 'ready';

    public const EXCEPTION = 'exception';

    public const NOT_READY = 'not_ready';

    public const INELIGIBLE = 'ineligible';
}
