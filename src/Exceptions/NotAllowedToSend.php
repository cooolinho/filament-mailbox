<?php

namespace Cooolinho\FilamentMailbox\Exceptions;

use RuntimeException;

/**
 * The sender of a queued message may no longer send from the mailbox.
 */
class NotAllowedToSend extends RuntimeException {}
