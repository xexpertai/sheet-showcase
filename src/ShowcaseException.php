<?php

declare(strict_types=1);

namespace SheetShowcase;

/**
 * Raised for any user-facing problem (unreadable file, bad config, ...).
 * Messages never contain absolute paths so they are safe to display.
 */
final class ShowcaseException extends \RuntimeException
{
}
