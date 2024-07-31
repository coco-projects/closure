<?php

    use Coco\closure\ClosureUtils;

    require '../vendor/autoload.php';

    $a = function($data) {
        return $data * 10;
    };

    $s = ClosureUtils::serialize($a);

    $result = ClosureUtils::unserialize($s);

    //200
    echo $result(20);