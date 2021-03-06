<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4; */

/**
 * PHP version 5
 *
 * @package     omeka
 * @subpackage  nlfeatures
 * @author      Scholars' Lab <>
 * @author      Eric Rochester <erochest@virginia.edu>
 * @copyright   2011 The Board and Visitors of the University of Virginia
 * @license     http://www.apache.org/licenses/LICENSE-2.0.html Apache 2 License
 */
?><?php

require_once NEATLINE_FEATURES_PLUGIN_DIR . '/models/NeatlineFeature.php';

/**
 * This is a model class for the neatline_features table.
 **/
class NeatlineFeatureTable extends Omeka_Db_Table
{

    /**
     * This looks for a record from the data table and returns it, or this 
     * creates it if it doesn't exist.
     *
     * @param $item         Omeka_Record The Omeka item associated with this 
     * feature.
     * @param $element_text ElementText The Omeka element text that this is 
     * associated with.
     *
     * @return NeatlineFeature
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function createOrGetRecord($item, $element_text)
    {
        $record = $this->getRecordByElementText($element_text);

        if (is_null($record)) {
            return new NeatlineFeature($item, $element_text);
        }

        return $record;
    }

    /**
     * This looks in the database for a neatline features row for an element 
     * text.
     *
     * @param $element_text ElementText The Omeka element text that this is 
     * associated with. If not given, it just takes the first element text for 
     * the Coverage field.
     *
     * @return NeatlineFeature|null
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function getRecordByElementText($element_text)
    {
        $db     = $this->getDb();
        $select = $db
            ->select()
            ->from(array( 'nf' => $this->getTableName() ))
            ->where('nf.item_id=?', $element_text->record_id);

        if (isset($element_text->id) && !is_null($element_text->id)) {
            $select = $select
                ->where('nf.element_text_id=?', $element_text->id);

        } else if (is_null($element_text->record_id)) {
            $select = NULL;

        } else {
            $etTable = $db->getTable('ElementText');

            $lines   = explode(
                "\n",
                str_ireplace("\r", "", str_ireplace('<br />', "\n", $element_text->text)),
                3
            );
            $wktParts = explode('/', $lines[0], 2);
            $qwkt     = $db->quote($wktParts[0]);
            $parts    = explode('|', $lines[0], 5);
            $geo      = htmlspecialchars_decode($parts[0]);
            $qgeo     = $db->quote($geo);
            $raw      = count($lines) >= 3 ? $lines[2] : '';
            $text     = $this->_findLongestNonHtml($raw);
            if ($raw === $text) {
                $text     = "%$text";
            } else {
                $text     = "%$text%";
            }

            $select = $select
                ->join(
                    array( 'et' => $etTable->getTableName() ),
                    'nf.element_text_id=et.id',
                    array()
                )
                ->where("nf.geo=$qgeo OR nf.geo=$qwkt")
                ->where('et.record_id=?',      $element_text->record_id)
                ->where('et.record_type_id=?', $element_text->record_type_id)
                ->where('et.element_id=?',     $element_text->element_id)
                ->where('et.html=?',           $element_text->html);
            if (!empty($text)) {
                $select = $select->where("et.text LIKE ?", $text);
            }
        }

        return (is_null($select) ? NULL : $this->fetchObject($select));
    }

    /**
     * This splits the input on anything that looks HTML-ish and returns the 
     * longest part, decorated with SQL wildcards (%).
     *
     * This is to handle the ElementText data. The text is sometimes HTML 
     * escaped, so we'll try to match on a subset of the text string. We'll get 
     * the longest subset that's not HTML.
     *
     * @param $text string The input string to split.
     *
     * @return string
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    private function _findLongestNonHtml($text)
    {
        $output = $text;

        if (strlen($text) > 0) {
            $parts = preg_split('/<[^>]+>|&[\S;]+;/', $text);
            $plen  = count($parts);
            $maxi  = -1;
            $maxl  = -1;
            $maxp  = '';

            // If there's only one part, there's no HTML. We can just match.
            if ($plen > 1) {
                for ($i = 0; $i<$plen; $i++) {
                    $part = $parts[$i];
                    if (strlen($part) > $maxl) {
                        $maxp = $part;
                        $maxl = strlen($part);
                        $maxi = $i;
                    }
                }

                $output = trim($maxp);
            }
        }

        return $output;
    }

    /**
     * This returns the features associated with an item.
     *
     * @param $item Omeka_Record The Omeka item associated with the features.
     *
     * @return array of NeatlineFeature
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function getItemFeatures($item)
    {
        return $this->fetchObjects(
            $this->getSelect()->where('item_id=?', $item->id)
        );
    }

    /**
     * This clears out all records for the given item.
     *
     * @param $item Omeka_Record The item to delete features from.
     *
     * @return void
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function removeItemFeatures($item)
    {
        if (!is_null($item->id)) {
            $where = $this->getAdapter()->quoteInto('item_id=?', $item->id);
            $this->delete($this->getTableName(), $where);
        }
    }

    /**
     * This populates features for an item from an associative array, such as 
     * might be found in $_POST.
     *
     * @param $item   Omeka_Record The item to populate items for.
     * @param $params array        The array to pull data from. This will 
     * ususally be $_POST['Elements'][$id].
     *
     * @return array of NeatlineFeature The features created.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function createFeatures($item, $params)
    {
        $name     = $this->getTableName();
        $item_id  = $item->id;
        $db       = $this->getDb();
        $coverage = $db
            ->getTable('Element')
            ->findByElementSetNameAndElementName('Dublin Core', 'Coverage');
        $cid      = $coverage->id;

        $sql     = $db->prepare(
            "INSERT INTO $name
                (added, item_id, element_text_id, is_map, geo, zoom,
                 center_lon, center_lat, base_layer)
                SELECT NOW(), ?, et.id, ?, ?, ?, ?, ?, ?
                FROM {$db->prefix}element_texts et
                WHERE et.record_id=? AND et.text=? AND et.element_id=?;
            "
        );

        foreach ($params as $field) {
            $isMap      = (bool)$this->_param($field, 'mapon', 0);
            $geo        = $this->_param($field, 'geo', '');
            $zoom       = $this->_param($field, 'zoom', 3);
            $center_lon = $this->_param($field, 'center_lon', 0.0);
            $center_lat = $this->_param($field, 'center_lat', 0.0);
            $base_layer = $this->_param($field, 'base_layer', 'osm');

            $data = array(
                $item_id, (int)$isMap,
                $geo, $zoom, $center_lon, $center_lat, $base_layer,
                $item_id, $field['text'], $cid
            );
            $sql->execute($data);
        }

        return $this->getItemFeatures($item);
    }

    /**
     * This attempts to get a field from an array of params or it returns the 
     * default.
     *
     * @param $params array This is an of parameters to read the input from.
     * @param $key string This is the key to read.
     * @param $default any This is the value to return if the $key isn't 
     * defined in the $params.
     *
     * @return string|any The value of the key or the default.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    private function _param($params, $key, $default=null)
    {
        $value = $default;
        try {
            if (array_key_exists($key, $params)) {
                $value = $params[$key];
            }
        } catch (Exception $e) {
        }
        return $value;
    }

    /**
     * This removes the current features for an item and re-creates them from the parameters.
     *
     * @param $item   Omeka_Record The item to populate items for.
     * @param $params array        The array to pull data from. This will 
     * ususally be $_POST['Elements'][$id].
     *
     * @return array of NeatlineFeature The features created.
     * @author Eric Rochester <erochest@virginia.edu>
     **/
    public function updateFeatures($item, $params)
    {
        $this->removeItemFeatures($item);
        return $this->createFeatures($item, $params);
    }

}

