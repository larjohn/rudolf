<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 03/03/2016
 * Time: 14:36:38
 */

namespace App\Model\Sparql;


class TriplePattern extends SparqlPattern
{
    public $subject;
    public $object;
    public $predicate;
    public function __construct(string $subject, string $predicate, string $object, bool $isOptional=false, $transitivity = null)
    {
        parent::__construct($isOptional) ;


        $this->subject = $subject;
        $this->predicate = $predicate;
        $this->object = $object;
        $this->transitivity = $transitivity;
    }

    public function sameAs($existing_pattern)
    {
        if($existing_pattern instanceof TriplePattern)
            return $this->subject==$existing_pattern->subject && $this->predicate==$existing_pattern->predicate && $this->object==$existing_pattern->object;
        else return false;
    }

    public $transitivity = null;

    public function id()
    {
        return "{$this->subject}|{$this->predicate}|{$this->object}";
    }
}