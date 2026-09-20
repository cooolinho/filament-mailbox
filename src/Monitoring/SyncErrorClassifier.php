<?php

namespace Cooolinho\FilamentMailbox\Monitoring;

use Cooolinho\FilamentMailbox\Enums\SyncErrorType;
use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Exceptions\OAuthReconnectRequired;
use Cooolinho\FilamentMailbox\Exceptions\ProviderOperationFailed;
use Cooolinho\FilamentMailbox\Exceptions\SyncFailed;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\QueryException;
use League\Flysystem\FilesystemException;
use Throwable;

/**
 * Classifies synchronisation errors for metrics and alerts. Provider
 * exceptions only carry redacted messages, so the message is inspected too.
 */
class SyncErrorClassifier
{
    public function classify(Throwable $exception): SyncErrorType
    {
        return match (true) {
            $exception instanceof OAuthReconnectRequired, $exception instanceof SyncFailed && $exception->permanent => SyncErrorType::Authentication,
            $exception instanceof FilesystemException, $exception instanceof FileNotFoundException, $exception instanceof QueryException => SyncErrorType::Storage,
            default => $this->fromMessage($exception->getMessage()) ?? $this->fromClass($exception instanceof SyncFailed && $exception->cause ? $exception->cause : $exception::class),
        };
    }

    /**
     * For errors that are only stored as text, e.g. per folder in SyncResult.
     */
    public function classifyMessage(string $message): SyncErrorType
    {
        return $this->fromMessage($message) ?? (str_contains(strtolower($message), 'connection') ? SyncErrorType::Connection : SyncErrorType::Unknown);
    }

    /**
     * @param  class-string  $class
     */
    protected function fromClass(string $class): SyncErrorType
    {
        return match (true) {
            is_a($class, OAuthReconnectRequired::class, true) => SyncErrorType::Authentication,
            is_a($class, ConnectionFailed::class, true) => SyncErrorType::Connection,
            is_a($class, ProviderOperationFailed::class, true), is_a($class, UnsupportedOperation::class, true) => SyncErrorType::Protocol,
            is_a($class, FilesystemException::class, true), is_a($class, QueryException::class, true) => SyncErrorType::Storage,
            default => SyncErrorType::Unknown,
        };
    }

    protected function fromMessage(string $message): ?SyncErrorType
    {
        $message = strtolower($message);

        return match (true) {
            (bool) preg_match('/authenticationfailed|authentication failed|invalid credentials|login failed|invalid_grant|unauthori[sz]ed|\b401\b|reconnect/', $message) => SyncErrorType::Authentication,
            (bool) preg_match('/\b429\b|too many requests|rate ?limit|throttl|quota exceeded/', $message) => SyncErrorType::RateLimited,
            (bool) preg_match('/timed out|timeout/', $message) => SyncErrorType::Timeout,
            default => null,
        };
    }
}
