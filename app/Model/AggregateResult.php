<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 22/03/2016
 * Time: 18:22:54
 */

namespace App\Model;


use App\Model\Sparql\SubPattern;
use App\Model\Sparql\TriplePattern;
use Asparagus\QueryBuilder;
use EasyRdf_Sparql_Result;

class AggregateResult extends SparqlModel
{

    public $page;
    public $page_size;
    public $total_cell_count;
    public $cell;
    public $status;
    public $cells;
    public $order;
    public $aggregates;
    public $summary;
    public $attributes;


    public function __construct($name, $page, $page_size, $aggregates, $drilldown, $orders, $cuts)
    {
        parent::__construct();

        $this->page = $page;
        $this->page_size = min($page_size,1000);
        $this->aggregates = $aggregates;
        $this->cell = [];
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
        $this->attributes = $drilldown;

        $this->aggregates = $aggregates;
        $this->load($name, $aggregates, $drilldown, $sorters, $filters);
        $this->status = "ok";

    }

    private function load($name, $aggregates, $drilldowns, $sorters, $filters)
    {
        $model = (new BabbageModelResult($name))->model;

//dd($aggregates);
        if(count($aggregates)<1||$aggregates[0]==""){
            foreach ($model->aggregates as $agg){
                if($agg->ref!="_count"){
                    $aggregates[]=$agg->ref;
                }
            }
        }

        // return $facts;
        $selectedAggregates = $this->modelFieldsToPatterns($model,$aggregates);
        $selectedDrilldowns = $this->modelFieldsToPatterns($model,$drilldowns);
//dd($selectedAggregates);

        $offset = $this->page_size * $this->page ;

        $dimensions = $model->dimensions;
        $measures = $model->measures;
        $finalSorters = [];
        $finalFilters = [];
        /** @var Sorter[] $sorterMap */
        $sorterMap = [] ;
        foreach ($sorters as $sorter){
            if($sorter->property == "_count") {
                $finalSorters[] = $sorter;
                continue;
            }
            if($sorter->property=="")continue;
            $path = explode('.',$sorter->property);
            $fullName = $this->getAttributePathByName($model, $path );
            if(empty($fullName))continue;

            $this->array_set($sorterMap, $fullName, $sorter);
        }
        //dd($sorterMap);
        /** @var FilterDefinition[] $filterMap */
        $filterMap = [] ;
        foreach ($filters as $filter){
            $path = explode('.',$filter->property);
            $fullName = $this->getAttributePathByName($model, $path );
            if(empty($fullName))continue;
            $this->array_set($filterMap, $fullName, $filter);
        }
        $attributes = [];
        $bindings = [];
        $patterns = [];

        /** @var GenericProperty[] $selectedAggregateDimensions */
        $selectedAggregateDimensions= [];
        /** @var GenericProperty[] $selectedDrilldownDimensions */
        $selectedDrilldownDimensions= [];
        $drilldownBindings = [];
        $aggregateBindings = [];
        foreach ($dimensions as $dimensionName=>$dimension) {
            if(!isset($selectedAggregates[$dimension->getUri()]) && !isset($selectedDrilldowns[$dimension->getUri()])) continue;
            $bindingName = "binding_" .  substr(md5($dimensionName),0,5);
            $valueAttributeLabel = "uri";
            $attributes[$dimension->getUri()][$valueAttributeLabel] = $bindingName;

            if(isset($selectedAggregates[$dimension->getUri()])){
                $selectedAggregateDimensions[$dimension->getUri()] = $dimension;
                $aggregateBindings[$dimension->getUri()] = "?$bindingName";
            }
            else{
                $selectedDrilldownDimensions[$dimension->getUri()] = $dimension;
                $drilldownBindings[$dimension->getUri()] = "?$bindingName";

            }

        }
        foreach ($measures as $measureName=>$measure) {
            if(!isset($selectedAggregates[$measure->getUri()]) ) continue;
            $selectedAggregateDimensions[$measure->getUri()] = $measure;
            $bindingName = "binding_" .  substr(md5($measure->getUri()),0,5);
            $valueAttributeLabel = "sum";
            $attributes[$measure->getUri()][$valueAttributeLabel] = $bindingName;
            $aggregateBindings[$measure->getUri()] = "?$bindingName";
        }





        $sliceSubGraph = new SubPattern([
            new TriplePattern("?slice", "a", "qb:Slice"),
            new TriplePattern("?slice", "qb:observation", "?observation"),

        ], false);


        $needsSliceSubGraph = false;

        foreach ($selectedDrilldownDimensions as $dimensionName=>$dimension) {
            $attribute = $dimensionName;
            $attachment = $dimension->getAttachment();
            if(isset($attachment) && $attachment=="qb:Slice"){
                $needsSliceSubGraph = true;
                $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $drilldownBindings[$attribute] , false));
            }
            else{
                $patterns [] = new TriplePattern("?observation", $attribute, $drilldownBindings[$attribute], false);
            }
            if(isset($sorterMap[$attribute]) && $sorterMap[$attribute] instanceof Sorter){
                $sorterMap[$attribute]->binding = $drilldownBindings[$attribute];
                $finalSorters[] = $sorterMap[$attribute] ;
            }
            if(isset($filterMap[$attribute]) && $filterMap[$attribute] instanceof FilterDefinition){
                if(!isset($drilldownBindings[$attribute]))continue;
                $filterMap[$attribute]->binding = $drilldownBindings[$attribute];
                $finalFilters[] = $filterMap[$attribute];
            }

            if($dimension instanceof Dimension){
                $dimensionPatterns = &$selectedDrilldowns[$attribute];
                foreach ($dimensionPatterns as $patternName=>$dimensionPattern){
                    $attributes[$attribute][$patternName] = $attributes[$attribute]["uri"]."_". substr(md5($patternName),0,5) ;
                    $drilldownBindings[] = $drilldownBindings[$attribute]."_". substr(md5($patternName),0,5) ;
                    if( isset($sorterMap[$attribute])&& is_array($sorterMap[$attribute]) && isset($sorterMap[$attribute][$patternName])){
                        $sorterMap[$attribute][$patternName]->binding = $drilldownBindings[$attribute]."_". substr(md5($patternName),0,5) ;
                        $finalSorters[] =  $sorterMap[$attribute][$patternName] ;

                    }
                    if(isset($filterMap[$attribute][$patternName])){
                        $filterMap[$attribute][$patternName]->binding = $drilldownBindings[$attribute]."_". substr(md5($patternName),0,5) ;
                        $finalFilters[] = $filterMap[$attribute][$patternName];

                    }
                    if(isset($attachment) && $attachment=="qb:Slice"){
                        $sliceSubGraph->add(new TriplePattern($drilldownBindings[$attribute],$patternName,$drilldownBindings[$attribute]."_". substr(md5($patternName),0,5), false));
                    }
                    else{
                        $patterns [] = new TriplePattern($drilldownBindings[$attribute],$patternName,$drilldownBindings[$attribute]."_". substr(md5($patternName),0,5), false);

                    }

                }

            }
        }


        foreach ($selectedAggregateDimensions as $dimensionName=>$dimension) {
            $attribute = $dimensionName;
            $attachment = $dimension->getAttachment();
            if(isset($attachment) && $attachment=="qb:Slice"){
                $needsSliceSubGraph = true;
                $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $aggregateBindings[$attribute] , false));
            }
            else{
                $patterns [] = new TriplePattern("?observation", $attribute, $aggregateBindings[$attribute], false);
            }
            if(isset($sorterMap[$attribute]) && $sorterMap[$attribute] instanceof Sorter){
                $sorterMap[$attribute]->binding = $aggregateBindings[$attribute];
                $finalSorters[] = $sorterMap[$attribute] ;
            }
            if(isset($filterMap[$attribute]) && $filterMap[$attribute] instanceof FilterDefinition){
                $filterMap[$attribute]->binding = $aggregateBindings[$attribute];
                $finalFilters[] = $filterMap[$attribute];
            }


        }
        // echo($queryBuilder->format());
        $bindings[] = "?observation";

        if($needsSliceSubGraph){
            $patterns[] = $sliceSubGraph;

        }
        $dataset = $model->getDataset();
        //$dsd = $model->getDsd();
        $patterns[] = new TriplePattern('?observation', 'a', 'qb:Observation');
        $patterns[] = new TriplePattern('?observation', 'qb:dataSet', "<$dataset>");

        $queryBuilderC = $this->buildC($drilldownBindings, $patterns,$finalFilters );
        /** @var EasyRdf_Sparql_Result $countResult */
        //echo($queryBuilderC->format());die;
       $countResult = $this->sparql->query(
            $queryBuilderC->getSPARQL()
        );


        $count = $countResult[0]->_count->getValue();


        $queryBuilderS = $this->buildS($aggregateBindings, $patterns,$finalFilters );
        /** @var EasyRdf_Sparql_Result $countResult */
        //echo($queryBuilderS->format());die;
       $summaryResult = $this->sparql->query(
            $queryBuilderS->getSPARQL()
        );
        $this->summary = $this->rdfResultsToArray3($summaryResult,$attributes, $model, array_merge($selectedAggregates))[0];
        $count = $countResult[0]->_count->getValue();
        $queryBuilder = $this->build( $aggregateBindings, $drilldownBindings, $patterns, $finalFilters );




        $queryBuilder

            ->limit($this->page_size )
            ->offset($offset);


        foreach ($finalSorters as $sorter) {
            if($sorter->property == "_count"){
                $queryBuilder->orderBy("?".$sorter->property, strtoupper($sorter->direction));
                continue;
            }
            $queryBuilder->orderBy($sorter->binding, strtoupper($sorter->direction));
        }
       /* $queryBuilder
            ->orderBy("?observation");*/



        // die;
        /** @var EasyRdf_Sparql_Result $result */
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );

        // $queryBuilder->format();
       // echo($result->dump());
//dd($selectedPatterns);
        //dd($attributes);
       // dd( array_merge($selectedAggregates,$selectedDrilldowns));
        $results = $this->rdfResultsToArray3($result,$attributes, $model, array_merge($selectedAggregates,$selectedDrilldowns));
//dd($results);
        $this->cells = $results;
        $this->total_cell_count = $count;

    }
    /**
     * @param array $bindings
     * @param Dimension[] $dimensionPatterns
     * @param FilterDefinition[] $filterMap
     * @return QueryBuilder
     */
    private function build( array $aggregateBindings, array $drilldownBindings, array $dimensionPatterns, array $filterMap =[]){
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));

        foreach ($dimensionPatterns as $dimensionPattern) {
            if($dimensionPattern instanceof TriplePattern ){
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

        $agBindings = [];
        foreach ($aggregateBindings as $binding) {
            $agBindings [] = "(sum($binding) AS $binding)";
        }
        $agBindings[]="(count(?observation) AS ?_count)";

        $drldnBindings = [];

        foreach ($drilldownBindings as $binding) {
            $drldnBindings [] = "$binding";
        }



        $queryBuilder
            ->selectDistinct(array_merge($agBindings, $drldnBindings))
        ;
        if(count($drilldownBindings)>0){
            $queryBuilder->groupBy($drldnBindings);
        }

        return $queryBuilder;

    }
   private function buildS( array $aggregateBindings, array $dimensionPatterns, array $filterMap =[]){
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));

        foreach ($dimensionPatterns as $dimensionPattern) {
            if($dimensionPattern instanceof TriplePattern ){
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

        $agBindings = [];
        foreach ($aggregateBindings as $binding) {
            $agBindings [] = "(sum($binding) AS $binding)";
        }
       $agBindings[]="(count(?observation) AS ?_count)";





        $queryBuilder
            ->selectDistinct($agBindings)
        ;


        return $queryBuilder;

    }
  private function buildC( array $drilldownBindings, array $dimensionPatterns, array $filterMap =[]){
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));

        $subQuery = $queryBuilder->newSubquery();

        foreach ($dimensionPatterns as $dimensionPattern) {
            if($dimensionPattern instanceof TriplePattern ){
                if($dimensionPattern->isOptional){
                    $subQuery->optional($dimensionPattern->subject,  self::expand($dimensionPattern->predicate), $dimensionPattern->object);
                }
                else{
                    $subQuery->where($dimensionPattern->subject,  self::expand($dimensionPattern->predicate), $dimensionPattern->object);
                }
            }
            elseif($dimensionPattern instanceof SubPattern){
                $subGraph = $subQuery->newSubgraph();

                foreach($dimensionPattern->patterns as $pattern){

                    if($pattern->isOptional){
                        $subGraph->optional($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                    }
                    else{
                        $subGraph->where($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                    }
                }

                $subQuery->optional($subGraph);
            }
        }

        foreach ($filterMap as $filter) {
            $subQuery->filter("str(".$filter->binding.")='".$filter->value."'");
        }


        $drldnBindings = [];

        foreach ($drilldownBindings as $binding) {
            $drldnBindings [] = "$binding";
        }



      $subQuery
            ->selectDistinct( $drldnBindings)
        ;

      $queryBuilder->subquery($subQuery);
      $queryBuilder->select("(count(*) AS ?_count)");

        return $queryBuilder;

    }



}