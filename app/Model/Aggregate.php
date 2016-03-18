<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 16/03/2016
 * Time: 13:40:44
 */

namespace App\Model;


class Aggregate
{

    /**
     * @var string
     */
    public $label;

    /**
     * @var string
     */
    public $measure;

    /**
     * @var string
     */
    public $ref;

    /**
     * @var string
     */
    public $function;

    public static $functions = ["sum"];

}