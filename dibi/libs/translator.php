<?php

/**
 * This file is part of the "dibi" project (http://dibi.texy.info/)
 *
 * Copyright (c) 2005-2007 David Grudl aka -dgx- <dave@dgx.cz>
 *
 * @version  $Revision$ $Date$
 * @package  dibi
 */


// security - include dibi.php, not this file
if (!class_exists('dibi', FALSE)) die();



/**
 * dibi translator
 *
 */
class DibiTranslator
{
    private
        $driver,
        $modifier,
        $hasError,
        $comment,
        $ifLevel,
        $ifLevelStart;



    public function __construct($driver)
    {
        $this->driver = $driver;
    }



    /**
     * Generates SQL
     *
     * @param  array
     * @return string|FALSE
     * @throw  DibiException
     */
    public function translate($args)
    {
        $this->hasError = FALSE;
        $command = null;
        $mod = & $this->modifier; // shortcut
        $mod = FALSE;

        // conditional sql
        $this->ifLevel = $this->ifLevelStart = 0;
        $comment = & $this->comment;
        $comment = FALSE;

        // iterate
        $sql = array();
        foreach ($args as $arg)
        {
            // %if was opened
            if ('if' == $mod) {
                $mod = FALSE;
                $this->ifLevel++;
                if (!$comment && !$arg) {
                    // open comment
                    $sql[] = "\0";
                    $this->ifLevelStart = $this->ifLevel;
                    $comment = TRUE;
                }
                continue;
            }

            // simple string means SQL
            if (is_string($arg) && (!$mod || 'sql' == $mod)) {
                $mod = FALSE;
                // will generate new mod
                $sql[] = $this->formatValue($arg, 'sql');
                continue;
            }

            // associative array without modifier - autoselect between SET or VALUES
            if (!$mod && is_array($arg) && is_string(key($arg))) {
                if (!$command)
                    $command = strtoupper(substr(ltrim($args[0]), 0, 6));

                $mod = ('INSERT' == $command || 'REPLAC' == $command) ? 'v' : 'a';
            }

            // default processing
            if (!$comment) $sql[] = $this->formatValue($arg, $mod);
            $mod = FALSE;
        } // foreach

        if ($comment) $sql[] = "\0";

        $sql = implode(' ', $sql);

        // remove comments
        // TODO: check !!!
        $sql = preg_replace('#\x00.*?\x00#s', '', $sql);

        // error handling
        if ($this->hasError) {
            if (dibi::$logFile)  // log to file
                dibi::log(
                    "ERROR: SQL generate error"
                    . "\n-- SQL: " . $sql
                    . ";\n-- " . date('Y-m-d H:i:s ')
                );

            if (dibi::$throwExceptions)
                throw new DibiException('SQL generate error', NULL, $sql);
            else {
                trigger_error("dibi: SQL generate error: $sql", E_USER_WARNING);
                return FALSE;
            }
        }

        // OK
        return $sql;
    }




    private function formatValue($value, $modifier)
    {
        // array processing (with or without modifier)
        if (is_array($value)) {

            $vx = $kx = array();
            switch ($modifier) {
            case 'a': // SET (assoc)
                foreach ($value as $k => $v) {
                    // split into identifier & modifier
                    $pair = explode('%', $k, 2);

                    // generate array
                    $vx[] = $this->delimite($pair[0]) . '='
                        . $this->formatValue($v, isset($pair[1]) ? $pair[1] : FALSE);
                }
                return implode(', ', $vx);


            case 'v': // VALUES
                foreach ($value as $k => $v) {
                    // split into identifier & modifier
                    $pair = explode('%', $k, 2);

                    // generate arrays
                    $kx[] = $this->delimite($pair[0]);
                    $vx[] = $this->formatValue($v, isset($pair[1]) ? $pair[1] : FALSE);
                }
                return '(' . implode(', ', $kx) . ') VALUES (' . implode(', ', $vx) . ')';


            default: // LIST
                foreach ($value as $v)
                    $vx[] = $this->formatValue($v, $modifier);

                return implode(', ', $vx);
            }
        }


        // with modifier procession
        if ($modifier) {
            if ($value === NULL) return 'NULL';

            if ($value instanceof IDibiVariable)
                return $value->toSql($this->driver, $modifier);

            if (!is_scalar($value)) {  // array is already processed
                $this->hasError = TRUE;
                return '**Unexpected ' . gettype($value) . '**';
            }

            switch ($modifier) {
            case 's':  // string
                return $this->driver->escape($value);
            case 'sn': // string or NULL
                return $value == '' ? 'NULL' : $this->driver->escape($value);
            case 'b':  // boolean
                return $value
                    ? $this->driver->formats['TRUE']
                    : $this->driver->formats['FALSE'];
            case 'i':  // signed int
            case 'u':  // unsigned int
                return (string) (int) $value;
            case 'f':  // float
                return (string) (float) $value; // something like -9E-005 is accepted by SQL
            case 'd':  // date
                return date($this->driver->formats['date'], is_string($value)
                    ? strtotime($value)
                    : $value);
            case 't':  // datetime
                return date($this->driver->formats['datetime'], is_string($value)
                    ? strtotime($value)
                    : $value);
            case 'n':  // identifier name
                return $this->delimite($value);
            case 'sql':// preserve as SQL
            case 'p':  // back compatibility
                $value = (string) $value;

                // speed-up - is regexp required?
                $toSkip = strcspn($value, '`[\'"%');

                if (strlen($value) == $toSkip) // needn't be translated
                    return $value;

                // note: only this can change $this->modifier
                return substr($value, 0, $toSkip)
/*
                     . preg_replace_callback('/
                       (?=`|\[|\'|"|%)              ## speed-up
                       (?:
                          `(.+?)`|                  ## 1) `identifier`
                          \[(.+?)\]|                ## 2) [identifier]
                          (\')((?:\'\'|[^\'])*)\'|  ## 3,4) string
                          (")((?:""|[^"])*)"|       ## 5,6) "string"
                          %(else|end)|              ## 7) conditional SQL
                          %([a-zA-Z]{1,3})$|        ## 8) right modifier
                          (\'|")                    ## 9) lone-quote
                       )/xs',
*/
                     . preg_replace_callback('/(?=`|\[|\'|"|%)(?:`(.+?)`|\[(.+?)\]|(\')((?:\'\'|[^\'])*)\'|(")((?:""|[^"])*)"|%(else|end)|%([a-zA-Z]{1,3})$|(\'|"))/s',
                           array($this, 'cb'),
                           substr($value, $toSkip)
                       );

            case 'a':
            case 'v':
                $this->hasError = TRUE;
                return "**Unexpected ".gettype($value)."**";
            case 'if':
                $this->hasError = TRUE;
                return "**The %$modifier is not allowed here**";
            default:
                $this->hasError = TRUE;
                return "**Unknown modifier %$modifier**";
            }
        }



        // without modifier procession
        if (is_string($value))
            return $this->driver->escape($value);

        if (is_int($value) || is_float($value))
            return (string) $value;  // something like -9E-005 is accepted by SQL

        if (is_bool($value))
            return $value ? $this->driver->formats['TRUE'] : $this->driver->formats['FALSE'];

        if ($value === NULL)
            return 'NULL';

        if ($value instanceof IDibiVariable)
            return $value->toSql($this->driver);

        $this->hasError = TRUE;
        return '**Unexpected ' . gettype($value) . '**';
    }




    /**
     * PREG callback for @see self::formatValue()
     * @param  array
     * @return string
     */
    private function cb($matches)
    {
        //    [1] => `ident`
        //    [2] => [ident]
        //    [3] => '
        //    [4] => string
        //    [5] => "
        //    [6] => string
        //    [7] => %else | %end
        //    [8] => right modifier
        //    [9] => lone-quote

        if (!empty($matches[7])) { // %end | %else
            if (!$this->ifLevel) {
                $this->hasError = TRUE;
                return "**Unexpected condition $matches[7]**";
            }

            if ('end' == $matches[7]) {
                $this->ifLevel--;
                if ($this->ifLevelStart == $this->ifLevel + 1) {
                    // close comment
                    $this->ifLevelStart = 0;
                    $this->comment = FALSE;
                    return "\0";
                }
                return '';
            }

            // else
            if ($this->ifLevelStart == $this->ifLevel) {
                $this->ifLevelStart = 0;
                $this->comment = FALSE;
                return "\0";
            } elseif (!$this->comment) {
                $this->ifLevelStart = $this->ifLevel;
                $this->comment = TRUE;
                return "\0";
            }
        }

        if (!empty($matches[8])) { // modifier
            $this->modifier = $matches[8];
            return '';
        }

        if ($this->comment) return '';


        if ($matches[1])  // SQL identifiers: `ident`
            return $this->delimite($matches[1]);

        if ($matches[2])  // SQL identifiers: [ident]
            return $this->delimite($matches[2]);

        if ($matches[3])  // SQL strings: '....'
            return $this->driver->escape( str_replace("''", "'", $matches[4]));

        if ($matches[5])  // SQL strings: "..."
            return $this->driver->escape( str_replace('""', '"', $matches[6]));


        if ($matches[9]) { // string quote
            $this->hasError = TRUE;
            return '**Alone quote**';
        }

        die('this should be never executed');
    }



    /**
     * Apply substitutions to indentifier and delimites it
     * @param string indentifier
     * @return string
     */
    private function delimite($value)
    {
        return $this->driver->delimite( dibi::substitute($value) );
    }



    /**
     * Undefined property usage prevention
     */
    function __get($nm) { throw new Exception("Undefined property '" . get_class($this) . "::$$nm'"); }
    function __set($nm, $val) { $this->__get($nm); }
    private function __unset($nm) { $this->__get($nm); }
    private function __isset($nm) { $this->__get($nm); }

} // class DibiParser