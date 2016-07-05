<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 20/03/2016
 * Time: 23:19:48
 */

namespace App\Model;


use App\Model\Sparql\SubPattern;
use App\Model\Sparql\TriplePattern;
use Asparagus\QueryBuilder;
use EasyRdf_Literal;
use EasyRdf_Literal_Decimal;
use EasyRdf_Literal_Integer;
use EasyRdf_Sparql_Result;
use Log;

class FactsResult extends SparqlModel
{


    public $status;
    public $page;
    public $page_size;
    public $data;
    public $order;
    public $cells;
    public $total_fact_count;
    public $fields;


    public function __construct(string $name, $page, $page_size, array $fields, array $orders, array $cuts)
    {
        parent::__construct();
        $sorters = [];
        foreach ($orders as $order) {
            $newSorter = new Sorter($order);
            $sorters[$newSorter->property] = $newSorter;
            $this->order[]=[$newSorter->property, $newSorter->direction];
        }

        $filters = [];
        foreach ($cuts as $cut) {
            $newFilter = new FilterDefinition($cut);
            $filters[$newFilter->property] = $newFilter;
            $this->cells[]=["operator"=>":", "ref"=> $newFilter->property, "value"=> $newFilter->value];

        }

        $this->load($name, $page, $page_size, $fields, $sorters, $filters);
        $this->status = "ok";
    }



    /**
     * @param $name
     * @param $page
     * @param $page_size
     * @param $fields
     * @param Sorter[] $sorters
     * @param FilterDefinition[] $filters
     * @return array
     */
    public function load($name, $page, $page_size, $fields, array $sorters, array $filters)
    {

        $model = (new BabbageModelResult($name))->model;
       // return $facts;
        $selectedPatterns = $this->modelFieldsToPatterns($model,$fields);

        $offset = $page_size * $page ;

        $dimensions = $model->dimensions;
        $measures = $model->measures;

        /** @var Sorter[] $sorterMap */
        $sorterMap = [] ;
        foreach ($sorters as $sorter){
            $path = explode('.',$sorter->property);
            $fullName = $this->getAttributePathByName($model, $path );
            $this->array_set($sorterMap, $fullName, $sorter);
        }
        /** @var FilterDefinition[] $filterMap */
        $filterMap = [] ;
        foreach ($filters as $filter){
            $path = explode('.',$filter->property);
            $fullName = $this->getAttributePathByName($model, $path );
            $this->array_set($filterMap, $fullName, $filter);
        }
        $attributes = [];
        $bindings = [];
        $patterns = [];
        $finalSorters = [];
        $finalFilters = [];
        /** @var GenericProperty[] $selectedDimensions */
        $selectedDimensions= [];
        foreach ($dimensions as $dimensionName=>$dimension) {
            if(!isset($selectedPatterns[$dimension->getUri()])) continue;
            $selectedDimensions[$dimension->getUri()] = $dimension;
            $bindingName = "binding_" . substr(md5($dimensionName),0,5);
            $valueAttributeLabel = "uri";

            $attributes[$dimension->getUri()][$valueAttributeLabel] = $bindingName;
            $bindings[$dimension->getUri()] = "?$bindingName";
        }
        foreach ($measures as $measureName=>$measure) {
            if(!isset($selectedPatterns[$measure->getUri()])) continue;
            $selectedDimensions[$measure->getUri()] = $measure;
            $bindingName = "binding_" .  substr(md5($measure->getUri()),0,5);
            $valueAttributeLabel = "value";

            $attributes[$measure->getUri()][$valueAttributeLabel] = $bindingName;
            $bindings[$measure->getUri()] = "?$bindingName";
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
                $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $bindings[$attribute] , false));
            }
            else{
                $patterns [] = new TriplePattern("?observation", $attribute, $bindings[$attribute], false);
            }
            if(isset($sorterMap[$attribute]) && $sorterMap[$attribute] instanceof Sorter){
                $sorterMap[$attribute]->binding = $bindings[$attribute];
                $finalSorters[] = $sorterMap[$attribute] ;
            }
            if(isset($filterMap[$attribute]) && $filterMap[$attribute] instanceof FilterDefinition){
                $filterMap[$attribute]->binding = $bindings[$attribute];
                $finalFilters[] = $filterMap[$attribute];
            }

            if($dimension instanceof Dimension){
                $dimensionPatterns = &$selectedPatterns[$attribute];
                foreach ($dimensionPatterns as $patternName=>$dimensionPattern){
                    $attributes[$attribute][$patternName] = $attributes[$attribute]["uri"]."_". substr(md5($patternName),0,5) ;
                    $bindings[] = $bindings[$attribute]."_". substr(md5($patternName),0,5) ;
                    if(isset($sorterMap[$attribute][$patternName])){
                        $sorterMap[$attribute][$patternName]->binding = $bindings[$attribute]."_". substr(md5($patternName),0,5) ;
                        $finalSorters[] =  $sorterMap[$attribute][$patternName] ;

                    }
                    if(isset($filterMap[$attribute][$patternName])){
                        $filterMap[$attribute][$patternName]->binding = $bindings[$attribute]."_". substr(md5($patternName),0,5) ;
                        $finalFilters[] = $filterMap[$attribute][$patternName];

                    }
                    if(isset($attachment) && $attachment=="qb:Slice"){
                        $sliceSubGraph->add(new TriplePattern($bindings[$attribute],$patternName,$bindings[$attribute]."_". substr(md5($patternName),0,5), true));
                    }
                    else{
                        $patterns [] = new TriplePattern($bindings[$attribute],$patternName,$bindings[$attribute]."_". substr(md5($patternName),0,5), true);


                    }


                }


            }
        }
        // echo($queryBuilder->format());
        $bindings[] = "?observation";
//dd($bindings);
        //dd($patterns);
        if($needsSliceSubGraph){
            $patterns[] = $sliceSubGraph;

        }
        $dataset = $model->getDataset();
        //$dsd = $model->getDsd();
        $patterns[] = new TriplePattern('?observation', 'a', 'qb:Observation');
        $patterns[] = new TriplePattern('?observation', 'qb:dataSet', "<$dataset>");


        $queryBuilderC = $this->build(["(count(?observation) as ?_count)"], $patterns,$finalFilters );
        /** @var EasyRdf_Sparql_Result $countResult */
        $countResult = $this->sparql->query(
            $queryBuilderC->getSPARQL()
        );
        $count = $countResult[0]->_count->getValue();
        $queryBuilder = $this->build($bindings, $patterns, $finalFilters );




        $queryBuilder

            ->limit($page_size )
            ->offset($offset);


        foreach ($finalSorters as $sorter) {
            $queryBuilder->orderBy($sorter->binding, strtoupper($sorter->direction));
        }
        $queryBuilder
            ->orderBy("?observation");
        Log::info($queryBuilder->format());

//echo  $queryBuilder->format();
//
     //   dd($bindings);
      //   die;
        /** @var EasyRdf_Sparql_Result $result */
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        //echo($result->dump());
//dd($selectedPatterns);
        //dd($attributes);
        $results = $this->rdfResultsToArray3($result,$attributes, $model, $selectedPatterns);

        $this->data = $results;
        $this->page_size = $page_size;
        $this->page = $page;
        $this->total_fact_count = $count;
        $this->fields = $fields;


    }

    /**
     * @param array $bindings
     * @param Dimension[] $dimensionPatterns
     * @param FilterDefinition[] $filterMap
     * @return QueryBuilder
     */
    private function build(array $bindings, array $dimensionPatterns, array $filterMap =[]){
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));

        foreach ($dimensionPatterns as $dimensionPattern) {
            if($dimensionPattern instanceof TriplePattern || ($dimensionPattern instanceof SubPattern && !$dimensionPattern->isOptional)){
                if($dimensionPattern->isOptional){
                    $queryBuilder->optional($dimensionPattern->subject,  self::expand($dimensionPattern->predicate), $dimensionPattern->object);
                }
                else{
                    $queryBuilder->where($dimensionPattern->subject,  self::expand($dimensionPattern->predicate), $dimensionPattern->object);
                }
            }
            elseif($dimensionPattern instanceof SubPattern){
                $subGraph = $queryBuilder->newSubgraph();

                foreach($dimensionPattern->patterns as $pattern){

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

        foreach ($filterMap as $filter) {
            $queryBuilder->filter("str(".$filter->binding.")='".$filter->value."'");
        }

        $queryBuilder
            ->selectDistinct($bindings)

        ;


        return $queryBuilder;

    }




}