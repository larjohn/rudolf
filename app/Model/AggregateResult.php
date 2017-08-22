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
use Illuminate\Database\Eloquent\Collection;
use Log;
use URL;

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
        $this->page_size = min($page_size, 1000);

        if (count($aggregates) < 1 || $aggregates[0] == "") {

            $aggregates = [];

        }


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
            if (!isset($filters[$newFilter->property])) {
                $filters[$newFilter->property] = $newFilter;
            } else {
                $filters[$newFilter->property]->addValue($cut);
            }
            $this->cells[] = ["operator" => ":", "ref" => $newFilter->property, "value" => $newFilter->value];

        }
        $this->attributes = array_filter($drilldown, function($item){return $item!="";});

        $this->aggregates = $aggregates;
        $this->load($name, $aggregates, $drilldown, $sorters, $filters);
        $this->status = "ok";

    }

    private function load($name, $aggregates, $drilldowns, $sorters, $filters)
    {
        $model = (new BabbageModelResult($name))->model;
//dd($model);
//dd($aggregates);
        if (count($aggregates) < 1 || $aggregates[0] == "") {
            foreach ($model->aggregates as $agg) {
                if ($agg->ref != "_count") {
                    $aggregates[] = $agg->ref;
                }
            }
        }
        $this->aggregates = $aggregates;
        $this->aggregates[] = "_count";

        // return $facts;
        $selectedAggregates = $this->modelFieldsToPatterns($model, $aggregates);
        //$this->modelFieldsToPatterns2($model, $drilldowns);
        $selectedDrilldowns = $this->modelFieldsToPatterns($model, $drilldowns);

        $offset = $this->page_size * $this->page;

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
        //dd($sorterMap);
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
        $parentDrilldownBindings = [];
        /** @var GenericProperty[] $selectedAggregateDimensions */
        $selectedAggregateDimensions = [];
        /** @var GenericProperty[] $selectedDrilldownDimensions */
        $selectedDrilldownDimensions = [];
        $selectedFilterDimensions = [];
        $selectedSorterDimensions = [];
        $drilldownBindings = [];
        $aggregateBindings = [];
        $filterBindings = [];
        $sorterBindings = [];
        foreach ($dimensions as $dimensionName => $dimension) {
            if (!isset($selectedAggregates[$dimension->getUri()]) && !isset($selectedDrilldowns[$dimension->getUri()]) && !isset($filterMap[$dimension->getUri()]) && !isset($sorterMap[$dimension->getUri()])) continue;
            $bindingName = "binding_" . substr(md5($dimensionName), 0, 5);
            $valueAttributeLabel = "uri";
            $attributes[$dimension->getUri()][$valueAttributeLabel] = $bindingName;
            if (!isset($parentDrilldownBindings["?" . $bindingName])) $parentDrilldownBindings["?" . $bindingName] = [];

            if (isset($selectedAggregates[$dimension->getUri()])) {
                $selectedAggregateDimensions[$dimension->getUri()] = $dimension;
                $aggregateBindings[$dimension->getUri()] = "?$bindingName";
            }
            if (isset($selectedDrilldowns[$dimension->getUri()])) {
                $selectedDrilldownDimensions[$dimension->getUri()] = $dimension;
                $drilldownBindings[$dimension->getUri()] = "?$bindingName";

            }
            if (isset($filterMap[$dimension->getUri()])) {
                $selectedFilterDimensions[$dimension->getUri()] = $dimension;
                $filterBindings[$dimension->getUri()] = "?{$bindingName}";

            }

            if (isset($sorterMap[$dimension->getUri()])) {
                $selectedSorterDimensions[$dimension->getUri()] = $dimension;
                $sorterBindings[$dimension->getUri()] = "?$bindingName";

            }

        }
        foreach ($measures as $measureName => $measure) {
            if (!isset($selectedAggregates[$measure->getUri()])) continue;
            $selectedAggregateDimensions[$measure->getUri()] = $measure;
            $bindingName = "binding_" . substr(md5($measure->getUri()), 0, 5);
            $valueAttributeLabel = "sum";
            $attributes[$measure->getUri()][$valueAttributeLabel] = $bindingName;
            $aggregateBindings[$measure->getUri()] = "?$bindingName";
        }


        $sliceSubGraph = new SubPattern([
            new TriplePattern("?slice", "a", "qb:Slice"),
            new TriplePattern("?slice", "qb:observation", "?observation"),

        ], false);

        $dataSetSubGraph = new SubPattern([
            new TriplePattern("?observation", "qb:dataSet", "<{$model->getDataset()}>"),

        ], false);


        $needsSliceSubGraph = false;
        $needsDataSetSubGraph = false;

        foreach ($selectedDrilldownDimensions as $dimensionName => $dimension) {

            $attribute = $dimensionName;
            $attachment = $dimension->getAttachment();
            if (isset($attachment) && $attachment == "qb:Slice") {
                $needsSliceSubGraph = true;
                $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $drilldownBindings[$attribute], false));
            } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                $needsDataSetSubGraph = true;
                $dataSetSubGraph->add(new TriplePattern("<{$model->getDataset()}>", $attribute, $drilldownBindings[$attribute], false));
            } else {
                $patterns [] = new TriplePattern("?observation", $attribute, $drilldownBindings[$attribute], false);
            }


            if ($dimension instanceof Dimension) {
                $dimensionPatterns = &$selectedDrilldowns[$attribute];
                foreach ($dimensionPatterns as $patternName => $dimensionPattern) {
                    $actualAttribute = array_filter($dimension->attributes, function ($attribute)use($patternName){return $attribute->getUri()==$patternName;});
                    $childBinding = $drilldownBindings[$attribute] . "_" . substr(md5($patternName), 0, 5);
                    $this->bindingsToLanguages[$childBinding] = reset($actualAttribute)->getLanguages();
                    $attributes[$attribute][$patternName] = $attributes[$attribute]["uri"] . "_" . substr(md5($patternName), 0, 5);
                    $drilldownBindings[] = $childBinding;
                    $parentDrilldownBindings[$drilldownBindings[$attribute]][$childBinding] = $childBinding;


                    if (isset($attachment) && $attachment == "qb:Slice") {
                        $sliceSubGraph->add(new TriplePattern($drilldownBindings[$attribute], $patternName, $drilldownBindings[$attribute] . "_" . substr(md5($patternName), 0, 5), false));
                    } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                        $dataSetSubGraph->add(new TriplePattern($drilldownBindings[$attribute], $patternName, $drilldownBindings[$attribute] . "_" . substr(md5($patternName), 0, 5), false));
                    } else {
                        $patterns [] = new TriplePattern($drilldownBindings[$attribute], $patternName, $drilldownBindings[$attribute] . "_" . substr(md5($patternName), 0, 5), false);

                    }

                }

            }
        }
        foreach ($selectedFilterDimensions as $dimensionName => $dimension) {
            $attribute = $dimensionName;
            $attachment = $dimension->getAttachment();
            if (isset($attachment) && $attachment == "qb:Slice") {
                $needsSliceSubGraph = true;
                $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $filterBindings[$attribute], false));
            } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                $needsDataSetSubGraph = true;
                $dataSetSubGraph->add(new TriplePattern("<{$model->getDataset()}>", $attribute, $filterBindings[$attribute], false));
            } else {
                $patterns [] = new TriplePattern("?observation", $attribute, $filterBindings[$attribute], false);
            }

            // $finalFilters[] = $filterMap[$attribute];

            if ($dimension instanceof Dimension) {
                $dimensionPatterns = &$filterMap[$attribute];
                //dd($dimensionPatterns);
                //if(!is_array($dimensionPatterns)) continue;
                foreach ($dimensionPatterns as $patternName => $dimensionPattern) {

                    $attributes[$attribute][$patternName] = $attributes[$attribute]["uri"] . "_" . substr(md5($patternName), 0, 5);
                    $filterBindings[] = $filterBindings[$attribute] . "_" . substr(md5($patternName), 0, 5);
                    if (is_array($filterMap[$attribute]) && isset($filterMap[$attribute][$patternName])) {
                        if (isset($filterMap[$attribute][$patternName]->transitivity)) {
                            $transitivity = $filterMap[$attribute][$patternName]->transitivity;
                        } else $transitivity = null;
                        $filterMap[$attribute][$patternName]->binding = $filterBindings[$attribute] . "_" . substr(md5($patternName), 0, 5);
                        $finalFilters[] = $filterMap[$attribute][$patternName];
                        if (isset($attachment) && $attachment == "qb:Slice") {
                            $sliceSubGraph->add(new TriplePattern($filterBindings[$attribute], $patternName, $filterBindings[$attribute] . "_" . substr(md5($patternName), 0, 5), false, $transitivity));
                        } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                            $dataSetSubGraph->add(new TriplePattern($filterBindings[$attribute], $patternName, $filterBindings[$attribute] . "_" . substr(md5($patternName), 0, 5), false, $transitivity));
                        } else {
                            $patterns [] = new TriplePattern($filterBindings[$attribute], $patternName, $filterBindings[$attribute] . "_" . substr(md5($patternName), 0, 5), false, $transitivity);

                        }

                    } else {
                        $filterMap[$attribute]->binding = $filterBindings[$attribute];
                        $finalFilters[] = $filterMap[$attribute];
                    }


                }

            }
        }


        foreach ($selectedSorterDimensions as $dimensionName => $dimension) {
            $attribute = $dimensionName;
            $attachment = $dimension->getAttachment();
            if (isset($attachment) && $attachment == "qb:Slice") {
                $needsSliceSubGraph = true;
                $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $sorterBindings[$attribute], false));
            } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                $needsDataSetSubGraph = true;
                $dataSetSubGraph->add(new TriplePattern("<{$model->getDataset()}>", $attribute, $sorterBindings[$attribute], false));
            } else {
                $patterns [] = new TriplePattern("?observation", $attribute, $sorterBindings[$attribute], false);
            }
            if (isset($sorterMap[$attribute]) && $sorterMap[$attribute] instanceof Sorter) {
                $sorterMap[$attribute]->binding = $sorterBindings[$attribute];
                $finalSorters[] = $sorterMap[$attribute];

            }

            // $finalFilters[] = $filterMap[$attribute];

            if ($dimension instanceof Dimension) {
                $dimensionPatterns = &$sorterMap[$attribute];
                //if (!is_array($dimensionPatterns)) continue;

                foreach ($dimensionPatterns as $patternName => $dimensionPattern) {
                    $attributes[$attribute][$patternName] = $attributes[$attribute]["uri"] . "_" . substr(md5($patternName), 0, 5);
                    $sorterBindings[] = $sorterBindings[$attribute] . "_" . substr(md5($patternName), 0, 5);

                    if (is_array($sorterMap[$attribute]) && isset($sorterMap[$attribute][$patternName])) {
                        $sorterMap[$attribute][$patternName]->binding = $sorterBindings[$attribute] . "_" . substr(md5($patternName), 0, 5);
                        $finalSorters[] = $sorterMap[$attribute][$patternName];
                        if (isset($attachment) && $attachment == "qb:Slice") {
                            $sliceSubGraph->add(new TriplePattern($sorterBindings[$attribute], $patternName, $sorterBindings[$attribute] . "_" . substr(md5($patternName), 0, 5), false));
                        } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                            $dataSetSubGraph->add(new TriplePattern($sorterBindings[$attribute], $patternName, $sorterBindings[$attribute] . "_" . substr(md5($patternName), 0, 5), false));
                        } else {
                            $patterns [] = new TriplePattern($sorterBindings[$attribute], $patternName, $sorterBindings[$attribute] . "_" . substr(md5($patternName), 0, 5), false);

                        }
                    } else {
                        $sorterMap[$attribute]->binding = $sorterBindings[$attribute];
                        $finalSorters[] = $sorterMap[$attribute];
                    }


                }

            }
        }


        foreach ($selectedAggregateDimensions as $dimensionName => $dimension) {
            $attribute = $dimensionName;
            $attachment = $dimension->getAttachment();
            if (isset($attachment) && $attachment == "qb:Slice") {
                $needsSliceSubGraph = true;
                $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $aggregateBindings[$attribute], false));
            }
            if (isset($attachment) && $attachment == "qb:DataSet") {
                $needsDataSetSubGraph = true;
                $dataSetSubGraph->add(new TriplePattern("<{$model->getDataset()}>", $attribute, $aggregateBindings[$attribute], false));
            } else {
                $patterns [] = new TriplePattern("?observation", $attribute, $aggregateBindings[$attribute], false);
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
        //dd($model);
        // echo($queryBuilder->format());
        $bindings[] = "?observation";

        if ($needsSliceSubGraph) {
            $patterns[] = $sliceSubGraph;
        }
        if ($needsDataSetSubGraph) {
            $patterns[] = $dataSetSubGraph;
        }
        $dataset = $model->getDataset();
        //$dsd = $model->getDsd();
        $patterns[] = new TriplePattern('?observation', 'a', 'qb:Observation');
        $patterns[] = new TriplePattern('?observation', 'qb:dataSet', "<$dataset>");
        $queryBuilderC = $this->buildC($parentDrilldownBindings, $patterns, $filterBindings, $finalFilters);
        /** @var EasyRdf_Sparql_Result $countResult */
        // dd($parentDrilldownBindings);
          //echo($queryBuilderC->format());die;

        $countResult = $this->sparql->query(
            $queryBuilderC->getSPARQL()
        );


        //  $count = $countResult[0]->_count->getValue();


        $queryBuilderS = $this->buildS($aggregateBindings, $patterns, $finalFilters);
        /** @var EasyRdf_Sparql_Result $countResult */
       // echo($queryBuilderS->format());die;
        $summaryResult = $this->sparql->query(
            $queryBuilderS->getSPARQL()
        );

        $this->summary = $this->rdfResultsToArray3($summaryResult, $attributes, $model, array_merge($selectedAggregates))[0];
        $count = $countResult[0]->_count->getValue();
        //dd($drilldownBindings);
        //dd($finalFilters);

        $queryBuilder = $this->build($aggregateBindings, $drilldownBindings, $parentDrilldownBindings, $sorterBindings, $patterns, $filterBindings, $finalFilters);


        $queryBuilder
            ->limit($this->page_size)
            ->offset($offset);

        foreach (($finalSorters) as $sorter) {
            if ($sorter->property == "_count") {
                $queryBuilder = $queryBuilder->orderBy("?" . $sorter->property, strtoupper($sorter->direction));
                continue;
            }
            if (in_array($sorter->property, $aggregates)) {
                $queryBuilder = $queryBuilder->orderBy($sorter->binding , strtoupper($sorter->direction));

            } else {
                $queryBuilder = $queryBuilder->orderBy($sorter->binding, strtoupper($sorter->direction));
            }
        }
        /* $queryBuilder
             ->orderBy("?observation");*/

        //echo $queryBuilder->format(); die;
     //   DIE;

        // die;
        /** @var EasyRdf_Sparql_Result $result */
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        //dd($result);
        Log::info($queryBuilder->format());

        // echo($result->dump());
//dd($selectedPatterns);
        //dd($attributes);
        // dd( array_merge($selectedAggregates,$selectedDrilldowns));
        $results = $this->rdfResultsToArray3($result, $attributes, $model, array_merge($selectedAggregates, $selectedDrilldowns));
//dd($results);
        $this->cells = $results;
        $this->total_cell_count = $count;

    }

    /**
     * @param array $aggregateBindings
     * @param array $drilldownBindings
     * @param array $sorterBindings
     * @param Dimension[] $dimensionPatterns
     * @param FilterDefinition[] $filterMap
     * @return QueryBuilder
     * @internal param array $bindings
     */
    private function build(array $aggregateBindings, array $drilldownBindings, array $parentDrilldownBindings, array $sorterBindings, array $dimensionPatterns, array $filterBindings, array $filterMap = [])
    {
        $allFilteredFields =  array_unique($filterBindings);
        $allSelectedFields = array_unique(array_flatten($drilldownBindings));
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"), config("sparql.excusedPrefixes"));
        $interMediateQuery = $queryBuilder->newSubquery();
        $basicQueryBuilder = $queryBuilder->newSubquery();
        $outsiderFilteredLabels = [];
        $innerSelectedFields = [];
        $patternsCollection = new Collection($dimensionPatterns);
        $uniques = $patternsCollection->unique(function($item){ return json_encode($item);  });

//dd($dimensionPatterns);
        foreach ($uniques as $dimensionPattern) {
            if ($dimensionPattern instanceof TriplePattern) {
                if ($dimensionPattern->predicate == "skos:prefLabel") {
                    if(in_array($dimensionPattern->object, $allFilteredFields)){
                        $basicQueryBuilder->where($dimensionPattern->subject, self::expand($dimensionPattern->predicate, $dimensionPattern->transitivity), $dimensionPattern->object);
                    }
                        $outsiderFilteredLabels[] = $dimensionPattern->object;
                        $queryBuilder->optional($queryBuilder->newSubgraph()->filter($this->buildLanguageFilterExpression($dimensionPattern->object))->where($dimensionPattern->subject, self::expand($dimensionPattern->predicate, $dimensionPattern->transitivity), $dimensionPattern->object));


                } else {
                    if (in_array($dimensionPattern->object, $allSelectedFields)) $innerSelectedFields[$dimensionPattern->object] = $dimensionPattern->object;
                    $basicQueryBuilder->where($dimensionPattern->subject, self::expand($dimensionPattern->predicate, $dimensionPattern->transitivity), $dimensionPattern->object);

                }

            } elseif ($dimensionPattern instanceof SubPattern) {

                foreach ($dimensionPattern->patterns as $pattern) {
                    if ($pattern->predicate == "skos:prefLabel") {
                        if(in_array($pattern->object, $allFilteredFields)){
                            $basicQueryBuilder->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);
                        }
                            $outsiderFilteredLabels[] = $pattern->object;
                        $queryBuilder->optional($queryBuilder->newSubgraph()->filter( $this->buildLanguageFilterExpression($pattern->object) )->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object));


                    } else {
                        if (in_array($pattern->object, $allSelectedFields)) $innerSelectedFields[$pattern->object] = $pattern->object;
                        $basicQueryBuilder->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);

                    }

                    /*     if($pattern->isOptional){
                           $subGraph->optional($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                       }
                       else{
                           $subGraph->where($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                       }*/
                }

                // $queryBuilder->optional($subGraph);
            }
        }

        $alreadyFiltered = [];

        foreach ($filterMap as $filter) {
            if(isset($alreadyFiltered[$filter->binding]))continue;
            $alreadyFiltered[$filter->binding] = $filter;
            if (!$filter->isCardinal) {
                $filter->value = trim($filter->value, '"');
                $filter->value = trim($filter->value, "'");


                $this->doFilter($basicQueryBuilder, $filter->binding, $filter->value);
            } else {

                $values = [];
                foreach ($filter->values as $value) {
                    $binding = ltrim($filter->binding, "?");
                    $val = trim($value, "'\"");
                    if (URL::isValidUrl($val)) {
                        $val = "<{$val}>";
                    } else {
                        $val = "'{$val}'";
                    }

                    $values[] = [$binding => "$val"];
                }
                $queryBuilder->values_multi($values);
            }
        }

        $innerAggregateBindings = array_merge($aggregateBindings,array_diff($drilldownBindings, $outsiderFilteredLabels));
        $aggregatedInnerAggregateBindings = [];
        foreach ($innerAggregateBindings as $binding) {
            $aggregatedInnerAggregateBindings [] = "(MAX($binding) AS {$binding})";
        }


        $basicQueryBuilder->select(array_merge($aggregatedInnerAggregateBindings,["?observation"]));


        $basicQueryBuilder->groupBy("?observation");
        $interMediateQuery->subquery($basicQueryBuilder);

        $agBindings = [];
        foreach ($aggregateBindings as $binding) {
            $agBindings [] = "(SUM(ABS({$binding})) AS {$binding}__)";
        }
        $agBindings[] = "(COUNT(?observation) AS ?_count)";

        $drldnBindings = [];

        foreach ($drilldownBindings as $binding) {
            $drldnBindings [] = "$binding";
        }

      //  echo $interMediateQuery->format();die;

        $interMediateQuery
            ->select(array_merge($agBindings, $innerSelectedFields));
        if (count($drilldownBindings) > 0 && count($innerSelectedFields)>0)  {
            $interMediateQuery->groupBy(array_unique(array_merge($innerSelectedFields)));
        }


        $outerSelections = $outsiderFilteredLabels+$innerSelectedFields;
        if(!empty($outerSelections))$queryBuilder->groupBy($outerSelections);

        $outerSelections[] = "(MAX(?_count) AS ?_count)";

        foreach ($aggregateBindings as $aggregateBinding) {
            $outerSelections[] = "(MAX({$aggregateBinding}__) AS {$aggregateBinding})";
        }

        $queryBuilder->subquery($interMediateQuery);
        $queryBuilder->select($outerSelections);

//echo  $queryBuilder->format() ; die;
        return $queryBuilder;

    }

    private function buildS(array $aggregateBindings, array $dimensionPatterns, array $filterMap = [])
    {
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"), config("sparql.excusedPrefixes"));
        $basicQueryBuilder = $queryBuilder->newSubquery();
        $patternsCollection = new Collection($dimensionPatterns);
        $uniques = $patternsCollection->unique(function($item){ return json_encode($item);  });

        foreach ($uniques as $dimensionPattern) {
            if ($dimensionPattern instanceof TriplePattern) {

                $basicQueryBuilder->where($dimensionPattern->subject, self::expand($dimensionPattern->predicate, $dimensionPattern->transitivity), $dimensionPattern->object);

            } elseif ($dimensionPattern instanceof SubPattern) {
                // $subGraph = $queryBuilder->newSubgraph();

                foreach ($dimensionPattern->patterns as $pattern) {
                    $basicQueryBuilder->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);

                    /* if($pattern->isOptional){
                         $subGraph->optional($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                     }
                     else{
                         $subGraph->where($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                     }*/
                }

                //$queryBuilder->optional($subGraph);
            }
        }

        $alreadyFiltered = [];

        foreach ($filterMap as $filter) {
            if(isset($alreadyFiltered[$filter->binding]))continue;
            $alreadyFiltered[$filter->binding] = $filter;

            if (!$filter->isCardinal) {
                $filter->value = trim($filter->value, '"');
                $filter->value = trim($filter->value, "'");
                $this->doFilter($basicQueryBuilder, $filter->binding, $filter->value);

            } else {

                $values = [];
                foreach ($filter->values as $value) {
                    $binding = ltrim($filter->binding, "?");
                    $val = trim($value, "'\"");
                    if (URL::isValidUrl($val)) {
                        $val = "<{$val}>";
                    } else {
                        $val = "'{$val}'";
                    }

                    $values[] = [$binding => "$val"];
                }
                $basicQueryBuilder->values_multi($values);
            }
        }

        $agBindings = [];
        foreach ($aggregateBindings as $binding) {
            $agBindings [] = "(SUM(ABS($binding)) AS {$binding}__)";
        }
        $agBindings[] = "(COUNT(?observation) AS ?_count)";


        $basicQueryBuilder
            ->select($aggregateBindings + ["?observation"]);
        $basicQueryBuilder->groupBy("?observation");


        $queryBuilder->select($agBindings);
        $queryBuilder->subquery($basicQueryBuilder);

        return $queryBuilder;

    }

    private function buildC(array $parentDrilldownBindings, array $dimensionPatterns, array $filterBindings, array $filterMap = [])
    {
       // dd($parentDrilldownBindings);
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"), config("sparql.excusedPrefixes"));
        $subQuery = $queryBuilder->newSubquery();

        $patternsCollection = new Collection($dimensionPatterns);
        $uniques = $patternsCollection->unique(function($item){ return json_encode($item);  });


        foreach ($uniques as $dimensionPattern) {
            if ($dimensionPattern instanceof TriplePattern) {


                $subQuery->where($dimensionPattern->subject, self::expand($dimensionPattern->predicate, $dimensionPattern->transitivity), $dimensionPattern->object);

            } elseif ($dimensionPattern instanceof SubPattern) {

                foreach ($dimensionPattern->patterns as $pattern) {
                    $subQuery->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);

                    /*  if($pattern->isOptional){
                          $subGraph->optional($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                      }
                      else{
                          $subGraph->where($pattern->subject, self::expand($pattern->predicate), $pattern->object);
                      }*/
                }

            }
        }
                $alreadyFiltered = [];
        foreach ($filterMap as $filter) {

            if(isset($alreadyFiltered[$filter->binding]))continue;
            $alreadyFiltered[$filter->binding] = $filter;

            if (!$filter->isCardinal) {
                $filter->value = trim($filter->value, '"');
                $filter->value = trim($filter->value, "'");

                $this->doFilter($subQuery, $filter->binding, $filter->value);
            } else {
                $values = [];
                foreach ($filter->values as $value) {
                    $binding = ltrim($filter->binding, "?");
                    $val = trim($value, "'\"");
                    if (URL::isValidUrl($val)) {
                        $val = "<{$val}>";
                    } else {
                        $val = "'{$val}'";
                    }

                    $values[] = [$binding => "$val"];
                }
                $subQuery->values_multi($values);
            }
        }

        $drldnBindings = [];

        foreach (array_keys($parentDrilldownBindings) as $binding) {
            $drldnBindings [] = "$binding";
        }

//dd(array_unique(array_keys($parentDrilldownBindings)));
        $subQuery
            ->select(array_unique(array_keys($parentDrilldownBindings)));
        if(!empty($parentDrilldownBindings))$subQuery->groupBy(array_unique(array_keys($parentDrilldownBindings)));

        $queryBuilder->subquery($subQuery);
        $queryBuilder->select("(count(*) AS ?_count)");
        return $queryBuilder;

    }

    protected function doFilter(QueryBuilder $queryBuilder, string $binding, $value){
        if(!config("sparql.virtuoso",false)){

            return $queryBuilder->filter("STR({$binding})='{$value}'");
        }
        else{
            if (URL::isValidUrl($value)) {
                return $queryBuilder->filter("$binding = <{$value}>");
            }
            else return $queryBuilder->where($binding, "bif:contains", '"\''.$value.'\'"');
        }
    }


}