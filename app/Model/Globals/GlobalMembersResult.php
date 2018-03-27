<?php
/**
 * Created by PhpStorm.
 * User: larjohns
 * Date: 29/04/2016
 * Time: 12:39:48
 */

namespace App\Model\Globals;


use App\Model\Dimension;
use App\Model\Sparql\FilterPattern;
use App\Model\Sparql\SubPattern;
use App\Model\Sparql\TriplePattern;
use App\Model\SparqlModel;
use App\Model\VolatileCacheManager;
use Asparagus\QueryBuilder;
use Cache;
use Illuminate\Database\Eloquent\Collection;

class GlobalMembersResult extends SparqlModel
{

    /**
     * PREFIX skos: <http://testSkos/>
     *
     * select distinct ?elem  where {
     * ?elem ?p ?o.
     * filter not exists {
     * ?elem (skos:similar|^skos:similar)* ?elem_
     * filter( str(?elem_) < str(?elem) )
     * }
     * }
     */

    public $page;
    public $page_size;
    public $order;
    public $status;
    public $data;
    protected $fields;
    protected $currencyService;
    protected $subPropertiesAcceleration = [];

    public function __construct($dimension, $page, $page_size, $orders)
    {

        parent::__construct();


        $this->page = intval($page);
        $this->page_size = intval($page_size);
        $this->order = $orders;
        $this->load($dimension, $page, $page_size, $orders);

        $this->status = "ok";
    }


    private function load($attributeShortName, $page, $page_size, $order)
    {

        if (Cache::has("members/global/$attributeShortName/$page/$page_size")) {
             $this->data = Cache::get("members/global/$attributeShortName/$page/$page_size");
            return;
        }


        $model = (new BabbageGlobalModelResult())->model;

        $this->fields = [];
        //($model->dimensions[$dimensionShortName]);
        $dimensionShortName = explode(".", $attributeShortName)[0];

        foreach ($model->dimensions[$dimensionShortName]->attributes as $att) {
            // dd($model->dimensions[$dimensionShortName]->attributes );
            $this->fields[] = $att->ref;
        }

        /** @var GlobalDimension $actualDimension */
        $actualDimension = $model->dimensions[explode('.', $dimensionShortName)[0]];
        $selectedPatterns = [];
        $patterns = [];
        $parentDrilldownBindings = [];
        $bindings = [];
        $attributes = [];
        $selectedDimensions = [];
        /** @var Dimension $innerDimension */
        foreach ($actualDimension->getInnerDimensions() as $innerDimension) {
            $labelPatterns = $this->globalDimensionToPatterns([$innerDimension], [$innerDimension->label_ref]);
            $keyPatterns = $this->globalDimensionToPatterns([$innerDimension], [$innerDimension->key_ref]);
            if (!isset($selectedDimensions[$actualDimension->getDataSet()]))
                $selectedDimensions[$actualDimension->getDataSet()] = [];

            $selectedDimensions[$actualDimension->getDataSet()] = array_merge_recursive(
                $selectedDimensions[$actualDimension->getDataSet()],
                $labelPatterns,
                $keyPatterns
            );
            $myPatterns = $this->globalDimensionToPatterns([$innerDimension], [$innerDimension->label_ref, $innerDimension->key_ref]);
            $selectedPatterns[$innerDimension->ref] = $myPatterns;

            $bindingName = "binding_" . substr(md5($actualDimension->ref), 0, 5);
            if (!isset($parentDrilldownBindings["?" . $bindingName])) $parentDrilldownBindings["?" . $bindingName] = [];

            $valueAttributeLabel = "uri";
            $attributes[$innerDimension->getDataSet()][$innerDimension->getUri()][$valueAttributeLabel] = $bindingName;

            $bindings[$innerDimension->getDataSet()][$innerDimension->getUri()] = "?$bindingName";

            $sliceSubGraph = $this->initSlice();

            $dataSetSubGraph = $this->initDataSet();

            $needsSliceSubGraph = false;
            $needsDataSetSubGraph = false;
            $attribute = $innerDimension->getUri();
            $attachment = $innerDimension->getAttachment();


            if (isset($attachment) && $attachment == "qb:Slice") {

                $needsSliceSubGraph = true;
                if ($actualDimension->getUri() == $innerDimension->getUri()) {
                    $sliceSubGraph->add(new TriplePattern("?slice", $attribute, $bindings[$innerDimension->getDataSet()][$attribute], false));
                } else {
                    $attName = "att_" . substr(md5($actualDimension->ref), 0, 5);
                    $sliceSubGraph->add(new TriplePattern("?slice", "?$attName", $bindings[$innerDimension->getDataSet()][$attribute], false));
                    $sliceSubGraph->add(new TriplePattern("?$attName", "rdfs:subPropertyOf", "<{$actualDimension->getUri()}>", false));
                    $helperTriple = new TriplePattern("?$attName", "a", "qb:DimensionProperty", false); //'hack' for virtuoso
                    $helperTriple->onlyGlobalTriples = true;
                    $sliceSubGraph->add($helperTriple);
                    $this->subPropertiesAcceleration[$attName]["<{$innerDimension->getUri()}>"]/*[$innerDimension->getDataSet()] */ = [$attName => "<{$innerDimension->getUri()}>", /*"dataSet"=>"<{$innerDimension->getDataSet()}>"*/];
                }

                if ($innerDimension->orig_dimension != $innerDimension->label_attribute) {
                    $childBinding = $bindings[$innerDimension->getDataSet()][$attribute] . "_" . substr(md5($innerDimension->label_attribute), 0, 5);
                    $bindings[$innerDimension->getDataSet()][$childBinding] = $childBinding;
                    $sliceSubGraph->add(new TriplePattern($bindings[$innerDimension->getDataSet()][$attribute], $innerDimension->attributes[$innerDimension->label_attribute]->getUri(), $childBinding, false));
                    $parentDrilldownBindings[$bindings[$innerDimension->getDataSet()][$attribute]][$childBinding] = $childBinding;
                    $attributes[$innerDimension->getDataSet()][$attribute][$innerDimension->attributes[$innerDimension->label_attribute]->getUri()] = ltrim($childBinding, "?");

                }

                if ($innerDimension->orig_dimension != $innerDimension->key_attribute) {
                    $childBinding = $bindings[$innerDimension->getDataSet()][$attribute] . "_" . substr(md5($innerDimension->key_attribute), 0, 5);
                    $sliceSubGraph->add(new TriplePattern($bindings[$innerDimension->getDataSet()][$attribute], $innerDimension->attributes[$innerDimension->key_attribute]->getUri(), $childBinding));
                    $attributes[$innerDimension->getDataSet()][$attribute][$innerDimension->attributes[$innerDimension->key_attribute]->getUri()] = ltrim($childBinding, "?");
                    $bindings[$innerDimension->getDataSet()][$childBinding] = $childBinding;
                    $parentDrilldownBindings[$bindings[$innerDimension->getDataSet()][$attribute]][$childBinding] = $childBinding;


                }

            } elseif (isset($attachment) && $attachment == "qb:DataSet") {
                $needsDataSetSubGraph = true;
                if ($actualDimension->getUri() == $innerDimension->getUri()) {
                    $dataSetSubGraph->add(new TriplePattern("?dataSet", $attribute, $bindings[$innerDimension->getDataSet()][$attribute], false));
                } else {
                    $attName = "att_" . substr(md5($actualDimension->ref), 0, 5);
                    $dataSetSubGraph->add(new TriplePattern("?dataSet", "?$attName", $bindings[$innerDimension->getDataSet()][$attribute], false));

                    $dataSetSubGraph->add(new TriplePattern("?$attName", "rdfs:subPropertyOf", "<{$actualDimension->getUri()}>", false));
                    $helperTriple = new TriplePattern("?$attName", "a", "qb:DimensionProperty", false); //'hack' for virtuoso
                    $helperTriple->onlyGlobalTriples = true;
                    $this->subPropertiesAcceleration[$attName]["<{$innerDimension->getUri()}>"]/*[$innerDimension->getDataSet()]*/ = [$attName => "<{$innerDimension->getUri()}>",/* "dataSet"=>"<{$innerDimension->getDataSet()}>"*/];
                    $dataSetSubGraph->add($helperTriple);

                }
                if ($innerDimension->orig_dimension != $innerDimension->key_attribute) {
                    $childBinding = $bindings[$innerDimension->getDataSet()][$attribute] . "_" . substr(md5($innerDimension->key_attribute), 0, 5);
                    $dataSetSubGraph->add(new TriplePattern($bindings[$innerDimension->getDataSet()][$attribute], $innerDimension->attributes[$innerDimension->key_attribute]->getUri(), $childBinding, false));
                    $parentDrilldownBindings[$bindings[$innerDimension->getDataSet()][$attribute]][$childBinding] = $childBinding;
                    $attributes[$innerDimension->getDataSet()][$attribute][$innerDimension->attributes[$innerDimension->key_attribute]->getUri()] = ltrim($childBinding, "?");

                    $bindings[$innerDimension->getDataSet()][$childBinding] = $childBinding;

                }

                if ($innerDimension->orig_dimension != $innerDimension->label_attribute) {
                    $childBinding = $bindings[$innerDimension->getDataSet()][$attribute] . "_" . substr(md5($innerDimension->label_attribute), 0, 5);
                    $dataSetSubGraph->add(new TriplePattern($bindings[$innerDimension->getDataSet()][$attribute], $innerDimension->attributes[$innerDimension->label_attribute]->getUri(), $childBinding, false));
                    $parentDrilldownBindings[$bindings[$innerDimension->getDataSet()][$attribute]][$childBinding] = $childBinding;
                    $bindings[$innerDimension->getDataSet()][$childBinding] = $childBinding;
                    $attributes[$innerDimension->getDataSet()][$attribute][$innerDimension->attributes[$innerDimension->label_attribute]->getUri()] = ltrim($childBinding, "?");

                }


            } else {

                if ($actualDimension->getUri() == $innerDimension->getUri()) {

                    $patterns[$innerDimension->getDataSet()][$actualDimension->getUri()][] = new SubPattern([new TriplePattern("?observation", $attribute, $bindings[$innerDimension->getDataSet()][$attribute], false)]);
                } else {
                    $attName = "att_" . substr(md5($actualDimension->ref), 0, 5);
                    $patterns[$innerDimension->getDataSet()][$actualDimension->getUri()][] = new SubPattern([new TriplePattern("?observation", "?$attName", $bindings[$innerDimension->getDataSet()][$attribute], false), new TriplePattern("?$attName", "rdfs:subPropertyOf", "<{$actualDimension->getUri()}>", false)]);
                    $helperTriple = new TriplePattern("?$attName", "a", "qb:DimensionProperty", false); //'hack' for virtuoso
                    $helperTriple->onlyGlobalTriples = true;
                    $this->subPropertiesAcceleration[$attName]["<{$innerDimension->getUri()}>"]/*[$innerDimension->getDataSet()]*/ = [$attName => "<{$innerDimension->getUri()}>", /*"dataSet"=>"<{$innerDimension->getDataSet()}>"*/];
                    $patterns[$innerDimension->getDataSet()][$actualDimension->getUri()][] = $helperTriple;

                }

                if ($innerDimension->orig_dimension != $innerDimension->label_attribute) {
                    $childBinding = $bindings[$innerDimension->getDataSet()][$attribute] . "_" . substr(md5($innerDimension->label_attribute), 0, 5);
                    $patterns[$innerDimension->getDataSet()][$actualDimension->getUri()][] = new TriplePattern($bindings[$innerDimension->getDataSet()][$attribute], $innerDimension->attributes[$innerDimension->label_attribute]->getUri(), $childBinding, false);
                    $parentDrilldownBindings[$bindings[$innerDimension->getDataSet()][$attribute]][$childBinding] = $childBinding;
                    $bindings[$innerDimension->getDataSet()][$childBinding] = $childBinding;
                    $attributes[$innerDimension->getDataSet()][$attribute][$innerDimension->attributes[$innerDimension->label_attribute]->getUri()] = ltrim($childBinding, "?");

                }
                if ($innerDimension->orig_dimension != $innerDimension->key_attribute) {
                    $childBinding = $bindings[$innerDimension->getDataSet()][$attribute] . "_" . substr(md5($innerDimension->key_attribute), 0, 5);
                    $patterns[$innerDimension->getDataSet()][$actualDimension->getUri()][] = new TriplePattern($bindings[$innerDimension->getDataSet()][$attribute], $innerDimension->attributes[$innerDimension->key_attribute]->getUri(), $childBinding, false);
                    $parentDrilldownBindings[$bindings[$innerDimension->getDataSet()][$attribute]][$childBinding] = $childBinding;
                    $bindings[$innerDimension->getDataSet()][$childBinding] = $childBinding;
                    $attributes[$innerDimension->getDataSet()][$attribute][$innerDimension->attributes[$innerDimension->key_attribute]->getUri()] = ltrim($childBinding, "?");


                }


            }
            if ($needsSliceSubGraph) {
                $patterns[$innerDimension->getDataSet()][$actualDimension->getUri()][] = $sliceSubGraph;

            }
            if ($needsDataSetSubGraph) {
                $patterns[$innerDimension->getDataSet()][$actualDimension->getUri()][] = $dataSetSubGraph;

            }

            $selectedPatterns = array_merge_recursive($selectedPatterns, $this->modelFieldsToPatterns($model, [$innerDimension->label_ref, $innerDimension->key_ref]));
        }
        $mergedAttributes = [];
        foreach ($attributes as $datasetAttributes) {
            $mergedAttributes = array_merge($mergedAttributes, $datasetAttributes);
        }
        //dd($mergedAttributes);

        /** @var QueryBuilder $subQueryBuilder */
        $queryBuilder = $this->build2($bindings, $patterns, $parentDrilldownBindings);
        $result = $this->sparql->query(
            $queryBuilder->getSPARQL()
        );
        $mergedSelectedDimensions = [];
        foreach ($selectedDimensions as $datasetSelectedDimensions) {
            $mergedSelectedDimensions = array_merge($mergedSelectedDimensions, $datasetSelectedDimensions);
        }
        // dd($result);

//dd($mergedSelectedDimensions);
        $results = $this->rdfResultsToArray3($result, $mergedAttributes, $model, $mergedSelectedDimensions, true);
        //dd($results);
        //return $result;
        // dd($result);
        // dd($selectedPatterns);
        // dd($selectedPatterns);


        $this->data = $results;


        /** @var QueryBuilder $subQueryBuilder */
        $queryBuilderC = $this->buildC2($bindings, $patterns, $parentDrilldownBindings);
       // echo $queryBuilderC->format();    die;
        $resultC = $this->sparql->query(
            $queryBuilderC->getSPARQL()
        );

        $this->total_member_count = $resultC[0]->count->getValue();





        Cache::forever("members/global/$attributeShortName/$page/$page_size", $this->data);
        VolatileCacheManager::addKey("members/global/$attributeShortName/$page/$page_size");

    }

    /**
     * @param Dimension[] $dimensions
     * @param $fields
     * @return array
     */
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


    private function build2(array $drilldownBindings, array $dimensionPatterns, array $parentDrilldownBindings)
    {
        $allSelectedFields = array_unique(array_flatten($drilldownBindings));
        $flatDimensionPatterns = new Collection();
        $outerSelectedFields = [];
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

        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $basicQueryBuilder = $queryBuilder->newSubquery();
       // $basicQueryBuilder->where("?observation", "qb:dataSet", "?dataSet");
        //$basicQueryBuilder->where("?observation", "a", "qb:Observation");

        $tripleAntiRepeatHashes = [];
        $outsiderFilteredLabels = [];
        $langTriplesArray = [];
        //  $basicQueryBuilder->where("?observation", "qb:dataSet", "?dataSet");
//dd($this->subPropertiesAcceleration);
        /** @var Collection $dimensionPatterCollections */
        foreach ($flatDimensionPatterns as $dimension => $dimensionPatternsCollections) {
            if ($dimensionPatternsCollections->count() > 1) {
                $multiPatternGraph = [];
                foreach ($dimensionPatternsCollections as $dimensionPatternsCollection) {
                    $newQuery = $basicQueryBuilder->newSubquery();
                    $selections = ["?observation"];
                    foreach ($dimensionPatternsCollection as $pattern) {
                        if ($pattern instanceof TriplePattern) {
                            if ($pattern->onlyGlobalTriples) continue;
                            if ($pattern->predicate == "skos:prefLabel") {

                                $outsiderFilteredLabels[] = $pattern->object;
                                $langTriplesArray[$pattern->object][$pattern->object] = $pattern;
                                $langTriplesArray[$pattern->object]["{$pattern->object}__filter"] = new FilterPattern("LANG({$pattern->object}) = 'en' || LANG({$pattern->object}) = ''");
                            } else {
                                if (in_array($pattern->object, array_keys($parentDrilldownBindings)) || in_array($pattern->object, $allSelectedFields)) $selections[$pattern->object] = $pattern->object;
                                if (in_array($pattern->object, $allSelectedFields)) $outerSelectedFields[$pattern->object] = $pattern->object;

                                if ($pattern->predicate == "rdfs:subPropertyOf") {
                                    $indx = ltrim($pattern->subject, "?");
                                    $valArray = ["?{$indx}"=> array_map(function($value) use ($indx) {return ($value[$indx]);}, $this->subPropertiesAcceleration[$indx])];
                                    $newQuery->values($valArray);
                                } else $newQuery->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);

                            }

                        } elseif ($pattern instanceof SubPattern) {

                            foreach ($pattern->patterns as $subPattern) {
                                if ($subPattern->onlyGlobalTriples) continue;

                                if ($subPattern->predicate == "skos:prefLabel") {


                                    $outsiderFilteredLabels[] = $subPattern->object;

                                    $langTriplesArray[$subPattern->object][$subPattern->object] = $subPattern;
                                    $langTriplesArray[$subPattern->object]["{$subPattern->object}__filter"] = new FilterPattern("LANG({$subPattern->object}) = 'en' || LANG({$subPattern->object}) = ''");


                                } else {
                                    if (in_array($subPattern->object, array_keys($parentDrilldownBindings)) || in_array($subPattern->object, $allSelectedFields)) $selections[$subPattern->object] = $subPattern->object;
                                    if (in_array($subPattern->object, $allSelectedFields)) $outerSelectedFields[$subPattern->object] = $subPattern->object;

                                    if ($subPattern->predicate == "rdfs:subPropertyOf") {
                                        $indx = ltrim($subPattern->subject, "?");
                                        $valArray = ["?{$indx}"=> array_map(function($value) use ($indx) {return ($value[$indx]);}, $this->subPropertiesAcceleration[$indx])];
                                        $newQuery->values($valArray);
                                    } else $newQuery->where($subPattern->subject, self::expand($subPattern->predicate, $subPattern->transitivity), $subPattern->object);
                                }


                            }

                        }
                    }
                    $newQuery->select($selections);
                    $multiPatternGraph[] = $newQuery;
                }

                $basicQueryBuilder->union(array_map(function (QueryBuilder $subQueryBuilder) use ($basicQueryBuilder) {


                    return $basicQueryBuilder->newSubgraph()->subquery($subQueryBuilder);
                }, $multiPatternGraph));



            } else {
                foreach ($dimensionPatternsCollections as $dimensionPatternsCollection) {
                    foreach ($dimensionPatternsCollection as $pattern) {

                        if ($pattern instanceof TriplePattern) {
                            if (in_array(md5(json_encode($pattern)), $tripleAntiRepeatHashes)) continue;

                            if ($pattern->predicate == "skos:prefLabel") {

                                $outsiderFilteredLabels[] = $pattern->object;
                                $langTriplesArray[$pattern->object][$pattern->object] = $pattern;
                                $langTriplesArray[$pattern->object]["{$pattern->object}__filter"] = new FilterPattern("LANG({$pattern->object}) = 'en' || LANG({$pattern->object}) = ''");

                            } else {
                                if (in_array($pattern->object, $allSelectedFields)) $outerSelectedFields[$pattern->object] = $pattern->object;

                                if ($pattern->predicate == "rdfs:subPropertyOf") {
                                    $indx = ltrim($pattern->subject, "?");
                                    $valArray = ["?{$indx}"=> array_map(function($value) use ($indx) {return ($value[$indx]);}, $this->subPropertiesAcceleration[$indx])];
                                    $basicQueryBuilder->values($valArray);
                                } else $basicQueryBuilder->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);
                            }


                            $tripleAntiRepeatHashes[] = md5(json_encode($pattern));

                        } elseif ($pattern instanceof SubPattern) {

                            foreach ($pattern->patterns as $subPattern) {

                               // if ($subPattern->onlySubGraphTriples) continue;

                                if ($subPattern->predicate == "skos:prefLabel") {
                                    $outsiderFilteredLabels[] = $subPattern->object;
                                    $langTriplesArray[$subPattern->object][$subPattern->object] = $subPattern;
                                    $langTriplesArray[$subPattern->object]["{$subPattern->object}__filter"] = new FilterPattern("LANG({$subPattern->object}) = 'en' || LANG({$subPattern->object}) = ''");

                                } else {
                                    if (in_array(md5(json_encode($subPattern)), $tripleAntiRepeatHashes)) continue;
                                    if (in_array($subPattern->object, $allSelectedFields)) $outerSelectedFields[$subPattern->object] = $subPattern->object;

                                    if ($subPattern->predicate == "rdfs:subPropertyOf") {
                                        $indx = ltrim($subPattern->subject, "?");
                                        $valArray = ["?{$indx}"=> array_map(function($value) use ($indx) {return ($value[$indx]);}, $this->subPropertiesAcceleration[$indx])];
                                        $basicQueryBuilder->values($valArray);
                                    } else $basicQueryBuilder->where($subPattern->subject, self::expand($subPattern->predicate, $subPattern->transitivity), $subPattern->object);
                                }
                                $tripleAntiRepeatHashes[] = md5(json_encode($subPattern));

                            }

                        }

                    }
                }
            }
        }


        $drldnBindings = [];

        foreach ($drilldownBindings as $dataset => $bindings) {
            foreach ($bindings as $binding) {
                $drldnBindings [] = "{$binding}";

            }
        }

        foreach ($langTriplesArray as $group) {
            $optional = $queryBuilder->newSubgraph();

            foreach ($group as $triple) {
                if ($triple instanceof FilterPattern) {
                    $optional->filter($triple->expression);

                } else {
                    $optional->where($triple->subject, self::expand($triple->predicate, $triple->transitivity), $triple->object);
                }
            }

            $queryBuilder->optional($optional);

        }

        $selections = array_unique(array_keys($parentDrilldownBindings)+$outerSelectedFields);
        $basicQueryBuilder->select($selections);
        $basicQueryBuilder->groupBy($selections);
        $queryBuilder->groupBy(array_keys($parentDrilldownBindings));
        $basicQueryBuilder->orderBy("COUNT(?observation)");
        $queryBuilder->subquery($basicQueryBuilder);
        $queryBuilder->select(array_unique(array_merge($selections,  array_flatten($parentDrilldownBindings))));
       // echo $queryBuilder->format();die;
        //$queryBuilder->limit(10);
        return $queryBuilder;


    }

    private function buildC2(array $drilldownBindings, array $dimensionPatterns, array $parentDrilldownBindings)
    {
        $allSelectedFields = array_unique(array_flatten($drilldownBindings));
        $flatDimensionPatterns = new Collection();
        $outerSelectedFields = [];

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

        $queryBuilder = new QueryBuilder(config("sparql.prefixes"));
        $basicQueryBuilder = $queryBuilder->newSubquery();
        //$basicQueryBuilder->where("?observation", "qb:dataSet", "?dataSet");
       // $basicQueryBuilder->where("?observation", "a", "qb:Observation");

        $tripleAntiRepeatHashes = [];
        $outsiderFilteredLabels = [];
        $langTriplesArray = [];
        //  $basicQueryBuilder->where("?observation", "qb:dataSet", "?dataSet");
//dd($this->subPropertiesAcceleration);
        /** @var Collection $dimensionPatterCollections */
        foreach ($flatDimensionPatterns as $dimension => $dimensionPatternsCollections) {
            if ($dimensionPatternsCollections->count() > 1) {
                $multiPatternGraph = [];
                foreach ($dimensionPatternsCollections as $dimensionPatternsCollection) {
                    $newQuery = $basicQueryBuilder->newSubquery();
                    $selections = ["?observation"];
                    foreach ($dimensionPatternsCollection as $pattern) {
                        if ($pattern instanceof TriplePattern) {
                            if ($pattern->onlyGlobalTriples) continue;
                            if ($pattern->predicate == "skos:prefLabel") {

                                $outsiderFilteredLabels[] = $pattern->object;
                                $langTriplesArray[$pattern->object][$pattern->object] = $pattern;
                                $langTriplesArray[$pattern->object]["{$pattern->object}__filter"] = new FilterPattern("LANG({$pattern->object}) = 'en' || LANG({$pattern->object}) = ''");
                            } else {
                                if (in_array($pattern->object, array_keys($parentDrilldownBindings)) || in_array($pattern->object, $allSelectedFields)) $selections[$pattern->object] = $pattern->object;
                                if (in_array($pattern->object, $allSelectedFields)) $outerSelectedFields[$pattern->object] = $pattern->object;

                                if ($pattern->predicate == "rdfs:subPropertyOf") {
                                    $indx = ltrim($pattern->subject, "?");
                                    $valArray = ["?{$indx}"=> array_map(function($value) use ($indx) {return ($value[$indx]);}, $this->subPropertiesAcceleration[$indx])];
                                    $newQuery->values($valArray);
                                } else $newQuery->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);

                            }

                        } elseif ($pattern instanceof SubPattern) {

                            foreach ($pattern->patterns as $subPattern) {
                                if ($subPattern->onlyGlobalTriples) continue;

                                if ($subPattern->predicate == "skos:prefLabel") {


                                    $outsiderFilteredLabels[] = $subPattern->object;

                                    $langTriplesArray[$subPattern->object][$subPattern->object] = $subPattern;
                                    $langTriplesArray[$subPattern->object]["{$subPattern->object}__filter"] = new FilterPattern("LANG({$subPattern->object}) = 'en' || LANG({$subPattern->object}) = ''");


                                } else {
                                    if (in_array($subPattern->object, array_keys($parentDrilldownBindings)) || in_array($subPattern->object, $allSelectedFields)) $selections[$subPattern->object] = $subPattern->object;
                                    if (in_array($subPattern->object, $allSelectedFields)) $outerSelectedFields[$subPattern->object] = $subPattern->object;

                                    if ($subPattern->predicate == "rdfs:subPropertyOf") {
                                        $indx = ltrim($subPattern->subject, "?");
                                        $valArray = ["?{$indx}"=> array_map(function($value) use ($indx) {return ($value[$indx]);}, $this->subPropertiesAcceleration[$indx])];
                                        $newQuery->values($valArray);
                                    } else $newQuery->where($subPattern->subject, self::expand($subPattern->predicate, $subPattern->transitivity), $subPattern->object);
                                }


                            }

                        }
                    }
                    $newQuery->select($selections);
                    $multiPatternGraph[] = $newQuery;
                }

                $basicQueryBuilder->union(array_map(function (QueryBuilder $subQueryBuilder) use ($basicQueryBuilder) {


                    return $basicQueryBuilder->newSubgraph()->subquery($subQueryBuilder);
                }, $multiPatternGraph));



            } else {
                foreach ($dimensionPatternsCollections as $dimensionPatternsCollection) {
                    foreach ($dimensionPatternsCollection as $pattern) {
                        if ($pattern instanceof TriplePattern) {
                            if (in_array(md5(json_encode($pattern)), $tripleAntiRepeatHashes)) continue;

                            if ($pattern->predicate == "skos:prefLabel") {

                                $outsiderFilteredLabels[] = $pattern->object;
                                $langTriplesArray[$pattern->object][$pattern->object] = $pattern;
                                $langTriplesArray[$pattern->object]["{$pattern->object}__filter"] = new FilterPattern("LANG({$pattern->object}) = 'en' || LANG({$pattern->object}) = ''");

                            } else {
                                if (in_array($pattern->object, $allSelectedFields)) $outerSelectedFields[$pattern->object] = $pattern->object;

                                if ($pattern->predicate == "rdfs:subPropertyOf") {
                                    $indx = ltrim($pattern->subject, "?");
                                    $valArray = ["?{$indx}"=> array_map(function($value) use ($indx) {return ($value[$indx]);}, $this->subPropertiesAcceleration[$indx])];
                                    $basicQueryBuilder->values($valArray);
                                } else $basicQueryBuilder->where($pattern->subject, self::expand($pattern->predicate, $pattern->transitivity), $pattern->object);
                            }


                            $tripleAntiRepeatHashes[] = md5(json_encode($pattern));

                        } elseif ($pattern instanceof SubPattern) {

                            foreach ($pattern->patterns as $subPattern) {
                                if ($subPattern->onlySubGraphTriples) continue;

                                if ($subPattern->predicate == "skos:prefLabel") {
                                    $outsiderFilteredLabels[] = $subPattern->object;
                                    $langTriplesArray[$subPattern->object][$subPattern->object] = $subPattern;
                                    $langTriplesArray[$subPattern->object]["{$subPattern->object}__filter"] = new FilterPattern("LANG({$subPattern->object}) = 'en' || LANG({$subPattern->object}) = ''");

                                } else {
                                    if (in_array(md5(json_encode($subPattern)), $tripleAntiRepeatHashes)) continue;
                                    if (in_array($subPattern->object, $allSelectedFields)) $outerSelectedFields[$subPattern->object] = $subPattern->object;

                                    if ($subPattern->predicate == "rdfs:subPropertyOf") {
                                        $indx = ltrim($subPattern->subject, "?");
                                        $valArray = ["?{$indx}"=> array_map(function($value) use ($indx) {return ($value[$indx]);}, $this->subPropertiesAcceleration[$indx])];
                                        $basicQueryBuilder->values($valArray);
                                    } else $basicQueryBuilder->where($subPattern->subject, self::expand($subPattern->predicate, $subPattern->transitivity), $subPattern->object);
                                }
                                $tripleAntiRepeatHashes[] = md5(json_encode($subPattern));

                            }

                        }

                    }
                }
            }
        }


        $drldnBindings = [];

        foreach ($drilldownBindings as $dataset => $bindings) {
            foreach ($bindings as $binding) {
                $drldnBindings [] = "{$binding}";

            }
        }

        foreach ($langTriplesArray as $group) {
            $optional = $queryBuilder->newSubgraph();

            foreach ($group as $triple) {
                if ($triple instanceof FilterPattern) {
                    $optional->filter($triple->expression);

                } else {
                    $optional->where($triple->subject, self::expand($triple->predicate, $triple->transitivity), $triple->object);
                }
            }

            $queryBuilder->optional($optional);

        }

        $selections = array_unique(array_keys($parentDrilldownBindings));
        $basicQueryBuilder->select(array_keys($parentDrilldownBindings));
        $basicQueryBuilder->groupBy($selections);
        $queryBuilder->subquery($basicQueryBuilder);
        $queryBuilder->select("(COUNT(*) AS ?count)");
        //$queryBuilder->limit(10);
        return $queryBuilder;


    }

    private function initSlice()
    {
        $observationTypePattern = new TriplePattern("?observation", "a", "qb:Observation");
        $observationTypePattern->onlySubGraphTriples = true;

        $observationTypePattern = new TriplePattern("?observation", "qb:dataSet", "?dataSet");
        $observationTypePattern->onlySubGraphTriples = true;

        $sliceTypePattern = new TriplePattern("?slice", "a", "qb:Slice");
        $sliceTypePattern->onlySubGraphTriples = true;

        $attachmentTriple = new TriplePattern("?slice", "qb:observation", "?observation");
        $attachmentTriple->onlySubGraphTriples = true;

        return new SubPattern([
            //$observationTypePattern,
            $sliceTypePattern,
            $attachmentTriple,

        ], false);
    }

    private function initDataSet()
    {
        $observationTypePattern = new TriplePattern("?observation", "a", "qb:Observation");
        $observationTypePattern->onlySubGraphTriples = true;

        $dataSetTypePattern = new TriplePattern("?dataSet", "a", "qb:DataSet");
        $dataSetTypePattern->onlySubGraphTriples = true;

        $attachmentTriple = new TriplePattern("?observation", "qb:dataSet", "?dataSet");
        $attachmentTriple->onlySubGraphTriples = true;


        return new SubPattern([
            $observationTypePattern,
            $dataSetTypePattern,
            $attachmentTriple,
        ], false);
    }


}