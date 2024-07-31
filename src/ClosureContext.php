<?php

    declare (strict_types = 1);

    namespace Coco\closure;

    class ClosureContext
    {
        public ClosureScope $scope;

        public int $locks;

        public function __construct()
        {
            $this->scope = new ClosureScope();
            $this->locks = 0;
        }
    }