<?php

    declare (strict_types = 1);

    namespace Coco\closure;

    class SelfReference
    {
        public string $hash;

        public function __construct(string $hash)
        {
            $this->hash = $hash;
        }
    }