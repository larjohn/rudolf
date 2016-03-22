<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 22/03/2016
 * Time: 11:05:33
 */

namespace App\Model;


use App\Model\Sparql\SubPattern;
use App\Model\Sparql\TriplePattern;
use Asparagus\QueryBuilder;

class MembersResult extends SparqlModel
{
    public $data;
    public $status;
    public function __construct($name, $dimension)
    {
        parent::__construct();


        $this->load($name, $dimension);

        $this->status = "ok";
    }

    private function load($name, $dimension)
    {
        $model = (new BabbageModelResult($name))->model;
        // return $facts;
        $dimensions = $model->dimensions;

        $actualDimension = $model->dimensions[explode('.',$dimension)[0]];
        $selectedPatterns = $this->modelFieldsToPatterns($model,[$actualDimension->label_ref, $actualDimension->key_ref]);
        $selectedDimensions = [];
        $bindings = [];
        $attributes = [];
        foreach ($dimensions as $dimensionName=>$dimension) {
            if(!isset($selectedPatterns[$dimension->getUri()])) continue;
            $selectedDimensions[$dimension->getUri()] = $dimension;
            $bindingName = "binding_" . md5($dimensionName);
            $valueAttributeLabel = "uri";

            $attributes[$dimension->getUri()][$valueAttributeLabel] = $bindingName;
            $bindings[$dimension->getUri()] = "?$bindingName";
            break;
        }



        $sliceSubGraph = new SubPattern([
            new TriplePattern("?slice", "a", "qb:Slice"),
            new TriplePattern("?slice", "qb:observation", "?observation"),

        ], true);


        $needsSliceSubGraph = false;
        foreach ($selectedDimensions as $dimensionName=>$dimension) {
            $attribute = $dimensionName;
            $attachment = $dimension->getAttachment();
            if(isset($attachment) && $attachment=="qb:Slice"){
                $needsSliceSubGraph = true;
                $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $bindings[$attribute] , true));
            }
            else{
                $patterns [] = new TriplePattern("?observation", $attribute, $bindings[$attribute], true);
            }

            if($dimension instanceof Dimension){

//dd($dimension->attributes[$dimension->key_attribute]->getUri());
                if($dimension->ref!=$dimension->key_attribute){
                    $attributes[$attribute][$dimension->attributes[$dimension->key_attribute]->getUri()] = $attributes[$attribute]["uri"]."_".md5($dimension->key_attribute) ;
                    $bindings[] = $bindings[$attribute]."_".md5($dimension->key_attribute) ;

                }

                if($dimension->ref!=$dimension->label_attribute){
                    $attributes[$attribute][$dimension->attributes[$dimension->label_attribute]->getUri()] = $attributes[$attribute]["uri"]."_".md5($dimension->label_attribute) ;

                    $bindings[] = $bindings[$attribute]."_".md5($dimension->label_attribute) ;
                }



                if(isset($attachment) && $attachment=="qb:Slice"){
                    $sliceSubGraph->add(new TriplePattern($bindings[$attribute],$dimension->attributes[$dimension->key_attribute]->getUri(),$bindings[$attribute]."_".md5($dimension->key_attribute), true));
                    $sliceSubGraph->add(new TriplePattern($bindings[$attribute],$dimension->attributes[$dimension->label_attribute]->getUri(),$bindings[$attribute]."_".md5($dimension->label_attribute), true));
                }
                else{
                    if($dimension->ref!=$dimension->key_attribute)
                        $patterns [] = new TriplePattern($bindings[$attribute],$dimension->attributes[$dimension->key_attribute]->getUri(),$bindings[$attribute]."_".md5($dimension->key_attribute), true);
                    if($dimension->ref!=$dimension->label_attribute)
                        $patterns [] = new TriplePattern($bindings[$attribute],$dimension->attributes[$dimension->label_attribute]->getUri(),$bindings[$attribute]."_".md5($dimension->label_attribute), true);

                }


            }
        }


        if($needsSliceSubGraph){
            $patterns[] = $sliceSubGraph;

        }
        $dataset = $model->getDataset();
        //$dsd = $model->getDsd();
        $patterns[] = new TriplePattern('?observation', 'a', 'qb:Observation');
        $patterns[] = new TriplePattern('?observation', 'qb:dataSet', "<$dataset>");


        $queryBuilder = $this->build($bindings, $patterns );
        $queryBuilder->limit(100);
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
       // dd($selectedPatterns);
        $results = $this->rdfResultsToArray3($result,$attributes, $model, $selectedPatterns);

        $this->data = $results;
    }


    private function build(array $bindings, array $filters){
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));

        foreach ($filters as $filter) {
            if($filter instanceof TriplePattern || ($filter instanceof SubPattern && !$filter->isOptional)){
                if($filter->isOptional){
                    $queryBuilder->optional($filter->subject,  self::expand($filter->predicate), $filter->object);
                }
                else{
                    $queryBuilder->where($filter->subject,  self::expand($filter->predicate), $filter->object);
                }
            }
            elseif($filter instanceof SubPattern){
                $subGraph = $queryBuilder->newSubgraph();

                foreach($filter->patterns as $pattern){

                    if($pattern->isOptional){
                        $subGraph->optional($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                    }
                    else{
                        $subGraph->where($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                    }
                }

                $queryBuilder->optional($subGraph);


            }
        }

        $queryBuilder
            ->selectDistinct($bindings)

        ;


        return $queryBuilder;

    }


}