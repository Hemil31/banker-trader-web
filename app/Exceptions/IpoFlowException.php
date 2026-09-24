<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain error in the IPO flow (e.g. checking allotment before the
 * application is placed or before the allotment date). Surfaced as a 422 by
 * the API controllers.
 */
class IpoFlowException extends RuntimeException {}
