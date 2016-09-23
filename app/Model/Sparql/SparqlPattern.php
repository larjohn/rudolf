<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 03/03/2016
 * Time: 14:36:17
 */

namespace App\Model\Sparql;


abstract class SparqlPattern
{
    public $isOptional;

    public $onlyGlobalTriples = false;

    protected function __construct($isOptional)
    {
        $this->isOptional = $isOptional;
    }

    public function sameAs($existing_pattern)
    {
        return false;
    }

    public abstract function id();



}