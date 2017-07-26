<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 11/07/2017
 * Time: 12:56:35
 */

namespace App\Model;


class PatternTree
{
    /**
     * @var GenericProperty
     */
    public $entity;

    public $root = null;

    public function __construct(GenericProperty $entity){
        $this->entity = $entity;
        $this->add(new PatternBranch($entity));
    }

    public function merge(PatternTree $tree){

    }

    public function add(PatternBranch $link)
    {
        if(!$this->root){
            $this->root = $link;
            $this->root->previous = null;
            $this->root->next = null;
        }
        else{
            /** @var PatternBranch $current */
            $current= $this->root;
            while ($current->next!=null){
                $current = $current->next;
            }
            $current->next = $link;
            $link->previous = $current;
            $link->next = null;
        }
    }

}