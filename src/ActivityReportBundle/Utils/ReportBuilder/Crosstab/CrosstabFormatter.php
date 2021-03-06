<?php

namespace ActivityReportBundle\Utils\ReportBuilder\Crosstab;

/**
 * Class PivotFormatter.
 */
class CrosstabFormatter
{
    /** @var mixed */
    protected $terms;

    /**
     * @param mixed $terms
     */
    public function setTerms($terms)
    {
        $this->terms = $terms;
    }

    /**
     * @param array $rows
     * @param array $cols
     * @param string $allValuesMethod
     *
     * @return array
     */
    public function getInversedRows($rows, &$cols, $allValuesMethod)
    {
        $cols = array();
        $new = array();
        foreach ($rows as $row) {
            if (isset($row['data'])) {
                $subrows = $row['data'];
                unset($row['data']);
                if (!isset($cols[$row['key']])) {
                    $cols[$row['key']] = $row;
                }
                foreach ($subrows as $subrow) {
                    $n = &$new[$subrow['key']];
                    if (!isset($n)) {
                        $n = $subrow;
                    }
                    $row['value'] = $subrow['value'];
                    $n['data'][] = $row;
                }
            }
        }

        // recalculate
        foreach ($new as &$item) {
            $nbrValues = 0;
            $item['value'] = array_sum(array_map(function ($i) use ($nbrValues) {
                ++$nbrValues;
                return $i['value'];
            }, $item['data']));
            if ($allValuesMethod === 'AVERAGE' && $nbrValues > 0) {
                $item['value'] /= $nbrValues;
            }
        }

        $cols = array_values($cols);

        return array_values($new);
    }

    /**
     * @param $data
     * @param $inverse
     * @param $allValuesMethod
     *
     * @return array
     */
    public function format($data, $inverse = false, $allValuesMethod = 'SUM')
    {
        $rows = $this->getProcessedRows($data, $cols, $inverse, $allValuesMethod);

        $value = 0;
        $nbrValues = 0;
        foreach ($cols as $col) {
            $value += $col['value'];
            ++$nbrValues;
        }
        if ($allValuesMethod === 'AVERAGE' && $nbrValues > 0) {
            $value /= $nbrValues;
        }

        return array(
            'cols' => array_values($cols),
            'rows' => $rows,
            'value' => $value,
        );
    }

    /**
     * Get the data mapping.
     *
     * @param $rows
     * @param $agg
     *
     * @return array
     */
    protected function getMapping($rows, $agg)
    {
        $mapping = array();
        $aggs = array();

        $map = function ($rows, $agg = null, $depth = 0) use (&$map, &$mapping, &$aggs) {
            $aggs[$depth] = $agg;
            foreach ($rows as $item) {
                $key = $item['key'];
                $mapping[$depth][$key] = array(
                    'key' => $key,
                    'value' => 0,
                    'label' => $key,
                );
                if (isset($this->terms[$agg][$key])) {
                    $mapping[$depth][$key]['label'] = $this->terms[$agg][$key];
                }
                if (isset($item['data'])) {
                    $map($item['data'], $item['agg'], $depth + 1);
                }
            }
        };
        $map($rows, $agg);

        // remove keys
        $mapping = array_map(function ($map) {
            return array_values($map);
        }, $mapping);

        foreach ($mapping as $depth => &$map) {
            // reorder mapping by terms
            if (!empty($this->terms[$aggs[$depth]])) {
                $map = $this->reorderMapByTerms($map, $this->terms[$aggs[$depth]]);
            }
            if (!isset($this->terms[$aggs[$depth]])) {
                // order by label if the term is not specified
                usort($map, function ($a, $b) {
                    if (isset($a['label']) && isset($b['label'])) {
                        if ($a['label'] === 'Autre') {
                            return 1;
                        }
                        if ($b['label'] === 'Autre') {
                            return 0;
                        }

                        return $a['label'] > $b['label'];
                    }

                    return 0;
                });
            }

            // put 'Autre' always at the end
            $index = $this->getIndexByKey($map, 'Autre');
            if ($index !== false) {
                $item = $map[$index];
                unset($map[$index]);
                array_push($map, $item);
            }
        }

        return $mapping;
    }

    /**
     * Reorder mapping by term list.
     *
     * @param $mapping
     * @param $terms
     *
     * @return array
     */
    protected function reorderMapByTerms($mapping, $terms)
    {
        $keys = array();
        foreach ($terms as $key => $term) {
            $keys[] = is_string($key) ? $key : $term;
        }
        $ordered = array();
        foreach ($keys as $key) {
            $done = false;
            foreach ($mapping as $k => $item) {
                if ($item['key'] === $key) {
                    if (isset($terms[$key])) {
                        $item['label'] = $terms[$key];
                    }
                    $ordered[] = $item;
                    unset($mapping[$k]);
                    $done = true;
                }
            }
            if (!$done) {
                $ordered[] = array(
                    'key' => $key,
                    'label' => isset($terms[$key]) ? $terms[$key] : $key,
                    'value' => 0,
                );
            }
        }

        // add residual columns from the mapping
        if (count($mapping)) {
            $ordered = array_merge($ordered, $mapping);
        }

        return $ordered;
    }

    /**
     * Process the rows, populate the final cols.
     *
     * @param $data
     * @param $cols
     * @param bool $inverse
     * @param string $allValuesMethod
     *
     * @return array
     */
    protected function getProcessedRows($data, &$cols, $inverse = false, $allValuesMethod)
    {
        $parent = null;
        $rows = $this->getRows($data, $parent, $allValuesMethod, null);
        $agg = $parent['agg'];

        $cols = array();
        $mapping = $this->getMapping($rows, $agg);

        if ($mapping) {
            // process mapping
            $process = function ($rows, $depth = 0) use (&$mapping, &$process) {
                $last = !isset($mapping[$depth + 1]);
                $rows = $this->reorderValuesByKeys($rows, $mapping[$depth], true);
                foreach ($rows as &$row) {
                    if (!$last) {
                        if (!isset($row['data'])) {
                            $row['data'] = array();
                        }
                        $row['data'] = $process($row['data'], $depth + 1);
                    }
                }

                return $rows;
            };
            $rows = $process($rows);
            $cols = end($mapping);
            if ($inverse) {
                $rows = $this->getInversedRows($rows, $cols, $allValuesMethod);
            }
        }

        // return
        return $rows;
    }

    /**
     * @param $data
     * @param null $parent
     * @param $allValuesMethod
     * @param int $depth
     * @param null $agg_name
     *
     * @return array
     */
    protected function getRows($data, &$parent = null, $allValuesMethod, $depth = -1, $agg_name = null)
    {
        $rows = array();

        foreach ($data as $key => $value) {
            if ($key === 'buckets') {
                $parent['agg'] = $agg_name;
                ++$depth;
                foreach ($value as $key => $bucket) {
                    $label = isset($bucket['key']) ? $bucket['key'] : $key;
                    $row = array(
                        'key' => $label,
                        'value' => $bucket['doc_count'],
                    );
                    $subRows = $this->getRows($bucket, $row, $allValuesMethod, $depth, $agg_name);
                    if ($subRows) {
                        // if there is subrows, get it and calculate sum
                        $row['data'] = $subRows;
                        $nbrValues = 0;
                        $total = array_sum(array_map(function ($row) use ($nbrValues) {
                            ++$nbrValues;
                            return $row['value'];
                        }, $subRows));
                        if ($allValuesMethod === 'AVERAGE' && $nbrValues > 0) {
                            $total /= $nbrValues;
                        }
                        // if total < parent doc_count, add difference to a 'Autre' facet
                        if ($row['value'] > $total) {
                            $index = $this->getIndexByKey($row['data'], 'Autre');
                            if ($index !== false) {
                                $row['data'][$index]['value'] += $row['value'] - $total;
                            } else {
                                $row['data'][] = array(
                                    'key' => 'Autre',
                                    'value' => $row['value'] - $total,
                                );
                            }
                        }
                    }
                    $rows[] = $row;
                }
            } elseif (is_array($value)) {
                $_rows = $this->getRows($value, $parent, $allValuesMethod, $depth, $key);
                if (count($_rows)) {
                    // if any rows returned, replace the current one
                    $rows = $_rows;
                }
            } elseif ($parent && in_array($key, array('value', 'doc_count'), true)) {
                $parent['value'] = $value;
            }
        }

        return $rows;
    }

    /**
     * Sort col data by the global cols
     * + fill the value total of the cols.
     *
     * @param $array
     * @param $mapping
     * @param $add
     *
     * @return array
     */
    protected function reorderValuesByKeys($array, &$mapping, $add = false)
    {
        $ordered = array();
        foreach ($mapping as &$col) {
            $done = false;
            foreach ($array as $key => $value) {
                if ($value['key'] === $col['key']) {
                    $value['label'] = isset($col['label']) ? $col['label'] : $col['key'];
                    $ordered[] = $value;
                    $col['value'] += $value['value'];
                    unset($array[$key]);
                    $done = true;
                    break;
                }
            }
            // if the col doesn't exist, add it to 0
            if (!$done && $add) {
                $ordered[] = array(
                    'key' => $col['key'],
                    'label' => isset($col['label']) ? $col['label'] : $col['key'],
                    'value' => 0,
                );
            }
        }

        return $ordered;
    }

    /**
     * @param $array
     * @param $value
     * @param string $key
     *
     * @return bool|int|string
     */
    protected function getIndexByKey($array, $value, $key = 'key')
    {
        foreach ($array as $index => $item) {
            if ($item[$key] === $value) {
                return $index;
            }
        }

        return false;
    }
}
