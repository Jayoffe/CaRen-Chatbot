<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ProviderException extends RuntimeException
{
    private $provider;
    private $publicCode;
    private $publicMessage;
    private $httpStatus;
    private $retryable;
    private $retryAfter;

    public function __construct($provider, $publicCode, $publicMessage, $httpStatus, $retryable, $retryAfter = null)
    {
        parent::__construct($publicMessage);
        $this->provider = $provider;
        $this->publicCode = $publicCode;
        $this->publicMessage = $publicMessage;
        $this->httpStatus = (int) $httpStatus;
        $this->retryable = (bool) $retryable;
        $this->retryAfter = $retryAfter === null ? null : (int) $retryAfter;
    }

    public function getProvider()
    {
        return $this->provider;
    }

    public function getPublicCode()
    {
        return $this->publicCode;
    }

    public function getPublicMessage()
    {
        return $this->publicMessage;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    public function isRetryable()
    {
        return $this->retryable;
    }

    public function getRetryAfter()
    {
        return $this->retryAfter;
    }
}
