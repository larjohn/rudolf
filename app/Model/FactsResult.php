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
use URL;

class FactsResult extends SparqlModel
{


    public $status;
    public $page;
    public $page_size;
    public $data;
    public $order;
    public $cell;
    public $total_fact_count;
    public $fields;


    public function __construct(string $name, $page, $page_size, array $fields, array $orders, array $cuts)
    {
        parent::__construct();
        $sorters = [];
        $this->order = [];
        foreach ($orders as $order) {
            $newSorter = new Sorter($order);
            $sorters[$newSorter->property] = $newSorter;
            $this->order[]=[$newSorter->property, $newSorter->direction];
        }

        $filters = [];
        $this->cell = [];
        foreach ($cuts as $cut) {
            $newFilter = new FilterDefinition($cut);
            if(!isset($filters[$newFilter->property])){
                $filters[$newFilter->property] = $newFilter;
            }
            else{
                $filters[$newFilter->property]->addValue($cut);
            }
            $this->cell[] = ["operator" => ":", "ref" => $newFilter->property, "value" => $newFilter->value];

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
        if (count($fields) < 1 || $fields[0] == "") {
            $fields = [];
            foreach ($model->dimensions as $dimension) {
                if($dimension->getAttachment()!="qb:DataSet")
                $fields[] = $dimension->label_ref;


            }

            foreach ($model->measures as $measure) {

                $fields[] = $measure->ref;
            }

        }

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
       // dd($filterMap);
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

        $dataSetSubGraph = new SubPattern([
            new TriplePattern("?observation", "qb:dataSet", "<{$model->getDataset()}>"),

        ], true);


        $needsSliceSubGraph = false;
        $needsDataSetSubGraph = false;
        foreach ($selectedDimensions as $dimensionName=>$dimension) {
            $attribute = $dimensionName;
            $attachment = $dimension->getAttachment();
            if(isset($attachment) && $attachment=="qb:Slice"){
                $needsSliceSubGraph = true;
                $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $bindings[$attribute] , false));
            }
            elseif(isset($attachment) && $attachment=="qb:DataSet"){
                $needsDataSetSubGraph = true;
                $dataSetSubGraph->add(new TriplePattern( "<{$model->getDataset()}>", $attribute, $bindings[$attribute], false));

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
                    $actualAttribute = array_filter($dimension->attributes, function ($attribute)use($patternName){return $attribute->getUri()==$patternName;});

                    $attributes[$attribute][$patternName] = $attributes[$attribute]["uri"]."_". substr(md5($patternName),0,5) ;
                    $childBinding = $bindings[$attribute]."_". substr(md5($patternName),0,5) ;
                    $bindings[] = $childBinding;

                    $this->bindingsToLanguages[$childBinding] = reset($actualAttribute)->getLanguages();

                    if(isset($sorterMap[$attribute][$patternName])){
                        $sorterMap[$attribute][$patternName]->binding = $bindings[$attribute]."_". substr(md5($patternName),0,5) ;
                        $finalSorters[] =  $sorterMap[$attribute][$patternName] ;

                    }
                    //dd($filterMap);
                    if(isset($filterMap[$attribute])&&is_array($filterMap[$attribute])&&isset($filterMap[$attribute][$patternName])){
                        $filterMap[$attribute][$patternName]->binding = $bindings[$attribute]."_". substr(md5($patternName),0,5) ;
                        $finalFilters[] = $filterMap[$attribute][$patternName];

                    }
                    if(isset($attachment) && $attachment=="qb:Slice"){
                        $sliceSubGraph->add(new TriplePattern($bindings[$attribute],$patternName,$bindings[$attribute]."_". substr(md5($patternName),0,5), true));
                    }
                    if(isset($attachment) && $attachment=="qb:DataSet"){
                        $dataSetSubGraph->add(new TriplePattern($bindings[$attribute],$patternName,$bindings[$attribute]."_". substr(md5($patternName),0,5), true));
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
        if($needsDataSetSubGraph){
            $patterns[] = $dataSetSubGraph;

        }
        $dataset = $model->getDataset();
        //$dsd = $model->getDsd();
        $patterns[] = new TriplePattern('?observation', 'a', 'qb:Observation');
        $patterns[] = new TriplePattern('?observation', 'qb:dataSet', "<$dataset>");


        $queryBuilderC = $this->build(["(COUNT(?observation) AS ?_count)"], $patterns,$finalFilters );
        /** @var EasyRdf_Sparql_Result $countResult */

//echo $queryBuilderC->format();

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
//dump($results[94]);
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
                if ($dimensionPattern->predicate == "skos:prefLabel") {

                    $queryBuilder->filter($this->buildLanguageFilterExpression($dimensionPattern->object))->where($dimensionPattern->subject, self::expand($dimensionPattern->predicate, $dimensionPattern->transitivity), $dimensionPattern->object);


                }

                if($dimensionPattern->isOptional){
                    $queryBuilder->where($dimensionPattern->subject,  self::expand($dimensionPattern->predicate), $dimensionPattern->object);
                }
                else{
                    $queryBuilder->where($dimensionPattern->subject,  self::expand($dimensionPattern->predicate), $dimensionPattern->object);
                }
            }
            elseif($dimensionPattern instanceof SubPattern){

                foreach($dimensionPattern->patterns as $pattern){
                    $queryBuilder->where($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                    if ($pattern->predicate == "skos:prefLabel") {

                        $queryBuilder->filter( $this->buildLanguageFilterExpression($pattern->object) )->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);


                    }
                }



            }
        }

        foreach ($filterMap as $filter) {
            if(!$filter->isCardinal){
                $filter->value = trim($filter->value, '"');
                $filter->value = trim($filter->value, "'");

                $queryBuilder->filter("str({$filter->binding})='{$filter->value}'");
            }
            else{

                $values = [];
                foreach ($filter->values as $value){
                    $binding = ltrim($filter->binding, "?");
                    $val = trim($value, "'\"");
                    if(URL::isValidUrl($val)){
                        $val = "<{$val}>";
                    }
                    else{
                        $val = "'{$val}'";
                    }

                    $values[]=[$binding=>"$val"];
                }
                $queryBuilder->values($values);
            }
        }

        $queryBuilder
            ->select($bindings)

        ;

        return $queryBuilder;

    }




}