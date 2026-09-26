<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Exceptions;

use RuntimeException;

/**
 * Base class of every error sluggable raises, so a host can catch the whole family at once.
 */
abstract class SluggableException extends RuntimeException {}
