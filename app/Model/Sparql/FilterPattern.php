<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 02/06/2016
 * Time: 02:02:02
 */

namespace App\Model\Sparql;


class FilterPattern extends SparqlPattern
{
    public $expression;
    public function __construct(string $expression)
    {
        parent::__construct(false) ;

        $this->expression = $expression;

    }

    public function sameAs($existing_pattern)
    {
        if($existing_pattern instanceof FilterPattern)
            return $this->expression==$existing_pattern->expression;
        else return false;
    }

    public function id()
    {
        return $this->expression;
    }


}