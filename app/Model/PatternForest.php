<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 11/07/2017
 * Time: 13:28:06
 */

namespace App\Model;


class PatternForest
{

    /**
     * @var PatternTree[]
     */
    public $trees;

    public function generate(){

    }

    public function get(GenericProperty $entity){

        if(!isset($this->trees[$entity->ref])){
            $tree = new PatternTree($entity);
            $this->trees[$entity->ref] = $tree;
            return $tree;
        }
        else{
            return $this->trees[$entity->ref];
        }
    }

    public function merge(PatternTree $tree)
    {
        if(isset($this->trees[$tree->entity->ref])){
            $this->trees[$tree->entity->ref]->merge($tree);
        }
        else{
            $this->trees[$tree->entity->ref] = $tree;
        }
    }
}