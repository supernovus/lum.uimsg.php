<?php

namespace Lum\UI;

use Lum\Plugins\Client;

/**
 * LangException: Thrown if something goes wrong.
 */
class LangException extends \Exception {}

/**
 * A class representing a set of UI strings in multiple languages.
 *
 * Implements the ArrayAccess interface so that you can get strings
 * using a simple $strings['stringid'] methodology.
 */
class Strings implements \ArrayAccess
{
  /**
   * @var string  The default language to use.
   *
   * This can be a string indicating a single default language, or an
   * array of strings representing multiple default languages.
   *
   * If this is set to the value 'auto', we will use the Accept-Language header
   * regardless of if $use_accept is enabled.
   * 
   * If it's set to a boolean value, it will override the $use_accept property.
   *
   * If it's not set at all, we will ignore it.
   */
  public $default_lang;

  /**
   * @var string  The language to fall back on if no other is found.
   */
  public $fallback_lang = 'en';

  /**
   * @var string  Always use the 'Accept-Language' header to add languages.
   */
  public $use_accept = false;

  /**
   * @var array   The namespaces to look in.
   */
  public $default_ns;

  private $languages; // Storage for our configuration.

  private $key_cache;

  /**
   * Create our object.
   *
   * @param array  $data    An associative array representing the translations.
   * @param array  $ns      Optional. Default namespaces to look in.
   * @param array  $opts    Optional, associative array of options.
   *                        'default'  (mixed)  Set $default_lang to this.
   *                        'fallback' (string) Set $fallback_lang to this.
   *                        'accept'   (bool)   Set $use_accept to this.
   */
  public function __construct ($data, $ns=[], $opts=[])
  {
    $this->languages  = $data;
    $this->default_ns = isset($ns) ? $ns : [];
    if (isset($opts))
    {
      if (isset($opts['default']))
      {
        $this->default_lang = $opts['default'];
      }
      if (isset($opts['fallback']) && is_string($opts['fallback']))
      {
        $this->fallback_lang = $opts['fallback'];
      }
      if (isset($opts['accept']) && is_bool($opts['accept']))
      {
        $this->use_accept = $opts['accept'];
      }
    }
    $this->clear_cache();
  }

  /**
   * Clear the cache.
   */
  public function clear_cache ()
  {
    $this->key_cache = [];
  }

  protected function add_lang ($add, &$langs)
  {
    if (is_string($add))
    {
      $add = [$add];
    }
    foreach ($add as $lang)
    {
      if (!in_array($lang, $langs))
      {
        if (isset($this->languages[$lang]))
        {
          $langdef = $this->languages[$lang];
          $langs[$lang] = $langdef;
          if (isset($langdef['.inherits']))
          {
            $this->add_lang($langdef['.inherits'], $langs);
          }
        }
      }
    }
  }

  protected function do_cache ($opts)
  {
    if (isset($opts['cache']))
    {
      return $opts['cache'];
    }
    if (isset($opts['setns']))
    {
      return false;
    }
    if (isset($opts['addns']))
    {
      return false;
    }
    if (isset($opts['insns']))
    {
      return false;
    }
    if (isset($opts['lang']))
    {
      return false;
    }
    return true;
  }

  protected function get_ns ($opts)
  {
    // Build a namespaces and language options.
    if (isset($opts['setns']))
    {
      if (is_array($opts['setns']))
      {
        $nses = $opts['setns'];
      }
      else
      {
        $nses = [$opts['setns']];
      }
    }
    else
    {
      $nses = $this->default_ns;
      if (isset($opts['addns']))
      {
        if (is_array($opts['addns']))
        {
          foreach ($opts['addns'] as $addns)
          {
            $nses[] = $addns;
          }
        }
        else
        {
          $nses[] = $opts['addns'];
        }
      }
      if (isset($opts['insns']))
      {
        if (is_array($opts['insns']))
        {
          foreach ($opts['insns'] as $insns)
          {
            array_unshift($nses, $insns);
          }
        }
        else
        {
          array_unshift($nses, $opts['insns']);
        }
      }
    }

    return $nses;
  }

  protected function get_langs ($opts)
  {
    $use_accept = $this->use_accept;
    $langs = [];
    if (isset($opts['lang']))
    {
      $this->add_lang($opts['lang'], $langs);
    }
    elseif (is_string($this->default_lang))
    {
      if ($this->default_lang == 'auto')
      {
        $use_accept = true;
      }
      else
      {
        $this->add_lang($this->default_lang, $langs);
      }
    }
    elseif (is_array($this->default_lang))
    {
      $this->add_lang($this->default_lang, $langs);
    }
    elseif (is_bool($this->default_lang))
    { // Override the use_accept value.
      $use_accept = $this->default_lang;
    }

    if ($use_accept)
    { // Add languages from the Accept-Language header.
      $use_langs = Client::acceptLanguage();
      #error_log("Using accept langs: ".json_encode($use_langs));
      $this->add_lang(array_keys($use_langs), $langs);
    }

    // Last but not least, add the fallback.
    $this->add_lang($this->fallback_lang, $langs);

    return $langs;
  }

  /**
   * Look up a string.
   *
   * @param string $key  The string id we are looking for.
   * @param array $opts  (Optional) Named options. To be documented.
   *
   * @return string  The UI string if found, or the original key if not.
   */
  public function getStr ($key, $opts=[])
  {
    #error_log("getStr($key)");
    $cache = $this->do_cache($opts);
    $cachekey = $key; // The raw key is used for the cache.

    $value = Null;
    if ($cache && isset($this->key_cache[$cachekey]))
    { // Use cached value.
      $cached = $this->key_cache[$cachekey];
      #error_log("Using cached value for '$key': ".json_encode($cached));
      $value = $cached['value'];
      if ($cached['return'])
      {
        return $value;
      }
      $ns = $cached['ns'];
      $cache = false; // Don't need to re-cache.
    }
    else
    { // Find a value.
      $nses = $this->get_ns($opts);
      $languages = $this->get_langs($opts);

      $matches = [];
      if (preg_match('/^(\w+):/', $key, $matches))
      {
        $prefix = $matches[1];
        $strip  = $matches[0];
        array_unshift($nses, $prefix);
        $key = str_replace($strip, '', $key);
      }

      foreach ($languages as $language)
      {
        foreach ($nses as $ns)
        {
          if (
            isset($language[$ns]) 
            && is_array($language[$ns]) 
            && isset($language[$ns][$key])
          )
          {
            $value = $language[$ns][$key];
            break 2;
          }
        }
      }
    }

    if (isset($value))
    {
      if ($cache)
      {
        $this->key_cache[$cachekey] = 
        [
          'ns'     => $ns, 
          'value'  => $value, 
          'return' => false
        ];
      }
      if (is_array($value))
      {
        if (isset($opts['complex']) && $opts['complex'])
        {
          $return = $value;
        }
        elseif (isset($value['text']))
        {
          $return = $value['text'];
        }
        else
        {
          throw new LangException
           ("Invalid language definition: '$lang:$ns:$key'");
        }
      }
      else
      {
        $return = $value;
      }
      // Now let's see if there's anything else to do.
      if (isset($opts['complex']) && $opts['complex'])
      {
        if (!is_array($return))
        {
          $return = ['text'=>$return];
        }
        $return['ns'] = $ns;
        if (isset($opts['reps']))
        {
          $return['raw_text'] = $return['text'];
          $return['reps'] = $opts['reps'];
          $return['text'] = vsprintf($return['text'], $opts['reps']);
        }
        elseif (isset($opts['vars']))
        {
          $return['raw_text'] = $return['text'];
          $return['vars'] = $opts['vars'];
          foreach ($opts['vars'] as $varkey => $varval)
          {
            $return['text'] = str_replace($varkey, $varval, $return['text']);
          }
        }
      }
      elseif (isset($opts['reps']))
      {
        $return = vsprintf($return, $opts['reps']);
      }
      elseif (isset($opts['vars']))
      {
        foreach ($opts['vars'] as $varkey => $varval)
        {
          $return = str_replace($varkey, $varval, $return);
        }
      }
      return $return;
    }

    // If we reached here, no string was found at all, return the key.
    if ($cache)
    { // Cache the unmatched key.
      $this->key_cache[$key] =
      [
        'value'  => $key,
        'ns'     => null,
        'return' => true,
      ];
    }
    return $key;
  }

  /**
   * Generate a translation table from an array of string ids.
   *
   * @param array $array  An array of string ids to find.
   *
   * If the key is numeric then the value is the string id, and also
   * the default value if the string was not found.
   *
   * If the key is a string then it's the string id and the value is
   * the default value if the string is not found.
   *
   * @param string $prefix  (Optional) Prefix to add to string ids.
   *
   * @param array $opts  (Optional) Options to pass to getStr().
   *
   * @return array  [$stringid => $stringtext]
   */
  public function strArray (array $array, string $prefix='', array $opts=[])
    : array
  {
    if (count($array) == 0 && isset($prefix) && trim($prefix) != '')
    { // Empty array, but defined prefix, let's get all prefixed keys.
      $array = $this->findKeys("/^$prefix/", true, $opts);
    }

    $assoc = [];

    foreach ($array as $index => $value)
    {
      if (is_numeric($index))
      {
        $key     = $value;
        $default = $value;
      }
      else
      {
        $key     = $index;
        $default = $value;
      }
      $val = $this->getStr($prefix.$key, $opts);
      if ($val == $prefix.$key)
      {
        $val = $default;
      }
      $assoc[$key] = $val;
    }

    return $assoc;
  }

  /**
   * Find all keys matching a regular expression.
   *
   * @param string $regex  The pattern we're looking for.
   * @param bool $strip  If true, remove the regex from the returned keys.
   *
   * @return array  A list of matching translation keys.
   */
  public function findKeys (string $regex, bool $strip=false, array $opts=[])
    : array
  {
    $nses = $this->get_ns($opts);
    $languages = $this->get_langs($opts);

    $keys = [];

    foreach ($languages as $language)
    {
      foreach ($nses as $ns)
      {
        if (isset($language[$ns]) && is_array($language[$ns]))
        {
          foreach ($language[$ns] as $key => $val)
          {
            if (preg_match($regex, $key))
            { // Key matched the prefix.
              $keys[] = ($strip ? preg_replace($regex, '', $key) : $key);
            }
          }
        }
      }
    }

    return $keys;
  }

  // A weird undocumented method that I've used once and need to keep.
  // I will likely replace this with something nicer at some point in the
  // future, at which point I will document it properly.
  public function strStruct ($array, $sep='.', $ns='', $opts=[])
  {
    $result = array();
    foreach ($array as $prefix => $def)
    {
      $result[$prefix] = 
        $this->strStruct_getDef($def, $prefix, $sep, $ns, $opts);
    }
    return $result;
  }

  // Private helper method for strStruct().
  private function strStruct_getDef ($def, $prefix, $sep, $ns, $opts)
  {
    $result = array();
    foreach ($def as $index => $value)
    {
      if (is_array($value))
      {
        $result[$index] = 
          $this->strStruct_getDef($value, $prefix, $sep, $ns, $opts);
        continue;
      }
      elseif (is_numeric($index))
      {
        $key     = $value;
        $default = $value;
      }
      else
      {
        $key     = $index;
        $default = $value;
      }
      $id  = $ns.$prefix.$sep.$key;
      $val = $this->getStr($id, $opts);
      if ($val == $id)
      {
        $val = $default;
      }
      $result[$key] = $val;
    }
    return $result;
  }

  /**
   * Reverse lookup.
   *
   * Searches through the language strings for the specified text,
   * and returns the string id if it was found.
   *
   * @param string $string  The string we are looking for.
   *
   * @return mixed  The string id if we found the string. False if we didn't.
   */
  public function lookupStr ($string, $opts=[])
  {
    // TODO: try looking in the get cache first.
    // TODO: maybe implement a string cache?
    $nses = $this->get_ns($opts);
    $languages = $this->get_langs($opts);

    foreach ($languages as $language)
    {
      foreach ($nses as $ns)
      {
        if (isset($language[$ns]) && is_array($language[$ns]))
        {
          foreach ($language[$ns] as $key=>$val)
          {
            if (is_string($val) && $val == $string)
            {
              if (isset($opts['complex']) && $opts['complex'])
              {
                $value = [
                  'ns'  => $ns,
                  'key' => $key,
                ];
                return $value;
              }
              else 
              {
                return $key;
              }
            }
            elseif 
              (is_array($val) && isset($val['text']) && $val['text'] == $string)
            {
              if (isset($opts['complex']) && $opts['complex'])
              {
                $value = $val;
                $value['ns']  = $ns;
                $value['key'] = $key;
                return $value;
              }
              else
              {
                return $key;
              }
            }
          }
        }
      }
    }

    // If we reached here, we didn't find it. Return false.
    return False;
  }

  // ArrayAccess Interface.

  /**
   * Does the string id map to a valid string?
   *
   * This is a very non-optimal method, as it literally has to do a full
   * getStr() call, and then see if the output was the same as the input.
   * Avoid using this if at all possible.
   */ 
  public function offsetExists ($offset): bool
  { // Simple, but non-optimized way of determining if the key exists.
    $newoffset = $this->getStr($offset);
    return $newoffset == $offset ? false : true;
  }

  /**
   * I may add a virtual language that can act as a string cache down the road.
   *
   * If I do, this will be used to remove items from that cache.
   * Until then, attempting to unset a string will throw an exception.
   *
   * @throws LangException
   */
  public function offsetUnset ($offset): void
  {
    throw new LangException("Cannot unset translations.");
  }

  /**
   * I may add a virtual language that can act as a string cache down the road.
   *
   * If I do, this will be used to add strings to the cache.
   * Until then, attempting to set a string will throw an exception.
   * 
   * @throws LangException
   */
  public function offsetSet ($offset, $value): void
  {
    throw new LangException("Cannot set translations.");
  }

  /**
   * Get a string.
   *
   * This is an alias to getStr() with all default settings (no options
   * can be passed obviously.)
   */
  public function offsetGet ($offset): mixed
  { // Return the results of getStr with default options.
    return $this->getStr($offset);
  }

}

