<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use RuntimeException;

/**
 * A credential could not be stored, or could not be read back after being stored.
 *
 * **The message must never carry the credential.** This is thrown on a path where the token is in
 * hand, so it is the easiest place in the application to leak one into a log.
 */
final class CredentialStoreFailed extends RuntimeException {}
