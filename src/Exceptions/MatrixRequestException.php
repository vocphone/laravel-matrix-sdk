<?php

namespace Vocphone\LaravelMatrixSdk\Exceptions;
/**
 * The home server returned an error response.
 *
 * @package MatrixPhp\Exceptions
 */
class MatrixRequestException extends MatrixException {

    public string $errCode;
    protected int $httpCode = 0;
    protected string $content = '';


    public function __construct(
        $httpCode = 0,
        $content = ''
    ) {
        $this->httpCode = $httpCode;
        $this->content = $content;
        parent::__construct($content, $httpCode);
        try {
            $decoded = \json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
            $this->errCode = $decoded['errcode'] ?? NULL;
        }
        catch (\JsonException $e ) {
            $this->errCode = NULL;
        }
    }

    /**
     * @return int
     */
    public function getHttpCode(): int {
        return $this->getCode();
    }

    /**
     * @return string
     */
    public function getContent(): string {
        return $this->content;
    }
}
