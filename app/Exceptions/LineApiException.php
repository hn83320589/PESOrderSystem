<?php

namespace App\Exceptions;

use RuntimeException;

class LineApiException extends RuntimeException
{
    /**
     * @param  bool  $retryable  429、5xx、連線失敗可重試；4xx（如對方封鎖、參數錯誤）重試無意義
     */
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }
}
