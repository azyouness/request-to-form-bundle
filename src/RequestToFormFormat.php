<?php

namespace AzYouness\RequestToFormBundle;

final class RequestToFormFormat
{
    public const JSON = 'json';
    public const FORM = 'form';
    public const QUERY = 'query';

    public const ALL = [
        self::JSON,
        self::FORM,
        self::QUERY,
    ];

    private function __construct()
    {
    }
}
