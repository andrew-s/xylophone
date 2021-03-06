<?php
/**
 * Xylophone
 *
 * An open source HMVC application development framework for PHP 5.3 or newer
 * Derived from CodeIgniter, Copyright (c) 2008 - 2013, EllisLab, Inc. (http://ellislab.com/)
 *
 * NOTICE OF LICENSE
 *
 * Licensed under the Open Software License version 3.0
 *
 * This source file is subject to the Open Software License (OSL 3.0) that is
 * bundled with this package in the files license.txt / license.rst. It is also
 * available through the world wide web at this URL:
 * http://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to obtain it
 * through the world wide web, please send an email to licensing@xylophone.io
 * so we can send you a copy immediately.
 *
 * @package     Xylophone
 * @author      Xylophone Dev Team, EllisLab Dev Team
 * @copyright   Copyright (c) 2013, Xylophone Team (http://xylophone.io/)
 * @license     http://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * @link        http://xylophone.io
 * @since       Version 1.0
 * @filesource
 */
namespace Xylophone\core;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Output Class
 *
 * Responsible for sending final output to the browser.
 *
 * @package     Xylophone
 * @subpackage  core
 * @link        http://xylophone.io/user_guide/libraries/output.html
 */
class Output
{
    /** @var    object  Xylophone framework object */
    public $XY;

    /** @var    int     Cache expiration time */
    public $cache_expiration = 0;

    /** @var    bool    Enable Profiler flag */
    public $enable_profiler = false;

    /** @var    bool    Whether or not to parse variables like {elapsed_time} and {memory_usage}. */
    public $parse_exec_vars = true;

    /** @var    array   List of server headers */
    public $headers = array();

    /** @var    bool    Whether to cache view variables */
    public $cache_vars = true;

    /** @var    array   List of cached variables */
    protected $cached_vars = array();

    /** @var    string  Mime-type for the current page */
    protected $mime_type = 'text/html';

    /** @var    bool    zLib output compression flag */
    protected $zlib_oc = false;

    /** @var    array   Output buffer handler (for zlib) */
    protected $ob_handler = null;

    /** @var    array   List of profiler sections */
    protected $profiler_sections = array();

    /**
     * Constructor
     *
     * Determines whether zLib output compression will be used.
     *
     * @return  void
     */
    public function __construct()
    {
        // Set XY reference for loadFile() so $this->XY works in views
        global $XY;
        $this->XY = $XY;

        // Is compression requested?
        $this->zlib_oc = (bool)@ini_get('zlib.output_compression');
        if ($this->zlib_oc === false && $XY->config['compress_output'] === true && extension_loaded('zlib')
        && isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
            $this->ob_handler = 'ob_gzhandler';
        }

        // Buffer all output for speed boost and so the final output can be
        // post-processed, which helps with calculating the elapsed load time.
        // This also lets us catch any output generated through template engines.
        ob_start($this->ob_handler);

        // Check if views can use (or have to allow) short tags
        $this->short_tags = ($XY->isPhp('5.4') || (bool)@ini_get('short_open_tag') ||
            !$XY->config['rewrite_short_tags'] || !$XY->isUsable('eval'));

        $XY->logger->debug('Output Class Initialized');
    }

    /**
     * Get Output
     *
     * Returns the current output string.
     *
     * @return  string  Output string
     */
    public function getOutput()
    {
        // Return the last output on the stack
        return ob_get_contents();
    }

    /**
     * Set Output
     *
     * Sets the current output string.
     *
     * @param   string  $output Output data
     * @return  object  This object
     */
    public function setOutput($output)
    {
        // Clear and replace current buffer contents
        ob_clean();
        echo $output;
        return $this;
    }

    /**
     * Append Output
     *
     * Appends data onto the output string.
     *
     * @param   string  $output Data to append
     * @return  object  This object
     */
    public function appendOutput($output)
    {
        // Append output to current buffer
        echo $output;
        return $this;
    }

    /**
     * Stack Push
     *
     * Pushes a new output buffer onto the stack.
     *
     * @used-by Xylophone::callController()
     *
     * @param   string  $output Optional initial buffer contents
     * @return  int     New stack depth
     */
    public function stackPush($output = '')
    {
        global $XY;

        // Add a buffer to the output stack
        ob_start($this->ob_handler);
        echo $output;
        return ob_get_level() - $XY->init_ob_level;
    }

    /**
     * Stack Pop
     *
     * Pops current output buffer off the stack and returns it.
     * Returns bottom buffer contents (without pop) if only one exists.
     *
     * @used-by Xylophone::callController()
     *
     * @param   bool    $erase  Whether to discard buffer contents
     * @return  mixed   Current output string or NULL on discard
     */
    public function stackPop($discard = false)
    {
        global $XY;

        // Check buffer level
        if ((ob_get_level() - $XY->init_ob_level) > 1) {
            if ($discard) {
                // End the buffer and return NULL
                ob_end_clean();
                return null;
            }

            // Pop the current buffer and return it
            return ob_get_clean();
        }

        // Nothing to pop - get contents unless discarding
        $buffer = $discard ? null : ob_get_contents();

        // Clear the buffer and return
        ob_clean();
        return $buffer;
    }

    /**
     * Get Stack Level
     *
     * Returns number of buffer levels in final output stack
     *
     * @return  int     Stack depth
     */
    public function stackLevel()
    {
        global $XY;

        // Just return count of buffers since we started
        return (ob_get_level() - $XY->init_ob_level);
    }

    /**
     * Output (or return) file contents
     *
     * @used-by Loader::view()
     * @used-by Loader::file()
     *
     * @todo: Add chunk flushing feature
     *
     * @param   string  $file       File path
     * @param   bool    $view_path  Whether to resolve path against view_paths
     * @param   bool    $return     Whether to return file contents
     * @param   array   $vars       Optional variables
     * @return  mixed   Captured output if $return, otherwise void
     */
    public function file($file, $view_path = false, $return = false, $vars = null)
    {
        global $XY;

        // Set the path to the requested file
        $exists = false;
        if ($view_path) {
            // Ensure extension
            pathinfo($file, PATHINFO_EXTENSION) !== '' || $file .= '.php';

            // Resolve file against view paths
            foreach ($XY->view_paths as $vwpath) {
                $_x_path = $vwpath.$file;
                if (file_exists($_x_path)) {
                    // Found it - done
                    $exists = true;
                    break;
                }
            }
            unset($vwpath);
        }
        else {
            // Extract filename from path
            $_x_path = $file;
            $parts = explode('/', $_x_path);
            $file = end($parts);
            $exists = file_exists($_x_path);
            unset($parts);
        }

        // Check if file exists
        $exists || $XY->showError('Unable to load the requested file: '.$file);

        // Clean up local vars so extracted vars don't conflict
        $_x_return = $return;
        unset($file);
        unset($view_path);
        unset($return);
        unset($exists);

        // Check for variable caching
        if ($this->cache_vars) {
            // Extract and cache variables
            // You can either set variables using the dedicated vars() method
            // or via the last parameter of this function. We'll merge the two
            // sources and cache them so that views that are embedded within
            // other views can have access to these variables.
            is_array($vars) && $this->cached_vars = array_merge($this->cached_vars, $vars);
            unset($vars);
            extract($this->cached_vars);
        }
        else if (is_array($vars)) {
            // Simply extract passed vars
            extract($vars);
        }

        // Start a nested buffer if return is requested. Otherwise, let the
        // output be captured by the latest buffer on the stack.
        $_x_return && ob_start();

        // Check for short tag support
        if ($this->short_tags) {
            // Include file directly - don't restrict with include_once
            include($_x_path);
        }
        else {
            // Translate file contents, changing short tags to echo statements
            $_x_content = str_replace('<?=', '<?php echo ', file_get_contents($_x_path));
            echo eval('?'.'>'.preg_replace('/;*\s*\?'.'>/', '; ?'.'>', $_x_content));
        }

        $XY->logger->debug('File loaded: '.$_x_path);

        // Return the captured output if requested
        if ($_x_return) {
            return @ob_get_clean();
        }
    }

    /**
     * Display Output
     *
     * Processes and sends finalized output data to the browser along
     * with any server headers and profile data. It also stops benchmark
     * timers so the page rendering speed and memory usage can be shown.
     *
     * @used-by Xylophone::play()
     *
     * @param   string  $output Output data override
     * @return  void
     */
    public function display($output = '')
    {
        global $XY;

        // Check for output override
        if ($output) {
            // Discard all our output buffers
            for ($level = $this->stackLevel(); $level; --$level) {
                ob_end_clean();
            }
        }
        else {
            // Collapse all our output buffers
            for ($level = $this->stackLevel(); $level > 1; --$level) {
                ob_end_flush();
            }

            // Get the collapsed output
            $output = ob_get_clean();
        }

        // Is minify requested?
        $XY->config['minify_output'] === true && $output = $this->minify($output, $this->mime_type);

        // Do we need to write a cache file? Only if the controller does not have its
        // own output() method and we are not dealing with a cache file, which we
        // can determine by the existence of the routed controller object
        if ($this->cache_expiration > 0 && isset($XY->routed) && !method_exists($XY->routed, 'output')) {
            $this->writeCache($output);
        }

        // Check for benchmarking
        $benchmark = isset($XY->benchmark);
        if ($benchmark) {
            // Get the elapsed time and check for tag parsing
            $elapsed = $XY->benchmark->elapsed_time('total_execution_time_start', 'total_execution_time_end');
            if ($XY->benchmark->tagged && $this->parse_exec_vars) {
                // Get memory usage and swap pseudo-variable tags with data
                $memory = round(memory_get_usage() / 1024 / 1024, 2).'MB';
                $output = str_replace(array('{elapsed_time}', '{memory_usage}'), array($elapsed, $memory), $output);
            }
        }

        // Send any server headers
        foreach ($this->headers as $header) {
            $this->header($header[0], $header[1]);
        }

        // If the routed controller object doesn't exist we know we are dealing
        // with a cache file so we'll simply echo out the data and exit.
        if (!isset($XY->routed)) {
            echo $output;
            $XY->logger->debug('Final output sent to browser');
            $benchmark && $XY->logger->debug('Total execution time: '.$elapsed);
            return;
        }

        // Do we need to generate profile data?
        // If so, load the Profile class and run it.
        if ($benchmark && $this->enable_profiler === true) {
            $XY->load->library('profiler');
            empty($this->profiler_sections) || $XY->profiler->set_sections($this->profiler_sections);

            // If the output data contains closing </body> and </html> tags
            // we will remove them and add them back after we insert the profile data
            $output = preg_replace('|</body>.*?</html>|is', '', $output, -1, $count).$XY->profiler->run();
            $count > 0 && $output .= '</body></html>';
        }

        // Echo output or send to routed controller
        if (method_exists($XY->routed, 'xyOutput')) {
            $XY->routed->xyOutput($output);
        }
        else {
            echo $output;
        }

        $XY->logger->debug('Final output sent to browser');
        $benchmark && $XY->logger->debug('Total execution time: '.$elapsed);
    }

    /**
     * Set cached variables
     *
     * Once variables are set they become available within
     * the controller class and its "view" files.
     *
     * @param   string|array    $vars   Value name or array of name/value pairs
     * @param   string          $val    Value to set, only used if $vars is a string
     * @return  void
     */
    public function vars($vars, $val = '')
    {
        // Convert to array
        if (is_string($vars)) {
            $vars = array($vars => $val);
        }
        elseif (is_object($vars)) {
            $vars = get_object_vars($vars);
        }

        // Add vars to cache
        if (is_array($vars) && count($vars) > 0) {
            foreach ($vars as $key => $val) {
                $this->cached_vars[$key] = $val;
            }
        }
    }

    /**
     * Clear cached variables
     * 
     * @return  void
     */
    public function clearVars()
    {
        // Empty vars array
        $this->cached_vars = array();
    }

    /**
     * Get cached variable
     *
     * Returns the variable if specified and set, or all variables if none specified.
     *
     * @param   string  $key    Optional variable name
     * @return  mixed   The variable or NULL if not found
     */
    public function getVar($key)
    {
        // Return all if no key, otherwise check key
        if ($key === null) {
            return $this->cached_vars;
        }
        return isset($this->cached_vars[$key]) ? $this->cached_vars[$key] : null;
    }

    /**
     * Set Header
     *
     * Lets you set a server header which will be sent with the final output.
     *
     * Note: If a file is cached, headers will not be sent.
     * @todo    We need to figure out how to permit headers to be cached.
     *
     * @used-by Output::displayCache()
     *
     * @param   string  $header     Header
     * @param   bool    $replace    Whether to replace the old header value, if already set
     * @return  object  This object
     */
    public function setHeader($header, $replace = true)
    {
        // If zlib.output_compression is enabled it will compress the output,
        // but it will not modify the content-length header to compensate for
        // the reduction, causing the browser to hang waiting for more data.
        // We'll just skip content-length in those cases.
        if (!$this->zlib_oc || strncasecmp($header, 'content-length', 14) !== 0) {
            $this->headers[] = array($header, $replace);
        }

        return $this;
    }

    /**
     * Get Header
     *
     * @param   string  $header Header name
     * @return  string  Header
     */
    public function getHeader($header)
    {
        // Combine headers already sent with our batched headers
        // We only need [x][0] from our multi-dimensional array
        $headers = array_merge(array_map('array_shift', $this->headers), headers_list());

        if (empty($headers) || empty($header)) {
            return null;
        }

        for ($i = 0, $c = count($headers); $i < $c; $i++) {
            if (strncasecmp($header, $headers[$i], $l = strlen($header)) === 0) {
                return trim(substr($headers[$i], $l+1));
            }
        }

        return null;
    }

    /**
     * Set Content-Type Header
     *
     * @param   string  $mime_type  Extension of the file we're outputting
     * @param   string  $charset    Character set
     * @return  object  This object
     */
    public function setContentType($mime_type, $charset = null)
    {
        global $XY;

        if (strpos($mime_type, '/') === false) {
            $extension = ltrim($mime_type, '.');

            // Is this extension supported?
            if (isset($XY->config['mimes'][$extension])) {
                $mime_type = $XY->config['mimes'][$extension];
                is_array($mime_type) && $mime_type = current($mime_type);
            }
        }

        // Set content mime type
        $this->mime_type = $mime_type;

        // Ensure charset and add type header
        empty($charset) && $charset = $XY->config['charset'];
        $header = 'Content-Type: '.$mime_type.(empty($charset) ? null : '; charset='.$charset);
        $this->headers[] = array($header, true);

        return $this;
    }

    /**
     * Get Current Content-Type Header
     *
     * @return  string  'text/html', if not already set
     */
    public function getContentType()
    {
        for ($i = 0, $c = count($this->headers); $i < $c; $i++) {
            if (sscanf($this->headers[$i][0], 'Content-Type: %[^;]', $content_type) === 1) {
                return $content_type;
            }
        }

        return 'text/html';
    }

    /**
     * Set HTTP Status Header
     *
     * @used-by Output::setCacheHeader()
     * @used-by Exceptions::showError()
     *
     * @param   int     $code   Status code
     * @param   string  $text   Header text
     * @return  object  This object
     */
    public function setStatusHeader($code = 200, $text = '')
    {
        global $XY;

        // Validate status code
        // showError here may have a recursion problem
        (!empty($code) && is_numeric($code)) || $XY->showError('Status codes must be numeric', 500);
        is_int($code) || $code = (int)$code;

        if (empty($text)) {
            isset(Xylophone::$status_codes[$code]) || $XY->showError('No status text available. '.
                'Please check your status code number or supply your own message text.', 500);
            $text = Xylophone::$status_codes[$code];
        }

        // Check for CLI
        if ($this->isCli()) {
            // Set CLI status
            $this->header('Status: '.$code.' '.$text, true);
        }
        else {
            // Combine protocol, code, and text
            $proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
            $this->header($proto.' '.$code.' '.$text, true, $code);
        }

        return $this;
    }

    /**
     * Set Cache Expiration
     *
     * @param   int     $time   Cache expiration time in seconds
     * @return  object  This object
     */
    public function cache($time)
    {
        $this->cache_expiration = is_numeric($time) ? $time : 0;
        return $this;
    }

    /**
     * Enable/disable Profiler
     *
     * @param   bool    $val    TRUE to enable or FALSE to disable
     * @return  object  This object
     */
    public function enableProfiler($val = true)
    {
        $this->enable_profiler = ($val === true);
        return $this;
    }

    /**
     * Set Profiler Sections
     *
     * Allows override of default/config settings for Profiler section display.
     *
     * @param   array   $sections   Profiler sections
     * @return  object  This object
     */
    public function setProfilerSections($sections)
    {
        if (isset($sections['query_toggle_count'])) {
            $this->profiler_sections['query_toggle_count'] = (int)$sections['query_toggle_count'];
            unset($sections['query_toggle_count']);
        }

        foreach ($sections as $section => $enable) {
            $this->profiler_sections[$section] = ($enable !== false);
        }

        return $this;
    }

    /**
     * Remove Invisible Characters
     *
     * This prevents sandwiching null characters between ascii characters,
     * like Java\0script.
     *
     * @used-by URI::setUriString()
     * @used-by Utf8::safeAsciiForXml()
     * @used-by Input::cleanInputData()
     * @used-by Security::xssClean()
     * @used-by Security::sanitizeFilename()
     *
     * @param   string  $str        String to clean
     * @param   bool    $encoded    Whether string is URL-encoded
     * @return  string  Cleaned string
     */
    public function removeInvisibleCharacters($str, $encoded = true)
    {
        // Find every control character except LF (dec 10), CR (dec 13),
        // and horizontal tab (dec 09)
        $invisible = array();
        if ($encoded) {
            $invisible[] = '/%0[0-8bcef]/'; // URL-encoded 00-08, 11, 12, 14, 15
            $invisible[] = '/%1[0-9a-f]/';  // URL-encoded 16-31
        }
        $invisible[] = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/S';  // 00-08, 11, 12, 14-31, 127

        // Strip characters from string
        do {
            $str = preg_replace($invisible, '', $str, -1, $count);
        } while ($count);

        return $str;
    }

    /**
     * Returns HTML escaped string
     *
     * @param   mixed   $html   HTML string or array of strings
     * @return  mixed   Escaped string or array of strings
     */
    public function htmlEscape($html)
    {
        global $XY;
        return is_array($html) ? array_map(array($this, 'htmlEscape'), $html) :
            htmlspecialchars($html, ENT_QUOTES, $XY->config['charset']);
    }

    /**
     * Write Cache
     *
     * @used-by Output::display()
     *
     * @param   string  $output Output data to cache
     * @return  void
     */
    public function writeCache($output)
    {
        global $XY;

        // Get cache file path and open
        if (!($file = $this->getCachePath()) || !($fp = @fopen($file, FOPEN_WRITE_CREATE_DESTRUCTIVE))) {
            $XY->logger->error('Unable to write cache file: '.$file);
            return;
        }

        // Put together our serialized info
        $expire = time() + ($this->cache_expiration * 60);
        $cache_info = serialize(array('expire' => $expire, 'headers' => $this->headers));

        // Lock, write, unlock, close, and chmod
        if (!flock($fp, LOCK_EX)) {
            $XY->logger->error('Unable to secure a file lock for file at: '.$file);
            return;
        }
        fwrite($fp, $cache_info.'ENDXY--->'.$output);
        flock($fp, LOCK_UN);
        fclose($fp);
        @chmod($cache_path, FILE_WRITE_MODE);

        // Send HTTP cache-control headers to browser to match file cache settings
        $this->setCacheHeader($_SERVER['REQUEST_TIME'], $expire);
        $XY->logger->debug('Cache file written: '.$file);
    }

    /**
     * Update/serve cached output
     *
     * @used-by Xylophone::play()
     *
     * @return  bool    TRUE on success, otherwise FALSE
     */
    public function displayCache()
    {
        global $XY;

        // Get cache file path and open file
        $file = $this->getCachePath();
        if (!$file || !@file_exists($file) || !($fp = @fopen($file, FOPEN_READ))) {
            return false;
        }

        // Lock, read, unlock, close
        flock($fp, LOCK_SH);
        $cache = (filesize($file) > 0) ? fread($fp, filesize($file)) : '';
        flock($fp, LOCK_UN);
        fclose($fp);

        // Look for embedded serialized file info.
        if (!preg_match('/^(.*)ENDXY--->/', $cache, $match)) {
            return false;
        }
        $cache_info = unserialize($match[1]);
        $expire = $cache_info['expire'];
        $last_modified = filemtime($file);

        // Has the file expired?
        if ($_SERVER['REQUEST_TIME'] >= $expire && $XY->isWritable($file)) {
            // If so we'll delete it.
            @unlink($file);
            $XY->logger->debug('Cache file has expired. File deleted.');
            return false;
        }
        else {
            // Or else send the HTTP cache control headers.
            $this->setCacheHeader($last_modified, $expire);
        }

        // Add headers from cache file.
        foreach ($cache_info['headers'] as $header) {
            $this->setHeader($header[0], $header[1]);
        }

        // Display the cache
        $XY->logger->debug('Cache file is current. Sending it to browser.');
        $this->display(substr($cache, strlen($match[0])));
        return true;
    }

    /**
     * Delete cache
     *
     * @param   string  $uri    URI string
     * @return  bool    TRUE on success, otherwise FALSE
     */
    public function deleteCache($uri = '')
    {
        global $XY;

        // Get cache path and unlink
        if (!($file = $this->getCachePath($uri)) || !@unlink($file)) {
            $XY->logger->error('Unable to delete cache file for '.$uri);
            return false;
        }

        return true;
    }

    /**
     * Set Cache Header
     *
     * Set the HTTP headers to match the server-side file cache settings
     * in order to reduce bandwidth.
     *
     * @used-by Output::writeCache()
     * @used-by Output::displayCache()
     *
     * @param   int     $last_modified  Timestamp of when the page was last modified
     * @param   int     $expiration     Timestamp of when should the requested page expire from cache
     * @return  void
     */
    public function setCacheHeader($last_modified, $expiration)
    {
        $max_age = $expiration - $_SERVER['REQUEST_TIME'];

        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) &&
        $last_modified <= strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $this->setStatusHeader(304);
            exit;
        }
        else {
            $this->header('Pragma: public');
            $this->header('Cache-Control: max-age='.$max_age.', public');
            $this->header('Expires: '.gmdate('D, d M Y H:i:s', $expiration).' GMT');
            $this->header('Last-modified: '.gmdate('D, d M Y H:i:s', $last_modified).' GMT');
        }
    }

    /**
     * Minify
     *
     * Reduce excessive size of HTML/CSS/JavaScript content.
     *
     * @used-by Output::display()
     *
     * @param   string  $output Output to minify
     * @param   string  $type   Output content MIME type
     * @return  string  Minified output
     */
    public function minify($output, $type = 'text/html')
    {
        global $XY;

        switch ($type) {
            case 'text/html':
                if (($size_before = strlen($output)) === 0) {
                    return '';
                }

                // Find all the <pre>,<code>,<textarea>, and <javascript> tags
                // We'll want to return them to this unprocessed state later.
                preg_match_all('{<pre.+</pre>}msU', $output, $pres_clean);
                preg_match_all('{<code.+</code>}msU', $output, $codes_clean);
                preg_match_all('{<textarea.+</textarea>}msU', $output, $textareas_clean);
                preg_match_all('{<script.+</script>}msU', $output, $javascript_clean);

                // Minify the CSS in all the <style> tags.
                preg_match_all('{<style.+</style>}msU', $output, $style_clean);
                foreach ($style_clean[0] as $s) {
                    $output = str_replace($s, $this->minifyScript($s, 'css', true), $output);
                }

                // Minify the javascript in <script> tags.
                foreach ($javascript_clean[0] as $s) {
                    $javascript_mini[] = $this->minifyScript($s, 'js', true);
                }

                // Replace multiple spaces with a single space.
                $output = preg_replace('!\s{2,}!', ' ', $output);

                // Remove comments (non-MSIE conditionals)
                $output = preg_replace('{\s*<!--[^\[<>].*(?<!!)-->\s*}msU', '', $output);

                // Remove spaces around block-level elements.
                $output = preg_replace('/\s*(<\/?(html|head|title|meta|script|link|style|body|table|thead|tbody|tfoot|tr|th|td|h[1-6]|div|p|br)[^>]*>)\s*/is', '$1', $output);

                // Replace mangled <pre> etc. tags with unprocessed ones.
                if (!empty($pres_clean)) {
                    preg_match_all('{<pre.+</pre>}msU', $output, $pres_messed);
                    $output = str_replace($pres_messed[0], $pres_clean[0], $output);
                }

                if (!empty($codes_clean)) {
                    preg_match_all('{<code.+</code>}msU', $output, $codes_messed);
                    $output = str_replace($codes_messed[0], $codes_clean[0], $output);
                }

                if (!empty($textareas_clean)) {
                    preg_match_all('{<textarea.+</textarea>}msU', $output, $textareas_messed);
                    $output = str_replace($textareas_messed[0], $textareas_clean[0], $output);
                }

                if (isset($javascript_mini)) {
                    preg_match_all('{<script.+</script>}msU', $output, $javascript_messed);
                    $output = str_replace($javascript_messed[0], $javascript_mini, $output);
                }

                $removed = $size_before - strlen($output);
                $savings_percent = round(($removed / $size_before * 100));
                $removed /= 1000;

                $XY->logger->debug('Minifier shaved '.$removed.'KB ('.$savings_percent.'%) off final HTML output.');
                break;
            case 'text/css':
                return $this->minifyScript($output, 'css');
            case 'text/javascript':
            case 'application/javascript':
            case 'application/x-javascript':
                return $this->minifyScript($output, 'js');
            default: break;
        }

        return $output;
    }

    /**
     * Minify JavaScript and CSS code
     *
     * Strips comments and excessive whitespace characters
     *
     * @used-by Output::minify()
     *
     * @param   string  $output Code to minify
     * @param   string  $type   Script type - 'js' or 'css'
     * @param   bool    $tags   Whether $output contains the 'script' or 'style' tag
     * @return  string  Minified code
     */
    protected function minifyScript($output, $type, $tags = false)
    {
        if ($tags === true) {
            $tags = array('close' => strrchr($output, '<'));

            $open_length = strpos($output, '>') + 1;
            $tags['open'] = substr($output, 0, $open_length);

            $output = substr($output, $open_length, -strlen($tags['close']));

            // Strip spaces from the tags
            $tags = preg_replace('#\s{2,}#', ' ', $tags);
        }

        $output = trim($output);

        if ($type === 'js') {
            // Catch all string literals and comment blocks
            if (preg_match_all('#((?:((?<!\\\)\'|")|(/\*)|(//)).*(?(2)(?<!\\\)\2|(?(3)\*/|\n)))#msuUS',
            $output, $match, PREG_OFFSET_CAPTURE)) {
                $js_literals = $js_code = array();
                for ($match = $match[0], $c = count($match), $i = $pos = $offset = 0; $i < $c; $i++) {
                    $js_code[$pos++] = trim(substr($output, $offset, $match[$i][1] - $offset));
                    $offset = $match[$i][1] + strlen($match[$i][0]);

                    // Save only if we haven't matched a comment block
                    $match[$i][0][0] === '/' || $js_literals[$pos++] = array_shift($match[$i]);
                }
                $js_code[$pos] = substr($output, $offset);

                // $match might be quite large, so free it up together with other vars that we no longer need
                unset($match, $offset, $pos);
            }
            else {
                $js_code = array($output);
                $js_literals = array();
            }

            $varname = 'js_code';
        }
        else {
            $varname = 'output';
        }

        // Standartize new lines
        $$varname = str_replace(array("\r\n", "\r"), "\n", $$varname);

        if ($type === 'js') {
            // Remove spaces following and preceeding JS-wise non-special & non-word characters
            // Reduce the remaining multiple whitespace characters to a single space 
            $patterns = array(
                '#\s*([!\#%&()*+,\-./:;<=>?@\[\]^`{|}~])\s*#' => '$1',
                '#\s{2,}#' => ' '
            );
        }
        else {
            $patterns = array(
                '#/\*.*(?=\*/)\*/#s' => '',     // Remove /* block comments */
                '#\n?//[^\n]*#' => '',          // Remove // line comments
                '#\s*([^\w.\#%])\s*#U' => '$1', // Remove spaces before and after non-word characters, except .#%
                '#\s{2,}#' => ' '               // Reduce the remaining multiple space characters to a single space
            );
        }

        $$varname = preg_replace(array_keys($patterns), array_values($patterns), $$varname);

        // Glue back JS quoted strings
        if ($type === 'js') {
            $js_code += $js_literals;
            ksort($js_code);
            $output = implode($js_code);
            unset($js_code, $js_literals, $varname, $patterns);
        }

        return is_array($tags) ? $tags['open'].$output.$tags['close'] : $output;
    }

    /**
     * Get Cache File Path
     *
     * @used-by Output::writeCache()
     * @used-by Output::displayCache()
     * @used-by Output::deleteCache()
     *
     * @param   string  $uri    Optional URI string
     * @return  mixed   Cache path string on success, otherwise FALSE
     */
    protected function getCachePath($uri = null)
    {
        global $XY;

        // Get configured cache path
        $cache_path = $XY->config['cache_path'];
        $cache_path === '' && $cache_path = $XY->app_path.'cache/';

        // Make sure directory is writable
        if (!is_dir($cache_path) || !$XY->isWritable($cache_path)) {
            $XY->logger->error('Invalid cache path: '.$cache_path);
            return false;
        }

        // Build the file path with an MD5 hash of the full URI
        empty($uri) && $uri = $XY->config['base_url'].$XY->config['index_page'].$XY->uri->uri_string;
        return $cache_path.md5($uri);
    }

    /**
     * Send raw HTTP header
     *
     * This abstraction of the header call allows overriding for unit testing
     *
     * @codeCoverageIgnore
     *
     * @param   string  $string             The header string
     * @param   bool    $replace            Whether to replace a previous similar header
     * @param   int     $http_response_code The HTTP response code to send
     * @return  void
     */
    protected function header($string, $replace = true, $http_response_code = null)
    {
        // By default, we just call header() with or without the response code
        if ($http_response_code === null) {
            header($string, $replace);
        }
        else {
            header($string, $replace, $http_response_code);
        }
    }
}

