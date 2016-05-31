<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 22/03/2016
 * Time: 18:22:54
 */

namespace App\Model\Globals;


use App\Model\Aggregate;
use App\Model\AggregateResult;
use App\Model\BabbageModel;
use App\Model\Dimension;
use App\Model\FilterDefinition;
use App\Model\GenericProperty;
use App\Model\Sorter;
use App\Model\Sparql\SubPattern;
use App\Model\Sparql\TriplePattern;
use App\Model\SparqlModel;
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
        $chosenDatasets = [];
        $datasetAggregates = [];
        // dd($model);
        if (count($aggregates) < 1 || $aggregates[0] == "") {
            $aggregates = [];
            foreach ($model->aggregates as $agg) {
                if ($agg->ref != "_count" && ($agg instanceof GlobalAggregate)) {
                    $aggregates[] = $agg->ref;
                    $chosenDatasets[] = $agg->getDataSets();
                    foreach ($agg->getDataSets() as $dataset) {
                        $datasetAggregates[$dataset][] = $agg;
                    }

                }
            }
        } else {
            foreach ($model->aggregates as $agg) {
                if ($agg->ref != "_count" && ($agg instanceof GlobalAggregate)) {
                    foreach ($aggregates as $aggregate) {
                        if ($agg->ref == $aggregate) {
                            $chosenDatasets[] = $agg->getDataSets();
                            foreach ($agg->getDataSets() as $dataset) {
                                $datasetAggregates[$dataset][] = $agg;
                            }
                        }
                    }


                }
            }
        }


        //dd($selectedAggregates);

        $offset = $this->page_size * $this->page;
        $sliceSubGraphs = [];
        $dataSetSubGraphs = [];

        $measures = $model->measures;
        $finalSorters = [];
        $finalFilters = [];
        /** @var Sorter[] $sorterMap */
        $sorterMap = [];
        /** @var Sorter $sorter */
        /*foreach ($sorters as $sorter) {
            if ($sorter->property == "_count") {
                $finalSorters[$sorter->property] = $sorter;
                continue;
            }
            if ($sorter->property == "") continue;
            $path = explode('.', $sorter->property);
            $fullName = $this->getAttributePathByName($model, $path);
            if (empty($fullName)) continue;

            $this->array_set($sorterMap, $fullName, $sorter);
        }*/
        /** @var FilterDefinition[] $filterMap */
        $filterMap = [];
        $attributes = [];
        $patterns = [];

        /** @var GenericProperty[] $selectedAggregateDimensions */
        $selectedAggregateDimensions = [];
        /** @var GenericProperty[] $selectedDrilldownDimensions */
        $selectedDrilldownDimensions = [];
        $selectedFilterDimensions = [];
        $selectedSorterDimensions = [];
        $drilldownBindings = [];
        $filterBindings = [];
        $sorterBindings = [];
        $parentDrilldownBindings = [];

        $aggregateBindings = [];
        $selectedDrilldowns = [];
        $selectedFilters = [];
        $selectedSorters = [];


        //***********************************************************************************


        foreach ($sorters as $sorterName=>$sorter) {

            if ($sorter->property == "_count") {
                $finalSorters[] = $sorter;

                continue;
            }
   
            if ($sorter->property == "") continue;

            $sorterElements = explode(".", $sorterName);
            foreach ($model->dimensions as $dimension) {
                if ($dimension->ref == $sorterElements[0]) {
                    $foundDimension = $dimension;
                    break;
                }
            }
            if (!isset($foundDimension)) continue;
            if ($sorterName == $foundDimension->label_ref) {
                $attributeModifier = "label_ref";
                $attributeSimpleModifier = "label_attribute";
            } else {
                $attributeModifier = "key_ref";
                $attributeSimpleModifier = "key_attribute";
            }

            /** @var GlobalDimension $foundDimension */

            $chosenDatasets[] = array_map(function (Dimension $dimension) {
                return $dimension->getDataset();
            }, $foundDimension->getInnerDimensions());

            /** @var Dimension $innerDimension */
            foreach ($foundDimension->getInnerDimensions() as $innerDimension) {
                $datasetURI = $innerDimension->getDataSet();

                $fullName =  [$innerDimension->getUri(), $innerDimension->attributes[$innerDimension->$attributeSimpleModifier]->getUri()];
                if (empty($fullName)) continue;
                if(!isset($sorterMap[$datasetURI]))$sorterMap[$datasetURI]=[];

                $this->array_set($sorterMap[$datasetURI], $fullName, $sorter);


                if (!isset($selectedSorters[$innerDimension->getDataSet()]))
                    $selectedSorters[$innerDimension->getDataSet()] = [];
                $selectedSorters[$innerDimension->getDataSet()] = array_merge_recursive(
                    $selectedSorters[$innerDimension->getDataSet()],
                    $this->globalDimensionToPatterns([$innerDimension],[$innerDimension->$attributeModifier])
                );
                $bindingName = "binding_" . substr(md5($foundDimension->ref), 0, 5);
                $valueAttributeLabel = "uri";
                $attributes[$innerDimension->getDataSet()][$innerDimension->getUri()][$valueAttributeLabel] = $bindingName;
                $selectedSorterDimensions[$innerDimension->getDataSet()][$innerDimension->getUri()] = $innerDimension;
                $sorterBindings[$innerDimension->getDataSet()][$innerDimension->getUri()] = "?$bindingName";
                $datasetURI = $innerDimension->getDataSet();
                if (isset($sliceSubGraphs[$datasetURI][$foundDimension->getUri()]) && isset($sliceSubGraphs[$datasetURI][$foundDimension->getUri()][0]))
                    $sliceSubGraph = $sliceSubGraphs[$datasetURI][$foundDimension->getUri()][0];
                else {
                    $sliceSubGraph = new SubPattern([
                        new TriplePattern("?slice", "a", "qb:Slice"),
                        new TriplePattern("?slice", "qb:observation", "?observation"),

                    ], false);
                }

                $needsSliceSubGraph = false;
                if (isset($dataSetSubGraphs[$datasetURI][$foundDimension->getUri()]) && isset($dataSetSubGraphs[$datasetURI][$foundDimension->getUri()][0]))
                    $dataSetSubGraph = $dataSetSubGraphs[$datasetURI][$foundDimension->getUri()][0];
                else {
                    $dataSetSubGraph = new SubPattern([
                        new TriplePattern("?dataSet", "a", "qb:DataSet"),
                        new TriplePattern("?observation", "qb:dataSet", "?dataSet"),

                    ], false);
                }
                $needsDataSetSubGraph = false;
                /** @var Dimension $dimension */
                $attribute = $innerDimension->getUri();
                $attachment = $innerDimension->getAttachment();
                if (isset($attachment) && $attachment == "qb:Slice") {
                    $needsSliceSubGraph = true;
                    $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $sorterBindings[$datasetURI][$attribute], false));
                } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                    $needsDataSetSubGraph = true;

                    $dataSetSubGraph->add(new TriplePattern("?dataSet", $attribute, $sorterBindings[$datasetURI][$attribute], false));
                } else {
                    $patterns[$datasetURI][$foundDimension->getUri()][] = new TriplePattern("?observation", $attribute, $sorterBindings[$datasetURI][$attribute], false);
                }
                if (isset($sorterMap[$datasetURI][$attribute][$attribute]) && $sorterMap[$datasetURI][$attribute][$attribute] instanceof Sorter) {
                    $sorterMap[$datasetURI][$attribute][$attribute]->binding = $sorterBindings[$datasetURI][$attribute];
                    $finalSorters[$sorterName] = $sorterMap[$datasetURI][$attribute];
                }
                if ($innerDimension instanceof Dimension) {
                    $dimensionPatterns = &$selectedSorters[$datasetURI][$attribute];
                    foreach ($dimensionPatterns as $patternName => $dimensionPattern) {
                        $attributes[$datasetURI][$attribute][$patternName] = $attributes[$datasetURI][$attribute]["uri"] . "_" . substr(md5($patternName), 0, 5);
                        $sorterBindings[$datasetURI][] = $sorterBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5);
                        $sorterMap[$datasetURI][$attribute][$patternName]->binding = $sorterBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5);
                        $finalSorters[$sorterName] = $sorterMap[$datasetURI][$attribute][$patternName];
                        if (isset($attachment) && $attachment == "qb:Slice") {
                            $sliceSubGraph->add(new TriplePattern($sorterBindings[$datasetURI][$attribute], $patternName, $sorterBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false));
                        } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                            $dataSetSubGraph->add(new TriplePattern($sorterBindings[$datasetURI][$attribute], $patternName, $sorterBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false));
                        } else {
                            $patterns[$datasetURI] [$foundDimension->getUri()][] = new TriplePattern($sorterBindings[$datasetURI][$attribute], $patternName, $sorterBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false);
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


        //**************************************************************************************

        //////////////////////////////////////////////////////////////////////////////////////


        foreach ($filters as $filterName=>$filter) {


            $filterElements = explode(".", $filterName);
            foreach ($model->dimensions as $dimension) {
                if ($dimension->ref == $filterElements[0]) {
                    $foundDimension = $dimension;
                    break;
                }
            }
            if (!isset($foundDimension)) continue;
            if ($filterName == $foundDimension->label_ref) {
                $attributeModifier = "label_ref";
                $attributeSimpleModifier = "label_attribute";
            } else {
                $attributeModifier = "key_ref";
                $attributeSimpleModifier = "key_attribute";
            }

            /** @var GlobalDimension $foundDimension */

            $chosenDatasets[] = array_map(function (Dimension $dimension) {
                return $dimension->getDataset();
            }, $foundDimension->getInnerDimensions());

            /** @var Dimension $innerDimension */
            foreach ($foundDimension->getInnerDimensions() as $innerDimension) {
                $datasetURI = $innerDimension->getDataSet();

                $fullName = [$innerDimension->getUri(), $innerDimension->attributes[$innerDimension->$attributeSimpleModifier]->getUri()];
                if(empty($fullName))continue;
                if(!isset($filterMap[$datasetURI]))$filterMap[$datasetURI]=[];
                $this->array_set($filterMap[$datasetURI], $fullName, $filter);

                if (!isset($selectedFilters[$innerDimension->getDataSet()]))
                    $selectedFilters[$innerDimension->getDataSet()] = [];

                $selectedFilters[$innerDimension->getDataSet()] = array_merge_recursive(
                    $selectedFilters[$innerDimension->getDataSet()],
                    $this->globalDimensionToPatterns([$innerDimension],[$innerDimension->$attributeModifier])
                );
                $bindingName = "binding_" . substr(md5($foundDimension->ref), 0, 5);
                $valueAttributeLabel = "uri";
                $attributes[$innerDimension->getDataSet()][$innerDimension->getUri()][$valueAttributeLabel] = $bindingName;
                $selectedFilterDimensions[$innerDimension->getDataSet()][$innerDimension->getUri()] = $innerDimension;
                $filterBindings[$innerDimension->getDataSet()][$innerDimension->getUri()] = "?$bindingName";
                $datasetURI = $innerDimension->getDataSet();
                if (isset($sliceSubGraphs[$datasetURI][$foundDimension->getUri()]) && isset($sliceSubGraphs[$datasetURI][$foundDimension->getUri()][0]))
                    $sliceSubGraph = $sliceSubGraphs[$datasetURI][$foundDimension->getUri()][0];
                else {
                    $sliceSubGraph = new SubPattern([
                        new TriplePattern("?slice", "a", "qb:Slice"),
                        new TriplePattern("?slice", "qb:observation", "?observation"),

                    ], false);
                }

                $needsSliceSubGraph = false;
                if (isset($dataSetSubGraphs[$datasetURI][$foundDimension->getUri()]) && isset($dataSetSubGraphs[$datasetURI][$foundDimension->getUri()][0]))
                    $dataSetSubGraph = $dataSetSubGraphs[$datasetURI][$foundDimension->getUri()][0];
                else {
                    $dataSetSubGraph = new SubPattern([
                        new TriplePattern("?dataSet", "a", "qb:DataSet"),
                        new TriplePattern("?observation", "qb:dataSet", "?dataSet"),

                    ], false);
                }
                $needsDataSetSubGraph = false;
                /** @var Dimension $dimension */
                $attribute = $innerDimension->getUri();
                $attachment = $innerDimension->getAttachment();
                if (isset($attachment) && $attachment == "qb:Slice") {
                    $needsSliceSubGraph = true;
                    $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $filterBindings[$datasetURI][$attribute], false));
                } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                    $needsDataSetSubGraph = true;

                    $dataSetSubGraph->add(new TriplePattern("?dataSet", $attribute, $filterBindings[$datasetURI][$attribute], false));
                } else {
                    $patterns[$datasetURI][$foundDimension->getUri()][] = new TriplePattern("?observation", $attribute, $filterBindings[$datasetURI][$attribute], false);
                }

                if ($innerDimension instanceof Dimension) {
                    $dimensionPatterns = &$selectedFilters[$datasetURI][$attribute];
                    foreach ($dimensionPatterns as $patternName => $dimensionPattern) {
                        $attributes[$datasetURI][$attribute][$patternName] = $attributes[$datasetURI][$attribute]["uri"] . "_" . substr(md5($patternName), 0, 5);
                        $filterBindings[$datasetURI][] = $filterBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5);
                        $filterMap[$datasetURI][$attribute][$patternName]->binding = $filterBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5);
                        $finalFilters[$filterName] = $filterMap[$datasetURI][$attribute][$patternName];

                        if (isset($attachment) && $attachment == "qb:Slice") {
                            $sliceSubGraph->add(new TriplePattern($filterBindings[$datasetURI][$attribute], $patternName, $filterBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false));
                        } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                            $dataSetSubGraph->add(new TriplePattern($filterBindings[$datasetURI][$attribute], $patternName, $filterBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false));
                        } else {
                            $patterns[$datasetURI] [$foundDimension->getUri()][] = new TriplePattern($filterBindings[$datasetURI][$attribute], $patternName, $filterBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false);
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


        //////////////////////////////////////////////////////////////////////////////////////

        foreach ($drilldowns as $drilldown) {
            $drilldownElements = explode(".", $drilldown);
            foreach ($model->dimensions as $dimension) {
                if ($dimension->ref == $drilldownElements[0]) {
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

            $chosenDatasets[] = array_map(function (Dimension $dimension) {
                return $dimension->getDataset();
            }, $foundDimension->getInnerDimensions());

            /** @var Dimension $innerDimension */
            foreach ($foundDimension->getInnerDimensions() as $innerDimension) {
                if (!isset($selectedDrilldowns[$innerDimension->getDataSet()]))
                    $selectedDrilldowns[$innerDimension->getDataSet()] = [];

                $selectedDrilldowns[$innerDimension->getDataSet()] = array_merge_recursive(
                    $selectedDrilldowns[$innerDimension->getDataSet()],
                    $this->globalDimensionToPatterns([$innerDimension],[$innerDimension->$attributeModifier])
                );

                $bindingName = "binding_" . substr(md5($foundDimension->ref), 0, 5);
                $parentDrilldownBindings["?" . $bindingName] = [];
                $valueAttributeLabel = "uri";
                $attributes[$innerDimension->getDataSet()][$innerDimension->getUri()][$valueAttributeLabel] = $bindingName;

                $selectedDrilldownDimensions[$innerDimension->getDataSet()][$innerDimension->getUri()] = $innerDimension;
                $drilldownBindings[$innerDimension->getDataSet()][$innerDimension->getUri()] = "?$bindingName";
                $datasetURI = $innerDimension->getDataSet();
                if (isset($sliceSubGraphs[$datasetURI][$foundDimension->getUri()]) && isset($sliceSubGraphs[$datasetURI][$foundDimension->getUri()][0]))
                    $sliceSubGraph = $sliceSubGraphs[$datasetURI][$foundDimension->getUri()][0];
                else {
                    $sliceSubGraph = new SubPattern([
                        new TriplePattern("?slice", "a", "qb:Slice"),
                        new TriplePattern("?slice", "qb:observation", "?observation"),

                    ], false);
                }

                $needsSliceSubGraph = false;
                if (isset($dataSetSubGraphs[$datasetURI][$foundDimension->getUri()]) && isset($dataSetSubGraphs[$datasetURI][$foundDimension->getUri()][0]))
                    $dataSetSubGraph = $dataSetSubGraphs[$datasetURI][$foundDimension->getUri()][0];
                else {
                    $dataSetSubGraph = new SubPattern([
                        new TriplePattern("?dataSet", "a", "qb:DataSet"),
                        new TriplePattern("?observation", "qb:dataSet", "?dataSet"),

                    ], false);
                }
                $needsDataSetSubGraph = false;
                /** @var Dimension $dimension */
                $attribute = $innerDimension->getUri();
                $attachment = $innerDimension->getAttachment();
                if (isset($attachment) && $attachment == "qb:Slice") {
                    $needsSliceSubGraph = true;
                    $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $drilldownBindings[$datasetURI][$attribute], false));
                } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                    $needsDataSetSubGraph = true;

                    $dataSetSubGraph->add(new TriplePattern("?dataSet", $attribute, $drilldownBindings[$datasetURI][$attribute], false));
                } else {
                    $patterns[$datasetURI][$foundDimension->getUri()][] = new TriplePattern("?observation", $attribute, $drilldownBindings[$datasetURI][$attribute], false);
                }

                if ($innerDimension instanceof Dimension) {
                    $dimensionPatterns = &$selectedDrilldowns[$datasetURI][$attribute];
                    foreach ($dimensionPatterns as $patternName => $dimensionPattern) {
                        $parentDrilldownBindings[$drilldownBindings[$datasetURI][$attribute]][] = $drilldownBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5);
                        $attributes[$datasetURI][$attribute][$patternName] = $attributes[$datasetURI][$attribute]["uri"] . "_" . substr(md5($patternName), 0, 5);
                        $drilldownBindings[$datasetURI][] = $drilldownBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5);

                        if (isset($attachment) && $attachment == "qb:Slice") {
                            $sliceSubGraph->add(new TriplePattern($drilldownBindings[$datasetURI][$attribute], $patternName, $drilldownBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false));
                        } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                            $dataSetSubGraph->add(new TriplePattern($drilldownBindings[$datasetURI][$attribute], $patternName, $drilldownBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false));
                        } else {
                            $patterns[$datasetURI] [$foundDimension->getUri()][] = new TriplePattern($drilldownBindings[$datasetURI][$attribute], $patternName, $drilldownBindings[$datasetURI][$attribute] . "_" . substr(md5($patternName), 0, 5), false);
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

        if (count($chosenDatasets) > 1) $chosenDatasets = call_user_func_array("array_intersect", $chosenDatasets);
        else $chosenDatasets = reset($chosenDatasets);
        $attributes = array_intersect_key($attributes, array_flip($chosenDatasets));
        $selectedDrilldowns = array_intersect_key($selectedDrilldowns, array_flip($chosenDatasets));



        $datasetAggregates = array_intersect_key($datasetAggregates, array_flip($chosenDatasets));
        if (!empty($datasetAggregates))
            $filteredAggregates = array_unique(call_user_func_array("array_merge", $datasetAggregates), SORT_REGULAR);
        else {
            $filteredAggregates = [];
        }
        $mergedAggregateRefs = array_map(function (Aggregate $agg) {
            return $agg->ref;
        }, $filteredAggregates);

        $selectedAggregates = $this->modelFieldsToPatterns($model, $mergedAggregateRefs);

        $alreadyRestrictedDatasets = array_keys(array_merge_recursive($sliceSubGraphs, $patterns, $dataSetSubGraphs));
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
                    if (!empty($alreadyRestrictedDatasets) && !in_array($innerMeasure->getDataSet(), $alreadyRestrictedDatasets)) continue; ///should not include this dataset, as aggregates have been selected that are specific NOT to this dataset
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
                        $finalSorters[$sorterMap[$attribute]->property] = $sorterMap[$attribute];
                    }

                }


            }


        }

//dd($model->dimensions["global__currency__1a842"]);
        // echo($queryBuilder->format());

        $mergedAttributes = [];
        foreach ($attributes as $datasetAttributes) {
            $mergedAttributes = array_merge($mergedAttributes, $datasetAttributes);
        }
        $mergedDrilldowns = [];
        foreach ($selectedDrilldowns as $datasetSelectedDrilldowns) {
            $mergedDrilldowns = array_merge($mergedDrilldowns, $datasetSelectedDrilldowns);
        }

        $queryBuilderC = $this->buildC(array_intersect_key(array_merge_recursive($patterns, $sliceSubGraphs, $dataSetSubGraphs), array_flip($chosenDatasets)), $parentDrilldownBindings, $filterBindings, $finalFilters);
        /** @var EasyRdf_Sparql_Result $countResult */
        //echo($queryBuilderC->format());die;
        $countResult = $this->sparql->query(
            $queryBuilderC->getSPARQL()
        );

//dd($datasetAttributes);
      //  dd($aggregateBindings);
       // dd($patterns);
        //dd(array_intersect_key(array_merge_recursive($patterns, $sliceSubGraphs, $dataSetSubGraphs), array_flip($chosenDatasets)));
        $queryBuilderS = $this->buildS($aggregateBindings, array_intersect_key(array_merge_recursive($patterns, $sliceSubGraphs, $dataSetSubGraphs), array_flip($chosenDatasets)), $filterBindings, $finalFilters);
        /** @var EasyRdf_Sparql_Result $countResult */
        //echo($queryBuilderS->format());die;
        $summaryResult = $this->sparql->query(
            $queryBuilderS->getSPARQL()
        );
        //dd($mergedAttributes);
        $summaryResults = $this->rdfResultsToArray3($summaryResult, $mergedAttributes, $model, $selectedAggregates);
        if (!empty($summaryResults)) $this->summary = $summaryResults[0];
        else {
            $this->summary = [];
        }
        $count = $countResult[0]->_count->getValue();
        $queryBuilder = $this->build($aggregateBindings, $drilldownBindings, array_intersect_key(array_merge_recursive($patterns, $sliceSubGraphs, $dataSetSubGraphs), array_flip($chosenDatasets)), $parentDrilldownBindings, $sorterBindings, $filterBindings, $finalFilters);


        $queryBuilder
            ->limit($this->page_size)
            ->offset($offset);

        if (count($chosenDatasets) > 0) {
            foreach (array_flatten($finalSorters) as $sorter) {
                if ($sorter->property == "_count") {
                    $queryBuilder->orderBy("?" . $sorter->property, strtoupper($sorter->direction));
                    continue;
                }
                $queryBuilder->orderBy($sorter->binding, strtoupper($sorter->direction));
            }
        }

        //   dd($selectedAggregates);
        //   echo  $queryBuilder->format();die;
        /* $queryBuilder
             ->orderBy("?observation");*/


        // die;
        /** @var EasyRdf_Sparql_Result $result */
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        // dd($result);
        //   echo($result->dump());
//dd($selectedPatterns);
        //  dd($model);
        /// dd(array_merge( $mergedDrilldowns, $selectedAggregates ));
        $results = $this->rdfResultsToArray3($result, $mergedAttributes, $model, array_merge($mergedDrilldowns, $selectedAggregates));
        $this->cells = $results;
        $this->total_cell_count = max($count, count($results));

    }

    /**
     * @param array $aggregateBindings
     * @param array $drilldownBindings
     * @param Dimension[] $dimensionPatterns
     * @param array $parentDrilldownBindings
     * @param FilterDefinition[] $filterMap
     * @return QueryBuilder
     * @internal param array $bindings
     */
    private function build(array $aggregateBindings, array $drilldownBindings, array $dimensionPatterns, array $parentDrilldownBindings, array $sorterBindings, array $filterBindings =[], array $filterMap = [])
    {
        //dd($dimensionPatterns);
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $innerGraph = $queryBuilder->newSubquery();
        $datasetQueries = [];
        $selectedFields = [];
        $allSelectedFields = array_unique(array_flatten($drilldownBindings));
        foreach ($drilldownBindings as $dataset => &$dataSetDrilldownBindings) {
            foreach ($allSelectedFields as $allSelectedField) {
                if (!in_array($allSelectedField, $dataSetDrilldownBindings)) {
                    $filtered = array_filter($dataSetDrilldownBindings, function ($element) use ($allSelectedField) {
                        return starts_with($allSelectedField, $element);
                    });
                    ksort($filtered);
                    $filteredValues = array_values($filtered);
                    $parentElement = reset($filteredValues);
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
            $dataSetFilterBindings = isset($filterBindings[$dataset])?$filterBindings[$dataset]:[];
            $dataSetSorterBindings = isset($sorterBindings[$dataset])?$sorterBindings[$dataset]:[];
            $selectedFields = array_merge($aggregateBindings, ["?observation"], $dataSetFilterBindings, $dataSetSorterBindings);
            if (isset($drilldownBindings[$dataset])) $selectedFields = array_merge($selectedFields, array_values($drilldownBindings[$dataset]));
            $datasetQuery->selectDistinct(array_unique($selectedFields));
            //  var_dump(array_values($drilldownBindings[$dataset]));
            $datasetQuery->where("?observation", "a", "qb:Observation");
            $datasetQuery->where("?observation", "qb:dataSet", "<$dataset>");
            $datasetQueries [$dataset] = $datasetQuery;
            //var_dump($datasetQuery->format());
        }



        foreach ($filterMap as $filter) {
            $filter->value = trim($filter->value,'"');
            $filter->value = trim($filter->value,"'");

            $innerGraph->filter("str(" . $filter->binding . ")='" . $filter->value . "'");
        }
        $innerGraph->union(array_map(function (QueryBuilder $subQueryBuilder) use ($innerGraph, $queryBuilder) {
            return $innerGraph->newSubgraph()->subquery($subQueryBuilder);
        }, $datasetQueries));

        $agBindings = [];
        foreach ($aggregateBindings as $binding) {
            $agBindings [] = "(sum($binding) AS $binding)";
        }
        $agBindings[] = "(count(?observation) AS ?_count)";

        $drldnBindings = [];

        foreach ($drilldownBindings as $dataset => $bindings) {
            foreach ($bindings as $binding) {
                $drldnBindings [] = "$binding";

            }
        }
        if (count($dimensionPatterns) > 0) {

            $innerGraph
                ->selectDistinct(array_merge($agBindings, $allSelectedFields));

            if (count($drilldownBindings) > 0) {
                $innerGraph->groupBy($allSelectedFields);
            }
        }

        $flatParentBindings = array_unique(array_keys($parentDrilldownBindings));
        foreach ($flatParentBindings as $flatParentBinding) {
            $queryBuilder->filterNotExists($queryBuilder->newSubgraph()->where($flatParentBinding . "_", "(skos:similar|^skos:similar)*",
                "?elem_")->filter("str(?elem_) < str($flatParentBinding" . "_)"));
        }

        $outerSelections = [];
        $outerGroupings = [];
        if (count($dimensionPatterns) > 0) {
            foreach ($parentDrilldownBindings as $parentBinding => $childrenBindings) {
                $queryBuilder->where($parentBinding, "(skos:similar|^skos:similar)*", $parentBinding . "_");
                $innerGraph->orderBy($parentBinding, "ASC");
                $outerSelections[] = "(" . $parentBinding . "_ AS $parentBinding)";
                $outerGroupings[] = $parentBinding . "_";
                foreach (array_unique($childrenBindings) as $childrenBinding) {
                    $outerSelections[] = "(MAX($childrenBinding) AS $childrenBinding)";
                }
            }
            foreach ($aggregateBindings as $aggregateBinding) {
                $outerSelections[] = "(SUM($aggregateBinding) AS $aggregateBinding)";

            }
        }

        $outerSelections[] = "(SUM(?_count) AS ?_count)";
        $queryBuilder->selectDistinct($outerSelections);
        if (!empty($outerGroupings))
            $queryBuilder->groupBy($outerGroupings);
        $queryBuilder->subquery($innerGraph);

        //echo $queryBuilder->format();die;
        return $queryBuilder;

    }

    private function buildS(array $aggregateBindings, array $dimensionPatterns, array $filterBindings = [], array $filterMap = [])
    {
        //  dd($aggregateBindings);
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
            $dataSetFilterBindings = isset($filterBindings[$dataset])?$filterBindings[$dataset]:[];

            $datasetQuery->selectDistinct(array_merge($aggregateBindings, ["?observation"], $dataSetFilterBindings));

            $datasetQuery->where("?observation", "a", "qb:Observation");
            $datasetQuery->where("?observation", "qb:dataSet", "<$dataset>");
            $datasetQueries [$dataset] = $datasetQuery;
        }
        foreach ($filterMap as $filter) {
            $filter->value = trim($filter->value,'"');
            $filter->value = trim($filter->value,"'");
            $queryBuilder->filter("str(" . $filter->binding . ")='" . $filter->value . "'");
        }
        $queryBuilder->union(array_map(function (QueryBuilder $subQueryBuilder) use ($queryBuilder) {
            return $queryBuilder->newSubgraph()->subquery($subQueryBuilder);
        }, $datasetQueries));

        $agBindings = [];
        foreach ($aggregateBindings as $binding) {
            $agBindings [] = "(sum($binding) AS $binding)";
        }
        $agBindings[] = "(count(?observation) AS ?_count)";

        if (!empty($dimensionPatterns))
            $queryBuilder
                ->selectDistinct($agBindings);
        // echo $queryBuilder->format();die;

        return $queryBuilder;

    }

    private function buildC(array $dimensionPatterns, array $parentDrilldownBindings, array $filterBindings = [], array $filterMap = [])
    {
        //dd($parentDrilldownBindings);
        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $midGraph = $queryBuilder->newSubquery();

        $innerGraph = $midGraph->newSubquery();
//dd($dimensionPatterns);
        $datasetQueries = [];
        foreach ($dimensionPatterns as $dataset => $dimensionPatternGroup) {
            $datasetQuery = $innerGraph->newSubquery();

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
            $dataSetFilterBindings = isset($filterBindings[$dataset])?$filterBindings[$dataset]:[];
            $datasetQuery->selectDistinct(array_merge(["?observation"], array_keys($parentDrilldownBindings), $dataSetFilterBindings));

            $datasetQuery->where("?observation", "a", "qb:Observation");
            $datasetQuery->where("?observation", "qb:dataSet", "<$dataset>");
           // echo $datasetQuery->format();die;
            $datasetQueries [$dataset] = $datasetQuery;
        }
        foreach ($filterMap as $filter) {
            $filter->value = trim($filter->value,'"');
            $filter->value = trim($filter->value,"'");
           $innerGraph->filter("str(" . $filter->binding . ")='" . $filter->value . "'");
        }
        $innerGraph->union(array_map(function (QueryBuilder $subQueryBuilder) use ($innerGraph) {
            return $innerGraph->newSubgraph()->subquery($subQueryBuilder);
        }, $datasetQueries));

        $agBindings = [];

        $agBindings[] = "(count(?observation) AS ?_count)";
        if (count($parentDrilldownBindings) > 0 && count($dimensionPatterns) > 0) {
            $innerGraph->groupBy(array_keys($parentDrilldownBindings));
        }
        if (count($dimensionPatterns) > 0)
            $innerGraph
                ->selectDistinct(array_merge($agBindings, array_keys($parentDrilldownBindings)), $filterBindings);

        //dd($dimensionPatterns);
      //   echo $innerGraph->format();die;
        $flatParentBindings = array_unique(array_keys($parentDrilldownBindings));
        foreach ($flatParentBindings as $flatParentBinding) {
            $midGraph->filterNotExists($midGraph->newSubgraph()->where($flatParentBinding . "_", "(skos:similar|^skos:similar)*",
                "?elem_")->filter("str(?elem_) < str($flatParentBinding" . "_)"));
        }

        $outerSelections = [];
        $outerGroupings = [];
        if (count($dimensionPatterns) > 0) {
            foreach ($parentDrilldownBindings as $parentBinding => $childrenBindings) {
                $midGraph->where($parentBinding, "(skos:similar|^skos:similar)*", $parentBinding . "_");

                $outerSelections[] = "(" . $parentBinding . "_ AS $parentBinding)";
                $outerGroupings[] = $parentBinding . "_";

            }
        }

       // dd($outerSelections);
        $midGraph->selectDistinct($outerSelections);
        if (!empty($outerGroupings))
            $midGraph->groupBy($outerGroupings);
        $midGraph->subquery($innerGraph);
        $queryBuilder->subquery($midGraph);
        $queryBuilder->select("(COUNT(*) AS ?_count)");


        //echo $queryBuilder->format();die;
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

                //  var_dump($fieldNames);
                //  var_dump($attribute->orig_dimension);


            }


        }
        return ($selectedDimensions);
    }
    protected function getGlobalAttributePathByName(array $dimensions, array $path){
        $result = [];
        foreach ($dimensions as $dimensionName => $dimension){

            if($path[0]==$dimensionName){
                $result[] = $dimension->getUri();

                if(count($path)>1){
                    if($path[0]==$path[1]){
                        return $result;
                    }
                    foreach ($dimension->attributes as $attributeName=>$attribute){
                        if($attributeName== $path[1]){
                            $result[] = $attribute->getUri();
                            return $result;
                        }
                    }
                }
                else{
                    return [$dimensionName];
                }
            }
        }



        return $result;

    }

}