<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 22/03/2016
 * Time: 18:22:54
 */

namespace App\Model\Globals;


use App\Model\AggregateResult;
use App\Model\Dimension;
use App\Model\FilterDefinition;
use App\Model\GenericProperty;
use App\Model\Sorter;
use App\Model\Sparql\SubPattern;
use App\Model\Sparql\TriplePattern;
use App\Model\SparqlModel;
use Asparagus\GraphBuilder;
use Asparagus\QueryBuilder;
use EasyRdf_Sparql_Result;

class GlobalAggregateResult extends AggregateResult
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


    public function __construct($page, $page_size, $aggregates, $drilldown, $orders, $cuts)
    {
        SparqlModel::__construct();

        $this->page = $page;
        $this->page_size = min($page_size, 1000);
        $this->aggregates = $aggregates;
        $this->cell = [];
        $sorters = [];
        foreach ($orders as $order) {
            $newSorter = new Sorter($order);
            $sorters[$newSorter->property] = $newSorter;
            $this->order[] = [$newSorter->property, $newSorter->direction];
        }

        $filters = [];
        foreach ($cuts as $cut) {
            $newFilter = new FilterDefinition($cut);
            $filters[$newFilter->property] = $newFilter;
            $this->cells[] = ["operator" => ":", "ref" => $newFilter->property, "value" => $newFilter->value];

        }
        $this->attributes = $drilldown;

        $this->aggregates = $aggregates;
        $this->load($aggregates, $drilldown, $sorters, $filters);
        $this->status = "ok";

    }

    private function load($aggregates, $drilldowns, $sorters, $filters)
    {
        $model = (new BabbageGlobalModelResult())->model;

        if (count($aggregates) < 1 || $aggregates[0] == "") {
            $aggregates = [];
            foreach ($model->aggregates as $agg) {
                if ($agg->ref != "_count" && ($agg instanceof GlobalAggregate)) {
                    $aggregates[] = $agg->ref;
                }
            }
        }
        $selectedAggregates = $this->modelFieldsToPatterns($model, $aggregates);


        //dd($selectedAggregates);

        $offset = $this->page_size * $this->page;
        $sliceSubGraphs = [];
        $dataSetSubGraphs = [];
        $graphs = [];

        $dimensions = $model->dimensions;
        $measures = $model->measures;
        $finalSorters = [];
        $finalFilters = [];
        /** @var Sorter[] $sorterMap */
        $sorterMap = [];
        foreach ($sorters as $sorter) {
            if ($sorter->property == "_count") {
                $finalSorters[] = $sorter;
                continue;
            }
            if ($sorter->property == "") continue;
            $path = explode('.', $sorter->property);
            $fullName = $this->getAttributePathByName($model, $path);
            if (empty($fullName)) continue;

            $this->array_set($sorterMap, $fullName, $sorter);
        }
        /** @var FilterDefinition[] $filterMap */
        $filterMap = [];
        foreach ($filters as $filter) {
            $path = explode('.', $filter->property);
            $fullName = $this->getAttributePathByName($model, $path);
            if (empty($fullName)) continue;
            $this->array_set($filterMap, $fullName, $filter);
        }
        $attributes = [];
        $bindings = [];
        $patterns = [];

        /** @var GenericProperty[] $selectedAggregateDimensions */
        $selectedAggregateDimensions = [];
        /** @var GenericProperty[] $selectedDrilldownDimensions */
        $selectedDrilldownDimensions = [];
        $drilldownBindings = [];
        $aggregateBindings = [];
        $selectedDrilldowns = [];

        foreach ($drilldowns as $drilldown) {
            $drilldownElements = explode(".", $drilldown);
            foreach ($model->dimensions as $dimension) {
                if ($dimension->key_ref == $drilldown) {
                    $foundDimension = $dimension;
                    break;
                }
            }
            if (!isset($foundDimension)) continue;
            if ($drilldown == $foundDimension->label_ref) {
                $attributeModifier = "label_ref";
                $attributeSimpleModifier = "label_attribute";
            } else {
                $attributeModifier = "key_ref";
                $attributeSimpleModifier = "key_attribute";

            }


            /** @var GlobalDimension $foundDimension */

            // dd($innerDrilldown);

            /** @var Dimension $innerDimension */
            foreach ($foundDimension->getInnerDimensions() as $innerDimension) {
                if (!isset($selectedDrilldowns[$innerDimension->getDataSet()])) $selectedDrilldowns[$innerDimension->getDataSet()] = [];
                $selectedDrilldowns[$innerDimension->getDataSet()] = array_merge_recursive($selectedDrilldowns[$innerDimension->getDataSet()], $this->globalDimensionToPatterns([$innerDimension], [$innerDimension->$attributeModifier]));
                $bindingName = "binding_" . substr(md5($foundDimension->ref), 0, 5);
                $valueAttributeLabel = "uri";
                $attributes[$innerDimension->getDataSet()][$innerDimension->getUri()][$valueAttributeLabel] = $bindingName;


                $selectedDrilldownDimensions[$innerDimension->getDataSet()][$innerDimension->getUri()] = $innerDimension;
                $drilldownBindings[$innerDimension->getDataSet()][$innerDimension->getUri()] = "?$bindingName";


            }

            foreach ($measures as $measureName => $measure) {
                if (!isset($selectedAggregates[$measure->getUri()])) continue;
                $selectedAggregateDimensions[$measure->getUri()] = $measure;
                $bindingName = "binding_" . substr(md5($measure->getUri()), 0, 5);
                $valueAttributeLabel = "sum";
                $attributes[$measure->getUri()][$valueAttributeLabel] = $bindingName;
                $aggregateBindings[$measure->getUri()] = "?$bindingName";
            }
            foreach ($selectedDrilldownDimensions as $datasetURI => $datasetDrilldownDimensions) {
                if(isset($sliceSubGraphs[$datasetURI][$foundDimension->getUri()]) && isset($sliceSubGraphs[$datasetURI][$foundDimension->getUri()][0]))
                    $sliceSubGraph = $sliceSubGraphs[$datasetURI][$foundDimension->getUri()][0];
                else{
                    $sliceSubGraph = new SubPattern([
                        new TriplePattern("?slice", "a", "qb:Slice"),
                        new TriplePattern("?slice", "qb:observation", "?observation"),

                    ], false);
                }

                $needsSliceSubGraph = false;

                if(isset($dataSetSubGraphs[$datasetURI][$foundDimension->getUri()]) && isset($dataSetSubGraphs[$datasetURI][$foundDimension->getUri()][0]))
                    $dataSetSubGraph = $dataSetSubGraphs[$datasetURI][$foundDimension->getUri()][0];
                else{
                    $dataSetSubGraph = new SubPattern([
                        new TriplePattern("?dataSet", "a", "qb:DataSet"),
                        new TriplePattern("?observation", "qb:dataSet", "?dataSet"),

                    ], false);
                }

                $needsDataSetSubGraph = false;
                /** @var Dimension $dimension */
                foreach ($datasetDrilldownDimensions as $dimensionName => $dimension) {
                    $attribute = $dimensionName;
                    $attachment = $dimension->getAttachment();
                    if (isset($attachment) && $attachment == "qb:Slice") {
                        $needsSliceSubGraph = true;

                        $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $drilldownBindings[$datasetURI][$attribute], false));
                    }

                    elseif (isset($attachment) && $attachment == "qb:DataSet") {
                        $needsDataSetSubGraph = true;

                        $dataSetSubGraph->add(new TriplePattern("?dataSet", $attribute, $drilldownBindings[$datasetURI][$attribute], false));
                    }
                    else {

                        $patterns[$datasetURI][$foundDimension->getUri()][] = new TriplePattern("?observation", $attribute, $drilldownBindings[$datasetURI][$attribute], false);
                    }
                    if (isset($sorterMap[$attribute]) && $sorterMap[$attribute] instanceof Sorter) {
                        $sorterMap[$attribute]->binding = $drilldownBindings[$datasetURI][$attribute];
                        $finalSorters[] = $sorterMap[$attribute];
                    }
                    if (isset($filterMap[$attribute]) && $filterMap[$attribute] instanceof FilterDefinition) {
                        if (!isset($drilldownBindings[$datasetURI][$attribute])) continue;
                        $filterMap[$attribute]->binding = $drilldownBindings[$datasetURI][$attribute];
                        $finalFilters[] = $filterMap[$attribute];
                    }

                    if ($dimension instanceof Dimension) {

                        $dimensionPatterns = &$selectedDrilldowns[$datasetURI][$attribute];

                        foreach ($dimensionPatterns as $patternName => $dimensionPattern) {
                            $attributes[$datasetURI][$attribute][$patternName] = $attributes[$datasetURI][$attribute]["uri"] . "_" . substr(md5($patternName), 0, 5);
                            $drilldownBindings[$datasetURI][] = $drilldownBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5);
                            if (isset($sorterMap[$attribute][$patternName])) {
                                $sorterMap[$attribute][$patternName]->binding = $drilldownBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5);
                                $finalSorters[] = $sorterMap[$attribute][$patternName];

                            }
                            if (isset($filterMap[$attribute][$patternName])) {
                                $filterMap[$attribute][$patternName]->binding = $drilldownBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5);
                                $finalFilters[] = $filterMap[$attribute][$patternName];

                            }
                            if (isset($attachment) && $attachment == "qb:Slice") {
                                $sliceSubGraph->add(new TriplePattern($drilldownBindings[$datasetURI][$attribute], $patternName, $drilldownBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false));
                            }
                            elseif (isset($attachment) && $attachment == "qb:DataSet") {
                                $dataSetSubGraph->add(new TriplePattern($drilldownBindings[$datasetURI][$attribute], $patternName, $drilldownBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false));
                            } else {
                                $patterns[$datasetURI] [$foundDimension->getUri()][] = new TriplePattern($drilldownBindings[$datasetURI][$attribute], $patternName, $drilldownBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false);

                            }

                        }

                    }

                }
                if ($needsSliceSubGraph && !isset($sliceSubGraphs[$datasetURI][$foundDimension->getUri()])){
                    $sliceSubGraphs[$datasetURI][$foundDimension->getUri()][0] = $sliceSubGraph;

                }
                if ($needsDataSetSubGraph && !isset($dataSetSubGraphs[$datasetURI][$foundDimension->getUri()])){
                    $dataSetSubGraphs[$datasetURI][$foundDimension->getUri()][0] = $dataSetSubGraph;

                }

            }
            /*  dd($sliceSubGraphs);
              dd($patterns);*/


        }
        $alreadyRestrictedDatasets = array_keys(array_merge($sliceSubGraphs,$patterns, $dataSetSubGraphs));
        foreach ($measures as $measureName => $measure) {
            if (!isset($selectedAggregates[$measure->getUri()])) continue;
            $selectedAggregateDimensions[$measure->getUri()] = $measure;
            $bindingName = "binding_" . substr(md5($measure->getUri()), 0, 5);
            $valueAttributeLabel = "sum";
            $attributes["_"][$measure->getUri()][$valueAttributeLabel] = $bindingName;
            $aggregateBindings[$measure->getUri()] = "?$bindingName";
        }

        foreach ($selectedAggregateDimensions as $measureName => $measure) {
            if ($measure instanceof GlobalMeasure) {
                foreach ($measure->getInnerMeasures() as $innerMeasureName => $innerMeasure) {
                    if(!empty($alreadyRestrictedDatasets)&&!in_array($innerMeasure->getDataSet(), $alreadyRestrictedDatasets )) continue; ///should not include this dataset, as aggregates have been selected that are specific NOT to this dataset
                    $attribute = $measureName;
                    $attachment = $innerMeasure->getAttachment();
                    if (isset($attachment) && $attachment == "qb:Slice") {
                        $needsSliceSubGraph = true;
                        $sliceSubGraphs[$innerMeasure->getDataSet()]->add(new TriplePattern("?slice", $attribute, $aggregateBindings[$attribute], false));
                    }
                    if (isset($attachment) && $attachment == "qb:DataSet") {
                        $needsDataSetSubGraph = true;
                        $dataSetSubGraphs[$innerMeasure->getDataSet()]->add(new TriplePattern("?dataSet", $attribute, $aggregateBindings[$attribute], false));
                    } else {
                        $patterns [$innerMeasure->getDataSet()][$attribute][] = new TriplePattern("?observation", $attribute, $aggregateBindings[$attribute], false);
                    }
                    if (isset($sorterMap[$attribute]) && $sorterMap[$attribute] instanceof Sorter) {
                        $sorterMap[$attribute]->binding = $aggregateBindings[$attribute];
                        $finalSorters[] = $sorterMap[$attribute];
                    }
                    if (isset($filterMap[$attribute]) && $filterMap[$attribute] instanceof FilterDefinition) {
                        $filterMap[$attribute]->binding = $aggregateBindings[$attribute];
                        $finalFilters[] = $filterMap[$attribute];
                    }
                }


            }


        }
        // echo($queryBuilder->format());
        //$bindings[] = "?observation";
        $mergedAttributes = [];
        foreach ($attributes as $datasetAttributes){
            $mergedAttributes = array_merge($mergedAttributes, $datasetAttributes);
        }
        $mergedDrilldowns = [];
        foreach ($selectedDrilldowns as $datasetSelectedDrilldowns){
            $mergedDrilldowns = array_merge($mergedDrilldowns, $datasetSelectedDrilldowns);
        }

        //$dsd = $model->getDsd();
        /*$patterns[] = new TriplePattern('?observation', 'a', 'qb:Observation');
        $patterns[] = new TriplePattern('?observation', 'qb:dataSet', "<$dataset>");*/
        /*   dd($aggregateBindings);
   dd(array_merge_recursive($patterns, $sliceSubGraphs));*/
        $queryBuilderC = $this->buildC($drilldownBindings, array_merge_recursive($patterns, $sliceSubGraphs, $dataSetSubGraphs), $finalFilters);
        /** @var EasyRdf_Sparql_Result $countResult */
        //echo($queryBuilderC->format());die;
        $countResult = $this->sparql->query(
            $queryBuilderC->getSPARQL()
        );



        $queryBuilderS = $this->buildS($aggregateBindings, $patterns, $finalFilters);
        /** @var EasyRdf_Sparql_Result $countResult */
        //echo($queryBuilderS->format());die;
        $summaryResult = $this->sparql->query(
            $queryBuilderS->getSPARQL()
        );
        $this->summary = $this->rdfResultsToArray3($summaryResult, $mergedAttributes, $model, array_merge($selectedAggregates))[0];
        $count = $countResult[0]->_count->getValue();
        $queryBuilder = $this->build($aggregateBindings, $drilldownBindings, array_merge_recursive($patterns, $sliceSubGraphs, $dataSetSubGraphs), $finalFilters);


        $queryBuilder
            ->limit($this->page_size)
            ->offset($offset);


        foreach ($finalSorters as $sorter) {
            if ($sorter->property == "_count") {
                $queryBuilder->orderBy("?" . $sorter->property, strtoupper($sorter->direction));
                continue;
            }
            $queryBuilder->orderBy($sorter->binding, strtoupper($sorter->direction));
        }

       //echo  $queryBuilder->format();die;
        /* $queryBuilder
             ->orderBy("?observation");*/


        // die;
        /** @var EasyRdf_Sparql_Result $result */
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );

      //   echo($result->dump());
//dd($selectedPatterns);
        //dd($attributes);

       // dd($selectedDrilldowns);
        $results = $this->rdfResultsToArray3($result, $mergedAttributes, $model, array_merge( $mergedDrilldowns, $selectedAggregates));
//dd($results);
        $this->cells = $results;
        $this->total_cell_count = $count;

    }

    /**
     * @param array $aggregateBindings
     * @param array $drilldownBindings
     * @param Dimension[] $dimensionPatterns
     * @param FilterDefinition[] $filterMap
     * @return QueryBuilder
     * @internal param array $bindings
     */
    private function build(array $aggregateBindings, array $drilldownBindings, array $dimensionPatterns, array $filterMap = [])
    {
        //dd($dimensionPatterns);
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $datasetQueries = [];
        $selectedFields = [];
        $allSelectedFields = array_unique(array_flatten($drilldownBindings));
        foreach ($drilldownBindings as $dataset=>&$dataSetDrilldownBindings) {
            foreach ($allSelectedFields as $allSelectedField) {
                if(!in_array($allSelectedField, $dataSetDrilldownBindings )){
                    $filtered = array_filter($dataSetDrilldownBindings, function($element) use($allSelectedField){return starts_with($allSelectedField,$element);});
                     ksort($filtered);
                    $parentElement = array_values($filtered)[0];
                    $dataSetDrilldownBindings[] = "(STR($parentElement) AS $allSelectedField)";
                }

            }
        }
       // dd($drilldownBindings);
        foreach ($dimensionPatterns as $dataset => $dimensionPatternGroup) {
            $datasetQuery = $queryBuilder->newSubquery();

            foreach ($dimensionPatternGroup as $uri => $dimensions) {

                foreach ($dimensions as $dimensionPattern) {
                    if ($dimensionPattern instanceof TriplePattern) {
                        if ($dimensionPattern->isOptional) {
                            $datasetQuery->optional($dimensionPattern->subject, self::expand($dimensionPattern->predicate), $dimensionPattern->object);
                        } else {
                            $datasetQuery->where($dimensionPattern->subject, self::expand($dimensionPattern->predicate), $dimensionPattern->object);
                        }
                    } elseif ($dimensionPattern instanceof SubPattern) {
                        $subGraph = $datasetQuery->newSubgraph();

                        foreach ($dimensionPattern->patterns as $pattern) {

                            if ($pattern->isOptional) {
                                $datasetQuery->optional($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                            } else {
                                $datasetQuery->where($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                            }
                        }

                        $datasetQuery->optional($subGraph);
                    }
                }
            }
            $selectedFields = array_merge($aggregateBindings,["?observation"]);
            if(isset($drilldownBindings[$dataset]))$selectedFields = array_merge($selectedFields, array_values($drilldownBindings[$dataset]));
            $datasetQuery->selectDistinct(array_unique($selectedFields));
          //  var_dump(array_values($drilldownBindings[$dataset]));
            $datasetQuery->where("?observation", "a", "qb:Observation");
            $datasetQuery->where("?observation", "qb:dataSet", "<$dataset>");
            $datasetQueries [$dataset]= $datasetQuery;
            //var_dump($datasetQuery->format());
        }


        foreach ($filterMap as $filter) {
            $queryBuilder->filter("str(" . $filter->binding . ")='" . $filter->value . "'");
        }
        $queryBuilder->union(array_map(function (QueryBuilder $subQueryBuilder) use($queryBuilder) {
            return $queryBuilder->newSubgraph()->subquery($subQueryBuilder);
        }, $datasetQueries));

        $agBindings = [];
        foreach ($aggregateBindings as $binding) {
            $agBindings [] = "(sum($binding) AS $binding)";
        }
        $agBindings[] = "(count(?observation) AS ?_count)";

        $drldnBindings = [];

        foreach ($drilldownBindings as $dataset=>$bindings) {
            foreach ($bindings as $binding) {
                $drldnBindings [] = "$binding";

            }
        }


        $queryBuilder
            ->selectDistinct(array_merge($agBindings, $allSelectedFields));
        if (count($drilldownBindings) > 0) {
            $queryBuilder->groupBy($allSelectedFields);
        }

        return $queryBuilder;

    }

    private function buildS(array $aggregateBindings, array $dimensionPatterns, array $filterMap = [])
    {
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));

        $datasetQueries = [];
        foreach ($dimensionPatterns as $dataset => $dimensionPatternGroup) {
            $datasetQuery = $queryBuilder->newSubquery();

            foreach ($dimensionPatternGroup as $uri => $dimensions) {

                foreach ($dimensions as $dimensionPattern) {
                    if ($dimensionPattern instanceof TriplePattern) {
                        if ($dimensionPattern->isOptional) {
                            $datasetQuery->optional($dimensionPattern->subject, self::expand($dimensionPattern->predicate), $dimensionPattern->object);
                        } else {
                            $datasetQuery->where($dimensionPattern->subject, self::expand($dimensionPattern->predicate), $dimensionPattern->object);
                        }
                    } elseif ($dimensionPattern instanceof SubPattern) {
                        $subGraph = $datasetQuery->newSubgraph();

                        foreach ($dimensionPattern->patterns as $pattern) {

                            if ($pattern->isOptional) {
                                $subGraph->optional($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                            } else {
                                $subGraph->where($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                            }
                        }

                        $datasetQuery->optional($subGraph);
                    }
                }
            }
            $datasetQuery->selectDistinct(array_merge($aggregateBindings,["?observation"]));

            $datasetQuery->where("?observation", "a", "qb:Observation");
            $datasetQuery->where("?observation", "qb:dataSet", "<$dataset>");
            $datasetQueries [$dataset]= $datasetQuery;
        }
        foreach ($filterMap as $filter) {
            $queryBuilder->filter("str(" . $filter->binding . ")='" . $filter->value . "'");
        }
        $queryBuilder->union(array_map(function (QueryBuilder $subQueryBuilder) use($queryBuilder) {
            return $queryBuilder->newSubgraph()->subquery($subQueryBuilder);
        }, $datasetQueries));

        $agBindings = [];
        foreach ($aggregateBindings as $binding) {
            $agBindings [] = "(sum($binding) AS $binding)";
        }
        $agBindings[] = "(count(?observation) AS ?_count)";


        $queryBuilder
            ->selectDistinct($agBindings);
       // echo $queryBuilder->format();die;

        return $queryBuilder;

    }

    private function buildC(array $drilldownBindings, array $dimensionPatterns, array $filterMap = [])
    {


        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        /** @var GraphBuilder[] $datasetQueries */
        $datasetQueries = [];
        foreach ($dimensionPatterns as $dataset => $dimensionPatternGroup) {
            $datasetQuery = $queryBuilder->newSubquery();

            foreach ($dimensionPatternGroup as $uri => $dimensions) {
                foreach ($dimensions as $dimensionPattern) {

                    if ($dimensionPattern instanceof TriplePattern) {

                        if ($dimensionPattern->isOptional) {
                            $datasetQuery->optional($dimensionPattern->subject, self::expand($dimensionPattern->predicate), $dimensionPattern->object);
                        } else {
                            $datasetQuery->where($dimensionPattern->subject, self::expand($dimensionPattern->predicate), $dimensionPattern->object);
                        }
                    }
                    elseif ($dimensionPattern instanceof SubPattern) {
                        $subGraph = $queryBuilder->newSubgraph();

                        foreach ($dimensionPattern->patterns as $pattern) {

                            if ($pattern->isOptional) {
                                $datasetQuery->optional($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                            } else {
                                $datasetQuery->where($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                            }
                        }

                        $datasetQuery->optional($subGraph);
                    }
                }

            }
            if(isset($drilldownBindings[$dataset]))$datasetQuery->selectDistinct(array_unique(array_values($drilldownBindings[$dataset])));
            $datasetQuery->where("?observation", "a", "qb:Observation");
            $datasetQuery->where("?observation", "qb:dataSet", "<$dataset>");
            $datasetQueries [$dataset]= $datasetQuery;


        }
        $subQuery = $queryBuilder->newSubquery();


        $subQuery->union(array_map(function (QueryBuilder $subQueryBuilder) use($subQuery) {
            return $subQuery->newSubgraph()->subquery($subQueryBuilder);
        }, $datasetQueries));



        foreach ($filterMap as $filter) {
            $subQuery->filter("str(" . $filter->binding . ")='" . $filter->value . "'");
        }


        $drldnBindings = [];
        foreach ($drilldownBindings as $dataset=>$bindings) {
            foreach ($bindings as $binding) {
                $drldnBindings [] = "$binding";

            }
        }


        $subQuery
            ->selectDistinct(array_unique($drldnBindings));

        $queryBuilder->subquery($subQuery);
        $queryBuilder->select("(count(*) AS ?_count)");
       // echo $queryBuilder->format();die;

        return $queryBuilder;

    }

    protected function globalDimensionToPatterns(array $dimensions, $fields)
    {
        $selectedDimensions = [];

        foreach ($fields as $field) {
            $fieldNames = explode(".", $field);

            /** @var Dimension $attribute */
            foreach ($dimensions as $name => $attribute) {
                //var_dump($fieldNames);
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

        }
        return ($selectedDimensions);
    }


}