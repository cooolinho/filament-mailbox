<?php

namespace Cooolinho\FilamentMailbox\Support;

/**
 * xtext encoding of SMTP DSN parameters (RFC 3461 §4): characters outside
 * "!" to "~" and "+" and "=" are written as "+XX". Prevents command
 * injection with CR/LF or spaces.
 */
class Xtext
{
    public static function encode(string $value): string
    {
        $encoded = '';

        foreach (str_split($value) as $char) {
            $code = ord($char);

            $encoded .= $code < 33 || $code > 126 || $char === '+' || $char === '='
                ? '+'.strtoupper(str_pad(dechex($code), 2, '0', STR_PAD_LEFT))
                : $char;
        }

        return $encoded;
    }
}
