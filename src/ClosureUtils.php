<?php

    declare (strict_types = 1);

    namespace Coco\closure;

    class ClosureUtils
    {
        public static function serialize($data): string
        {
            SerializableClosure::enterContext();
            SerializableClosure::wrapClosures($data);
            $data = \serialize($data);
            SerializableClosure::exitContext();

            return $data;
        }

        public static function unserialize($data)
        {
            SerializableClosure::enterContext();
            $data = \unserialize($data);
            SerializableClosure::unwrapClosures($data);
            SerializableClosure::exitContext();

            return $data;
        }
    }