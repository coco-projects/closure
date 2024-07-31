<?php

    declare (strict_types = 1);

    namespace Coco\closure;

    class ClosureScope extends \SplObjectStorage
    {
        public int $serializations = 0;

        public int $toserialize = 0;
    }