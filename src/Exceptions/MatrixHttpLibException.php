<?php

namespace Vocphone\LaravelMatrixSdk\Exceptions;
/**
 * The library used for http requests raised an exception.
 *
 * @package MatrixPhp\Exceptions
 */
class MatrixHttpLibException extends MatrixException {

    public function __construct(\Throwable $originalException, string $method, string $endpoint) {
        $msg = sprintf(
            'Something went wrong in %s requesting %s: %s',
            $method,
            $endpoint,
            $originalException->getMessage(),
        );
        parent::__construct($msg, $originalException->getCode(), $originalException);
    }
}
