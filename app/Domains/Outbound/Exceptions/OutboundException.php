<?php

namespace App\Domains\Outbound\Exceptions;

use RuntimeException;

/** A connector call failed: invalid key, API error or unreachable host. */
class OutboundException extends RuntimeException {}
