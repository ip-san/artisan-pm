<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by LdapAuthenticator::testConnection() when the directory can't
 * be reached, or (if a search account is configured) that account fails
 * to bind — matches Redmine's AuthSourceException raised from
 * AuthSourceLdap#test_connection.
 */
final class LdapConnectionTestException extends RuntimeException {}
