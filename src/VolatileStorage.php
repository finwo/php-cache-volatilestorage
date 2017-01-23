<?php

namespace Finwo\Cache;

use Finwo\FileLock\FileLock;

/**
 * Implements memcached
 * Handles refreshing in a server-friendly way
 */
class VolatileStorage extends Cache
{
    /* Variables */

    /**
     * @var string
     */
    protected $directory = null;

    /**
     * @var array
     */
    protected $locks = array();

    /**
     * @var string
     */
    protected $fileExt = ".pev";

    public function __construct($options = array())
    {
        // Override default options
        foreach ($options as $key => $value) {
            if (isset($this->{$key})) {
                $this->{$key} = $value;
            }
        }

        // Make sure we have a directory
        if (is_null($this->directory)) {
            $this->directory = implode(DIRECTORY_SEPARATOR, array( __DIR__, 'storage' ));
        }

        // Create it if needed
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 750, true);
        }

        // Expire old files
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*' . $this->fileExt) as $filename) {
            $contents = json_decode(file_get_contents($filename), true);
            if ($contents['expires'] && time() > $contents['expires']) {
                unlink($filename);
            }
        }
    }

    /**
     * @param string $input
     *
     * @return string
     */
    protected function percentEncode($input)
    {
        if (function_exists('urlencode')) {
            try {
                $output = urlencode($input);

                return $output;
            } catch (\Exception $e) {
                // Fall back to 'manual'
            }
        }

        return preg_replace_callback("/[^a-z0-9\\-_\\.~]/i", function ($matches) {
            $encoded = '00' . dechex(ord(array_shift($matches)));

            return '%' . substr($encoded, -2);
        }, $input);
    }

    /**
     * @param string $input
     *
     * @return string
     */
    protected function percentDecode($input)
    {
        if (function_exists('urldecode')) {
            try {
                $output = urldecode($input);

                return $output;
            } catch (\Exception $e) {
                // Fall back to 'manual'
            }
        }

        return preg_replace_callback("/%[0-9a-f]{2}/i", function ($matches) {
            return chr(hexdec(substr(array_shift($matches), 1)));
        }, $input);
    }

    /**
     * @param string $input
     *
     * @return string
     */
    protected function encode($input)
    {
        try {
            $output = serialize($input);

            return $output;
        } catch (\Exception $e) {
            return $this->percentEncode($input);
        }
    }

    /**
     * @param string $input
     *
     * @return string
     */
    protected function decode($input)
    {
        try {
            $output = unserialize($input);

            return $output;
        } catch (\Exception $e) {
            return $this->percentDecode($input);
        }
    }

    /* Public functions a.k.a. API */

    /**
     * {@inheritdoc}
     */
    public function fetch($key = '', $ttl = 30)
    {
        // Generate filenames
        $file = $this->directory . DIRECTORY_SEPARATOR . $this->percentEncode($key) . $this->fileExt;

        // If it doesn't exist, that's easy
        if (!file_exists($file)) {
            return false;
        }

        // Lock the file
        FileLock::_acquire($file);

        // Fetch file contents
        $contents = explode("\n", file_get_contents($file), 2);
        $expires  = intval(array_shift($contents));
        $data     = str_replace("\n", "", array_shift($contents));

        // Release lock
        FileLock::_release($file);

        // Expire the data if needed
        if ($expires && time() > $expires) {
            unlink($file);

            return false;
        }

        // Return data
        return $this->decode($data);
    }

    /**
     * {@inheritdoc}
     */
    public function store($key = '', $value, $ttl = 30)
    {
        // Generate filenames
        $file = $this->directory . DIRECTORY_SEPARATOR . $this->percentEncode($key) . $this->fileExt;

        // Lock the file
        FileLock::_acquire($file);

        // Generate new file
        $expires = is_null($ttl) ? 0 : time() + intval($ttl);
        $data    = $this->encode($value);
        $success = file_put_contents($file, $expires . "\n" . implode("\n", str_split($data, 70))) !== false;

        // Release lock
        FileLock::_release($file);

        // Return state
        return $success;
    }

    /**
     * (@inheritdoc}
     */
    public function supported()
    {
        return is_writable($this->directory);
    }
}
