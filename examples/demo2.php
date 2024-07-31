<?php

    use Coco\closure\ClosureUtils;

    require '../vendor/autoload.php';

    class AAA
    {
        public int $id;

        public mixed $closure;

        public function __construct(int $id)
        {
            $this->id = $id;
        }

        public function setClosure(mixed $closure): void
        {
            $this->closure = $closure;
        }

        public function getClosure(): mixed
        {
            return $this->closure;
        }
    }

    $a = function($data) {
        return $data * 10;
    };

    $obj = new AAA(123);
    $obj->setClosure($a);

    $s = ClosureUtils::serialize($obj);

    $result = ClosureUtils::unserialize($s);

    //50
    echo $result->getClosure()(5);


    // $s

    /*

    O:3:"AAA":2:{s:2:"id";i:123;s:7:"closure";O:32:"Coco\closure\SerializableClosure":5:{s:3:"use";a:0:{}s:8:"function";s:52:"function($data) {
            return $data * 10;
        }";s:5:"scope";N;s:4:"this";N;s:4:"self";s:32:"00000000000000020000000000000000";}}

     */