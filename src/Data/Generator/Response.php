<?php

namespace Crescat\SaloonSdkGenerator\Data\Generator;

class Response
{
    public function __construct(
        public array $content,
        public string $description,
        public array $headers = [],
    ) {
    }
}
