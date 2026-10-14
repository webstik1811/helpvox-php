<?php

declare(strict_types=1);

namespace HelpVox\Exception;

/**
 * An expected failure inside a tool handler. The message is short, plain text the
 * agent can tell the customer, e.g. "No loyalty account for this phone number."
 * It is sent back as `{"error": "<message>"}` with HTTP 200.
 */
class ToolError extends HelpVoxException
{
}
