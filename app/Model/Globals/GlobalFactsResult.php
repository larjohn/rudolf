<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 13/05/2016
 * Time: 01:49:43
 */

namespace App\Model\Globals;


use App\Model\CurrencyService;
use App\Model\Dimension;
use App\Model\FactsResult;
use App\Model\FilterDefinition;
use App\Model\Measure;
use App\Model\Sorter;
use App\Model\Sparql\BindPattern;
use App\Model\Sparql\SubPattern;
use App\Model\Sparql\TriplePattern;
use App\Model\SparqlModel;
use Asparagus\GraphBuilder;
use Asparagus\QueryBuilder;
use EasyRdf_Sparql_Result;
use Illuminate\Database\Eloquent\Collection;
use URL;

class GlobalFactsResult extends FactsResult
{
    protected $subPropertiesAcceleration = [];
    private $currencyService ;


    public function __construct($page, $page_size, array $fields, array $orders, array $cuts)
    {
        SparqlModel::__construct();
        $this->currencyService = new CurrencyService();
        $sorters = [];
        foreach ($orders as $order) {
            $newSorter = new Sorter($order);
            $sorters[$newSorter->property] = $newSorter;
            $this->order[] = [$newSorter->property, $newSorter->direction];
        }

        $filters = [];
        foreach ($cuts as $cut) {
            $newFilter = new FilterDefinition($cut);
            if(!isset($filters[$newFilter->property])){
                $filters[$newFilter->property] = $newFilter;
            }
            else{
                $filters[$newFilter->property]->addValue($cut);
            }
            $this->cells[] = ["operator" => ":", "ref" => $newFilter->property, "value" => $newFilter->value];

        }
        $this->load2($page, $page_size, $fields, $sorters, $filters);
        $this->status = "ok";
    }


    /**
     * @param $page
     * @param $page_size
     * @param $fields
     * @param Sorter[] $sorters
     * @param FilterDefinition[] $filters
     * @return array
     */
    public function load2($page, $page_size, $fields, array $sorters, array $filters)
    {
        $model = (new BabbageGlobalModelResult())->model;
        // return $facts;

        if(empty($fields)||$fields[0]==""){
            foreach (array_slice($model->dimensions,0,0) as $dimensionName => $dimension) {
                $fields[]=$dimension->ref;
            }

            foreach ($model->measures as $measure) {
                $fields[]=$measure->ref;
            }
        }


        $selectedPatterns = $this->modelFieldsToPatterns($model, $fields);;
        $offset = $page_size * $page;

        $dimensions = $model->dimensions;
        //dd($dimensions);
        $measures = $model->measures;

        /** @var Sorter[] $sorterMap */
        $sorterMap = [];
        $sliceSubGraphs = [];
        $dataSetSubGraphs = [];
        foreach ($sorters as $sorter) {
            $path = explode('.', $sorter->property);
            $fullName = $this->getAttributePathByName($model, $path);
            $this->array_set($sorterMap, $fullName, $sorter);
        }
        /** @var FilterDefinition[] $filterMap */
        $filterMap = [];
        foreach ($filters as $filter) {
            $path = explode('.', $filter->property);
            $fullName = $this->getAttributePathByName($model, $path);
            $this->array_set($filterMap, $fullName, $filter);
        }
        $attributes = [];
        $bindings = [];
        $patterns = [];
        $finalSorters = [];
        $finalFilters = [];
        $selectedDimensions = [];
        $selectedMeasures = [];
        $dimensionBindings = [];
        $measureBindings = [];
        $parentDimensionBindings = [];
        $selectedInnerDimensions = [];
        $selectedInnerMeasures = [];
        foreach ($fields as $field) {
            $fieldElements = explode(".", $field);
            foreach ($model->dimensions as $dimension) {
                if ($dimension->ref == $fieldElements[0]) {

                    /** @var GlobalDimension $foundDimension */
                    $foundDimension = $dimension;
                    break;
                }
            }

            foreach ($model->measures as $measure) {
                if ($measure->ref == $fieldElements[0]) {
                    $foundMeasure = $measure;
                    break;
                }
            }
            if (!isset($foundDimension) && !isset($foundMeasure)) continue;

            if (isset($foundDimension)) {
                if ($field == $foundDimension->label_ref) {
                    $attributeModifier = "label_ref";
                    $attributeSimpleModifier = "label_attribute";
                } else {
                    $attributeModifier = "key_ref";
                    $attributeSimpleModifier = "key_attribute";

                }
                /** @var Dimension $innerDimension */
                foreach ($foundDimension->getInnerDimensions() as $innerDimensionName => $innerDimension) {
                    if (!isset($selectedDimensions[$innerDimension->getDataSet()])) $selectedDimensions[$innerDimension->getDataSet()] = [];

                    $selectedDimensions[$innerDimension->getDataSet()] = array_merge($selectedDimensions[$innerDimension->getDataSet()], $this->globalDimensionToPatterns([$innerDimension], [$innerDimension->$attributeModifier]));
                    $bindingName = "binding_" . substr(md5($foundDimension->ref), 0, 5);
                    $parentDimensionBindings["?" . $bindingName] = [];
                    $valueAttributeLabel = "uri";
                    $attributes[$innerDimension->getDataSet()][$innerDimension->getUri()][$valueAttributeLabel] = $bindingName;

                    $selectedInnerDimensions[$innerDimension->getDataSet()][$innerDimension->getUri()] = $innerDimension;
                    $dimensionBindings[$innerDimension->getDataSet()][$innerDimension->getUri()] = "?$bindingName";


                    if (isset($sliceSubGraphs[$innerDimension->getDataSet()][$foundDimension->getUri()]) && isset($sliceSubGraphs[$innerDimension->getDataSet()][$foundDimension->getUri()][0]))
                        $sliceSubGraph = $sliceSubGraphs[$innerDimension->getDataSet()][$foundDimension->getUri()][0];
                    else {
                        $sliceSubGraph = new SubPattern([
                            new TriplePattern("?slice", "a", "qb:Slice"),
                            new TriplePattern("?slice", "qb:observation", "?observation"),

                        ], false);
                    }

                    $needsSliceSubGraph = false;

                    if (isset($dataSetSubGraphs[$innerDimension->getDataSet()][$foundDimension->getUri()]) && isset($dataSetSubGraphs[$innerDimension->getDataSet()][$foundDimension->getUri()][0]))
                        $dataSetSubGraph = $dataSetSubGraphs[$innerDimension->getDataSet()][$foundDimension->getUri()][0];
                    else {
                        $dataSetSubGraph = new SubPattern([
                            new TriplePattern("?dataSet", "a", "qb:DataSet"),
                            new TriplePattern("?observation", "qb:dataSet", "?dataSet"),

                        ], false);
                    }

                    $needsDataSetSubGraph = false;
                    $attribute = $innerDimension->getUri();
                    $datasetURI = $innerDimension->getDataSet();
                    $attachment = $innerDimension->getAttachment();
                    if (isset($attachment) && $attachment == "qb:Slice") {
                        $needsSliceSubGraph = true;

                        if($foundDimension->getUri() == $innerDimension->getUri()){
                            $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $dimensionBindings[$datasetURI][$attribute], false));
                        }
                        else{
                            $attName = "att_" . substr(md5($foundDimension->ref), 0, 5);

                            $sliceSubGraph->add(new TriplePattern("?slice", "?$attName", $dimensionBindings[$datasetURI][$attribute], false));

                            $sliceSubGraph->add(new TriplePattern("?$attName", "rdfs:subPropertyOf", "<{$foundDimension->getUri()}>", false));
                            $this->subPropertiesAcceleration[$attName][$innerDimension->getUri()] = [$attName=>"<{$innerDimension->getUri()}>"];

                        }
                    } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                        $needsDataSetSubGraph = true;


                        if($foundDimension->getUri() == $innerDimension->getUri()){
                            $dataSetSubGraph->add(new TriplePattern("?dataSet", $attribute, $dimensionBindings[$datasetURI][$attribute], false));
                        }
                        else{
                            $attName = "att_" . substr(md5($foundDimension->ref), 0, 5);

                            $dataSetSubGraph->add(new TriplePattern("?dataSet", "?$attName", $dimensionBindings[$datasetURI][$attribute], false));

                            $dataSetSubGraph->add(new TriplePattern("?$attName", "rdfs:subPropertyOf", "<{$foundDimension->getUri()}>", false));
                            $this->subPropertiesAcceleration[$attName][$innerDimension->getUri()] = [$attName=>"<{$innerDimension->getUri()}>"];

                        }
                    } else {
                        if($foundDimension->getUri() == $innerDimension->getUri()){

                            $patterns[$datasetURI][$foundDimension->getUri()][] = new SubPattern([new TriplePattern("?observation", $attribute, $dimensionBindings[$datasetURI][$attribute], false)]);
                        }
                        else{
                            $attName = "att_" . substr(md5($foundDimension->ref), 0, 5);

                            $patterns[$datasetURI][$foundDimension->getUri()][] = new SubPattern([new TriplePattern("?observation", "?$attName", $dimensionBindings[$datasetURI][$attribute], false), new TriplePattern("?$attName", "rdfs:subPropertyOf", "<{$foundDimension->getUri()}>", false)]);
                            $this->subPropertiesAcceleration[$attName][$innerDimension->getUri()] = [$attName=>"<{$innerDimension->getUri()}>"];

                        }

                    }
                    if (isset($sorterMap[$attribute]) && $sorterMap[$attribute] instanceof Sorter) {
                        $sorterMap[$attribute]->binding = $dimensionBindings[$datasetURI][$attribute];
                        $finalSorters[$sorterMap[$attribute]->property] = $sorterMap[$attribute];
                    }
                    if (isset($filterMap[$attribute]) && $filterMap[$attribute] instanceof FilterDefinition) {
                        if (!isset($dimensionBindings[$datasetURI][$attribute])) continue;
                        $filterMap[$attribute]->binding = $dimensionBindings[$datasetURI][$attribute];
                        $finalFilters[] = $filterMap[$attribute];
                    }

                    if ($innerDimension instanceof Dimension) {
                        $dimensionPatterns = &$selectedDimensions[$datasetURI][$attribute];

                        foreach ($dimensionPatterns as $patternName => $dimensionPattern) {
                            $parentDimensionBindings[$dimensionBindings[$datasetURI][$attribute]][] = $dimensionBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5);
                            $attributes[$datasetURI][$attribute][$patternName] = $attributes[$datasetURI][$attribute]["uri"] . "_" . substr(md5($patternName), 0, 5);
                            $dimensionBindings[$datasetURI][] = $dimensionBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5);
                            if (isset($sorterMap[$attribute][$patternName])) {
                                $sorterMap[$attribute][$patternName]->binding = $dimensionBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5);
                                $finalSorters[$sorterMap[$attribute][$patternName]->property] = $sorterMap[$attribute][$patternName];

                            }
                            if (isset($filterMap[$attribute][$patternName])) {
                                $filterMap[$attribute][$patternName]->binding = $dimensionBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5);
                                $finalFilters[] = $filterMap[$attribute][$patternName];

                            }
                            if (isset($attachment) && $attachment == "qb:Slice") {
                                $sliceSubGraph->add(new TriplePattern($dimensionBindings[$datasetURI][$attribute], $patternName, $dimensionBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false));
                            } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                                $dataSetSubGraph->add(new TriplePattern($dimensionBindings[$datasetURI][$attribute], $patternName, $dimensionBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false));
                            } else {
                                $patterns[$datasetURI] [$foundDimension->getUri()][] = new TriplePattern($dimensionBindings[$datasetURI][$attribute], $patternName, $dimensionBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false);

                            }

                        }

                    }
                    if ($needsSliceSubGraph && !isset($sliceSubGraphs[$datasetURI][$foundDimension->getUri()])) {
                        $sliceSubGraphs[$datasetURI][$foundDimension->getUri()][0] = $sliceSubGraph;

                    }
                    if ($needsDataSetSubGraph && !isset($dataSetSubGraphs[$datasetURI][$foundDimension->getUri()])) {
                        $dataSetSubGraphs[$datasetURI][$foundDimension->getUri()][0] = $dataSetSubGraph;

                    }


                }


            }

           // dd($foundMeasure);

            /** @var GlobalMeasure $foundMeasure */
            if (isset($foundMeasure)) {
                foreach ($foundMeasure->getInnerMeasures() as $innerMeasure) {
                    if (!isset($selectedMeasures[$innerMeasure->getDataSet()])) $selectedMeasures[$innerMeasure->getDataSet()] = [];
                    $selectedMeasures[$innerMeasure->getDataSet()] =
                        array_merge_recursive(
                            $selectedMeasures[$innerMeasure->getDataSet()],
                            $this->globalDimensionToPatterns([$innerMeasure], [$innerMeasure->ref]));
                    //dd($selectedMeasures);
                    $bindingName = "binding_" . substr(md5($foundMeasure->ref), 0, 5);
                    $parentDimensionBindings["?" . $bindingName] = [];
                    $valueAttributeLabel = "uri";
                    $attributes[$innerMeasure->getDataSet()][$innerMeasure->getUri()][$valueAttributeLabel] = $bindingName;

                    $selectedInnerMeasures[$innerMeasure->getDataSet()][$innerMeasure->getUri()] = $innerMeasure;
                    $measureBindings[$innerMeasure->getDataSet()][$innerMeasure->getUri()] = "?$bindingName";

                    $datasetURI = $innerMeasure->getDataSet();


                    if (isset($sliceSubGraphs[$datasetURI][$foundMeasure->getUri()]) && isset($sliceSubGraphs[$datasetURI][$foundMeasure->getUri()][0]))
                        $sliceSubGraph = $sliceSubGraphs[$datasetURI][$foundMeasure->getUri()][0];
                    else {
                        $sliceSubGraph = new SubPattern([
                            new TriplePattern("?slice", "a", "qb:Slice"),
                            new TriplePattern("?slice", "qb:observation", "?observation"),

                        ], false);
                    }

                    $needsSliceSubGraph = false;

                    if (isset($dataSetSubGraphs[$datasetURI][$foundMeasure->getUri()]) && isset($dataSetSubGraphs[$datasetURI][$foundMeasure->getUri()][0]))
                        $dataSetSubGraph = $dataSetSubGraphs[$datasetURI][$foundMeasure->getUri()][0];
                    else {
                        $dataSetSubGraph = new SubPattern([
                            new TriplePattern("?dataSet", "a", "qb:DataSet"),
                            new TriplePattern("?observation", "qb:dataSet", "?dataSet"),

                        ], false);
                    }

                    $needsDataSetSubGraph = false;
                    /** @var Dimension $dimension */
                    $attribute = $innerMeasure->getUri();
                    $attachment = $innerMeasure->getAttachment();
                    if (isset($attachment) && $attachment == "qb:Slice") {
                        $needsSliceSubGraph = true;
                        $sliceSubGraph->addMany($this->currencyService->currencyMagicTriples("?slice", $attribute, $measureBindings[$datasetURI][$attribute], $innerMeasure->currency, $foundMeasure->currency, $innerMeasure->getDataSetFiscalYear(), $innerMeasure->getDataSet()));

                      //  $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $measureBindings[$datasetURI][$attribute], false));
                    } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                        $needsDataSetSubGraph = true;
                        $dataSetSubGraph->addMany($this->currencyService->currencyMagicTriples("?dataSet", $attribute, $measureBindings[$datasetURI][$attribute], $innerMeasure->currency, $foundMeasure->currency, $innerMeasure->getDataSetFiscalYear(), $innerMeasure->getDataSet()));

                       // $dataSetSubGraph->add(new TriplePattern("?dataSet", $attribute, $measureBindings[$datasetURI][$attribute], false));
                    } else {


                        $triples = $this->currencyService->currencyMagicTriples("?observation", $attribute, $measureBindings[$datasetURI][$attribute], $innerMeasure->currency, $foundMeasure->currency, $innerMeasure->getDataSetFiscalYear(), $innerMeasure->getDataSet());
                        foreach ($triples as $triple) {
                            $patterns [$innerMeasure->getDataSet()][$foundMeasure->getUri()][] = $triple;
                        }
                        //$patterns[$datasetURI][$foundMeasure->getUri()][] = new TriplePattern("?observation", $attribute, $measureBindings[$datasetURI][$attribute], false);
                        //dd($foundMeasure->getUri());

                    }
                    if (isset($sorterMap[$attribute]) && $sorterMap[$attribute] instanceof Sorter) {
                        $sorterMap[$attribute]->binding = $measureBindings[$datasetURI][$attribute];
                        $finalSorters[$sorterMap[$attribute]->property] = $sorterMap[$attribute];
                    }
                    if (isset($filterMap[$attribute]) && $filterMap[$attribute] instanceof FilterDefinition) {
                        if (!isset($measureBindings[$datasetURI][$attribute])) continue;
                        $filterMap[$attribute]->binding = $measureBindings[$datasetURI][$attribute];
                        $finalFilters[] = $filterMap[$attribute];
                    }

                    if ($needsSliceSubGraph && !isset($sliceSubGraphs[$datasetURI][$foundMeasure->getUri()])) {
                        $sliceSubGraphs[$datasetURI][$foundMeasure->getUri()][0] = $sliceSubGraph;

                    }
                    if ($needsDataSetSubGraph && !isset($dataSetSubGraphs[$datasetURI][$foundMeasure->getUri()])) {
                        $dataSetSubGraphs[$datasetURI][$foundMeasure->getUri()][0] = $dataSetSubGraph;

                    }
                }


            }
            unset($foundMeasure);
            unset($foundDimension);

        }

     /*   $mergedAttributes = [];
        foreach ($attributes as $datasetAttributes){
            $mergedAttributes = array_merge($mergedAttributes, $datasetAttributes);
        }
        $mergedDimensions = [];
        foreach ($selectedDimensions as $datasetSelectedDrilldowns){
            $mergedDrilldowns = array_merge($mergedDimensions, $inner);
        }*/

      //  dd(array_merge_recursive($patterns, $dataSetSubGraphs, $sliceSubGraphs));

        $queryBuilder = $this->build2(array_merge_recursive($patterns, $sliceSubGraphs, $dataSetSubGraphs),array_merge_recursive($dimensionBindings, $measureBindings), $finalFilters);
        /** @var EasyRdf_Sparql_Result $countResult */
       // echo($queryBuilder->format());die;

        $queryBuilder
            ->limit($page_size)
            ->offset($offset);

//dd($finalSorters);
        foreach ($finalSorters as $sorter) {
            if ($sorter->property == "_count") {
                $queryBuilder->orderBy("?" . $sorter->property, strtoupper($sorter->direction));
                continue;
            }
            $queryBuilder->orderBy($sorter->binding, strtoupper($sorter->direction));
        }

        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        //   echo($result->dump());
//dd($selectedPatterns);
        if(count($attributes)>0)
            $mergedAttributes = call_user_func_array("array_merge_recursive",$attributes);
        else{
            $mergedAttributes = $attributes;
        }
        if(count($selectedDimensions)>0)
            $mergedDimensions = call_user_func_array("array_merge_recursive",$selectedDimensions);
        else{
            $mergedDimensions = $selectedDimensions;
        }
        if(count($selectedMeasures)>0)
            $mergedMeasures = call_user_func_array("array_merge_recursive",$selectedMeasures);
        else{
            $mergedMeasures=$selectedMeasures;
        }
        // dd($selectedDrilldowns);
        //dd($selectedDimensions);
//dd($selectedMeasures);
        array_walk($mergedAttributes, function(&$data, $key)
        {
            //
            //
            foreach($data as $k=>&$arr)
            {
                if(is_array($arr))
                $data[$k] = array_values(array_unique($arr))[0];
              //  dd($arr);
            }
        });
       // dd($mergedAttributes);
        $results = $this->rdfResultsToArray3($result, $mergedAttributes, $model, array_merge( $mergedDimensions, $mergedMeasures));
//dd($results);
//dd(array_merge_recursive($patterns, $sliceSubGraphs, $dataSetSubGraphs));


        $queryBuilderC =new QueryBuilder(config("sparql.prefixes"));

        $queryBuilderC->subquery($this->build2(array_merge_recursive($patterns, $sliceSubGraphs, $dataSetSubGraphs),array_merge_recursive($dimensionBindings, $measureBindings), $finalFilters));
        $queryBuilderC->selectDistinct("(COUNT(?observation) AS ?_count)");
        /** @var EasyRdf_Sparql_Result $countResult */
       // echo $queryBuilderC->format();die;

        $countResult = $this->sparql->query(
            $queryBuilderC->getSPARQL()
        );
        $count = $countResult[0]->_count->getValue();


        $this->data = $results;
        $this->page_size = $page_size;
        $this->page = $page;
        $this->total_fact_count = $count;
        $this->fields = $fields;


    }

    private function build2( array $dimensionPatterns, array $bindings, array $filterMap = []){

        $allSelectedFields = array_unique(array_flatten($bindings));


        $flatDimensionPatterns = new Collection();

        foreach (new Collection($dimensionPatterns) as $dataSet => $patternsOfDimension) {

            foreach ($patternsOfDimension as $pattern => $patternsArray) {
                if ($flatDimensionPatterns->has($pattern)) {
                    /** @var Collection $existing */
                    $existing = $flatDimensionPatterns->get($pattern);
                    $found = false;
                    /** @var Collection $existingPatternsArray */
                    foreach ($existing as $existingPatternsArray) {
                        /** @var Collection $patternsArray */
                        $patternsArrayCol = new Collection($patternsArray);
                        if (json_encode($existingPatternsArray) == json_encode($patternsArrayCol)) {
                            $found = true;
                        }
                    }
                    if (!$found) {
                        $flatDimensionPatterns->get($pattern)->add(new Collection($patternsArray));
                    }
                } else {
                    $flatDimensionPatterns->put($pattern, new Collection([new Collection($patternsArray)]));
                }
            }
        }

        $basicQueryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $dataSets = array_keys($dimensionPatterns);
        $rateTuples = [];

        array_walk($dataSets, function ($dataSet) use (&$rateTuples) {
            $value = ["dataSet" => "<$dataSet>"];
            if(isset($this->currencyService->dataSetRates[$dataSet] ))
                foreach ($this->currencyService->dataSetRates[$dataSet] as $target=>$dataSetRate) {
                    $value["rate__{$target}"] = $dataSetRate;
                }
            $rateTuples[] = $value;

        });


        //$basicQueryBuilder->values($rateTuples);

        $basicQueryBuilder->where("?observation", "qb:dataSet", "?dataSet");
//dd($flatDimensionPatterns);
        /** @var Collection $dimensionPatterCollections */
        foreach ($flatDimensionPatterns as $dimension => $dimensionPatternsCollections) {
            if ($dimensionPatternsCollections->count() > 1) {
                $multiPatternGraph = [];
                foreach ($dimensionPatternsCollections as $dimensionPatternsCollection) {
                    $newQuery = $basicQueryBuilder->newSubquery();
                    $selections = ["?observation"];
                    foreach ($dimensionPatternsCollection as $pattern) {
                        if ($pattern instanceof TriplePattern) {
                            if (in_array($pattern->object, $allSelectedFields)) $selections[$pattern->object] = $pattern->object;
                            //if ($pattern->isOptional) {
                               // $newQuery->optional($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);
                          //  } else {
                            if($pattern->predicate == "rdfs:subPropertyOf"){
                                $newQuery->values($this->subPropertiesAcceleration[ltrim($pattern->subject,"?")]);
                            }else $newQuery->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);
                           // }
                        } elseif ($pattern instanceof SubPattern) {

                            foreach ($pattern->patterns as $subPattern) {
                                if (in_array($subPattern->object, $allSelectedFields)) $selections[$subPattern->object] = $subPattern->object;
                               // if ($subPattern->isOptional) {
                                  //  $newQuery->optional($subPattern->subject, self::expand($subPattern->predicate, $subPattern->transitivity), $subPattern->object);
                               // } else {
                                if($subPattern->predicate == "rdfs:subPropertyOf"){
                                    $newQuery->values($this->subPropertiesAcceleration[ltrim($subPattern->subject,"?")]);
                                }else $newQuery->where($subPattern->subject, self::expand($subPattern->predicate, $subPattern->transitivity), $subPattern->object);
                              //  }
                            }

                        } else if ($pattern instanceof BindPattern) {
                           // $newQuery->values($rateTuples);
                           // if (in_array($pattern->getVariable(), $allSelectedFields)) $selections[$pattern->getVariable()] = $pattern->getVariable();
                            //dd($pattern->getVariable());
                            //$newQuery->bind($pattern->expression);
                        }
                    }
                   // dump($selections);
                   // dump($newQuery->format());

                    $newQuery->select($selections);
                   // dump($newQuery->format());

                    $multiPatternGraph[] = $newQuery;
                }

                /** @var GraphBuilder $optional */
                $basicQueryBuilder->union(array_map(function (QueryBuilder $subQueryBuilder) use ($basicQueryBuilder) {
                    return $basicQueryBuilder->newSubgraph()->subquery($subQueryBuilder);
                }, $multiPatternGraph));





            } else {
                foreach ($dimensionPatternsCollections as $dimensionPatternsCollection) {
                    //dump($dimensionPatternsCollection);
                    foreach ($dimensionPatternsCollection as $pattern) {
                        if ($pattern instanceof TriplePattern) {
                            if ($pattern->isOptional) {
                                $basicQueryBuilder->optional($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);
                            } else {
                                if($pattern->predicate == "rdfs:subPropertyOf"){
                                    $basicQueryBuilder->values($this->subPropertiesAcceleration[ltrim($pattern->subject,"?")]);
                                }else $basicQueryBuilder->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);
                            }
                        } elseif ($pattern instanceof SubPattern) {


                            foreach ($pattern->patterns as $subPattern) {

                                if ($subPattern->isOptional) {
                                    $basicQueryBuilder->optional($subPattern->subject, self::expand($subPattern->predicate, $subPattern->transitivity), $subPattern->object);
                                } else {
                                    if($subPattern->predicate == "rdfs:subPropertyOf"){
                                        $basicQueryBuilder->values($this->subPropertiesAcceleration[ltrim($subPattern->subject,"?")]);
                                    }else $basicQueryBuilder->where($subPattern->subject, self::expand($subPattern->predicate, $subPattern->transitivity), $subPattern->object);
                                }
                            }


                        } else if ($pattern instanceof BindPattern) {
                            //$basicQueryBuilder->bind($pattern->expression);
                        }

                    }
                }
            }
        }
        $filterCollection = new Collection(array_flatten($filterMap));
        $filterCollection = $filterCollection->unique(function($item){return json_encode($item);});

        foreach ($filterCollection as $filter) {
            if(!$filter->isCardinal){
                $filter->value = trim($filter->value, '"');
                $filter->value = trim($filter->value, "'");

                $basicQueryBuilder->filter("str({$filter->binding})='{$filter->value}'");
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
                $basicQueryBuilder->values($values);
            }
        }


       // dd($flatDimensionPatterns);

        $basicQueryBuilder->select(array_unique(array_merge(["?observation"], array_values($allSelectedFields))));
      //echo $basicQueryBuilder->format();die;

        return $basicQueryBuilder;


    }





    protected function globalDimensionToPatterns(array $dimensions, $fields)
    {
        $selectedDimensions = [];

        foreach ($fields as $field) {
            $fieldNames = explode(".", $field);

            /** @var Dimension $attribute */
            foreach ($dimensions as $name => $attribute) {
                //var_dump($fieldNames);
                if($attribute instanceof Dimension){
                    if ($fieldNames[0] == $attribute->orig_dimension) {
                        if (!isset($selectedDimensions[$attribute->getUri()])) {
                            $selectedDimensions[$attribute->getUri()] = [];
                        }
                        $currentAttribute = $attribute;
                        for ($i = 1; $i < count($fieldNames); $i++) {

                            if ($fieldNames[$i] == $fieldNames[$i - 1]) continue;

                            foreach ($currentAttribute->attributes as $innerAttributeName => $innerAttribute) {
                                if ($fieldNames[$i] == $fieldNames[$i - 1]) continue;

                                if ($fieldNames[$i] == $innerAttributeName) {

                                    $selectedDimensions[$attribute->getUri()][$innerAttribute->getUri()] = [];
                                    break;

                                }
                            }
                        }

                    }
                }
                elseif ($attribute instanceof Measure){
                    if ($fieldNames[0] == $attribute->ref) {
                        if (!isset($selectedDimensions[$attribute->getUri()])) {
                            $selectedDimensions[$attribute->getUri()] = [];
                        }

                    }
                }


            }

        }
        return ($selectedDimensions);
    }


}