<?php

/**
 * Optimize Plugin
 *
 * @author Brandon J. Yaniz (joomla@adept.travel)
 * @copyright 2022 The Adept Traveler, Inc. All Rights Reserved.
 * @license BSD 3-Clause; See LICENSE.txt
 */
defined('_JEXEC') or die();

require_once(__DIR__ . '/vendor/autoload.php');

/**
 * Optimize Plugin
 *
 * @author Brandon J. Yaniz (joomla@adept.travel)
 * @copyright 2021 - 2022 The Adept Traveler, Inc. All Rights Reserved.
 * @license BSD 3-Clause; See LICENSE.txt
 */
class PlgSystemOptimize extends \Joomla\CMS\Plugin\CMSPlugin
{
  /**
   * Joomla Application Instance
   *
   * @var  \Joomla\CMS\Application\CMSApplication
   */
  protected $app;

  /**
   * Joomla Document Instance
   *
   * @var  \JDocument
   */
  protected $doc;

  /**
   * onAfterRender - Used for HTML minification
   */
  public function onAfterRender()
  {
    // Link to the main application
    $this->app = \Joomla\CMS\Factory::getApplication();
    // Link to the main document
    $this->doc = $this->app->getDocument();

    // Only run the plugin in the front end of the website and if the format is HTML
    if ($this->app->isClient('site') && $this->doc instanceof \Joomla\CMS\Document\HtmlDocument) {

      $params = $this->app->getParams();

      //cache
      $cache = \Joomla\CMS\Uri\Uri::getInstance()->getPath();

      if (empty($cache) || $cache == '/') {
        $cache = '/home';
      }

      $cache = JPATH_CACHE . '/plg_system_optimize' . $cache . '.html';

      if (
        $this->app->get('caching') > 0
        && file_exists($cache)
        && filemtime($cache) > (time() - ($this->app->get('cachetime') * 60))
      ) {
        $this->app->setBody(file_get_contents($cache));
      } else {


        // Get the HTML
        $html = $this->app->getBody();

        // Optimize HTML
        if ($this->params->get('html_optimize', 1)) {
          // Normalize HTML
          if ($this->params->get('html_normalize', 1)) {
            $html = $this->normalize($html);
          }

          // Remove HTML comments
          if ($this->params->get('html_comments', 1)) {
            $html = preg_replace('/<!--(.|\s)*?-->/', '', $html);
          }
        }

        // Load the HTML5 DOM Parser
        $html5 = new \Masterminds\HTML5();
        // Load HTML into the DOM Parser
        $dom = $html5->loadHTML($html);

        // Check if images should be optimized
        if ($this->params->get('img_optimize', 1)) {

          // Optimize images
          foreach ([$dom->getElementsByTagName('img'), $dom->getElementsByTagName('video')] as $nodeList) {

            foreach ($nodeList as $el) {
              // Attribute 'poster' is used on video tags, src on img tags
              $src = ($el->hasAttribute('poster'))
                ? $el->getAttribute('poster')
                : $el->getAttribute('src');

              $src = $this->cleanUri($src);

              if (strpos($src, 'https://') === false) {

                $ext = pathinfo($src, PATHINFO_EXTENSION);

                if ($ext == 'jpeg') {
                  $ext = 'jpg';
                }

                // Check if uri is relative or absolute
                if (substr($src, 0, 1) != '/') {
                  // Make filename absolute
                  $src = "/" . $src;
                }

                $image = JPATH_ROOT . $src;

                if (!empty($src)) {
                  if ($this->params->get('img_webp', 1) && file_exists($image)) {
                    if ($this->params->get('img_webp_convert_' . $ext, 1))

                      // WebP file to be saved to the cache directory
                      $webp = $this->getCachePath('img') . substr($src, 1) . '.webp';

                    if (!file_exists($dir = substr($webp, 0, strrpos($webp, '/')))) {
                      mkdir($dir, 0755, true);
                    }

                    if (!file_exists($webp) || filemtime($image) > filemtime($webp)) {
                      // Variable to hold the original image data
                      $data = '';
                      // Get the extension
                      $ext = pathinfo($image, PATHINFO_EXTENSION);

                      // Determine kind of file and load into $image
                      switch ($ext) {
                        case 'png':
                          $data = imagecreatefrompng($image);
                          break;

                        case 'jpg':
                        case 'jpeg':
                          $data = imagecreatefromjpeg($image);
                          break;

                        default:
                          break;
                      }

                      if (!empty($data) && !empty($webp)) {

                        /*

                      TODO: Allow specifing of maximum image with/height then
                      resize optimized image to conform to those sizes while 
                      maintining aspect ratio.

                      // Check that $image has data and that the $webp file has been set
                      

                        if (get_class($data) == 'GdImage') {

                          $width = 640;                 // New Width
                          $height = 0;                  // New Height

                          $w = imagesx($data);         // Current Width
                          $h = imagesy($data);         // Current Height

                          $ratio = $h / $w;           // Aspect Ratio

                          $height = $ratio * $width;  // New Height
                        }
                        */

                        // Create the webp file and save the result to a variable
                        imagewebp($data, $webp, $this->params->get('webp_quality', 80));
                      }
                    }

                    if (file_exists($webp)) {
                      $image = $webp;
                    }

                    if ($el->hasAttribute('src')) {
                      $el->setAttribute('src', str_replace(JPATH_ROOT, '', $image));
                    }

                    if ($el->hasAttribute('poster')) {
                      $el->setAttribute('poster', str_replace(JPATH_ROOT, '', $image));
                    }
                  }

                  if (
                    $ext != 'svg'
                    && !$el->hasAttribute('poster')
                    && $this->params->get('img_dimensions', 1)
                    && !$el->hasAttribute('width')
                    && !$el->hasAttribute('height')
                  ) {

                    $size = getimagesize($image);

                    $el->setAttribute('width', $size[0]);
                    $el->setAttribute('height', $size[1]);
                  }

                  if ($this->params->get('img_lazyload', 0)) {
                    $el->setAttribute('loading', 'lazy');
                  }
                }
              }
            }
          }
        }

        // Check if JQuery should be removed
        if ($this->params->get('jquery_remove', 1)) {
          // Remove that basterd!
          $dom = $this->removeLibrary($dom, 'jquery');
        }

        // Check if bootstrap should be removed... Hint, it should
        if ($this->params->get('bootstrap_remove', 0)) {
          // Yeah, I knew you were a cool kid, now removing bootstrap
          $dom = $this->removeLibrary($dom, 'bootstrap');
        }

        // Convert any JavaScript/CSS resources to load from their respective CDN's
        $dom = $this->cdn($dom);

        $removeNodes = [];

        // CSS Optimization
        if ($this->params->get('css_ext_optimize', 1)) {
          $dom = $this->optimizeExtAssets($dom, 'css');
        }

        // Optimize JavaScript

        if ($this->params->get('s_ext_optimize', 1)) {
          $dom = $this->optimizeExtAssets($dom, 'js');
        }

        // Minify HTML
        if ($this->params->get('html_optimize', 1) && $this->params->get('html_minify', 1)) {
          // Miniize HTML`
          $dom = $this->minifyHTML($dom);
        }

        // Get HTML as a string from the DOM
        $html = $html5->saveHTML($dom);
        // Replace the document's HTML with the optimized document
        $this->app->setBody($html);

        $this->writeToFile($cache, $html);
      }
    }
  }

  /*** CDN ***/

  /**
   * JQuery and Bootstrap CDN Support
   *
   * @var \DOMDocument $dom The DOM Document to parse
   *
   * @return \DOMDocument The (possibly) modified DOM Document
   */
  protected function cdn(\DOMDocument $dom): \DOMDocument
  {
    // Loop through all the script elements in the DOM
    foreach ($dom->getElementsByTagName('script') as $node) {
      // Check that the node is a linked script
      if ((!empty($node->getAttribute('type')) && $node->getAttribute('type') != 'text/javascript') || empty($node->getAttribute('src'))) {
        // If it's a script with no externally linked source, skip
        continue;
      }

      // Load the source from the script into the Joomla! Uri Object
      $uri = new \Joomla\CMS\Uri\Uri($node->getAttribute('src'));

      // Check if the host is empty, and if the host matches the current host
      if (!empty($uri->getHost()) && $uri->getHost() != \Joomla\CMS\Uri\Uri::getInstance()->getHost()) {
        // It is set and dosn't match the current host of the website so it
        // should be skipped
        continue;
      }

      // Get the filepath from the Joomla! Uri Object
      $file = $uri->getPath();

      if ($file == '/media/jui/js/jquery.js' || $file == '/media/jui/js/jquery.min.js') {
        if ($this->params->get('jquery_cdn') == 'google') {
          $node->setAttribute('src', 'https://ajax.googleapis.com/ajax/libs/jquery/' . $this->params->get('jquery_cdn_version', '1.12.4') . '/jquery.min.js');
        } else if ($this->params->get('jquery_cdn') == 'jquery') {
          $node->setAttribute('src', 'https://code.jquery.com/jquery-' . $this->params->get('jquery_cdn_version', '1.12.4') . '.min.js');
        }
      }

      if ($file == '/media/jui/js/bootstrap.js' || $file == '/media/jui/js/bootstrap.min.js') {
        if ($this->params->get('bootstrap_cdn', 'local') != 'local') {
          $node->setAttribute('src', 'https://stackpath.bootstrapcdn.com/twitter-bootstrap/2.3.2/js/bootstrap.min.js');
        }
      }
    }

    foreach ($dom->getElementsByTagName('link') as $node) {
      // Check that the node is a stylesheet
      if ($node->getAttribute('rel') != 'stylesheet' || empty($node->getAttribute('href'))) {
        // It's a link tag bu not a stylesheet, skip or the src/href is empty.
        continue;
      }

      $uri = new \Joomla\CMS\Uri\Uri($node->getAttribute('href'));

      // It's not a local file, skip
      if (!empty($uri->getHost()) && $uri->getHost() != \Joomla\CMS\Uri\Uri::getInstance()->getHost()) {
        continue;
      }

      $file = $uri->getPath();
    }

    return $dom;
  }

  protected function removeLibrary(\DOMDocument $dom, string $library): \DOMDocument
  {
    // Array of nodes to remove.  Originally we tried to remove from the DOM, but
    // this shifted the position of all elements having us miss checking nodes.
    $nodes = array();

    if (file_exists($file = JPATH_ROOT . '/plugins/system/yoptimize/data/' . $library . '-js.txt')) {
      $files = file($file, FILE_IGNORE_NEW_LINES);

      foreach ($dom->getElementsByTagName('script') as $node) {
        if ((!empty($node->getAttribute('type')) && $node->getAttribute('type') != 'text/javascript') || empty($node->getAttribute('src'))) {
          // If it's a script with no externally linked source, skip
          continue;
        }

        $uri = new \Joomla\CMS\Uri\Uri($node->getAttribute('src'));

        if (!empty($uri->getHost()) && $uri->getHost() != \Joomla\CMS\Uri\Uri::getInstance()->getHost()) {
          continue;
        }

        $src = $uri->getPath();

        foreach ($files as $file) {
          if ($src == $file) {
            $nodes[$src] = $node;
            break;
          }
        }
      }
    }

    if (file_exists($file = JPATH_ROOT . '/plugins/system/yoptimize/data/' . $library . '-js-inline.txt')) {

      $scripts = file($file, FILE_IGNORE_NEW_LINES);

      foreach ($dom->getElementsByTagName('script') as $node) {
        if ((!empty($node->getAttribute('type')) && $node->getAttribute('type') != 'text/javascript') || !empty($node->getAttribute('src'))) {
          // If it's a script with no externally linked source, skip
          continue;
        }

        if (!empty($js = $node->textContent)) {
          //$js = $this->optimizeJS($js, true, true, true);

          foreach ($scripts as $script) {
            if ($js == $script) {
              $nodes[$src] = $node;
            }
          }
        }
      }
    }

    if (file_exists($file = JPATH_ROOT . '/plugins/system/yoptimize/data/' . $library . '-css.txt')) {
      $js = file($file, FILE_IGNORE_NEW_LINES);

      foreach ($dom->getElementsByTagName('link') as $node) {
        // Check that the node is a stylesheet
        if ($node->getAttribute('rel') != 'stylesheet' || empty($node->getAttribute('href'))) {
          // It's a link tag bu not a stylesheet, skip or the src/href is empty.
          continue;
        }

        $uri = new \Joomla\CMS\Uri\Uri($node->getAttribute('href'));

        if (!empty($uri->getHost()) && $uri->getHost() != \Joomla\CMS\Uri\Uri::getInstance()->getHost()) {
          continue;
        }

        $href = $uri->getPath();

        foreach ($js as $file) {
          if ($href == $file) {
            $nodes[$src] = $node;
            break;
          }
        }
      }
    }

    foreach ($nodes as $node) {
      $node->parentNode->removeChild($node);
    }

    return $dom;
  }

  /**** CSS ****/

  protected function optimizeExtAssets(\DOMDocument $dom, string $ext): \DOMDocument
  {
    $page = \Joomla\CMS\Uri\Uri::getInstance()->getPath();
    $cache = $this->getCachePath('data') . substr($page, 1);

    // If the data cache is older then 24 hours rerun caching checks
    //if (!file_exists($cache) || (time() - filemtime($cache)) > 86400)

    $removeNodes = [];
    $tag = ($ext == 'js') ? 'script' : 'link';
    $attr = ($ext == 'js') ? 'src' : 'href';

    $file = (object)[
      'key' => '',
      'time' => 0,
      'files' => [],
      'cache' => ''
    ];

    $files = (object)[
      'libs' => clone $file,
      'tmpl' =>  clone $file,
      'page' =>  clone $file
    ];

    foreach ($dom->getElementsByTagName($tag) as $node) {
      if (
        ($ext == 'css' && $node->getAttribute('rel') != 'stylesheet')
        || empty($node->getAttribute($attr))
      ) {
        continue;
      }

      $uri = new \Joomla\CMS\Uri\Uri($node->getAttribute($attr));

      if (empty($uri->getHost()) || $uri->getHost() == \Joomla\CMS\Uri\Uri::getInstance()->getHost()) {
        $path = $uri->getPath();
        $parts = explode('/', substr($path, 1));
        $type = 'page';

        if ($parts[0] == 'templates' && $parts[2] == $ext) {
          if (count($parts) == 4) {
            $type = 'tmpl';
          } else {
            $type = 'page';
          }
        } else {
          $type = 'libs';
        }

        $files->$type->key .= ((empty($files->libs->key)) ?: ':') . $path;

        if (($t = filemtime(JPATH_ROOT . $path)) > $files->$type->time) {
          $files->$type->time = $t;
        }

        $files->$type->files[] = $path;

        $removeNodes[] = $node;
      }
    }

    if ($page == '/') {
      $page = 'home';
    }

    $files->libs->cache = $this->getCachePath($ext) . hash('md5', $files->libs->key) . '.' . $ext;
    $files->tmpl->cache = $this->getCachePath($ext) . 'template.' . $ext;
    $files->page->cache = str_replace('//', '/', $this->getCachePath($ext) . $page) . '.' . $ext;

    foreach ($files as $f) {
      $data = '';

      if (!file_exists($f->cache) || $f->time > filemtime($f->cache)) {

        for ($i = 0; $i < count($f->files); $i++) {
          $data .= file_get_contents(JPATH_ROOT . $f->files[$i]);
        }

        if (!empty($data)) {

          $method = 'optimize' . strtoupper($ext);

          $this->writeToFile($f->cache, $this->$method(
            $data,
            $this->params->get($ext . '_ext_minify', ''),
            $this->params->get($ext . '_ext_normalize', 0),
            $this->params->get($ext . '_ext_comments', 0)
          ));
        }
      }

      if (!empty($f->files)) {
        $el = $dom->createElement($tag);
        $el->setAttribute($attr, str_replace(JPATH_ROOT, '', $f->cache) . '?' . $f->time);
        $el->setAttribute('type', ($ext == 'js') ? 'text/javascript' : 'text/css');

        if ($ext == 'css') {
          $el->setAttribute('rel', 'stylesheet');
        }

        $dom->getElementsByTagName('head')->item(0)->appendChild($el);
      }
    }

    // Cleanup DOM
    foreach ($removeNodes as $node) {
      $node->parentNode->removeChild($node);
    }

    return $dom;
  }

  /***
   * Convert all relative urls in a CSS file to an absolute
   * 
   * @param string $file The CSS file
   * @param string $css The CSS data
   * 
   * @return string The modified CSS data
   */
  protected function convertRelToAbs(string $file, string $css): string
  {
    // Find all url() within css
    preg_match_all('/url\(([^)]+)\)/i', $css, $results);

    // Check if any url() have been found
    if (!empty($results) && !empty($results[1]) && is_array($results[1])) {
      // Loop through all instances of url()
      foreach ($results[1] as $r) {
        //if (substr($r, 0, 1) != '/')
        if (substr($r, 0, 7) != '/media/' && substr($r, 0, 8) != '/images/') {
          // Get the absolute path
          $abs = $this->resolveRelative($file, $r);
          // Replace the old path with the new path
          $css = str_replace($r, $abs, $css);
          // Remove filesystem path making this a uri
          $css = str_replace(JPATH_ROOT, '', $css);
        }
      }
    }

    return $css;
  }

  /**
   * Optimizes JavaScript
   *
   * @param string $js - Original JavaScript to optimize
   * @param bool $minify - Minify with what library
   * @param bool $normalize - Normalize linebreaks
   * @param bool $comments -  Remove comments
   *
   * @return string - Optimized JavaScript
   */
  protected function optimizeJS(string $js, string $minify = '', bool $normalize = true, bool $comments = true): string
  {
    if ($normalize) {
      // Normalize line breaks
      $js = $this->normalize($js);
    }

    //if ($comments && $minify != 'JShrink') {
    if ($comments) {
      // Remove comments
      $pattern = '/(?:(?:\/\*(?:[^*]|(?:\*+[^*\/]))*\*+\/)|(?:(?<!\:|\\\|\'|\")\/\/.*))/';
      //$pattern = '/(?:(?:\/\*(?:[^*]|(?:\*+[^*\/]))*\*+\/)|(?:(?<!\:|\\\|\')\/\/.*))/';

      $js = preg_replace($pattern, '', $js);
    }

    // Minify
    if ($minify == 'RegEx') {
      // special case where the + indicates treating variable as numeric, e.g. a = b + +c
      $js = preg_replace('/([-\+])\s+\+([^\s;]*)/', '$1 (+$2)', $js);

      // condense spaces
      $js = preg_replace("/\s*\n\s*/", "\n", $js); // spaces around newlines
      $js = preg_replace("/\h+/", " ", $js); // \h+ horizontal white space

      // remove unnecessary horizontal spaces around non variables (alphanumerics, underscore, dollarsign)
      $js = preg_replace("/\h([^A-Za-z0-9\_\$])/", '$1', $js);
      $js = preg_replace("/([^A-Za-z0-9\_\$])\h/", '$1', $js);

      // remove unnecessary spaces around brackets and parantheses
      $js = preg_replace("/\s?([\(\[{])\s?/", '$1', $js);
      $js = preg_replace("/\s([\)\]}])/", '$1', $js);

      // remove unnecessary spaces around operators that don't need any spaces (specifically newlines)
      $js = preg_replace("/\s?([\.=:\-+,])\s?/", '$1', $js);

      // unnecessary characters
      $js = preg_replace("/;\n/", ";", $js); // semicolon before newline
      $js = preg_replace('/;}/', '}', $js); // semicolon before end bracket
    } else if ($minify == 'MatthiasMullie') {
      $minifier = new \MatthiasMullie\Minify\JS();
      $minifier->add($js);
      $js = $minifier->minify();
    } else if ($minify == 'JShrink') {
      if ($comments) {
        $js = \JShrink\Minifier::minify($js, array('flaggedComments' => false));
      } else {
        $js = \JShrink\Minifier::minify($js);
      }
    }

    $js = trim($js);

    return $js;
  }

  /**
   * Optimizes CSS
   *
   * @param string $css - Original CSS to optimize
   * @param bool $minify - Minify with what library
   * @param bool $normalize - Normalize linebreaks
   * @param bool $comments -  Remove comments
   *
   * @return string - Optimized CSS
   */
  public function optimizeCSS(string $css, string $minify = '', bool $normalize = true, bool $comments = true): string
  {
    if ($normalize) {
      // Normalize line breaks
      $css = $this->normalize($css);
    }

    if ($comments) {
      // Remove CSS comments
      $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
    }

    // Minify CSS
    if ($minify == 'Sabberworm') {
      $parse = new Sabberworm\CSS\Parser($css);
      $doc = $parse->parse();
      $css = $doc->render(Sabberworm\CSS\OutputFormat::createCompact());
    } else if ($minify == 'MatthiasMullie') {
      $minifier = new \MatthiasMullie\Minify\CSS();
      $minifier->add($css);
      $css = $minifier->minify();
    }

    $css = trim($css);

    return $css;
  }

  function minifyHTML(\DOMDocument $dom): \DOMDocument
  {
    $allowOut = array(
      'b', 'big', 'i', 'small', 'tt', 'abbr', 'acronym', 'cite',
      'code', 'dfn', 'em', 'kbd', 'strong', 'samp', 'var', 'a',
      'bdo', 'br', 'img', 'map', 'object', 'q', 'span', 'sub',
      'sup', 'button', 'input', 'label', 'select', 'textarea'
    );

    $allowIn = array('style', 'class', 'script');

    $xpath = new \DOMXPath($dom);
    $nodes = $xpath->query("//text()");

    foreach ($nodes as $node) {
      if (in_array($node->parentNode->nodeName, $allowIn)) {
        continue;
      }

      $node->textContent = str_replace(["\r", "\n", "\t"], ' ', $node->textContent);

      while (strpos($node->textContent, '  ') !== false) {
        $node->textContent = str_replace('  ', ' ', $node->textContent);
      }

      if (!in_array($node->parentNode->nodeName, $allowOut)) {
        if (!($node->previousSibling && in_array($node->previousSibling->nodeName, $allowOut))) {
          $node->textContent = ltrim($node->textContent);
        }

        if (!($node->nextSibling && in_array($node->nextSibling->nodeName, $allowOut))) {
          $node->textContent = rtrim($node->textContent);
        }
      }

      if ((strlen($node->textContent) == 0)) {
        $node->parentNode->removeChild($node);
      }
    }

    return $dom;
  }

  /*** Misc ***/

  /**
   * Normalize line breaks
   *
   * @param string $data Data to normalize
   *
   * @return string Normalized data
   */
  protected function normalize(string $data): string
  {
    return str_replace(["\r\n", "\r"], "\n", $data);
  }

  protected function cleanUri(string $link): string
  {
    $uri = \Joomla\CMS\Uri\Uri::root();

    if (strpos($link, $uri) !== false) {
      $link = str_replace($uri, '/', $link);
    }

    if (strpos($link, '?') !== false) {
      $link = substr($link, 0, strpos($link, '?'));
    }

    if (strpos($link, '#') !== false) {
      $link = substr($link, 0, strpos($link, '#'));
    }

    return $link;
  }

  /**
   * Get the directory to cache asset files in, creates if does not exist.
   *
   * @return string absolute cache directory
   */
  protected function getCachePath(string $type = ''): string
  {
    $cache = '';

    if (file_exists(JPATH_CACHE)) {

      $cache = JPATH_ROOT . '/o/';

      if (!empty($type)) {
        $cache .= $type . '/';
      }

      if (!file_exists($cache)) {
        if (!mkdir($cache, 0755, true)) {
          throw new Exception('Can\'t create cache directory.');
        }
      }
    } else {
      throw new Exception('System cache directory not found.');
    }

    return $cache;
  }

  protected function writeToFile(string $file, string $data)
  {
    if (strpos($file, JPATH_ROOT) !== false) {
      $parts = explode('/', $file);
      unset($parts[count($parts) - 1]);
      $path = implode('/', $parts);

      if (!file_exists($path)) {
        mkdir($path, 0755, true);
      }

      file_put_contents($file, $data);
    }
  }
}
