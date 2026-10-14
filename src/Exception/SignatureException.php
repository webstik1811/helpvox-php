<?php

declare(strict_types=1);

namespace HelpVox\Exception;

/** The request did not carry a valid, fresh HelpVox-Signature. Answer it with 401. */
class SignatureException extends HelpVoxException
{
}
