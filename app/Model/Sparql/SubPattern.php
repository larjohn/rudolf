<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 03/03/2016
 * Time: 14:39:11
 */

namespace App\Model\Sparql;


class SubPattern extends SparqlPattern
{

    public $patterns;

    public function __construct(array $patterns = [], $isOptional=false)
    {
        parent::__construct($isOptional);
        $this->patterns = $patterns;
    }


    public function add(SparqlPattern $pattern){
        foreach ($this->patterns as $existing_pattern) {
            if($pattern->sameAs($existing_pattern)) return;
        }
        $this->patterns[] = $pattern;
    }

}