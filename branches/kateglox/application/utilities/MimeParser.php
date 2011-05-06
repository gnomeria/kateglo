<?php
namespace kateglo\application\utilities;
/**
 * MimeParser class. This class provides basic functions for handling mime-types. It can
 * handle matching mime-types against a list of media-ranges. See section
 * 14.1 of the HTTP specification [RFC 2616] for a complete explanation.
 *
 * It's just a port to php from original
 * Python code (http://code.google.com/p/mimeparse/)
 * @author Joe Gregario, Andrew "Venom" K.
 *
 * ported from version 0.1.2
 *
 * Comments are mostly excerpted from the original.
 */
class MimeParser implements interfaces\MimeParser {

    public static $CLASS_NAME = __CLASS__;

    /**
     * Carves up a mime-type and returns an Array of the [type, subtype, params]
     * where "params" is a Hash of all the parameters for the media range.
     *
     * For example, the media range "application/xhtml;q=0.5" would
     * get parsed into:
     *
     * array("application", "xhtml", array( "q" => "0.5" ))
     *
     * @param string $mimeType
     * @return array ($type, $subtype, $params)
     */
    public function parseMimeType($mimeType) {
        $parts = explode(";", $mimeType);

        $params = array();
        foreach ($parts as $i => $param) {
            if (strpos($param, '=') !== false) {
                list ($k, $v) = explode('=', trim($param));
                $params[$k] = $v;
            }
        }

        $fullType = trim($parts[0]);
        /* Java URLConnection class sends an Accept header that includes a single "*" Turn it into a legal wildcard. */
        if ($fullType == '*') {
            $fullType = '*/*';
        }
        list ($type, $subtype) = explode('/', $fullType);
        if (!$subtype) throw (new Exception("malformed mime type"));

        return array(trim($type), trim($subtype), $params);
    }


    /**
     * Carves up a media range and returns an Array of the
     * [type, subtype, params] where "params" is a Hash of all
     * the parameters for the media range.
     *
     * For example, the media range "application/*;q=0.5" would
     * get parsed into:
     *
     * array("application", "*", ( "q", "0.5" ))
     *
     * In addition this function also guarantees that there
     * is a value for "q" in the params dictionary, filling it
     * in with a proper default if necessary.
     *
     * @param string $range
     * @return array ($type, $subtype, $params)
     */
    public function parseMediaRange($range) {
        list ($type, $subtype, $params) = $this->parseMimeType($range);

        if (!(isset($params['q']) && $params['q'] && floatval($params['q']) &&
              floatval($params['q']) <= 1 && floatval($params['q']) >= 0))
            $params['q'] = '1';

        return array($type, $subtype, $params);
    }

    /**
     * Find the best match for a given mime-type against a list of
     * mediaRanges that have already been parsed by MimeParser::parseMediaRange()
     *
     * Returns the fitness and the "q" quality parameter of the best match, or an
     * array [-1, 0] if no match was found. Just as for MimeParser::quality(),
     * "parsedRanges" must be an Enumerable of parsed media ranges.
     *
     * @param string $mimeType
     * @param array  $parsedRanges
     * @return array ($bestFitness, $bestFitQ)
     */
    public function fitnessAndQualityParsed($mimeType, $parsedRanges) {
        $bestFitness = -1;
        $bestFitQ = 0;
        list ($targetType, $targetSubtype, $targetParams) = $this->parseMediaRange($mimeType);

        foreach ($parsedRanges as $item) {
            list ($type, $subtype, $params) = $item;

            if (($type == $targetType or $type == "*" or $targetType == "*") &&
                ($subtype == $targetSubtype or $subtype == "*" or $targetSubtype == "*")) {

                $paramMatches = 0;
                foreach ($targetParams as $k => $v) {
                    if ($k != 'q' && isset($params[$k]) && $v == $params[$k])
                        $paramMatches++;
                }

                $fitness = ($type == $targetType) ? 100 : 0;
                $fitness += ($subtype == $targetSubtype) ? 10 : 0;
                $fitness += $paramMatches;

                if ($fitness > $bestFitness) {
                    $bestFitness = $fitness;
                    $bestFitQ = $params['q'];
                }
            }
        }

        return array($bestFitness, (float)$bestFitQ);
    }

    /**
     * Find the best match for a given mime-type against a list of
     * mediaRanges that have already been parsed by MimeParser::parseMediaRange()
     *
     * Returns the "q" quality parameter of the best match, 0 if no match
     * was found. This function behaves the same as MimeParser::quality() except that
     * "parsedRanges" must be an Enumerable of parsed media ranges.
     *
     * @param string $mimeType
     * @param array  $parsedRanges
     * @return float $q
     */
    public function qualityParsed($mimeType, $parsedRanges) {
        list ($fitness, $q) = $this->fitnessAndQualityParsed($mimeType, $parsedRanges);
        return $q;
    }

    /**
     * Returns the quality "q" of a mime-type when compared against
     * the media-ranges in ranges. For example:
     *
     * MimeParser::quality("text/html", "text/*;q=0.3, text/html;q=0.7,
     * text/html;level=1, text/html;level=2;q=0.4, *\/*;q=0.5")
     * => 0.7
     *
     * @param string $mimeType
     * @param string $ranges
     * @return string
     */
    public function quality($mimeType, $ranges) {
        $parsedRanges = explode(',', $ranges);

        foreach ($parsedRanges as $i => $r)
            $parsedRanges[$i] = $this->parseMediaRange($r);

        return $this->qualityParsed($mimeType, $parsedRanges);
    }

    /**
     * Takes a list of supported mime-types and finds the best match
     * for all the media-ranges listed in header. The value of header
     * must be a string that conforms to the format of the HTTP Accept:
     * header. The value of supported is an Enumerable of mime-types
     *
     * MimeParser::bestMatch(array("application/xbel+xml", "text/xml"), "text/*;q=0.5,*\/*; q=0.1")
     * => "text/xml"
     *
     * @param  array  $supported
     * @param  string $header
     * @return mixed  $mimeType or NULL
     */
    public function bestMatch($supported, $header) {
        $parsedHeader = explode(',', $header);

        foreach ($parsedHeader as $i => $r)
            $parsedHeader[$i] = $this->parseMediaRange($r);

        $weightedMatches = array();
        foreach ($supported as $mimeType) {
            $weightedMatches[] = array(
                $this->fitnessAndQualityParsed($mimeType, $parsedHeader),
                $mimeType
            );
        }

        array_multisort($weightedMatches);

        $a = $weightedMatches[count($weightedMatches) - 1];
        return (empty($a[0][1]) ? null : $a[1]);
    }

}

?>